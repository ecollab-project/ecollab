const assert=require('node:assert/strict');
const {Centrifuge}=require('../tools/chat-delivery/node_modules/centrifuge');
const WebSocket=require('../tools/chat-delivery/node_modules/ws');
const {createHmac}=require('node:crypto');
const secret='a'.repeat(64),key='b'.repeat(64),base=process.env.TEST_BROKER_API||'http://127.0.0.1:18001/api';
function token(channel){const b=x=>Buffer.from(JSON.stringify(x)).toString('base64url');const head=b({alg:'HS256',typ:'JWT'}),claims=b({sub:'18',exp:Math.floor(Date.now()/1000)+300,...(channel?{channel}:{})});return head+'.'+claims+'.'+createHmac('sha256',secret).update(head+'.'+claims).digest('base64url');}
function once(emitter,event){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Timeout: '+event)),7000);emitter.once(event,x=>{clearTimeout(timer);resolve(x);});});}
async function publish(id){const r=await fetch(base+'/broadcast',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':key},body:JSON.stringify({channels:['inbox:user18'],data:{id}})});const j=await r.json();assert(!j.error);assert(!j.result.responses[0].error);}
(async()=>{
const c=new Centrifuge(base.replace('http','ws').replace('/api','/connection/websocket'),{token:token(),websocket:WebSocket});
const sub=c.newSubscription('inbox:user18',{token:token('inbox:user18')});let received=[];sub.on('publication',x=>received.push(x.data.id));
let joined=once(sub,'subscribed');sub.subscribe();c.connect();await joined;
await assert.rejects(sub.publish({id:99}),e=>e.code===103); // Browser publish denied.
await publish(1);await new Promise(r=>setTimeout(r,80));assert.deepEqual(received,[1]);
c.disconnect();await publish(2);await publish(3);joined=once(sub,'subscribed');c.connect();const recovery=await joined;await new Promise(r=>setTimeout(r,100));assert.equal(recovery.recovered,true);assert.deepEqual(received,[1,2,3]);
const denied=c.newSubscription('inbox:user19',{token:token('inbox:user18')});const rejected=once(c,'disconnected');denied.subscribe();const rejection=await rejected;assert(rejection.code >= 3500);c.disconnect();
console.log('Real broker: private inbox, publish denial, message delivery and reconnect recovery passed');
})().catch(e=>{console.error(e);process.exit(1);});
