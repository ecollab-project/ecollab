'use strict';
// Isolated rendering of production markup/styles; no API or media connection.
const {chromium}=require('playwright'),{JSDOM}=require('jsdom'),http=require('node:http'),fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync('modules/chat/chat.php','utf8').replace(/<\?[\s\S]*?\?>/g,''),doc=new JSDOM(source).window.document;
const markup=['workspaceSwitcher','vcConnectedBar','voiceChannelView','vcLeaveModal'].map(id=>doc.getElementById(id).outerHTML).join('');
const styles=['assets/css/desktop/chat.css','assets/css/desktop/chat-taskbar.css','assets/css/app-design.css','assets/css/mobile/app-mobile.css','assets/css/device-layout.css','assets/css/calls/calls.css'].map(p=>fs.readFileSync(p,'utf8').replace(/@import\s+url\([^)]*\);/g,'')).join('\n');
const voice=fs.readFileSync('assets/js/chat/voice.js','utf8'),chat=fs.readFileSync('assets/js/chat/chat.js','utf8');
const leave=voice.slice(voice.indexOf('function leaveVoice()'),voice.indexOf('function disconnectVoice()'));
const modals=chat.slice(chat.indexOf('const modalDisplayTimers ='),chat.indexOf('window.openModal ='));
const dock=fs.readFileSync('assets/js/calls/call-dock.js','utf8');
const server=http.createServer((req,res)=>{res.setHeader('Content-Type','text/html');res.end(`<style>${styles}</style><body data-mobile-surface="chat" class="vc-active">${markup}<script>
let settingsOpened=0,disconnects=0;function openAudioSettings(){settingsOpened++;}function toggleVcMic(){}function toggleVcPanelFromBar(){document.getElementById('voiceChannelView').classList.remove('vc-minimized');}function disconnectVoice(){disconnects++;document.body.classList.remove('vc-active');document.getElementById('voiceChannelView').classList.remove('active');document.getElementById('vcConnectedBar').style.display='none';EcollabCallDock.refresh();}
${modals}\n${leave}\n${dock}
const panel=document.getElementById('voiceChannelView');panel.classList.add('active');const bar=document.getElementById('vcConnectedBar');bar.style.display='';EcollabCallDock.refresh();
</script>`);});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));let browser;try{
 browser=await chromium.launch(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{});
 for(const [width,height] of [[390,844],[860,900],[861,900],[1024,768],[1440,900],[1920,863]]){
  const page=await browser.newPage({viewport:{width,height}});await page.route('**/*',r=>r.request().url().startsWith('http://127.0.0.1:')?r.continue():r.abort());await page.goto('http://127.0.0.1:'+server.address().port);
  const button=page.getByRole('button',{name:'Leave voice channel',exact:true});
  for(const minimized of [false,true]){
   await page.locator('#voiceChannelView').evaluate((e,m)=>e.classList.toggle('vc-minimized',m),minimized);
   const hit=await button.evaluate(e=>{const r=e.getBoundingClientRect();return e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));});assert(hit,`Leave hit target reachable at ${width}, minimized=${minimized}`);
   await button.click();await page.locator('#vcLeaveModal').waitFor({state:'visible'});assert.equal(await page.evaluate(()=>settingsOpened),0,'Leave never opens settings');
   await page.locator('#vcLeaveModal').getByRole('button',{name:'Stay',exact:true}).click();await page.locator('#vcLeaveModal').waitFor({state:'hidden'});assert.equal(await page.evaluate(()=>disconnects),0,'Stay keeps call connected');
  }
  await button.focus();await page.keyboard.press('Enter');await page.locator('#vcLeaveModal').getByRole('button',{name:'Leave Call',exact:true}).click();assert.equal(await page.evaluate(()=>disconnects),1);assert.equal(await button.isVisible(),false);assert.equal(await page.evaluate(()=>settingsOpened),0);await page.close();
 }
 console.log('PASS: real pointer/keyboard Leave, Stay and disconnect at six viewport sizes; expanded/minimized panels never intercept dock clicks');
}finally{await browser?.close();await new Promise(r=>server.close(r));}})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
