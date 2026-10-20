const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../src/js/app-1.0.0.js'),'utf8');
const model=source.slice(source.indexOf('function makeHistorySurface('),source.indexOf('function h3Schedule('));
const sandbox={Intl,Date,isValue:v=>v!==null&&v!==undefined&&v!==''&&Number.isFinite(Number(v))};vm.createContext(sandbox);vm.runInContext(model,sandbox);
const build=sandbox.makeHistorySurface,ts=Date.UTC(2026,8,1,10)/1000;
assert.equal(build([], 'pv',300),null);
assert.equal(build([{ts,pv:null}], 'pv',300),null);
const points=[{ts,pv:0},{ts:ts+3600,pv:100},{ts:ts+86400,pv:null},{ts:ts+172800,pv:200}],snapshot=JSON.stringify(points);
const m=build(points,'pv',300);assert.equal(m.days,3);assert.equal(m.low,0);assert.equal(m.high,200);assert(m.buckets[1].every(b=>!b.count));assert.equal(JSON.stringify(points),snapshot);
const huge=Array.from({length:800},(_,i)=>({ts:ts+i*86400,pv:i}));const big=build(huge,'pv',3600);assert(big.rows<=64&&big.cols<=96);
const signed=build([{ts,spot:-2},{ts:ts+3600,spot:3}],'spot',3600);assert.equal(signed.low,-2);assert.equal(signed.high,3);
// Backend property names must be mapped, not mistaken for the 2D legend keys.
assert(source.includes("pv:'pvW'")&&source.includes("soc:'socPct'")&&source.includes("spot:'spotPrice'"));
console.log('3D model: zero, gaps, signed values, bounded mesh, source preservation and backend mapping PASS');
