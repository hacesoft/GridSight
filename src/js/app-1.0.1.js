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
const EXPECTED_API_SCHEMA=1;
let fnCurrentCleanup=null;
let bPageClosed=false,bStarted=false;

/**
 * Zobrazí chybový stav při neúspěšné inicializaci aplikace.
 * Vstupy: `oRoot` (object) – kořenový DOM prvek; `sTitle` (string) – nadpis chybového panelu; `sDetail` (string) – podrobná zpráva o chybě.
 * Výstup: `void` – nahradí kontejner přístupným upozorněním o chybě inicializace.
 */
function showStartupError(oRoot,sTitle,sDetail){
 oRoot.className='hc-app-startup-error';
 oRoot.replaceChildren();
 const oHeading=document.createElement('h2');oHeading.textContent=sTitle;
 const oMessage=document.createElement('p');oMessage.textContent=sDetail;
 oRoot.append(oHeading,oMessage);
}

/**
 * Připojí hlavní rozhraní do Nextcloudu a zaregistruje jeho životní cyklus.
 * Vstupy: `oBootstrapRoot` (object) – DOM kontejner pro aplikaci Nextcloud; `CORE` (mixed) – instance Hacesoft Shared App Core.
 * Výstup: `Promise<function>` – po inicializaci vrátí funkci pro korektní odpojení aplikace.
 */
