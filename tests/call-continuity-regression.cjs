'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const filename='assets/js/calls/call-continuity.js';const peers=[],storage=new Map(),channels=new Map();
const tick=()=>new Promise(r=>setTimeout(r,10));
function page(uid,owner=false,fallback=false){
 const dom=new JSDOM('<a id="lab" href="/modules/code-lab/index.php?server_id=1">Code Lab</a><a id="outside" href="https://example.org/">Outside</a><a id="anchor" href="#section">Section</a>',{url:'https://ecollab.test/modules/chat/chat.php',runScripts:'outside-only'}),w=dom.window;peers.push(w);
 w.setInterval=()=>1;w.ECOLLAB_CALLS_CONFIG={userId:uid,baseUrl:''};w.fetch=async()=>({ok:true});
 const state={active:owner,muted:false,deafened:false},actions=[],opened=[];
 w.EcollabCalls={get snapshot(){return state.active?{active:true,state:'active',kind:'direct',muted:state.muted,deafened:state.deafened}:null;},get room(){return state.active?{}:null;},async control(action){actions.push(action);if(action==='mic')state.muted=!state.muted;if(action==='deafen')state.deafened=!state.deafened;if(action==='end')state.active=false;}};
 w.open=url=>{const popup={closed:false,location:{origin:w.location.origin,href:String(url)},focus(){},opener:w};opened.push(popup);return popup;};
 Object.defineProperty(w,'localStorage',{value:{getItem:key=>storage.get(key)||null,setItem(key,value){storage.set(key,value);for(const other of peers)if(other!==w)queueMicrotask(()=>other.dispatchEvent(new other.StorageEvent('storage',{key,newValue:value})));},removeItem(key){storage.delete(key);for(const other of peers)if(other!==w)queueMicrotask(()=>other.dispatchEvent(new other.StorageEvent('storage',{key,newValue:null})));}}});
 if(!fallback)w.BroadcastChannel=class {constructor(name){this.name=name;(channels.get(name)||channels.set(name,[]).get(name)).push(this);}postMessage(data){for(const peer of channels.get(this.name))if(peer!==this)queueMicrotask(()=>peer.onmessage?.({data:JSON.parse(JSON.stringify(data))}));}close(){}};
 w.navigation=new w.EventTarget();w.eval(fs.readFileSync(filename,'utf8'));
 return {dom,w,state,actions,opened};
}
(async()=>{const a=page(7,true),b=page(7),other=page(8);await tick();
const click=new a.w.MouseEvent('click',{bubbles:true,cancelable:true,button:0});a.w.document.getElementById('lab').dispatchEvent(click);
assert(click.defaultPrevented,'navigation must preserve the media-owning document');assert.equal(a.opened.length,1);assert(a.opened[0].location.href.includes('/modules/code-lab/'));assert.equal(a.opened[0].opener,null,'workspace does not retain an opener');assert(a.state.active,'navigation never ends the call');
assert(b.w.EcollabCallContinuity.remoteActive());assert(!other.w.EcollabCallContinuity.remoteActive(),'another account never sees call status');
b.w.document.querySelector('[data-continuity=mic]').click();await tick();assert(a.state.muted);assert.equal(a.actions.filter(x=>x==='mic').length,1,'dual transport cannot toggle twice');
const nav=new a.w.Event('navigate',{cancelable:true});Object.assign(nav,{destination:{url:'https://ecollab.test/modules/student/dashboard.php'},canIntercept:true,navigationType:'push',hashChange:false});a.w.navigation.dispatchEvent(nav);assert(nav.defaultPrevented,'imperative same-origin navigation is protected');
const reload=new a.w.Event('navigate',{cancelable:true});Object.assign(reload,{destination:{url:a.w.location.href},canIntercept:true,navigationType:'reload'});a.w.navigation.dispatchEvent(reload);assert(!reload.defaultPrevented,'explicit reload is not trapped');
a.opened[0].closed=true;a.w.open=()=>null;const blocked=new a.w.MouseEvent('click',{bubbles:true,cancelable:true,button:0});a.w.document.getElementById('lab').dispatchEvent(blocked);assert(blocked.defaultPrevented&&a.state.active,'blocked popup preserves the call');
assert(a.w.document.querySelector('[data-continuity-status]').textContent.includes('Allow the workspace tab'));
const form=a.w.document.createElement('form');form.action='/modules/code-lab/index.php';a.w.document.body.append(form);const submit=new a.w.Event('submit',{bubbles:true,cancelable:true});form.dispatchEvent(submit);assert(submit.defaultPrevented,'blocked form submission cannot unload media');
await b.w.fetch('/API/auth/logout.php',{method:'POST'});await tick();assert(!a.state.active,'logout ends the call in its owner tab');
const v=page(9,false,true),vRemote=page(9,false,true);v.w.EcollabLiveKit={room:{state:'connected',localParticipant:{isMicrophoneEnabled:true}}};v.w.disconnectVoice=async()=>{v.actions.push('end');v.w.EcollabLiveKit.room=null;};v.w.toggleVcMic=async()=>{v.actions.push('mic');v.w.EcollabLiveKit.room.localParticipant.isMicrophoneEnabled=false;};v.w.EcollabCallContinuity.refresh();await tick();assert(vRemote.w.EcollabCallContinuity.remoteActive());vRemote.w.document.querySelector('[data-continuity=mic]').click();await tick();assert.deepEqual(v.actions,['mic'],'voice controls work using storage alone');vRemote.w.document.querySelector('[data-continuity=end]').click();await tick();assert.deepEqual(v.actions,['mic','end']);assert(!vRemote.w.EcollabCallContinuity.remoteActive());
for(const p of [a,b,other,v,vRemote])p.dom.window.close();console.log('PASS: call-preserving navigation, remote controls, identity isolation, popup blocking and logout');
})().catch(e=>{console.error(e);for(const w of peers)w.close();process.exitCode=1;});
