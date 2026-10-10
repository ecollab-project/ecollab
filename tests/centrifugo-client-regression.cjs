const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('../tools/chat-delivery/node_modules/jsdom');
const root=require('node:path').resolve(__dirname,'..');
(async()=>{
const dom=new JSDOM('<div id="dmMessagesArea"><div data-msg-id="10"></div></div>',{url:'https://ecollab.tech'});const w=dom.window;
w.ECOLLAB={centrifugoEnabled:true,userId:18,baseUrl:''};w.apiFetch=async(_url,opts)=>({inbox:'inbox:user18',token:JSON.parse(opts.body).mode});
class Mock {constructor(url,opts){this.url=url;this.opts=opts;Mock.instance=this;this.events={};this.state='connected';}on(k,fn){this.events[k]=fn;}newSubscription(ch,opts){this.sub={channel:ch,opts,events:{},on(k,fn){this.events[k]=fn;},subscribe(){}};return this.sub;}connect(){}disconnect(){}}
let src=fs.readFileSync(root+'/tools/chat-delivery/index.js','utf8').replace("import { Centrifuge } from 'centrifuge';",'const Centrifuge = Mock;').replace('export function','function');
const context=vm.createContext({window:w,document:w.document,CustomEvent:w.CustomEvent,URL,console,Mock});vm.runInContext(src,context);const c=Mock.instance;
assert.equal(c.url,'wss://ecollab.tech/realtime/connection/websocket');assert.equal(await c.opts.getToken(),'connection');assert.equal(await c.sub.opts.getToken(),'subscription');
let events=[];w.addEventListener('ecollab:delivery-sync',e=>events.push(e.detail));c.sub.events.subscribed({});c.sub.events.subscribed({recovered:false});
const publication={data:{type:'chat_changed',kind:'dm',target_id:2,message_id:10,event_id:5}};
c.sub.events.publication(publication);c.sub.events.publication(publication);c.sub.events.publication({data:{...publication.data,target_id:-1}});
assert.deepEqual(events.map(e=>e.kind),['all','all','dm']);
// Events arriving while a read is pending must cause another read; stale responses must not append.
let release,requests=0,appended=[];
Object.defineProperty(w.document,'hidden',{value:false,configurable:true});
const syncContext=vm.createContext({window:w,document:w.document,DM:{activeGroupId:3,activePartnerId:null,activeConvId:null},BASE:()=>'',_appendDmMessage:m=>appended.push(m.id),setTimeout:()=>{},apiFetch:async()=>{requests++;if(requests===1)return await new Promise(r=>release=r);return {messages:[{id:12}],has_more:false};}});
let dm=fs.readFileSync(root+'/assets/js/chat/dm-notifications.js','utf8');dm=dm.slice(dm.indexOf('let dmSyncBusy = false;')>=0?dm.indexOf('let dmSyncBusy = false;'):dm.indexOf('let dmSyncBusy = false,'),dm.indexOf('// ═',dm.indexOf('let dmSyncBusy = false')));
vm.runInContext(dm,syncContext);const pending=vm.runInContext('_syncOpenDmMessages()',syncContext);vm.runInContext('_syncOpenDmMessages()',syncContext);release({messages:[{id:11}],has_more:false});await pending;assert.equal(requests,2);assert.deepEqual(appended,[11,12]);
requests=0;appended=[];const stale=vm.runInContext('_syncOpenDmMessages()',syncContext);syncContext.DM.activeGroupId=null;syncContext.DM.activePartnerId=null;release({messages:[{id:999}],has_more:false});await stale;assert.deepEqual(appended,[]);
// More than one page of missed messages must be read in order.
syncContext.DM.activeGroupId=7;syncContext.DM.activePartnerId=null;requests=0;appended=[];
syncContext.apiFetch=async(url)=>{requests++;const after=Number(new URL('https://ecollab.tech'+url).searchParams.get('after'));return requests===1?{messages:Array.from({length:50},(_,i)=>({id:after+i+1})),has_more:true}:{messages:[{id:after+1}],has_more:false};};
await vm.runInContext('_syncOpenDmMessages()',syncContext);assert.equal(requests,2);assert.equal(appended.length,51);assert.equal(appended[50],61);
console.log('Browser adapter: scoped tokens, reconnect sync, duplicate events, queued reads and stale target guards passed');dom.window.close();
})().catch(e=>{console.error(e);process.exit(1);});
