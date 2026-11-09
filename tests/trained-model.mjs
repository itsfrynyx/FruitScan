import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { root, startApp, Client, browserPath, upload } from './helpers.mjs';

const service=JSON.parse(await readFile(resolve(root,'server/model-service.json'),'utf8'));
const model=JSON.parse(await readFile(resolve(root,'server/model.json'),'utf8'));
const dataset=JSON.parse(await readFile(resolve(root,'storage/ml/data/manifest.json'),'utf8'));
const sampleFor=label=>dataset.records.find(r=>r.split==='test'&&r.label===label);
const imageFor=record=>readFile(resolve(root,'storage/ml/data/images',record.image));
const app=await startApp('trained-model',{FRUITSCAN_MODEL_URL:service.url,FRUITSCAN_MODEL_TOKEN:service.token,FRUITSCAN_MODEL_MANIFEST:resolve(root,'server/model.json')});
let browser;
try{
  const client=await new Client(app.base).init();
  const initial=await client.request('bootstrap');assert.equal(initial.demo,false);assert.equal(initial.model_classes.length,8);
  const conditions=new Set(),fruits=new Set();
  for(const label of dataset.classes){
    const sample=sampleFor(label);const result=await client.request('predict',upload('rotten',await imageFor(sample),'image/jpeg'));
    assert.equal(result.success,true);assert.equal(result.result.is_demo,0);assert.equal(result.result.id,null);
    assert.equal(result.result.model_version,model.version);assert.ok(result.result.confidence>=0&&result.result.confidence<=1);
    assert.ok(Math.abs(result.result.probabilities.fresh+result.result.probabilities.rotten-1)<.00001);
    conditions.add(result.result.prediction);fruits.add(result.result.fruit_class);
    console.log(`PASS trained endpoint ${label}: ${result.result.fruit_class}, ${result.result.prediction}, ${(result.result.confidence*100).toFixed(2)}% model confidence`);
  }
  assert.equal(conditions.size,2);assert.equal(fruits.size,8);
  await client.request('login',{email:'admin@example.test',password:app.adminPassword});
  const freshSample=sampleFor('FreshApple');
  const saved=await client.request('predict',upload('rotten',await imageFor(freshSample),'image/jpeg'));
  assert.ok(saved.result.id);
  const detail=await client.request('detail',undefined,{id:saved.result.id});assert.equal(detail.record.is_demo,0);assert.equal(detail.record.model_version,model.version);
  assert.ok(Math.abs(detail.record.confidence-saved.result.confidence)<1e-12);console.log('PASS real predictions persist with model identity, probabilities, and no demo flag');
  const invalidAuth=await fetch(service.url,{method:'POST',body:upload()});assert.equal(invalidAuth.status,401);
  const metrics=await client.request('stats');assert.equal(metrics.stats.demo,0);assert.equal(metrics.stats.total,1);
  browser=await chromium.launch({headless:true,executablePath:browserPath});const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  // Check actual XAMPP deployment as a guest: no new live account or saved detection.
  const live='http://localhost/FruitScanWeb/';await page.goto(live+'#/detector');await page.locator('#analyze-button').waitFor();
  assert.equal(await page.locator('#demo-outcome').count(),0);await page.locator('#image-input').setInputFiles({name:'held-out-apple.jpg',mimeType:'image/jpeg',buffer:await imageFor(freshSample)});
  await page.locator('#preview-zone').waitFor({state:'visible'});await page.getByRole('button',{name:'Analyze fruit',exact:true}).click();await page.getByText('ANALYSIS COMPLETE',{exact:true}).waitFor();
  assert.equal(await page.getByText('DEMONSTRATION RESULT',{exact:true}).count(),0);assert.equal(await page.getByText('Confidence unavailable',{exact:true}).count(),0);
  await page.evaluate(()=>scrollTo({top:0,behavior:'instant'}));await page.screenshot({path:resolve(app.dir,'real-result-desktop.png'),fullPage:true,style:'.toasts { visibility:hidden; }'});
  let accessibility=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze();assert.equal(accessibility.violations.length,0,JSON.stringify(accessibility.violations));
  await page.setViewportSize({width:390,height:900});await page.screenshot({path:resolve(app.dir,'real-result-mobile.png'),fullPage:true,style:'.toasts { visibility:hidden; }'});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.goto(live+'#/fruits');await page.locator('.fruit-card').first().waitFor();assert.equal(await page.locator('.fruit-card').count(),8);
  await page.screenshot({path:resolve(app.dir,'supported-fruits-mobile.png'),fullPage:true});
  // Inspect metrics UI using isolated admin credentials, never live user accounts.
  await page.setViewportSize({width:1440,height:1000});await page.goto(app.base+'/#/admin/login');await page.getByLabel('Email address',{exact:true}).fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill(app.adminPassword);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('heading',{name:'Overview',exact:true}).waitFor();
  await page.goto(app.base+'/#/admin/settings');await page.getByRole('heading',{name:'Held-out evaluation',exact:true}).waitFor();await page.screenshot({path:resolve(app.dir,'model-settings.png'),fullPage:true,style:'.toasts { visibility:hidden; }'});
  accessibility=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze();assert.equal(accessibility.violations.length,0,JSON.stringify(accessibility.violations));
  assert.deepEqual(errors,[]);console.log('PASS XAMPP real inference, eight fruit categories, responsive result, model metrics UI, and accessibility checks');
  console.log('Screenshots: '+app.dir);
}finally{await browser?.close();await app.close();}
