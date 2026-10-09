'use strict';
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
(async()=>{
for(const role of ['student','facilitator','admin']){
 const home=role==='admin'?'overview':'dashboard';
 const dom=new JSDOM(`<aside class="sidebar open"></aside><div id="sidebarOverlay" class="open"></div><div id="page-${home}" class="page-section active"></div><div id="page-resources" class="page-section"></div><div id="nav-resources" class="nav-item"></div>`,{url:'https://test.invalid/dashboard.php?page=resources&server_id=5',runScripts:'outside-only'}),w=dom.window;
 w.closeAllDD=w.closeAllDropdowns=()=>{};
 w.eval(read('assets/js/dashboard-state.js'));
 const source=read(`assets/js/${role}/dashboard.js`),start=source.indexOf('function showPage('),end=source.indexOf('// ═══ MODALS',start);
 w.eval(source.slice(start,end));w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 assert(w.document.querySelector('#page-resources').classList.contains('active'),role+' restore');assert(!w.document.querySelector('.sidebar').classList.contains('open'));
 w.showPage(home);assert.equal(new URL(w.location.href).searchParams.get('page'),home);assert.equal(new URL(w.location.href).searchParams.get('server_id'),'5');
 w.history.replaceState(null,'','?page=resources');w.dispatchEvent(new w.PopStateEvent('popstate'));assert(w.document.querySelector('#page-resources').classList.contains('active'));
 w.showPage('invalid');assert(w.document.querySelector('#page-resources').classList.contains('active'));
 dom.window.close();
}
const source=read('modules/chat/settings.php');
let script=[...source.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(x=>x[1]).find(x=>x.includes('const BASE='));
script=script.replace(/<\?=[\s\S]*?\?>/g,m=>m.includes("$user['id']")?'18':m.includes('$name')?'"Test User"':'""');
const dom=new JSDOM(source.replace(/<\?(?:php|=)[\s\S]*?\?>/g,'').replace(/<script[^>]*>[\s\S]*?<\/script>/g,''),{url:'https://test.invalid/settings.php?section=voice',runScripts:'outside-only'}),w=dom.window;
let settings={font_scale:130,input_device:'selected-mic',noise_suppression:false},constraints;
w.matchMedia=()=>({matches:false});w.alert=m=>{throw Error(m)};w.fetch=async()=>({ok:true,json:async()=>({settings,profile:{}})});
Object.defineProperty(w.navigator,'mediaDevices',{value:{enumerateDevices:async()=>[],getUserMedia:async value=>{constraints=value;return {getTracks:()=>[{stop(){}}]};}}});
w.eval(script);await new Promise(r=>setTimeout(r,0));assert.equal(w.document.getElementById('title').textContent,'Voice & Video');assert.equal(w.document.documentElement.style.fontSize,'130%');
w.document.querySelector('[data-section=appearance]').click();assert.equal(new URL(w.location.href).searchParams.get('section'),'appearance');
w.history.replaceState(null,'','?section=voice');w.dispatchEvent(new w.PopStateEvent('popstate'));assert.equal(w.document.getElementById('title').textContent,'Voice & Video');
await w.document.getElementById('testMic').onclick();assert.equal(constraints.audio.deviceId.exact,'selected-mic');assert.equal(constraints.audio.noiseSuppression,false);
w.document.querySelector('[data-section=account]').click();w.document.getElementById('openPassword').click();assert.equal(w.document.activeElement.id,'currentPassword');w.document.getElementById('passwordModal').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert(!w.document.getElementById('passwordModal').classList.contains('open'));assert.equal(w.document.activeElement.id,'openPassword');
w.close();console.log('PASS: role navigation restore/Back, invalid sections, drawer closing, settings URL, font scaling, selected microphone and password Escape/focus');
})().catch(e=>{console.error(e);process.exitCode=1;});
