'use strict';
const {chromium}=require('playwright'),http=require('http'),fs=require('fs'),assert=require('node:assert/strict');
const voice=fs.readFileSync('assets/js/chat/voice.js','utf8');
function section(start,end){const a=voice.indexOf(start),b=voice.indexOf(end,a+start.length);assert(a>=0&&b>a);return voice.slice(a,b);}
const functions=section('function _showRemoteScreenShareSection(', '\nfunction _hideRemoteScreenShareSection')+section('function _hideRemoteScreenShareSection(', '\nfunction ')+section('function _applyScreenWatchState(', 'window.toggleScreenWatch =');
const css=fs.readFileSync('assets/css/desktop/chat.css','utf8').replace(/@import\s+url\([^)]*\);/g,'')+fs.readFileSync('assets/css/calls/calls.css','utf8');
const server=http.createServer((req,res)=>{res.setHeader('Content-Type','text/html');res.end(`<style>${css}</style><body><div id="voiceChannelView" class="active" style="position:relative;inset:auto;width:100%;height:80vh;max-width:100%"><div class="vc-body"><section id="vcScreenSection"><div id="vcScreenGrid" class="vc-screen-grid"></div><span id="vcScreenSectionCount"></span></section></div></div><button id="vcUnwatchBtn" onclick="unwatchAllScreens()">Unwatch</button><script>
var _watchedScreenUsers=new Set(),_remoteScreenStreams={},vcMinimized=false;
function toggleVcMinimize(){vcMinimized=!vcMinimized;document.getElementById('voiceChannelView').classList.toggle('vc-minimized',vcMinimized);}
${functions}
window.testTracks=[];for(const [uid,name] of [[1,'You'],[2,'Remote peer']]){const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;const ctx=canvas.getContext('2d');ctx.fillStyle=uid===1?'#257b42':'#4266c9';ctx.fillRect(0,0,640,360);ctx.fillStyle='white';ctx.font='30px sans-serif';ctx.fillText(name,20,100);const stream=canvas.captureStream(1);testTracks.push(stream.getVideoTracks()[0]);_showRemoteScreenShareSection(uid,name,stream);}
</script></body>`);});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));let browser;try{
 browser=await chromium.launch(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{});
 for(const width of [390,1280]){const page=await browser.newPage({viewport:{width,height:900},hasTouch:width<500});await page.goto('http://127.0.0.1:'+server.address().port);
 for(const uid of [2,1]){const card=page.locator('[data-screen-user="'+uid+'"]'),button=card.locator('.vc-screen-watch-btn');await card.hover();await button.click();assert((await card.getAttribute('class')).includes('vc-screen-expanded'),'real Watch click expands '+uid);assert(await card.evaluate(el=>el.getBoundingClientRect().height)>200,'expanded stream is visible');await button.click();assert(!(await card.getAttribute('class')).includes('vc-screen-expanded'),'Restore is reachable');}
 await page.locator('[data-screen-user="2"] .vc-screen-watch-btn').focus();await page.keyboard.press('Enter');assert((await page.locator('[data-screen-user="2"]').getAttribute('class')).includes('vc-screen-expanded'),'keyboard Watch works');assert(await page.evaluate(()=>testTracks.every(t=>t.readyState==='live')),'watch controls never stop local or remote tracks');await page.close();}
 console.log('PASS: Chromium desktop/touch Watch and Restore for remote/own streams, keyboard access and live track preservation');
}finally{await browser?.close();await new Promise(r=>server.close(r));}})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
