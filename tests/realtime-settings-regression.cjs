'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
 const d=new JSDOM('<div id="messagesArea"><div data-msg-id="1" class="message-group"><div class="msg-content"><img src="/test.gif"><span class="msg-timestamp">time</span></div></div></div><div id="dmMessagesArea"></div>',{runScripts:'outside-only',pretendToBeVisual:true}),w=d.window,doc=w.document;
 w.eval(fs.readFileSync('assets/js/chat/preferences.js','utf8'));const prefs=w.EcollabPreferences;
 prefs.apply({show_timestamps:0,typing_indicators:0,media_previews:0,screen_reader_mode:1,enter_to_send:0});assert(doc.documentElement.classList.contains('ec-no-show_timestamps'));assert(doc.documentElement.classList.contains('ec-no-typing_indicators'));assert.equal(doc.getElementById('messagesArea').getAttribute('aria-live'),'polite');assert(doc.querySelector('img').hidden);assert.equal(prefs.enabled('enter_to_send'),false);
 doc.querySelector('.ec-preview-control').click();assert(!doc.querySelector('img').hidden);prefs.apply({media_previews:1,autoplay_gifs:0});assert(doc.querySelector('img').hidden,'Animation requires explicit reveal');prefs.apply({autoplay_gifs:1,show_timestamps:1});assert(!doc.querySelector('img').hidden);assert(!doc.documentElement.classList.contains('ec-no-show_timestamps'));
 doc.querySelector('.msg-content').insertAdjacentHTML('beforeend','<div class="msg-text"><a href="https://example.invalid/guide">Read</a></div>');prefs.apply({link_previews:1});assert(doc.querySelector('.ec-link-preview'));prefs.apply({link_previews:0});assert(doc.querySelector('.ec-link-preview').hidden);
 w.ECOLLAB={userId:1};w.eval(fs.readFileSync('assets/js/chat/timeline.js','utf8'));const t=w.EcollabTimeline,area=doc.getElementById('dmMessagesArea');
 let formatterCalls=0;const original=w.Date.prototype.toLocaleDateString;w.Date.prototype.toLocaleDateString=function(...a){formatterCalls++;return original.apply(this,a);};
 function message(id){const e=doc.createElement('div');e.dataset.msgId=id;e.dataset.senderId=2;e.dataset.messageTimestamp='2026-10-17 01:00:00';return e;}
 const first=message(1);area.append(first);t.reset(area,2);t.decorate(area);const separator=area.querySelector('.ec-chat-day');const began=performance.now();
 for(let n=2;n<=1001;n++){assert(!t.has(area,n));const next=message(n);area.append(next);t.append(area,next);assert(t.has(area,n));}
 assert.equal(area.querySelectorAll('.ec-chat-day').length,1);assert.equal(area.querySelector('.ec-chat-day'),separator,'Existing date separator remains the same DOM node through 1,000 live appends');assert.equal(area.querySelectorAll('.ec-chat-unread').length,1);assert.equal(formatterCalls,0,'Cached Intl formatter avoids per-message date formatting setup');
 area.querySelector('[data-msg-id="500"]').remove();assert(!t.has(area,500),'Removed nodes cannot cause false duplicate matches');await new Promise(r=>setTimeout(r,0));assert.equal(area.querySelectorAll('.ec-chat-day').length,1);assert(t.has(area,1001));
 console.log('PASS: preference effects, explicit media reveal, screen-reader log, stable unread/date nodes and 1,000 incremental appends ('+Math.round(performance.now()-began)+'ms, local DOM test only)');w.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
