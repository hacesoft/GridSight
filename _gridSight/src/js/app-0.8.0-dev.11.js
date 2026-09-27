(() => {
'use strict';
const APP='hc_gridsight';
const EXPECTED_API_SCHEMA=1;
let fnCurrentCleanup=null;
let bPageClosed=false,bStarted=false;

function showStartupError(oRoot,sTitle,sDetail){
 oRoot.className='hc-app-startup-error';
 oRoot.replaceChildren();
 const oHeading=document.createElement('h2');oHeading.textContent=sTitle;
 const oMessage=document.createElement('p');oMessage.textContent=sDetail;
 oRoot.append(oHeading,oMessage);
}

async function mountApplication(oBootstrapRoot,CORE){
 let bDisposed=false;
 const oLifetime=new AbortController();
 const APP_VERSION=String(oBootstrapRoot.dataset.appVersion||'');
 const CORE_MIN_VERSION=String(oBootstrapRoot.dataset.requiredCoreVersion||'0.18.0-dev.2');
 CORE.about.register({id:APP,name:'GridSight',version:APP_VERSION,repository:'https://github.com/hacesoft/nextcloud-linea-monitor',releaseNotes:'https://github.com/hacesoft/nextcloud-linea-monitor/releases',documentation:'https://github.com/hacesoft/nextcloud-linea-monitor#readme',core:'>='+CORE_MIN_VERSION});
 const oShellTemplate=document.getElementById('lm-app-shell-template');
 if(!(oShellTemplate instanceof HTMLTemplateElement))throw new Error('LINEA Monitor shell template is missing.');
 const oShellFragment=oShellTemplate.content.cloneNode(true);
 oBootstrapRoot.replaceChildren(oShellFragment);
 oShellTemplate.remove();
 oBootstrapRoot.classList.add('lm-app','hc-shared-app-core-layout');
 let nLastLayoutWidth=0;
 const fnHandleCoreLayoutResize=({width})=>{
  // Pinch changes visualViewport, not the CSS width of the allocated app area.
  if(width===nLastLayoutWidth)return;
  nLastLayoutWidth=width;
  window.requestAnimationFrame(()=>{
   if(bDisposed)return;
   if(activeTab()==='history'&&historyPoints.length>1)renderPowerChart(historyPoints);
   else if(activeTab()==='live'&&latestStatus)renderSpotSchedule(latestStatus);
  });
 };
 const oLayoutController=CORE.layout.observe(oBootstrapRoot,{
  heightMode:'container',
  topOffset:0,
  onResize:fnHandleCoreLayoutResize,
 });
 oLayoutController.refresh('manual');
const LOGGER=CORE.logger;
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const isValue=v=>v!==null&&v!==undefined&&v!==''&&Number.isFinite(Number(v));
const num=(v,d=0)=>isValue(v)?Number(v).toFixed(d):'—';
const onoff=v=>v===true?t(APP,'On'):v===false?t(APP,'Off'):'—';
const yesno=v=>v===true?t(APP,'Yes'):v===false?t(APP,'No'):'—';
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
const tip=(key,text='?')=>`<span class="lm-help-tip" tabindex="0" title="${esc(HELP[key]||key)}" aria-label="${esc(HELP[key]||key)}">${esc(text)}</span>`;
const api=async(path,options={})=>{
 const r=await fetch(OC.generateUrl('/apps/'+APP+path),{cache:'no-store',...options,signal:oLifetime.signal,headers:{requesttoken:OC.requestToken,'Content-Type':'application/json',...(options.headers||{})}});
 const text=await r.text(); let data={}; try{data=text?JSON.parse(text):{}}catch(_){data={}}
 if(!r.ok||data.ok===false)throw new Error(data.error||r.statusText||t(APP,'Request failed')); return data;
};
let refreshSeconds=2,timer=null,loading=false,latestStatus=null;
let dailyLoading=false,lastDailyFetchAt=0,lastDailyDay='';
const SOFT_REFRESH_MS=5*60*1000;
let softRefreshTimer=null,softRefreshTick=null,nextSoftRefreshAt=Date.now()+SOFT_REFRESH_MS;
function ageLabel(iso){if(!iso)return'—';const ms=Date.now()-new Date(iso).getTime();if(!Number.isFinite(ms))return'—';const s=Math.max(0,Math.round(ms/1000));if(s<60)return s+' s';const m=Math.round(s/60);if(m<60)return m+' min';const h=Math.round(m/60);if(h<48)return h+' h';return Math.round(h/24)+' d';}
function setText(id,value){const el=$(id);if(el)el.textContent=value;}
function setTitle(id,value){const el=$(id);if(el)el.title=value||'';}
function boolBadge(v){return v===true?'<span class="lm-state on">'+esc(t(APP,'ON'))+'</span>':v===false?'<span class="lm-state off">'+esc(t(APP,'OFF'))+'</span>':'<span class="lm-state unknown">—</span>';}
function unit(s,path,fallback=''){return s?.units?.[path]||fallback;}
function value(v,u='',d=0){const n=num(v,d);return n==='—'?'—':n+(u?' '+u:'');}
function blockAvailable(block){return block?.available===true;}
function validateContract(s){
 const problems=[];
 if(s?.api?.name!=='LINEA API')problems.push(t(APP,'Unexpected API:')+' '+(s?.api?.name??'—'));
 if(Number(s?.api?.schema)!==EXPECTED_API_SCHEMA)problems.push(t(APP,'Unsupported LINEA API schema')+' '+(s?.api?.schema??'—')+' ('+EXPECTED_API_SCHEMA+')');
 if(s?.api?.readOnly!==true)problems.push(t(APP,'API is not marked as read-only'));
 return problems;
}
function renderStatus(s){
 latestStatus=s;
 const contractProblems=validateContract(s);
 if(contractProblems.length)throw new Error(contractProblems.join('. '));
 const energyOk=blockAvailable(s.energy),e=energyOk?(s.energy||{}):{},pv=e.pv||{},house=e.house||{},grid=e.grid||{},batt=e.battery||{};
 setText('lm-pv-power',num(pv.powerW));setTitle('lm-pv-power',HELP['energy.pv.powerW']);
 setText('lm-house-power',num(house.powerW));setTitle('lm-house-power',HELP['energy.house.powerW']);
 setText('lm-grid-power',num(grid.powerW));setTitle('lm-grid-power',HELP['energy.grid.powerW']);
 if(isValue(grid.powerW)) setText('lm-grid-direction',Number(grid.powerW)<0?t(APP,'W sale'):Number(grid.powerW)>0?t(APP,'W purchase'):'W'); else setText('lm-grid-direction','W');
 setText('lm-battery-soc',num(batt.socPct));setTitle('lm-battery-soc',HELP['energy.battery.socPct']);
 setText('lm-battery-power',(isValue(batt.powerW)&&Number(batt.powerW)>0?'+':'')+value(batt.powerW,unit(s,'energy.battery.powerW','W')));setTitle('lm-battery-power',HELP['energy.battery.powerW']);
 const fill=$('lm-battery-fill');if(fill)fill.style.width=isValue(batt.socPct)?Math.max(0,Math.min(100,Number(batt.socPct)))+'%':'0%';
 setText('lm-batt-current',value(batt.currentA,unit(s,'energy.battery.currentA','A'),1));
 setText('lm-batt-voltage',value(batt.voltageV,unit(s,'energy.battery.voltageV','V'),1));
 setText('lm-batt-limit',value(batt.batteryLifeSocLimitPct,unit(s,'energy.battery.batteryLifeSocLimitPct','%'),1));
 const strings=Array.isArray(pv.strings)?pv.strings:[];
 const validYields=strings.filter(x=>isValue(x.yieldTodayKWh));
 const totalYield=validYields.length?validYields.reduce((a,x)=>a+Number(x.yieldTodayKWh),0):null;
 setText('lm-pv-yield',value(totalYield,'kWh',2));
 $('lm-pv-strings').innerHTML=strings.length?strings.map(x=>`<div class="lm-string"><div><strong>${esc(x.name)}</strong><small>${value(x.pvVoltageV,unit(s,'energy.pv.strings[].pvVoltageV','V'),2)} · ${value(x.pvCurrentA,unit(s,'energy.pv.strings[].pvCurrentA','A'),1)} ${tip('energy.pv.strings[].pvVoltageV')}</small></div><div><strong title="${esc(HELP['energy.pv.strings[].powerW'])}">${value(x.powerW,unit(s,'energy.pv.strings[].powerW','W'))}</strong><small title="${esc(HELP['energy.pv.strings[].yieldTodayKWh'])}">${value(x.yieldTodayKWh,unit(s,'energy.pv.strings[].yieldTodayKWh','kWh'),1)}</small></div></div>`).join(''):'<div class="lm-empty">—</div>';
 const gp=grid.phases||{},hp=house.phases||{};['l1','l2','l3'].forEach(k=>{setText('lm-grid-'+k,value(gp[k+'PowerW'],'W'));setText('lm-house-'+k,value(hp[k+'PowerW'],'W'));});
 const g=isValue(grid.powerW)?Number(grid.powerW):null;setText('lm-grid-badge',g===null?'—':g<0?t(APP,'To grid'):g>0?t(APP,'From grid'):t(APP,'Balanced'));setText('lm-grid-tech-total',value(grid.powerW,'W'));setText('lm-house-tech-total',value(house.powerW,'W'));
 const essOk=blockAvailable(s.ess),ess=essOk?(s.ess||{}):{},dec=ess.decision||{};
 setText('lm-grid-point',value(dec.gridPointW,unit(s,'ess.decision.gridPointW','W')));setText('lm-prediction',onoff(dec.predictionActive));setText('lm-ess-state',essOk?t(APP,'Active'):t(APP,'Unavailable'));
 const reason=dec.reason||{};setText('lm-ess-reason',[reason.code,reason.text].filter(Boolean).join(' · ')||'—');
 renderEssDetails(s,ess);
 setText('lm-spot',blockAvailable(s.spot)?value(s.spot.currentPrice,unit(s,'spot.currentPrice','CZK/kWh'),2):'—');
 const salePermission=$('lm-spot-export-state');
 if(salePermission){salePermission.textContent=dec.exportAllowed===true?t(APP,'Allowed'):dec.exportAllowed===false?t(APP,'Blocked'):'—';salePermission.className='lm-spot-permission '+(dec.exportAllowed===true?'allowed':dec.exportAllowed===false?'blocked':'unknown');}
 setText('lm-spot-grid-charge-state',ess?.switches?.spotGridCharging===true?t(APP,'Active'):ess?.switches?.spotGridCharging===false?t(APP,'Off'):'—');
 setText('lm-spot-threshold',value(ess?.settings?.spotThresholdPrice,'CZK/kWh',2));
 setText('lm-forecast-pv',blockAvailable(s.forecast)?value(s.forecast.solarYieldForecastKWh,unit(s,'forecast.solarYieldForecastKWh','kWh'),1):'—');
 setText('lm-forecast-cons',blockAvailable(s.forecast)?value(s.forecast.consumptionForecastKWh,unit(s,'forecast.consumptionForecastKWh','kWh'),1):'—');
 setText('lm-spot-pv-forecast',blockAvailable(s.forecast)?value(s.forecast.solarYieldForecastKWh,unit(s,'forecast.solarYieldForecastKWh','kWh'),1):'—');
 setText('lm-spot-cons-forecast',blockAvailable(s.forecast)?value(s.forecast.consumptionForecastKWh,unit(s,'forecast.consumptionForecastKWh','kWh'),1):'—');
 setText('lm-sun',blockAvailable(s.solar)?[s.solar.sunrise,s.solar.sunset].filter(Boolean).join(' – ')||'—':'—');
 setText('lm-day-length',blockAvailable(s.solar)?(s.solar.dayLength||'—'):'—');
 setText('lm-weather-today',blockAvailable(s.weather)?(s.weather.today||'—'):'—');
 setText('lm-weather-rain',blockAvailable(s.weather)?value(s.weather.rainProbabilityPct,unit(s,'weather.rainProbabilityPct','%')):'—');
 setText('lm-weather-trend',blockAvailable(s.weather)?(s.weather.trend||'—'):'—');
 const vrmOk=blockAvailable(s.vrm),vd=vrmOk?(s.vrm?.data||{}):{},vrm=vd.today||{};setText('lm-vrm-updated',vrmOk?ageLabel(vd.updatedAt):'—');
 setText('lm-vrm-pv',value(vrm.pvYieldKWh,unit(s,'vrm.data.today.pvYieldKWh','kWh'),2));setText('lm-vrm-cons',value(vrm.consumptionKWh,unit(s,'vrm.data.today.consumptionKWh','kWh'),2));setText('lm-vrm-import',value(vrm.gridImportKWh,unit(s,'vrm.data.today.gridImportKWh','kWh'),2));setText('lm-vrm-export',value(vrm.gridExportKWh,unit(s,'vrm.data.today.gridExportKWh','kWh'),2));setText('lm-vrm-batt-charge',value(vrm.batteryChargeKWh,unit(s,'vrm.data.today.batteryChargeKWh','kWh'),3));setText('lm-vrm-batt-discharge',value(vrm.batteryDischargeKWh,unit(s,'vrm.data.today.batteryDischargeKWh','kWh'),3));
 // Compact daily energy balance: actual VRM values + LINEA day forecasts.
 const fc=blockAvailable(s.forecast)?(s.forecast?.data||s.forecast||{}):{};
 const pvToday=vrm.pvYieldKWh,consToday=vrm.consumptionKWh,pvForecast=fc.solarYieldForecastKWh,consForecast=fc.consumptionForecastKWh;
 setText('lm-balance-pv-today',value(pvToday,'kWh',2));setText('lm-balance-pv-forecast',value(pvForecast,'kWh',1));
 setText('lm-balance-house-today',value(consToday,'kWh',2));setText('lm-balance-house-forecast',value(consForecast,'kWh',1));
 setText('lm-balance-import',historyNum(vrm.gridImportKWh,2));setText('lm-balance-export',historyNum(vrm.gridExportKWh,2));setText('lm-balance-soc',num(batt.socPct));
 const dayGridBalance=isValue(vrm.gridImportKWh)&&isValue(vrm.gridExportKWh)?Number(vrm.gridImportKWh)-Number(vrm.gridExportKWh):null;setText('lm-grid-day-balance',dayGridBalance===null?'—':(dayGridBalance>0?'+':'')+dayGridBalance.toFixed(2)+' kWh');
 const batteryEnergyBalance=isValue(vrm.batteryChargeKWh)&&isValue(vrm.batteryDischargeKWh)?Number(vrm.batteryChargeKWh)-Number(vrm.batteryDischargeKWh):null;
 setText('lm-battery-energy-balance',batteryEnergyBalance===null?'—':(batteryEnergyBalance>0?'+':'')+batteryEnergyBalance.toFixed(2)+' kWh');
 setText('lm-balance-charge',historyNum(vrm.batteryChargeKWh,2));setText('lm-balance-discharge',historyNum(vrm.batteryDischargeKWh,2));
 const setProgress=(id,actual,forecast)=>{const el=$(id);if(!el)return;const pct=isValue(actual)&&isValue(forecast)&&Number(forecast)>0?Math.max(0,Math.min(100,Number(actual)/Number(forecast)*100)):0;el.style.width=pct+'%';};
 setProgress('lm-balance-pv-progress',pvToday,pvForecast);setProgress('lm-balance-house-progress',consToday,consForecast);
 const outlook=isValue(pvForecast)&&isValue(consForecast)?Number(pvForecast)-Number(consForecast):null;
 setText('lm-balance-outlook',outlook===null?'—':(outlook>=0?'+':'')+outlook.toFixed(1)+' kWh');
 setText('lm-balance-text',outlook===null?t(APP,'Energy outlook unavailable'):outlook>=0?t(APP,'Forecast production is higher than forecast consumption by')+' '+outlook.toFixed(1)+' kWh':t(APP,'Forecast consumption is higher than forecast production by')+' '+Math.abs(outlook).toFixed(1)+' kWh');
 setText('lm-house-tech-total',value(house.powerW,'W'));setText('lm-grid-tech-total',value(grid.powerW,'W'));
 const temps=s.hcTemperatures||{},techTemps=Array.isArray(temps.sensors)?temps.sensors:[];
 const tempEl=$('lm-tech-temperatures');if(tempEl)tempEl.innerHTML=techTemps.length?techTemps.map(x=>`<div class="lm-tech-temp" title="${esc(HELP.temperatures)}"><span>🌡 ${esc(x.name||t(APP,'Sensor'))}</span><strong>${value(x.temperatureC,'°C',1)}</strong><small>${esc(t(APP,({inverter:'Inverter',rack:'Rack',battery:'Battery',sensor:'Sensor'})[x.kind]||'Sensor'))}</small></div>`).join(''):'<div class="lm-empty">—</div>';
 setText('lm-house-temp-l1','🌡 '+value(temps.inverter1TempC,'°C',1));setText('lm-house-temp-l2','🌡 '+value(temps.inverter2TempC,'°C',1));setText('lm-house-temp-l3','🌡 '+value(temps.inverter3TempC,'°C',1));
 setText('lm-house-rack-temp',value(temps.rackTempC,'°C',1));setText('lm-batt-rack-temp',value(temps.rackTempC,'°C',1));
 const climateOk=blockAvailable(s.climate),cd=climateOk?(s.climate?.data||{}):{};setText('lm-climate-updated',climateOk?ageLabel(cd.updatedAt):'—');const climateEl=$('lm-climate');const climateOpen=[...climateEl.querySelectorAll('.lm-climate-device')].map((el,i)=>el.querySelector('details')?.open?i:-1).filter(i=>i>=0);climateEl.innerHTML=(cd.devices||[]).length?cd.devices.map(x=>renderClimate(x,s)).join(''):'<div class="lm-empty">—</div>';climateOpen.forEach(i=>{const d=climateEl.querySelectorAll('.lm-climate-device')[i]?.querySelector('details');if(d)d.open=true;});
 const shellyOk=blockAvailable(s.shelly),sd=shellyOk?(s.shelly?.data||{}):{};setText('lm-shelly-updated',shellyOk?ageLabel(sd.updatedAt):'—');
 const shellyDevices=sd.devices||[];
 const isPrimaryShelly=x=>{const n=String(x.name||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');return n.includes('bojler')||n.includes('nocni proud')||n.includes('nocni_proud');};
 const shellyPrimary=shellyDevices.filter(isPrimaryShelly),shellyMore=shellyDevices.filter(x=>!isPrimaryShelly(x));
 const shellyHtml=x=>`<div class="lm-switch" title="${esc(HELP.shelly)}"><span>${esc(String(x.name||'').trim())}<small>${esc(x.kind||'—')} · ${esc(t(APP,'channel'))} ${x.channel??'—'}</small></span>${x.available===false?'<span class="lm-state unknown">N/A</span>':boolBadge(x.state)}</div>`;
 $('lm-shelly-primary').innerHTML=shellyPrimary.length?shellyPrimary.map(shellyHtml).join(''):'<div class="lm-empty">—</div>';
 $('lm-shelly').innerHTML=shellyMore.length?shellyMore.map(shellyHtml).join(''):'<div class="lm-empty">—</div>';setText('lm-shelly-more-count',String(shellyMore.length));
 const smoke=sd.smokeDetectors||[];$('lm-smoke').innerHTML=smoke.length?smoke.map(x=>`<div class="lm-device-row" title="${esc(HELP.smoke)}"><div><strong>🔥 ${esc(x.name)}</strong><small>${t(APP,'Battery')} ${value(x.batteryPct,'%',0)} · ${value(x.batteryVoltageV,'V',2)} · RSSI ${value(x.rssiDbm,'dBm')} · ${t(APP,'seen')} ${ageLabel(x.lastSeen)} · ${esc(t(APP,'age'))} ${value(x.ageSec,'s')}</small><small>${esc(x.wakeupReason||'—')}</small></div><span class="lm-state ${x.alarm?'alarm':x.ok===true?'on':'warn'}">${x.alarm?t(APP,'ALARM'):x.ok===true?t(APP,'OK'):'?'}</span></div>`).join(''):'<div class="lm-empty">'+esc(t(APP,'No smoke data'))+'</div>';
 const u=blockAvailable(s.ups)?s.ups?.data:null;const upsSummary=$('lm-ups-summary'),upsDetails=$('lm-ups-details');if(upsSummary)upsSummary.innerHTML=u?renderUpsSummary(u,s):'<div class="lm-empty">—</div>';if(upsDetails)upsDetails.innerHTML=u?renderUpsDetails(u,s):'<div class="lm-empty">—</div>';setText('lm-ups-age',u&&isValue(u.updatedAt)?ageLabel(u.updatedAt):'—');
 renderSpotSchedule(s);renderForecastAvailability(s);
 setText('lm-api-version','LINEA API '+(s.api?.version||'—'));
 const age=isValue(s.system?.ageMs)?Math.round(Number(s.system.ageMs)/1000)+' s':'—';setText('lm-source-age',t(APP,'Source age')+' '+age);setTitle('lm-source-age',HELP['system.ageMs']);
 const c=$('lm-connection');c.className='lm-status '+(s.system?.stale?'lm-status-warn':'lm-status-ok');c.innerHTML='<span class="lm-dot"></span>'+(s.system?.stale?t(APP,'LINEA stale'):t(APP,'LINEA online'));
}
function renderEssDetails(s,ess){
 const sw=ess.switches||{},st=ess.settings||{},tm=ess.time||{},dc=tm.delayCharging||{};
 const switches=[
  [t(APP,'ESS AC Grid control'),'ess.switches.controlModeEssAcGrid',sw.controlModeEssAcGrid],
  [t(APP,'SPOT grid charging'),'ess.switches.spotGridCharging',sw.spotGridCharging],
  [t(APP,'Grid charging'),'ess.switches.gridCharging',sw.gridCharging],
  [t(APP,'Grid consumption allowed'),'ess.switches.gridConsumption',sw.gridConsumption],
  [t(APP,'Energy Threshold Injector'),'ess.switches.energyThresholdInjector',sw.energyThresholdInjector],
  [t(APP,'Non-battery priority'),'ess.switches.nonBatteryPriority',sw.nonBatteryPriority],
  [t(APP,'Delay Charging'),'ess.switches.delayCharging',sw.delayCharging],
  [t(APP,'Dynamic SOC reserve'),'ess.switches.dynamicSocReserve',sw.dynamicSocReserve],
  [t(APP,'Prediction threshold'),'ess.switches.predictionThreshold',sw.predictionThreshold],
  [t(APP,'SOC delta before export'),'ess.switches.socDeltaBeforeExport',sw.socDeltaBeforeExport],
  [t(APP,'Morning battery sale'),'ess.switches.morningPeakBatterySales',sw.morningPeakBatterySales],
  [t(APP,'Evening battery sale'),'ess.switches.eveningPeakBatterySales',sw.eveningPeakBatterySales]
 ];
 const switchEl=$('lm-ess-switches');if(switchEl)switchEl.innerHTML=switches.map(([n,k,v])=>`<div><dt>${esc(n)} ${tip(k)}</dt><dd>${boolBadge(v)}</dd></div>`).join('');
 const settings=[
  [t(APP,'Balancing reserve'),'ess.settings.balancingReserveW',st.balancingReserveW,'W',0],[t(APP,'Grid target'),'ess.settings.setGridValueW',st.setGridValueW,'W',0],[t(APP,'Max. Grid Point'),'ess.settings.maxGridPointW',st.maxGridPointW,'W',0],[t(APP,'SPOT threshold'),'ess.settings.spotThresholdPrice',st.spotThresholdPrice,'CZK/kWh',2],[t(APP,'Morning SOC'),'ess.settings.morningSocSalesPct',st.morningSocSalesPct,'%',0],[t(APP,'Evening SOC'),'ess.settings.eveningSocSalesPct',st.eveningSocSalesPct,'%',0],[t(APP,'Grid charging SOC'),'ess.settings.gridChargingSocPct',st.gridChargingSocPct,'%',0],[t(APP,'Prediction threshold'),'ess.settings.predictionThresholdKWh',st.predictionThresholdKWh,'kWh',1],[t(APP,'SOC delta before export'),'ess.settings.socDeltaBeforeExportPct',st.socDeltaBeforeExportPct,'%',0],[t(APP,'Grid charging duration'),'ess.settings.chargingDurationGridH',st.chargingDurationGridH,'h',1],[t(APP,'Acceptable GRID price'),'ess.settings.acceptablePriceGrid',st.acceptablePriceGrid,'CZK/kWh',2]
 ];
 const settingsEl=$('lm-ess-settings');if(settingsEl)settingsEl.innerHTML=settings.map(([n,k,v,u,d])=>`<div><dt>${esc(n)} ${tip(k)}</dt><dd>${value(v,unit(s,k,u),d)}</dd></div>`).join('');
 setText('lm-delay-window',dc.start&&dc.stop?dc.start+' – '+dc.stop:'—');
 setText('lm-morning-hours',Array.isArray(tm.morningPeakHours)?tm.morningPeakHours.map(h=>String(h).padStart(2,'0')+':00').join(', '):'—');
 setText('lm-evening-hours',Array.isArray(tm.eveningPeakHours)?tm.eveningPeakHours.map(h=>String(h).padStart(2,'0')+':00').join(', '):'—');
}
function upsInfo(u){
 const runtime=isValue(u.battery?.runtimeSec)?Math.round(Number(u.battery.runtimeSec)/60)+' min':'—';
 const flags=[];if(u.status?.lowBattery)flags.push(t(APP,'LOW BATTERY'));if(u.status?.charging)flags.push(t(APP,'CHARGING'));if(u.status?.discharging)flags.push(t(APP,'DISCHARGING'));if(u.status?.overload)flags.push(t(APP,'OVERLOAD'));if(u.status?.replaceBattery)flags.push(t(APP,'REPLACE BATTERY'));if(u.status?.bypass)flags.push(t(APP,'BYPASS'));
 const errorFlags=[];if(u.online===false)errorFlags.push(t(APP,'OFFLINE'));if(u.status?.lowBattery)errorFlags.push(t(APP,'LOW BATTERY'));if(u.status?.overload)errorFlags.push(t(APP,'OVERLOAD'));if(u.status?.replaceBattery)errorFlags.push(t(APP,'REPLACE BATTERY'));
 const state=u.onBattery?t(APP,'BATTERY'):u.online?t(APP,'ONLINE'):t(APP,'OFFLINE');
 const cls=u.online&&!u.onBattery?'on':u.onBattery?'warn':'off';
 return {runtime,flags,errorFlags,state,cls};
}
function renderUpsSummary(u,s){
 const z=upsInfo(u),load=value(u.load?.percent,'%',0),err=z.errorFlags.length?z.errorFlags.join(' · '):t(APP,'OK');
 return `<div class="lm-ups-basic" title="${esc(HELP.ups)}"><div class="lm-ups-basic-name"><strong>🔋 ${esc(u.name||'UPS')}</strong><small>${esc(u.status?.raw||'—')}</small></div><div class="lm-ups-basic-item"><span>${esc(t(APP,'Status'))}</span><b class="lm-state ${z.cls}">${esc(z.state)}</b></div><div class="lm-ups-basic-item"><span>${esc(t(APP,'Load'))}</span><b>${load}</b></div><div class="lm-ups-basic-item lm-ups-basic-error ${z.errorFlags.length?'warn':'ok'}"><span>${esc(t(APP,'Error'))}</span><b>${esc(err)}</b></div></div>`;
}
function renderUpsDetails(u,s){
 const z=upsInfo(u);
 return `<div class="lm-ups" title="${esc(HELP.ups)}"><div class="lm-ups-flags"><span>${esc(t(APP,'Status'))}</span><strong>${esc(u.status?.raw||'—')}${z.flags.length?' · '+esc(z.flags.join(' · ')):''}</strong></div><div class="lm-mini-grid"><div><span>${esc(t(APP,'Charge'))}</span><strong>${value(u.battery?.chargePct,'%',0)}</strong></div><div><span>${esc(t(APP,'Runtime'))}</span><strong>${z.runtime}</strong></div><div><span>${esc(t(APP,'Load'))}</span><strong>${value(u.load?.percent,'%',0)}</strong></div><div><span>${esc(t(APP,'Power'))}</span><strong>${value(u.load?.realPowerW,'W',0)}</strong></div><div><span>${esc(t(APP,'Input'))}</span><strong>${value(u.input?.voltageV,'V',0)}</strong></div><div><span>${esc(t(APP,'Output'))}</span><strong>${value(u.output?.voltageV,'V',0)}</strong></div><div><span>${esc(t(APP,'Frequency'))}</span><strong>${value(u.output?.frequencyHz,'Hz',1)}</strong></div><div><span>${esc(t(APP,'Battery voltage'))}</span><strong>${value(u.battery?.voltageV,'V',1)}</strong></div></div></div>`;
}
function renderClimate(x,s){
 const en=x.energy||{};const state=x.error||x.cloudUp===false?'warn':x.on?'on':'off';const stateText=x.error?t(APP,'ERROR'):x.cloudUp===false?t(APP,'CLOUD OFF'):x.on?t(APP,'ON'):t(APP,'OFF');
 const mode=String(x.operationMode||'—');
 const bars=[[t(APP,'Today'),en.todayKWh],[t(APP,'Week'),en.weekKWh],[t(APP,'Month'),en.monthKWh],[t(APP,'Cooling month'),en.coolingMonthKWh],[t(APP,'Heating month'),en.heatingMonthKWh]];const nums=bars.map(v=>isValue(v[1])?Math.max(0,Number(v[1])):0),max=Math.max(1,...nums);const barHtml=bars.map(([label,v],i)=>`<div class="lm-energy-bar"><span>${esc(label)}</span><i><em style="width:${Math.max(2,nums[i]/max*100).toFixed(1)}%"></em></i><strong>${value(v,'kWh',1)}</strong></div>`).join('');
 return `<article class="lm-climate-device" title="${esc(HELP.climate)}"><div class="lm-climate-summary"><div class="lm-climate-name"><strong>${esc(x.name||t(APP,'Climate'))}</strong><small>${esc(t(APP,'Mode'))}: ${esc(mode)}</small></div><div class="lm-climate-temp"><strong>${value(x.roomTemperatureC,'°C',1)}</strong><small>${esc(t(APP,'room'))}</small></div><div class="lm-climate-outdoor"><strong>${value(x.outdoorTemperatureC,'°C',1)}</strong><small>${esc(t(APP,'outside'))}</small></div><div class="lm-climate-target"><strong>${value(x.setpointC,'°C',1)}</strong><small>${esc(t(APP,'Target'))}</small></div><span class="lm-state ${state}">${stateText}</span></div><details class="lm-details lm-climate-details"><summary>${esc(t(APP,'Statistics and device details'))}</summary><div class="lm-climate-body"><div class="lm-energy-bars">${barHtml}</div><div class="lm-climate-meta"><span>${esc(t(APP,'Firmware'))}</span><strong>${esc(x.firmwareVersion||'—')}${x.firmwareChanged?' ⚠':''}</strong><span>${esc(t(APP,'Cloud'))}</span><strong>${x.cloudUp===true?'OK':x.cloudUp===false?t(APP,'Unavailable'):'—'}</strong></div></div></details>${x.error===true?`<div class="lm-inline-warning">${esc(x.errorCode||t(APP,'Climate error'))}</div>`:''}${x.firmwareChanged===true?'<div class="lm-inline-warning">'+esc(t(APP,'Firmware changed'))+'</div>':''}</article>`;
}

function localDateHourTs(date,hour){
 if(!date||!Number.isFinite(Number(hour)))return null;
 const hh=String(Math.max(0,Math.min(23,Number(hour)))).padStart(2,'0');
 const d=new Date(String(date)+'T'+hh+':00:00');
 return Number.isFinite(d.getTime())?Math.floor(d.getTime()/1000):null;
}
function spotSchedulePoints(spot){
 const out=[];
 for(const day of [spot?.today,spot?.tomorrow]){
  if(day?.available!==true||!Array.isArray(day.prices))continue;
  for(const row of day.prices){const ts=localDateHourTs(day.date,row?.hour);if(ts!==null&&isValue(row?.price))out.push({ts,price:Number(row.price),day:day===spot.today?'today':'tomorrow'});}
 }
 return out.sort((a,b)=>a.ts-b.ts);
}
function forecastSeriesPoints(forecast,key){
 const interval=Math.max(1,Number(forecast?.intervalMinutes)||15),rows=forecast?.series?.[key];if(!Array.isArray(rows))return[];
 return rows.map(r=>{const ms=isValue(r?.timestampMs)?Number(r.timestampMs):(r?.timestamp?new Date(r.timestamp).getTime():NaN);const e=isValue(r?.energyKWh)?Number(r.energyKWh):null;return Number.isFinite(ms)&&e!==null?{ts:Math.floor(ms/1000),energyKWh:e,powerW:e*1000*60/interval}:null;}).filter(Boolean).sort((a,b)=>a.ts-b.ts);
}
function forecastCorrectionFactor(status,solarPoints,now=Date.now()/1000){
 const actual=status?.vrm?.data?.today?.pvYieldKWh;if(!isValue(actual)||!solarPoints.length)return 1;
 const d=new Date(now*1000),dayStart=new Date(d.getFullYear(),d.getMonth(),d.getDate()).getTime()/1000;
 const predicted=solarPoints.filter(p=>p.ts>=dayStart&&p.ts<=now).reduce((a,p)=>a+Number(p.energyKWh||0),0);
 if(predicted<0.35)return 1;
 return Math.max(.45,Math.min(1.65,Number(actual)/predicted));
}
function stepPath(points,key,x,y,intervalSec=3600){if(!points.length)return'';let d='',active=false;for(let i=0;i<points.length;i++){const p=points[i],v=p[key];if(!isValue(v)){active=false;continue;}const px=x(p.ts),py=y(Number(v));if(!active){d+=` M ${px.toFixed(1)} ${py.toFixed(1)}`;active=true;}const next=points[i+1],endTs=next?next.ts:p.ts+intervalSec;d+=` H ${x(endTs).toFixed(1)}`;if(next&&isValue(next[key]))d+=` V ${y(Number(next[key])).toFixed(1)}`;else active=false;}return d.trim();}
function renderSpotSchedule(status){
 const el=$('lm-spot-price-chart');if(!el)return;
 const spot=blockAvailable(status?.spot)?status.spot:null;
 if(!spot){el.innerHTML='<div class="lm-empty">'+esc(t(APP,'Hourly SPOT schedule is not available yet.'))+'</div>';return;}
 const today=spotSchedulePoints({today:spot.today,tomorrow:null});
 const tomorrow=spotSchedulePoints({today:null,tomorrow:spot.tomorrow});
 const all=[...today,...tomorrow].sort((a,b)=>a.ts-b.ts);
 if(!all.length){el.innerHTML='<div class="lm-empty">'+esc(t(APP,'Hourly SPOT schedule is not available yet.'))+'</div>';return;}
 const intervalSec=Math.max(1,Number(spot.intervalMinutes)||60)*60;
 const availableWidth=Math.floor(el.clientWidth||oBootstrapRoot.clientWidth||window.innerWidth);
 const mobile=availableWidth<760;
 const W=mobile?Math.max(260,availableWidth):760,H=mobile?180:190,L=mobile?46:54,R=mobile?38:16,T=24,B=mobile?38:40,plotW=W-L-R,plotH=H-T-B;
 const t0=all[0].ts,t1=all[all.length-1].ts+intervalSec;
 const vals=all.map(p=>p.price),min=Math.min(0,...vals),max=Math.max(0,...vals),pad=Math.max(.15,(max-min)*.1),lo=min-pad,hi=max+pad;
 const x=ts=>L+(ts-t0)/(t1-t0||1)*plotW,y=v=>T+(hi-v)/(hi-lo||1)*plotH;
 let grid='';for(let i=0;i<=4;i++){const v=hi-(hi-lo)*i/4,py=y(v);grid+=`<line x1="${L}" y1="${py}" x2="${W-R}" y2="${py}" class="lm-spot-mini-grid"/><text x="${L-5}" y="${py+4}" class="lm-spot-mini-y">${v.toFixed(2)}</text>`;}
 const tickCount=mobile?2:4;
 let ticks='';for(let i=0;i<=tickCount;i++){const ts=t0+(t1-t0)*i/tickCount,px=x(ts);const label=new Date(ts*1000).toLocaleString([],{day:'numeric',month:'numeric',hour:'2-digit',...(mobile?{}:{minute:'2-digit'})});ticks+=`<text x="${px}" y="${H-8}" class="lm-spot-mini-x">${esc(label)}</text>`;}
 const midnight=tomorrow.length?tomorrow[0].ts:null;
 const divider=midnight!==null&&today.length?`<line x1="${x(midnight)}" y1="${T}" x2="${x(midnight)}" y2="${H-B}" class="lm-spot-midnight"/>`:'';
 const shade=midnight!==null&&today.length?`<rect x="${x(midnight)}" y="${T}" width="${Math.max(0,W-R-x(midnight))}" height="${plotH}" class="lm-spot-tomorrow-shade"/>`:'';
 const now=Date.now()/1000,nowLine=now>=t0&&now<=t1?`<line x1="${x(now)}" y1="${T}" x2="${x(now)}" y2="${H-B}" class="lm-spot-mini-now"/>`:'';
 const marker=all.map(p=>`<rect x="${x(p.ts)}" y="${T}" width="${Math.max(2,x(p.ts+intervalSec)-x(p.ts))}" height="${plotH}" fill="transparent"><title>${esc(new Date(p.ts*1000).toLocaleString())} · ${p.price.toFixed(2)} CZK/kWh</title></rect>`).join('');
 el.innerHTML=`<div class="lm-spot-schedule-head"><strong>${esc(t(APP,'Hourly SPOT prices'))}</strong><span>${tomorrow.length?esc(t(APP,'Tomorrow prices available')):esc(t(APP,'Tomorrow prices not published yet'))}</span></div><div class="lm-spot-combined-head"><span>${esc(spot.today?.date||spot.tomorrow?.date||'')}</span>${tomorrow.length?`<span>${esc(spot.tomorrow?.date||'')}</span>`:''}</div><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(t(APP,'Hourly SPOT prices'))}">${shade}${grid}<line x1="${L}" y1="${y(0)}" x2="${W-R}" y2="${y(0)}" class="lm-spot-zero"/>${divider}<path d="${stepPath(today,'price',x,y,intervalSec)}" class="lm-spot-mini-line"/><path d="${stepPath(tomorrow,'price',x,y,intervalSec)}" class="lm-spot-mini-line lm-spot-tomorrow-line"/>${nowLine}${ticks}${marker}</svg>`;
}
function renderForecastAvailability(status){
 const el=$('lm-forecast-series-note');if(!el)return;const f=blockAvailable(status?.forecast)?status.forecast:null;if(!f){el.textContent=t(APP,'Detailed VRM forecast is unavailable.');return;}
 const pv=forecastSeriesPoints(f,'solarYield'),cons=forecastSeriesPoints(f,'consumption'),factor=forecastCorrectionFactor(status,pv);
 el.textContent=t(APP,'VRM forecast')+' · '+(Number(f.intervalMinutes)||15)+' min · '+pv.length+' / '+cons.length+' '+t(APP,'points')+(Math.abs(factor-1)>.03?' · '+t(APP,'PV correction')+' '+factor.toFixed(2)+'×':'');
}

function pragueDay(){const parts=new Intl.DateTimeFormat('en-US',{timeZone:'Europe/Prague',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());const part=key=>parts.find(p=>p.type===key)?.value||'';return `${part('year')}-${part('month')}-${part('day')}`;}
async function loadDailyRevenue(force=false){
 if(dailyLoading||bDisposed)return;
 const day=pragueDay();
 if(day!==lastDailyDay){setText('lm-spot-sale-value',t(APP,'Today')+' —');lastDailyFetchAt=0;}
 if(!force&&Date.now()-lastDailyFetchAt<60000)return;
 dailyLoading=true;lastDailyFetchAt=Date.now();
 try{
  const r=await api('/api/history/daily');if(bDisposed)return;
  lastDailyDay=String(r.data?.day||'');
  if(lastDailyDay!==pragueDay()){setText('lm-spot-sale-value',t(APP,'Today')+' —');lastDailyFetchAt=0;return;}
  const d=r.data||{},complete=d.hasData&&Number(d.unpricedExportKWh)===0;
  setText('lm-spot-sale-value',t(APP,'Today')+' '+(complete&&isValue(d.saleCzk)?Number(d.saleCzk).toFixed(2)+' Kč':'—'));
  setTitle('lm-spot-sale-value',d.hasData&&Number(d.unpricedExportKWh)>0
   ?t(APP,'SPOT price is missing for part of today’s export; a complete sale amount cannot be shown.')
   :t(APP,'Sum of measured export multiplied by the SPOT price at each sample. Updated after each completed five-minute interval.'));
 }catch(_){if(!bDisposed){setText('lm-spot-sale-value',t(APP,'Today')+' —');setTitle('lm-spot-sale-value',t(APP,'Daily sale data unavailable.'));}}
 finally{dailyLoading=false;}
}
async function loadStatus(){if(loading)return;loading=true;try{const r=await api('/api/linea/status');renderStatus(r.data);$('lm-error').hidden=true;void loadDailyRevenue();}catch(e){const el=$('lm-error');el.textContent=e.message;el.hidden=false;const c=$('lm-connection');c.className='lm-status lm-status-error';c.innerHTML='<span class="lm-dot"></span>'+esc(t(APP,'LINEA offline'));}finally{loading=false;}}

let historyRange='24h',historyLoading=false,historyLoaded=false;
const historyNum=(v,d=2)=>isValue(v)?Number(v).toFixed(d):'—';
function formatHistoryTime(ts,withDate=false){const d=new Date(Number(ts)*1000);if(!Number.isFinite(d.getTime()))return'—';return withDate?d.toLocaleString():d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});}
function polylinePath(points,key,x,y){let d='',started=false;for(const p of points){const v=p[key];if(!isValue(v)){started=false;continue;}const px=x(Number(p.ts)),py=y(Number(v));d+=(started?' L ':' M ')+px.toFixed(1)+' '+py.toFixed(1);started=true;}return d.trim();}
function smoothHistoryPoints(points,keys){
 const out=(points||[]).map(p=>({...p}));
 for(const key of keys){
  for(let i=0;i<out.length;i++){
   const c=points[i]?.[key];if(!isValue(c))continue;
   const prev=i>0&&isValue(points[i-1]?.[key])?Number(points[i-1][key]):Number(c);
   const next=i+1<points.length&&isValue(points[i+1]?.[key])?Number(points[i+1][key]):Number(c);
   out[i][key]=prev*.25+Number(c)*.5+next*.25;
  }
 }
 return out;
}
const HISTORY_SERIES=[
 ['pv','PV'],['house','House'],['grid','Grid'],['export','Sale power'],['battery','Battery'],['soc','Battery SOC'],['spot','SPOT'],
 ['batteryTemp','Battery temperature'],['rackTemp','Rack temperature'],
 ['inverter1Temp','Inverter 1'],['inverter2Temp','Inverter 2'],['inverter3Temp','Inverter 3'],
 ['forecastPv','PV forecast'],['forecastHouse','Consumption forecast']
];
let historyPoints=[],historyVisible=Object.fromEntries(HISTORY_SERIES.map(([key])=>[key,true])),historyAllowed={...historyVisible},historyZoom=null,historySelectedTs=null,historyChartStatus=null,historyChartNow=null;
const TEMPERATURE_SERIES=['batteryTemp','rackTemp','inverter1Temp','inverter2Temp','inverter3Temp'];
function updateTemperatureLegend(points){
 for(const series of TEMPERATURE_SERIES){
  const btn=document.querySelector(`[data-series="${series}"]`);
  if(!btn)continue;
  btn.hidden=!historyAllowed[series]||!points.some(p=>isValue(p[series+'C']));
  btn.disabled=false;
 }
}
function applyHistoryPreferences(values){
 for(const [key] of HISTORY_SERIES){
  const allowed=values['chart_'+key]!==false,wasAllowed=historyAllowed[key];
  historyAllowed[key]=allowed;
  if(!allowed)historyVisible[key]=false;
  else if(!wasAllowed)historyVisible[key]=true;
  const btn=document.querySelector(`[data-series="${key}"]`);
  if(btn){btn.hidden=!allowed;btn.classList.toggle('active',historyVisible[key]);btn.setAttribute('aria-pressed',String(historyVisible[key]));}
 }
 if(activeTab()==='history'&&historyPoints.length>1)renderPowerChart(historyPoints);
}
function renderPowerChart(points){
 const el=$('lm-history-power-chart');if(!el)return;historyPoints=Array.isArray(points)?points:[];
 let shown=historyPoints;if(historyZoom)shown=shown.filter(p=>Number(p.ts)>=historyZoom[0]&&Number(p.ts)<=historyZoom[1]);
 updateTemperatureLegend(shown);
 if(shown.length<2){el.innerHTML='<div class="lm-empty">'+esc(t(APP,'There are not yet at least two completed five-minute intervals. The fast collector creates them automatically from approximately one-second data.'))+'</div>';return;}
 const status=historyChartStatus||latestStatus||{},forecast=blockAvailable(status.forecast)?status.forecast:null,spotBlock=blockAvailable(status.spot)?status.spot:null;
 const pvFcRaw=forecastSeriesPoints(forecast,'solarYield'),houseFc=forecastSeriesPoints(forecast,'consumption'),nowTs=historyChartNow??Date.now()/1000,pvFactor=forecastCorrectionFactor(status,pvFcRaw,nowTs);
 const pvFc=pvFcRaw.map(p=>({...p,powerW:p.ts>=nowTs?p.powerW*pvFactor:p.powerW})),spotFc=spotSchedulePoints(spotBlock);
 const availableWidth=Math.floor(el.clientWidth||oBootstrapRoot.clientWidth||window.innerWidth),mobile=availableWidth<760;
 const W=Math.max(220,availableWidth),H=mobile?348:Math.min(490,Math.max(365,Math.round(W*.31)));
 const L=mobile?57:78,R=mobile?108:130,T=26,B=mobile?41:52;
 const tempKeys=['batteryTempC','rackTempC','inverter1TempC','inverter2TempC','inverter3TempC'];
 const hasTemps=shown.some(p=>tempKeys.some(key=>isValue(p[key])));
 const temperatureLane=hasTemps?86:0,plotW=W-L-R,plotH=H-T-B-temperatureLane,tempTop=T+plotH+19,tempH=temperatureLane-27;
 const raw=shown.map(p=>({...p,exportW:isValue(p.gridW)?Math.max(0,-Number(p.gridW)):null}));
 const keys=[['pvW','pv'],['houseW','house'],['gridW','grid'],['exportW','export'],['batteryW','battery']],visual=smoothHistoryPoints(raw,keys.map(([k])=>k)),values=[];
 // Fix the data domain for this range; legend switches must never rescale it.
 for(const p of raw)for(const [k] of keys)if(isValue(p[k]))values.push(Number(p[k]));
 for(const p of pvFc)if(isValue(p.powerW))values.push(Number(p.powerW));
 for(const p of houseFc)if(isValue(p.powerW))values.push(Number(p.powerW));
 let min=values.length?Math.min(0,...values):-1,max=values.length?Math.max(0,...values):1;if(max===min){max+=1;min-=1;}const pad=(max-min)*.08;max+=pad;min-=pad;
 const histSpot=shown.filter(p=>isValue(p.spotPrice)).map(p=>Number(p.spotPrice)),allSpot=[...histSpot,...spotFc.map(p=>p.price)];let smin=allSpot.length?Math.min(...allSpot):0,smax=allSpot.length?Math.max(...allSpot):1;if(smax===smin){smax+=.5;smin-=.5;}const spad=(smax-smin)*.08;smax+=spad;smin-=spad;
 let t0=Number(shown[0].ts),t1=Number(shown[shown.length-1].ts)||t0+1;
 if(!historyZoom){const horizons=[];if(pvFc.length)horizons.push(pvFc[pvFc.length-1].ts);if(houseFc.length)horizons.push(houseFc[houseFc.length-1].ts);if(spotFc.length)horizons.push(spotFc[spotFc.length-1].ts+(Number(spotBlock?.intervalMinutes)||60)*60);if(horizons.length)t1=Math.max(t1,...horizons);}
 const within=arr=>arr.filter(p=>p.ts>=t0&&p.ts<=t1),pvF=within(pvFc),houseF=within(houseFc),spotF=within(spotFc);
 const x=t=>L+((t-t0)/(t1-t0||1))*plotW,y=v=>T+((max-v)/(max-min))*plotH,ys=v=>T+((smax-v)/(smax-smin))*plotH,ysoc=v=>T+((100-Math.max(0,Math.min(100,v)))/100)*plotH;
 let grid='';for(let i=0;i<=5;i++){const v=max-(max-min)*i/5,py=y(v);grid+=`<line x1="${L}" y1="${py}" x2="${W-R}" y2="${py}" class="lm-chart-grid"/><text x="${L-8}" y="${py+4}" class="lm-chart-y">${Math.round(v)} W</text>`;if(i%2===0){const pct=100-i*20;grid+=`<text x="${W-R+9}" y="${py+4}" class="lm-chart-y lm-chart-y-soc">${pct}%</text>`;}if(allSpot.length&&i%2===0){const sv=smax-(smax-smin)*i/5;grid+=`<text x="${W-45}" y="${py+4}" class="lm-chart-y lm-chart-y-right">${sv.toFixed(2)}</text>`;}}
 const tickCount=mobile?2:Math.max(4,Math.min(8,Math.floor(W/210)));
 let xt='';for(let i=0;i<=tickCount;i++){const ts=t0+(t1-t0)*i/tickCount,px=x(ts);const label=mobile&&t1-t0>86400?new Date(ts*1000).toLocaleDateString([],{day:'numeric',month:'numeric'}):formatHistoryTime(ts,(t1-t0)>86400);xt+=`<line x1="${px}" y1="${T}" x2="${px}" y2="${H-B}" class="lm-chart-grid lm-chart-grid-v"/><text x="${px}" y="${H-12}" class="lm-chart-x">${esc(label)}</text>`;}
 let paths=keys.filter(([k,c])=>historyVisible[c]).map(([k,c])=>`<path d="${polylinePath(visual,k,x,y)}" class="lm-chart-line ${c}"/>`).join('');
 if(historyVisible.soc)paths+=`<path d="${polylinePath(shown,'socPct',x,ysoc)}" class="lm-chart-line soc"/>`;
 if(historyVisible.spot){paths+=`<path d="${stepPath(shown,'spotPrice',x,ys,Number(shown[0]?.resolutionSec)||3600)}" class="lm-chart-line spot"/>`;if(spotF.length)paths+=`<path d="${stepPath(spotF,'price',x,ys,(Number(spotBlock?.intervalMinutes)||60)*60)}" class="lm-chart-line spot-forecast"/>`;}
 if(historyVisible.forecastPv&&pvF.length)paths+=`<path d="${polylinePath(pvF,'powerW',x,y)}" class="lm-chart-line forecast-pv"/>`;
 if(historyVisible.forecastHouse&&houseF.length)paths+=`<path d="${polylinePath(houseF,'powerW',x,y)}" class="lm-chart-line forecast-house"/>`;
 if(hasTemps){
  const temps=shown.flatMap(p=>tempKeys.map(key=>p[key])).filter(isValue).map(Number),low=Math.floor((Math.min(...temps)-2)/5)*5,high=Math.ceil((Math.max(...temps)+2)/5)*5;
  const yt=v=>tempTop+((high-Number(v))/(high-low||1))*tempH;
  paths+=`<line x1="${L}" y1="${tempTop-8}" x2="${W-R}" y2="${tempTop-8}" class="lm-chart-grid"/><text x="${L-8}" y="${tempTop+8}" class="lm-chart-y">${high}°C</text><text x="${L-8}" y="${tempTop+tempH}" class="lm-chart-y">${low}°C</text>`;
  if(historyVisible.batteryTemp)paths+=`<path d="${polylinePath(shown,'batteryTempC',x,yt)}" class="lm-chart-line battery-temp"/>`;
  if(historyVisible.rackTemp)paths+=`<path d="${polylinePath(shown,'rackTempC',x,yt)}" class="lm-chart-line rack-temp"/>`;
  for(const i of [1,2,3])if(historyVisible['inverter'+i+'Temp'])paths+=`<path d="${polylinePath(shown,'inverter'+i+'TempC',x,yt)}" class="lm-chart-line inverter-${i}-temp"/>`;
 }
 const nowX=(nowTs>=t0&&nowTs<=t1)?x(nowTs):null,futureShade=nowX!==null?`<rect x="${nowX}" y="${T}" width="${Math.max(0,W-R-nowX)}" height="${plotH}" class="lm-chart-future"/><line x1="${nowX}" y1="${T}" x2="${nowX}" y2="${H-B}" class="lm-chart-now"/>`:'';
 el.innerHTML=`<div class="lm-chart-stage"><svg class="lm-history-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(t(APP,'Energy flow history'))}"><text x="${W-R+9}" y="14" class="lm-chart-axis-title lm-chart-axis-soc">%</text><text x="${W-6}" y="14" class="lm-chart-axis-title">CZK/kWh</text>${grid}${xt}${futureShade}<line x1="${L}" y1="${y(0)}" x2="${W-R}" y2="${y(0)}" class="lm-chart-zero"/>${paths}<line id="lm-chart-cursor" class="lm-chart-cursor" x1="0" y1="${T}" x2="0" y2="${H-B}" visibility="hidden"/><rect id="lm-chart-selection" class="lm-chart-selection" x="0" y="${T}" width="0" height="${plotH}" visibility="hidden"/><rect class="lm-chart-hit" x="${L}" y="${T}" width="${plotW}" height="${H-B-T}"/></svg></div><div id="lm-chart-tooltip" class="lm-chart-tooltip lm-chart-tooltip-below" role="status" aria-live="polite">${esc(t(APP,'Tap a point in the chart to inspect values.'))}</div><div class="lm-chart-hint">${esc(t(APP,'Tap to inspect values; drag with a mouse to zoom.'))}</div>`;
 const svg=el.querySelector('svg'),hit=el.querySelector('.lm-chart-hit'),cursor=$('lm-chart-cursor'),tip=$('lm-chart-tooltip'),sel=$('lm-chart-selection');let dragStart=null;
 const svgPoint=e=>{try{const p=svg.createSVGPoint();p.x=e.clientX;p.y=e.clientY;const m=svg.getScreenCTM();if(m){const q=p.matrixTransform(m.inverse());return{x:q.x,y:q.y};}}catch(_){}const r=svg.getBoundingClientRect();return{x:(e.clientX-r.left)*W/r.width,y:(e.clientY-r.top)*H/r.height};};
 const nearestByTs=(arr,ts,tol)=>{if(!arr.length)return null;const p=arr.reduce((a,b)=>Math.abs(Number(b.ts)-ts)<Math.abs(Number(a.ts)-ts)?b:a);return Math.abs(Number(p.ts)-ts)<=tol?p:null;};
 const updateReadout=ts=>{
  historySelectedTs=ts;const clampedX=x(ts);cursor.setAttribute('x1',clampedX);cursor.setAttribute('x2',clampedX);cursor.setAttribute('visibility','visible');
  const p=nearestByTs(shown,ts,Math.max(450,Number(shown[0]?.resolutionSec||300)*1.5)),pf=nearestByTs(pvF,ts,Math.max(900,(Number(forecast?.intervalMinutes)||15)*60)),hf=nearestByTs(houseF,ts,Math.max(900,(Number(forecast?.intervalMinutes)||15)*60)),sf=nearestByTs(spotF,ts,2400);const sale=p&&isValue(p.saleCzk)?Number(p.saleCzk):null;
  const rows=[
   historyAllowed.pv?`<span>☀ FVE <b>${p?historyNum(p.pvW,0):'—'} W</b></span>`:null,
   historyAllowed.house?`<span>🏠 ${esc(t(APP,'House'))} <b>${p?historyNum(p.houseW,0):'—'} W</b></span>`:null,
   historyAllowed.grid?`<span>⚡ ${esc(t(APP,'Grid'))} <b>${p?historyNum(p.gridW,0):'—'} W</b></span>`:null,
   historyAllowed.export?`<span>↑ ${esc(t(APP,'Sale power'))} <b>${p&&isValue(p.gridW)?historyNum(Math.max(0,-Number(p.gridW)),0):'—'} W</b></span>`:null,
   historyAllowed.battery?`<span>🔋 ${esc(t(APP,'Battery'))} <b>${p?historyNum(p.batteryW,0):'—'} W</b></span>`:null,
   historyAllowed.soc?`<span>🔋 SOC <b>${p?historyNum(p.socPct,1):'—'} %</b></span>`:null,
   historyAllowed.spot?`<span>💰 SPOT <b>${p?historyNum(p.spotPrice,2):'—'} CZK/kWh</b></span>`:null,
   historyAllowed.batteryTemp&&shown.some(point=>isValue(point.batteryTempC))?`<span>🌡 ${esc(t(APP,'Battery temperature'))} <b>${p?historyNum(p.batteryTempC,1):'—'} °C</b></span>`:null,
   historyAllowed.rackTemp&&shown.some(point=>isValue(point.rackTempC))?`<span>🌡 ${esc(t(APP,'Rack temperature'))} <b>${p?historyNum(p.rackTempC,1):'—'} °C</b></span>`:null,
   ...[1,2,3].map(i=>historyAllowed['inverter'+i+'Temp']&&shown.some(point=>isValue(point['inverter'+i+'TempC']))?`<span>🌡 ${esc(t(APP,'Inverter'))} ${i} <b>${p?historyNum(p['inverter'+i+'TempC'],1):'—'} °C</b></span>`:null),
   `<span>↑ ${esc(t(APP,'Sale in interval'))} <b>${p?historyNum(p.gridExportKWh,3):'—'} kWh</b></span>`,
   `<span>💵 ${esc(t(APP,'Sale value'))} <b>${sale===null?'—':sale.toFixed(3)} CZK</b></span>`,
   historyAllowed.forecastPv?`<span>☀ ${esc(t(APP,'PV forecast'))} <b>${pf?historyNum(pf.powerW,0):'—'} W</b></span>`:null,
   historyAllowed.forecastHouse?`<span>🏠 ${esc(t(APP,'Consumption forecast'))} <b>${hf?historyNum(hf.powerW,0):'—'} W</b></span>`:null,
   historyAllowed.spot?`<span>💰 ${esc(t(APP,'SPOT schedule'))} <b>${sf?historyNum(sf.price,2):'—'} CZK/kWh</b></span>`:null
  ];
  tip.innerHTML=`<strong>${esc(formatHistoryTime(ts,true))}</strong>${rows.filter(Boolean).join('')}`;
 };
 const selectAt=e=>{const px=Math.max(L,Math.min(W-R,svgPoint(e).x));updateReadout(t0+((px-L)/plotW)*(t1-t0));if(dragStart!==null){const a=Math.min(dragStart,px),b=Math.max(dragStart,px);sel.setAttribute('x',a);sel.setAttribute('width',b-a);sel.setAttribute('visibility','visible');}};
 hit.addEventListener('pointerdown',e=>{selectAt(e);if(e.pointerType==='touch')return;dragStart=Math.max(L,Math.min(W-R,svgPoint(e).x));hit.setPointerCapture?.(e.pointerId);});
 hit.addEventListener('pointermove',e=>{if(e.pointerType!=='touch'||e.buttons)selectAt(e);});
 hit.addEventListener('pointerup',e=>{if(e.pointerType==='touch'){selectAt(e);return;}if(dragStart===null)return;const end=Math.max(L,Math.min(W-R,svgPoint(e).x));if(Math.abs(end-dragStart)>25){const a=Math.min(dragStart,end),b=Math.max(dragStart,end);historyZoom=[t0+((a-L)/plotW)*(t1-t0),t0+((b-L)/plotW)*(t1-t0)];$('lm-history-zoom-reset').hidden=false;}dragStart=null;renderPowerChart(historyPoints);});
 if(historySelectedTs!==null&&historySelectedTs>=t0&&historySelectedTs<=t1)updateReadout(historySelectedTs);
}
function renderSocChart(points){
 const el=$('lm-history-soc-chart');if(!el)return;const valid=(points||[]).filter(p=>isValue(p.socPct));if(valid.length<2){el.innerHTML='<div class="lm-empty">'+esc(t(APP,'No SOC data yet.'))+'</div>';return;}
 const W=1000,H=220,L=58,R=18,T=16,B=42,plotW=W-L-R,plotH=H-T-B,t0=Number(valid[0].ts),t1=Number(valid[valid.length-1].ts)||t0+1;
 const x=t=>L+((t-t0)/(t1-t0||1))*plotW,y=v=>T+((100-v)/100)*plotH;let grid='';for(const v of [0,25,50,75,100]){const py=y(v);grid+=`<line x1="${L}" y1="${py}" x2="${W-R}" y2="${py}" class="lm-chart-grid"/><text x="${L-9}" y="${py+4}" class="lm-chart-y">${v}%</text>`;}
 let xt='';for(let i=0;i<=4;i++){const ts=t0+(t1-t0)*i/4,px=x(ts);xt+=`<text x="${px}" y="${H-13}" class="lm-chart-x">${esc(formatHistoryTime(ts,(t1-t0)>86400))}</text>`;}
 el.innerHTML=`<svg class="lm-history-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(t(APP,'Historical battery state of charge'))}">${grid}${xt}<path d="${polylinePath(valid,'socPct',x,y)}" class="lm-chart-line soc"/></svg>`;
}
function renderHistoryEvents(events){const el=$('lm-history-events');if(!el)return;if(!Array.isArray(events)||!events.length){el.innerHTML='<div class="lm-empty">'+esc(t(APP,'No events yet.'))+'</div>';return;}el.innerHTML=events.map(ev=>`<div class="lm-event ${esc(ev.severity||'info')}"><time>${esc(new Date(Number(ev.ts)*1000).toLocaleString())}</time><strong>${esc(ev.title||ev.type||t(APP,'Event'))}</strong><small>${esc(ev.oldValue??'')} ${ev.oldValue!==null&&ev.newValue!==null?'→':''} ${esc(ev.newValue??'')}</small></div>`).join('');}
function renderHistory(data){
 const points=data?.points||[],sum=data?.summary||{},collector=data?.collector||{};
 setText('lm-h-pv-kwh',historyNum(sum.pvKWh));setText('lm-h-export-value',historyNum(sum.spotSaleValue,2));setText('lm-h-house-kwh',historyNum(sum.houseKWh));setText('lm-h-import-kwh',historyNum(sum.gridImportKWh));setText('lm-h-export-kwh',historyNum(sum.gridExportKWh));setText('lm-h-batt-charge-kwh',historyNum(sum.batteryChargeKWh));setText('lm-h-batt-discharge-kwh',historyNum(sum.batteryDischargeKWh));
 setText('lm-history-meta',`${points.length} ${t(APP,'points')} · ${data.resolutionSec||'—'} s`);setText('lm-history-resolution',(data.resolutionSec||'—')+' s');setText('lm-history-count',String(collector.fiveMinuteBuckets??collector.rawSamples??'—'));setText('lm-history-last',collector.lastCollectedAt?new Date(Number(collector.lastCollectedAt)*1000).toLocaleString():'—');
 const db=collector.database||{};const formatBytes=(bytes)=>{if(!isValue(bytes))return'—';let n=Number(bytes);if(n<1024)return n+' B';if(n<1024*1024)return (n/1024).toFixed(1)+' kB';if(n<1024*1024*1024)return (n/1024/1024).toFixed(1)+' MB';return (n/1024/1024/1024).toFixed(2)+' GB';};setText('lm-history-db-size',db.available===false?'—':formatBytes(db.bytes));setText('lm-history-db-size-checked',db.checkedAt?new Date(Number(db.checkedAt)*1000).toLocaleString():'—');
 const fast=collector.fastCollector||{};setText('lm-history-fast-state',!fast.online?t(APP,'OFFLINE'):fast.writeProblem?t(APP,'ONLINE, DB write missing'):t(APP,'ONLINE'));setText('lm-history-buffer',`${fast.bufferCount??'—'} / 3600`);setText('lm-history-polls',`${fast.pollOk??'—'} / ${fast.pollErrors??'—'}`);
 const st=$('lm-history-state');if(st){const active=collector.enabled&&fast.online&&!fast.writeProblem;st.textContent=!collector.enabled?t(APP,'Disabled'):fast.writeProblem?t(APP,'DB write problem'):fast.online?t(APP,'Active'):t(APP,'Collector offline');st.className='lm-pill '+(active?'lm-history-on':fast.writeProblem?'lm-history-bad':'');}
 const err=$('lm-history-error');if(err&&collector.enabled&&(fast.lastError||fast.writeProblem)){err.textContent=fast.lastError?(t(APP,'Fast collector:')+' '+fast.lastError):t(APP,'Collector is polling LINEA, but no 5-minute aggregate has been written recently. Restart the collector and inspect its log.');err.hidden=false;}
 historyChartStatus=latestStatus;historyChartNow=Date.now()/1000;
 historyPoints=points;if(activeTab()==='history')renderPowerChart(points);renderHistoryEvents(data?.events||[]);
}
async function loadHistory(force=false){if(historyLoading)return;if(historyLoaded&&!force)return;historyLoading=true;const err=$('lm-history-error');try{if(!latestStatus){try{const sr=await api('/api/linea/status');latestStatus=sr.data;}catch(_){}}const r=await api('/api/history?range='+encodeURIComponent(historyRange));renderHistory(r.data||{});if(err)err.hidden=true;historyLoaded=true;}catch(e){if(err){err.textContent=e.message;err.hidden=false;}}finally{historyLoading=false;}}

function schedule(){if(bDisposed)return;clearInterval(timer);timer=setInterval(loadStatus,Math.max(1,refreshSeconds)*1000);}
function activeTab(){return document.querySelector('.lm-tab.active')?.dataset.tab||'live';}
function formatCountdown(ms){const total=Math.max(0,Math.ceil(ms/1000));const m=Math.floor(total/60),sec=total%60;return `${m}:${String(sec).padStart(2,'0')}`;}
function updateAutoRefreshUi(){
 const label=$('lm-auto-refresh-countdown');if(!label)return;
 if(document.hidden){label.textContent=t(APP,'paused');return;}
 label.textContent=formatCountdown(nextSoftRefreshAt-Date.now());
}
function resetSoftRefreshClock(){nextSoftRefreshAt=Date.now()+SOFT_REFRESH_MS;updateAutoRefreshUi();}
async function softRefresh({fromVisibility=false}={}){
 if(document.hidden)return;
 const tab=activeTab();
 // LIVE already polls continuously, but do one explicit fresh read every five minutes.
 // HISTORY receives a full data refresh only when it is the active tab. This preserves
 // zoom, hidden/visible series and open details because those states live in JS/DOM.
 try{
  if(['history','collector','events'].includes(tab))await loadHistory(true);
  else await loadStatus();
 }finally{resetSoftRefreshClock();}
}
function scheduleSoftRefresh(){if(bDisposed)return;
 clearInterval(softRefreshTimer);clearInterval(softRefreshTick);
 resetSoftRefreshClock();
 softRefreshTimer=setInterval(()=>{if(!document.hidden)softRefresh();},SOFT_REFRESH_MS);
 softRefreshTick=setInterval(updateAutoRefreshUi,1000);
}
let bSettingsLoading=false,bSettingsReady=false;
let oAboutController=null;
let oSettingsFormController=null;
const oSettingsClient=CORE.settings.create(OC.generateUrl('/apps/'+APP+'/api/settings'),'hc_gridsight_main');
function cloneTemplate(id){
 const tpl=$(id);if(!(tpl instanceof HTMLTemplateElement))throw new Error('Missing page template: '+id);
 const fragment=tpl.content.cloneNode(true);const wrapper=document.createElement('div');wrapper.append(fragment);return wrapper;
}
async function openSettings(){
 if(bSettingsLoading||bSettingsReady)return;
 bSettingsLoading=true;
 let oValues;
 try{oValues=await oSettingsClient.load();}
 catch(oError){bSettingsLoading=false;CORE.notifications.error(oError.message,{dedupeKey:'hc_gridsight:settings-load'});LOGGER.error('Unable to load GridSight settings',oError);return;}
 if(bDisposed)return;
 const bCanConfigure=oValues.canConfigure===true;
 const aFields=[];
 if(bCanConfigure)aFields.push({id:'baseUrl',label:t(APP,'LINEA API base URL'),type:'text',value:String(oValues.baseUrl||''),required:true,placeholder:'http://node-red:1880',maxLength:512});
 aFields.push({id:'refreshSeconds',label:t(APP,'LIVE refresh interval'),type:'select',value:String(oValues.refreshSeconds||2),required:true,options:[1,2,5,10,30].map(nValue=>({value:String(nValue),label:String(nValue)+' s'}))});
 if(bCanConfigure)aFields.push({id:'historyEnabled',label:t(APP,'Store history in the background'),type:'checkbox',value:oValues.historyEnabled!==false});
 for(const [key,label] of HISTORY_SERIES){
  const name=label.startsWith('Inverter ')?t(APP,'Inverter')+' '+label.slice(-1):t(APP,label);
  aFields.push({id:'chart_'+key,label:name,type:'checkbox',value:oValues['chart_'+key]!==false});
 }
 const content=cloneTemplate('lm-settings-dialog-template');
 const oResult=content.querySelector('#lm-test-result');
 oSettingsFormController=CORE.forms.create(aFields);
 const firstSeries=oSettingsFormController.element.querySelector('[name="chart_pv"]')?.closest('.hc-shared-app-core-form__field');
 if(firstSeries){const heading=document.createElement('h4');heading.textContent=t(APP,'History chart series');const hint=document.createElement('p');hint.className='lm-help';hint.textContent=t(APP,'Choose the curves shown in History. Collection continues for every sensor.');firstSeries.before(heading,hint);}
 content.querySelector('#lm-settings-form-host')?.append(oSettingsFormController.element);
 const oActions=document.createElement('div');oActions.className='lm-settings-actions';
 if(bCanConfigure){
  const oTest=document.createElement('button');oTest.type='button';oTest.textContent=t(APP,'Test connection');oActions.append(oTest);
  oTest.addEventListener('click',async()=>{
   const oFormValues=oSettingsFormController.values();oResult.textContent=t(APP,'Testing…');
   try{const oResponse=await api('/api/settings/test',{method:'POST',body:JSON.stringify({baseUrl:String(oFormValues.baseUrl||'')})});const oHealth=oResponse.health||{},oInfo=oHealth.api||{},aProblems=[];if(oInfo.name!=='LINEA API')aProblems.push(t(APP,'API name'));if(Number(oInfo.schema)!==EXPECTED_API_SCHEMA)aProblems.push(t(APP,'API schema')+' '+(oInfo.schema??'—'));if(oInfo.readOnly!==true)aProblems.push(t(APP,'read-only flag'));if(aProblems.length){oResult.textContent=t(APP,'API responds, but the contract is not compatible:')+' '+aProblems.join(', ');CORE.notifications.warning(oResult.textContent,{dedupeKey:'hc_gridsight:test-warning'});}else{oResult.textContent=t(APP,'Connection OK')+' · LINEA API '+(oInfo.version||'1.x')+' · read-only';CORE.notifications.success(t(APP,'Connection OK'),{dedupeKey:'hc_gridsight:test-ok'});}}
   catch(oError){oResult.textContent=oError.message;CORE.notifications.error(oError.message,{dedupeKey:'hc_gridsight:test-error'});}
  },{signal:oLifetime.signal});
 }
 const oSave=document.createElement('button');oSave.type='button';oSave.className='primary';oSave.textContent=t(APP,'Save');oActions.append(oSave);
 oSave.addEventListener('click',async()=>{
  if(!oSettingsFormController.validate()){oResult.textContent=t(APP,'Request failed');return;}
  const oFormValues=oSettingsFormController.values();const oSaveValues={refreshSeconds:Number(oFormValues.refreshSeconds)||2};
  if(bCanConfigure){oSaveValues.baseUrl=String(oFormValues.baseUrl||'');oSaveValues.historyEnabled=oFormValues.historyEnabled!==false;}
  for(const [key] of HISTORY_SERIES)oSaveValues['chart_'+key]=oFormValues['chart_'+key]===true;
  try{const oSaved=await oSettingsClient.save(oSaveValues);refreshSeconds=Number(oSaved.refreshSeconds||oSaveValues.refreshSeconds)||2;applyHistoryPreferences(oSaved);CORE.notifications.success(t(APP,'Settings saved'),{dedupeKey:'hc_gridsight:settings-saved'});schedule();void loadStatus();historyLoaded=false;}
  catch(oError){oResult.textContent=oError.message;CORE.notifications.error(oError.message,{dedupeKey:'hc_gridsight:settings-save-error'});}
 },{signal:oLifetime.signal});
 content.querySelector('#lm-core-about')?.before(oActions);
 const host=$('lm-settings-page-host');if(!host)return;
 host.replaceChildren(...content.childNodes);
 const aboutEl=host.querySelector('#lm-core-about');if(aboutEl)oAboutController=CORE.about.mount(aboutEl,{requiredCoreVersion:CORE_MIN_VERSION,heading:t(APP,'About')});
 bSettingsReady=true;bSettingsLoading=false;
}
const oToolbarHost=$('lm-core-toolbar');
const oToolbarController=oToolbarHost?CORE.toolbar.create(oToolbarHost,{ariaLabel:t(APP,'LINEA sections'),actions:[
 {id:'refresh',label:t(APP,'Refresh'),icon:'↻',compact:true,onClick:async()=>{if(['history','collector','events'].includes(activeTab()))await loadHistory(true);else{await loadStatus();void loadDailyRevenue(true);}resetSoftRefreshClock();}},
]}):null;
document.querySelectorAll('.lm-tab').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.lm-tab').forEach(x=>x.classList.toggle('active',x===btn));document.querySelectorAll('.lm-tab-panel').forEach(x=>x.classList.remove('active'));$('lm-tab-'+btn.dataset.tab)?.classList.add('active');if(['history','collector','events'].includes(btn.dataset.tab)){if(!historyLoaded)loadHistory();else if(btn.dataset.tab==='history')renderPowerChart(historyPoints);}if(btn.dataset.tab==='settings')void openSettings();oLayoutController?.refresh?.('manual');}));
document.querySelectorAll('[data-history-range]').forEach(btn=>btn.addEventListener('click',()=>{historyRange=btn.dataset.historyRange||'24h';document.querySelectorAll('[data-history-range]').forEach(x=>x.classList.toggle('active',x===btn));historyLoaded=false;loadHistory(true);}));
document.querySelectorAll('[data-series]').forEach(btn=>btn.addEventListener('click',()=>{const k=btn.dataset.series;if(!historyAllowed[k]||btn.disabled)return;historyVisible[k]=!historyVisible[k];btn.classList.toggle('active',historyVisible[k]);btn.setAttribute('aria-pressed',String(historyVisible[k]));renderPowerChart(historyPoints);}));
$('lm-history-zoom-reset')?.addEventListener('click',()=>{historyZoom=null;$('lm-history-zoom-reset').hidden=true;renderPowerChart(historyPoints);});
oLayoutController?.refresh?.('manual');
const fnVisibilityChange=()=>{if(document.hidden){updateAutoRefreshUi();return;}softRefresh({fromVisibility:true});};
document.addEventListener('visibilitychange',fnVisibilityChange);
function fnCleanup(){
 if(bDisposed)return;
 bDisposed=true;oLifetime.abort();
 clearInterval(timer);clearInterval(softRefreshTimer);clearInterval(softRefreshTick);
 document.removeEventListener('visibilitychange',fnVisibilityChange);
 oSettingsFormController?.destroy?.();oToolbarController?.destroy?.();oLayoutController?.destroy?.();oAboutController?.destroy?.();CORE.notifications.clear();
}

 // Publish cleanup before the first asynchronous startup request.
 fnCurrentCleanup=fnCleanup;
 try{const oValues=await oSettingsClient.load();if(bDisposed)return fnCleanup;refreshSeconds=Number(oValues.refreshSeconds)||2;applyHistoryPreferences(oValues);}catch(_){}
 if(bDisposed)return fnCleanup;
 await loadStatus();
 void loadDailyRevenue();
 if(bDisposed)return fnCleanup;
 schedule();
 scheduleSoftRefresh();
 return fnCleanup;
}

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
  oCore.assertCompatible(oRoot.dataset.requiredCoreVersion||'0.18.0-dev.2');
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
window.addEventListener('pagehide',event=>{
 if(event.persisted)return;
 bPageClosed=true;
 if(typeof fnCurrentCleanup==='function')fnCurrentCleanup();
 fnCurrentCleanup=null;
});

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
else void start();
})();
