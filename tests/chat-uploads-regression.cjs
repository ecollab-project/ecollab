const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const path = require('node:path');
const root = path.resolve(__dirname,'..');
const source = fs.readFileSync(path.join(root,'tools/chat-uploads/index.js'),'utf8').replace(/^import .*;\n/gm,'');
const dom = new JSDOM('<body></body>',{url:'https://example.test/modules/chat/chat.php',runScripts:'outside-only'});
const w = dom.window;
const instances=[];
class FakeUppy {
  constructor(options){this.options=options;this.events={};instances.push(this);}
  use(Plugin,options){this.tus=options;return this;}
  addFile(file){this.file={...file,id:'file',tus:{uploadUrl:'https://example.test/API/chat/resumable-upload.php?id=abc'}};return 'file';}
  getFile(){return this.file;}
  on(event,fn){this.events[event]=fn;return this;}
  emit(event,...args){this.events[event]?.(...args);}
  async upload(){}
  pauseResume(){this.paused=!this.paused;return this.paused;}
  cancelAll(){this.cancelled=true;}
  destroy(){this.destroyed=true;}
}
w.Uppy=FakeUppy; w.Tus=class{}; w.ECOLLAB={userId:18,csrfToken:'old',baseUrl:'https://example.test'};
const requests=[];
w.fetch=async (url,options={})=>{
  requests.push({url,options});
  return {ok:true,json:async()=>url.endsWith('csrf-token.php')?{token:'fresh'}:{success:true,file_path:'uploads/a.png',file_name:'a.png'}};
};
vm.runInContext(source,dom.getInternalVMContext());
const tick=()=>new Promise(r=>setImmediate(r));
const file={name:'a.png',size:40,type:'image/png',lastModified:10};
(async()=>{
  await assert.rejects(w.EcollabUploads.upload(file,{kind:'unknown',id:1}),/Select a chat/);
  await assert.rejects(w.EcollabUploads.upload({...file,size:21*1024*1024},{kind:'dm',id:3}),/20 MB/);
  const first=w.EcollabUploads.upload(file,{kind:'channel',id:20}); await tick();
  const u=instances.at(-1);
  assert.equal(u.options.id,'ecollab-18-channel-20');assert.equal(u.tus.chunkSize,1024*1024);
  let sentHeader;
  u.tus.onBeforeRequest({setHeader:(name,value)=>{sentHeader=[name,value];}});
  assert.deepEqual(sentHeader,['X-CSRF-Token','fresh']);
  assert.equal(u.file.meta.kind,'channel');assert.equal(u.file.meta.target,'20');
  u.emit('upload-progress',u.file,{bytesUploaded:20,bytesTotal:40});
  assert.equal(w.document.querySelector('progress').value,50);
  w.document.querySelector('button').click();assert.equal(u.paused,true);
  assert.equal(w.document.querySelector('button').textContent,'Resume');
  w.document.querySelector('button').click();assert.equal(u.paused,false);
  assert.equal(u.tus.onShouldRetry({originalResponse:{getStatus:()=>403}},0,{},()=>true),false);
  u.emit('upload-success',u.file,{uploadURL:u.file.tus.uploadUrl});
  const result=await first;assert.equal(result.file_path,'uploads/a.png');
  assert.equal(requests.at(-1).options.headers['X-CSRF-Token'],'fresh');
  assert.equal(u.destroyed,true);assert.equal(w.document.querySelector('#chatUploadTransfers'),null);
  const second=w.EcollabUploads.upload(file,{kind:'dm',id:20}); await tick();
  assert.equal(instances.at(-1).options.id,'ecollab-18-dm-20');
  const rejection=assert.rejects(second,/cancelled/);
  w.document.querySelectorAll('button')[1].click();await rejection;
  assert(requests.some(r=>r.options.method==='DELETE'));assert.equal(instances.at(-1).cancelled,true);

  // A late channel upload must never populate the composer after switching channel.
  const chat=fs.readFileSync(path.join(root,'assets/js/chat/chat.js'),'utf8');
  const handler=chat.slice(chat.indexOf('async function handleFileUpload('),chat.indexOf('\nfunction showAttachmentPreview'));
  let resolveUpload;
  w.EcollabUploads={upload:()=>new Promise(r=>{resolveUpload=r;})};
  w.showToast=()=>{};w.showAttachmentPreview=()=>{throw new Error('Wrong chat preview');};
  vm.runInContext('let currentChannelId=20;let attachmentUploadGeneration=0;let pendingAttachment=null;const UPLOAD_ENDPOINT="";'+handler,dom.getInternalVMContext());
  w.ECOLLAB.resumableUploads=true;
  w.inputStub={files:[file],value:'a.png'};
  const operation=vm.runInContext('handleFileUpload(inputStub,"image")',dom.getInternalVMContext());
  vm.runInContext('currentChannelId=21',dom.getInternalVMContext());resolveUpload(result);await operation;
  assert.equal(vm.runInContext('pendingAttachment',dom.getInternalVMContext()),null);
  console.log('Chat upload controls and navigation regression checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
