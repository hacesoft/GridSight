'use strict';
// Regresní test 1.0.1: restart Node-RED nesmí v UI zobrazovat cURL/HTTP detaily.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../../src/js/app-1.0.1.js'),'utf8');
const apiStart=source.indexOf('const api=async(');
const apiEnd=source.indexOf('\nlet ',apiStart);
const statusStart=source.indexOf('async function loadStatus(){');
const statusEnd=source.indexOf('\nlet ',statusStart);
assert(apiStart>0&&apiEnd>apiStart&&statusStart>0&&statusEnd>statusStart);

const nodes={
 'lm-error':{hidden:true,className:'lm-alert',textContent:''},
 'lm-connection':{className:'lm-status lm-status-ok',innerHTML:''},
};
let response=null;
let successfulRenders=0;
const context={
 APP:'hc_gridsight',OC:{requestToken:'test',generateUrl:route=>route},
 oLifetime:{signal:{}},FormData,console,
 t:(_app,key)=>key,esc:value=>String(value),
 $:id=>nodes[id],
 loadDailyRevenue:()=>Promise.resolve(),
 renderStatus:body=>{
  if(body?.invalid)throw new Error('Incompatible API contract');
  successfulRenders++;
  nodes['lm-connection'].className='lm-status lm-status-ok';
 },
 fetch:async()=>{
  if(response instanceof Error)throw response;
  return {ok:response.status===200,status:response.status,statusText:'Backend unavailable',text:async()=>JSON.stringify(response.body)};
 },
};
vm.createContext(context);
vm.runInContext('let bLoading=false,bDisposed=false;\n'+source.slice(apiStart,apiEnd)+'\n'+source.slice(statusStart,statusEnd)+'\nglobalThis.testLoad=loadStatus;',context);

(async()=>{
 response={status:503,body:{ok:false,code:'linea_api_unavailable'}};
 await context.testLoad();
 assert.match(nodes['lm-error'].textContent,/temporarily unavailable/);
 assert.match(nodes['lm-error'].className,/lm-alert-info/);
 assert.match(nodes['lm-connection'].className,/lm-status-warn/);
 assert(!nodes['lm-error'].textContent.includes('cURL'));

 response={status:200,body:{ok:true,data:{api:{schema:1}}}};
 await context.testLoad();
 assert.equal(nodes['lm-error'].hidden,true);
 assert.equal(nodes['lm-error'].className,'lm-alert');
 assert.equal(nodes['lm-connection'].className,'lm-status lm-status-ok');
 assert.equal(successfulRenders,1);

 response={status:502,body:{ok:false,code:'linea_api_error',error:'Incompatible response'}};
 await context.testLoad();
 assert.equal(nodes['lm-error'].textContent,'Incompatible response');
 assert.equal(nodes['lm-error'].className,'lm-alert');
 assert.match(nodes['lm-connection'].className,/lm-status-error/);

 response=new TypeError('Failed to fetch');
 await context.testLoad();
 assert.match(nodes['lm-error'].className,/lm-alert-info/);
 assert(!nodes['lm-error'].textContent.includes('Failed to fetch'));

 response={status:200,body:{ok:true,data:{invalid:true}}};
 await context.testLoad();
 assert.equal(nodes['lm-error'].textContent,'Incompatible API contract');
 assert.equal(nodes['lm-error'].className,'lm-alert');
 console.log('LINEA outage: 503 notice, auto-recovery, real errors, transport failure, schema failure PASS');
})().catch(error=>{console.error(error);process.exitCode=1;});
