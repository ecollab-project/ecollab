'use strict';
// Real document/frame navigation and cross-tab transport, with a stub media controller.
const {chromium}=require('playwright'),http=require('http'),fs=require('fs'),assert=require('node:assert/strict');
const dock=fs.readFileSync('assets/js/calls/call-dock.js','utf8');
const script=fs.readFileSync('assets/js/calls/call-continuity.js','utf8'),css=fs.readFileSync('assets/css/calls/calls.css','utf8');
const server=http.createServer((req,res)=>{
 res.setHeader('Content-Type','text/html');res.setHeader('X-Frame-Options','SAMEORIGIN');res.setHeader('Content-Security-Policy',"frame-ancestors 'self'");
 const owner=req.url.includes('owner=1');
 res.end(`<title>Workspace</title><style>${css}</style><nav id="workspaceSwitcher"><div id="workspaceCallDock"></div><button id="muteBtn">Mic</button><button id="deafenBtn">Audio</button></nav><button id="bookmarks" onclick="history.replaceState({},'','?owner=1&view=bookmarks');document.body.dataset.view='bookmarks'">Bookmarks</button><a id="collabs" href="/modules/collaboration/server-coworkspaces.php?server_id=5">Collabs</a><a id="lab" href="/modules/code-lab/index.php?server_id=5">Code Lab</a><a id="library" href="/modules/library/library.php?server_id=5">Library</a><a id="back" href="/modules/chat/chat.php">Back to messages</a><button id="dashboard" onclick="location.href='/modules/student/dashboard.php'">Dashboard</button><script>
 window.ECOLLAB_CALLS_CONFIG={userId:7,baseUrl:''};window.state={active:${owner},muted:false,deafened:false,ends:0};window.mediaIdentity={};
 window.EcollabCalls={get snapshot(){return {...state,state:'active',kind:'direct'}},async control(action){if(action==='mic')state.muted=!state.muted;if(action==='deafen')state.deafened=!state.deafened;if(action==='end'){state.active=false;state.ends++}}};
 </script><script>${dock}</script><script>${script}</script>`);
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));let browser;
 try{
  browser=await chromium.launch(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{});
  const context=await browser.newContext(),owner=await context.newPage(),base='http://127.0.0.1:'+server.address().port;
  await owner.goto(base+'/modules/chat/chat.php?owner=1');await owner.evaluate(()=>window.originalMedia=mediaIdentity);
  await owner.locator('#bookmarks').click();assert.equal(await owner.getAttribute('body','data-view'),'bookmarks');assert.equal(context.pages().length,1,'history-only sidebar actions cannot create another tab');
  for(const [link,path] of [['collabs','/modules/collaboration/server-coworkspaces.php'],['lab','/modules/code-lab/index.php'],['library','/modules/library/library.php'],['dashboard','/modules/student/dashboard.php']]){
   await owner.locator('#'+link).click();const frame=await owner.locator('#ecCallWorkspaceFrame').elementHandle();await owner.waitForFunction(p=>document.getElementById('ecCallWorkspaceFrame').contentWindow.location.pathname===p,path);
   assert.equal(context.pages().length,1,link+' must not open another tab');assert.equal(await owner.locator('#ecCallWorkspaceFrame').count(),1,'inline frame is reused');assert(await owner.evaluate(()=>state.active&&mediaIdentity===originalMedia&&state.ends===0),'original media controller stays intact');
   const child=await frame.contentFrame();await child.waitForLoadState();assert.equal(await child.locator('#bookmarks').count(),1,'framed workspace actually loaded with SAMEORIGIN policy');
   await owner.locator('[data-call-workspace-close]').click();assert(await owner.locator('#ecCallWorkspace').isHidden());
  }
  await owner.locator('#lab').click();let child=owner.frames().find(f=>f!==owner.mainFrame());await child.waitForURL('**/modules/code-lab/index.php?server_id=5');await child.locator('#back').click();assert(await owner.locator('#ecCallWorkspace').isHidden(),'workspace Back returns without unloading call');assert.equal(context.pages().length,1);
  const remote=await context.newPage();await remote.goto(base+'/modules/chat/chat.php');await remote.waitForFunction(()=>EcollabCallContinuity.remoteActive());
  assert.equal(await owner.locator('#ecCallContinuityPanel').count(),0);assert.equal(await remote.locator('#workspaceCallDock #ecRemoteCallBar').count(),1);
  await remote.locator('#muteBtn').click();await owner.waitForFunction(()=>state.muted);await remote.locator('[data-continuity=end]').click();await owner.waitForFunction(()=>!state.active);assert.equal(await owner.evaluate(()=>state.ends),1);
  await owner.evaluate(()=>{state.active=true;EcollabCallContinuity.refresh();});await owner.locator('#lab').click();child=owner.frames().find(f=>f!==owner.mainFrame());await child.waitForURL('**/modules/code-lab/index.php?server_id=5');await child.evaluate(()=>fetch('/API/auth/logout.php',{method:'POST'}));await owner.waitForFunction(()=>!state.active);assert.equal(await owner.evaluate(()=>state.ends),2,'logout from inline workspace ends original call');
  await context.close();console.log('PASS: Chromium keeps all four workspaces in one tab, retains media/dock, bridges Back/logout and remote mute/leave');
 }finally{await browser?.close();await new Promise(r=>server.close(r));}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
