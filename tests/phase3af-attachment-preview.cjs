'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
 const d=new JSDOM('<body><div id="chatInputContainer"></div><input id="file" type="file"></body>',{url:'http://localhost',runScripts:'dangerously'}),w=d.window;
 try {
  w.ECOLLAB={userId:1,csrfToken:'owned'};const s=w.document.createElement('script');s.textContent=fs.readFileSync('assets/js/chat/chat.js','utf8');w.document.head.append(s);w.eval('currentChannelId=17');w.showToast=()=>{};
  let revoked;w.URL.createObjectURL=()=> 'blob:http://localhost/owned';w.URL.revokeObjectURL=u=>{revoked=u;};
  w.fetch=async()=>({ok:true,status:200,json:async()=>({success:true,file_name:'owned.png',file_path:'uploads/owned.png',file_size:10,mime_type:'image/png',url:'http://localhost/uploads/owned.png'})});
  const i=w.document.getElementById('file');Object.defineProperty(i,'files',{value:[new w.File(['owned'],'owned.png',{type:'image/png'})]});
  await w.handleFileUpload(i,'image');assert.equal(w.document.querySelector('#_attachPreview img').src,'blob:http://localhost/owned','Unbound uploads must preview from local bytes');w.clearAttachmentPreview();assert.equal(revoked,'blob:http://localhost/owned');assert.equal(w.document.getElementById('_attachPreview'),null);
  console.log('PASS local attachment preview and object URL cleanup');
 } finally {d.window.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
