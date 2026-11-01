'use strict';
// DOM unit test only;API transport is simulated. Hosted Chromium remains required.
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const dom=new JSDOM('<body></body>',{runScripts:'outside-only',url:'http://localhost'}),w=dom.window;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
 w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 w.ECOLLAB={currentChannelId:17};w.showToast=()=>{};w.closeExtrasMenu=()=>{};
 let collabs=0,request,fail=true,rendered=0;
 w.openCollabHub=()=>{collabs++;};w.appendMessageToUI=()=>{rendered++;};
 w.apiFetch=async(url,options)=>{request={url,body:JSON.parse(options.body)};if(fail)throw Error('Owned failure');return {success:true,message:{id:42}};};
 w.eval(fs.readFileSync('assets/js/chat/functionality-overrides.js','utf8'));
 w.openExtrasAction('code');const d=w.document.querySelector('#channelCodeDialog'),input=d.querySelector('textarea');
 assert(d.open);assert.equal(collabs,0);input.value='<img src=x onerror=alert(1)>\nconst n = 1;';
 d.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true}));await new Promise(r=>setTimeout(r,0));
 assert.equal(request.body.content_type,'code');assert.equal(request.body.channel_id,17);assert.equal(request.body.content,input.value);assert(d.open);assert.match(d.textContent,/Owned failure/);assert(input.value.length>0);
 fail=false;w.ECOLLAB.currentChannelId=18;
 d.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true}));await new Promise(r=>setTimeout(r,0));
 assert.equal(request.body.channel_id,17);assert.equal(rendered,0);assert.equal(input.value,'');assert.equal(d.open,false);
 w.openExtrasAction('resource');assert.equal(collabs,1);dom.window.close();console.log('PASS code editor DOM:destination,error draft,success,Collabs preservation');
})().catch(e=>{console.error(e);process.exitCode=1;});
