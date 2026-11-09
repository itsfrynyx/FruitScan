import { state, bootstrap } from './services/api.js';
import { shell, bindLayout } from './components/layout.js';
import { loading, empty, esc, btn, enhanceTables } from './components/ui.js';
import { publicPage, bindPublic } from './pages/public.js';
import { detector, bindDetector } from './pages/detector.js';
import { authPage, bindAuth, profilePage, bindProfile } from './pages/auth.js';
import { dashboard, historyPage, detailPage, analyticsPage, bindRecords } from './pages/records.js';
import { usersPage, classesPage, settingsPage, feedbackPage, bindAdmin } from './pages/admin.js';
let cleanup=null,version=0;
const authPaths=['/login','/register','/forgot','/reset','/admin/login'];
async function route() {
  const ticket=++version;cleanup?.();cleanup=null;
  const [path='/',query='']=(location.hash.slice(1)||'/').split('?');const params=Object.fromEntries(new URLSearchParams(query));
  const admin=path.startsWith('/admin')&&path!=='/admin/login';const protectedPage=admin||['/dashboard','/history','/profile'].includes(path)||path.startsWith('/detection/');
  if(protectedPage&&!state.user){location.hash=admin?'/admin/login':'/login';return;}
  if(admin&&state.user.role!=='admin'){document.querySelector('#app').innerHTML=shell(`<div class="container page">${empty('This space is for administrators.','Your account does not have access to this workspace.',btn('Your dashboard','#/dashboard'),'shield')}</div>`,path);bindLayout();return;}
  document.querySelector('#app').innerHTML=shell(loading(),path);bindLayout();
  try{
    let content=publicPage(path);
    if(path==='/detector')content=detector();
    else if(authPaths.includes(path))content=authPage(path);
    else if(path==='/profile')content=profilePage();
    else if(path==='/dashboard'||path==='/admin')content=await dashboard(admin);
    else if(path==='/history'||path==='/admin/records')content=await historyPage(admin,params);
    else if(/^\/detection\/\d+$/.test(path))content=await detailPage(path.split('/')[2]);
    else if(path==='/admin/users')content=await usersPage(params);
    else if(path==='/admin/classes')content=await classesPage();
    else if(path==='/admin/settings')content=await settingsPage();
    else if(path==='/admin/analytics')content=await analyticsPage(params);
    else if(path==='/admin/feedback')content=await feedbackPage();
    if(ticket!==version)return;
    content??=`<div class="container page">${empty('This page took a different path.','Let’s get you back to something fresh.',btn('Back to home','#/'),'leaf')}</div>`;
    document.querySelector('#app').innerHTML=shell(content,path);bindLayout();bindPublic(path);enhanceTables();
    if(path==='/detector')cleanup=bindDetector();
    if(authPaths.includes(path))bindAuth(path);
    if(path==='/profile')bindProfile(route);
    bindRecords(route);if(admin)bindAdmin(path,route);
    const heading=document.querySelector('main h1');document.title=heading?`${heading.textContent} | FruitScan`:'FruitScan – AI Fruit Quality Detector';
    document.querySelector('#main').focus({preventScroll:true});window.scrollTo(0,0);
  }catch(e){if(ticket!==version)return;document.querySelector('#app').innerHTML=shell(`<div class="container page">${empty('Something needs a fresh start.',esc(e.message),'<button class="button" id="retry-page">Try again</button>','alert')}</div>`,path);bindLayout();document.querySelector('#retry-page').onclick=route;}
}
window.addEventListener('hashchange',route);
try { await bootstrap(); await route(); } catch(e) { document.querySelector('#app').innerHTML=`<div class="container page">${empty('FruitScan could not connect.',esc(e.message),'<button class="button" id="retry-start">Try again</button>','alert')}</div>`;document.querySelector('#retry-start').onclick=()=>location.reload(); }
