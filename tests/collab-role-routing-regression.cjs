'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('assets/js/chat/functionality-overrides.js','utf8');
for(const role of ['student','facilitator','admin'])test(role+' collaboration menu follows the existing role policy without opening an unrelated pane',()=>{
 const dom=new JSDOM(`<div id="extrasMenu"><div data-collab-tool="code"></div></div><div id="collabHub"><div class="collab-tab-bar"><button class="collab-tab-btn" data-tool="notes"></button><button class="collab-tab-btn" data-tool="code"></button></div><div class="collab-pane" id="collabPane_notes"></div><div class="collab-pane" id="collabPane_code"></div></div>`,{runScripts:'outside-only'}),w=dom.window;
 try{
  const opened=[];w.ECOLLAB={role};w.openCollabHub=tool=>opened.push(tool);w._switchCollabTool=tool=>opened.push(tool);
  w.eval(source.slice(source.indexOf('(function configureChatCollaborationHub()')));
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  assert.equal(w.document.querySelector('[data-collab-tool=code]').style.display,role==='student'?'none':'');
  w.openCollabHub('code');assert.deepEqual(opened,role==='student'?[]:['code']);
  opened.length=0;w.openCollabHub('notes');assert.deepEqual(opened,['notes']);
 }finally{w.close();}
});
