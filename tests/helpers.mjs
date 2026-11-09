import { spawn, spawnSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { deflateSync } from 'node:zlib';
import { createServer } from 'node:net';
import { randomBytes } from 'node:crypto';

export const root = resolve(import.meta.dirname, '..');
export const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:\\xamppni\\php\\php.exe' : 'php');
export const browserPath = process.env.BROWSER_BINARY || [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  '/usr/bin/chromium', '/usr/bin/google-chrome'
].find(existsSync);
export async function freePort() {
  return new Promise((done,reject)=>{const server=createServer();server.once('error',reject);server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>done(port));});});
}
export async function startApp(label, extraEnv={}) {
  const dir=resolve(root,'test-results',`${label}-${Date.now()}-${randomBytes(3).toString('hex')}`);
  await mkdir(dir,{recursive:true});
  await writeFile(resolve(root,'test-results','.htaccess'),'Require all denied\n');
  const env={...process.env,FRUITSCAN_MODEL_URL:'',FRUITSCAN_MODEL_MANIFEST:'',FRUITSCAN_MODEL_TOKEN:'',FRUITSCAN_MAIL_FROM:'',FRUITSCAN_PUBLIC_URL:'',FRUITSCAN_STORAGE_DIR:dir,...extraEnv};
  env.FRUITSCAN_DB_DRIVER=process.env.FRUITSCAN_TEST_MYSQL==='1'?'mysql':'sqlite';
  if(env.FRUITSCAN_DB_DRIVER==='mysql') {
    env.FRUITSCAN_DB_CONFIG=resolve(dir,'database.json');
    const setup=spawnSync(php,['scripts/test-mysql.php','create',env.FRUITSCAN_DB_CONFIG],{cwd:root,env,encoding:'utf8',windowsHide:true});
    if(setup.status!==0)throw new Error(`Test database setup failed: ${setup.stderr}`);
  }
  const adminPassword=randomBytes(20).toString('base64url');
  const seed=spawnSync(php,['scripts/admin.php','admin@example.test','Test Administrator'],{cwd:root,env:{...env,FRUITSCAN_ADMIN_PASSWORD:adminPassword},encoding:'utf8',windowsHide:true});
  if(seed.status!==0)throw new Error(`Admin setup failed: ${seed.stderr || seed.error || seed.stdout}`);
  const port=await freePort();const base=`http://127.0.0.1:${port}`;
  const processHandle=spawn(php,['-d','upload_max_filesize=10M','-d','post_max_size=12M','-S',`127.0.0.1:${port}`,'router.php'],{cwd:root,env,windowsHide:true,stdio:['ignore','pipe','pipe']});
  let logs='';processHandle.stdout.on('data',d=>logs+=d);processHandle.stderr.on('data',d=>logs+=d);
  let error;processHandle.on('error',e=>error=e);
  for(let i=0;i<60;i++) {if(error)throw error;try{const r=await fetch(base+'/api.php?action=bootstrap');if(r.ok)return {base,dir,adminPassword,close:async()=>{processHandle.kill();await writeFile(resolve(dir,'server.log'),logs);if(env.FRUITSCAN_DB_DRIVER==='mysql'){const cleanup=spawnSync(php,['scripts/test-mysql.php','cleanup',env.FRUITSCAN_DB_CONFIG],{cwd:root,env,encoding:'utf8',windowsHide:true});if(cleanup.status!==0)throw new Error(cleanup.stderr);}},logs:()=>logs};}catch{}await new Promise(r=>setTimeout(r,100));}
  processHandle.kill();throw new Error(`PHP server failed to start: ${logs}`);
}
export class Client {
  constructor(base){this.base=base;this.cookie='';this.csrf='';}
  async request(action,data,params={}) {
    const headers={Cookie:this.cookie};const options={headers};
    if(data!==undefined){options.method='POST';headers['X-CSRF-Token']=this.csrf;if(data instanceof FormData)options.body=data;else {headers['Content-Type']='application/json';options.body=JSON.stringify(data);}}
    const response=await fetch(`${this.base}/api.php?${new URLSearchParams({action,...params})}`,options);
    const cookie=response.headers.get('set-cookie');if(cookie)this.cookie=cookie.split(';')[0];
    const body=await response.json();if(body.csrf)this.csrf=body.csrf;
    return {status:response.status,...body};
  }
  async init(){await this.request('bootstrap');return this;}
}
function crc(buffer){let c=0xffffffff;for(const byte of buffer){c^=byte;for(let n=0;n<8;n++)c=(c&1)?(0xedb88320^(c>>>1)):(c>>>1);}return(c^0xffffffff)>>>0;}
function chunk(type,data){const b=Buffer.concat([Buffer.from(type),data]);const length=Buffer.alloc(4);length.writeUInt32BE(data.length);const checksum=Buffer.alloc(4);checksum.writeUInt32BE(crc(b));return Buffer.concat([length,b,checksum]);}
export function samplePng(width=128,height=128){const header=Buffer.alloc(13);header.writeUInt32BE(width,0);header.writeUInt32BE(height,4);header[8]=8;header[9]=2;const pixels=Buffer.alloc((width*3+1)*height);for(let y=0;y<height;y++){for(let x=0;x<width;x++){const i=y*(width*3+1)+1+x*3;pixels[i]=230;pixels[i+1]=145+Math.floor(x/width*50);pixels[i+2]=35;}}return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',header),chunk('IDAT',deflateSync(pixels)),chunk('IEND',Buffer.alloc(0))]);}
export function upload(outcome='fresh',image=samplePng(),type='image/png'){const data=new FormData();data.append('image',new Blob([image],{type}),'fruit.png');data.append('demo_outcome',outcome);return data;}
