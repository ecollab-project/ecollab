'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{const d=new JSDOM('<body></body>',{url:'http://localhost',runScripts:'dangerously'}),w=d.window;try{
 w.ECOLLAB={userId:1};function load(p){const s=w.document.createElement('script');s.textContent=fs.readFileSync(p,'utf8');w.document.head.append(s);}load('assets/js/chat/chat.js');w.showToast=()=>{};
 const original='  const a = "<b>literal</b>";\n';const row=w.buildMessageElement({id:12,sender_id:1,username:'owned',content_type:'code',content:original,created_at:'2026-11-01 00:00:00'});w.document.body.append(row);
 w.startEditMsg(row,original,12);w.cancelEditMsg(row.querySelector('#_editHint span:nth-child(2)'),original);assert.equal(row.querySelector('pre code')?.textContent,original,'Cancel must preserve literal code');
 let body;w.fetch=async(_url,options)=>{body=JSON.parse(options.body);return{ok:true,status:200,json:async()=>({success:true,message:{id:12,content:body.content,content_type:'code'}})};};
 const edited='  const b = "<i>literal</i>";\n';w.startEditMsg(row,original,12);const field=row.querySelector('textarea.channel-code-edit');if(field)field.value=edited;else{const t=row.querySelector('.msg-text');t.replaceChildren(w.document.createTextNode(edited.slice(0,-1)),w.document.createElement('br'));}await w.saveEditMsg(row.querySelector('#_editHint span'),12);assert.equal(body.content,edited,'Save must preserve whitespace');assert.equal(row.querySelector('pre code').textContent,edited);
 load('assets/js/chat/socket-core.js');w.EcollabComposer={render:()=>'<b>must not render code as markup</b>'};w.handleMessageEdited({id:12,content:original,content_type:'code'});assert.equal(row.querySelector('pre code').textContent,original);assert.equal(row.querySelector('.msg-text b'),null);
 console.log('PASS code edit cancel/save/realtime preserves literal text and whitespace');
}finally{d.window.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
