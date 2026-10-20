'use strict';
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const voice=read('assets/js/chat/voice.js'),chat=read('assets/js/chat/chat.js');
function section(source,start,end){const a=source.indexOf(start),b=source.indexOf(end,a+start.length);assert(a>=0&&b>a);return source.slice(a,b);}
(async()=>{
 const dom=new JSDOM('<main class="chat-main" style="display:none"><div id="voiceChannelView" class="active"><button class="vc-minimize-btn"></button></div></main><div id="navViewOverlay"></div><div id="vcScreenSection"></div><div id="vcScreenGrid"></div><span id="vcScreenSectionCount"></span><button id="vcUnwatchBtn"></button>',{url:'https://ecollab.test',runScripts:'outside-only'}),w=dom.window;
 w.HTMLElement.prototype.scrollIntoView=()=>{};
 w.requestAnimationFrame=callback=>callback();
 w.eval('var vcActive=true,vcMinimized=false;var _watchedScreenUsers=new Set();var _remoteScreenStreams={};function _refreshVoiceLayout(){}');
 w.eval(section(voice,'function toggleVcMinimize()','// ── Render current user card'));
 w.toggleVcMinimize();assert.equal(w.document.getElementById('voiceChannelView').parentNode,w.document.body,'active voice must not remain inside a hidden chat section');assert(w.document.body.classList.contains('vc-pip'));
 w.toggleVcMinimize();assert(!w.document.getElementById('voiceChannelView').classList.contains('vc-minimized'));
 w._currentNavView='drafts';w.switchView=name=>{w._currentNavView=name;};w.saveChatLocation=()=>{};w.eval('var currentServerId=5,currentChannelId=20;var lastServerChannels=new Map();');
 w.eval(section(chat,'async function switchChannel(', '\nfunction '));await w.switchChannel(null,20);assert.equal(w._currentNavView,'home');assert.equal(w.document.querySelector('.chat-main').style.display,'');assert.equal(w.document.getElementById('navViewOverlay').style.display,'none');assert(w.vcActive,'switching sidebar views must keep the call active');
 w.eval(section(voice,'function _showRemoteScreenShareSection(', '\nfunction _hideRemoteScreenShareSection'));
 w.eval(section(voice,'function _hideRemoteScreenShareSection(', '\nfunction '));
 w.eval(section(voice,'function _applyScreenWatchState(', 'window.toggleScreenWatch ='));
 const stream={};w._showRemoteScreenShareSection(2,'Peer',stream);w.toggleScreenWatch(2);const card=w.document.querySelector('[data-screen-user="2"]');assert(card.classList.contains('vc-screen-expanded'));w._showRemoteScreenShareSection(2,'Peer',{});assert.equal(w.document.querySelector('[data-screen-user="2"]'),card);assert(card.classList.contains('vc-screen-expanded'),'subscription refresh must preserve Watch');w._hideRemoteScreenShareSection(2);assert(!w.document.querySelector('[data-screen-user="2"]'));
 w.eval(section(chat,'function chatMemberOnline(', '\nfunction '));assert(!w.chatMemberOnline({is_online:'0'}));assert(w.chatMemberOnline({is_online:'1'}));assert(!w.chatMemberOnline({is_online:false}));
 const source=read('assets/js/collab/excalidraw-whiteboard.js');w.eval(section(source,'function mergeSceneElements(', '\nasync function loadBoard'));
 let merged=w.mergeSceneElements([{id:'a',version:2,versionNonce:3,isDeleted:true},{id:'b',version:1}], [{id:'a',version:1},{id:'c',version:1}]);assert.equal(merged.length,3);assert(merged[0].isDeleted,'old scene cannot resurrect deletion');assert.equal(w.sceneFingerprint(merged,'#fff'),w.sceneFingerprint([...merged].reverse(),'#fff'),'render order alone does not trigger a broadcast');
 dom.window.close();console.log('PASS: Drafts-to-channel restore, voice retained outside hidden views, Watch survives refresh, strict presence, concurrent drawing merge and echo suppression');
})().catch(error=>{console.error(error);process.exitCode=1;});
