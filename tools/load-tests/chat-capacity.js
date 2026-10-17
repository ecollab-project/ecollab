import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { SharedArray } from 'k6/data';
import { WebSocket } from 'k6/websockets';
import { Rate, Trend, Counter } from 'k6/metrics';
import { plan, validateTarget, validateAccounts, plannedClose } from './plan.js';
const target=JSON.parse(open(__ENV.TARGET_FILE||'./target.json'));validateTarget(target);
const accounts=new SharedArray('synthetic accounts',()=>{const value=JSON.parse(open(__ENV.ACCOUNTS_FILE||'./accounts.json'));validateAccounts(value,target);return value;});
const run=plan(__ENV,accounts.length),base=target.base_url.replace(/\/$/,'');
const apiOk=new Rate('api_ok'),authOk=new Rate('auth_ok'),socketOk=new Rate('socket_ok');
const ratchetReady=new Rate('ratchet_join_ok'),deliveryReady=new Rate('centrifugo_subscribe_ok'),socketFailures=new Counter('socket_failures');
const warned=new Set();
function socketFailure(transport,reason){socketOk.add(false);socketFailures.add(1,{transport,reason});const key=transport+':'+reason;if(!warned.has(key)){warned.add(key);console.warn('[Socket check] '+transport+': '+reason);}}
const publications=new Counter('inbox_publications'),deliveryOk=new Rate('own_delivery_ok'),deliveryTime=new Trend('own_delivery_ms',true);
export const options={noCookiesReset:true,summaryTrendStats:['avg','med','p(95)','p(99)','max'],
  scenarios:{students:{executor:'ramping-vus',startVUs:1,stages:run.stages,gracefulRampDown:'5s',gracefulStop:'5s'}},
  thresholds:{
    api_ok:[{threshold:'rate>0.99',abortOnFail:true,delayAbortEval:'20s'}],
    auth_ok:[{threshold:'rate>0.99',abortOnFail:true,delayAbortEval:'20s'}],
    socket_ok:[{threshold:'rate>0.99',abortOnFail:true,delayAbortEval:'20s'}],
    'http_req_duration{kind:read}':[{threshold:'p(95)<1500',abortOnFail:true,delayAbortEval:'30s'}],
    'http_req_duration{kind:login}':[{threshold:'p(95)<10000',abortOnFail:true,delayAbortEval:'30s'}],
    ...(run.writeEvery?{own_delivery_ok:['rate>0.99'],own_delivery_ms:['p(95)<3000']}:{}),
  },systemTags:['status','method','name','scenario','expected_response','group','check']};
