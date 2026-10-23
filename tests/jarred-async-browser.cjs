'use strict';
// Actual apiFetch/send controller/status watcher in Chromium; synthetic DOM renderer/HTTP backend.
const fs=require('fs'),http=require('http'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const dm=fs.readFileSync('assets/js/chat/dm-notifications.js','utf8'),chat=fs.readFileSync('assets/js/chat/chat.js','utf8');
const controller=dm.slice(dm.indexOf('window.sendDmMessage ='),dm.indexOf('window.closeDmPanel ='));
const watch=dm.slice(dm.indexOf('let _jarredWatchGeneration='));
const api=chat.slice(chat.indexOf('async function apiFetch'),chat.indexOf('async function uploadChatAttachment'));
let state='queued',mode='normal',job=0,statusReads=0;
const html=`<!doctype html><meta charset="utf-8"><div id="dmMessagesArea"></div><div id="dmTypingIndicator"></div><textarea id="dmInputField"></textarea><div id="toast"></div><button data-channel-id="5">Channel</button><script>
window.ECOLLAB={csrfToken:'fixture'};window.DM={activeConvId:1,activeGroupId:null,activePartnerId:2,activePartnerIsAI:true,conversations:[]};let _dmPendingFile=null;
function BASE(){return ''}function ME_ID(){return 1}window.ws=[];function _wsSend(e){ws.push(e)}
function _appendDmMessage(m){if(document.querySelector('[data-msg-id="'+m.id+'"]'))return;const e=document.createElement('p');e.dataset.msgId=m.id;e.textContent=m.body;document.getElementById('dmMessagesArea').append(e)}
function _showAiTyping(){document.getElementById('dmTypingIndicator').textContent='Pending'}function _hideAiTyping(){document.getElementById('dmTypingIndicator').textContent=''}function _renderDmList(){}function showToast(m){document.getElementById('toast').textContent=m}
window.opened=0;document.querySelector('button').onclick=()=>window.opened++;
${api}\n${controller}\n${watch}</script>`;
const server=http.createServer(async(req,res)=>{
 res.setHeader('Content-Type','application/json');let body='';for await(const chunk of req)body+=chunk;
 if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
 if(req.url==='/API/dm/send-message.php'){
  if(mode==='busy'){res.writeHead(409);res.end(JSON.stringify({error:'Jarred is still replying. Wait or cancel the pending reply.'}));return;}
  const input=JSON.parse(body);job++;state='queued';res.writeHead(input.conversation_id===2?200:202);res.end(JSON.stringify({success:true,message_id:job,recipient_id:input.conversation_id===2?3:2,is_ai:input.conversation_id!==2,ai_job_id:input.conversation_id===2?null:job,body:input.body,created_at:'2026-10-23 00:00:00'}));return;
 }
 if(req.url.startsWith('/API/dm/jarred-job.php')){
  statusReads++;if(mode==='outage'){res.writeHead(503);res.end(JSON.stringify({error:'Jarred status unavailable'}));return;}
  if(body){assert.equal(JSON.parse(body).action,'cancel');state='cancelled';}
  res.end(JSON.stringify({job:{id:job,state,ai_message:state==='done'?{id:100+job,body:'Synthetic reply'}:null,ai_action:state==='done'?{type:'open_channel',channel_id:5}:null}}));return;
 }
 res.writeHead(404);res.end('{}');
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  for(const width of [390,1440]){
   const context=await browser.newContext({viewport:{width,height:850}}),page=await context.newPage();mode='normal';statusReads=0;await page.goto('http://127.0.0.1:'+server.address().port);
   await page.fill('#dmInputField','Synthetic question');await page.evaluate(()=>sendDmMessage());await page.waitForSelector('[data-cancel-jarred]');
   assert(await page.locator('#dmTypingIndicator').innerText());assert.equal(await page.locator('#dmInputField').isDisabled(),false);assert.equal(await page.evaluate(()=>ws.length),0,'AI source is not broadcast by browser');
   state='done';await page.evaluate(()=>window.dispatchEvent(new CustomEvent('ecollab:delivery-sync',{detail:{kind:'dm',target_id:1}})));await page.waitForFunction(()=>document.getElementById('dmMessagesArea').textContent.includes('Synthetic reply'));assert.equal(await page.evaluate(()=>opened),1);
   await page.evaluate(()=>_watchJarredJob(1));assert.equal(await page.evaluate(()=>opened),1,'reopen must not repeat navigation');assert.equal(await page.locator('#dmMessagesArea p').count(),2,'duplicate status retains one source and one reply');
   await page.fill('#dmInputField','Cancel this');await page.evaluate(()=>sendDmMessage());await page.waitForSelector('[data-cancel-jarred]');await page.click('[data-cancel-jarred]');await page.waitForFunction(()=>document.getElementById('toast').textContent.includes('cancelled'));
   mode='busy';await page.fill('#dmInputField','Busy attempt');await page.evaluate(()=>sendDmMessage());assert((await page.locator('#toast').innerText()).includes('still replying'));
   mode='normal';await page.evaluate(()=>{DM.activeConvId=2;DM.activePartnerId=3;DM.activePartnerIsAI=false});await page.fill('#dmInputField','Human DM');await page.evaluate(()=>sendDmMessage());assert((await page.evaluate(()=>ws)).some(e=>e.type==='dm_message'),'human websocket delivery retained');
   await context.close();console.log('PASS Chromium async202, pending, realtime wake, status deduplication, cancel, busy and human DM at width '+width);
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
