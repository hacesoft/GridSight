/**
 * Hacesoft GridSight – legenda maďarské notace proměnných.
 * Historie: 2026-11-21 – revize vnitřních názvů, komentářů a typových předpon.
 * Historie: 2026-11-22 – kontrola typů, callbacků a konkrétních návratových hodnot.
 * s – řetězec, identifikátor, formátovaná hodnota.
 * n – číslo, časový údaj či souřadnice.
 * b – logický stav (true/false).
 * a – pole či posloupnost prvků.
 * o – objekt, DOM prvek, událost nebo výjimka.
 * v – hodnota s nejednoznačným či proměnlivým typem.
 * m – mapa klíčů na hodnoty.
 * fn – funkce nebo callback.
 * Výjimky: názvy konstant a externích API polí se nemění kvůli kompatibilitě.
 */
(/**
 * Inicializuje soukromý JavaScript modul, zaregistruje jeho obsluhu a izoluje proměnné od ostatních aplikací.
 * Vstupy: žádné.
 * Výstup: `void` – provede inicializační a životní cyklus modulu bez veřejné návratové hodnoty.
 */ () => {
'use strict';
const APP='hc_gridsight';
/**
 * Bezpečně escapuje text před vložením do HTML.
 * Vstupy: `sS` (string) – textový identifikátor nebo popisek.
 * Výstup: `string` – HTML-safe text s escapovanými nebezpečnými znaky.
 */
const esc=sS=>String(sS??'').replace(/[&<>'"]/g,/**
 * Nahradí speciální HTML znaky jejich bezpečnými entitami podle převodní tabulky.
 * Vstupy: `vC` (mixed) – znak pro HTML escapování.
 * Výstup: `string` – příslušná escapovaná HTML entita.
 */ vC=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[vC]));
/**
 * Zobrazí hodnotu widgetu jako číslo nebo pomlčku při nedostupnosti.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `number|string` – zaokrouhlená číselná hodnota nebo pomlčka při nedostupnosti.
 */
const fnNumber=vV=>vV!==null&&vV!==undefined&&vV!==''&&Number.isFinite(Number(vV))?Math.round(Number(vV)):'—';
OCA.Dashboard.register(APP,/**
 * Předá inicializační funkci rozhraní.
 * Vstupy: `oEl` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ (oEl)=>{
 const sUrl=OC.generateUrl('/apps/'+APP+'/');
 oEl.innerHTML=`<div class="lm-widget"><div id="lmw-status" class="lm-widget-status">${esc(t(APP,'Connecting'))}</div><div class="lm-widget-grid"><div title="${esc(t(APP,'Total instantaneous PV power.'))}"><span>☀ ${esc(t(APP,'PV'))}</span><strong id="lmw-pv">—</strong></div><div title="${esc(t(APP,'Total instantaneous house consumption.'))}"><span>⌂ ${esc(t(APP,'House'))}</span><strong id="lmw-house">—</strong></div><div title="${esc(t(APP,'Power flow between the site and the grid: positive import, negative export.'))}"><span>⇄ ${esc(t(APP,'Grid'))}</span><strong id="lmw-grid">—</strong></div><div title="${esc(t(APP,'Current battery state of charge.'))}"><span>▰ ${esc(t(APP,'Battery'))}</span><strong id="lmw-batt">—</strong></div></div><a class="button primary" href="${sUrl}">${esc(t(APP,'Open LINEA Monitor'))}</a></div>`;
  /**
 * Načte aktuální stav LINEA pro widget Nextcloudu, ověří schéma API a vypíše dostupné hodnoty.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – aktualizuje měření nebo stav „LINEA offline“.
 */
 const load=async()=>{try{const vR=await fetch(OC.generateUrl('/apps/'+APP+'/api/linea/status'),{cache:'no-store',headers:{requesttoken:OC.requestToken}});const vJ=await vR.json();if(!vR.ok||!vJ.ok)throw new Error(vJ.error||t(APP,'Offline'));const vStatus=vJ.data;const nMajor=Number.parseInt(String(vStatus?.api?.version??'').split('.')[0],10);if(vStatus?.api?.name!=='LINEA API'||nMajor!==1||vStatus?.api?.readOnly!==true)throw new Error(t(APP,'Incompatible API'));const vE=vStatus.energy?.available===true?vStatus.energy:{};document.getElementById('lmw-pv').textContent=fnNumber(vE.pv?.powerW)+' W';document.getElementById('lmw-house').textContent=fnNumber(vE.house?.powerW)+' W';document.getElementById('lmw-grid').textContent=fnNumber(vE.grid?.powerW)+' W';document.getElementById('lmw-batt').textContent=fnNumber(vE.battery?.socPct)+' %';document.getElementById('lmw-status').textContent=vStatus.system?.stale?t(APP,'Stale data'):t(APP,'LINEA online');}catch(oE){document.getElementById('lmw-status').textContent=t(APP,'LINEA offline');}};load();setInterval(load,10000);
 });
})();
