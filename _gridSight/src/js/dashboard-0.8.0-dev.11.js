(() => {
'use strict';
const APP='hc_gridsight';
const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const n=v=>v!==null&&v!==undefined&&v!==''&&Number.isFinite(Number(v))?Math.round(Number(v)):'—';
OCA.Dashboard.register(APP,(el)=>{
 const url=OC.generateUrl('/apps/'+APP+'/');
 el.innerHTML=`<div class="lm-widget"><div id="lmw-status" class="lm-widget-status">${esc(t(APP,'Connecting'))}</div><div class="lm-widget-grid"><div title="${esc(t(APP,'Total instantaneous PV power.'))}"><span>☀ ${esc(t(APP,'PV'))}</span><strong id="lmw-pv">—</strong></div><div title="${esc(t(APP,'Total instantaneous house consumption.'))}"><span>⌂ ${esc(t(APP,'House'))}</span><strong id="lmw-house">—</strong></div><div title="${esc(t(APP,'Power flow between the site and the grid: positive import, negative export.'))}"><span>⇄ ${esc(t(APP,'Grid'))}</span><strong id="lmw-grid">—</strong></div><div title="${esc(t(APP,'Current battery state of charge.'))}"><span>▰ ${esc(t(APP,'Battery'))}</span><strong id="lmw-batt">—</strong></div></div><a class="button primary" href="${url}">${esc(t(APP,'Open LINEA Monitor'))}</a></div>`;
 const load=async()=>{try{const r=await fetch(OC.generateUrl('/apps/'+APP+'/api/linea/status'),{cache:'no-store',headers:{requesttoken:OC.requestToken}});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||t(APP,'Offline'));const s=j.data;const major=Number.parseInt(String(s?.api?.version??'').split('.')[0],10);if(s?.api?.name!=='LINEA API'||major!==1||s?.api?.readOnly!==true)throw new Error(t(APP,'Incompatible API'));const e=s.energy?.available===true?s.energy:{};document.getElementById('lmw-pv').textContent=n(e.pv?.powerW)+' W';document.getElementById('lmw-house').textContent=n(e.house?.powerW)+' W';document.getElementById('lmw-grid').textContent=n(e.grid?.powerW)+' W';document.getElementById('lmw-batt').textContent=n(e.battery?.socPct)+' %';document.getElementById('lmw-status').textContent=s.system?.stale?t(APP,'Stale data'):t(APP,'LINEA online');}catch(e){document.getElementById('lmw-status').textContent=t(APP,'LINEA offline');}};load();setInterval(load,10000);
 });
})();
