import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startApp,browserPath,Client,upload } from './helpers.mjs';
const app=await startApp('accessibility');let browser;
try{
  browser=await chromium.launch({headless:true,executablePath:browserPath});const context=await browser.newContext();const page=await context.newPage();const findings=[];
  const scan=async(path)=>{await page.goto(app.base+'/#'+path);await page.locator('main h1').waitFor();const result=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze();if(result.violations.length){findings.push({path,violations:result.violations});console.log(path,JSON.stringify(result.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),null,2));}else console.log('PASS accessibility '+path);};
  for(const path of ['/','/detector','/how-it-works','/fruits','/about','/faq','/contact','/privacy','/login','/register','/forgot'])await scan(path);
  await page.goto(app.base+'/#/admin/login');await page.getByLabel('Email address',{exact:true}).fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill(app.adminPassword);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('heading',{name:'Overview',exact:true}).waitFor();
  const admin=await new Client(app.base).init();await admin.request('login',{email:'admin@example.test',password:app.adminPassword});await admin.request('predict',upload());
  for(const path of ['/dashboard','/history','/profile','/admin','/admin/records','/admin/users','/admin/classes','/admin/analytics','/admin/settings','/admin/feedback'])await scan(path);
  await writeFile(resolve(app.dir,'accessibility.json'),JSON.stringify(findings,null,2));if(findings.length)throw new Error(`Accessibility issues found on ${findings.length} pages. Report: ${app.dir}`);
  console.log('All 21 pages passed automated WCAG 2.1 A/AA checks.');
}finally{await browser?.close();await app.close();}
