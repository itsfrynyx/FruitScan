export const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const paths = {
 leaf:'M20 4c-10-2-17 3-15 10 1 4 5 6 9 4 5-2 6-8 6-14ZM4 21 15 10',
 arrow:'M5 12h14m-6-6 6 6-6 6', upload:'M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5',
 camera:'M8 5l2-2h4l2 2h4v15H4V5h4Zm8 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
 scan:'M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M7 12h10', check:'m5 12 4 4L19 6',
 clock:'M12 8v5l3 2m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', shield:'m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Zm-4 9 3 3 5-6',
 spark:'m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z',
 alert:'m12 3 10 18H2L12 3Zm0 6v5m0 3h.01', image:'M3 3h18v18H3V3Zm0 14 6-6 4 4 3-3 5 5M8 7h.01',
 menu:'M4 6h16M4 12h16M4 18h16', close:'m6 6 12 12M6 18 18 6', user:'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
 grid:'M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7', chart:'M4 3v18h17M8 16v-5m5 5V6m5 10V9',
 settings:'M12 3v3m0 12v3M3 12h3m12 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2m-3 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
 trash:'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7', eye:'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Zm13 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
 mail:'M3 5h18v14H3V5Zm0 0 9 8 9-8', logout:'M9 3H3v18h6m5-15 6 6-6 6m-6-6h12', plus:'M12 4v16M4 12h16', search:'m16 16 5 5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
 chevron:'m6 9 6 6 6-6', bolt:'m13 2-9 12h7l-1 8 10-13h-7l0-7', book:'M12 5Q6 1 2 4v15q5-3 10 1 5-4 10-1V4q-4-3-10 1v15'
};
export const icon = (name, cls = '') => `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${paths[name] || paths.leaf}"/></svg>`;
export const logo = () => `<a class="logo" href="#/" aria-label="FruitScan home"><img src="assets/favicon.svg" alt="" width="36" height="36">Fruit<span>Scan</span><span class="logo-dot">.</span></a>`;
export const btn = (text, href = '#/detector', secondary = false, glyph = 'arrow') => `<a class="button ${secondary ? 'secondary' : ''}" href="${href}">${text}${glyph ? icon(glyph) : ''}</a>`;
export const badge = (prediction, demo = false) => `<span class="badge ${prediction === 'rotten' ? 'rotten' : 'fresh'}">${icon(prediction === 'rotten' ? 'alert' : 'check')}${esc(prediction === 'rotten' ? 'Rotten' : 'Fresh')}</span>${demo ? '<span class="badge demo">Demo</span>' : ''}`;
export const confidence = value => value === null || value === undefined ? 'Unavailable' : `${Math.round(Number(value) * 100)}%`;
export const date = value => value ? new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')).toLocaleString(undefined, {dateStyle:'medium', timeStyle:'short'}) : '—';
export const pageHeader = (eyebrow, title, description, action = '') => `<div class="page-heading"><div><p class="eyebrow">${eyebrow}</p><h1>${title}</h1><p class="muted">${description}</p></div>${action}</div>`;
export const empty = (title, text, action = btn('Scan a Fruit'), glyph = 'scan') => `<div class="empty"><span class="empty-icon">${icon(glyph)}</span><h3>${title}</h3><p>${text}</p>${action}</div>`;
export const loading = () => '<div class="loading" role="status"><span class="spinner"></span> Getting things ready…</div>';
export function toast(message, type = 'success') { const item=document.createElement('div'); item.className=`toast ${type}`; item.setAttribute('role',type==='error'?'alert':'status'); item.innerHTML=`${icon(type==='error'?'alert':'check')}<span>${esc(message)}</span>`; const host=document.querySelector('#toasts');while(host.children.length>=2)host.firstElementChild.remove();host.append(item); setTimeout(()=>item.remove(),5500); }
export function confirmAction(title, message, label='Delete') {
  return new Promise(resolve => { const dialog=document.querySelector('#confirm-dialog'); dialog.innerHTML=`<form method="dialog"><span class="empty-icon danger">${icon('alert')}</span><h2 id="dialog-title">${esc(title)}</h2><p>${esc(message)}</p><div class="actions"><button value="cancel" class="button secondary" autofocus>Cancel</button><button value="confirm" class="button ${label==='Delete'?'danger-button':''}">${esc(label)}</button></div></form>`; dialog.onclose=()=>resolve(dialog.returnValue==='confirm'); dialog.showModal(); });
}
export function bindForm(id, callback) { const form=document.getElementById(id); if(!form)return; form.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('[type=submit]');const error=form.querySelector('.form-error');if(error)error.textContent='';button.disabled=true;button.setAttribute('aria-busy','true');const original=button.innerHTML;button.innerHTML=`<span class="spinner"></span> Please wait…`;try{await callback(Object.fromEntries(new FormData(form)),form);}catch(e){if(error)error.textContent=e.message;else toast(e.message,'error');}finally{button.disabled=false;button.removeAttribute('aria-busy');button.innerHTML=original;}}); }
export const field = (label, name, type='text', value='', extra='') => `<label class="field"><span>${label}</span><input name="${name}" type="${type}" value="${esc(value)}" ${extra}></label>`;
export const passwordField = (label='Password', name='password', autocomplete='current-password') => `<label class="field"><span>${label}</span><span class="password-field"><input name="${name}" type="password" minlength="10" maxlength="72" required autocomplete="${autocomplete}"><button type="button" class="icon-button password-toggle" aria-label="Show ${label.toLowerCase()}">${icon('eye')}</button></span></label>`;
export function bindPasswords() { document.querySelectorAll('.password-toggle').forEach(b=>b.onclick=()=>{const i=b.previousElementSibling;i.type=i.type==='password'?'text':'password';b.setAttribute('aria-label',`${i.type==='password'?'Show':'Hide'} password`);}); }
export function enhanceTables() {
  document.querySelectorAll('table').forEach(table=>{
    const labels=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row=>[...row.children].forEach((cell,index)=>{
      if(index>0 && index<row.children.length-1)cell.dataset.label=labels[index]||'';
    }));
  });
}
