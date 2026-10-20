'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const target={fixture_id:'k6-20261012-110000-aabbccdd',base_url:'http://127.0.0.1:18120',ws_url:'ws://127.0.0.1:18120/ratchet',server_id:1,channel_id:1};
const accounts=[1,2].map(id=>({id,email:'k6_aabbccdd_'+id+'@example.invalid',password:'a'.repeat(48)}));
const records=new Map();class Metric{constructor(name){this.name=name;}add(value){records.set(this.name,[...(records.get(this.name)||[]),value]);}}
const context=vm.createContext({__ENV:{},open:name=>JSON.stringify(name.includes('target')?target:accounts),SharedArray:class{constructor(_,fn){return fn();}},Rate:Metric,Trend:Metric,Counter:Metric,exec:{vu:{idInTest:1},scenario:{progress:.5}},console});
vm.runInContext(fs.readFileSync('tools/load-tests/plan.js','utf8').replaceAll('export ',''),context);
let code=fs.readFileSync('tools/load-tests/chat-capacity.js','utf8').replace(/^import .*;\s*$/gm,'').replace('export default async function(){','async function defaultIteration(){').replaceAll('export ','');
vm.runInContext(code+'\nglobalThis.testRunner={handleSummary,observe,socketContext};',context);
context.testRunner.observe('messages',{timings:{duration:30,waiting:20,receiving:10}});assert.deepEqual(records.get('endpoint_messages_duration_ms'),[30]);assert.deepEqual(records.get('endpoint_messages_receiving_ms'),[10]);
const detail=context.testRunner.socketContext({code:1006,wasClean:false},{readyState:3},Date.now()-5);assert.equal(detail.close_code,1006);assert.equal(detail.was_clean,false);assert.equal(detail.vu,1);assert(detail.iteration_age_ms>=0);
const summary=duration=>({state:{testRunDurationMs:duration},options:{noConnectionReuse:true},metrics:{vus:{values:{max:2}},endpoint_messages_duration_ms:{values:{'p(95)':42}},socket_ok:{thresholds:{'rate>0.99':{ok:true}},values:{rate:1}}}});
const early=context.testRunner.handleSummary(summary(10000));const earlyJSON=JSON.parse(early['results-2-users.json']);assert.equal(earlyJSON.full_schedule_elapsed,false);assert.equal(earlyJSON.connection_reuse_disabled,true);assert.equal(earlyJSON.endpoint_times.messages.duration_ms['p(95)'],42);assert.match(early.stdout,/does not validate capacity/);assert.doesNotMatch(early.stdout,/completed|Thresholds passed/);
const full=context.testRunner.handleSummary(summary(35001));assert.equal(JSON.parse(full['results-2-users.json']).full_schedule_elapsed,true);assert.match(full.stdout,/Full schedule elapsed/);
const fail=summary(35001);fail.metrics.socket_ok.thresholds['rate>0.99'].ok=false;const failed=context.testRunner.handleSummary(fail);assert.match(failed.stdout,/Thresholds failed/);
console.log('PASS: endpoint phase metrics, numeric socket diagnostics and truthful early/full/failed schedule reports');