async function mountApplication(oBootstrapRoot,CORE){
 let bDisposed=false;
 const oLifetime=new AbortController();
 const APP_VERSION=String(oBootstrapRoot.dataset.appVersion||'');
 const CORE_MIN_VERSION=String(oBootstrapRoot.dataset.requiredCoreVersion||'0.18.8');
 CORE.about.register({id:APP,name:'GridSight',version:APP_VERSION,repository:'https://github.com/hacesoft/GridSight',releaseNotes:'https://github.com/hacesoft/GridSight/releases',documentation:'https://github.com/hacesoft/GridSight#readme',core:'>='+CORE_MIN_VERSION});
 const oShellTemplate=document.getElementById('lm-app-shell-template');
 if(!(oShellTemplate instanceof HTMLTemplateElement))throw new Error('LINEA Monitor shell template is missing.');
 const oShellFragment=oShellTemplate.content.cloneNode(true);
 oBootstrapRoot.replaceChildren(oShellFragment);
 oShellTemplate.remove();
 oBootstrapRoot.classList.add('lm-app','hc-shared-app-core-layout');
 let nLastLayoutWidth=0;
  /**
 * Při změně skutečné šířky pracovní plochy naplánuje nové vykreslení grafu historie či denního SPOT přehledu.
 * Vstupy: `{width}` (object) – nová šířka pracovního prostoru od Shared App Core.
 * Výstup: `void` – při stejné šířce nic neprovede, jinak aktualizuje graf v následujícím snímku.
 */
 const fnHandleCoreLayoutResize=({width})=>{
  // Pinch changes visualViewport, not the CSS width of the allocated app area.
  if(width===nLastLayoutWidth)return;
  nLastLayoutWidth=width;
  window.requestAnimationFrame(/**
 * Provede naplánované překreslení grafu při následujícím animačním snímku.
 * Vstupy: žádné.
 * Výstup: void – změna grafu bez návratové hodnoty.
 */ ()=>{
   if(bDisposed)return;
   if(activeTab()==='history'&&aHistoryPoints.length>1)renderPowerChart(aHistoryPoints);
   else if(activeTab()==='live'&&vLatestStatus)renderSpotSchedule(vLatestStatus);
  });
 };
 const oLayoutController=CORE.layout.observe(oBootstrapRoot,{
  heightMode:'container',
  topOffset:0,
  onResize:fnHandleCoreLayoutResize,
 });
 oLayoutController.refresh('manual');
const LOGGER=CORE.logger;
/**
 * Vrátí DOM prvek GridSight podle HTML ID.
 * Vstupy: `sId` (string) – ID HTML prvku nebo šablony.
 * Výstup: `HTMLElement|null` – nalezený prvek nebo null.
 */
const $=sId=>document.getElementById(sId);
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
 * Rozpozná dostupnou konečnou číselnou hodnotu.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `boolean` – true pouze pro dostupnou konečnou číselnou hodnotu.
 */
const isValue=vV=>vV!==null&&vV!==undefined&&vV!==''&&Number.isFinite(Number(vV));
/**
 * Převede číslo na formátovaný text nebo pomlčku při nedostupnosti.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null; `nD` (number) – počet desetinných míst.
 * Výstup: `string` – číslo na požadovaná desetinná místa, jinak pomlčka.
 */
const num=(vV,nD=0)=>isValue(vV)?Number(vV).toFixed(nD):'—';
/**
 * Převede logický stav na lokalizovaný zapnuto/vypnuto.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `string` – lokalizované „Zapnuto“/„Vypnuto“ nebo pomlčka.
 */
const onoff=vV=>vV===true?t(APP,'On'):vV===false?t(APP,'Off'):'—';
/**
 * Převede logický stav na lokalizované ano/ne.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `string` – lokalizované „Ano“/„Ne“ nebo pomlčka.
 */
const yesno=vV=>vV===true?t(APP,'Yes'):vV===false?t(APP,'No'):'—';
const HELP={
 'energy.pv.powerW':t(APP,'Total instantaneous PV power. Source: LINEA / MPPT.'),
 'energy.pv.strings[].powerW':t(APP,'Instantaneous power of a specific MPPT string.'),
 'energy.pv.strings[].pvVoltageV':t(APP,'PV voltage of a specific MPPT string.'),
 'energy.pv.strings[].pvCurrentA':t(APP,'Calculated current of the PV string (power / PV voltage).'),
 'energy.pv.strings[].yieldTodayKWh':t(APP,"Today's yield of a specific MPPT string."),
 'energy.house.powerW':t(APP,'Total instantaneous house consumption.'),
 'energy.house.phases':t(APP,'Instantaneous house consumption split across phases L1, L2 and L3.'),
 'energy.grid.powerW':t(APP,'Power flow between the site and the grid. Positive means import, negative means export.'),
 'energy.grid.phases':t(APP,'Power flow between the site and the grid on each phase. Positive is import, negative is export.'),
 'energy.battery.socPct':t(APP,'Current battery state of charge.'),
 'energy.battery.powerW':t(APP,'Instantaneous battery power. Positive means charging, negative means discharging.'),
 'energy.battery.currentA':t(APP,'Instantaneous battery current.'),
 'energy.battery.voltageV':t(APP,'Current battery voltage.'),
 'energy.battery.batteryLifeSocLimitPct':t(APP,'Lower BatteryLife / ESS SOC limit.'),
 'ess.decision.gridPointW':t(APP,'Resulting requested Grid Point calculated by LINEA control logic.'),
 'ess.decision.predictionActive':t(APP,'Whether prediction logic is active in the current decision.'),
 'ess.decision.exportAllowed':t(APP,'Whether the current LINEA decision allows grid export.'),
 'ess.decision.reason':t(APP,'Machine-readable and optionally human-readable description of the current ESS decision.'),
 'ess.switches':t(APP,'Current states of the main ESS switches and strategies in LINEA.'),
 'ess.switches.controlModeEssAcGrid':t(APP,'Selects the ESS control target. On = AC Grid Set Point 2716/2717 (INT32, runtime RAM value refreshed periodically). Off = legacy ESS Control Loop Setpoint 2700 (INT16, written only on change).'),
 'ess.switches.spotGridCharging':t(APP,'Automatic battery charging from the grid during the cheapest continuous block of hours. Uses charging duration, maximum acceptable price and MAX Grid Point.'),
 'ess.switches.gridCharging':t(APP,'Immediate battery charging from the grid to the configured target SOC. The function turns off after the target SOC is reached.'),
 'ess.switches.gridConsumption':t(APP,'Allows use of the configured Grid Point / grid consumption within the main ESS logic.'),
 'ess.switches.energyThresholdInjector':t(APP,'A specific function that can set Grid Point when sufficient PV surplus is available. Surplus is evaluated from PV production, house consumption and balancing reserve.'),
 'ess.switches.nonBatteryPriority':t(APP,'Mode that limits unnecessary battery discharge at high consumption; missing power may be taken from the grid instead.'),
 'ess.switches.delayCharging':t(APP,'Delays normal battery charging during the configured time window so capacity remains available for expected later PV production.'),
 'ess.switches.dynamicSocReserve':t(APP,'Dynamically raises the morning SOC reserve so the battery has enough energy until usable PV production begins.'),
 'ess.switches.predictionThreshold':t(APP,'Requires sufficient predicted production for selected operations. Used especially for Morning Peak and Delay Charging.'),
 'ess.switches.socDeltaBeforeExport':t(APP,'During Delay Charging, allows export only after the defined SOC threshold is reached so a minimum energy reserve remains.'),
 'ess.switches.morningPeakBatterySales':t(APP,'Battery energy sale during the selected morning price peak when SOC, price, export and optional prediction conditions are met.'),
 'ess.switches.eveningPeakBatterySales':t(APP,'Battery energy sale during the selected evening price peak when SOC, price and export permission conditions are met.'),
 'ess.settings.balancingReserveW':t(APP,'Balancing power reserve used by LINEA.'),
 'ess.settings.setGridValueW':t(APP,'User-configured grid target value.'),
 'ess.settings.maxGridPointW':t(APP,'Power limit used for some battery operations, for example Morning/Evening Peak, GRID CHARGING and Spot-Grid Charging. It is not the same as Maximum Grid Feed-In register 2706.'),
 'ess.settings.spotThresholdPrice':t(APP,'Price threshold of the SPOT strategy.'),
 'ess.settings.morningSocSalesPct':t(APP,'Minimum fixed SOC reserve for morning battery sale. With Dynamic SOC Reserve active, the resulting reserve may be increased.'),
 'ess.settings.eveningSocSalesPct':t(APP,'Minimum SOC threshold for evening battery sale during the price peak.'),
 'ess.settings.gridChargingSocPct':t(APP,'Target SOC for manual GRID CHARGING. Grid charging turns itself off after this SOC is reached.'),
 'ess.settings.predictionThresholdKWh':t(APP,'Minimum predicted production energy threshold used by Prediction Threshold.'),
 'ess.settings.socDeltaBeforeExportPct':t(APP,'SOC threshold after which surplus export may be allowed during Delay Charging.'),
 'ess.settings.chargingDurationGridH':t(APP,'Number of hours for automatic Spot-Grid Charging. The cheapest continuous block of this duration is selected.'),
 'ess.settings.acceptablePriceGrid':t(APP,'Maximum acceptable SPOT price for automatic Spot-Grid Charging. Automatic charging does not start above this price.'),
 'ess.time.delayCharging':t(APP,'Time window in which normal battery charging is delayed so capacity remains available for expected later PV production.'),
 'spot.currentPrice':t(APP,'Current SPOT price used by LINEA. The price may also be negative.'),
 'forecast.solarYieldForecastKWh':t(APP,'Predicted PV production.'),
 'forecast.consumptionForecastKWh':t(APP,'Predicted site consumption.'),
 'solar':t(APP,'Sunrise, sunset and day length.'),
 'weather.today':t(APP,'Current textual weather summary from the LINEA weather source.'),
 'weather.rainProbabilityPct':t(APP,'Rain probability.'),
 'weather.trend':t(APP,'Weather trend provided by the LINEA source.'),
 'vrm.data.today.pvYieldKWh':t(APP,"Today's PV production according to Victron VRM."),
 'vrm.data.today.consumptionKWh':t(APP,"Today's consumption according to Victron VRM."),
 'vrm.data.today.gridImportKWh':t(APP,"Today's grid import according to Victron VRM."),
 'vrm.data.today.gridExportKWh':t(APP,"Today's grid export according to Victron VRM."),
 'vrm.data.today.batteryChargeKWh':t(APP,'Cumulative battery charge energy since Node-RED restart. This is not guaranteed to be a daily value.'),
 'vrm.data.today.batteryDischargeKWh':t(APP,'Cumulative battery discharge energy since Node-RED restart. This is not guaranteed to be a daily value.'),
 'temperatures':t(APP,'Rack/battery, inverter and other sensor temperatures. Source: Modbus.'),
 'shelly':t(APP,'Current states of selected Shelly inputs and outputs published by LINEA.'),
 'smoke':t(APP,'Shelly smoke detector states. A long time since the last report does not by itself indicate an error because the sensors may sleep.'),
 'ups':t(APP,'UPS operating data obtained through NUT.'),
 'climate':t(APP,'Daikin climate device operating data obtained through Onecta.'),
 'system.ageMs':t(APP,'Age of the main LINEA source snapshot.'),
 'system.stale':t(APP,'Indicates that the main source data exceeded the allowed age.'),
 'available':t(APP,'available=true means LINEA has usable data for the block. available=false means the client must not treat the block content as currently available.'),
 'null':t(APP,'A dash means the API returned null or the value is unavailable. Zero is a valid value.')
};
/**
 * Připraví přístupnou HTML nápovědu pro zvolenou veličinu.
 * Vstupy: `sKey` (string) – technický klíč veličiny či pole; `sText` (string) – zobrazený text.
 * Výstup: `string` – HTML nápověda veličiny se správným popisem z API kontraktu.
 */
const tip=(sKey,sText='?')=>`<span class="lm-help-tip" tabindex="0" title="${esc(HELP[sKey]||sKey)}" aria-label="${esc(HELP[sKey]||sKey)}">${esc(sText)}</span>`;
/**
 * Načte odpověď Nextcloud API a rozliší dočasný výpadek LINEA od skutečné chyby.
 * Vstupy: `sPath` (string) – relativní cesta Nextcloud API; `oOptions` (object) – volitelné nastavení fetch včetně hlaviček a těla.
 * Výstup: `Promise<object>` – dekódovaný JSON, při chybě vyhodí výjimku s odlišením nedostupnosti LINEA.
 */
const api=async(sPath,oOptions={})=>{
 const oHeaders={requesttoken:OC.requestToken,...(oOptions.body instanceof FormData?{}:{'Content-Type':'application/json'}),...(oOptions.headers||{})};
 const bIsLineaEndpoint=['/api/linea/status','/api/linea/health'].includes(sPath);
 let vR,sText;
 try{
  vR=await fetch(OC.generateUrl('/apps/'+APP+sPath),{cache:'no-store',...oOptions,signal:oLifetime.signal,headers: oHeaders});
  sText=await vR.text();
 }catch(oE){
  // Odpojení při restartu Node-RED/HTTP proxy není chyba rozhraní GridSight.
  if(oE?.name==='AbortError')throw oE;
  const oFailure=new Error(bIsLineaEndpoint
   ?t(APP,'LINEA API is temporarily unavailable. Waiting for reconnection.')
   :t(APP,'Request failed'));
  oFailure.code=bIsLineaEndpoint?'linea_api_unavailable':'request_error';
  throw oFailure;
 }
 let oData={};try{oData=sText?JSON.parse(sText):{}}catch(_){oData={}}
 if(!vR.ok||oData.ok===false){
  const oFailure=new Error(oData.error||vR.statusText||t(APP,'Request failed'));
  // 502/503/504 na LINEA endpointu mohou vzniknout během restartu služby.
  oFailure.code=oData.code||(bIsLineaEndpoint&&[502,503,504].includes(vR.status)?'linea_api_unavailable':'request_error');
  throw oFailure;
 }
 return oData;
};
let nRefreshSeconds=2,vTimer=null,bLoading=false,vLatestStatus=null;
let bDailyLoading=false,nLastDailyFetchAt=0,sLastDailyDay='';
const SOFT_REFRESH_MS=5*60*1000;
let vSoftRefreshTimer=null,vSoftRefreshTick=null,nNextSoftRefreshAt=Date.now()+SOFT_REFRESH_MS;
/**
 * Převede datum poslední aktualizace na lidsky čitelný údaj.
 * Vstupy: `sIso` (string) – datum a čas ve formátu ISO.
 * Výstup: `string` – čitelný údaj o stáří poslední aktualizace, nebo pomlčka.
 */
function ageLabel(sIso){if(!sIso)return'—';const nMs=Date.now()-new Date(sIso).getTime();if(!Number.isFinite(nMs))return'—';const nS=Math.max(0,Math.round(nMs/1000));if(nS<60)return nS+' s';const nM=Math.round(nS/60);if(nM<60)return nM+' min';const nH=Math.round(nM/60);if(nH<48)return nH+' h';return Math.round(nH/24)+' d';}
/**
 * Nastaví čistý text v daném prvku stránky.
 * Vstupy: `sId` (string) – ID HTML prvku nebo šablony; `vValue` (mixed) – hodnota měření, která může být nedostupná.
 * Výstup: `void` – aktualizuje textový obsah nalezeného DOM prvku.
 */
function setText(sId,vValue){const oEl=$(sId);if(oEl)oEl.textContent=vValue;}
/**
 * Nastaví titulkovou nápovědu v prvku stránky.
 * Vstupy: `sId` (string) – ID HTML prvku nebo šablony; `vValue` (mixed) – hodnota měření, která může být nedostupná.
 * Výstup: `void` – aktualizuje HTML title nalezeného DOM prvku.
 */
function setTitle(sId,vValue){const oEl=$(sId);if(oEl)oEl.title=vValue||'';}
/**
 * Vykreslí bezpečný štítek pro dostupný logický stav.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `string` – bezpečně sestavený HTML štítek stavu ON/OFF/neznámý.
 */
function boolBadge(vV){return vV===true?'<span class="lm-state on">'+esc(t(APP,'ON'))+'</span>':vV===false?'<span class="lm-state off">'+esc(t(APP,'OFF'))+'</span>':'<span class="lm-state unknown">—</span>';}
/**
 * Vybere měrnou jednotku deklarovanou API nebo náhradní hodnotu.
 * Vstupy: `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API; `sPath` (string) – relativní cesta Nextcloud API; `sFallback` (string) – náhradní jednotka.
 * Výstup: `string` – jednotka daného pole LINEA nebo náhradní hodnota.
 */
function unit(vStatus,sPath,sFallback=''){return vStatus?.units?.[sPath]||sFallback;}
/**
 * Vytvoří text číselné hodnoty s jednotkou.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null; `sU` (string) – jednotka měřené hodnoty; `nD` (number) – počet desetinných míst.
 * Výstup: `string` – formátované měření s jednotkou, jinak pomlčka.
 */
function value(vV,sU='',nD=0){const sNumber=num(vV,nD);return sNumber==='—'?'—':sNumber+(sU?' '+sU:'');}
/**
 * Zkontroluje příznak dostupnosti datového bloku LINEA.
 * Vstupy: `vBlock` (mixed) – stav bloku LINEA s příznakem dostupnosti.
 * Výstup: `boolean` – true jen pro dostupný datový blok LINEA.
 */
function blockAvailable(vBlock){return vBlock?.available===true;}
/**
 * Ověří kompatibilitu a čtecí režim API LINEA.
 * Vstupy: `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API.
 * Výstup: `array` – seznam chybějících či nekompatibilních API položek.
 */
function validateContract(vStatus){
 const aProblems2=[];
 if(vStatus?.api?.name!=='LINEA API')aProblems2.push(t(APP,'Unexpected API:')+' '+(vStatus?.api?.name??'—'));
 if(Number(vStatus?.api?.schema)!==EXPECTED_API_SCHEMA)aProblems2.push(t(APP,'Unsupported LINEA API schema')+' '+(vStatus?.api?.schema??'—')+' ('+EXPECTED_API_SCHEMA+')');
 if(vStatus?.api?.readOnly!==true)aProblems2.push(t(APP,'API is not marked as read-only'));
 return aProblems2;
}
/**
 * Vykreslí živé hodnoty FVE, sítě, domu, baterie a zařízení.
 * Vstupy: `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API.
 * Výstup: `void` – aktualizuje všechny živé energetické, ESS a doplňkové panely.
 */
function renderStatus(vStatus){
 const vContractProblems=validateContract(vStatus);
 if(vContractProblems.length)throw new Error(vContractProblems.join('. '));
 vLatestStatus=vStatus;
 const vEnergyOk=blockAvailable(vStatus.energy),vE=vEnergyOk?(vStatus.energy||{}):{},vPv=vE.pv||{},vHouse=vE.house||{},vGrid=vE.grid||{},vBatt=vE.battery||{};
 setText('lm-pv-power',num(vPv.powerW));setTitle('lm-pv-power',HELP['energy.pv.powerW']);
 setText('lm-house-power',num(vHouse.powerW));setTitle('lm-house-power',HELP['energy.house.powerW']);
 setText('lm-grid-power',num(vGrid.powerW));setTitle('lm-grid-power',HELP['energy.grid.powerW']);
 if(isValue(vGrid.powerW)) setText('lm-grid-direction',Number(vGrid.powerW)<0?t(APP,'W sale'):Number(vGrid.powerW)>0?t(APP,'W purchase'):'W'); else setText('lm-grid-direction','W');
 setText('lm-battery-soc',num(vBatt.socPct));setTitle('lm-battery-soc',HELP['energy.battery.socPct']);
 setText('lm-battery-power',(isValue(vBatt.powerW)&&Number(vBatt.powerW)>0?'+':'')+value(vBatt.powerW,unit(vStatus,'energy.battery.powerW','W')));setTitle('lm-battery-power',HELP['energy.battery.powerW']);
 const vFill=$('lm-battery-fill');if(vFill)vFill.style.width=isValue(vBatt.socPct)?Math.max(0,Math.min(100,Number(vBatt.socPct)))+'%':'0%';
 setText('lm-batt-current',value(vBatt.currentA,unit(vStatus,'energy.battery.currentA','A'),1));
 setText('lm-batt-voltage',value(vBatt.voltageV,unit(vStatus,'energy.battery.voltageV','V'),1));
 setText('lm-batt-limit',value(vBatt.batteryLifeSocLimitPct,unit(vStatus,'energy.battery.batteryLifeSocLimitPct','%'),1));
 const aStrings=Array.isArray(vPv.strings)?vPv.strings:[];
 const aValidYields=aStrings.filter(/**
 * Vybere z kolekce aStrings jen položky splňující podmínku nad yieldTodayKWh.
 * Vstupy: `vString` (mixed) – FV string s měřeným výkonem a denní výrobou.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vString=>isValue(vString.yieldTodayKWh));
 const vTotalYield=aValidYields.length?aValidYields.reduce(/**
 * Přičte nebo sloučí údaje položky aValidYields do průběžného akumulátoru podle polí yieldTodayKWh.
 * Vstupy: `vA` (mixed) – dosavadní akumulovaná hodnota; `vString` (mixed) – FV string s měřeným výkonem a denní výrobou.
 * Výstup: nová průběžná akumulovaná hodnota.
 */ (vA,vString)=>vA+Number(vString.yieldTodayKWh),0):null;
 setText('lm-pv-yield',value(vTotalYield,'kWh',2));
 $('lm-pv-strings').innerHTML=aStrings.length?aStrings.map(/**
 * Převede položku kolekce aStrings na HTML záznam pro UI z polí name, pvVoltageV, pv.
 * Vstupy: `vString` (mixed) – FV string s měřeným výkonem a denní výrobou.
 * Výstup: `string` – HTML karta měřeného FV stringu s napětím a výkonem
 */ vString=>`<div class="lm-string"><div><strong>${esc(vString.name)}</strong><small>${value(vString.pvVoltageV,unit(vStatus,'energy.pv.strings[].pvVoltageV','V'),2)} · ${value(vString.pvCurrentA,unit(vStatus,'energy.pv.strings[].pvCurrentA','A'),1)} ${tip('energy.pv.strings[].pvVoltageV')}</small></div><div><strong title="${esc(HELP['energy.pv.strings[].powerW'])}">${value(vString.powerW,unit(vStatus,'energy.pv.strings[].powerW','W'))}</strong><small title="${esc(HELP['energy.pv.strings[].yieldTodayKWh'])}">${value(vString.yieldTodayKWh,unit(vStatus,'energy.pv.strings[].yieldTodayKWh','kWh'),1)}</small></div></div>`).join(''):'<div class="lm-empty">—</div>';
 const vGp=vGrid.phases||{},vHp=vHouse.phases||{};['l1','l2','l3'].forEach(/**
 * Projde položky kolekce ['l1','l2','l3'] a aktualizuje odpovídající prvky rozhraní.
 * Vstupy: `sPhase` (string) – identifikátor elektrické fáze L1, L2 nebo L3.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ sPhase=>{setText('lm-grid-'+sPhase,value(vGp[sPhase+'PowerW'],'W'));setText('lm-house-'+sPhase,value(vHp[sPhase+'PowerW'],'W'));});
 const vG=isValue(vGrid.powerW)?Number(vGrid.powerW):null;setText('lm-grid-badge',vG===null?'—':vG<0?t(APP,'To grid'):vG>0?t(APP,'From grid'):t(APP,'Balanced'));setText('lm-grid-tech-total',value(vGrid.powerW,'W'));setText('lm-house-tech-total',value(vHouse.powerW,'W'));
 const vEssOk=blockAvailable(vStatus.ess),vEss=vEssOk?(vStatus.ess||{}):{},vDec=vEss.decision||{};
 setText('lm-grid-point',value(vDec.gridPointW,unit(vStatus,'ess.decision.gridPointW','W')));setText('lm-prediction',onoff(vDec.predictionActive));setText('lm-ess-state',vEssOk?t(APP,'Active'):t(APP,'Unavailable'));
 const sReason=vDec.reason||{};setText('lm-ess-reason',[sReason.code,sReason.text].filter(Boolean).join(' · ')||'—');
 renderEssDetails(vStatus,vEss);
 setText('lm-spot',blockAvailable(vStatus.spot)?value(vStatus.spot.currentPrice,unit(vStatus,'spot.currentPrice','CZK/kWh'),2):'—');
 const vSalePermission=$('lm-spot-export-state');
 if(vSalePermission){vSalePermission.textContent=vDec.exportAllowed===true?t(APP,'Allowed'):vDec.exportAllowed===false?t(APP,'Blocked'):'—';vSalePermission.className='lm-spot-permission '+(vDec.exportAllowed===true?'allowed':vDec.exportAllowed===false?'blocked':'unknown');}
 setText('lm-spot-threshold',value(vEss?.settings?.spotThresholdPrice,'CZK/kWh',2));
 setText('lm-forecast-pv',blockAvailable(vStatus.forecast)?value(vStatus.forecast.solarYieldForecastKWh,unit(vStatus,'forecast.solarYieldForecastKWh','kWh'),1):'—');
 setText('lm-forecast-cons',blockAvailable(vStatus.forecast)?value(vStatus.forecast.consumptionForecastKWh,unit(vStatus,'forecast.consumptionForecastKWh','kWh'),1):'—');
 setText('lm-spot-pv-forecast',blockAvailable(vStatus.forecast)?value(vStatus.forecast.solarYieldForecastKWh,unit(vStatus,'forecast.solarYieldForecastKWh','kWh'),1):'—');
 setText('lm-spot-cons-forecast',blockAvailable(vStatus.forecast)?value(vStatus.forecast.consumptionForecastKWh,unit(vStatus,'forecast.consumptionForecastKWh','kWh'),1):'—');
 setText('lm-sun',blockAvailable(vStatus.solar)?[vStatus.solar.sunrise,vStatus.solar.sunset].filter(Boolean).join(' – ')||'—':'—');
 setText('lm-day-length',blockAvailable(vStatus.solar)?(vStatus.solar.dayLength||'—'):'—');
 setText('lm-weather-today',blockAvailable(vStatus.weather)?(vStatus.weather.today||'—'):'—');
 setText('lm-weather-rain',blockAvailable(vStatus.weather)?value(vStatus.weather.rainProbabilityPct,unit(vStatus,'weather.rainProbabilityPct','%')):'—');
 setText('lm-weather-trend',blockAvailable(vStatus.weather)?(vStatus.weather.trend||'—'):'—');
 const vVrmOk=blockAvailable(vStatus.vrm),vVd=vVrmOk?(vStatus.vrm?.data||{}):{},vVrm=vVd.today||{};setText('lm-vrm-updated',vVrmOk?ageLabel(vVd.updatedAt):'—');
 setText('lm-vrm-pv',value(vVrm.pvYieldKWh,unit(vStatus,'vrm.data.today.pvYieldKWh','kWh'),2));setText('lm-vrm-cons',value(vVrm.consumptionKWh,unit(vStatus,'vrm.data.today.consumptionKWh','kWh'),2));setText('lm-vrm-import',value(vVrm.gridImportKWh,unit(vStatus,'vrm.data.today.gridImportKWh','kWh'),2));setText('lm-vrm-export',value(vVrm.gridExportKWh,unit(vStatus,'vrm.data.today.gridExportKWh','kWh'),2));setText('lm-vrm-batt-charge',value(vVrm.batteryChargeKWh,unit(vStatus,'vrm.data.today.batteryChargeKWh','kWh'),3));setText('lm-vrm-batt-discharge',value(vVrm.batteryDischargeKWh,unit(vStatus,'vrm.data.today.batteryDischargeKWh','kWh'),3));
 // Compact daily energy balance: actual VRM values + LINEA day forecasts.
 const vFc=blockAvailable(vStatus.forecast)?(vStatus.forecast?.data||vStatus.forecast||{}):{};
 const vPvToday=vVrm.pvYieldKWh,vConsToday=vVrm.consumptionKWh,vPvForecast=vFc.solarYieldForecastKWh,vConsForecast=vFc.consumptionForecastKWh;
 setText('lm-balance-pv-today',value(vPvToday,'kWh',2));setText('lm-balance-pv-forecast',value(vPvForecast,'kWh',1));
 setText('lm-balance-house-today',value(vConsToday,'kWh',2));setText('lm-balance-house-forecast',value(vConsForecast,'kWh',1));
 setText('lm-balance-import',historyNum(vVrm.gridImportKWh,2));setText('lm-balance-export',historyNum(vVrm.gridExportKWh,2));setText('lm-balance-soc',num(vBatt.socPct));
 const nDayGridBalance=isValue(vVrm.gridImportKWh)&&isValue(vVrm.gridExportKWh)?Number(vVrm.gridImportKWh)-Number(vVrm.gridExportKWh):null;setText('lm-grid-day-balance',nDayGridBalance===null?'—':(nDayGridBalance>0?'+':'')+nDayGridBalance.toFixed(2)+' kWh');
 const nBatteryEnergyBalance=isValue(vVrm.batteryChargeKWh)&&isValue(vVrm.batteryDischargeKWh)?Number(vVrm.batteryChargeKWh)-Number(vVrm.batteryDischargeKWh):null;
 setText('lm-battery-energy-balance',nBatteryEnergyBalance===null?'—':(nBatteryEnergyBalance>0?'+':'')+nBatteryEnergyBalance.toFixed(2)+' kWh');
 setText('lm-balance-charge',historyNum(vVrm.batteryChargeKWh,2));setText('lm-balance-discharge',historyNum(vVrm.batteryDischargeKWh,2));
  /**
 * Vypočte procento dokončení oproti plánované hodnotě a vykreslí průběhový ukazatel.
 * Vstupy: `sId` (string) – ID HTML prvku nebo šablony; `nActual` (number) – číselný parametr výpočtu; `vForecast` (mixed) – predikce výroby nebo spotřeby LINEA.
 * Výstup: `void` – upraví text i šířku příslušného průběhového ukazatele.
 */
 const setProgress=(sId,nActual,vForecast)=>{const oEl=$(sId);if(!oEl)return;const nPct=isValue(nActual)&&isValue(vForecast)&&Number(vForecast)>0?Math.max(0,Math.min(100,Number(nActual)/Number(vForecast)*100)):0;oEl.style.width=nPct+'%';};
 setProgress('lm-balance-pv-progress',vPvToday,vPvForecast);setProgress('lm-balance-house-progress',vConsToday,vConsForecast);
 const vOutlook=isValue(vPvForecast)&&isValue(vConsForecast)?Number(vPvForecast)-Number(vConsForecast):null;
 setText('lm-balance-outlook',vOutlook===null?'—':(vOutlook>=0?'+':'')+vOutlook.toFixed(1)+' kWh');
 setText('lm-balance-text',vOutlook===null?t(APP,'Energy outlook unavailable'):vOutlook>=0?t(APP,'Forecast production is higher than forecast consumption by')+' '+vOutlook.toFixed(1)+' kWh':t(APP,'Forecast consumption is higher than forecast production by')+' '+Math.abs(vOutlook).toFixed(1)+' kWh');
 setText('lm-house-tech-total',value(vHouse.powerW,'W'));setText('lm-grid-tech-total',value(vGrid.powerW,'W'));
 const vTemps=vStatus.hcTemperatures||{},vTechTemps=Array.isArray(vTemps.sensors)?vTemps.sensors:[];
 const vTempEl=$('lm-tech-temperatures');if(vTempEl)vTempEl.innerHTML=vTechTemps.length?vTechTemps.map(/**
 * Převede položku kolekce vTechTemps na HTML záznam pro UI z polí temperatures, name.
 * Vstupy: `vSensor` (mixed) – záznam teplotního čidla s názvem a aktuální teplotou.
 * Výstup: `string` – HTML řádek teplotního čidla
 */ vSensor=>`<div class="lm-tech-temp" title="${esc(HELP.temperatures)}"><span>🌡 ${esc(vSensor.name||t(APP,'Sensor'))}</span><strong>${value(vSensor.temperatureC,'°C',1)}</strong><small>${esc(t(APP,({inverter:'Inverter',rack:'Rack',battery:'Battery',sensor:'Sensor'})[vSensor.kind]||'Sensor'))}</small></div>`).join(''):'<div class="lm-empty">—</div>';
 setText('lm-house-temp-l1','🌡 '+value(vTemps.inverter1TempC,'°C',1));setText('lm-house-temp-l2','🌡 '+value(vTemps.inverter2TempC,'°C',1));setText('lm-house-temp-l3','🌡 '+value(vTemps.inverter3TempC,'°C',1));
 setText('lm-house-rack-temp',value(vTemps.rackTempC,'°C',1));setText('lm-batt-rack-temp',value(vTemps.rackTempC,'°C',1));
 const vClimateOk=blockAvailable(vStatus.climate),vCd=vClimateOk?(vStatus.climate?.data||{}):{};setText('lm-climate-updated',vClimateOk?ageLabel(vCd.updatedAt):'—');const vClimateEl=$('lm-climate');const aClimateOpen=[...vClimateEl.querySelectorAll('.lm-climate-device')].map(/**
 * Převede rozbalené panely klimatizace na uložené ID zařízení na odvozenou hodnotu z polí querySelector, open.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `number` – index rozbaleného panelu nebo -1
 */ (oEl,nI)=>oEl.querySelector('details')?.open?nI:-1).filter(/**
 * Vybere z kolekce [...vClimateEl.querySelectorAll('.lm-climate- jen položky splňující zadanou logickou podmínku.
 * Vstupy: `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ nI=>nI>=0);vClimateEl.innerHTML=(vCd.devices||[]).length?vCd.devices.map(/**
 * Převede položku kolekce vCd.devices na odvozenou hodnotu.
 * Vstupy: `vDevice` (mixed) – záznam zařízení a jeho dostupných telemetrických polí.
 * Výstup: `string` – HTML komponenta konkrétní klimatizační jednotky
 */ vDevice=>renderClimate(vDevice,vStatus)).join(''):'<div class="lm-empty">—</div>';aClimateOpen.forEach(/**
 * Po opětovném vykreslení obnoví původně rozbalené detaily klimatizačních zařízení.
 * Vstupy: `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ nI=>{const vD=vClimateEl.querySelectorAll('.lm-climate-device')[nI]?.querySelector('details');if(vD)vD.open=true;});
 const vShellyOk=blockAvailable(vStatus.shelly),vSd=vShellyOk?(vStatus.shelly?.data||{}):{};setText('lm-shelly-updated',vShellyOk?ageLabel(vSd.updatedAt):'—');
 const aShellyDevices=vSd.devices||[];
  /**
  * Rozpozná sledované Shelly vstupy podle dostupného názvu.
  * Vstupy: `vDevice` (mixed) – datový objekt jednoho zařízení.
  * Výstup: `boolean` – výsledek logického testu.
  */
 const isPrimaryShelly=vDevice=>{const sN=String(vDevice.name||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');return sN.includes('bojler')||sN.includes('nocni proud')||sN.includes('nocni_proud');};
 const aShellyPrimary=aShellyDevices.filter(isPrimaryShelly),aShellyMore=aShellyDevices.filter(/**
 * Vybere z kolekce aShellyDevices jen položky splňující zadanou logickou podmínku.
 * Vstupy: `vDevice` (mixed) – záznam zařízení a jeho dostupných telemetrických polí.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vDevice=>!isPrimaryShelly(vDevice));
  /**
 * Sestaví HTML přehled stavu a typu jednoho zařízení Shelly.
 * Vstupy: `vDevice` (mixed) – datový objekt jednoho zařízení.
 * Výstup: `string` – escapovaný HTML prvek se stavem Shelly.
 */
 const shellyHtml=vDevice=>`<div class="lm-switch" title="${esc(HELP.shelly)}"><span>${esc(String(vDevice.name||'').trim())}<small>${esc(vDevice.kind||'—')} · ${esc(t(APP,'channel'))} ${vDevice.channel??'—'}</small></span>${vDevice.available===false?'<span class="lm-state unknown">N/A</span>':boolBadge(vDevice.state)}</div>`;
 $('lm-shelly-primary').innerHTML=aShellyPrimary.length?aShellyPrimary.map(shellyHtml).join(''):'<div class="lm-empty">—</div>';
 $('lm-shelly').innerHTML=aShellyMore.length?aShellyMore.map(shellyHtml).join(''):'<div class="lm-empty">—</div>';setText('lm-shelly-more-count',String(aShellyMore.length));
 const vSmoke=vSd.smokeDetectors||[];$('lm-smoke').innerHTML=vSmoke.length?vSmoke.map(/**
 * Převede položku kolekce vSmoke na HTML záznam pro UI z polí smoke, name.
 * Vstupy: `vDevice` (mixed) – záznam zařízení a jeho dostupných telemetrických polí.
 * Výstup: `string` – HTML řádek hlásiče kouře
 */ vDevice=>`<div class="lm-device-row" title="${esc(HELP.smoke)}"><div><strong>🔥 ${esc(vDevice.name)}</strong><small>${t(APP,'Battery')} ${value(vDevice.batteryPct,'%',0)} · ${value(vDevice.batteryVoltageV,'V',2)} · RSSI ${value(vDevice.rssiDbm,'dBm')} · ${t(APP,'seen')} ${ageLabel(vDevice.lastSeen)} · ${esc(t(APP,'age'))} ${value(vDevice.ageSec,'s')}</small><small>${esc(vDevice.wakeupReason||'—')}</small></div><span class="lm-state ${vDevice.alarm?'alarm':vDevice.ok===true?'on':'warn'}">${vDevice.alarm?t(APP,'ALARM'):vDevice.ok===true?t(APP,'OK'):'?'}</span></div>`).join(''):'<div class="lm-empty">'+esc(t(APP,'No smoke data'))+'</div>';
 const vU=blockAvailable(vStatus.ups)?vStatus.ups?.data:null;const vUpsSummary=$('lm-ups-summary'),vUpsDetails=$('lm-ups-details');if(vUpsSummary)vUpsSummary.innerHTML=vU?renderUpsSummary(vU,vStatus):'<div class="lm-empty">—</div>';if(vUpsDetails)vUpsDetails.innerHTML=vU?renderUpsDetails(vU,vStatus):'<div class="lm-empty">—</div>';setText('lm-ups-age',vU&&isValue(vU.updatedAt)?ageLabel(vU.updatedAt):'—');
 renderSpotSchedule(vStatus);renderForecastAvailability(vStatus);
 setText('lm-api-version','LINEA API '+(vStatus.api?.version||'—'));
 const sAge=isValue(vStatus.system?.ageMs)?Math.round(Number(vStatus.system.ageMs)/1000)+' s':'—';setText('lm-source-age',t(APP,'Source age')+' '+sAge);setTitle('lm-source-age',HELP['system.ageMs']);
 const vC=$('lm-connection');vC.className='lm-status '+(vStatus.system?.stale?'lm-status-warn':'lm-status-ok');vC.innerHTML='<span class="lm-dot"></span>'+(vStatus.system?.stale?t(APP,'LINEA stale'):t(APP,'LINEA online'));
}
/**
 * Vykreslí čtecí přehled stavů a nastavení ESS.
 * Vstupy: `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API; `vEss` (mixed) – stav rozhodnutí a nastavení ESS.
 * Výstup: `void` – vykreslí rozhodnutí, přepínače a parametry ESS.
 */
function renderEssDetails(vStatus,vEss){
 const vSw=vEss.switches||{},vSt=vEss.settings||{},vTm=vEss.time||{},vDc=vTm.delayCharging||{};
 const aSwitches=[
  [t(APP,'ESS AC Grid control'),'ess.switches.controlModeEssAcGrid',vSw.controlModeEssAcGrid],
  [t(APP,'SPOT grid charging'),'ess.switches.spotGridCharging',vSw.spotGridCharging],
  [t(APP,'Grid charging'),'ess.switches.gridCharging',vSw.gridCharging],
  [t(APP,'Grid consumption allowed'),'ess.switches.gridConsumption',vSw.gridConsumption],
  [t(APP,'Energy Threshold Injector'),'ess.switches.energyThresholdInjector',vSw.energyThresholdInjector],
  [t(APP,'Non-battery priority'),'ess.switches.nonBatteryPriority',vSw.nonBatteryPriority],
  [t(APP,'Delay Charging'),'ess.switches.delayCharging',vSw.delayCharging],
  [t(APP,'Dynamic SOC reserve'),'ess.switches.dynamicSocReserve',vSw.dynamicSocReserve],
  [t(APP,'Prediction threshold'),'ess.switches.predictionThreshold',vSw.predictionThreshold],
  [t(APP,'SOC delta before export'),'ess.switches.socDeltaBeforeExport',vSw.socDeltaBeforeExport],
  [t(APP,'Morning battery sale'),'ess.switches.morningPeakBatterySales',vSw.morningPeakBatterySales],
  [t(APP,'Evening battery sale'),'ess.switches.eveningPeakBatterySales',vSw.eveningPeakBatterySales]
 ];
 const vSwitchEl=$('lm-ess-switches');if(vSwitchEl)vSwitchEl.innerHTML=aSwitches.map(/**
 * Převede položku kolekce aSwitches na HTML záznam pro UI.
 * Vstupy: `[n,k,v]` (array) – položky rozloženého pole.
 * Výstup: `string` – HTML položka nastavení přepínače ESS
 */ ([n,k,v])=>`<div><dt>${esc(n)} ${tip(k)}</dt><dd>${boolBadge(v)}</dd></div>`).join('');
 const aSettings=[
  [t(APP,'Balancing reserve'),'ess.settings.balancingReserveW',vSt.balancingReserveW,'W',0],[t(APP,'Grid target'),'ess.settings.setGridValueW',vSt.setGridValueW,'W',0],[t(APP,'Max. Grid Point'),'ess.settings.maxGridPointW',vSt.maxGridPointW,'W',0],[t(APP,'SPOT threshold'),'ess.settings.spotThresholdPrice',vSt.spotThresholdPrice,'CZK/kWh',2],[t(APP,'Morning SOC'),'ess.settings.morningSocSalesPct',vSt.morningSocSalesPct,'%',0],[t(APP,'Evening SOC'),'ess.settings.eveningSocSalesPct',vSt.eveningSocSalesPct,'%',0],[t(APP,'Grid charging SOC'),'ess.settings.gridChargingSocPct',vSt.gridChargingSocPct,'%',0],[t(APP,'Prediction threshold'),'ess.settings.predictionThresholdKWh',vSt.predictionThresholdKWh,'kWh',1],[t(APP,'SOC delta before export'),'ess.settings.socDeltaBeforeExportPct',vSt.socDeltaBeforeExportPct,'%',0],[t(APP,'Grid charging duration'),'ess.settings.chargingDurationGridH',vSt.chargingDurationGridH,'h',1],[t(APP,'Acceptable GRID price'),'ess.settings.acceptablePriceGrid',vSt.acceptablePriceGrid,'CZK/kWh',2]
 ];
 const vSettingsEl=$('lm-ess-settings');if(vSettingsEl)vSettingsEl.innerHTML=aSettings.map(/**
 * Převede položku kolekce aSettings na HTML záznam pro UI.
 * Vstupy: `[n,k,v,u,d]` (array) – položky rozloženého pole.
 * Výstup: `string` – HTML položka číselného nastavení ESS
 */ ([n,k,v,u,d])=>`<div><dt>${esc(n)} ${tip(k)}</dt><dd>${value(v,unit(vStatus,k,u),d)}</dd></div>`).join('');
 setText('lm-delay-window',vDc.start&&vDc.stop?vDc.start+' – '+vDc.stop:'—');
 setText('lm-morning-hours',Array.isArray(vTm.morningPeakHours)?vTm.morningPeakHours.map(/**
 * Převede položku kolekce vTm.morningPeakHours na odvozenou hodnotu z polí padStart.
 * Vstupy: `vH` (mixed) – celá hodina špičkového tarifu (0–23).
 * Výstup: `string` – hodina ranní špičky ve formátu HH:00
 */ vH=>String(vH).padStart(2,'0')+':00').join(', '):'—');
 setText('lm-evening-hours',Array.isArray(vTm.eveningPeakHours)?vTm.eveningPeakHours.map(/**
 * Převede položku kolekce vTm.eveningPeakHours na odvozenou hodnotu z polí padStart.
 * Vstupy: `vH` (mixed) – celá hodina špičkového tarifu (0–23).
 * Výstup: `string` – hodina večerní špičky ve formátu HH:00
 */ vH=>String(vH).padStart(2,'0')+':00').join(', '):'—');
}
/**
 * Vybere a normalizuje přehled stavu UPS.
 * Vstupy: `vU` (mixed) – stav UPS nebo null, pokud chybí.
 * Výstup: `object` – normalizovaný popis stavu UPS včetně zatížení a baterie.
 */
function upsInfo(vU){
 const vRuntime=isValue(vU.battery?.runtimeSec)?Math.round(Number(vU.battery.runtimeSec)/60)+' min':'—';
 const aFlags=[];if(vU.status?.lowBattery)aFlags.push(t(APP,'LOW BATTERY'));if(vU.status?.charging)aFlags.push(t(APP,'CHARGING'));if(vU.status?.discharging)aFlags.push(t(APP,'DISCHARGING'));if(vU.status?.overload)aFlags.push(t(APP,'OVERLOAD'));if(vU.status?.replaceBattery)aFlags.push(t(APP,'REPLACE BATTERY'));if(vU.status?.bypass)aFlags.push(t(APP,'BYPASS'));
 const aErrorFlags=[];if(vU.online===false)aErrorFlags.push(t(APP,'OFFLINE'));if(vU.status?.lowBattery)aErrorFlags.push(t(APP,'LOW BATTERY'));if(vU.status?.overload)aErrorFlags.push(t(APP,'OVERLOAD'));if(vU.status?.replaceBattery)aErrorFlags.push(t(APP,'REPLACE BATTERY'));
 const sState=vU.onBattery?t(APP,'BATTERY'):vU.online?t(APP,'ONLINE'):t(APP,'OFFLINE');
 const sCls=vU.online&&!vU.onBattery?'on':vU.onBattery?'warn':'off';
 return {runtime: vRuntime,flags: aFlags,errorFlags: aErrorFlags,state: sState,cls: sCls};
}
/**
 * Zobrazí základní přehled provozu UPS.
 * Vstupy: `vU` (mixed) – stav UPS nebo null, pokud chybí; `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API.
 * Výstup: `string` – bezpečně escapovaná HTML karta základního stavu, zatížení a chyby UPS.
 */
function renderUpsSummary(vU,vStatus){
 const vUpsInfo=upsInfo(vU),vLoad=value(vU.load?.percent,'%',0),oErr=vUpsInfo.errorFlags.length?vUpsInfo.errorFlags.join(' · '):t(APP,'OK');
 return `<div class="lm-ups-basic" title="${esc(HELP.ups)}"><div class="lm-ups-basic-name"><strong>🔋 ${esc(vU.name||'UPS')}</strong><small>${esc(vU.status?.raw||'—')}</small></div><div class="lm-ups-basic-item"><span>${esc(t(APP,'Status'))}</span><b class="lm-state ${vUpsInfo.cls}">${esc(vUpsInfo.state)}</b></div><div class="lm-ups-basic-item"><span>${esc(t(APP,'Load'))}</span><b>${vLoad}</b></div><div class="lm-ups-basic-item lm-ups-basic-error ${vUpsInfo.errorFlags.length?'warn':'ok'}"><span>${esc(t(APP,'Error'))}</span><b>${esc(oErr)}</b></div></div>`;
}
/**
 * Zobrazí podrobnou diagnostiku UPS.
 * Vstupy: `vU` (mixed) – stav UPS nebo null, pokud chybí; `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API.
 * Výstup: `string` – HTML detail UPS s napětím, frekvencí, zatížením a stavem baterie.
 */
function renderUpsDetails(vU,vStatus){
 const vUpsInfo=upsInfo(vU);
 return `<div class="lm-ups" title="${esc(HELP.ups)}"><div class="lm-ups-flags"><span>${esc(t(APP,'Status'))}</span><strong>${esc(vU.status?.raw||'—')}${vUpsInfo.flags.length?' · '+esc(vUpsInfo.flags.join(' · ')):''}</strong></div><div class="lm-mini-grid"><div><span>${esc(t(APP,'Charge'))}</span><strong>${value(vU.battery?.chargePct,'%',0)}</strong></div><div><span>${esc(t(APP,'Runtime'))}</span><strong>${vUpsInfo.runtime}</strong></div><div><span>${esc(t(APP,'Load'))}</span><strong>${value(vU.load?.percent,'%',0)}</strong></div><div><span>${esc(t(APP,'Power'))}</span><strong>${value(vU.load?.realPowerW,'W',0)}</strong></div><div><span>${esc(t(APP,'Input'))}</span><strong>${value(vU.input?.voltageV,'V',0)}</strong></div><div><span>${esc(t(APP,'Output'))}</span><strong>${value(vU.output?.voltageV,'V',0)}</strong></div><div><span>${esc(t(APP,'Frequency'))}</span><strong>${value(vU.output?.frequencyHz,'Hz',1)}</strong></div><div><span>${esc(t(APP,'Battery voltage'))}</span><strong>${value(vU.battery?.voltageV,'V',1)}</strong></div></div></div>`;
}
/**
 * Zobrazí teploty, režim a stav dostupných klimatizačních zařízení.
 * Vstupy: `vDevice` (mixed) – datový objekt jednoho zařízení; `vStatus` (mixed) – strukturovaná aktuální odpověď LINEA API.
 * Výstup: `string` – HTML karta klimatizační jednotky včetně stavu a diagnostiky.
 */
function renderClimate(vDevice,vStatus){
 const vEn=vDevice.energy||{};const sStateClass=vDevice.error||vDevice.cloudUp===false?'warn':vDevice.on?'on':'off';const sStateText=vDevice.error?t(APP,'ERROR'):vDevice.cloudUp===false?t(APP,'CLOUD OFF'):vDevice.on?t(APP,'ON'):t(APP,'OFF');
 const sMode=String(vDevice.operationMode||'—');
 const aBars=[[t(APP,'Today'),vEn.todayKWh],[t(APP,'Week'),vEn.weekKWh],[t(APP,'Month'),vEn.monthKWh],[t(APP,'Cooling month'),vEn.coolingMonthKWh],[t(APP,'Heating month'),vEn.heatingMonthKWh]];const aNums=aBars.map(/**
 * Převede položku kolekce aBars na odvozenou hodnotu z polí max.
 * Vstupy: `vV` (mixed) – měřená hodnota nebo dvojice označení a číselné hodnoty.
 * Výstup: `number` – nezáporná hodnota výkonu pro výšku sloupce
 */ vV=>isValue(vV[1])?Math.max(0,Number(vV[1])):0),nMax=Math.max(1,...aNums);const sBarHtml=aBars.map(/**
 * Převede položku kolekce aBars na HTML záznam pro UI z polí max, toFixed.
 * Vstupy: `[label,v]` (array) – položky rozloženého pole; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `string` – HTML sloupec energetického přehledu se správným měřítkem
 */ ([label,v],nI)=>`<div class="lm-energy-bar"><span>${esc(label)}</span><i><em style="width:${Math.max(2,aNums[nI]/nMax*100).toFixed(1)}%"></em></i><strong>${value(v,'kWh',1)}</strong></div>`).join('');
 return `<article class="lm-climate-device" title="${esc(HELP.climate)}"><div class="lm-climate-summary"><div class="lm-climate-name"><strong>${esc(vDevice.name||t(APP,'Climate'))}</strong><small>${esc(t(APP,'Mode'))}: ${esc(sMode)}</small></div><div class="lm-climate-temp"><strong>${value(vDevice.roomTemperatureC,'°C',1)}</strong><small>${esc(t(APP,'room'))}</small></div><div class="lm-climate-outdoor"><strong>${value(vDevice.outdoorTemperatureC,'°C',1)}</strong><small>${esc(t(APP,'outside'))}</small></div><div class="lm-climate-target"><strong>${value(vDevice.setpointC,'°C',1)}</strong><small>${esc(t(APP,'Target'))}</small></div><span class="lm-state ${sStateClass}">${sStateText}</span></div><details class="lm-details lm-climate-details"><summary>${esc(t(APP,'Statistics and device details'))}</summary><div class="lm-climate-body"><div class="lm-energy-bars">${sBarHtml}</div><div class="lm-climate-meta"><span>${esc(t(APP,'Firmware'))}</span><strong>${esc(vDevice.firmwareVersion||'—')}${vDevice.firmwareChanged?' ⚠':''}</strong><span>${esc(t(APP,'Cloud'))}</span><strong>${vDevice.cloudUp===true?'OK':vDevice.cloudUp===false?t(APP,'Unavailable'):'—'}</strong></div></div></details>${vDevice.error===true?`<div class="lm-inline-warning">${esc(vDevice.errorCode||t(APP,'Climate error'))}</div>`:''}${vDevice.firmwareChanged===true?'<div class="lm-inline-warning">'+esc(t(APP,'Firmware changed'))+'</div>':''}</article>`;
}

/**
 * Převede lokální datum a hodinu na časový bod SPOT grafu.
 * Vstupy: `sDate` (string) – datum ve tvaru YYYY-MM-DD; `nHour` (number) – hodina v pražském čase.
 * Výstup: `number|null` – unixové sekundy pro hodinu nebo null při neplatném vstupu.
 */
function localDateHourTs(sDate,nHour){
 if(!sDate||!Number.isFinite(Number(nHour)))return null;
 const vHh=String(Math.max(0,Math.min(23,Number(nHour)))).padStart(2,'0');
 const oD=new Date(String(sDate)+'T'+vHh+':00:00');
 return Number.isFinite(oD.getTime())?Math.floor(oD.getTime()/1000):null;
}
/**
 * Převede hodinový rozpis SPOT na body grafu.
 * Vstupy: `vSpot` (mixed) – hodinové SPOT ceny a časová osa.
 * Výstup: `array` – body hodinové cenové křivky pro vykreslení.
 */
function spotSchedulePoints(vSpot){
 const aOut=[];
 for(const sDay of [vSpot?.today,vSpot?.tomorrow]){
  if(sDay?.available!==true||!Array.isArray(sDay.prices))continue;
  for(const oRow of sDay.prices){const nTs=localDateHourTs(sDay.date,oRow?.hour);if(nTs!==null&&isValue(oRow?.price))aOut.push({ts: nTs,price:Number(oRow.price),day:sDay===vSpot.today?'today':'tomorrow'});}
 }
 return aOut.sort(/**
 * Porovná dvojici položek a určí jejich pořadí podle pole ts.
 * Vstupy: `vA` (mixed) – první měřený bod pro určení pořadí; `vB` (mixed) – druhý měřený bod pro určení pořadí.
 * Výstup: záporné/nulové/kladné číslo pro pořadí položek.
 */ (vA,vB)=>vA.ts-vB.ts);
}
/**
 * Převede předpovědní časovou řadu na body grafu.
 * Vstupy: `vForecast` (mixed) – predikce výroby nebo spotřeby LINEA; `sKey` (string) – technický klíč veličiny či pole.
 * Výstup: `array` – normalizované časové body zvolené predikce.
 */
function forecastSeriesPoints(vForecast,sKey){
 const nInterval=Math.max(1,Number(vForecast?.intervalMinutes)||15),aRows=vForecast?.series?.[sKey];if(!Array.isArray(aRows))return[];
 return aRows.map(/**
 * Převede položku kolekce aRows na datový záznam z polí timestampMs, timestamp, getTime.
 * Vstupy: `vR` (mixed) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `object` – normalizovaný časový bod předpovědi a energie
 */ vR=>{const nMs=isValue(vR?.timestampMs)?Number(vR.timestampMs):(vR?.timestamp?new Date(vR.timestamp).getTime():NaN);const vE=isValue(vR?.energyKWh)?Number(vR.energyKWh):null;return Number.isFinite(nMs)&&vE!==null?{ts:Math.floor(nMs/1000),energyKWh:vE,powerW:vE*1000*60/nInterval}:null;}).filter(Boolean).sort(/**
 * Porovná dvojici položek a určí jejich pořadí podle pole ts.
 * Vstupy: `vA` (mixed) – první měřený bod pro určení pořadí; `vB` (mixed) – druhý měřený bod pro určení pořadí.
 * Výstup: záporné/nulové/kladné číslo pro pořadí položek.
 */ (vA,vB)=>vA.ts-vB.ts);
}
/**
 * Vypočte korekci předpovědi podle dostupných údajů.
 * Vstupy: `oStatus` (object) – datový objekt posledního stavu LINEA; `vSolarPoints` (mixed) – časová řada předpovědi sluneční výroby; `nNow` (number) – unixový čas použitý ve výpočtu.
 * Výstup: `number` – korekční koeficient predikce vůči aktuálnímu výkonu.
 */
function forecastCorrectionFactor(oStatus,vSolarPoints,nNow=Date.now()/1000){
 const nActual=oStatus?.vrm?.data?.today?.pvYieldKWh;if(!isValue(nActual)||!vSolarPoints.length)return 1;
 const oD=new Date(nNow*1000),vDayStart=new Date(oD.getFullYear(),oD.getMonth(),oD.getDate()).getTime()/1000;
 const vPredicted=vSolarPoints.filter(/**
 * Vybere z kolekce vSolarPoints jen položky splňující podmínku nad ts.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vP=>vP.ts>=vDayStart&&vP.ts<=nNow).reduce(/**
 * Přičte nebo sloučí údaje položky vSolarPoints.filter(/**
 * Zkontroluje dostupnost a nezápornost energie u předpovězených bodů.
 * Vstupy: `vA` (mixed) – první hodnota z porovnávané nebo zobrazované dvojice; `vP` (mixed) – časový bod předpovědi výroby nebo spotřeby.
 * Výstup: nová průběžná akumulovaná hodnota.
 */ (vA,vP)=>vA+Number(vP.energyKWh||0),0);
 if(vPredicted<0.35)return 1;
 return Math.max(.45,Math.min(1.65,Number(nActual)/vPredicted));
}
/**
 * Sestaví spojitou SVG čáru se schody pro hodinové hodnoty.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů; `sKey` (string) – technický klíč veličiny či pole; `fnX` (function) – funkce převádějící hodnotu/čas na souřadnici X; `fnY` (function) – funkce převádějící hodnotu na souřadnici Y; `nIntervalSec` (number) – časové rozlišení v sekundách.
 * Výstup: `string` – SVG path schodového průběhu bez spojování chybějících měření.
 */
function stepPath(aPoints,sKey,fnX,fnY,nIntervalSec=3600){if(!aPoints.length)return'';let sD='',bActive=false;for(let nI=0;nI<aPoints.length;nI++){const vP=aPoints[nI],vV=vP[sKey];if(!isValue(vV)){bActive=false;continue;}const nPx=fnX(vP.ts),nPy=fnY(Number(vV));if(!bActive){sD+=` M ${nPx.toFixed(1)} ${nPy.toFixed(1)}`;bActive=true;}const vNext=aPoints[nI+1],nEndTs=vNext?vNext.ts:vP.ts+nIntervalSec;sD+=` H ${fnX(nEndTs).toFixed(1)}`;if(vNext&&isValue(vNext[sKey]))sD+=` V ${fnY(Number(vNext[sKey])).toFixed(1)}`;else bActive=false;}return sD.trim();}
/**
 * Vykreslí hodinové ceny SPOT a cenový práh.
 * Vstupy: `oStatus` (object) – datový objekt posledního stavu LINEA.
 * Výstup: `void` – vykreslí SPOT ceny, předpovědi a jejich porovnání.
 */
function renderSpotSchedule(oStatus){
 const oEl=$('lm-spot-price-chart');if(!oEl)return;
 const vSpot=blockAvailable(oStatus?.spot)?oStatus.spot:null;
 if(!vSpot){oEl.innerHTML='<div class="lm-empty">'+esc(t(APP,'Hourly SPOT schedule is not available yet.'))+'</div>';return;}
 const sToday=spotSchedulePoints({today:vSpot.today,tomorrow:null});
 const vTomorrow=spotSchedulePoints({today:null,tomorrow:vSpot.tomorrow});
 const aAll=[...sToday,...vTomorrow].sort(/**
 * Porovná dvojici položek a určí jejich pořadí podle pole ts.
 * Vstupy: `vA` (mixed) – první měřený bod pro určení pořadí; `vB` (mixed) – druhý měřený bod pro určení pořadí.
 * Výstup: záporné/nulové/kladné číslo pro pořadí položek.
 */ (vA,vB)=>vA.ts-vB.ts);
 if(!aAll.length){oEl.innerHTML='<div class="lm-empty">'+esc(t(APP,'Hourly SPOT schedule is not available yet.'))+'</div>';return;}
 const nThreshold=blockAvailable(oStatus?.ess)&&isValue(oStatus.ess?.settings?.spotThresholdPrice)?Number(oStatus.ess.settings.spotThresholdPrice):null;
 const vIntervalSec=Math.max(1,Number(vSpot.intervalMinutes)||60)*60;
 const nAvailableWidth=Math.floor(oEl.clientWidth||oBootstrapRoot.clientWidth||window.innerWidth);
 const bMobile=nAvailableWidth<760;
 const nW=bMobile?Math.max(260,nAvailableWidth):760,nH=bMobile?180:190,nL=bMobile?46:54,nR=bMobile?38:16,nT=24,nB=bMobile?38:40,nPlotW=nW-nL-nR,nPlotH=nH-nT-nB;
 const nT0=aAll[0].ts,nT1=aAll[aAll.length-1].ts+vIntervalSec;
 const aVals=aAll.map(/**
 * Převede položku kolekce aAll na odvozenou hodnotu z polí price.
 * Vstupy: `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce.
 * Výstup: `number` – číselná hodinová cena SPOT v Kč/kWh
 */ vP=>vP.price);if(nThreshold!==null)aVals.push(nThreshold);
 const nMin=Math.min(0,...aVals),nMax=Math.max(0,...aVals),nPad=Math.max(.15,(nMax-nMin)*.1),vLo=nMin-nPad,vHi=nMax+nPad;
  /**
  * Převádí čas a hodinovou cenu SPOT na souřadnice SVG grafu.
  * Vstupy: `nTs` (number) – časový okamžik v unixových sekundách.
  * Výstup: `void` – bez návratové hodnoty.
  */
 const x=nTs=>nL+(nTs-nT0)/(nT1-nT0||1)*nPlotW,/** Vypočte svislou souřadnici měřené hodnoty na časovém grafu. Vstupy: `vV` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ y=vV=>nT+(vHi-vV)/(vHi-vLo||1)*nPlotH;
 let sGrid='';for(let nI=0;nI<=4;nI++){const vV=vHi-(vHi-vLo)*nI/4,nPy=y(vV);sGrid+=`<line x1="${nL}" y1="${nPy}" x2="${nW-nR}" y2="${nPy}" class="lm-spot-mini-grid"/><text x="${nL-5}" y="${nPy+4}" class="lm-spot-mini-y">${vV.toFixed(2)}</text>`;}
 const nTickCount=bMobile?2:4;
 let sTicks='';for(let nI=0;nI<=nTickCount;nI++){const nTs=nT0+(nT1-nT0)*nI/nTickCount,nPx=x(nTs);const sLabel=new Date(nTs*1000).toLocaleString([],{day:'numeric',month:'numeric',hour:'2-digit',...(bMobile?{}:{minute:'2-digit'})});sTicks+=`<text x="${nPx}" y="${nH-8}" class="lm-spot-mini-x">${esc(sLabel)}</text>`;}
 const vMidnight=vTomorrow.length?vTomorrow[0].ts:null;
 const vDivider=vMidnight!==null&&sToday.length?`<line x1="${x(vMidnight)}" y1="${nT}" x2="${x(vMidnight)}" y2="${nH-nB}" class="lm-spot-midnight"/>`:'';
 const vShade=vMidnight!==null&&sToday.length?`<rect x="${x(vMidnight)}" y="${nT}" width="${Math.max(0,nW-nR-x(vMidnight))}" height="${nPlotH}" class="lm-spot-tomorrow-shade"/>`:'';
 const nNow=Date.now()/1000,vNowLine=nNow>=nT0&&nNow<=nT1?`<line x1="${x(nNow)}" y1="${nT}" x2="${x(nNow)}" y2="${nH-nB}" class="lm-spot-mini-now"/>`:'';
 const vThresholdLine=nThreshold===null?'':`<line x1="${nL}" y1="${y(nThreshold)}" x2="${nW-nR}" y2="${y(nThreshold)}" class="lm-spot-threshold-line"/><text x="${nW-nR-3}" y="${y(nThreshold)-4}" class="lm-spot-threshold-label">${esc(t(APP,'SPOT threshold'))} ${nThreshold.toFixed(2)}</text>`;
  /**
 * Určí CSS třídu hodinové SPOT ceny podle nastavené hranice.
 * Vstupy: `nPrice` (number) – číselný parametr výpočtu.
 * Výstup: `string` – `no-threshold`, `above` nebo `below`.
 */
 const priceClass=nPrice=>nThreshold===null?'no-threshold':nPrice>=nThreshold?'above':'below';
 const sSegments=aAll.map(/**
 * Převede položku kolekce aAll na odvozenou hodnotu z polí ts, toFixed, price.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `string` – SVG vodorovný segment hodinové SPOT ceny
 */ vP=>`<path d="M ${x(vP.ts).toFixed(1)} ${y(vP.price).toFixed(1)} H ${x(vP.ts+vIntervalSec).toFixed(1)}" class="lm-spot-price-segment ${priceClass(vP.price)} ${vP.day==='tomorrow'?'tomorrow':''}"/>`).join('');
 // Join only adjacent hours. Split a crossing at the threshold so every
 // part of the step keeps the same meaning and color as its price side.
 const sConnectors=aAll.slice(0,-1).map(/**
 * Převede položku kolekce aAll.slice(0,-1) na datový záznam z polí ts, price, toFixed.
 * Vstupy: `vP` (mixed) – historické časově označené měření; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `string` – SVG spojnice sousedních rozdílných cen nebo prázdný řetězec
 */ (vP,nI)=>{
  const vNext=aAll[nI+1];if(vNext.ts!==vP.ts+vIntervalSec||vNext.price===vP.price)return'';
  const sPx=x(vNext.ts).toFixed(1),sStart=y(vP.price).toFixed(1),sEnd=y(vNext.price).toFixed(1);
  const vNextStyle=vNext.day==='tomorrow'?'tomorrow':'';
  if(nThreshold!==null&&(vP.price>=nThreshold)!==(vNext.price>=nThreshold)){
   const sSplit=y(nThreshold).toFixed(1);
   return `<path d="M ${sPx} ${sStart} V ${sSplit}" class="lm-spot-price-segment ${priceClass(vP.price)} ${vNextStyle}"/><path d="M ${sPx} ${sSplit} V ${sEnd}" class="lm-spot-price-segment ${priceClass(vNext.price)} ${vNextStyle}"/>`;
  }
  return `<path d="M ${sPx} ${sStart} V ${sEnd}" class="lm-spot-price-segment ${priceClass(vNext.price)} ${vNextStyle}"/>`;
 }).join('');
 const sMarker=aAll.map(/**
 * Převede položku kolekce aAll na odvozenou hodnotu z polí ts, max.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `string` – neviditelná SVG plocha pro tooltip SPOT ceny
 */ vP=>`<rect x="${x(vP.ts)}" y="${nT}" width="${Math.max(2,x(vP.ts+vIntervalSec)-x(vP.ts))}" height="${nPlotH}" fill="transparent"><title>${esc(new Date(vP.ts*1000).toLocaleString())} · ${vP.price.toFixed(2)} CZK/kWh${nThreshold===null?'':' · '+esc(vP.price>=nThreshold?t(APP,'Above threshold'):t(APP,'Below threshold'))}</title></rect>`).join('');
 oEl.innerHTML=`<div class="lm-spot-schedule-head"><strong>${esc(t(APP,'Hourly SPOT prices'))}</strong><span>${vTomorrow.length?esc(t(APP,'Tomorrow prices available')):esc(t(APP,'Tomorrow prices not published yet'))}</span></div><div class="lm-spot-combined-head"><span>${esc(vSpot.today?.date||vSpot.tomorrow?.date||'')}</span>${vTomorrow.length?`<span>${esc(vSpot.tomorrow?.date||'')}</span>`:''}</div>${nThreshold===null?'':`<div class="lm-spot-threshold-legend"><span class="above">● ${esc(t(APP,'Above threshold'))}</span><span class="below">● ${esc(t(APP,'Below threshold'))}</span><span>${esc(t(APP,'SPOT threshold'))}: ${nThreshold.toFixed(2)} CZK/kWh</span></div>`}<svg viewBox="0 0 ${nW} ${nH}" role="img" aria-label="${esc(t(APP,'Hourly SPOT prices'))}">${vShade}${sGrid}<line x1="${nL}" y1="${y(0)}" x2="${nW-nR}" y2="${y(0)}" class="lm-spot-zero"/>${vThresholdLine}${vDivider}${sSegments}${sConnectors}${vNowLine}${sTicks}${sMarker}</svg>`;
}
/**
 * Vyhodnotí a zobrazí dostupnost předpovědních dat.
 * Vstupy: `oStatus` (object) – datový objekt posledního stavu LINEA.
 * Výstup: `void` – zobrazuje informaci o dostupnosti předpovědi.
 */
function renderForecastAvailability(oStatus){
 const oEl=$('lm-forecast-series-note');if(!oEl)return;const vF=blockAvailable(oStatus?.forecast)?oStatus.forecast:null;if(!vF){oEl.textContent=t(APP,'Detailed VRM forecast is unavailable.');return;}
 const vPv=forecastSeriesPoints(vF,'solarYield'),vCons=forecastSeriesPoints(vF,'consumption'),vFactor=forecastCorrectionFactor(oStatus,vPv);
 oEl.textContent=t(APP,'VRM forecast')+' · '+(Number(vF.intervalMinutes)||15)+' min · '+vPv.length+' / '+vCons.length+' '+t(APP,'points')+(Math.abs(vFactor-1)>.03?' · '+t(APP,'PV correction')+' '+vFactor.toFixed(2)+'×':'');
}

/**
 * Určí současný kalendářní den v časové zóně Praha.
 * Vstupy: žádné.
 * Výstup: `string` – aktuální datum v časové zóně Praha.
 */
function pragueDay(){const aParts=new Intl.DateTimeFormat('en-US',{timeZone:'Europe/Prague',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());/**
 * Vrátí zvolenou část rozparsovaného data.
 * Vstupy: `sKey` (string) – technický klíč veličiny či pole.
 * Výstup: `string` – číselná část data z formátovače časového pásma.
 */
const part=sKey=>aParts.find(/**
 * Vyhledá požadovanou položku v aParts podle type.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `boolean` – true při shodě vyhledávaného identifikátoru.
 */ vP=>vP.type===sKey)?.value||'';return `${part('year')}-${part('month')}-${part('day')}`;}
/**
 * Načte hodnoty dodané energie a denního hrubého výnosu.
 * Vstupy: `bForce` (boolean) – zda vynutit nové načtení namísto cache.
 * Výstup: `Promise<void>` – načte denní příjmy ze SPOT a překreslí hodnoty.
 */
async function loadDailyRevenue(bForce=false){
 if(bDailyLoading||bDisposed)return;
 const sDay=pragueDay();
 if(sDay!==sLastDailyDay){setText('lm-spot-sale-value',t(APP,'Today')+' —');nLastDailyFetchAt=0;}
 if(!bForce&&Date.now()-nLastDailyFetchAt<60000)return;
 bDailyLoading=true;nLastDailyFetchAt=Date.now();
 try{
  const vR=await api('/api/history/daily');if(bDisposed)return;
  sLastDailyDay=String(vR.data?.day||'');
  if(sLastDailyDay!==pragueDay()){setText('lm-spot-sale-value',t(APP,'Today')+' —');nLastDailyFetchAt=0;return;}
  const vD=vR.data||{},bComplete=vD.hasData&&Number(vD.unpricedExportKWh)===0;
  setText('lm-spot-sale-value',t(APP,'Today')+' '+(bComplete&&isValue(vD.saleCzk)?Number(vD.saleCzk).toFixed(2)+' Kč':'—'));
  setTitle('lm-spot-sale-value',vD.hasData&&Number(vD.unpricedExportKWh)>0
   ?t(APP,'SPOT price is missing for part of today’s export; a complete sale amount cannot be shown.')
   :t(APP,'Sum of measured export multiplied by the SPOT price at each sample. Updated after each completed five-minute interval.'));
 }catch(_){if(!bDisposed){setText('lm-spot-sale-value',t(APP,'Today')+' —');setTitle('lm-spot-sale-value',t(APP,'Daily sale data unavailable.'));}}
 finally{bDailyLoading=false;}
}
/**
 * Obnoví stav LINEA, zpracuje nedostupnost a vykreslí LIVE.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – načte živý stav, rozliší výpadek API a aktualizuje data.
 */
async function loadStatus(){
 if(bLoading||bDisposed)return;
 bLoading=true;
 try{
  const vR=await api('/api/linea/status');
  renderStatus(vR.data);
  const oNotice=$('lm-error');oNotice.hidden=true;oNotice.className='lm-alert';
  void loadDailyRevenue();
 }catch(oE){
  if(bDisposed||oE?.name==='AbortError')return;
  const bUnavailable=oE?.code==='linea_api_unavailable';
  const oNotice=$('lm-error');
  oNotice.textContent=bUnavailable
   ?t(APP,'LINEA API is temporarily unavailable. Waiting for reconnection.')
   :oE.message;
  oNotice.className=bUnavailable?'lm-alert lm-alert-info':'lm-alert';
  oNotice.hidden=false;
  const vConnection=$('lm-connection');
  vConnection.className='lm-status '+(bUnavailable?'lm-status-warn':'lm-status-error');
  vConnection.innerHTML='<span class="lm-dot"></span>'+esc(t(APP,'LINEA offline'));
 }finally{bLoading=false;}
}

let vHistoryBounds=null;
let sHistoryRange='this-year',bHistoryLoading=false,bHistoryLoaded=false;
/**
 * Formátuje číslo z historie na požadovaný počet desetinných míst.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null; `nD` (number) – počet desetinných míst.
 * Výstup: `string` – formátované číslo nebo pomlčka při chybějícím měření.
 */
const historyNum=(vV,nD=2)=>isValue(vV)?Number(vV).toFixed(nD):'—';
/**
 * Zformátuje čas historického vzorku.
 * Vstupy: `nTs` (number) – časový okamžik v unixových sekundách; `bWithDate` (boolean) – zda zobrazit i datum.
 * Výstup: `string` – místní datum/čas historického bodu.
 */
function formatHistoryTime(nTs,bWithDate=false){const oD=new Date(Number(nTs)*1000);if(!Number.isFinite(oD.getTime()))return'—';return bWithDate?oD.toLocaleString():oD.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});}
/**
 * Převede měřené body na SVG cestu grafu s mezerami.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů; `sKey` (string) – technický klíč veličiny či pole; `fnX` (function) – funkce převádějící hodnotu/čas na souřadnici X; `fnY` (function) – funkce převádějící hodnotu na souřadnici Y.
 * Výstup: `string` – SVG cesta historické křivky bez mezilehlých neplatných bodů.
 */
function polylinePath(aPoints,sKey,fnX,fnY){let sD='',bStarted2=false;for(const vP of aPoints){const vV=vP[sKey];if(!isValue(vV)){bStarted2=false;continue;}const nPx=fnX(Number(vP.ts)),nPy=fnY(Number(vV));sD+=(bStarted2?' L ':' M ')+nPx.toFixed(1)+' '+nPy.toFixed(1);bStarted2=true;}return sD.trim();}
/**
 * Připraví zobrazené body vybraných historických křivek.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů; `aKeys` (array) – seznam názvů číselných polí historické řady.
 * Výstup: `array` – vyhlazená měření pro graf při zachování chybějících hodnot.
 */
function smoothHistoryPoints(aPoints,aKeys){
 const aOut=(aPoints||[]).map(/**
 * Převede položku kolekce (aPoints||[]) na datový záznam z polí vP.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `object` – mělká kopie historického měření
 */ vP=>({...vP}));
 for(const sKey of aKeys){
  for(let nI=0;nI<aOut.length;nI++){
   const vC=aPoints[nI]?.[sKey];if(!isValue(vC))continue;
   const vPrev=nI>0&&isValue(aPoints[nI-1]?.[sKey])?Number(aPoints[nI-1][sKey]):Number(vC);
   const vNext=nI+1<aPoints.length&&isValue(aPoints[nI+1]?.[sKey])?Number(aPoints[nI+1][sKey]):Number(vC);
   aOut[nI][sKey]=vPrev*.25+Number(vC)*.5+vNext*.25;
  }
 }
 return aOut;
}
const HISTORY_SERIES=[
 ['pv','PV'],['house','House'],['grid','Grid'],['export','Sale power'],['battery','Battery'],['soc','Battery SOC'],['spot','SPOT'],
 ['batteryTemp','Battery temperature'],['rackTemp','Rack temperature'],
 ['inverter1Temp','Inverter 1'],['inverter2Temp','Inverter 2'],['inverter3Temp','Inverter 3'],
 ['forecastPv','PV forecast'],['forecastHouse','Consumption forecast']
];
let aHistoryPoints=[],vHistoryVisible=Object.fromEntries(HISTORY_SERIES.map(/**
 * Převede položku kolekce HISTORY_SERIES na odvozenou hodnotu.
 * Vstupy: `[key]` (array) – položky rozloženého pole.
 * Výstup: `array` – dvojice `[klíč série, true]` pro výchozí nastavení viditelnosti
 */ ([key])=>[key,true])),oHistoryAllowed={...vHistoryVisible},vHistoryZoom=null,nHistorySelectedTs=null,vHistoryChartStatus=null,vHistoryChartNow=null;
const TEMPERATURE_SERIES=['batteryTemp','rackTemp','inverter1Temp','inverter2Temp','inverter3Temp'];
/**
 * Upraví legendu podle dostupných teplotních měření.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů.
 * Výstup: `void` – upraví dostupnost popisků teplotních řad.
 */
function updateTemperatureLegend(aPoints){
 for(const oSeries of TEMPERATURE_SERIES){
  const oBtn=document.querySelector(`[data-series="${oSeries}"]`);
  if(!oBtn)continue;
  oBtn.hidden=!oHistoryAllowed[oSeries]||!aPoints.some(/**
 * Ověří podmínku nad položkou pole.
 * Vstupy: `vP` (mixed).
 * Výstup: `boolean` – stav splnění právě ověřované podmínky.
 */ vP=>isValue(vP[oSeries+'C']));
  oBtn.disabled=false;
 }
}
/**
 * Promítne osobní nastavení do viditelnosti křivek.
 * Vstupy: `aValues` (array) – mapa/pole uložených uživatelských nastavení.
 * Výstup: `void` – zapne nebo vypne uživatelsky vybrané řady grafů.
 */
function applyHistoryPreferences(aValues){
 bHistory3dInvert=aValues.history3dInvert===true;
 for(const [key] of HISTORY_SERIES){
  const bAllowed=aValues['chart_'+key]!==false,vWasAllowed=oHistoryAllowed[key];
  oHistoryAllowed[key]=bAllowed;
  if(!bAllowed)vHistoryVisible[key]=false;
  else if(!vWasAllowed)vHistoryVisible[key]=true;
  const oBtn=document.querySelector(`[data-series="${key}"]`);
  if(oBtn){oBtn.hidden=!bAllowed;oBtn.classList.toggle('active',vHistoryVisible[key]);oBtn.setAttribute('aria-pressed',String(vHistoryVisible[key]));}
 }
 if(activeTab()==='history'&&aHistoryPoints.length>1)renderPowerChart(aHistoryPoints);
}
/**
 * Vykreslí časový graf energií a interaktivní výběr vzorků.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů.
 * Výstup: `void` – vykreslí hlavní graf výkonů a spotových cen z historických dat.
 */
function renderPowerChart(aPoints){
 const oEl=$('lm-history-power-chart');if(!oEl)return;aHistoryPoints=Array.isArray(aPoints)?aPoints:[];
 let vShown=aHistoryPoints;if(vHistoryZoom)vShown=vShown.filter(/**
 * Vybere z kolekce vShown jen položky splňující podmínku nad ts.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vP=>Number(vP.ts)>=vHistoryZoom[0]&&Number(vP.ts)<=vHistoryZoom[1]);
 updateTemperatureLegend(vShown);
 if(vShown.length<2){oEl.innerHTML='<div class="lm-empty">'+esc(t(APP,'There are not yet at least two completed five-minute intervals. The fast collector creates them automatically from approximately one-second data.'))+'</div>';return;}
 const oStatus=vHistoryChartStatus||vLatestStatus||{},vForecast=blockAvailable(oStatus.forecast)?oStatus.forecast:null,vSpotBlock=blockAvailable(oStatus.spot)?oStatus.spot:null;
 const vPvFcRaw=forecastSeriesPoints(vForecast,'solarYield'),vHouseFc=forecastSeriesPoints(vForecast,'consumption'),nNowTs=vHistoryChartNow??Date.now()/1000,vPvFactor=forecastCorrectionFactor(oStatus,vPvFcRaw,nNowTs);
 const aPvFc=vPvFcRaw.map(/**
 * Převede položku kolekce vPvFcRaw na datový záznam z polí vP, ts, powerW.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `object` – bod předpovědi s upraveným výkonem FVE pro budoucí období
 */ vP=>({...vP,powerW:vP.ts>=nNowTs?vP.powerW*vPvFactor:vP.powerW})),vSpotFc=spotSchedulePoints(vSpotBlock);
 const nAvailableWidth=Math.floor(oEl.clientWidth||oBootstrapRoot.clientWidth||window.innerWidth),bMobile=nAvailableWidth<760;
 const nW=Math.max(220,nAvailableWidth),nH=bMobile?348:Math.min(490,Math.max(365,Math.round(nW*.31)));
 const nL=bMobile?57:78,nR=bMobile?108:130,nT=26,nB=bMobile?41:52;
 const aTempKeys=['batteryTempC','rackTempC','inverter1TempC','inverter2TempC','inverter3TempC'];
 const bHasTemps=vShown.some(/**
 * Ověří podmínku nad položkou pole.
 * Vstupy: `vP` (mixed).
 * Výstup: `boolean` – stav splnění právě ověřované podmínky.
 */ vP=>aTempKeys.some(/**
 * Ověří podmínku nad položkou pole.
 * Vstupy: `sKey` (string).
 * Výstup: `boolean` – stav splnění právě ověřované podmínky.
 */ sKey=>isValue(vP[sKey])));
 const vTemperatureLane=bHasTemps?86:0,nPlotW=nW-nL-nR,nPlotH=nH-nT-nB-vTemperatureLane,vTempTop=nT+nPlotH+19,vTempH=vTemperatureLane-27;
 const aRaw=vShown.map(/**
 * Převede položku kolekce vShown na datový záznam z polí vP, gridW, max.
 * Vstupy: `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce.
 * Výstup: `object` – bod historie s vypočteným kladným exportním výkonem
 */ vP=>({...vP,exportW:isValue(vP.gridW)?Math.max(0,-Number(vP.gridW)):null}));
 const aKeys=[['pvW','pv'],['houseW','house'],['gridW','grid'],['exportW','export'],['batteryW','battery']],vVisual=smoothHistoryPoints(aRaw,aKeys.map(/**
 * Vybere identifikátor měření z každé definice časové řady.
 * Vstupy: `[k]` (array) – položky rozloženého pole.
 * Výstup: `string` – interní identifikátor vybrané časové řady
 */ ([k])=>k)),aValues=[];
 // Fix the data domain for this range; legend switches must never rescale it.
 for(const vP of aRaw)for(const [k] of aKeys)if(isValue(vP[k]))aValues.push(Number(vP[k]));
 for(const vP of aPvFc)if(isValue(vP.powerW))aValues.push(Number(vP.powerW));
 for(const vP of vHouseFc)if(isValue(vP.powerW))aValues.push(Number(vP.powerW));
 let nMin=aValues.length?Math.min(0,...aValues):-1,nMax=aValues.length?Math.max(0,...aValues):1;if(nMax===nMin){nMax+=1;nMin-=1;}const vPad=(nMax-nMin)*.08;nMax+=vPad;nMin-=vPad;
 const aHistSpot=vShown.filter(/**
 * Vybere z kolekce vShown jen položky splňující podmínku nad spotPrice.
 * Vstupy: `vP` (mixed) – časový bod s cenou SPOT.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vP=>isValue(vP.spotPrice)).map(/**
 * Převede filtrované historické SPOT body na číselné ceny pro rozsah grafu.
 * Vybere historické body se skutečně dostupnou cenou SPOT.
 * Vstupy: `vP` (mixed) – časový bod s cenou SPOT.
 * Výstup: `number` – dostupná historická cena SPOT
 */ vP=>Number(vP.spotPrice)),aAllSpot=[...aHistSpot,...vSpotFc.map(/**
 * Převede položku kolekce vSpotFc na odvozenou hodnotu z polí price.
 * Vstupy: `vP` (mixed) – časový bod s cenou SPOT.
 * Výstup: `number` – cena předpovězeného hodinového SPOT bodu
 */ vP=>vP.price)];let vSmin=aAllSpot.length?Math.min(...aAllSpot):0,vSmax=aAllSpot.length?Math.max(...aAllSpot):1;if(vSmax===vSmin){vSmax+=.5;vSmin-=.5;}const vSpad=(vSmax-vSmin)*.08;vSmax+=vSpad;vSmin-=vSpad;
 let nT0=Number(vShown[0].ts),nT1=Number(vShown[vShown.length-1].ts)||nT0+1;
 if(!vHistoryZoom&&vHistoryBounds){nT0=vHistoryBounds.from;nT1=Math.max(nT0+1,vHistoryBounds.to);}
 if(!vHistoryZoom&&!vHistoryBounds){const aHorizons=[];if(aPvFc.length)aHorizons.push(aPvFc[aPvFc.length-1].ts);if(vHouseFc.length)aHorizons.push(vHouseFc[vHouseFc.length-1].ts);if(vSpotFc.length)aHorizons.push(vSpotFc[vSpotFc.length-1].ts+(Number(vSpotBlock?.intervalMinutes)||60)*60);if(aHorizons.length)nT1=Math.max(nT1,...aHorizons);}
  /**
 * Ponechá v historických bodech jen vzorky ležící ve vybraném časovém intervalu.
 * Vstupy: `vArr` (mixed) – vstupní údaj z LINEA nebo rozhraní.
 * Výstup: `array` – časově filtrované vzorky historie.
 */
 const within=vArr=>vArr.filter(/**
 * Vybere z kolekce vArr jen položky splňující podmínku nad ts.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vP=>vP.ts>=nT0&&vP.ts<=nT1),vPvF=within(aPvFc),vHouseF=within(vHouseFc),vSpotF=within(vSpotFc);
  /**
  * Přepočítá čas, výkon, cenu a SOC na souřadnice 2D historického grafu.
  * Vstupy: `vT` (mixed) – vstupní údaj z LINEA nebo rozhraní.
  * Výstup: `void` – bez návratové hodnoty.
  */
 const x=vT=>nL+((vT-nT0)/(nT1-nT0||1))*nPlotW,/** Vypočte svislou souřadnici měřené hodnoty na časovém grafu. Vstupy: `vV` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ y=vV=>nT+((nMax-vV)/(nMax-nMin))*nPlotH,/** Převede cenu SPOT na samostatnou svislou osu SVG grafu. Vstupy: `vV` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ ys=vV=>nT+((vSmax-vV)/(vSmax-vSmin))*nPlotH,/** Převede procenta SOC baterie na svislou souřadnici grafu. Vstupy: `vV` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ ysoc=vV=>nT+((100-Math.max(0,Math.min(100,vV)))/100)*nPlotH;
 let sGrid='';for(let nI=0;nI<=5;nI++){const vV=nMax-(nMax-nMin)*nI/5,nPy=y(vV);sGrid+=`<line x1="${nL}" y1="${nPy}" x2="${nW-nR}" y2="${nPy}" class="lm-chart-grid"/><text x="${nL-8}" y="${nPy+4}" class="lm-chart-y">${Math.round(vV)} W</text>`;if(nI%2===0){const nPct=100-nI*20;sGrid+=`<text x="${nW-nR+9}" y="${nPy+4}" class="lm-chart-y lm-chart-y-soc">${nPct}%</text>`;}if(aAllSpot.length&&nI%2===0){const vSv=vSmax-(vSmax-vSmin)*nI/5;sGrid+=`<text x="${nW-45}" y="${nPy+4}" class="lm-chart-y lm-chart-y-right">${vSv.toFixed(2)}</text>`;}}
 const nTickCount=bMobile?2:Math.max(4,Math.min(8,Math.floor(nW/210)));
 let sXt='';for(let nI=0;nI<=nTickCount;nI++){const nTs=nT0+(nT1-nT0)*nI/nTickCount,nPx=x(nTs);const sLabel=bMobile&&nT1-nT0>86400?new Date(nTs*1000).toLocaleDateString([],{day:'numeric',month:'numeric'}):formatHistoryTime(nTs,(nT1-nT0)>86400);sXt+=`<line x1="${nPx}" y1="${nT}" x2="${nPx}" y2="${nH-nB}" class="lm-chart-grid lm-chart-grid-v"/><text x="${nPx}" y="${nH-12}" class="lm-chart-x">${esc(sLabel)}</text>`;}
 let sPaths=aKeys.filter(/**
 * Vybere z kolekce aKeys jen položky splňující zadanou logickou podmínku.
 * Vstupy: `[k,c]` (array) – položky rozloženého pole.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ ([k,c])=>vHistoryVisible[c]).map(/**
 * Vytvoří SVG cesty pro zapnuté energetické série historie.
 * Vybere pouze aktivní historické řady povolené uživatelem.
 * Vstupy: `[k,c]` (array) – položky rozloženého pole.
 * Výstup: `string` – SVG křivka zvolené řady historických výkonů
 */ ([k,c])=>`<path d="${polylinePath(vVisual,k,x,y)}" class="lm-chart-line ${c}"/>`).join('');
 if(vHistoryVisible.soc)sPaths+=`<path d="${polylinePath(vShown,'socPct',x,ysoc)}" class="lm-chart-line soc"/>`;
 if(vHistoryVisible.spot){sPaths+=`<path d="${stepPath(vShown,'spotPrice',x,ys,Number(vShown[0]?.resolutionSec)||3600)}" class="lm-chart-line spot"/>`;if(vSpotF.length)sPaths+=`<path d="${stepPath(vSpotF,'price',x,ys,(Number(vSpotBlock?.intervalMinutes)||60)*60)}" class="lm-chart-line spot-forecast"/>`;}
 if(vHistoryVisible.forecastPv&&vPvF.length)sPaths+=`<path d="${polylinePath(vPvF,'powerW',x,y)}" class="lm-chart-line forecast-pv"/>`;
 if(vHistoryVisible.forecastHouse&&vHouseF.length)sPaths+=`<path d="${polylinePath(vHouseF,'powerW',x,y)}" class="lm-chart-line forecast-house"/>`;
 if(bHasTemps){
  const aTemps=vShown.flatMap(/**
 * Ze všech viditelných bodů historie sesbírá dostupné teploty pro výpočet rozsahu svislé osy.
 * Vstupy: `vP` (mixed) – historické měření s dostupnými teplotami.
 * Výstup: `array` – teploty jednoho historického vzorku pro všechny dostupné senzory.
 */ vP=>aTempKeys.map(/**
 * Vrátí hodnotu zvoleného teplotního senzoru pro jeden vzorek historie.
 * Vstupy: `sKey` (string) – název datového pole teplotního senzoru nebo měření.
 * Výstup: `number|null|undefined` – údaj teploty aktuálního senzoru
 */ sKey=>vP[sKey])).filter(isValue).map(Number),nLow=Math.floor((Math.min(...aTemps)-2)/5)*5,nHigh=Math.ceil((Math.max(...aTemps)+2)/5)*5;
    /**
 * Převede hodnotu teploty na svislou souřadnici samostatné teplotní osy grafu.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `number` – pozice v pixelech ve vykreslované SVG ploše.
 */
  const yt=vV=>vTempTop+((nHigh-Number(vV))/(nHigh-nLow||1))*vTempH;
  sPaths+=`<line x1="${nL}" y1="${vTempTop-8}" x2="${nW-nR}" y2="${vTempTop-8}" class="lm-chart-grid"/><text x="${nL-8}" y="${vTempTop+8}" class="lm-chart-y">${nHigh}°C</text><text x="${nL-8}" y="${vTempTop+vTempH}" class="lm-chart-y">${nLow}°C</text>`;
  if(vHistoryVisible.batteryTemp)sPaths+=`<path d="${polylinePath(vShown,'batteryTempC',x,yt)}" class="lm-chart-line battery-temp"/>`;
  if(vHistoryVisible.rackTemp)sPaths+=`<path d="${polylinePath(vShown,'rackTempC',x,yt)}" class="lm-chart-line rack-temp"/>`;
  for(const nI of [1,2,3])if(vHistoryVisible['inverter'+nI+'Temp'])sPaths+=`<path d="${polylinePath(vShown,'inverter'+nI+'TempC',x,yt)}" class="lm-chart-line inverter-${nI}-temp"/>`;
 }
 const vNowX=(nNowTs>=nT0&&nNowTs<=nT1)?x(nNowTs):null,vFutureShade=vNowX!==null?`<rect x="${vNowX}" y="${nT}" width="${Math.max(0,nW-nR-vNowX)}" height="${nPlotH}" class="lm-chart-future"/><line x1="${vNowX}" y1="${nT}" x2="${vNowX}" y2="${nH-nB}" class="lm-chart-now"/>`:'';
 oEl.innerHTML=`<div class="lm-chart-stage"><svg class="lm-history-svg" viewBox="0 0 ${nW} ${nH}" role="img" aria-label="${esc(t(APP,'Energy flow history'))}"><text x="${nW-nR+9}" y="14" class="lm-chart-axis-title lm-chart-axis-soc">%</text><text x="${nW-6}" y="14" class="lm-chart-axis-title">CZK/kWh</text>${sGrid}${sXt}${vFutureShade}<line x1="${nL}" y1="${y(0)}" x2="${nW-nR}" y2="${y(0)}" class="lm-chart-zero"/>${sPaths}<line id="lm-chart-cursor" class="lm-chart-cursor" x1="0" y1="${nT}" x2="0" y2="${nH-nB}" visibility="hidden"/><rect id="lm-chart-selection" class="lm-chart-selection" x="0" y="${nT}" width="0" height="${nPlotH}" visibility="hidden"/><rect class="lm-chart-hit" x="${nL}" y="${nT}" width="${nPlotW}" height="${nH-nB-nT}"/></svg></div><div id="lm-chart-tooltip" class="lm-chart-tooltip lm-chart-tooltip-below" role="status" aria-live="polite">${esc(t(APP,'Tap a point in the chart to inspect values.'))}</div><div class="lm-chart-hint">${esc(t(APP,'Tap to inspect values; drag with a mouse to zoom.'))}</div>`;
 const vSvg=oEl.querySelector('svg'),vHit=oEl.querySelector('.lm-chart-hit'),vCursor=$('lm-chart-cursor'),vTip=$('lm-chart-tooltip'),vSel=$('lm-chart-selection');let vDragStart=null;
  /**
 * Převede souřadnice pointeru v okně prohlížeče do souřadnic SVG grafu.
 * Vstupy: `vE` (mixed) – vstupní údaj z LINEA nebo rozhraní.
 * Výstup: `object` – dvojice souřadnic `{x,y}` nebo náhradní bod při nemožnosti transformace.
 */
 const svgPoint=vE=>{try{const vP=vSvg.createSVGPoint();vP.x=vE.clientX;vP.y=vE.clientY;const vM=vSvg.getScreenCTM();if(vM){const vQ=vP.matrixTransform(vM.inverse());return{x:vQ.x,y:vQ.y};}}catch(_){}const vR=vSvg.getBoundingClientRect();return{x:(vE.clientX-vR.left)*nW/vR.width,y:(vE.clientY-vR.top)*nH/vR.height};};
  /**
  * Vyhledá nejbližší vzorek k požadovanému času.
  * Vstupy: `vArr` (mixed) – vstupní údaj z LINEA nebo rozhraní; `nTs` (number) – časový okamžik v unixových sekundách; `vTol` (mixed) – vstupní údaj z LINEA nebo rozhraní.
  * Výstup: `object | null` – nejbližší vzorek ve zvolené toleranci nebo null.
  */
 const nearestByTs=(vArr,nTs,vTol)=>{if(!vArr.length)return null;const vP=vArr.reduce(/**
 * Přičte nebo sloučí údaje položky vArr do průběžného akumulátoru podle polí abs, ts.
 * Vstupy: `vA` (mixed) – dosavadní akumulovaná hodnota; `vB` (mixed) – aktuální příspěvek do akumulace.
 * Výstup: nová průběžná akumulovaná hodnota.
 */ (vA,vB)=>Math.abs(Number(vB.ts)-nTs)<Math.abs(Number(vA.ts)-nTs)?vB:vA);return Math.abs(Number(vP.ts)-nTs)<=vTol?vP:null;};
  /**
 * Vyhledá hodnoty pro vybraný čas grafu a aktualizuje informační panel měření.
 * Vstupy: `nTs` (number) – časový okamžik v unixových sekundách.
 * Výstup: `void` – upraví čtené hodnoty a čas na obrazovce.
 */
 const updateReadout=nTs=>{
  nHistorySelectedTs=nTs;const vClampedX=x(nTs);vCursor.setAttribute('x1',vClampedX);vCursor.setAttribute('x2',vClampedX);vCursor.setAttribute('visibility','visible');
  const vP=nearestByTs(vShown,nTs,Math.max(450,Number(vShown[0]?.resolutionSec||300)*1.5)),vPf=nearestByTs(vPvF,nTs,Math.max(900,(Number(vForecast?.intervalMinutes)||15)*60)),vHf=nearestByTs(vHouseF,nTs,Math.max(900,(Number(vForecast?.intervalMinutes)||15)*60)),vSf=nearestByTs(vSpotF,nTs,2400);const vSale=vP&&isValue(vP.saleCzk)?Number(vP.saleCzk):null;
  const aRows=[
   oHistoryAllowed.pv?`<span>☀ FVE <b>${vP?historyNum(vP.pvW,0):'—'} W</b></span>`:null,
   oHistoryAllowed.house?`<span>🏠 ${esc(t(APP,'House'))} <b>${vP?historyNum(vP.houseW,0):'—'} W</b></span>`:null,
   oHistoryAllowed.grid?`<span>⚡ ${esc(t(APP,'Grid'))} <b>${vP?historyNum(vP.gridW,0):'—'} W</b></span>`:null,
   oHistoryAllowed.export?`<span>↑ ${esc(t(APP,'Sale power'))} <b>${vP&&isValue(vP.gridW)?historyNum(Math.max(0,-Number(vP.gridW)),0):'—'} W</b></span>`:null,
   oHistoryAllowed.battery?`<span>🔋 ${esc(t(APP,'Battery'))} <b>${vP?historyNum(vP.batteryW,0):'—'} W</b></span>`:null,
   oHistoryAllowed.soc?`<span>🔋 SOC <b>${vP?historyNum(vP.socPct,1):'—'} %</b></span>`:null,
   oHistoryAllowed.spot?`<span>💰 SPOT <b>${vP?historyNum(vP.spotPrice,2):'—'} CZK/kWh</b></span>`:null,
   oHistoryAllowed.batteryTemp&&vShown.some(/**
 * Ověří podmínku nad položkou pole.
 * Vstupy: `oPoint` (object).
 * Výstup: `boolean` – stav splnění právě ověřované podmínky.
 */ oPoint=>isValue(oPoint.batteryTempC))?`<span>🌡 ${esc(t(APP,'Battery temperature'))} <b>${vP?historyNum(vP.batteryTempC,1):'—'} °C</b></span>`:null,
   oHistoryAllowed.rackTemp&&vShown.some(/**
 * Ověří podmínku nad položkou pole.
 * Vstupy: `oPoint` (object).
 * Výstup: `boolean` – stav splnění právě ověřované podmínky.
 */ oPoint=>isValue(oPoint.rackTempC))?`<span>🌡 ${esc(t(APP,'Rack temperature'))} <b>${vP?historyNum(vP.rackTempC,1):'—'} °C</b></span>`:null,
   ...[1,2,3].map(/**
 * Převede položku kolekce [1,2,3] na odvozenou hodnotu z polí some.
 * Vstupy: `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `boolean` – true, pokud má daný měnič zobrazit teplotu
 */ nI=>oHistoryAllowed['inverter'+nI+'Temp']&&vShown.some(/**
 * Ověří podmínku nad položkou pole.
 * Vstupy: `oPoint` (object).
 * Výstup: `boolean` – stav splnění právě ověřované podmínky.
 */ oPoint=>isValue(oPoint['inverter'+nI+'TempC']))?`<span>🌡 ${esc(t(APP,'Inverter'))} ${nI} <b>${vP?historyNum(vP['inverter'+nI+'TempC'],1):'—'} °C</b></span>`:null),
   `<span>↑ ${esc(t(APP,'Sale in interval'))} <b>${vP?historyNum(vP.gridExportKWh,3):'—'} kWh</b></span>`,
   `<span>💵 ${esc(t(APP,'Sale value'))} <b>${vSale===null?'—':vSale.toFixed(3)} CZK</b></span>`,
   oHistoryAllowed.forecastPv?`<span>☀ ${esc(t(APP,'PV forecast'))} <b>${vPf?historyNum(vPf.powerW,0):'—'} W</b></span>`:null,
   oHistoryAllowed.forecastHouse?`<span>🏠 ${esc(t(APP,'Consumption forecast'))} <b>${vHf?historyNum(vHf.powerW,0):'—'} W</b></span>`:null,
   oHistoryAllowed.spot?`<span>💰 ${esc(t(APP,'SPOT schedule'))} <b>${vSf?historyNum(vSf.price,2):'—'} CZK/kWh</b></span>`:null
  ];
  vTip.innerHTML=`<strong>${esc(formatHistoryTime(nTs,true))}</strong>${aRows.filter(Boolean).join('')}`;
 };
  /**
 * Při kliknutí nebo tažení v grafu převede bod ukazatele na čas a vybere interval nebo aktuální měření.
 * Vstupy: `vE` (mixed) – vstupní údaj z LINEA nebo rozhraní.
 * Výstup: `void` – změní výběr grafu a informační panel.
 */
 const selectAt=vE=>{const nPx=Math.max(nL,Math.min(nW-nR,svgPoint(vE).x));updateReadout(nT0+((nPx-nL)/nPlotW)*(nT1-nT0));if(vDragStart!==null){const nA=Math.min(vDragStart,nPx),nB=Math.max(vDragStart,nPx);vSel.setAttribute('x',nA);vSel.setAttribute('width',nB-nA);vSel.setAttribute('visibility','visible');}};
 vHit.addEventListener('pointerdown',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{selectAt(vE);if(vE.pointerType==='touch')return;vDragStart=Math.max(nL,Math.min(nW-nR,svgPoint(vE).x));vHit.setPointerCapture?.(vE.pointerId);});
 vHit.addEventListener('pointermove',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{if(vE.pointerType!=='touch'||vE.buttons)selectAt(vE);});
 vHit.addEventListener('pointerup',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{if(vE.pointerType==='touch'){selectAt(vE);return;}if(vDragStart===null)return;const nEnd=Math.max(nL,Math.min(nW-nR,svgPoint(vE).x));if(Math.abs(nEnd-vDragStart)>25){const nA=Math.min(vDragStart,nEnd),nB=Math.max(vDragStart,nEnd);vHistoryZoom=[nT0+((nA-nL)/nPlotW)*(nT1-nT0),nT0+((nB-nL)/nPlotW)*(nT1-nT0)];$('lm-history-zoom-reset').hidden=false;}vDragStart=null;renderPowerChart(aHistoryPoints);});
 if(nHistorySelectedTs!==null&&nHistorySelectedTs>=nT0&&nHistorySelectedTs<=nT1)updateReadout(nHistorySelectedTs);
}
/**
 * Vykreslí graf historického stavu nabití baterie.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů.
 * Výstup: `void` – vykreslí graf SOC baterie a dostupných teplot.
 */
function renderSocChart(aPoints){
 const oEl=$('lm-history-soc-chart');if(!oEl)return;const aValid=(aPoints||[]).filter(/**
 * Vybere z kolekce (aPoints||[]) jen položky splňující podmínku nad socPct.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vP=>isValue(vP.socPct));if(aValid.length<2){oEl.innerHTML='<div class="lm-empty">'+esc(t(APP,'No SOC data yet.'))+'</div>';return;}
 const nW=1000,nH=220,nL=58,nR=18,nT=16,nB=42,nPlotW=nW-nL-nR,nPlotH=nH-nT-nB,nT0=Number(aValid[0].ts),nT1=Number(aValid[aValid.length-1].ts)||nT0+1;
  /**
  * Převádí čas a hodinovou cenu SPOT na souřadnice SVG grafu.
  * Vstupy: `vT` (mixed) – vstupní údaj z LINEA nebo rozhraní.
  * Výstup: `void` – bez návratové hodnoty.
  */
 const x=vT=>nL+((vT-nT0)/(nT1-nT0||1))*nPlotW,/** Vypočte svislou souřadnici měřené hodnoty na časovém grafu. Vstupy: `vV` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ y=vV=>nT+((100-vV)/100)*nPlotH;let sGrid='';for(const vV of [0,25,50,75,100]){const nPy=y(vV);sGrid+=`<line x1="${nL}" y1="${nPy}" x2="${nW-nR}" y2="${nPy}" class="lm-chart-grid"/><text x="${nL-9}" y="${nPy+4}" class="lm-chart-y">${vV}%</text>`;}
 let sXt='';for(let nI=0;nI<=4;nI++){const nTs=nT0+(nT1-nT0)*nI/4,nPx=x(nTs);sXt+=`<text x="${nPx}" y="${nH-13}" class="lm-chart-x">${esc(formatHistoryTime(nTs,(nT1-nT0)>86400))}</text>`;}
 oEl.innerHTML=`<svg class="lm-history-svg" viewBox="0 0 ${nW} ${nH}" role="img" aria-label="${esc(t(APP,'Historical battery state of charge'))}">${sGrid}${sXt}<path d="${polylinePath(aValid,'socPct',x,y)}" class="lm-chart-line soc"/></svg>`;
}
/**
 * Zobrazí poslední známé události sběrače.
 * Vstupy: `vEvents` (mixed) – seznam událostí historie.
 * Výstup: `void` – vyplní seznam událostí historie nebo stav bez dat.
 */
function renderHistoryEvents(vEvents){const oEl=$('lm-history-events');if(!oEl)return;if(!Array.isArray(vEvents)||!vEvents.length){oEl.innerHTML='<div class="lm-empty">'+esc(t(APP,'No events yet.'))+'</div>';return;}oEl.innerHTML=vEvents.map(/**
 * Převede položku kolekce vEvents na HTML záznam pro UI z polí severity, ts, toLocaleString.
 * Vstupy: `vEv` (mixed) – záznam události včetně času a závažnosti.
 * Výstup: `string` – HTML řádek události historie se závažností a časem
 */ vEv=>`<div class="lm-event ${esc(vEv.severity||'info')}"><time>${esc(new Date(Number(vEv.ts)*1000).toLocaleString())}</time><strong>${esc(vEv.title||vEv.type||t(APP,'Event'))}</strong><small>${esc(vEv.oldValue??'')} ${vEv.oldValue!==null&&vEv.newValue!==null?'→':''} ${esc(vEv.newValue??'')}</small></div>`).join('');}
// Interactive local canvas surface: no network assets or changes to stored measurements.
let sHistoryView='2d',nHistoryResolution=300,nHistory3dFrame=0;
let bHistory3dInvert=false;
const oH3={yaw:-.7,pitch:.55,roll:0,zoom:1,panX:0,panY:0,cells:[],mesh:null};
/**
 * Sestaví řídkou mřížku agregovaných hodnot pro 3D graf.
 * Vstupy: `aPoints` (array) – pole historických nebo předpovědních bodů; `sKey` (string) – technický klíč veličiny či pole; `vResolution` (mixed) – požadované rozlišení historie.
 * Výstup: `object|null` – výšková mřížka 3D historie pro danou veličinu nebo null.
 */
function makeHistorySurface(aPoints,sKey,vResolution){
 const aValid=(aPoints||[]).filter(/**
 * Vybere z kolekce (aPoints||[]) jen položky splňující podmínku nad isFinite, ts.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vP=>Number.isFinite(Number(vP.ts))).sort(/**
 * Porovná dvojici položek a určí jejich pořadí podle pole ts.
 * Vstupy: `vA` (mixed) – první měřený bod pro určení pořadí; `vB` (mixed) – druhý měřený bod pro určení pořadí.
 * Výstup: záporné/nulové/kladné číslo pro pořadí položek.
 */ (vA,vB)=>vA.ts-vB.ts);
 if(!aValid.length)return null;
 const oFormatter=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Prague',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});/**
 * Rozloží unixový čas na pražský kalendářní den a minutu dne pro 3D mřížku.
 * Vstupy: `nTs` (number) – časový okamžik v unixových sekundách.
 * Výstup: `object` – {day: pořadový UTC den v pražském čase, minute: minuta dne}.
 */
const dateParts=nTs=>{const aParts=oFormatter.formatToParts(new Date(nTs*1000));const oO=Object.fromEntries(aParts.map(/**
 * Převede položku kolekce aParts na odvozenou hodnotu z polí type, value.
 * Vstupy: `oPart` (object) – dvojice `type` a `value` z Intl.DateTimeFormat.
 * Výstup: `array` – dvojice `[type,value]` pro převod časových polí na objekt
 */ oPart=>[oPart.type,oPart.value]));return {day:Date.UTC(+oO.year,+oO.month-1,+oO.day)/86400000,minute:+oO.hour*60 + +oO.minute};};
 const vFirst=dateParts(aValid[0].ts),vLast=dateParts(aValid[aValid.length-1].ts);
 const vDays=vLast.day-vFirst.day+1,nRows=Math.min(64,vDays),nCols=Math.max(2,Math.min(96,Math.floor(86400/Math.max(300,vResolution))));
 const vBuckets=Array.from({length:nRows},/**
 * Pro každý den vytvoří pole prázdných hodinových buněk 3D povrchu.
 * Vstupy: žádné.
 * Výstup: `array` – řádek prázdných hodinových buněk 3D grafu.
 */ ()=>Array.from({length:nCols},/**
 * Založí buňku 3D mřížky s nulovým součtem, počtem měření a neobsazeným časem.
 * Vstupy: žádné.
 * Výstup: `object` – prázdná buňka s nulovým součtem, počtem a časem null.
 */ ()=>({sum:0,count:0,ts:null,raw:null})));
 for(const vP of aValid){const vD=dateParts(+vP.ts),nR=Math.min(nRows-1,Math.floor((vD.day-vFirst.day)*nRows/vDays)),nC=Math.min(nCols-1,Math.floor(vD.minute*nCols/1440));const vB=vBuckets[nR][nC];if(isValue(vP[sKey])){vB.sum+=Number(vP[sKey]);vB.count++;vB.ts=+vP.ts;vB.raw=Number(vP[sKey]);}}
 const aValues=vBuckets.flat().filter(/**
 * Vybere z kolekce vBuckets.flat() jen položky splňující podmínku nad count.
 * Vstupy: `vB` (mixed) – druhá hodnota z porovnávané nebo zobrazované dvojice.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vB=>vB.count).map(/**
 * Vypočte průměr naměřené veličiny v každé obsazené 3D buňce.
 * Ověří položku p na odvozenou hodnotu z polí sum, count.
 * Vstupy: `vB` (mixed) – druhá hodnota z porovnávané nebo zobrazované dvojice.
 * Výstup: `number` – aritmetický průměr naměřené hodnoty v dané buňce
 */ vB=>vB.sum/vB.count);if(!aValues.length)return null;
 const nLow=Math.min(...aValues),nHigh=Math.max(...aValues);
 return {buckets: vBuckets,rows: nRows,cols: nCols,low: nLow,high: nHigh,days: vDays,firstDay:vFirst.day,key: sKey,resolution: vResolution};
}
/**
 * Naplánuje překreslení 3D plochy v dalším snímku prohlížeče.
 * Vstupy: žádné.
 * Výstup: `void` – naplánuje jediné překreslení Canvas v animovaném snímku.
 */
function h3Schedule(){if(nHistory3dFrame||bDisposed)return;nHistory3dFrame=requestAnimationFrame(/**
 * Provede naplánované překreslení grafu při následujícím animačním snímku.
 * Vstupy: žádné.
 * Výstup: void – změna grafu bez návratové hodnoty.
 */ ()=>{nHistory3dFrame=0;drawHistorySurface();});}
/**
 * Převede výšku povrchu na uživatelskou barevnou škálu.
 * Vstupy: `vValue` (mixed) – hodnota měření, která může být nedostupná; `nLow` (number) – dolní hranice zobrazené hodnoty; `nHigh` (number) – horní hranice zobrazené hodnoty.
 * Výstup: `string` – RGB barva interpolovaná z uživatelské škály.
 */
function h3Color(vValue,nLow,nHigh){/**
 * Přečte barevnou hodnotu HEX ze zadaného ovládacího prvku a rozloží ji na kanály RGB.
 * Vstupy: `sId` (string) – ID HTML prvku nebo šablony.
 * Výstup: `number[]` – tři barevné kanály v rozsahu 0–255.
 */
const read=sId=>{const sS=$(sId)?.value||'#000000';return [1,3,5].map(/**
 * Převede položku kolekce [1,3,5] na odvozenou hodnotu z polí slice.
 * Vstupy: `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `number` – jedna složka barvy RGB 0–255
 */ nI=>parseInt(sS.slice(nI,nI+2),16));};const vA=read('lm-3d-low'),vB=read('lm-3d-high'),vF=nHigh===nLow?.5:Math.max(0,Math.min(1,(vValue-nLow)/(nHigh-nLow)));return `rgb(${vA.map(/**
 * Převede položku kolekce vA na odvozenou hodnotu z polí round.
 * Vstupy: `nX` (number) – aktuální záznam příslušného datového vstupu; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `number` – interpolovaná a zaokrouhlená barevná složka RGB
 */ (nX,nI)=>Math.round(nX+(vB[nI]-nX)*vF)).join(',')})`;}
/**
 * Vykreslí 3D historii na plátno včetně chybějících měření.
 * Vstupy: žádné.
 * Výstup: `void` – vykreslí 3D plochu a interakční mřížku Canvas.
 */
function drawHistorySurface(){
 if(sHistoryView!=='3d'||activeTab()!=='history')return;
 const oCanvas=$('lm-3d-canvas');if(!oCanvas)return;const vCtx=oCanvas.getContext('2d');if(!vCtx)return;
 const vTop=oCanvas.getBoundingClientRect().top;oCanvas.style.height=Math.max(280,(window.innerHeight||800)-vTop-68)+'px';
 const oRect=oCanvas.getBoundingClientRect(),nW=Math.max(320,oRect.width),nHeight=Math.max(280,oRect.height),nDpr=Math.min(2,window.devicePixelRatio||1);oCanvas.width=nW*nDpr;oCanvas.height=nHeight*nDpr;vCtx.scale(nDpr,nDpr);vCtx.fillStyle='#141c29';vCtx.fillRect(0,0,nW,nHeight);
 const vM=oH3.mesh;oH3.cells=[];if(!vM){vCtx.fillStyle='#fff';vCtx.font='14px sans-serif';vCtx.fillText(t(APP,'No historical data have been stored yet.'),20,40);return;}
 const vScale=Math.min(nW,nHeight)*.33*oH3.zoom,nCy=Math.cos(oH3.yaw),nSy=Math.sin(oH3.yaw),nCp=Math.cos(oH3.pitch),nSp=Math.sin(oH3.pitch),nCr=Math.cos(oH3.roll),nSr=Math.sin(oH3.roll);
  /**
 * Promítne trojrozměrný bod včetně natočení a posunu kamery do souřadnic Canvas.
 * Vstupy: `nX` (number) – souřadnice X nebo číslo podle kontextu funkce; `nY` (number) – souřadnice Y nebo číslo podle kontextu funkce; `nZ` (number) – souřadnice Z.
 * Výstup: `object` – souřadnice plátna a hloubka `{x,y,depth}`.
 */
 const project=(nX,nY,nZ)=>{const vA=nX*nCy-nY*nSy,vB=nX*nSy+nY*nCy,vC=vB*nCp-nZ*nSp,vD=vB*nSp+nZ*nCp;return {x:nW/2+oH3.panX+(vA*nCr-vC*nSr)*vScale,y:nHeight*.57+oH3.panY+(vA*nSr+vC*nCr)*vScale,depth:vD};};
  /**
  * Převádí sloupec, řádek a hodnotu měření na souřadnice 3D povrchu.
  * Vstupy: `vC` (mixed) – vstupní údaj z LINEA nebo rozhraní.
  * Výstup: `void` – bez návratové hodnoty.
  */
 const x=vC=>-1+2*vC/vM.cols,/** Vypočte svislou souřadnici měřené hodnoty na časovém grafu. Vstupy: `vR` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ y=vR=>-1+2*vR/vM.rows,/** Převede naměřenou hodnotu na normalizovanou výšku 3D povrchu. Vstupy: `vV` – měřená hodnota nebo index. Výstup: `number` – číselná souřadnice grafu. */ z=vV=>((vV-vM.low)/(vM.high-vM.low||1))*1.3;
 const nStrength=Number($('lm-3d-smooth')?.value||0);
  /**
  * Vrátí agregovanou hodnotu 3D buňky a případné vyhlazení.
  * Vstupy: `vR` (mixed) – vstupní údaj z LINEA nebo rozhraní; `vC` (mixed) – vstupní údaj z LINEA nebo rozhraní.
  * Výstup: `number | null` – hodnota z 3D buňky po vyhlazení nebo null pro chybějící vzorek.
  */
 const displayValue=(vR,vC)=>{const vB=vM.buckets[vR][vC];if(!vB.count)return null;const nRaw=vB.sum/vB.count;if(!nStrength)return nRaw;let nSum=nRaw*4,nN=4;for(const [dr,dc] of [[0,-1],[0,1],[-1,0],[1,0]]){const vB2=vM.buckets[vR+dr]?.[vC+dc];if(vB2?.count){nSum+=vB2.sum/vB2.count;nN++;}}return nRaw*(1-nStrength/100)+(nSum/nN)*(nStrength/100);};
 const aFaces=[];
 for(let nR=0;nR<vM.rows;nR++)for(let nC=0;nC<vM.cols;nC++){const vB=vM.buckets[nR][nC],vV=displayValue(nR,nC),bMissing=vV===null;
 // Cell corners share means of neighbouring valid samples. Missing cells stay on the base plane.
  /**
  * Vypočte vrchol povrchu z okolních dostupných buněk.
  * Vstupy: `vRr` (mixed) – vstupní údaj z LINEA nebo rozhraní; `vCc` (mixed) – vstupní údaj z LINEA nebo rozhraní.
  * Výstup: `number` – vypočtená výška vrcholu 3D buňky.
  */
 const corner=(vRr,vCc)=>{if(bMissing)return 0;let nSum=0,nN=0;for(const vDr of [-1,0])for(const vDc of [-1,0]){const vR2=vRr+vDr,vC2=vCc+vDc;if(vR2>=0&&vR2<vM.rows&&vC2>=0&&vC2<vM.cols){const vVv=displayValue(vR2,vC2);if(vVv!==null){nSum+=vVv;nN++;}}}return z(nN?nSum/nN:vV);};
 const aVertices=[[nR,nC],[nR,nC+1],[nR+1,nC+1],[nR+1,nC]].map(/**
 * Převede položku kolekce [[nR,nC],[nR,nC+1],[nR+1,nC+1],[nR+1,nC]] na odvozenou hodnotu.
 * Vstupy: `[rr,cc]` (array) – položky rozloženého pole.
 * Výstup: `object` – promítnutý vrchol stěny 3D grafu
 */ ([rr,cc])=>project(x(cc),y(rr),corner(rr,cc)));
 aFaces.push({vertices: aVertices,depth:aVertices.reduce(/**
 * Přičte nebo sloučí údaje položky aVertices do průběžného akumulátoru podle polí depth.
 * Vstupy: `nSum` (number) – dosud nasčítaná hodnota průběžného výpočtu; `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce.
 * Výstup: nová průběžná akumulovaná hodnota.
 */ (nSum,vP)=>nSum+vP.depth,0)/4,b: vB,r: nR,c: nC,v: vV,missing: bMissing});}
 aFaces.sort(/**
 * Porovná dvojici položek a určí jejich pořadí podle pole depth.
 * Vstupy: `vA` (mixed) – první měřený bod pro určení pořadí; `vB` (mixed) – druhý měřený bod pro určení pořadí.
 * Výstup: záporné/nulové/kladné číslo pro pořadí položek.
 */ (vA,vB)=>vA.depth-vB.depth);
 for(const vF of aFaces){vCtx.beginPath();vF.vertices.forEach(/**
 * Projde položky kolekce vF.vertices a provede popsanou změnu dat nebo stavu.
 * Vstupy: `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ (vP,nI)=>nI?vCtx.lineTo(vP.x,vP.y):vCtx.moveTo(vP.x,vP.y));vCtx.closePath();vCtx.fillStyle=vF.missing?$('lm-3d-gap').value:h3Color(vF.v,vM.low,vM.high);vCtx.fill();vCtx.strokeStyle='rgba(255,255,255,.07)';vCtx.lineWidth=.5;vCtx.stroke();oH3.cells.push(vF);}
 vCtx.strokeStyle='#b9cadb';vCtx.fillStyle='#e9eef5';vCtx.lineWidth=1;vCtx.font='12px sans-serif';
  /**
 * Nakreslí osu 3D grafu mezi dvěma body a doplní její popisek.
 * Vstupy: `vA` (mixed) – vstupní údaj z LINEA nebo rozhraní; `vB` (mixed) – vstupní údaj z LINEA nebo rozhraní; `sLabel` (string) – viditelný popisek.
 * Výstup: `void` – kreslí do kontextu Canvas, nic nevrací.
 */
 const line=(vA,vB,sLabel)=>{const vP=project(...vA),vQ=project(...vB);vCtx.beginPath();vCtx.moveTo(vP.x,vP.y);vCtx.lineTo(vQ.x,vQ.y);vCtx.stroke();vCtx.fillText(sLabel,vQ.x+5,vQ.y);};
 line([-1,-1,0],[1.15,-1,0],'00:00 → 24:00');line([-1,-1,0],[-1,1.15,0],t(APP,'Date'));line([-1,-1,0],[-1,-1,1.5],t(APP,vM.key==='spot'?'SPOT':HISTORY_SERIES.find(/**
 * Vyhledá požadovanou položku v HISTORY_SERIES podle key.
 * Vstupy: `aSeries` (array) – aktuální záznam příslušného datového vstupu.
 * Výstup: `boolean` – true při shodě vyhledávaného identifikátoru.
 */ aSeries=>aSeries[0]===vM.key)?.[1]||vM.key));
 const sUnit=vM.key==='soc'?'%':vM.key==='spot'?'CZK/kWh':TEMPERATURE_SERIES.includes(vM.key)?'°C':'W';
 vCtx.fillText(`${vM.low.toFixed(2)} → ${vM.high.toFixed(2)} ${sUnit}`,16,24);vCtx.fillText(`${vM.days} ${t(APP,'days')} · ${vM.rows} × ${vM.cols}`,16,44);
  /**
 * Převede pořadový den používaný na 3D ose na místní text data.
 * Vstupy: `vD` (mixed) – vstupní údaj z LINEA nebo rozhraní.
 * Výstup: `string` – formátované datum pro popisek grafu.
 */
 const dayString=vD=>new Date(vD*86400000).toLocaleDateString(undefined,{timeZone:'UTC'});vCtx.fillText(`${dayString(vM.firstDay)} → ${dayString(vM.firstDay+vM.days-1)}`,16,64);
}
/**
 * Připraví 3D model pro vybranou veličinu a období.
 * Vstupy: žádné.
 * Výstup: `void` – vytvoří datový model 3D pro zvolenou řadu.
 */
function h3Generate(){const sKey=$('lm-3d-series')?.value||'pv';const oFields={pv:'pvW',house:'houseW',grid:'gridW',battery:'batteryW',soc:'socPct',spot:'spotPrice',batteryTemp:'batteryTempC',rackTemp:'rackTempC',inverter1Temp:'inverter1TempC',inverter2Temp:'inverter2TempC',inverter3Temp:'inverter3TempC'};const aPoints=aHistoryPoints.map(/**
 * Převede položku kolekce aHistoryPoints na datový záznam z polí vP, gridW, max.
 * Vstupy: `vP` (mixed) – historické časově označené měření.
 * Výstup: `object` – normalizovaný bod 3D historie s hodnotou vybrané řady
 */ vP=>({...vP,[sKey]:sKey==='export'?(isValue(vP.gridW)?Math.max(0,-Number(vP.gridW)):null):vP[oFields[sKey]]}));oH3.mesh=makeHistorySurface(aPoints,sKey,nHistoryResolution);h3Schedule();}
/**
 * Přepne zobrazení historie mezi 2D a 3D.
 * Vstupy: `vView` (mixed) – vybraný režim zobrazení historie.
 * Výstup: `void` – přepne režim historie a aktualizuje ovládací prvky.
 */
function setHistoryView(vView){sHistoryView=vView;document.querySelectorAll('[data-history-view]').forEach(/**
 * Projde položky kolekce document.querySelectorAll('[data-history-view a aktualizuje odpovídající prvky rozhraní.
 * Vstupy: `vB` (mixed) – druhá hodnota z porovnávané nebo zobrazované dvojice.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ vB=>{vB.classList.toggle('active',vB.dataset.historyView===vView);vB.setAttribute('aria-pressed',String(vB.dataset.historyView===vView));});$('lm-history-2d').hidden=vView!=='2d';$('lm-history-3d').hidden=vView!=='3d';if(vView==='3d')h3Generate();else renderPowerChart(aHistoryPoints);}
/**
 * Nastaví ovládání 3D pohledu myší, klávesnicí a dotykem.
 * Vstupy: žádné.
 * Výstup: `void` – napojí události myši/dotyku na 3D graf.
 */
function initializeHistorySurface(){
 const vSelect=$('lm-3d-series');if(!vSelect)return;
 for(const [key,label] of HISTORY_SERIES){if(key.startsWith('forecast'))continue;const oO=document.createElement('option');oO.value=key;oO.textContent=t(APP,label);vSelect.append(oO);}
 document.querySelectorAll('[data-history-view]').forEach(/**
 * Připojí událost změny 2D/3D zobrazení pro každý přepínač historie.
 * Vstupy: `vB` (mixed) – druhá hodnota z porovnávané nebo zobrazované dvojice.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ vB=>vB.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>setHistoryView(vB.dataset.historyView),{signal:oLifetime.signal}));
 vSelect.addEventListener('change',h3Generate,{signal:oLifetime.signal});
 for(const sId of ['lm-3d-low','lm-3d-high','lm-3d-gap','lm-3d-smooth'])$(sId).addEventListener('input',h3Schedule,{signal:oLifetime.signal});
 $('lm-3d-reset').addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{Object.assign(oH3,{yaw:-.7,pitch:.55,roll:0,zoom:1,panX:0,panY:0});h3Schedule();},{signal:oLifetime.signal});
 const oCanvas=$('lm-3d-canvas'),oPointers=new Map();let vPinch=null,bMoved=false;
 oCanvas.addEventListener('contextmenu',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>vE.preventDefault(),{signal:oLifetime.signal});
 oCanvas.addEventListener('wheel',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{vE.preventDefault();oH3.zoom=Math.max(.25,Math.min(6,oH3.zoom*Math.exp(-vE.deltaY*.001)));h3Schedule();},{passive:false,signal:oLifetime.signal});
 oCanvas.addEventListener('pointerdown',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{oCanvas.focus({preventScroll:true});oCanvas.setPointerCapture(vE.pointerId);oPointers.set(vE.pointerId,{x:vE.clientX,y:vE.clientY});bMoved=false;vPinch=null;},{signal:oLifetime.signal});
 oCanvas.addEventListener('pointermove',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{const vOld=oPointers.get(vE.pointerId);if(vOld){const vDirection=bHistory3dInvert?-1:1,nDx=vOld.x-vE.clientX,nDy=(vE.clientY-vOld.y)*vDirection;bMoved=bMoved||Math.abs(nDx)+Math.abs(nDy)>2;oPointers.set(vE.pointerId,{x:vE.clientX,y:vE.clientY});if(oPointers.size===2){const [a,b]=[...oPointers.values()],nDistance=Math.hypot(a.x-b.x,a.y-b.y);if(vPinch)oH3.zoom=Math.max(.25,Math.min(6,oH3.zoom*nDistance/vPinch));vPinch=nDistance;}else if(vE.buttons===2){oH3.panX+=nDx;oH3.panY+=nDy;}else if(vE.shiftKey){oH3.roll+=nDx*.01;}else{oH3.yaw+=nDx*.01;oH3.pitch=Math.max(-1.4,Math.min(1.4,oH3.pitch+nDy*.01));}h3Schedule();return;}
 const oRect=oCanvas.getBoundingClientRect(),nPx=vE.clientX-oRect.left,nPy=vE.clientY-oRect.top;
  /**
  * Ověří, zda vybraný bod leží uvnitř polygonu.
  * Vstupy: `aVertices` (array) – vstupní seznam hodnot.
  * Výstup: `boolean` – výsledek logického testu.
  */
 const inside=aVertices=>{let bYes=false;for(let nI=0,vJ=aVertices.length-1;nI<aVertices.length;vJ=nI++){const vA=aVertices[nI],vB=aVertices[vJ];if((vA.y>nPy)!==(vB.y>nPy)&&nPx<(vB.x-vA.x)*(nPy-vA.y)/(vB.y-vA.y)+vA.x)bYes=!bYes;}return bYes;};
 const vF=[...oH3.cells].reverse().find(/**
 * Vyhledá požadovanou položku v [...oH3.cells].reverse() podle vertices.
 * Vstupy: `vF` (mixed) – stěna 3D plochy s vrcholy a hloubkou.
 * Výstup: `boolean` – true při shodě vyhledávaného identifikátoru.
 */ vF=>inside(vF.vertices)),oHint=$('lm-3d-hint');if(!vF){oHint.textContent='';return;}
 oHint.textContent=vF.missing?t(APP,'Missing data'):`${formatHistoryTime(vF.b.ts,true)} · ${(vF.b.sum/vF.b.count).toFixed(2)} · ${t(APP,'Original bucket mean')}`;
 },{signal:oLifetime.signal});
  /**
 * Ukončí interakci pointeru nad 3D grafem a zruší režim přibližování gestem.
 * Vstupy: `vE` (mixed) – vstupní údaj z LINEA nebo rozhraní.
 * Výstup: `void` – odstraní pointer z aktivního seznamu a resetuje pinch stav.
 */
 const release=vE=>{oPointers.delete(vE.pointerId);vPinch=null;};oCanvas.addEventListener('pointerup',release,{signal:oLifetime.signal});oCanvas.addEventListener('pointercancel',release,{signal:oLifetime.signal});
 oCanvas.addEventListener('keydown',/**
 * Obslouží událost rozhraní.
 * Vstupy: `vE` (mixed).
 * Výstup: `void` – provede vedlejší účinek.
 */ vE=>{if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home'].includes(vE.key))return;vE.preventDefault();if(vE.key==='Home'){$('lm-3d-reset').click();return;}const nDx=vE.key==='ArrowLeft'?.08:vE.key==='ArrowRight'?-.08:0,nDy=(vE.key==='ArrowUp'?-.08:vE.key==='ArrowDown'?.08:0)*(bHistory3dInvert?-1:1);if(vE.shiftKey)oH3.roll+=nDx||nDy;else{oH3.yaw+=nDx;oH3.pitch=Math.max(-1.4,Math.min(1.4,oH3.pitch+nDy));}h3Schedule();},{signal:oLifetime.signal});
 window.addEventListener('resize',h3Schedule,{signal:oLifetime.signal});
}

/**
 * Vykreslí historické grafy, události a diagnostiku sběrače.
 * Vstupy: `oData` (object) – JSON odpověď ze služby historie/analýzy.
 * Výstup: `void` – aktualizuje historii, souhrny, grafy a události.
 */
function renderHistory(oData){
 nHistoryResolution=Number(oData?.resolutionSec)||300;
 const aPoints=oData?.points||[],vSummary=oData?.summary||{},vCollector=oData?.collector||{};
 setText('lm-h-pv-kwh',historyNum(vSummary.pvKWh));setText('lm-h-export-value',historyNum(vSummary.spotSaleValue,2));setText('lm-h-house-kwh',historyNum(vSummary.houseKWh));setText('lm-h-import-kwh',historyNum(vSummary.gridImportKWh));setText('lm-h-export-kwh',historyNum(vSummary.gridExportKWh));setText('lm-h-batt-charge-kwh',historyNum(vSummary.batteryChargeKWh));setText('lm-h-batt-discharge-kwh',historyNum(vSummary.batteryDischargeKWh));
 setText('lm-history-meta',`${aPoints.length} ${t(APP,'points')} · ${oData.resolutionSec||'—'} s`);setText('lm-history-resolution',(oData.resolutionSec||'—')+' s');setText('lm-history-count',String(vCollector.fiveMinuteBuckets??vCollector.rawSamples??'—'));setText('lm-history-last',vCollector.lastCollectedAt?new Date(Number(vCollector.lastCollectedAt)*1000).toLocaleString():'—');
 const vDb=vCollector.database||{};/**
 * Převede velikost databáze v bajtech na B/kB/MB/GB.
 * Vstupy: `vBytes` (mixed) – počet bajtů, případně chybějící hodnota.
 * Výstup: `string` – velikost databáze v B/kB/MB/GB nebo pomlčka.
 */
const formatBytes=(vBytes)=>{if(!isValue(vBytes))return'—';let nN=Number(vBytes);if(nN<1024)return nN+' B';if(nN<1024*1024)return (nN/1024).toFixed(1)+' kB';if(nN<1024*1024*1024)return (nN/1024/1024).toFixed(1)+' MB';return (nN/1024/1024/1024).toFixed(2)+' GB';};setText('lm-history-db-size',vDb.available===false?'—':formatBytes(vDb.bytes));setText('lm-history-db-size-checked',vDb.checkedAt?new Date(Number(vDb.checkedAt)*1000).toLocaleString():'—');
 const vFast=vCollector.fastCollector||{};setText('lm-history-fast-state',!vFast.online?t(APP,'OFFLINE'):vFast.writeProblem?t(APP,'ONLINE, DB write missing'):t(APP,'ONLINE'));setText('lm-history-buffer',`${vFast.bufferCount??'—'} / 3600`);setText('lm-history-polls',`${vFast.pollOk??'—'} / ${vFast.pollErrors??'—'}`);
 const vSt=$('lm-history-state');if(vSt){const bActive=vCollector.enabled&&vFast.online&&!vFast.writeProblem;vSt.textContent=!vCollector.enabled?t(APP,'Disabled'):vFast.writeProblem?t(APP,'DB write problem'):vFast.online?t(APP,'Active'):t(APP,'Collector offline');vSt.className='lm-pill '+(bActive?'lm-history-on':vFast.writeProblem?'lm-history-bad':'');}
 const oErr=$('lm-history-error');if(oErr&&vCollector.enabled&&(vFast.lastError||vFast.writeProblem)){oErr.textContent=vFast.lastError?(t(APP,'Fast collector:')+' '+vFast.lastError):t(APP,'Collector is polling LINEA, but no 5-minute aggregate has been written recently. Restart the collector and inspect its log.');oErr.hidden=false;}
 vHistoryChartStatus=vLatestStatus;vHistoryChartNow=Date.now()/1000;
 aHistoryPoints=aPoints;if(activeTab()==='history'){if(sHistoryView==='3d')h3Generate();else renderPowerChart(aPoints);}renderHistoryEvents(oData?.events||[]);
}
/**
 * Načte vybraný rozsah historie ze serveru a aktualizuje stránku.
 * Vstupy: `bForce` (boolean) – zda vynutit nové načtení namísto cache.
 * Výstup: `Promise<void>` – načte data historie včetně ochrany proti souběžným změnám období.
 */
async function loadHistory(bForce=false){if(bHistoryLoading)return;if(bHistoryLoaded&&!bForce)return;bHistoryLoading=true;const oErr=$('lm-history-error');try{if(!vLatestStatus){try{const vSr=await api('/api/linea/status');vLatestStatus=vSr.data;}catch(_){}}const vRequestedRange=sHistoryRange;const vR=await api('/api/history?range='+encodeURIComponent(vRequestedRange));if(vRequestedRange!==sHistoryRange){bHistoryLoading=false;return loadHistory(true);}vHistoryBounds=['today','yesterday','3m','this-half-year','this-year','6m','12m','2y','all'].includes(vRequestedRange)?{from:Number(vR.from),to:Number(vR.to)}:null;renderHistory(vR.data||{});if(oErr)oErr.hidden=true;bHistoryLoaded=true;}catch(oE){if(oErr){oErr.textContent=oE.message;oErr.hidden=false;}}finally{bHistoryLoading=false;}}

let vAnalysisData=null,bAnalysisLoading=false,bAnalysisCanConfigure=false,bAnalysisProfileLoaded=false,sAnalysisPriceUnit='MWh';
/**
 * Zformátuje měnovou částku nebo nedostupnost.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `string` – formátovaná částka v korunách nebo pomlčka.
 */
const money=vV=>isValue(vV)?Number(vV).toLocaleString([],{minimumFractionDigits:2,maximumFractionDigits:2})+' Kč':'—';
/**
 * Zformátuje energii v kWh nebo nedostupnost.
 * Vstupy: `vV` (mixed) – hodnota měření s možným null.
 * Výstup: `string` – formátovaná energie v kWh nebo pomlčka.
 */
const energy=vV=>isValue(vV)?Number(vV).toLocaleString([],{minimumFractionDigits:2,maximumFractionDigits:2})+' kWh':'—';
/**
 * Převede minuty dne na čas HH:MM.
 * Vstupy: `nN` (number) – číselný parametr výpočtu.
 * Výstup: `string` – čas ve formátu HH:MM.
 */
const minuteLabel=nN=>String(Math.floor(nN/60)).padStart(2,'0')+':'+String(nN%60).padStart(2,'0');
/**
 * Převede rozpis HDO do editovatelného textového formátu.
 * Vstupy: `vSchedules` (mixed) – časové rozpisy HDO.
 * Výstup: `string` – řádky HDO s daty a intervaly ve formátu importního editoru.
 */
function scheduleText(vSchedules){return (vSchedules||[]).map(/**
 * Převede položku kolekce (vSchedules||[]) na odvozenou hodnotu z polí from, to, ntWindows.
 * Vstupy: `vSchedule` (mixed) – aktuální záznam příslušného datového vstupu.
 * Výstup: `string` – text jednoho období HDO včetně oken nízkého tarifu
 */ vSchedule=>`${vSchedule.from};${vSchedule.to};${(vSchedule.ntWindows||[]).map(/**
 * Převede položku kolekce (vSchedule.ntWindows||[]) na odvozenou hodnotu.
 * Vstupy: `[a,b]` (array) – položky rozloženého pole.
 * Výstup: `string` – časové okno HDO ve formátu HH:MM-HH:MM
 */ ([a,b])=>minuteLabel(a)+'-'+minuteLabel(b)).join(',')}`).join('\n');}
/**
 * Ověří a rozloží textové intervaly HDO.
 * Vstupy: `vRaw` (mixed) – text zadaného rozpisu HDO.
 * Výstup: `array` – validované časové rozpisy HDO, jinak výjimka.
 */
function parseScheduleText(vRaw){
 const aLines=String(vRaw||'').split(/\r?\n/).map(/**
 * Převede položku kolekce String(vRaw||'').split(/\r?\n/) na odvozenou hodnotu z polí trim.
 * Vstupy: `sS` (string) – textový řádek ke zpracování nebo oddělená hodnota.
 * Výstup: `string` – řádek vstupního souboru bez okrajových mezer
 */ sS=>sS.trim()).filter(Boolean);
 return aLines.map(/**
 * Převede položku kolekce aLines na datový záznam z polí split, map.
 * Vstupy: `vLine` (mixed) – jeden řádek textového záznamu HDO.
 * Výstup: `object` – záznam HDO se začátkem, koncem a intervaly NT
 */ vLine=>{
  const aParts=vLine.split(';').map(/**
 * Převede položku kolekce vLine.split(';') na odvozenou hodnotu z polí trim.
 * Vstupy: `sS` (string) – textový řádek ke zpracování nebo oddělená hodnota.
 * Výstup: `string` – očištěné textové pole oddělené středníkem
 */ sS=>sS.trim());
  if(aParts.length!==3||!aParts[2])throw new Error(t(APP,'Invalid HDO line:')+' '+vLine);
  const aNtWindows=aParts[2].split(',').map(/**
 * Převede položku kolekce aParts[2].split(',') na datový záznam z polí exec, trim.
 * Vstupy: `sWindow` (object) – textový úsek HDO ve tvaru HH:MM-HH:MM.
 * Výstup: `array` – číselná dvojice začátku a konce intervalu HDO
 */ sWindow=>{
   const vMatch=/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/.exec(sWindow.trim());
   if(!vMatch)throw new Error(t(APP,'Invalid HDO window:')+' '+sWindow);
   return [Number(vMatch[1])*60+Number(vMatch[2]),Number(vMatch[3])*60+Number(vMatch[4])];
  });
  return {from:aParts[0],to:aParts[1],ntWindows: aNtWindows};
 });
}
const ANALYSIS_FIELDS=['ean','distributor','meterType','distributionTariff','mainBreaker','usage','hdoSchedules'];
/**
 * Přečte editované řádky smluv z formuláře.
 * Vstupy: žádné.
 * Výstup: `array` – řádky smluv a dodavatelů pro formulář analýzy.
 */
function contractRows(){
 return [...$('lm-analysis-contract-rows').querySelectorAll('.lm-analysis-entry')].map(/**
 * Převede položku kolekce [...$('lm-analysis-contract-rows').querySelec na datový záznam.
 * Vstupy: `oRow` (object) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `object` – smlouva vytvořená z formulářového řádku
 */ oRow=>{
    /**
   * Vytvoří text číselné hodnoty s jednotkou.
   * Vstupy: `sName` (string) – název formulářového pole.
   * Výstup: `string` – formátované měření s jednotkou, jinak pomlčka.
   */
  const value=sName=>oRow.querySelector(`[name="${sName}"]`).value.trim();
  return {start:value('start'),durationMonths:Number(value('durationMonths')),supplier:value('supplier'),product:value('product'),sourceUrl:value('sourceUrl')};
 });
}
/**
 * Přečte editované řádky cenových období z formuláře.
 * Vstupy: žádné.
 * Výstup: `array` – časové úseky cenových podmínek.
 */
function priceRows(){
 return [...$('lm-analysis-price-rows').querySelectorAll('.lm-analysis-entry')].map(/**
 * Převede položku kolekce [...$('lm-analysis-price-rows').querySelector na datový záznam.
 * Vstupy: `oRow` (object) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `object` – cenové období vytvořené z formulářového řádku
 */ oRow=>{
    /**
   * Vytvoří text číselné hodnoty s jednotkou.
   * Vstupy: `sName` (string) – název formulářového pole.
   * Výstup: `string` – formátované měření s jednotkou, jinak pomlčka.
   */
  const value=sName=>oRow.querySelector(`[name="${sName}"]`).value.trim();
  return {from:value('from'),to:oRow.dataset.to||'',contractStart:value('contractStart'),vtCzkKWh:analysisRateToStored(value('vtCzkKWh')),
   ntCzkKWh:analysisRateToStored(value('ntCzkKWh')),fixedCzkMonth:value('fixedCzkMonth'),greenCzkKWh:analysisRateToStored(value('greenCzkKWh')),
   greenEnabled:oRow.querySelector('[name="greenEnabled"]').checked,sourceUrl:value('sourceUrl')};
 });
}
/**
 * Převede interní jednotku ceny na jednotku formuláře.
 * Vstupy: `vValue` (mixed) – hodnota měření, která může být nedostupná.
 * Výstup: `string|number` – cenová sazba upravená pro editor.
 */
function analysisRateToInput(vValue){
 if(vValue===''||vValue===null||vValue===undefined)return '';
 const nN=Number(vValue);
 return sAnalysisPriceUnit==='MWh'?(Math.round(nN*100000)/100).toFixed(2):String(nN);
}
/**
 * Převede zvolenou jednotku ceny do formátu pro ukládání.
 * Vstupy: `vValue` (mixed) – hodnota měření, která může být nedostupná.
 * Výstup: `number|null` – sazba převedená pro uložení do profilu.
 */
function analysisRateToStored(vValue){
 if(vValue==='')return '';
 const nN=Number(vValue);
 return sAnalysisPriceUnit==='MWh'?Number((nN/1000).toFixed(5)):nN;
}
/**
 * Sestaví popsaný a bezpečně escapovaný formulářový prvek.
 * Vstupy: `sName` (string) – název formulářového pole; `sLabel` (string) – viditelný popisek; `sValue` (string) – řetězcová hodnota formulářového pole; `sType` (string) – typ formulářového vstupu; `sExtra` (string) – volitelné HTML atributy formuláře; `sSuffix` (string) – textová jednotka za názvem položky.
 * Výstup: `string` – HTML formulářový input s atributy a popiskem.
 */
const analysisField=(sName,sLabel,sValue='',sType='text',sExtra='',sSuffix='')=>`<label>${esc(t(APP,sLabel))}${sSuffix?' ('+esc(sSuffix)+')':''}<input name="${sName}" type="${sType}" value="${esc(sValue)}" ${sExtra}></label>`;
/**
 * Vykreslí řádky historie smluv.
 * Vstupy: `aRows` (array) – seznam řádků smluv, tarifů nebo výkazů.
 * Výstup: `void` – překreslí řádky smluv v editoru.
 */
function renderContractRows(aRows){
 const oHost=$('lm-analysis-contract-rows');
 oHost.innerHTML=aRows.map(/**
 * Převede položku kolekce aRows na HTML záznam pro UI.
 * Vstupy: `vC` (mixed) – záznam smlouvy, sazby nebo nastavení; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `string` – HTML formulář pro jednu smlouvu
 */ (vC,nI)=>`<div class="lm-analysis-entry" data-index="${nI}"><div class="lm-analysis-entry-head"><strong>${esc(t(APP,'Contract'))} ${nI+1}</strong><button type="button" data-remove-contract="${nI}" ${bAnalysisCanConfigure?'':'hidden'}>${esc(t(APP,'Remove'))}</button></div><div class="lm-analysis-entry-grid">
 ${analysisField('start','Supply start date',vC.start||'','date')}
 ${analysisField('durationMonths','Contract duration in months',vC.durationMonths??36,'number','min="1" max="120"')}
 ${analysisField('supplier','Supplier',vC.supplier||'')}
 ${analysisField('product','Product',vC.product||'')}
 ${analysisField('sourceUrl','Contract source URL',vC.sourceUrl||'','url')}
 <small>${esc(t(APP,'Contract end:'))} ${esc(vC.to||t(APP,'calculated on save'))}</small></div></div>`).join('');
 for(const oInput of oHost.querySelectorAll('input'))oInput.disabled=!bAnalysisCanConfigure;
}
/**
 * Vykreslí řádky tarifních a cenových období.
 * Vstupy: `aRows` (array) – seznam řádků smluv, tarifů nebo výkazů.
 * Výstup: `void` – překreslí řádky cenových období v editoru.
 */
function renderPriceRows(aRows){
 const oHost=$('lm-analysis-price-rows'),aContracts=contractRows().filter(/**
 * Vybere z kolekce contractRows() jen položky splňující podmínku nad start.
 * Vstupy: `vC` (mixed) – záznam smlouvy, sazby nebo nastavení.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vC=>vC.start);
 const sUnit=sAnalysisPriceUnit==='MWh'?'Kč/MWh':'Kč/kWh',vStep=sAnalysisPriceUnit==='MWh'?'0.01':'0.00001';
 oHost.innerHTML=aRows.map(/**
 * Převede položku kolekce aRows na datový záznam z polí contractStart, from, length.
 * Vstupy: `vP` (mixed) – historické časově označené měření; `nI` (number) – pořadové číslo/index právě zpracovávané položky.
 * Výstup: `string` – HTML formulář jednoho cenového období
 */ (vP,nI)=>{
  const bSelected=vP.contractStart||(!vP.from&&aContracts.length===1?aContracts[0].start:'');
  const vOptions=`<option value="">${esc(t(APP,'No linked contract'))}</option>`+aContracts.map(/**
 * Převede položku kolekce aContracts na odvozenou hodnotu z polí start, product.
 * Vstupy: `vC` (mixed) – záznam smlouvy, sazby nebo nastavení.
 * Výstup: `string` – HTML volba smlouvy pro přiřazení k ceníku
 */ vC=>`<option value="${esc(vC.start)}" ${bSelected===vC.start?'selected':''}>${esc(vC.start+' · '+vC.product)}</option>`).join('');
  return `<div class="lm-analysis-entry" data-index="${nI}" data-to="${esc(vP.to||'')}"><div class="lm-analysis-entry-head"><strong>${esc(t(APP,'Price list'))} ${nI+1}</strong><button type="button" data-remove-price="${nI}" ${bAnalysisCanConfigure?'':'hidden'}>${esc(t(APP,'Remove'))}</button></div><div class="lm-analysis-entry-grid">
  <label>${esc(t(APP,'Contract'))}<select name="contractStart">${vOptions}</select></label>
  ${analysisField('from','Price change date (blank = contract start)',vP.from||'','date')}
  ${analysisField('vtCzkKWh','Total high tariff incl. VAT',analysisRateToInput(vP.vtCzkKWh),'number',`min="0" step="${vStep}"`,sUnit)}
  ${analysisField('ntCzkKWh','Total low tariff incl. VAT',analysisRateToInput(vP.ntCzkKWh),'number',`min="0" step="${vStep}"`,sUnit)}
  ${analysisField('fixedCzkMonth','Total fixed CZK/month incl. VAT',vP.fixedCzkMonth??'','number','min="0" step="0.01"')}
  ${analysisField('greenCzkKWh','Optional green fee incl. VAT',analysisRateToInput(vP.greenCzkKWh??0),'number',`min="0" step="${vStep}"`,sUnit)}
  <label class="lm-analysis-checkbox"><input name="greenEnabled" type="checkbox" ${vP.greenEnabled?'checked':''}> ${esc(t(APP,'Green electricity is contracted'))}</label>
  ${analysisField('sourceUrl','Price list source URL',vP.sourceUrl||'','url')}
  <small>${esc(t(APP,'Price valid through:'))} ${esc(vP.to||t(APP,'end of the start year'))}</small></div></div>`;
 }).join('');
 for(const oInput of oHost.querySelectorAll('input,select'))oInput.disabled=!bAnalysisCanConfigure;
}
/**
 * Vyplní analytický formulář uloženými údaji.
 * Vstupy: `oProfile` (object) – uložený profil odběrného místa a smluv.
 * Výstup: `void` – předvyplní editor uloženými hodnotami.
 */
function fillAnalysisProfile(oProfile){
 const oForm=$('lm-analysis-profile-form');if(!oForm)return;
 $('lm-analysis-price-unit').value=sAnalysisPriceUnit;
 for(const sName of ANALYSIS_FIELDS){
  const oInput=oForm.elements.namedItem(sName);if(!oInput)continue;
  oInput.value=sName==='hdoSchedules'?scheduleText(oProfile.hdoSchedules):oProfile[sName]??'';
  oInput.disabled=!bAnalysisCanConfigure;
 }
 const aContracts=Array.isArray(oProfile.contracts)?oProfile.contracts:
  (oProfile.supplier||oProfile.product||oProfile.contractStart?[{start:oProfile.contractStart||'',durationMonths:oProfile.durationMonths||36,supplier:oProfile.supplier||'',product:oProfile.product||'',sourceUrl:''}]:[]);
 const aPeriods=Array.isArray(oProfile.pricePeriods)&&oProfile.pricePeriods.length?oProfile.pricePeriods:
  (oProfile.priceValidFrom?[{from:oProfile.priceValidFrom,to:oProfile.priceValidTo||'',vtCzkKWh:oProfile.vtCzkKWh,
   ntCzkKWh:oProfile.ntCzkKWh,fixedCzkMonth:oProfile.fixedCzkMonth,greenCzkKWh:oProfile.greenCzkKWh||0,
   greenEnabled:oProfile.greenEnabled===true,sourceUrl:oProfile.sourceUrl||''}]:[]);
 renderContractRows(aContracts);
 renderPriceRows(aPeriods);
 for(const oButton of ['lm-analysis-add-contract','lm-analysis-add-price'])$(oButton).hidden=!bAnalysisCanConfigure;
 $('lm-analysis-profile-file').disabled=!bAnalysisCanConfigure;
 oForm.querySelector('button[type="submit"]').hidden=!bAnalysisCanConfigure;
 $('lm-analysis-dropzone').hidden=!bAnalysisCanConfigure;
 $('lm-analysis-file').disabled=!bAnalysisCanConfigure;
 bAnalysisProfileLoaded=true;
}
/**
 * Zobrazí již uložený profil odběrného místa.
 * Vstupy: `oProfile` (object) – uložený profil odběrného místa a smluv.
 * Výstup: `void` – zobrazí současný profil odběrného místa a ceníků.
 */
function renderStoredAnalysisProfile(oProfile){
 const aContracts=Array.isArray(oProfile.contracts)?oProfile.contracts:(oProfile.contractStart?[oProfile]:[]);
 const aPrices=Array.isArray(oProfile.pricePeriods)?oProfile.pricePeriods:[];
  /**
 * Převede energetickou hodnotu v MWh na kWh a připraví formátování pro český přehled.
 * Vstupy: `vValue` (mixed) – hodnota měření, která může být nedostupná.
 * Výstup: `string` – lokalizovaně formátované číslo v kWh.
 */
 const mwh=vValue=>(Number(vValue)*1000).toLocaleString([],{minimumFractionDigits:2,maximumFractionDigits:2});
 const vRanges=aPrices.map(/**
 * Převede položku kolekce aPrices na odvozenou hodnotu z polí from, to, vtCzkKWh.
 * Vstupy: `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce.
 * Výstup: `string` – textový popis uloženého cenového období
 */ vP=>vP.from+'–'+vP.to+' (VT/NT '+mwh(vP.vtCzkKWh)+' / '+mwh(vP.ntCzkKWh)+' Kč/MWh; '+money(vP.fixedCzkMonth)+' '+t(APP,'monthly')+')').join(', ')||t(APP,'none');
 const vHdo=(oProfile.hdoSchedules||[]).map(/**
 * Převede položku kolekce (oProfile.hdoSchedules||[]) na odvozenou hodnotu z polí from, to, ntWindows.
 * Vstupy: `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce.
 * Výstup: `string` – textový popis uloženého rozvrhu HDO
 */ vP=>vP.from+'–'+vP.to+' (NT: '+(vP.ntWindows||[]).length+')').join(', ')||t(APP,'none');
 setText('lm-analysis-stored-profile',t(APP,'Stored in GridSight:')+' '+t(APP,'Contracts')+' '+aContracts.length+' · '+t(APP,'Price periods')+' '+vRanges);
 setText('lm-analysis-stored-hdo','HDO: '+vHdo);
}
/**
 * Přečte a normalizuje nastavení analýzy z formuláře.
 * Vstupy: žádné.
 * Výstup: `object` – validovaná struktura hodnot z aktuálních formulářů.
 */
function readAnalysisProfile(){
 const oForm=$('lm-analysis-profile-form'),oP={};
 for(const sName of ANALYSIS_FIELDS){
  const oInput=oForm.elements.namedItem(sName);
  oP[sName]=sName==='hdoSchedules'?parseScheduleText(oInput.value):oInput.value.trim();
 }
 oP.contracts=contractRows();oP.pricePeriods=priceRows();
 return oP;
}
/**
 * Zobrazí čtvrthodinové a denní detaily vybraného reportu.
 * Vstupy: žádné.
 * Výstup: `void` – vykreslí detail výpočtu pro jednotlivé čtvrthodiny.
 */
function renderAnalysisDay(){
 const oData=vAnalysisData?.month,bSelected=$('lm-analysis-day')?.value,oHost=$('lm-analysis-intervals');if(!oHost)return;
 if(!oData||!bSelected){oHost.innerHTML='<div class="lm-empty">—</div>';return;}
 const aRows=oData.intervals.filter(/**
 * Vybere z kolekce oData.intervals jen položky splňující podmínku nad day.
 * Vstupy: `oRow` (object) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ oRow=>oRow.day===bSelected);
 const oSource={schedule:t(APP,'HDO schedule'),shelly:t(APP,'Shelly history'),snapshot:t(APP,'Shelly diagnostic snapshot')};
 oHost.innerHTML=`<table class="lm-analysis-table"><thead><tr><th>${esc(t(APP,'Time'))}</th><th>${esc(t(APP,'Tariff'))}</th><th>${esc(t(APP,'Grid purchase'))}</th><th>${esc(t(APP,'Grid export'))}</th><th>${esc(t(APP,'Consumption'))} · LINEA</th><th>${esc(t(APP,'PV production'))} · LINEA</th><th title="${esc(t(APP,'LINEA data coverage'))}">LINEA %</th><th>${esc(t(APP,'Variable cost'))}</th><th>${esc(t(APP,'House without PV and battery'))}</th></tr></thead><tbody>${aRows.map(/**
 * Převede položku kolekce aRows na odvozenou hodnotu z polí localTime, slice, tariffSource.
 * Vstupy: `oRow` (object) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `string` – HTML řádek čtvrthodinového vyúčtování
 */ oRow=>`<tr><td>${esc(oRow.localTime.slice(11))}</td><td title="${esc(oSource[oRow.tariffSource]||t(APP,'Unknown HDO state'))}">${esc(oRow.tariff||'—')}</td><td>${energy(oRow.importKWh)}</td><td>${energy(oRow.exportKWh)}</td><td>${energy(oRow.houseKWh)}</td><td>${energy(oRow.pvKWh)}</td><td>${Number(oRow.lineaCoveragePct).toFixed(1)} %</td><td>${money(oRow.variableCzk)}</td><td>${money(oRow.hypotheticalVariableCzk)}</td></tr>`).join('')}</tbody></table>`;
}
/**
 * Vykreslí finanční analýzu, tarify a souhrn importů.
 * Vstupy: žádné.
 * Výstup: `void` – vykreslí souhrn cen, tarifů, měření a rozdílů EG.D.
 */
function renderAnalysis(){
 const oData=vAnalysisData||{},vMonth=oData.month,vSummary=vMonth?.summary;
 const vMonthPicker=$('lm-analysis-month');
 vMonthPicker.innerHTML=(oData.reports||[]).map(/**
 * Převede položku kolekce (oData.reports||[]) na odvozenou hodnotu z polí period, selectedPeriod, importKWh.
 * Vstupy: `vR` (mixed) – nahraný report distributora.
 * Výstup: `string` – HTML volba měsíčního reportu distributora
 */ vR=>`<option value="${esc(vR.period)}" ${vR.period===oData.selectedPeriod?'selected':''}>${esc(vR.period)} · ${energy(vR.importKWh)}</option>`).join('');
 if(!bAnalysisProfileLoaded)fillAnalysisProfile(oData.profile||{});
 const vLiveHdo=oData.shellyStatus||{},vPolarity=vLiveHdo.onMeansNt;
 setText('lm-analysis-shelly-status',t(APP,'Shelly Noční proud:')+' '+(vPolarity===true?'ON = NT · OFF = VT':vPolarity===false?'OFF = NT · ON = VT':t(APP,'polarity is waiting for one complete 20/4-hour day'))+' · '+t(APP,'verified days:')+' '+(vLiveHdo.days||0));
 renderStoredAnalysisProfile(oData.profile||{});
 setText('lm-report-detail-title',t(APP,'ED.G report')+' '+(oData.selectedPeriod||'—'));
 const vImports=$('lm-analysis-imports');
 vImports.innerHTML=(oData.reports||[]).length?`<div class="lm-analysis-table-wrap"><table class="lm-analysis-table"><thead><tr><th>${esc(t(APP,'Selection'))}</th><th>${esc(t(APP,'Month'))}</th><th>EAN</th><th>${esc(t(APP,'Grid import'))}</th><th>${esc(t(APP,'Grid export'))}</th><th>${esc(t(APP,'Intervals'))}</th><th>${esc(t(APP,'Imported'))}</th><th></th></tr></thead><tbody>${oData.reports.map(/**
 * Převede položku kolekce oData.reports na odvozenou hodnotu z polí period, peri.
 * Vstupy: `vR` (mixed) – nahraný report distributora.
 * Výstup: `string` – HTML řádek reportu se zaškrtávacím polem
 */ vR=>`<tr><td><input type="checkbox" data-report-select value="${esc(vR.period)}" aria-label="${esc(vR.period)}"></td><td>${esc(vR.period)} <button type="button" data-report-open="${esc(vR.period)}">${esc(t(APP,'Open detail'))}</button></td><td>${esc(vR.ean)}</td><td>${energy(vR.importKWh)}</td><td>${energy(vR.exportKWh)}</td><td>${vR.rows}</td><td>${esc(new Date(vR.importedAt*1000).toLocaleString())}</td><td>${bAnalysisCanConfigure?`<button type="button" data-report-delete="${esc(vR.period)}">${esc(t(APP,'Remove'))}</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:esc(t(APP,'No reports stored yet.'));
 const vSales=$('lm-sale-list');
 const vSaleForm=$('lm-sale-form');if(vSaleForm)for(const oInput of vSaleForm.querySelectorAll('input,button'))oInput.disabled=!bAnalysisCanConfigure;
 if(vSales)vSales.innerHTML=`<div class="lm-analysis-table-wrap"><table class="lm-analysis-table"><thead><tr><th>${esc(t(APP,'Period'))}</th><th>${esc(t(APP,'Document'))}</th><th>${esc(t(APP,'Actual payment'))}</th><th>${esc(t(APP,'Gross SPOT'))}</th><th>${esc(t(APP,'Difference'))}</th><th>${esc(t(APP,'Coverage'))}</th><th>${esc(t(APP,'ED.G export'))}</th><th></th></tr></thead><tbody>${(oData.statements||[]).map(/**
 * Převede položku kolekce (oData.statements||[]) na odvozenou hodnotu z polí from, to, document.
 * Vstupy: `vR` (mixed) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `string` – HTML řádek uloženého vyúčtování Delta Green
 */ vR=>`<tr><td>${esc(vR.from)} – ${esc(vR.to)}</td><td>${esc(vR.document)}<br>${esc(vR.saleEan)}</td><td>${money(vR.amountCzk)}</td><td>${vR.spotCzk===null?'—':money(vR.spotCzk)}</td><td>${vR.differenceCzk===null?'—':money(vR.differenceCzk)}</td><td>${vR.coveragePct}%</td><td>${vR.distributorExportKWh===null?'—':energy(vR.distributorExportKWh)}</td><td>${bAnalysisCanConfigure?`<button type="button" data-sale-edit="${esc(vR.id)}">${esc(t(APP,'Edit'))}</button><button type="button" data-sale-delete="${esc(vR.id)}">${esc(t(APP,'Remove'))}</button>`:''}</td></tr>`).join('')}</tbody></table></div>`;

 const oHost=$('lm-analysis-summary'),vDailyHost=$('lm-analysis-days'),vDayPicker=$('lm-analysis-day');
 if(!vMonth){oHost.innerHTML='<div class="lm-card lm-empty">'+esc(t(APP,'Import the first ED.G monthly report to see measurements.'))+'</div>';vDailyHost.innerHTML='—';vDayPicker.innerHTML='';renderAnalysisDay();return;}
  /**
 * Sestaví bezpečný HTML fragment souhrnné karty finanční analýzy.
 * Vstupy: `sLabel` (string) – viditelný popisek; `vV` (mixed) – hodnota měření s možným null; `sSmall` (string) – textový identifikátor nebo popisek.
 * Výstup: `string` – karta s popiskem, hodnotou a volitelnou poznámkou.
 */
 const card=(sLabel,vV,sSmall='')=>`<article class="lm-card"><small>${esc(sLabel)}</small><strong>${esc(vV)}</strong>${sSmall?`<p>${esc(sSmall)}</p>`:''}</article>`;
 const aNotes=[];
 if(!vSummary.priceValid)aNotes.push(t(APP,'No valid price list covers this full month.')+' '+t(APP,'Saved price periods:')+' '+((oData.profile?.pricePeriods||[]).map(/**
 * Převede položku kolekce (oData.profile?.pricePeriods||[]) na odvozenou hodnotu z polí from, to.
 * Vstupy: `vP` (mixed) – datový řádek z aktuálně zpracovávané kolekce.
 * Výstup: `string` – textový interval platnosti ceníku
 */ vP=>vP.from+'–'+vP.to).join(', ')||t(APP,'none'))+'.');
 if((vSummary.incompleteSchedules||[]).length)aNotes.push(t(APP,'D57d HDO schedule does not add up to 20 hours of NT; it was ignored:')+' '+vSummary.incompleteSchedules.join(', ')+'.');
 if(!vSummary.tariffComplete && !vSummary.partialPrice?.complete)aNotes.push(t(APP,'HDO is missing for some intervals; check the HDO schedule or the Shelly recording.'));
 const vShelly=vSummary.shellyCalibration||{},vSources=vSummary.tariffSources||{};
 if((vSources.shelly||0)+(vSources.snapshot||0)>0)aNotes.push(t(APP,'Shelly tariff mapping:')+' '+(vShelly.onMeansNt===true?'ON = NT':vShelly.onMeansNt===false?'OFF = NT':'—')+' ('+vShelly.days+' '+t(APP,'complete days')+').');
 else if((vSources.unknown||0)>0 && vShelly.onMeansNt===null)aNotes.push(t(APP,'The Shelly polarity is not confirmed by a complete 20/4-hour day. Earlier missing recordings cannot be reconstructed.'));
 if(vSummary.lineaCoveragePct<99.5)aNotes.push(t(APP,'LINEA house history is incomplete; the no-PV comparison is unavailable.'));
 if(!oData.profile?.contractStart)aNotes.push(t(APP,'Supply start date has not been confirmed.'));
 const vPartial=vSummary.partialPrice,vRange=vPartial?vPartial.from+'–'+vPartial.to+' · '+vPartial.days+' '+t(APP,'priced days'):'';
 const vPartialCards=vPartial?card(t(APP,'Partial variable cost'),money(vPartial.variableCzk),vRange)+card(t(APP,'Partial fixed cost'),money(vPartial.fixedCzk),vRange)+card(t(APP,'Partial estimated purchase'),money(vPartial.estimatedTotalCzk),vRange):'';
 oHost.innerHTML=`<div class="lm-analysis-cards">${card(t(APP,'Distributor grid purchase'),energy(vSummary.importKWh))}${card(t(APP,'Distributor grid export'),energy(vSummary.exportKWh))}${card(t(APP,'High / low tariff'),`${energy(vSummary.vtKWh)} / ${energy(vSummary.ntKWh)}`,vSummary.unknownKWh>0?t(APP,'Unassigned:')+' '+energy(vSummary.unknownKWh):'')}${card(t(APP,'Variable purchase cost'),money(vSummary.variableCzk))}${card(t(APP,'Fixed monthly cost'),money(vSummary.fixedCzk))}${card(t(APP,'Estimated total purchase'),money(vSummary.estimatedTotalCzk))}${vPartialCards}${card(t(APP,'House without PV and battery'),money(vSummary.hypotheticalVariableCzk),t(APP,'Hypothetical variable electricity cost at the same times.'))}${card(t(APP,'Difference in variable cost'),money(vSummary.differenceCzk),t(APP,'PV and battery combined; grid battery charging can affect this value.'))}${card(t(APP,'LINEA data coverage'),vSummary.lineaCoveragePct.toFixed(2)+' %',t(APP,'Covered house load:')+' '+energy(vSummary.houseCoveredKWh)+' · '+t(APP,'PV production:')+' '+energy(vSummary.pvCoveredKWh))}</div>${aNotes.length?`<p class="lm-analysis-warning">${aNotes.map(esc).join(' ')}</p>`:''}`;
 vDailyHost.innerHTML=`<div class="lm-analysis-table-wrap"><table class="lm-analysis-table"><thead><tr><th>${esc(t(APP,'Day'))}</th><th>${esc(t(APP,'Grid purchase'))}</th><th>${esc(t(APP,'Grid export'))}</th><th>VT</th><th>NT</th><th>${esc(t(APP,'Variable cost'))}</th></tr></thead><tbody>${vMonth.daily.map(/**
 * Převede položku kolekce vMonth.daily na odvozenou hodnotu z polí day, importKWh, exportKWh.
 * Vstupy: `oRow` (object) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `string` – HTML řádek denní bilance elektřiny
 */ oRow=>`<tr><td>${esc(oRow.day)}</td><td>${energy(oRow.importKWh)}</td><td>${energy(oRow.exportKWh)}</td><td>${energy(oRow.vtKWh)}</td><td>${energy(oRow.ntKWh)}</td><td>${oRow.unknownIntervals>0||!oRow.priceAvailable?'—':money(oRow.variableCzk)}</td></tr>`).join('')}</tbody></table></div>`;
 const vChosen=vDayPicker.value;
 vDayPicker.innerHTML=vMonth.daily.map(/**
 * Převede položku kolekce vMonth.daily na odvozenou hodnotu z polí day.
 * Vstupy: `oRow` (object) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `string` – HTML volba dne pro detailní přehled
 */ oRow=>`<option value="${esc(oRow.day)}" ${vChosen===oRow.day?'selected':''}>${esc(oRow.day)}</option>`).join('');
 renderAnalysisDay();
}
/**
 * Načte podklady měsíční analýzy a aktualizuje formuláře.
 * Vstupy: `sPeriod` (string) – měsíc YYYY-MM zvolený pro EG.D analýzu.
 * Výstup: `Promise<void>` – načte výkaz a přepočítá zobrazení měsíční analýzy.
 */
async function loadAnalysis(sPeriod=''){
 if(bAnalysisLoading||bDisposed)return;
 bAnalysisLoading=true;const aErrors=[$('lm-analysis-error'),$('lm-analysis-data-error')];
 try{const vR=await api('/api/analysis'+(sPeriod?'?period='+encodeURIComponent(sPeriod):''));vAnalysisData=vR.data;for(const oErr of aErrors)if(oErr)oErr.hidden=true;renderAnalysis();}
 catch(oE){for(const oErr of aErrors)if(oErr){oErr.textContent=oE.message;oErr.hidden=false;}}
 finally{bAnalysisLoading=false;}
}
/**
 * Zaregistruje obsluhu importu, exportu a správy analytických údajů.
 * Vstupy: žádné.
 * Výstup: `void` – připojí události pro editaci, import a export výkazů.
 */
function bindAnalysis(){
  /**
 * Zobrazí vybranou část výsledků energetické analýzy a skryje ostatní části.
 * Vstupy: `sKey` (string) – technický klíč veličiny či pole.
 * Výstup: `void` – přepne aktivní část rozhraní.
 */
 const showPane=sKey=>{
  document.querySelectorAll('[data-analysis-view]').forEach(/**
 * Projde položky kolekce document.querySelectorAll('[data-analysis-vie a provede popsanou změnu dat nebo stavu.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oEl=>oEl.hidden=oEl.dataset.analysisView!==(sKey==='contracts'||sKey==='hdo'?'settings':sKey));
  document.querySelectorAll('[data-analysis-pane]').forEach(/**
 * Nastaví nebo připojí ovládání přepínání panelů energetické analýzy.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oEl=>oEl.setAttribute('aria-pressed',String(oEl.dataset.analysisPane===sKey)));
  const oHdo=$('lm-analysis-profile-form')?.elements.namedItem('hdoSchedules');
  const oForm=$('lm-analysis-profile-form');
  document.querySelectorAll('[data-hdo-only]').forEach(/**
 * Projde položky kolekce document.querySelectorAll('[data-hdo-only]') a provede popsanou změnu dat nebo stavu.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oEl=>oEl.hidden=sKey!=='hdo');
  $('lm-analysis-stored-profile').hidden=sKey!=='contracts';
  if(oForm)Array.from(oForm.children).forEach(/**
 * Projde položky kolekce Array.from(oForm.children) a aktualizuje odpovídající prvky rozhraní.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oEl=>{
   if(oEl.classList.contains('lm-analysis-actions'))return;
   oEl.hidden=sKey==='hdo'?!(oEl.contains(oHdo)||oEl===oHdo):sKey==='contracts'?(oEl.contains(oHdo)||oEl===oHdo):false;
  });
  oLayoutController?.refresh?.('manual');
 };
 document.querySelectorAll('[data-analysis-pane]').forEach(/**
 * Nastaví nebo připojí ovládání přepínání panelů energetické analýzy.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oEl=>oEl.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>showPane(oEl.dataset.analysisPane),{signal:oLifetime.signal}));
 showPane('reports');
 $('lm-analysis-imports')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async oEvent=>{
  const vOpened=oEvent.target.closest('[data-report-open]')?.dataset.reportOpen;
  if(vOpened){await loadAnalysis(vOpened);if(vAnalysisData?.selectedPeriod===vOpened){$('lm-report-details').hidden=false;for(const oEl of $('lm-report-details').querySelectorAll('details'))oEl.open=true;$('lm-report-details').scrollIntoView({block:'start',behavior:'smooth'});}return;}
  const vPeriod=oEvent.target.closest('[data-report-delete]')?.dataset.reportDelete;
  if(!vPeriod||!bAnalysisCanConfigure||!confirm(t(APP,'Remove imported report {period}? LINEA history will be kept.').replace('{period}',vPeriod)))return;
  try{await api('/api/analysis/report/'+encodeURIComponent(vPeriod),{method:'DELETE'});await loadAnalysis();}
  catch(oE){setText('lm-analysis-import-state',oE.message);}
 },{signal:oLifetime.signal});
 $('lm-report-export')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async()=>{
  const aPeriods=Array.from(document.querySelectorAll('[data-report-select]:checked')).map(/**
 * Převede položku kolekce Array.from(document.querySelectorAll('[data-r na odvozenou hodnotu z polí value.
 * Vstupy: `oEl` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: `string` – identifikátor označeného reportu
 */ oEl=>oEl.value);
  if(!aPeriods.length){setText('lm-analysis-import-state',t(APP,'Select at least one report.'));return;}
  try{
   const oResponse2=await fetch(OC.generateUrl('/apps/'+APP+'/api/analysis/export'),{method:'POST',headers:{'Content-Type':'application/json',requesttoken:OC.requestToken},body:JSON.stringify({periods: aPeriods}),signal:oLifetime.signal});
   if(!oResponse2.ok){const oErr=await oResponse2.json();throw new Error(oErr.error||t(APP,'Export failed.'));}
   const sUrl=URL.createObjectURL(await oResponse2.blob()),oLink=document.createElement('a');oLink.href=sUrl;oLink.download='gridsight-reports.zip';oLink.click();setTimeout(/**
 * Zpracuje časovanou aktualizaci údajů nebo stavu rozhraní.
 * Vstupy: žádné.
 * Výstup: void – vedlejší účinek časovače.
 */ ()=>URL.revokeObjectURL(sUrl),1000);
  }catch(oE){setText('lm-analysis-import-state',oE.message);}
 },{signal:oLifetime.signal});
 const vSaleForm=$('lm-sale-form');
 vSaleForm?.addEventListener('reset',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{vSaleForm.elements.namedItem('id').value='';},{signal:oLifetime.signal});
 vSaleForm?.addEventListener('submit',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async oEvent=>{
  oEvent.preventDefault();if(!bAnalysisCanConfigure)return;
  const vStatement=Object.fromEntries(new FormData(vSaleForm));
  try{await api('/api/analysis/statement',{method:'POST',body:JSON.stringify({statement: vStatement})});vSaleForm.reset();setText('lm-sale-state',t(APP,'Statement saved.'));await loadAnalysis(vAnalysisData?.selectedPeriod||'');}
  catch(oE){setText('lm-sale-state',oE.message);}
 },{signal:oLifetime.signal});
 $('lm-sale-list')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async oEvent=>{
  const vEdit=oEvent.target.closest('[data-sale-edit]')?.dataset.saleEdit,vDel=oEvent.target.closest('[data-sale-delete]')?.dataset.saleDelete;
  if(!bAnalysisCanConfigure)return;
  if(vEdit){const oRow=vAnalysisData.statements.find(/**
 * Vyhledá požadovanou položku v vAnalysisData.statements podle id.
 * Vstupy: `vR` (mixed) – vstupní datový řádek se sloupci právě vytvářeného přehledu.
 * Výstup: `boolean` – true při shodě vyhledávaného identifikátoru.
 */ vR=>vR.id===vEdit);for(const sKey of ['id','from','to','amountCzk','saleEan','document'])vSaleForm.elements.namedItem(sKey).value=oRow[sKey];vSaleForm.scrollIntoView({block:'nearest'});}
  if(vDel&&confirm(t(APP,'Delete statement?')))try{await api('/api/analysis/statement/'+vDel,{method:'DELETE'});await loadAnalysis(vAnalysisData?.selectedPeriod||'');}catch(oE){setText('lm-sale-state',oE.message);}
 },{signal:oLifetime.signal});
 $('lm-analysis-month')?.addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>void loadAnalysis(oEvent.target.value),{signal:oLifetime.signal});
 $('lm-analysis-day')?.addEventListener('change',renderAnalysisDay,{signal:oLifetime.signal});
 $('lm-analysis-price-unit')?.addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
  const aPrices=priceRows();sAnalysisPriceUnit=oEvent.target.value==='kWh'?'kWh':'MWh';renderPriceRows(aPrices);
 },{signal:oLifetime.signal});
 $('lm-analysis-add-contract')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{
  const aPrices=priceRows();renderContractRows([...contractRows(),{start:'',durationMonths:36,supplier:'',product:'',sourceUrl:''}]);renderPriceRows(aPrices);
 },{signal:oLifetime.signal});
 $('lm-analysis-add-price')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{
  const aPrices=priceRows(),vLast=aPrices.at(-1)||{},aContracts=contractRows().filter(/**
 * Vybere z kolekce contractRows() jen položky splňující podmínku nad start.
 * Vstupy: `vC` (mixed) – záznam smlouvy, sazby nebo nastavení.
 * Výstup: `boolean` – true jen pokud aktuální záznam splňuje podmínku výběru.
 */ vC=>vC.start);
  aPrices.push({from:'',to:'',contractStart:aContracts.at(-1)?.start||'',vtCzkKWh:vLast.vtCzkKWh??'',
   ntCzkKWh:vLast.ntCzkKWh??'',fixedCzkMonth:vLast.fixedCzkMonth??'',greenCzkKWh:vLast.greenCzkKWh??0,
   greenEnabled:vLast.greenEnabled??false,sourceUrl:vLast.sourceUrl||''});renderPriceRows(aPrices);
 },{signal:oLifetime.signal});
 $('lm-analysis-contract-rows')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
  const nIndex=oEvent.target.closest('[data-remove-contract]')?.dataset.removeContract;if(nIndex===undefined)return;
  const aPrices=priceRows(),aRows=contractRows();aRows.splice(Number(nIndex),1);renderContractRows(aRows);renderPriceRows(aPrices);
 },{signal:oLifetime.signal});
 $('lm-analysis-contract-rows')?.addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
  if(oEvent.target.name==='start')renderPriceRows(priceRows());
 },{signal:oLifetime.signal});
 $('lm-analysis-price-rows')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
  const nIndex=oEvent.target.closest('[data-remove-price]')?.dataset.removePrice;if(nIndex===undefined)return;
  const aRows=priceRows();aRows.splice(Number(nIndex),1);renderPriceRows(aRows);
 },{signal:oLifetime.signal});
 $('lm-analysis-price-rows')?.addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
  if(oEvent.target.name!=='from')return;
  const oRow=oEvent.target.closest('.lm-analysis-entry');oRow.dataset.to='';
  oRow.querySelector('small').textContent=t(APP,'Price valid through:')+' '+t(APP,'end of the start year');
 },{signal:oLifetime.signal});
 const vZone=$('lm-analysis-dropzone'),vPicker=$('lm-analysis-file'),oStatus=$('lm-analysis-import-state');
 let bImporting=false;
  /**
 * Načte a vyhodnotí jeden vybraný soubor analýzy a zobrazí stav importu.
 * Vstupy: `oFile` (object) – soubor XLSX připravený k importu.
 * Výstup: `Promise<void>` – asynchronně aktualizuje seznam a stav importu.
 */
 const importFile=async oFile=>{
  if(!bAnalysisCanConfigure||bImporting)return;
  if(!oFile||!oFile.name.toLowerCase().endsWith('.xlsx')){oStatus.textContent=t(APP,'Select an XLSX file.');return;}
  if(oFile.size>5_000_000){oStatus.textContent=t(APP,'The XLSX file exceeds 5 MB.');return;}
  bImporting=true;vZone.setAttribute('aria-busy','true');vPicker.disabled=true;
  const oBody=new FormData();oBody.append('file',oFile,oFile.name);
  oStatus.textContent=t(APP,'Importing report…')+' '+oFile.name;
  try{const vResult=await api('/api/analysis/report',{method:'POST',body: oBody});oStatus.textContent=vResult.result.unchanged?t(APP,'This report is already imported.'):t(APP,'Report imported:')+' '+vResult.result.period+' · '+vResult.result.rows+' '+t(APP,'intervals');bAnalysisProfileLoaded=false;await loadAnalysis(vResult.result.period);}
  catch(oE){oStatus.textContent=oE.message+' ('+oFile.name+')';}
  finally{bImporting=false;vZone.removeAttribute('aria-busy');vPicker.disabled=!bAnalysisCanConfigure;vPicker.value='';}
 };
 vZone?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{if(bAnalysisCanConfigure&&!bImporting)vPicker.click();},{signal:oLifetime.signal});
 vZone?.addEventListener('keydown',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{if(oEvent.key==='Enter'||oEvent.key===' '){oEvent.preventDefault();if(bAnalysisCanConfigure&&!bImporting)vPicker.click();}},{signal:oLifetime.signal});
  /**
 * Postupně zpracuje všechny soubory vybrané pro import a vypíše výsledky.
 * Vstupy: `aFiles` (array) – seznam souborů XLSX k dávkovému importu.
 * Výstup: `Promise<void>` – po dokončení všech importů aktualizuje stavové hlášení.
 */
 const importFiles=async aFiles=>{const aMessages=[];for(const oFile of Array.from(aFiles||[])){await importFile(oFile);aMessages.push(oFile.name+': '+oStatus.textContent);}oStatus.textContent=aMessages.join('\n');};
 vPicker?.addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>void importFiles(Array.from(vPicker.files||[])),{signal:oLifetime.signal});
 vZone?.addEventListener('dragover',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{if(!bAnalysisCanConfigure||bImporting)return;oEvent.preventDefault();oEvent.dataTransfer.dropEffect='copy';vZone.classList.add('is-dragover');},{signal:oLifetime.signal});
 vZone?.addEventListener('dragleave',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>vZone.classList.remove('is-dragover'),{signal:oLifetime.signal});
 vZone?.addEventListener('drop',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
  if(!bAnalysisCanConfigure||bImporting)return;
  oEvent.preventDefault();vZone.classList.remove('is-dragover');
  const aFiles=oEvent.dataTransfer?.files;
  if(!aFiles?.length)return;
  void importFiles(Array.from(aFiles));
 },{signal:oLifetime.signal});
 $('lm-analysis-profile-file')?.addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async oEvent=>{
  const oFile=oEvent.target.files?.[0];if(!oFile)return;
  try{if(oFile.size>20000)throw new Error(t(APP,'Profile file is too large.'));fillAnalysisProfile(JSON.parse(await oFile.text()));setText('lm-analysis-profile-state',t(APP,'Profile loaded. Save to apply it.'));}
  catch(oE){setText('lm-analysis-profile-state',oE.message);}
 },{signal:oLifetime.signal});
 $('lm-analysis-profile-form')?.addEventListener('submit',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async oEvent=>{
  oEvent.preventDefault();const oStatus=$('lm-analysis-profile-state');
  try{const oProfile=readAnalysisProfile(),vSaved=await api('/api/analysis/profile',{method:'PUT',body:JSON.stringify({profile: oProfile})});vAnalysisData={...(vAnalysisData||{}),profile:vSaved.profile};fillAnalysisProfile(vSaved.profile);renderStoredAnalysisProfile(vSaved.profile);oStatus.textContent=t(APP,'Profile saved.');await loadAnalysis(vAnalysisData?.selectedPeriod||'');}
  catch(oE){oStatus.textContent=oE.message;}
 },{signal:oLifetime.signal});
}
bindAnalysis();

