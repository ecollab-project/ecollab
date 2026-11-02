'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('assets/js/chat/chat-features.js','utf8');
const start=source.indexOf('async function _loadPrivateMemberSelector()');
const end=source.indexOf('// ── Expose openMiniProfile',start);
for(const denied of [false,true])test(denied?'member selector rejects HTTP failure':'member selector uses the existing member API without a preliminary broken URL',async()=>{
 const dom=new JSDOM('<div id="privateMembersLoading"></div><div id="privateMembersList"></div>',{runScripts:'outside-only'}),w=dom.window;
 try{
  const calls=[];w.ECOLLAB={baseUrl:'/ecollab',currentServerId:12,csrfToken:'token'};
  w._esc=s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;');
  w.fetch=async(url,options)=>{calls.push({url,options});if(!url.includes('/API/threads/get-server-members.php'))throw Error('Missing endpoint');return {ok:!denied,json:async()=>({success:true,members:[{id:8,full_name:'Test Learner'}]})};};
  w.eval(source.slice(start,end)+';window.loadSelector=_loadPrivateMemberSelector;');
  await w.loadSelector();
  assert.equal(calls.length,1);
  assert.equal(new URL(calls[0].url,'https://ecollab.test').pathname,'/ecollab/API/threads/get-server-members.php');
  assert.equal(new URL(calls[0].url,'https://ecollab.test').searchParams.get('server_id'),'12');
  assert.equal(w.document.getElementById('privateMembersLoading').style.display,'none');
  if(denied){assert.match(w.document.getElementById('privateMembersList').textContent,/Failed to load/);assert.equal(w.document.querySelector('[data-user-id]'),null);}
  else{const member=w.document.querySelector('[data-user-id="8"]');assert(member);member.click();assert(w._privateChannelSelectedUsers.has(8));member.click();assert(!w._privateChannelSelectedUsers.has(8));}
 }finally{w.close();}
});
