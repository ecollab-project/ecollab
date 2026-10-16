'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
 const dom=new JSDOM('<button data-profile-user-id="2" data-profile-name="Rayven O&#39;Neil">Rayven O\'Neil</button><div id="profileCardModal" style="display:none"></div>',{runScripts:'outside-only',url:'https://ecollab.test/chat',pretendToBeVisual:true}),w=dom.window,d=w.document;
 w.ECOLLAB={userId:1};let pending=[];const preparedImages=[];
 w.Image=function(){const element=d.createElement('img');preparedImages.push(element);return element;};
 w.fetch=(url)=>new Promise(resolve=>pending.push({url,resolve}));
 w.eval(fs.readFileSync('assets/js/chat/profile-view.js','utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));
 const tick=()=>new Promise(r=>setTimeout(r,0));
 const answer=(request,id,name)=>request.resolve({ok:true,json:async()=>({success:true,profile:{id,full_name:name,username:'learner',role:'student',bio:'Test bio',interests:'C++, Python',mutual_servers:['Programming'],avatar_url:'/assets/logo.svg'}})});
 const trigger=d.querySelector('button');trigger.focus();trigger.click();assert(pending[0].url.endsWith('user_id=2'),'Stable ID routing with apostrophe in name');answer(pending.shift(),2,"Rayven O'Neil");await tick();
 assert.equal(d.getElementById('ecProfileName').textContent,"Rayven O'Neil");assert.equal(d.getElementById('ecProfileBio').textContent,'Test bio');assert.equal(d.getElementById('ecProfileAvatar').textContent,'R','Initial stays visible while avatar is loading');assert.equal(preparedImages[0].src,'https://ecollab.test/assets/logo.svg');preparedImages[0].onload();assert.equal(d.getElementById('ecProfileAvatar').querySelector('img')?.src,'https://ecollab.test/assets/logo.svg');assert.equal(d.querySelectorAll('.ec-profile-tag').length,2);
 d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(d.getElementById('profileCardModal').style.display,'none');assert.equal(d.activeElement,trigger);
 trigger.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));assert.equal(pending.length,1,'Keyboard activation loads profile');
 w.openFullProfileCard(3);answer(pending[1],3,'Latest profile');await tick();answer(pending[0],2,'Stale profile');await tick();assert.equal(d.getElementById('ecProfileName').textContent,'Latest profile','Slow previous response cannot replace latest profile');pending=[];
 w.openFullProfileCard(4);pending.shift().resolve({ok:false,status:404,json:async()=>({error:'User not found'})});await tick();assert.equal(d.getElementById('ecProfileName').textContent,'Profile unavailable');assert.equal(d.getElementById('ecProfileBio').textContent,'User not found');
 dom.window.close();console.log('PASS: profile click/keyboard, apostrophe names, avatar and content rendering, Escape/focus, response races and API errors');
})().catch(e=>{console.error(e);process.exitCode=1;});