/**
 * Nastaví periodickou obnovu živých dat.
 * Vstupy: žádné.
 * Výstup: `void` – restartuje časovač pravidelného dotazování LINEA.
 */
function schedule(){if(bDisposed)return;clearInterval(vTimer);vTimer=setInterval(loadStatus,Math.max(1,nRefreshSeconds)*1000);}
/**
 * Vrátí název právě aktivní záložky.
 * Vstupy: žádné.
 * Výstup: `string` – ID právě aktivní záložky aplikace.
 */
function activeTab(){return document.querySelector('.lm-tab.active')?.dataset.tab||'live';}
/**
 * Převede zbývající interval na MM:SS.
 * Vstupy: `nMs` (number) – časový interval v milisekundách.
 * Výstup: `string` – zbývající čas ve formátu MM:SS.
 */
function formatCountdown(nMs){const nTotal=Math.max(0,Math.ceil(nMs/1000));const nM=Math.floor(nTotal/60),vSec=nTotal%60;return `${nM}:${String(vSec).padStart(2,'0')}`;}
/**
 * Aktualizuje indikátor automatického obnovení.
 * Vstupy: žádné.
 * Výstup: `void` – aktualizuje text a stav odpočtu automatického obnovení.
 */
function updateAutoRefreshUi(){
 const oLabel=$('lm-auto-refresh-countdown');if(!oLabel)return;
 if(document.hidden){oLabel.textContent=t(APP,'paused');return;}
 oLabel.textContent=formatCountdown(nNextSoftRefreshAt-Date.now());
}
/**
 * Nastaví další čas automatické aktualizace.
 * Vstupy: žádné.
 * Výstup: `void` – přenastaví čas příští měkké aktualizace.
 */
