const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom'),Y=require('yjs'),protocol=require('y-protocols/awareness');
const frameSource=fs.readFileSync(__dirname+'/../assets/js/collab/yjs-presence.js','utf8').replace(/^import .*;$/gm,'').replace(/export function/g,'function');
const source=fs.readFileSync(__dirname+'/../assets/js/chat/yjs-channel-presence.js','utf8').replace(/^import .*;$/gm,'').replace(/export function/g,'function');
function client(uid){const dom=new JSDOM('');let timerId=0;const timers=new Map();const context={Y,...protocol,document:dom.window.document,window:dom.window,TextEncoder,Uint8Array,setTimeout:fn=>{timers.set(++timerId,fn);return timerId},clearTimeout:id=>timers.delete(id)};vm.createContext(context);vm.runInContext(frameSource,context);vm.runInContext(source,context);const messages=[];let states=new Map();const provider=context.createChannelPresence({send:m=>messages.push(m),changed:s=>states=new Map(s)});return{uid,dom,messages,provider,timers,get states(){return states}};}
const canonical=(c,m)=>({client_id:m.client_id,clock:m.clock,state:m.state?{...m.state,user:{id:c.uid,name:'User '+c.uid}}:null});
const a=client(1),b=client(2);
try{
 a.provider.receive({type:'joined_channel',channel_id:20,presence:[]});b.provider.receive({type:'joined_channel',channel_id:20,presence:[canonical(a,a.messages.at(-1))]});assert.equal(b.states.size,2);
 a.provider.typing(true);const typing=a.messages.at(-1);assert.equal(typing.state.typing,true);assert(!Object.hasOwn(typing.state,'text'),'no draft text is sent');
 const before=b.messages.length;b.provider.receive({type:'chat_presence',channel_id:20,presence:canonical(a,typing)});assert.equal(b.messages.length,before);assert([...b.states.values()].some(s=>s.user?.id===1&&s.typing));
 for(const callback of a.timers.values())callback();assert.equal(a.messages.at(-1).state.typing,false,'typing stops after idle timeout');
 const count=a.messages.length;a.provider.receive({type:'joined_channel',channel_id:21,presence:[]});assert.equal(a.messages.length,count+1,'channel change must not send old-client tombstone to new room');assert.equal(a.messages.at(-1).channel_id,21);
 a.provider.receive({type:'chat_presence',channel_id:20,presence:canonical(b,b.messages.at(-1))});assert.equal(a.states.size,1,'previous channel presence ignored');
 b.provider.receive({type:'chat_presence_remove',channel_id:20,client_ids:[typing.client_id]});assert.equal(b.states.size,1);
 b.provider.disconnect();assert.equal(b.states.size,0);assert.equal(b.provider.typing(true),false,'legacy typing fallback available before join');
 const whiteboard=fs.readFileSync(__dirname+'/../assets/js/collab/excalidraw-whiteboard.js','utf8');const start=whiteboard.indexOf('function liveSceneDelta('),end=whiteboard.indexOf('\nasync function loadBoard',start);const contextForDelta={Map,Set,Object,JSON};vm.createContext(contextForDelta);vm.runInContext(whiteboard.slice(start,end),contextForDelta);
 const delta=contextForDelta.liveSceneDelta({elements:[{id:'a',version:1,versionNonce:2},{id:'b',version:2,versionNonce:4,isDeleted:true}],files:{old:{},added:{}}},new Map([['a',JSON.stringify([1,2,false])]]),new Set(['old']));assert.equal(delta.elements.length,1);assert.equal(delta.elements[0].id,'b');assert.deepEqual(Object.keys(delta.files),['added']);
}catch(error){console.error(error);process.exitCode=1;}finally{a.provider.dispose();b.provider.dispose();a.dom.window.close();b.dom.window.close();}
if(!process.exitCode)console.log('PASS chat awareness: typing timeout, no draft exposure, no echo, channel isolation, clock lifecycle and disconnect');