let authenticated=false,csrf='',ticks=0;
function json(response){try{return response.json();}catch(_){return {};}}
function params(name,kind='read'){return {timeout:'10s',redirects:0,tags:{name,kind},headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}};}
function request(path,name,method='GET',body=null,kind='read'){
  const response=http.request(method,base+path,body===null?null:JSON.stringify(body),params(name,kind));const data=json(response);
  const ok=response.status===200 && (data.success===true || typeof data.token==='string') && !data.error;
  apiOk.add(ok);check(response,{[name+' succeeded']:()=>ok});return {response,data,ok};
}
function login(){
  const account=accounts[exec.vu.idInTest-1];if(!account)exec.test.abort('Not enough unique test accounts');
  const initial=request('/API/auth/csrf-token.php','csrf');csrf=initial.data.token||'';
  const result=request('/API/auth/login.php','login','POST',{identifier:account.email,password:account.password,remember:false},'login');
  const ok=result.ok && result.data.success===true && !result.data.otp_required;authOk.add(ok);
  if(!ok)exec.test.abort('Synthetic login failed. Check the fixture and application logs.');
  const refreshed=request('/API/auth/csrf-token.php','csrf');csrf=refreshed.data.token||'';authenticated=true;
}
export default async function(){
  if(!authenticated)login();
  request('/API/chat/get-channels.php?server_id='+target.server_id,'channels');
  request('/API/chat/get-channel.php?id='+target.channel_id,'channel');
  request('/API/chat/get-messages.php?channel_id='+target.channel_id+'&limit=50','messages');
  const wsToken=request('/API/auth/ws-token.php','ratchet-token').data.token;
  const connection=request('/API/auth/realtime-token.php','delivery-token','POST',{mode:'connection'}).data;
  const inbox=connection.inbox;
  const subscription=request('/API/auth/realtime-token.php','subscription-token','POST',{mode:'subscription',channel:inbox}).data;
  if(!wsToken||!connection.token||!subscription.token){socketOk.add(false);exec.test.abort('WebSocket token unavailable; ensure Centrifugo is enabled.');}
  const opening=Date.now(),deadline=opening+run.connection*1000;
  let joined=false,subscribed=false,closing=false,readBusy=false;
  const pending=new Map(),seen=new Map(),sockets=[];
  function publication(data){if(data?.type!=='chat_changed'||data.kind!=='channel'||data.target_id!==target.channel_id)return;publications.add(1);seen.set(data.message_id,Date.now());const start=pending.get(data.message_id);if(start!==undefined){deliveryTime.add(Date.now()-start);deliveryOk.add(true);pending.delete(data.message_id);}if(seen.size>512)seen.delete(seen.keys().next().value);}
  const ratchet=new WebSocket(target.ws_url,[],{headers:{Origin:base},tags:{name:'ratchet'}});sockets.push(ratchet);
  ratchet.onopen=()=>ratchet.send(JSON.stringify({type:'auth',ws_token:wsToken}));
  ratchet.onmessage=e=>{let data;try{data=JSON.parse(e.data);}catch(_){socketFailure('ratchet','invalid_json');return;}if(data.type==='auth_ok')ratchet.send(JSON.stringify({type:'join_channel',channel_id:target.channel_id}));if(data.type==='joined_channel')joined=true;if(data.type==='error'){const message=String(data.message||'');socketFailure('ratchet',/token|auth/i.test(message)?'authentication_rejected':/channel|access/i.test(message)?'channel_access_rejected':'server_error');}};
  ratchet.onerror=()=>socketFailure('ratchet','connection_error');ratchet.onclose=()=>{if(!plannedClose(closing,exec.scenario.progress,run))socketFailure('ratchet','unexpected_close');};
  const delivery=new WebSocket(base.replace(/^http/,'ws')+'/realtime/connection/websocket',[],{headers:{Origin:base},tags:{name:'centrifugo'}});sockets.push(delivery);
  delivery.onopen=()=>delivery.send(JSON.stringify({id:1,connect:{token:connection.token}}));
  delivery.onmessage=e=>{for(const line of String(e.data).split('\n')){if(!line.trim())continue;let data;try{data=JSON.parse(line);}catch(_){socketFailure('centrifugo','invalid_json');continue;}
    if(Object.keys(data).length===0){delivery.send('{}');continue;}if(data.error){socketFailure('centrifugo',data.id===1?'connection_rejected':data.id===2?'subscription_rejected':'command_rejected');continue;}if(data.id===1)delivery.send(JSON.stringify({id:2,subscribe:{channel:inbox,token:subscription.token}}));if(data.id===2)subscribed=true;if(data.push?.pub)publication(data.push.pub.data);if(data.push?.disconnect)socketFailure('centrifugo','server_disconnect');
  }};
  delivery.onerror=()=>socketFailure('centrifugo','connection_error');delivery.onclose=()=>{if(!plannedClose(closing,exec.scenario.progress,run))socketFailure('centrifugo','unexpected_close');};
  async function activity(){if(readBusy||closing)return;readBusy=true;
    try{ticks++;const response=await http.asyncRequest('GET',base+'/API/chat/get-messages.php?channel_id='+target.channel_id+'&limit=50',null,params('messages'));
      apiOk.add(response.status===200&&json(response).success===true);
      if(run.writeEvery&&ticks%run.writeEvery===0&&subscribed&&Date.now()<deadline-5000){const start=Date.now();const response=await http.asyncRequest('POST',base+'/API/chat/send-message.php',JSON.stringify({channel_id:target.channel_id,content:'[Capacity test '+target.fixture_id+'] student '+exec.vu.idInTest+' tick '+ticks,content_type:'text'}),params('send-message','write'));const data=json(response);const id=Number(data.message?.id);const ok=response.status===201&&data.success===true&&id>0;apiOk.add(ok);if(ok){if(seen.has(id)){deliveryTime.add(Math.max(0,seen.get(id)-start));deliveryOk.add(true);}else pending.set(id,start);}}
    }finally{readBusy=false;}
  }
  const work=setInterval(activity,run.interval*1000),heartbeat=setInterval(()=>{if(ratchet.readyState===1)ratchet.send(JSON.stringify({type:'ping'}));},15000);
  await new Promise(resolve=>setTimeout(()=>{closing=true;clearInterval(work);clearInterval(heartbeat);ratchetReady.add(joined);deliveryReady.add(subscribed);socketOk.add(joined&&subscribed);if(!joined)socketFailure('ratchet','channel_join_not_confirmed');if(!subscribed)socketFailure('centrifugo','subscription_not_confirmed');for(const _ of pending)deliveryOk.add(false);for(const socket of sockets)socket.close();resolve();},run.connection*1000));
}
export function handleSummary(data){
  const metric=name=>data.metrics[name]?.values||{};const failed=[];for(const [name,m] of Object.entries(data.metrics))for(const [threshold,result] of Object.entries(m.thresholds||{}))if(!result.ok)failed.push(name+': '+threshold);
  const report={fixture_id:target.fixture_id,configured_vus:run.vus,peak_vus:metric('vus').max??null,profile:{ramp_seconds:run.ramp,hold_seconds:run.hold,read_interval_seconds:run.interval,connection_seconds:run.connection,write_every:run.writeEvery},http:metric('http_req_duration'),requests:metric('http_reqs'),api:metric('api_ok'),authentication:metric('auth_ok'),sockets:metric('socket_ok'),ratchet:metric('ratchet_join_ok'),centrifugo:metric('centrifugo_subscribe_ok'),socket_failures:metric('socket_failures'),received_publications:metric('inbox_publications'),own_delivery:metric('own_delivery_ok'),own_delivery_ms:metric('own_delivery_ms'),failed_thresholds:failed};
  return {[__ENV.REPORT_FILE||'results-'+run.vus+'-users.json']:JSON.stringify(report,null,2),stdout:'\nCapacity test completed: '+run.vus+' configured users. '+(failed.length?'Thresholds failed; this load is not validated.':'Thresholds passed for this workload only.')+'\nResults saved as JSON. This test does not measure video/audio media or document editing.\n'};
}
