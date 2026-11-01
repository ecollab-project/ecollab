'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const dom=new JSDOM('<body></body>',{runScripts:'dangerously',url:'http://localhost'}),w=dom.window;
try {
 w.ECOLLAB={userId:1};const script=w.document.createElement('script');script.textContent=fs.readFileSync('assets/js/chat/chat.js','utf8');w.document.head.append(script);
 const content="');window.codeExecuted=true;// <img src=x onerror=alert(1)>";const username="');window.nameExecuted=true;//";
 const row=w.buildMessageElement({id:12,sender_id:1,username,content,content_type:'code',created_at:'2026-10-31 00:00:00'});w.document.body.append(row);
 assert.equal(row.querySelector('pre code').textContent,content);assert.equal(row.querySelector('.msg-text img'),null);
 let reply,pin,menu;w.msgReply=(...a)=>{reply=a;};w.msgPin=(...a)=>{pin=a;};w.showMsgMenu=(...a)=>{menu=a;};
 row.querySelector('[title="Reply"]').click();assert.equal(w.codeExecuted,undefined);assert.equal(w.nameExecuted,undefined);assert.deepEqual(reply.slice(1),[username,12,content.substring(0,60)]);
 row.querySelector('[title="Pin Message"]').click();assert.deepEqual(pin.slice(1),[username,content.substring(0,60),12]);row.querySelector('[title="More Options"]').click();assert.equal(menu[2],username);
 console.log('PASS literal code and inert reply/pin/menu arguments');
} finally {dom.window.close();}