function resetSoftRefreshClock(){nNextSoftRefreshAt=Date.now()+SOFT_REFRESH_MS;updateAutoRefreshUi();}
/**
 * Provede aktualizaci dostupných údajů bez obnovení celé stránky.
 * Vstupy: `{fromVisibility=false}` (object) – příznak návratu uživatele na viditelnou záložku.
 * Výstup: `Promise<void>` – aktualizuje data aktivní oblasti bez změny stránky.
 */
async function softRefresh({fromVisibility=false}={}){
 if(document.hidden)return;
 const vTab=activeTab();
 // LIVE already polls continuously, but do one explicit fresh read every five minutes.
 // HISTORY receives a full data refresh only when it is the active tab. This preserves
 // zoom, hidden/visible series and open details because those states live in JS/DOM.
 try{
  if(['history','collector','events'].includes(vTab))await loadHistory(true);
  else if(['analysis'].includes(vTab))await loadAnalysis(vAnalysisData?.selectedPeriod||'');
  else await loadStatus();
 }finally{resetSoftRefreshClock();}
}
/**
 * Naplánuje kontrolu automatického obnovení.
 * Vstupy: žádné.
 * Výstup: `void` – naplánuje další kontrolu časovače obnovení.
 */
function scheduleSoftRefresh(){if(bDisposed)return;
 clearInterval(vSoftRefreshTimer);clearInterval(vSoftRefreshTick);
 resetSoftRefreshClock();
 vSoftRefreshTimer=setInterval(/**
 * Zpracuje časovanou aktualizaci údajů nebo stavu rozhraní.
 * Vstupy: žádné.
 * Výstup: void – vedlejší účinek časovače.
 */ ()=>{if(!document.hidden)softRefresh();},SOFT_REFRESH_MS);
 vSoftRefreshTick=setInterval(updateAutoRefreshUi,1000);
}
let bSettingsLoading=false,bSettingsReady=false;
let oAboutController=null;
let oSettingsFormController=null;
const oSettingsClient=CORE.settings.create(OC.generateUrl('/apps/'+APP+'/api/settings'),'hc_gridsight_main');
/**
 * Získá kopii HTML šablony pro vykreslení sekce.
 * Vstupy: `sId` (string) – ID HTML prvku nebo šablony.
 * Výstup: `DocumentFragment` – klon požadované HTML šablony.
 */
