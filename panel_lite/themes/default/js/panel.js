(function(){
'use strict';
const root=document.getElementById('panel-lite-app');
if(!root) return;
const moduleName=root.dataset.module||'panel_lite';
const isAdmin=root.dataset.admin==='1';
const canMove=root.dataset.canMove==='1';
const csrf=root.dataset.csrf||'';
const api=`modules/${moduleName}/api.php`;
let state=null, config={groups:[],assignments:{}}, etag=null, inflight=false, pollTimer=null, search='', dragging=false, saving=false, resizing=false;
const layoutKey='panel_lite_layout_v2_'+(root.dataset.userKey||'unknown');
let layout={}, layoutTimer=null, resizeObserver=null, resizeEndTimer=null, renderedOnce=false;
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function normalizeLayout(source){
  const clean={};
  if(!source||typeof source!=='object'||Array.isArray(source))return clean;
  for(const [k,v] of Object.entries(source)){
    if(!/^(?:[1-9]|1[0-7]|55)$/.test(k)||!v||typeof v!=='object')continue;
    const w=Number(v.width),h=Number(v.height);
    if(Number.isFinite(w)&&Number.isFinite(h)&&w>=260&&w<=4000&&h>=90&&h<=4000){clean[k]={width:Math.round(w),height:Math.round(h)};}
  }
  return clean;
}
async function loadLayout(){
  try{layout=normalizeLayout(JSON.parse(localStorage.getItem(layoutKey)||'{}'));}catch(e){layout={};}
  try{
    const r=await fetch(`${api}?action=layout`,{credentials:'same-origin',cache:'no-store'});
    if(r.ok){const data=await r.json();if(data.ok&&data.layout&&Object.keys(data.layout).length){layout=normalizeLayout(data.layout);localStorage.setItem(layoutKey,JSON.stringify(layout));}}
  }catch(e){} // Continue with browser's per-user copy if the server store is unavailable.
}
function applyLayout(){
  root.querySelectorAll('.pl-group[data-group]').forEach(el=>{
    const settings=layout[el.dataset.group];
    if(!settings)return;
    el.style.width=settings.width+'px';
    el.style.height=settings.height+'px';
  });
}
async function saveLayout(){
  try{localStorage.setItem(layoutKey,JSON.stringify(layout));}catch(e){}
  try{
    const r=await fetch(`${api}?action=layout`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Panel-Token':csrf},body:JSON.stringify({layout})});
    if(!r.ok)throw new Error(`HTTP ${r.status}`);
  }catch(e){console.warn('Diseño guardado en este navegador; sincronización con el servidor pendiente:',e);}
}
function beginResizeTracking(){
  if(resizeObserver)resizeObserver.disconnect();
  if(typeof ResizeObserver==='undefined')return;
  resizeObserver=new ResizeObserver(entries=>{
    if(!renderedOnce||dragging||saving)return;
    let changed=false;
    for(const entry of entries){
      const el=entry.target;
      // Only save explicit user changes; the automatic browser grid is not a new preference.
      if(!el.dataset.resizeActive)continue;
      const width=Math.round(el.getBoundingClientRect().width),height=Math.round(el.getBoundingClientRect().height);
      if(width<260||height<90)continue;
      const key=el.dataset.group;
      if(!layout[key]||layout[key].width!==width||layout[key].height!==height){layout[key]={width,height};changed=true;}
    }
    if(changed){clearTimeout(layoutTimer);layoutTimer=setTimeout(saveLayout,450);}
  });
  root.querySelectorAll('.pl-group[data-group]').forEach(el=>{
    resizeObserver.observe(el);
    el.addEventListener('pointerdown',ev=>{
      const r=el.getBoundingClientRect();
      if(r.right-ev.clientX>24||r.bottom-ev.clientY>24)return;
      resizing=true;el.dataset.resizeActive='1';el.classList.add('pl-resizing');
    });
  });
}
function finishResizing(){
  if(!resizing)return;
  resizing=false;
  root.querySelectorAll('.pl-group[data-resize-active]').forEach(el=>{
    const width=Math.round(el.getBoundingClientRect().width),height=Math.round(el.getBoundingClientRect().height);
    if(width>=260&&height>=90){layout[el.dataset.group]={width,height};}
    el.classList.remove('pl-resizing');delete el.dataset.resizeActive;
  });
  clearTimeout(layoutTimer);layoutTimer=setTimeout(saveLayout,150);
}
document.addEventListener('pointerup',finishResizing);
document.addEventListener('pointercancel',finishResizing);

function defaultGroups(){return Array.from({length:17},(_,i)=>({id:i+1,name:`Grupo ${i+1}`}));}
async function loadConfig(){
  try{const r=await fetch(`${api}?action=config`,{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error(`HTTP ${r.status}`);config=await r.json();}
  catch(e){config={groups:defaultGroups(),assignments:{}};}
  if(!Array.isArray(config.groups)||config.groups.length!==17)config.groups=defaultGroups();
  if(!config.assignments||typeof config.assignments!=='object')config.assignments={};
}
function health(ok,text){const el=$('pl-health');el.classList.toggle('ok',!!ok);el.classList.toggle('bad',!ok);$('pl-health-text').textContent=text;}
function statusLabel(e){if(e.status==='incall')return 'En llamada';if(e.status==='ringing')return 'Sonando';if(e.status==='dialing')return 'Marcando';if(e.registered)return 'Disponible';return 'No registrada';}
function fmt(sec){sec=Math.max(0,Math.floor(sec||0));const h=Math.floor(sec/3600),m=Math.floor((sec%3600)/60),s=sec%60;return h>0?`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`:`${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;}
function cardHtml(e){
  const remote=e.remote_number||e.remote_name||'';
  const active=e.call_since?`<span class="pl-remote">${esc(remote||'Llamada activa')}</span><span class="pl-timer" data-since="${Number(e.call_since)}">00:00</span>`:`<span class="pl-remote">${e.registered?'Libre':'Sin registro'}</span><span></span>`;
  return `<div class="pl-card status-${esc(e.status)}" data-ext="${esc(e.extension)}" ${canMove?'draggable="true"':''}>
    <div class="pl-card-top"><div><span class="pl-ext">${esc(e.extension)}</span><span class="pl-desc">${esc(e.description||'')}</span></div><span class="pl-badge">${esc(statusLabel(e))}</span></div>
    <div class="pl-line">${active}</div><div class="pl-tech">${esc(e.tech||'')} ${e.ip?`• ${esc(e.ip)}`:''}</div></div>`;
}
function groupHtml(g,items){
  const cards=items.length?items.map(cardHtml).join(''):'<div class="pl-empty">Sin extensiones asignadas</div>';
  return `<section class="pl-group" data-group="${g.id}"><div class="pl-group-head"><div><span>${esc(g.name)}</span> <span class="pl-group-count">(${items.length})</span></div>${isAdmin?'<button type="button" class="pl-rename" title="Renombrar grupo">✎</button>':''}</div><div class="pl-cards">${cards}</div></section>`;
}
function render(){
  if(!state||!Array.isArray(state.extensions))return;
  let list=state.extensions;
  const q=search.trim().toLowerCase();
  if(q)list=list.filter(e=>String(e.extension).toLowerCase().includes(q)||String(e.description||'').toLowerCase().includes(q));
  $('pl-total').textContent=state.summary?.total??state.extensions.length;
  $('pl-registered').textContent=state.summary?.registered??0;$('pl-unregistered').textContent=state.summary?.unregistered??0;
  $('pl-ringing').textContent=state.summary?.ringing??0;$('pl-incall').textContent=state.summary?.incall??0;
  const by={};config.groups.forEach(g=>by[g.id]=[]);const un=[];
  list.forEach(e=>{const gid=Number(config.assignments[e.extension]||0);if(by[gid])by[gid].push(e);else un.push(e);});
  const sortFn=(a,b)=>String(a.extension).localeCompare(String(b.extension),undefined,{numeric:true});Object.values(by).forEach(a=>a.sort(sortFn));un.sort(sortFn);
  $('pl-groups').innerHTML=config.groups.map(g=>groupHtml(g,by[g.id])).join('');
  $('pl-unassigned-wrap').innerHTML=`<section class="pl-group" data-group="55"><div class="pl-group-head"><div>Sin asignar (55) <span class="pl-group-count">(${un.length})</span></div></div><div class="pl-cards">${un.length?un.map(cardHtml).join(''):'<div class="pl-empty">Arrastrá extensiones aquí para dejarlas sin asignar</div>'}</div></section>`;
  applyLayout();bindDnD();beginResizeTracking();renderedOnce=true;tickTimers();
}
function tickTimers(){const now=Math.floor(Date.now()/1000);document.querySelectorAll('.pl-timer[data-since]').forEach(el=>{el.textContent=fmt(now-Number(el.dataset.since||now));});}
async function saveConfig(){const r=await fetch(`${api}?action=config`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Panel-Token':csrf},body:JSON.stringify({groups:config.groups,assignments:config.assignments})});if(!r.ok)throw new Error(`HTTP ${r.status}`);config=await r.json();}
async function moveExtension(ext,gid){
  const response=await fetch(`${api}?action=move`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Panel-Token':csrf},body:JSON.stringify({extension:ext,group:gid})});
  const result=await response.json().catch(()=>({}));
  if(!response.ok||!result.ok)throw new Error(result.error||`HTTP ${response.status}`);
  if(gid===55)delete config.assignments[ext];else config.assignments[ext]=gid;
}
function bindDnD(){
  if(canMove){
    document.querySelectorAll('.pl-card').forEach(card=>{
      card.addEventListener('dragstart',ev=>{
        if(saving){ev.preventDefault();return;}
        dragging=true;card.classList.add('dragging');
        ev.dataTransfer.setData('text/plain',card.dataset.ext);ev.dataTransfer.effectAllowed='move';
      });
      card.addEventListener('dragend',()=>{dragging=false;document.querySelectorAll('.drag-over,.dragging').forEach(x=>x.classList.remove('drag-over','dragging'));});
    });
    document.querySelectorAll('.pl-group').forEach(group=>{
      group.addEventListener('dragover',ev=>{if(!saving){ev.preventDefault();ev.dataTransfer.dropEffect='move';group.classList.add('drag-over');}});
      group.addEventListener('dragleave',ev=>{if(!group.contains(ev.relatedTarget))group.classList.remove('drag-over');});
      group.addEventListener('drop',async ev=>{
        ev.preventDefault();group.classList.remove('drag-over');
        if(saving)return;
        const ext=ev.dataTransfer.getData('text/plain'),gid=Number(group.dataset.group);
        if(!ext||!(gid===55||(gid>=1&&gid<=17)))return;
        dragging=false;saving=true;
        try{await moveExtension(ext,gid);showWarning('');render();}
        catch(e){showWarning('No se pudo mover la extensión: '+e.message);await loadConfig();render();}
        finally{saving=false;}
      });
    });
  }
  if(isAdmin)document.querySelectorAll('.pl-rename').forEach(btn=>btn.addEventListener('click',async()=>{
    const section=btn.closest('.pl-group'),gid=Number(section.dataset.group),g=config.groups.find(x=>Number(x.id)===gid);
    if(!g)return;const name=prompt('Nombre del grupo:',g.name);if(name===null)return;
    const v=name.trim();if(!v)return;g.name=v.slice(0,80);render();
    try{await saveConfig();}catch(e){showWarning('No se pudo guardar el nombre del grupo.');await loadConfig();render();}
  }));
}
function showWarning(msg){const w=$('pl-warning');w.textContent=msg;w.hidden=!msg;}
async function poll(){if(inflight)return;inflight=true;try{const headers={};if(etag)headers['If-None-Match']=etag;const r=await fetch(`${api}?action=state&t=${Date.now()}`,{credentials:'same-origin',cache:'no-store',headers});if(r.status===304){health(true,'En línea');return;}if(!r.ok)throw new Error(`HTTP ${r.status}`);etag=r.headers.get('ETag')||etag;const next=await r.json();if(next&&next.ok!==false){state=next;if(!dragging&&!saving&&!resizing)render();const age=Math.max(0,Math.floor(Date.now()/1000-(state.generated_at||0)));health(age<8,age<8?'En línea':`Demora ${age}s`);showWarning(age>15?'El servicio de monitoreo lleva más de 15 segundos sin actualizar.':'');$('pl-last-update').textContent=` • último estado ${age}s`;}}
catch(e){health(false,'Sin conexión');showWarning(`No se pudo consultar el estado: ${e.message}`);}finally{inflight=false;schedule();}}
function schedule(){clearTimeout(pollTimer);pollTimer=setTimeout(poll,document.hidden?5000:1000);}
$('pl-search').addEventListener('input',ev=>{search=ev.target.value;render();});document.addEventListener('visibilitychange',()=>{clearTimeout(pollTimer);poll();});setInterval(tickTimers,1000);
(async()=>{await Promise.all([loadConfig(),loadLayout()]);render();poll();})();
})();
