'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const dom=new JSDOM('<nav id="workspaceSwitcher"><div id="workspaceCallDock"></div></nav><a id="collabs" href="/modules/collaboration/server-coworkspaces.php?server_id=5&channel_id=20">Collabs</a><a id="lab" href="/modules/code-lab/index.php?server_id=5">Code Lab</a><a id="library" href="/modules/library/library.php?server_id=5">Library</a>',{url:'https://ecollab.test/modules/chat/chat.php?server_id=5&channel_id=20',runScripts:'outside-only'});
const w=dom.window,opened=[],media={};let ends=0;
w.matchMedia=()=>({matches:true});w.setInterval=()=>1;w.ECOLLAB_CALLS_CONFIG={userId:7,baseUrl:''};w.EcollabCalls={snapshot:{active:true,state:'active',kind:'direct'},room:media,control:()=>{ends++;}};w.open=url=>{opened.push(url);return {opener:null,focus(){},location:{origin:w.location.origin},closed:false};};
w.eval(fs.readFileSync('assets/js/calls/call-dock.js','utf8'));w.eval(fs.readFileSync('assets/js/calls/call-continuity.js','utf8'));
try{let initialFrame;
 for(const id of ['collabs','lab','library']){const event=new w.MouseEvent('click',{bubbles:true,cancelable:true,button:0});w.document.getElementById(id).dispatchEvent(event);assert(event.defaultPrevented);assert.equal(opened.length,0,id+' cannot open a browser tab');const frame=w.document.getElementById('ecCallWorkspaceFrame');assert(frame,'workspace renders inside the current page');assert.equal(frame.src,w.document.getElementById(id).href);if(initialFrame)assert.equal(frame,initialFrame,'one frame is reused');initialFrame=frame;assert.equal(w.EcollabCalls.room,media);assert.equal(ends,0);}
 for(const role of ['student','facilitator','admin']){w.EcollabCallContinuity.navigate('/modules/'+role+'/dashboard.php');assert(initialFrame.src.includes('/'+role+'/dashboard.php'));assert.equal(opened.length,0,'dashboard navigation stays in this tab');}
 w.document.querySelector('[data-call-workspace-close]').click();assert(w.document.getElementById('ecCallWorkspace').hidden,'return closes the workspace view');assert.equal(w.EcollabCalls.room,media);assert.equal(ends,0);assert.equal(w.location.search,'?server_id=5&channel_id=20','media-owning route remains intact');
 console.log('PASS: Collabs, Library, Code Lab and all role dashboards reuse one inline workspace and preserve media');
}finally{w.close();}
