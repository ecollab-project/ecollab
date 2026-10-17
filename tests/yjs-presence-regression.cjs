const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom'),Y=require('yjs'),protocol=require('y-protocols/awareness');
const code=fs.readFileSync(__dirname+'/../assets/js/collab/yjs-presence.js','utf8').replace(/^import .*;$/gm,'').replace(/export function/g,'function');
const tick=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function client(userId){const dom=new JSDOM('',{runScripts:'outside-only'}),window=dom.window;const context={Y,...protocol,window,document:window.document,TextEncoder,Uint8Array,setTimeout,clearTimeout};vm.createContext(context);vm.runInContext(code,context);let calls=0,last;const messages=[];const presence=context.createWhiteboardPresence({channelId:20,whiteboardId:10},{send:m=>{messages.push(m);return true},changed:(states,id)=>{calls++;last=new Map(states);}});return{dom,window,presence,messages,get states(){return last},get calls(){return calls},userId};}
function canonical(client,packet){return{client_id:packet.client_id,clock:packet.clock,state:packet.state===null?null:{...packet.state,user:{id:client.userId,name:'User '+client.userId,color:'#3b82f6'}}};}
(async()=>{const a=client(1),b=client(2);try{
 a.presence.receive({type:'wb_joined',whiteboard_id:10,presence:[]});b.presence.receive({type:'wb_joined',whiteboard_id:10,presence:[canonical(a,a.messages.at(-1))]});
 assert.equal(b.states.size,2,'join snapshot includes local and remote awareness');
 const before=b.messages.length;b.presence.receive({type:'wb_presence',whiteboard_id:10,presence:canonical(a,a.messages.at(-1))});assert.equal(b.messages.length,before,'receiving presence must never echo');
 for(let i=0;i<20;i++)a.presence.pointer({pointer:{x:i,y:20},button:'down'});await tick(120);assert.equal(a.messages.length,2,'pointer events coalesce into one update');
 b.presence.receive({type:'wb_presence',whiteboard_id:10,presence:canonical(a,a.messages.at(-1))});assert.equal(b.states.get(a.messages[0].client_id).pointer.x,19);
 const old=a.messages[0];b.presence.receive({type:'wb_presence',whiteboard_id:10,presence:canonical(a,old)});assert.equal(b.states.get(old.client_id).pointer.x,19,'Yjs clocks prevent stale cursor rollback');
 b.presence.receive({type:'wb_presence_remove',whiteboard_id:11,client_ids:[old.client_id]});assert.equal(b.states.size,2,'other boards ignored');
 a.presence.selection({shape:true});b.presence.receive({type:'wb_presence',whiteboard_id:10,presence:canonical(a,a.messages.at(-1))});assert(b.states.get(old.client_id).selectedElementIds.shape);
 b.window.dispatchEvent(new b.window.Event('ecollab:realtime-disconnected'));assert.equal(b.states.size,1,'lost network clears remote cursors');
 b.presence.receive({type:'wb_joined',whiteboard_id:10,presence:[canonical(a,a.messages.at(-1))]});assert.equal(b.states.size,2,'reconnect restores peers even at unchanged clock');
 b.presence.receive({type:'wb_presence_remove',whiteboard_id:10,client_ids:[old.client_id]});assert.equal(b.states.size,1,'disconnect removes cursor immediately');
 a.window.dispatchEvent(new a.window.Event('blur'));assert.equal(a.messages.at(-1).state.status,'idle');
 a.presence.dispose();assert.equal(a.messages.at(-1).state,null,'closing clears local awareness');
 console.log('PASS Yjs: join, real protocol frames, throttling, no echoes, stale clocks, selection, room isolation, disconnect and idle');
 }finally{a.presence.dispose();b.presence.dispose();a.dom.window.close();b.dom.window.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
