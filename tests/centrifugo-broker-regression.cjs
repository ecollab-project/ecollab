const assert=require('node:assert/strict'),fs=require('node:fs');
const {Centrifuge}=require('../tools/chat-delivery/node_modules/centrifuge');
const WebSocket=require('../tools/chat-delivery/node_modules/ws');
const {createHmac}=require('node:crypto');
const base=process.env.TEST_BROKER_API,fixture=process.env.TEST_FIXTURE_BASE,proxy=process.env.TEST_PROXY_BASE;
assert(base&&fixture&&proxy,'Run through tests/centrifugo-proxy-regression.py');
const wsURL=base.replace('http','ws').replace('/api','/connection/websocket'),clients=[];
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function token(uid=1,channel){const b=x=>Buffer.from(JSON.stringify(x)).toString('base64url');const value=b({alg:'HS256',typ:'JWT'})+'.'+b({sub:String(uid),exp:Math.floor(Date.now()/1000)+300,...(channel?{channel}:{})});return value+'.'+createHmac('sha256','a'.repeat(64)).update(value).digest('base64url');}
function once(c,e,ms=12000){return new Promise((resolve,reject)=>{const f=x=>{clearTimeout(t);resolve(x);},t=setTimeout(()=>{c.off(e,f);reject(Error('Timeout: '+e));},ms);c.once(e,f);});}
async function http(path,{cookie='',body,method}={}){const r=await fetch(fixture+path,{method:method||(body?'POST':'GET'),headers:{'X-Fixture-Key':'fixture-local-only','Content-Type':'application/json',Cookie:cookie},...(body?{body:JSON.stringify(body)}:{})});const raw=await r.text();assert(raw.startsWith('{')||raw.startsWith('['),'Fixture HTTP '+path+' status='+r.status+' returned no JSON');return {status:r.status,data:JSON.parse(raw),cookie:r.headers.get('set-cookie')?.split(';')[0]};}
async function control(action,cookie=''){const r=await http('/control',{body:{action},cookie});assert.equal(r.status,200);}
async function seed(uid=1){const r=await http('/seed?user='+uid);assert(r.cookie);return r.cookie;}
function make(cookie='',opts={},origin='https://ecollab.tech'){
 class Socket extends WebSocket{constructor(url,protocols){super(url,protocols,{headers:{Cookie:cookie,Origin:origin}});}}
 const c=new Centrifuge(wsURL,{websocket:Socket,minReconnectDelay:100,maxReconnectDelay:300,...opts});clients.push(c);c.on('error',()=>{});return c;
}
async function connect(cookie,opts={},uid=1){const c=make(cookie,opts);const joined=once(c,'subscribed');c.connect();const s=await joined;assert.equal(s.channel,'inbox:user'+uid);return c;}
async function rejected(c){return new Promise((resolve,reject)=>{
 const done=(value,error=false)=>{clearTimeout(timer);c.off('connected',accepted);c.off('disconnected',denied);c.off('error',denied);c.disconnect();error?reject(value):resolve(value);};
 const accepted=()=>done(Error('Unauthorized connection accepted'),true),denied=value=>done(value);
 const timer=setTimeout(()=>done(Error('Timed out waiting for admission rejection'),true),4000);
 c.once('connected',accepted);c.once('disconnected',denied);c.once('error',denied);c.connect();
});}
async function publish(event,uid=1){const r=await fetch(base+'/broadcast',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'b'.repeat(64)},body:JSON.stringify({channels:['inbox:user'+uid],data:event})});const j=await r.json();assert(!j.error);assert(!j.result.responses[0].error);}
async function kick(uid=1){const r=await fetch(base+'/disconnect',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'b'.repeat(64)},body:JSON.stringify({user:String(uid),disconnect:{code:4503,reason:'Account access revoked'}})});assert(!(await r.json()).error);}
async function legacy(){
 await control('restore');const cookie=await seed();let c=make(cookie,{token:token()});let sub=c.newSubscription('inbox:user1',{token:token(1,'inbox:user1')});let ready=once(sub,'subscribed');sub.subscribe();c.connect();await ready;
 await control('deactivate');const closed=once(c,'disconnected');await kick();await closed;
 ready=once(sub,'subscribed');c.connect();await ready; // Actual revoked account; old connection and cached subscription JWTs accepted.
 c.disconnect();await control('restore');console.log('CONFIRMED baseline v6.9.6: old connection AND cached inbox subscription JWT restore broker access after account deactivation + disconnect');
}
async function main(){
 await control('restore');let cookie=await seed();
 const oldConnectionJWT=token(),oldSubscriptionJWT=token(1,'inbox:user1');
 await control('disable-delivery');await rejected(make(cookie));await control('proxy-restore');
 await control('proxy-timeout');const timeoutStart=performance.now();await rejected(make(cookie));console.log('OBSERVED callback deadline rejection ms='+(performance.now()-timeoutStart).toFixed(2));await control('proxy-restore');await wait(600);
 for(const secret of ['', 'wrong']){
  const r=await fetch(proxy+'/connect',{method:'POST',headers:{'Content-Type':'application/json','X-Ecollab-Broker-Secret':secret,Cookie:cookie,Origin:'https://ecollab.tech'},body:'{}'});assert.equal(r.status,403);
 }
 assert.equal((await fetch(fixture+'/direct-proxy',{headers:{'Ecollab-Internal-Broker-Proxy':'1','X-Ecollab-Broker-Secret':'c'.repeat(64),Cookie:cookie}})).status,404);
 const mismatch=await fetch(proxy+'/refresh',{method:'POST',headers:{'Content-Type':'application/json','X-Ecollab-Broker-Secret':'c'.repeat(64),Cookie:cookie,Origin:'https://ecollab.tech'},body:JSON.stringify({user:'2'})});assert.equal((await mismatch.json()).result.expired,true);
 // Old JWTs are rejected even for an active account; no parallel legacy verifier survives launcher poisoning.
 await rejected(make(cookie,{token:oldConnectionJWT}));await rejected(make('',{data:{user:'1'},headers:{Cookie:cookie,Origin:'https://ecollab.tech'}}));
 await rejected(make(cookie,{},'https://evil.example'));await rejected(make(cookie,{},''));
 console.log('PASS stage: old JWT and origin checks');
 let c=await connect(cookie,{data:{user:'2',channels:['inbox:user2'],meta:{user:'2'}},headers:{'X-User-Id':'2'}});let received=[];c.on('publication',x=>received.push(x));
 const stats0=(await http('/stats')).data.length;await wait(1100);assert.equal((await http('/stats')).data.length,stats0,'Ordinary protocol pings must not call auth proxy');
 await assert.rejects(c.publish('inbox:user1',{id:99}),e=>e.code===103);
 await publish({event_id:1});await wait(80);assert.deepEqual(received.map(x=>x.data.event_id),[1]);assert(received.every(x=>x.channel==='inbox:user1'));
 c.disconnect();await publish({event_id:2});await publish({event_id:3});let rejoined=once(c,'subscribed');c.connect();const recovery=await rejoined;assert.notEqual(recovery.recovered,true);await wait(80);assert.deepEqual(received.map(x=>x.data.event_id),[1]);
 assert.equal((await http('/messages',{cookie})).data.messages[0].content,'synthetic saved message');
 await publish({event_id:4});await wait(80);assert.deepEqual(received.map(x=>x.data.event_id),[1,4]);
 c.disconnect();
 console.log('PASS stage: inbox delivery and HTTP catchup');
 // A valid session cannot use retired channel tokens to join another account's inbox.
 for(const subscription of [{channel:'inbox:user2'},{channel:'inbox:user2',token:token(2,'inbox:user2')}]){
  c=await connect(cookie);let leaked=false;c.on('publication',x=>{if(x.channel==='inbox:user2')leaked=true;});
  const sub=c.newSubscription(subscription.channel,subscription.token?{token:subscription.token}:{});
  const denied=Promise.race([once(sub,'unsubscribed'),once(c,'disconnected')]);sub.subscribe();await denied;await publish({secret:'synthetic'},2);await wait(40);assert(!leaked);c.disconnect();
 }
 const modCookie=await seed(2);c=await connect(modCookie,{},2);assert.equal((await http('/messages',{cookie:modCookie})).status,403);
 await control('deactivate');const revokedInbox=c.newSubscription('inbox:user1',{token:oldSubscriptionJWT});const oldSubDenied=Promise.race([once(revokedInbox,'unsubscribed'),once(c,'disconnected')]);revokedInbox.subscribe();await oldSubDenied;c.disconnect();await control('restore');
 console.log('PASS stage: cached subscription denial');
 // Resource revocation keeps the account inbox, but filters outbox hints and authorized HTTP reads.
 for(const action of ['remove-private','remove-member','server-ban']){
  await control('restore');c=await connect(cookie);let events=0;c.on('publication',()=>events++);await control(action);
  const sent=await http('/deliver?event=10');assert.equal(sent.data.recipients,0,action);await wait(60);assert.equal(events,0);assert.equal((await http('/messages',{cookie})).status,403,action);c.disconnect();
 }
 for(const action of ['server-mute','server-suspend']){
  await control('restore');await control(action);c=await connect(cookie);assert.equal((await http('/messages',{cookie})).status,200);c.disconnect();
 }
 console.log('PASS stage: resource audience checks');
 for(const action of ['deactivate','suspend','delete','global-ban']){
  await control('restore');c=await connect(cookie);const closed=once(c,'disconnected');await control(action);await kick();assert.equal((await closed).code,4503);
  await rejected(make(cookie,{token:oldConnectionJWT}));await rejected(make(cookie));
 }
 console.log('PASS stage: active account disconnects');
 await control('restore');cookie=await seed();c=await connect(cookie);let refreshedBlock=once(c,'disconnected',15000);await control('deactivate');await refreshedBlock;
 await control('restore');cookie=await seed();c=await connect(cookie);
 // Actual refresh callback carries the original transport cookie/Origin and rechecks the account.
 const start=(await http('/stats')).data.filter(x=>x.event==='refresh').length;
 for(let i=0;i<40;i++){if((await http('/stats')).data.filter(x=>x.event==='refresh').length>start)break;await wait(200);}
 assert((await http('/stats')).data.filter(x=>x.event==='refresh').length>start,'No real refresh callback');assert.equal(c.state,'connected');
 console.log('PASS stage: refresh accepted');
 let closed=once(c,'disconnected',15000);await control('logout',cookie);await closed;await rejected(make(cookie));
 console.log('PASS stage: logout expired');
 cookie=await seed();c=await connect(cookie);closed=once(c,'connecting',15000);await control('db-outage');await rejected(make(cookie));await closed;assert.notEqual(c.state,'connected');rejoined=once(c,'subscribed');await control('db-restore');await rejoined;c.disconnect();
 console.log('PASS stage: database outage expired');
 await control('restore');cookie=await seed();c=await connect(cookie);
 await control('deactivate');await control('proxy-outage');await rejected(make(cookie));
 let outageHints=0;c.on('publication',()=>outageHints++);assert.equal((await http('/deliver?event=20')).data.recipients,0);
 const outageStart=performance.now();
 await wait(process.env.TEST_EXTENDED_REVOCATION?75000:6500);
 console.log('OBSERVED proxy HTTP-503 outage '+JSON.stringify({milliseconds:performance.now()-outageStart,state:c.state,refreshFailures:(await http('/stats')).data.filter(x=>x.event==='refresh'&&x.status===503).length}));
 assert.equal(c.state,'connected');
 assert.equal(outageHints,0);
 if(process.env.TEST_EXTENDED_REVOCATION){
  // Model a recipient snapshot selected before revocation, delayed until after commit.
  // The direct server API has no current-policy callback. Only a synthetic hint is sent.
  await publish({type:'chat_changed',event_id:999,target_id:10});await wait(100);assert.equal(outageHints,1);
  console.log('CONFIRMED delayed preselected hint can reach revoked established inbox during proxy outage; fresh outbox recipient lookup returned zero');
  const recoveredClose=once(c,'disconnected',70000),recoveryStart=performance.now();await control('proxy-restore');await recoveredClose;
  console.log('MEASURED callback recovery '+JSON.stringify({restore_to_disconnect_ms:performance.now()-recoveryStart,observed_revoked_connection_ms:performance.now()-outageStart}));
  await control('restore');cookie=await seed();c=await connect(cookie);await control('deactivate');await control('proxy-outage');await wait(5500);
  const failed=await http('/reconcile?fail_api=1');assert.equal(failed.status,200);assert.equal(c.state,'connected');
  const closedByWorker=once(c,'disconnected'),workerStart=performance.now();const reconciled=await http('/reconcile');assert.equal(reconciled.data.exit,0);await closedByWorker;
  console.log('MEASURED actual singleton CLI worker during refresh outage '+JSON.stringify({dispatch_to_disconnect_ms:performance.now()-workerStart,api_failure_left_connected:true,media_api_unavailable:true,worker_stderr:reconciled.data.stderr.trim()}));
  await control('proxy-restore');await control('restore');cookie=await seed();c=await connect(cookie);await control('deactivate');await control('proxy-outage');await control('db-outage');
  const dbFailed=await http('/reconcile');assert.match(dbFailed.data.stderr,/incomplete/);assert.equal(c.state,'connected');
  await control('db-restore');const afterDB=once(c,'disconnected');await http('/reconcile');await afterDB;
  console.log('PASS actual CLI worker cannot enforce during policy DB failure, then disconnects after DB recovery without callback recovery');
 }
 await control('proxy-restore');c.disconnect();await rejected(make(cookie));
 await control('restore');cookie=await seed();
 // Bounded reconnect sample: 16 connections, concurrency 8, ten FPM slots, one session.
 const latencies=[];const before=(await http('/stats')).data.length;
 for(let batch=0;batch<2;batch++)await Promise.all(Array.from({length:8},async()=>{const t=performance.now();const x=await connect(cookie);latencies.push(performance.now()-t);x.disconnect();}));
 const rows=(await http('/stats')).data.slice(before).filter(x=>x.event==='connect');assert.equal(rows.length,16);assert(rows.every(x=>x.queries===1));
 latencies.sort((a,b)=>a-b);const work=rows.reduce((s,x)=>s+x.milliseconds,0);
 console.log('MEASURED synthetic reconnect sample '+JSON.stringify({connections:16,concurrency:8,fpm_slots:10,connect_p50_ms:latencies[7],connect_p95_ms:latencies[15],proxy_mean_ms:work/16,auth_queries:rows.reduce((s,x)=>s+x.queries,0),errors:0}));
 fs.writeFileSync(process.env.TEST_COOKIE_FILE,cookie,{mode:0o600});
 console.log('PASS real broker: JWT retirement, cookie/Origin admission+refresh, server-side inbox isolation, publish denial, no hint replay, outbox revocation, HTTP recovery, active disconnect, logout, DB/proxy failures and reconnect sample');
}
(async()=>{try{
 if(process.env.TEST_LEGACY_REPLAY)await legacy();
 else if(process.env.TEST_REFRESH_TRANSFORM){
  await control('restore');let cookie=await seed(),c=await connect(cookie),closed=once(c,'connecting',15000),t=performance.now();
  await control('deactivate');await control('proxy-outage');await closed;
  assert.notEqual(c.state,'connected');console.log('MEASURED supported HTTP-503 transform '+JSON.stringify({old_connection_closed_ms:performance.now()-t,state:c.state}));
  await control('proxy-restore');await control('restore');cookie=await seed();c=await connect(cookie);
  await control('deactivate');await control('proxy-timeout');await wait(6500);assert.equal(c.state,'connected');
  console.log('CONFIRMED HTTP-status transform does not bound refresh transport timeout; established revoked client still connected after 6.5 seconds');
  await control('proxy-restore');c.disconnect();fs.writeFileSync(process.env.TEST_COOKIE_FILE,cookie,{mode:0o600});
 }
 else if(process.env.TEST_TRANSPORT_OUTAGE){await rejected(make(fs.readFileSync(process.env.TEST_COOKIE_FILE,'utf8')));console.log('PASS actual Nginx callback transport stopped: new broker admission denied');}
 else await main();
}finally{for(const c of clients)c.disconnect();}})().catch(e=>{console.error(e);process.exitCode=1;});