function cloneTemplate(sId){
 const vTpl=$(sId);if(!(vTpl instanceof HTMLTemplateElement))throw new Error('Missing page template: '+sId);
 const vFragment=vTpl.content.cloneNode(true);const oWrapper=document.createElement('div');oWrapper.append(vFragment);return oWrapper;
}
/**
 * Načte, vykreslí a zpřístupní nastavení aplikace.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – otevře editor uživatelského a administrátorského nastavení.
 */
async function openSettings(){
 if(bSettingsLoading||bSettingsReady)return;
 bSettingsLoading=true;
 let oValues;
 try{oValues=await oSettingsClient.load();}
 catch(oError){bSettingsLoading=false;CORE.notifications.error(oError.message,{dedupeKey:'hc_gridsight:settings-load'});LOGGER.error('Unable to load GridSight settings',oError);return;}
 if(bDisposed)return;
 const bCanConfigure=oValues.canConfigure===true;
 const aFields=[];
 aFields.push({id:'history3dInvert',label:t(APP,'Invert vertical movement in 3D'),type:'checkbox',value:oValues.history3dInvert===true});
 if(bCanConfigure)aFields.push({id:'baseUrl',label:t(APP,'LINEA API base URL'),type:'text',value:String(oValues.baseUrl||''),required:true,placeholder:'http://node-red:1880',maxLength:512});
 aFields.push({id:'refreshSeconds',label:t(APP,'LIVE refresh interval'),type:'select',value:String(oValues.refreshSeconds||2),required:true,options:[1,2,5,10,30].map(/**
 * Převede položku kolekce [1,2,5,10,30] na datový záznam.
 * Vstupy: `nValue` (number) – délka intervalu obnovy v sekundách.
 * Výstup: `object` – volba intervalu aktualizace `{value,label}`
 */ nValue=>({value:String(nValue),label:String(nValue)+' s'}))});
 if(bCanConfigure)aFields.push({id:'historyEnabled',label:t(APP,'Store history in the background'),type:'checkbox',value:oValues.historyEnabled!==false});
 for(const [key,label] of HISTORY_SERIES){
  const sName=label.startsWith('Inverter ')?t(APP,'Inverter')+' '+label.slice(-1):t(APP,label);
  aFields.push({id:'chart_'+key,label:sName,type:'checkbox',value:oValues['chart_'+key]!==false});
 }
 const vContent=cloneTemplate('lm-settings-dialog-template');
 const oResult=vContent.querySelector('#lm-test-result');
 oSettingsFormController=CORE.forms.create(aFields);
 const vFirstSeries=oSettingsFormController.element.querySelector('[name="chart_pv"]')?.closest('.hc-shared-app-core-form__field');
 if(vFirstSeries){const oHeading2=document.createElement('h4');oHeading2.textContent=t(APP,'History chart series');const oHint=document.createElement('p');oHint.className='lm-help';oHint.textContent=t(APP,'Choose the curves shown in History. Collection continues for every sensor.');vFirstSeries.before(oHeading2,oHint);}
 vContent.querySelector('#lm-settings-form-host')?.append(oSettingsFormController.element);
 const oActions=document.createElement('div');oActions.className='lm-settings-actions';
 if(bCanConfigure){
  const oTest=document.createElement('button');oTest.type='button';oTest.textContent=t(APP,'Test connection');oActions.append(oTest);
  oTest.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async()=>{
   const oFormValues=oSettingsFormController.values();oResult.textContent=t(APP,'Testing…');
   try{const oResponse=await api('/api/settings/test',{method:'POST',body:JSON.stringify({baseUrl:String(oFormValues.baseUrl||'')})});const oHealth=oResponse.health||{},oInfo=oHealth.api||{},aProblems=[];if(oInfo.name!=='LINEA API')aProblems.push(t(APP,'API name'));if(Number(oInfo.schema)!==EXPECTED_API_SCHEMA)aProblems.push(t(APP,'API schema')+' '+(oInfo.schema??'—'));if(oInfo.readOnly!==true)aProblems.push(t(APP,'read-only flag'));if(aProblems.length){oResult.textContent=t(APP,'API responds, but the contract is not compatible:')+' '+aProblems.join(', ');CORE.notifications.warning(oResult.textContent,{dedupeKey:'hc_gridsight:test-warning'});}else{oResult.textContent=t(APP,'Connection OK')+' · LINEA API '+(oInfo.version||'1.x')+' · read-only';CORE.notifications.success(t(APP,'Connection OK'),{dedupeKey:'hc_gridsight:test-ok'});}}
   catch(oError){oResult.textContent=oError.message;CORE.notifications.error(oError.message,{dedupeKey:'hc_gridsight:test-error'});}
  },{signal:oLifetime.signal});
 }
 const oSave=document.createElement('button');oSave.type='button';oSave.className='primary';oSave.textContent=t(APP,'Save');oActions.append(oSave);
 oSave.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – provede vedlejší účinek.
 */ async()=>{
  if(!oSettingsFormController.validate()){oResult.textContent=t(APP,'Request failed');return;}
  const oFormValues=oSettingsFormController.values();const oSaveValues={refreshSeconds:Number(oFormValues.refreshSeconds)||2,history3dInvert:oFormValues.history3dInvert===true};
  if(bCanConfigure){oSaveValues.baseUrl=String(oFormValues.baseUrl||'');oSaveValues.historyEnabled=oFormValues.historyEnabled!==false;}
  for(const [key] of HISTORY_SERIES)oSaveValues['chart_'+key]=oFormValues['chart_'+key]===true;
  try{const oSaved=await oSettingsClient.save(oSaveValues);nRefreshSeconds=Number(oSaved.refreshSeconds||oSaveValues.refreshSeconds)||2;applyHistoryPreferences(oSaved);CORE.notifications.success(t(APP,'Settings saved'),{dedupeKey:'hc_gridsight:settings-saved'});schedule();void loadStatus();bHistoryLoaded=false;}
  catch(oError){oResult.textContent=oError.message;CORE.notifications.error(oError.message,{dedupeKey:'hc_gridsight:settings-save-error'});}
 },{signal:oLifetime.signal});
 vContent.querySelector('#lm-core-about')?.before(oActions);
 const oHost=$('lm-settings-page-host');if(!oHost)return;
 oHost.replaceChildren(...vContent.childNodes);
 const vAboutEl=oHost.querySelector('#lm-core-about');if(vAboutEl)oAboutController=CORE.about.mount(vAboutEl,{requiredCoreVersion:CORE_MIN_VERSION,heading:t(APP,'About')});
 bSettingsReady=true;bSettingsLoading=false;
}
const oToolbarHost=$('lm-core-toolbar');
const oToolbarController=oToolbarHost?CORE.toolbar.create(oToolbarHost,{ariaLabel:t(APP,'LINEA sections'),actions:[
 {id:'refresh',label:t(APP,'Refresh'),icon:'↻',compact:true,onClick:/**
 * Obslouží vlastnost nebo zpětné volání `onClick`.
 * Vstupy: žádné.
 * Výstup: `Promise<mixed>` – vrátí výsledek lokálního callbacku.
 */ async()=>{if(['history','collector','events'].includes(activeTab()))await loadHistory(true);else if(['analysis'].includes(activeTab()))await loadAnalysis(vAnalysisData?.selectedPeriod||'');else{await loadStatus();void loadDailyRevenue(true);}resetSoftRefreshClock();}},
]}):null;
const vNavToggle=$('lm-open-nav'),vNav=$('lm-tabs'),vNavBackdrop=$('lm-nav-backdrop');
/**
 * Upraví zobrazení navigačních záložek.
 * Vstupy: `bOpen` (boolean) – požadovaný stav otevření navigace; `bRestoreFocus` (boolean) – zda po zavření vrátit fokus na ovladač.
 * Výstup: `void` – přepne viditelnost nabídky záložek a podle volby obnoví fokus.
 */
