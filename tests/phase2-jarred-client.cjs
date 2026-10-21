const fs=require('fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<div id="dmTypingIndicator"></div><button data-channel-id="5"></button>',{runScripts:'outside-only',pretendToBeVisual:true,url:'https://fixture.invalid'}),w=dom.window;
let states=[{id:1,state:'queued'},{id:1,state:'done',ai_message:{id:100,body:'reply'},ai_action:{type:'open_channel',channel_id:5}}],messages=[],clicks=0,timers=[],requests=0;
w.DM={activeConvId:1};w.BASE=()=>'';w.apiFetch=async()=>{requests++;return {job:states.shift()};};w._appendDmMessage=m=>messages.push(m);w._showAiTyping=()=>{};w._hideAiTyping=()=>{};w.showToast=()=>{};w.setTimeout=f=>{timers.push(f);return timers.length;};w.clearTimeout=()=>{};w.document.querySelector('button').onclick=()=>clicks++;
const source=fs.readFileSync('assets/js/chat/dm-notifications.js','utf8');w.eval(source.slice(source.indexOf('let _jarredWatchGeneration=')));
const tick=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const pending=w._watchJarredJob(1,1);await tick();assert.equal(requests,1);assert(w.document.querySelector('[data-cancel-jarred]'));
 w.dispatchEvent(new w.CustomEvent('ecollab:delivery-sync',{detail:{kind:'dm',target_id:1}}));await pending;
 assert.equal(messages.length,1);assert.equal(clicks,1);
 states=[{id:1,state:'done',ai_message:{id:100},ai_action:{type:'open_channel',channel_id:5}}];await w._watchJarredJob(1);assert.equal(clicks,1,'Reopen never repeats action');
 let resolve;w.apiFetch=()=>new Promise(r=>resolve=r);const stale=w._watchJarredJob(1,2);await tick();w.DM.activeConvId=2;resolve({job:{id:2,state:'done',ai_message:{id:101}}});await stale;assert.equal(messages.length,2,'Late response cannot enter other conversation');
 dom.window.close();console.log('PASS Jarred pending/cancel control, realtime wake, action deduplication and late conversation response');
})().catch(e=>{console.error(e);dom.window.close();process.exitCode=1;});
