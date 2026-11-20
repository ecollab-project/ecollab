'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const dom=new JSDOM('<div id="dmTypingIndicator"></div><input id="dmInputField"><div id="dmMessagesArea"></div>',{url:'https://ecollab.test',runScripts:'outside-only'}),w=dom.window;
let source=fs.readFileSync('assets/js/chat/dm-notifications.js','utf8');const start=source.indexOf("if (document.readyState === 'loading') {"),end=source.indexOf('// Export for global access',start);source=source.slice(0,start)+source.slice(end);
w.eval(source+'\nwindow.fixture=DM;window.jobStatus=_applyJarredJob;window.syncAi=_syncAiTyping;');w.ECOLLAB={userId:1};w.CSS={escape:String};w.showToast=()=>{};
w.eval('_appendDmMessage=m=>{const e=document.createElement("div");e.dataset.msgId=m.id;e.innerHTML="<div class=dm-message-bubble></div>";document.getElementById("dmMessagesArea").append(e);};');
const dm=w.fixture,input=w.document.getElementById('dmInputField'),typing=w.document.getElementById('dmTypingIndicator');
(async()=>{try{
 dm.activeConvId=1;dm.activePartnerId=99;dm.activePartnerIsAI=true;let posts=0;
 w.apiFetch=async(url,options)=>{posts++;return {success:true,message_id:11,is_ai:true,ai_pending:true,ai_job:{id:1,state:'queued',request_message_id:11}};};input.value='A long question';await w.sendDmMessage();assert.equal(posts,1);assert(dm.aiPending.has(1));assert(input.disabled,'queued acknowledgement must not unlock the same conversation');assert(typing.textContent.includes('queued'),'queued state is visible after HTTP returns');
 dm.activeConvId=2;dm.activePartnerIsAI=false;w.syncAi();assert.equal(typing.style.display,'none','background typing never leaks into human chat');
 dm.activeConvId=1;dm.activePartnerIsAI=true;w.jobStatus(1,{id:1,state:'running'},11);assert(typing.textContent.includes('preparing'));w.jobStatus(1,{id:1,state:'done',reply_message_id:12});assert(!input.disabled&&!dm.aiPending.has(1),'completed background reply releases composer');
 w.jobStatus(1,{id:2,state:'failed'},21);const button=w.document.querySelector('#dmJarredStatus button');assert(button);button.focus();w.jobStatus(1,{id:2,state:'failed'},21);assert.equal(w.document.activeElement,button,'polling does not steal Retry keyboard focus');
 w.apiFetch=async(url,options)=>{assert(url.includes('jarred-retry.php'));assert.equal(JSON.parse(options.body).message_id,21);posts++;return {ai_job:{id:2,state:'queued'}};};button.click();await new Promise(r=>setTimeout(r,0));assert.equal(posts,2,'retry uses separate saved-message endpoint');assert(dm.aiPending.has(1));assert(w.document.getElementById('dmJarredStatus').hidden);
 w.jobStatus(1,{id:2,state:'failed'},21);assert(!w.document.querySelector('#dmJarredStatus button').disabled,'a second failure must offer an enabled retry');
 w.jobStatus(1,null,30);assert(w.document.querySelector('#dmJarredStatus button'),'legacy saved unanswered question can retry after upgrade');
 console.log('PASS: fast queued ack, persistent pending state, conversation isolation, completion, saved-question retry and focus');
}finally{w.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