function setTabsOpen(bOpen,bRestoreFocus=false){
 vNav.classList.toggle('is-open',bOpen);
 vNavBackdrop.hidden=!bOpen;
 vNavToggle.setAttribute('aria-expanded',String(bOpen));
 if(bOpen)vNav.querySelector('.lm-tab.active')?.focus();
 else if(bRestoreFocus)vNavToggle.focus();
}
vNavToggle.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>setTabsOpen(!vNav.classList.contains('is-open')),{signal:oLifetime.signal});
$('lm-close-nav').addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>setTabsOpen(false,true),{signal:oLifetime.signal});
vNavBackdrop.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>setTabsOpen(false,true),{signal:oLifetime.signal});
document.addEventListener('keydown',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{if(oEvent.key==='Escape'&&vNav.classList.contains('is-open')){oEvent.preventDefault();setTabsOpen(false,true)}},{signal:oLifetime.signal});
window.matchMedia('(max-width:760px)').addEventListener('change',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>setTabsOpen(false),{signal:oLifetime.signal});
document.querySelectorAll('.lm-tab').forEach(/**
 * Pro každý přepínač záložky zaregistruje ovladač kliknutí.
 * Vstupy: `oBtn` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oBtn=>oBtn.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{setTabsOpen(false,true);document.querySelectorAll('.lm-tab').forEach(/**
 * Projde položky kolekce document.querySelectorAll('.lm-tab') a aktualizuje odpovídající prvky rozhraní.
 * Vstupy: `oElement` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oElement=>oElement.classList.toggle('active',oElement===oBtn));document.querySelectorAll('.lm-tab-panel').forEach(/**
 * Projde položky kolekce document.querySelectorAll('.lm-tab-panel') a aktualizuje odpovídající prvky rozhraní.
 * Vstupy: `oElement` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oElement=>oElement.classList.remove('active'));$('lm-tab-'+oBtn.dataset.tab)?.classList.add('active');if(['history','collector','events'].includes(oBtn.dataset.tab)){if(!bHistoryLoaded)loadHistory();else if(oBtn.dataset.tab==='history'){if(sHistoryView==='3d')h3Schedule();else renderPowerChart(aHistoryPoints);}}if(['analysis'].includes(oBtn.dataset.tab))void loadAnalysis(vAnalysisData?.selectedPeriod||'');if(oBtn.dataset.tab==='settings')void openSettings();oLayoutController?.refresh?.('manual');}));
initializeHistorySurface();
document.querySelectorAll('[data-history-range]').forEach(/**
 * Projde položky kolekce document.querySelectorAll('[data-history-rang a provede popsanou změnu dat nebo stavu.
 * Vstupy: `oBtn` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oBtn=>oBtn.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{sHistoryRange=oBtn.dataset.historyRange||'24h';vHistoryZoom=null;nHistorySelectedTs=null;document.querySelectorAll('[data-history-range]').forEach(/**
 * Projde položky kolekce document.querySelectorAll('[data-history-rang a aktualizuje odpovídající prvky rozhraní.
 * Vstupy: `oElement` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oElement=>oElement.classList.toggle('active',oElement===oBtn));bHistoryLoaded=false;loadHistory(true);}));
document.querySelectorAll('[data-series]').forEach(/**
 * Projde položky kolekce document.querySelectorAll('[data-series]') a provede popsanou změnu dat nebo stavu.
 * Vstupy: `oBtn` (object) – DOM prvek ovládacího rozhraní.
 * Výstup: void – provedení operace bez návratové hodnoty.
 */ oBtn=>oBtn.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{const sKey=oBtn.dataset.series;if(!oHistoryAllowed[sKey]||oBtn.disabled)return;vHistoryVisible[sKey]=!vHistoryVisible[sKey];oBtn.classList.toggle('active',vHistoryVisible[sKey]);oBtn.setAttribute('aria-pressed',String(vHistoryVisible[sKey]));renderPowerChart(aHistoryPoints);}));
