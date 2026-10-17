const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=require('node:path');const code=fs.readFileSync(path.join(__dirname,'../tools/load-tests/plan.js'),'utf8').replaceAll('export ','');const context=vm.createContext({});vm.runInContext(code,context);
const target={fixture_id:'k6-20261012-110000-aabbccdd',base_url:'https://ecollab.tech',ws_url:'wss://ws.ecollab.tech',server_id:5,channel_id:20};context.validateTarget(target);
const accounts=[{id:1,email:'k6_aabbccdd_1@example.invalid',password:'a'.repeat(48)},{id:2,email:'k6_aabbccdd_2@example.invalid',password:'a'.repeat(48)}];context.validateAccounts(accounts,target);
assert.equal(context.plan({},2).vus,2);assert.equal(context.plan({VUS:'100'},100).vus,100);assert.equal(context.plan({VUS:'1000'},1000).vus,1000);
for(const env of [{VUS:'1001'},{VUS:'0'},{VUS:'NaN'},{VUS:'3'},{INTERVAL_SECONDS:'0'},{CONNECTION_SECONDS:'121'},{WRITE_EVERY:'-1'}])assert.throws(()=>context.plan(env,2));
assert.throws(()=>context.validateAccounts([accounts[0],accounts[0]],target));assert.throws(()=>context.validateAccounts([{...accounts[0],email:'real-user@example.com'}],target));
assert.throws(()=>context.validateTarget({...target,fixture_id:'real-server'}));assert.throws(()=>context.validateTarget({...target,base_url:'http://ecollab.tech'}));assert.throws(()=>context.validateTarget({...target,channel_id:0}));
console.log('Load-test plan checks passed: distinct synthetic users, target validation, limits and smoke defaults');

const stages={ramp:5,hold:20};
assert.equal(context.plannedClose(false,0.5,stages),false);
assert.equal(context.plannedClose(false,25/35,stages),true);
assert.equal(context.plannedClose(false,1,stages),true);
assert.equal(context.plannedClose(true,0,stages),true);
console.log('Planned-close checks passed: active-period failures remain counted; ramp-down closes excluded');
