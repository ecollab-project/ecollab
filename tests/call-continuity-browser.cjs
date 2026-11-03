'use strict';
// Real browser navigation and cross-tab transport, with a stub media controller.
const {chromium}=require('playwright'),http=require('http'),fs=require('fs'),assert=require('node:assert/strict');
const script=fs.readFileSync('assets/js/calls/call-continuity.js','utf8'),css=fs.readFileSync('assets/css/calls/calls.css','utf8');
const server=http.createServer((req,res)=>{
 res.setHeader('Content-Type','text/html');
 const owner=req.url.includes('owner=1');
 res.end(`<title>Workspace</title><style>${css}</style><a id="lab" href="/modules/code-lab/index.php">Code Lab</a><button id="dashboard" onclick="location.href='/modules/student/dashboard.php'">Dashboard</button><script>
 window.ECOLLAB_CALLS_CONFIG={userId:7,baseUrl:''};window.state={active:${owner},muted:false,deafened:false,ends:0};window.mediaIdentity={};
 window.EcollabCalls={get snapshot(){return {...state,state:'active',kind:'direct'}},async control(action){if(action==='mic')state.muted=!state.muted;if(action==='deafen')state.deafened=!state.deafened;if(action==='end'){state.active=false;state.ends++}}};
 </script><script>${script}</script>`);
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));let browser;
 try{
  browser=await chromium.launch(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{});
  const context=await browser.newContext(),owner=await context.newPage();
  const base='http://127.0.0.1:'+server.address().port;
  await owner.goto(base+'/modules/chat/chat.php?owner=1');await owner.evaluate(()=>window.originalMedia=mediaIdentity);
  const opened=context.waitForEvent('page');await owner.locator('#lab').click();const workspace=await opened;await workspace.waitForLoadState();
  assert(workspace.url().includes('/modules/code-lab/'));assert.equal(await workspace.evaluate(()=>window.opener),null);
  await workspace.waitForFunction(()=>EcollabCallContinuity.remoteActive());
  await workspace.locator('[data-continuity=mic]').click();await owner.waitForFunction(()=>state.muted);
  assert(await owner.evaluate(()=>state.active&&mediaIdentity===originalMedia&&state.ends===0),'owner and media controller survive tool navigation');
  await workspace.goto(base+'/modules/collaboration/server-coworkspaces.php');await workspace.waitForFunction(()=>EcollabCallContinuity.remoteActive());
  await owner.locator('#dashboard').click();await workspace.waitForURL('**/modules/student/dashboard.php');
  assert.equal(context.pages().length,2,'workspace tab is reused');assert(owner.url().includes('owner=1'),'imperative navigation leaves owner intact');
  await workspace.waitForFunction(()=>EcollabCallContinuity.remoteActive());await workspace.locator('[data-continuity=end]').click();await owner.waitForFunction(()=>!state.active);
  assert.equal(await owner.evaluate(()=>state.ends),1,'leave reaches owner once');await context.close();
  console.log('PASS: Chromium keeps owner intact across tool/dashboard navigation and remote mute/leave');
 }finally{await browser?.close();await new Promise(r=>server.close(r));}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