$('lm-history-zoom-reset')?.addEventListener('click',/**
 * Obslouží událost rozhraní.
 * Vstupy: žádné.
 * Výstup: `void` – provede vedlejší účinek.
 */ ()=>{vHistoryZoom=null;$('lm-history-zoom-reset').hidden=true;renderPowerChart(aHistoryPoints);});
oLayoutController?.refresh?.('manual');
/**
 * Obnoví data po návratu na viditelnou stránku.
 * Vstupy: žádné.
 * Výstup: `void` – znovu vyhodnotí data po návratu na viditelnou stránku.
 */
const fnVisibilityChange=()=>{if(document.hidden){updateAutoRefreshUi();return;}softRefresh({fromVisibility:true});};
document.addEventListener('visibilitychange',fnVisibilityChange);
/**
 * Odpojí obslužné události a zastaví časovače aplikace.
 * Vstupy: žádné.
 * Výstup: `void` – uvolní časovače, posluchače a aktivní operace aplikace.
 */
function fnCleanup(){
 if(bDisposed)return;
 bDisposed=true;oLifetime.abort();if(nHistory3dFrame)cancelAnimationFrame(nHistory3dFrame);
 clearInterval(vTimer);clearInterval(vSoftRefreshTimer);clearInterval(vSoftRefreshTick);
 document.removeEventListener('visibilitychange',fnVisibilityChange);
 oSettingsFormController?.destroy?.();oToolbarController?.destroy?.();oLayoutController?.destroy?.();oAboutController?.destroy?.();CORE.notifications.clear();
}

 // Publish cleanup before the first asynchronous startup request.
 fnCurrentCleanup=fnCleanup;
 try{const oValues=await oSettingsClient.load();if(bDisposed)return fnCleanup;nRefreshSeconds=Number(oValues.refreshSeconds)||2;bAnalysisCanConfigure=oValues.canConfigure===true;applyHistoryPreferences(oValues);}catch(_){}
 if(bDisposed)return fnCleanup;
 await loadStatus();
 void loadDailyRevenue();
 if(bDisposed)return fnCleanup;
 schedule();
 scheduleSoftRefresh();
 return fnCleanup;
}

