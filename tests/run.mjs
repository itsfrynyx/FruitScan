import assert from 'node:assert/strict';
import { readFile,writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { createServer } from 'node:http';
import { startApp,Client,upload,samplePng,freePort } from './helpers.mjs';
if (process.argv.includes('--browser')) { await import('./browser.mjs'); process.exit(0); }
if (process.argv.includes('--a11y')) { await import('./accessibility.mjs'); process.exit(0); }
if (process.argv.includes('--trained-model')) { await import('./trained-model.mjs'); process.exit(0); }
if (process.argv.includes('--trained-model')) { await import('./trained-model.mjs'); process.exit(0); }
let checks=0;
function check(condition,message){assert.ok(condition,message);checks++;console.log(`PASS ${message}`);}
const app=await startApp('api');
try {
  const guest=await new Client(app.base).init();
  let r=await guest.request('bootstrap');check(r.demo===true&&r.user===null,'Demo mode is explicit and guest session starts unauthenticated');
  check((await guest.request('history')).status===401,'History requires authentication');
  check((await guest.request('users')).status===401,'Admin API requires authentication');
  const original=guest.csrf;guest.csrf='invalid';check((await guest.request('register',{})).status===403,'Mutations reject invalid CSRF tokens');guest.csrf=original;
  check((await fetch(app.base+'/api.php?action=logout')).status===405,'GET cannot mutate session state');
  for(const path of ['/storage/fruitscan.sqlite','/server/model.example.json','/scripts/admin.php','/test-results/','/storage%5cfruitscan.sqlite','/.gitignore'])check((await fetch(app.base+path)).status===404,`Private path is blocked: ${path}`);
  r=await guest.request('predict',upload());check(r.success&&r.result.id===null&&r.result.confidence===null&&r.result.is_demo===1,'Guest demo scan is not persisted and has no invented confidence');
  check((await guest.request('predict',upload('fresh',Buffer.from('not an image'),'image/png'))).status===400,'Server rejects a spoofed image MIME type');
  check((await guest.request('predict',upload('fresh',samplePng(16,16)))).status===400,'Server rejects images below minimum dimensions');
  check((await guest.request('predict',upload('fresh',Buffer.alloc(10*1024*1024+1)))).status===400,'Server enforces 10 MB image size limit');
  const password='A-test-passphrase-42';
  r=await guest.request('register',{name:'Test Gardener',email:'gardener@example.test',password,confirm:password});check(r.success&&r.user.role==='user','Registration creates a user account and authenticated session');
  check((await guest.request('users')).status===403,'Regular users cannot access admin API');
  const scans=[];for(let i=0;i<9;i++){r=await guest.request('predict',upload(i%2?'rotten':'fresh'));assert.equal(r.success,true);scans.push(r.result.id);}
  r=await guest.request('history');check(r.total===9&&r.records.length===8&&r.pages===2,'History paginates persisted scans');
  check((await guest.request('history',undefined,{page:2})).records.length===1,'Second history page returns remaining record');
  r=await guest.request('history',undefined,{prediction:'rotten'});check(r.total===4&&r.records.every(x=>x.prediction==='rotten'),'Result filter returns only matching detections');
  check((await guest.request('history',undefined,{search:'no such fruit'})).total===0,'Search has an honest empty result');
  r=await guest.request('history',undefined,{sort:'oldest',fruit:'Unclassified fruit'});check(r.records[0].id===scans[0],'Fruit filter and oldest-first sort work');
  r=await guest.request('detail',undefined,{id:scans[0]});check(r.record.is_demo===1&&r.record.confidence===null,'Saved details preserve the demo label and missing confidence');
  const img=await fetch(`${app.base}/api.php?action=image&id=${scans[0]}`,{headers:{Cookie:guest.cookie}});check(img.ok&&img.headers.get('content-type')==='image/png','Owner can retrieve private image');
  const other=await new Client(app.base).init();r=await other.request('register',{name:'Other User',email:'other@example.test',password,confirm:password});const otherId=r.user.id;
  check((await other.request('detail',undefined,{id:scans[0]})).status===404,'Another user cannot read private detection');
  check((await other.request('delete-detection',{id:scans[0]})).status===404,'Another user cannot delete private detection');
  check((await other.request('image',undefined,{id:scans[0]})).status===404,'Another user cannot read private image');
  r=await guest.request('stats');check(r.stats.total===9&&r.stats.fresh===5&&r.stats.rotten===4&&r.stats.demo===9,'Analytics count real database records');
  r=await guest.request('profile',{name:'Updated Gardener',email:'gardener@example.test'});check(r.user.name==='Updated Gardener','Profile changes persist');
  const secondSession=await new Client(app.base).init();await secondSession.request('login',{email:'gardener@example.test',password});
  const newPassword='New-test-passphrase-84';await guest.request('password',{current:password,password:newPassword,confirm:newPassword});
  check((await secondSession.request('history')).status===401,'Password change revokes other sessions');
  await guest.request('logout',{});check((await guest.request('history')).status===401,'Logout protects user routes again');
  check((await guest.request('login',{email:'gardener@example.test',password})).status===401,'Old password no longer works');
  check((await guest.request('login',{email:'gardener@example.test',password:newPassword})).success,'New password works');
  await guest.request('forgot',{email:'gardener@example.test'});const outbox=(await readFile(resolve(app.dir,'reset-outbox.log'),'utf8')).trim().split('\n').map(JSON.parse);const token=outbox.at(-1).link.split('token=')[1];
  const resetter=await new Client(app.base).init();check((await resetter.request('reset',{token,password,confirm:password})).success,'Password reset accepts a valid one-time token');
  check((await resetter.request('reset',{token,password,confirm:password})).status===400,'Password reset token cannot be reused');
  check((await guest.request('history')).status===401,'Password reset revokes existing sessions');
  await guest.request('login',{email:'gardener@example.test',password});
  const admin=await new Client(app.base).init();r=await admin.request('login',{email:'admin@example.test',password:app.adminPassword,admin:true});check(r.user.role==='admin','Admin can authenticate through separate sign-in flow');
  check((await admin.request('history',undefined,{scope:'all'})).total===9,'Admin can view all records');
  check((await admin.request('users')).users.length===3,'Admin can inspect registered users');
  await admin.request('user-update',{id:otherId,status:'inactive'});check((await other.request('history')).status===401,'Deactivation revokes user access');
  await admin.request('user-update',{id:otherId,status:'active'});check((await other.request('history')).status===401,'Reactivation does not restore an old session');
  await other.request('login',{email:'other@example.test',password});
  r=await admin.request('user-update',{id:otherId,operation:'reset'});check(typeof r.token==='string'&&r.token.length===64,'Administrator can issue a secure reset token');
  await admin.request('class-save',{name:'apple',description:'Illustrative apple category',status:'active'});r=await admin.request('classes');const classId=r.classes[0].id;check(r.classes.length===1,'Admin can add demonstration fruit metadata');
  await admin.request('class-save',{id:classId,name:'apple',description:'Updated description',status:'inactive'});check((await guest.request('bootstrap')).classes.length===0,'Disabled classes disappear from public list');
  check((await admin.request('class-save',{name:'unsafe',description:'Bad URL',image_path:'javascript:alert(1)'})).status===400,'Class image rejects unsafe URLs');
  await admin.request('class-delete',{id:classId});check((await admin.request('classes')).classes.length===0,'Admin can delete class metadata');
  const freshGuest=await new Client(app.base).init();await admin.request('settings-save',{allow_guest:'0',maintenance:'0'});check((await freshGuest.request('predict',upload())).status===401,'Guest scanning setting is enforced server-side');
  await admin.request('settings-save',{allow_guest:'1',maintenance:'1'});check((await guest.request('predict',upload())).status===503,'Maintenance setting blocks new analyses');
  await admin.request('settings-save',{allow_guest:'1',maintenance:'0'});
  await guest.request('feedback',{name:'Test Gardener',email:'gardener@example.test',message:'This is a test message for the feedback inbox.'});check((await admin.request('feedback-list')).feedback.length===1,'Feedback is saved and visible to administrators');
  await guest.request('delete-detection',{id:scans[0]});check((await guest.request('detail',undefined,{id:scans[0]})).status===404,'Deletion removes the detection');
  check((await fetch(`${app.base}/api.php?action=image&id=${scans[0]}`,{headers:{Cookie:guest.cookie}})).status===404,'Deleted scan image is no longer accessible');
  await admin.request('user-update',{id:otherId,operation:'delete'});check((await admin.request('users')).users.length===2,'Admin can delete a user account');
  check(!/PHP (?:Fatal|Warning|Notice|Parse)/.test(app.logs()),'No PHP warnings or fatal errors during demo API flows');
} finally {await app.close();}

// Exercise the real-model adapter against a controlled test endpoint, never the user database.
let response={success:true,prediction:'fresh',confidence:.96,fruit_class:'apple',probabilities:{fresh:.96,rotten:.04}};
const modelPort=await freePort();const mock=createServer((req,res)=>{req.resume();req.on('end',()=>{res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify(response));});});
await new Promise(r=>mock.listen(modelPort,'127.0.0.1',r));
let real;
try {
  const manifest=resolve(app.dir,'model.json');await writeFile(manifest,JSON.stringify({version:'integration-test-only',fruit_classes:['apple']}));
  real=await startApp('model',{FRUITSCAN_MODEL_URL:`http://127.0.0.1:${modelPort}/predict`,FRUITSCAN_MODEL_MANIFEST:manifest});
  const client=await new Client(real.base).init();let r=await client.request('predict',upload());check(r.success&&r.result.is_demo===0&&r.result.confidence===.96&&r.result.fruit_class==='apple','Real-model adapter returns validated model values');
  response={success:true,prediction:'rotten',fruit_class:'apple'};r=await client.request('predict',upload());check(r.success&&r.result.confidence===null&&r.result.prediction==='rotten','Real-model adapter preserves unavailable confidence');
  response={success:true,prediction:'fresh',confidence:4,fruit_class:'apple'};check((await client.request('predict',upload())).status===503,'Real-model adapter rejects invalid confidence');
  response={success:true,prediction:'fresh',confidence:.8,fruit_class:'dragonfruit'};check((await client.request('predict',upload())).status===503,'Real-model adapter rejects undeclared fruit classes');
  response={success:true,prediction:'fresh',confidence:.8,fruit_class:'apple',probabilities:{fresh:.8,rotten:.8}};check((await client.request('predict',upload())).status===503,'Real-model adapter rejects invalid probability totals');
  const admin=await new Client(real.base).init();await admin.request('login',{email:'admin@example.test',password:real.adminPassword});
  check((await admin.request('class-save',{name:'banana',description:'Not in model'})).status===400,'Production fruit metadata must match model manifest');
}finally{if(real)await real.close();await new Promise(r=>mock.close(r));}
console.log(`\n${checks} integration checks passed. Test data is isolated under test-results/.`);