/**
 * Inicializuje zdroje, překlady a první zobrazení GridSight.
 * Vstupy: žádné.
 * Výstup: `Promise<void>` – ověří Core, vytvoří rozhraní a zahájí načítání.
 */
async function start(){
 const oRoot=document.getElementById('linea-monitor');
 if(!oRoot||bPageClosed||bStarted)return;
 bStarted=true;
 const oCore=window.HcSharedAppCore;
 if(!oCore){
  showStartupError(oRoot,t(APP,'Application could not be started'),t(APP,'Shared App Core is missing, disabled or temporarily unavailable.'));
  return;
 }
 try{
  oCore.assertCompatible(oRoot.dataset.requiredCoreVersion||'0.18.8');
  if(Number(oCore.apiVersion)!==Number(oRoot.dataset.requiredCoreApiVersion||1))throw new Error('Unsupported Shared App Core API version.');
  const fnCleanup=await mountApplication(oRoot,oCore);
  if(bPageClosed)fnCleanup();else fnCurrentCleanup=fnCleanup;
 }catch(oError){
  fnCurrentCleanup?.();fnCurrentCleanup=null;
  if(bPageClosed)return;
  showStartupError(oRoot,t(APP,'Application could not be started'),oError instanceof Error?oError.message:String(oError));
 }
}

// A persisted page is frozen by the browser and resumes with its controllers intact.
window.addEventListener('pagehide',/**
 * Obslouží událost rozhraní.
 * Vstupy: `oEvent` (object).
 * Výstup: `void` – provede vedlejší účinek.
 */ oEvent=>{
 if(oEvent.persisted)return;
 bPageClosed=true;
 if(typeof fnCurrentCleanup==='function')fnCurrentCleanup();
 fnCurrentCleanup=null;
});

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
else void start();
})();
