'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('assets/js/chat/chat.js', 'utf8');
const features = fs.readFileSync('assets/js/chat/chat-features.js', 'utf8');
const dom = new JSDOM('<input id="chatInputField"><div id="membersList"></div><span id="memberCountBadge"></span><div id="membersModalList"></div><input id="membersSearch"><div id="membersStatusTabs"></div>');
const pending = [];
const context = vm.createContext({window: dom.window, document: dom.window.document, console, API_BASE: '/API/chat', currentChannelId: 80,
  apiFetch: url => new Promise((resolve, reject) => pending.push({url, resolve, reject})), chatPresenceSnapshot: null,
  chatAvatarUrl: () => '', chatSidebarAvatar: () => '', chatMemberOnline: m => Number(m.is_online) === 1,
  openModal: () => {},});
context.window.ECOLLAB = {currentChannelId:80,userId:1};
vm.runInContext(source.slice(source.indexOf('function escHtml('), source.indexOf('function timeAgo(')), context);
vm.runInContext(source.slice(source.indexOf('let memberDirectoryPending'), source.indexOf('function _hideMentionDropdown')), context);
vm.runInContext(source.slice(source.indexOf('function renderMembersPanel('), source.indexOf('async function refreshMembersPanel(')), context);
vm.runInContext(features.slice(features.indexOf("let _membersTab ="), features.indexOf('function switchMembersTab(')), context);
vm.runInContext(features.slice(features.indexOf('function _esc('), features.indexOf('// ═',features.indexOf('function _esc('))), context);
const members = Array.from({length:1000}, (_,i) => ({id:i+1, username:'member'+i, full_name:i===999?'Outside preview':"O'Neil "+i, is_online: i<5 ? 1 : 0, account_type: i===999 ? 'professor' : i<3 ? 'student' : 'personal'}));
(async () => {
  context.renderMembersPanel(members.slice(0,20),1000);
  assert.equal(dom.window.document.querySelectorAll('.member-item').length,20);
  assert.match(dom.window.document.getElementById('memberCountBadge').textContent,/1000/);
  assert.match(dom.window.document.querySelector('.members-more').textContent,/980/);
  const first=context.loadChatMemberDirectory(), second=context.loadChatMemberDirectory();
  assert.equal(pending.length,1,'Overlapping requests share one HTTP request');
  assert.match(pending[0].url,/id=80&members=all$/);
  pending.shift().resolve({success:true,has_access:true,members});
  await Promise.all([first,second]);
  assert.equal(context.window.serverMembers.length,1000);
  assert.equal(context._getModalMembersSource().length,1000,'Modal no longer limited to sidebar');
  vm.runInContext("_membersSearch='outside preview'; _renderMembersModal();",context);
  assert.match(dom.window.document.getElementById('membersModalList').textContent,/Outside preview/);
  assert.equal(dom.window.document.querySelector('#membersModalList [data-profile-user-id]').dataset.profileUserId,'1000','Profile uses the actual user ID');
  context.filterMembersByAccountType('student');
  assert.match(dom.window.document.getElementById('membersModalList').textContent,/No members found/,'Account type and name search combine');
  vm.runInContext("_membersSearch=''",context);
  context.filterMembersByAccountType('student');
  assert.equal(dom.window.document.querySelectorAll('#membersModalList .account-type-badge[data-account-type="student"]').length,3);
  context.filterMembersByAccountType('professor');
  assert.equal(dom.window.document.querySelectorAll('#membersModalList .account-type-badge[data-account-type="professor"]').length,1);
  assert.match(dom.window.document.getElementById('membersModalList').textContent,/Outside preview/);
  context.filterMembersByAccountType('');
  const stale=context.loadChatMemberDirectory();
  context.currentChannelId=81; context.window.serverMembers=[];
  pending.shift().resolve({success:true,has_access:true,members}); await stale;
  assert.equal(context.window.serverMembers.length,0,'Old channel cannot replace new roster');
  const denied=context.loadChatMemberDirectory();
  pending.shift().resolve({success:true,has_access:false,members:[]});
  await assert.rejects(denied,/unavailable/);
  const retry=context.loadChatMemberDirectory();
  assert.equal(pending.length,1,'Failed requests can retry');
  pending.shift().resolve({success:true,has_access:true,members:[]}); await retry;
  assert.equal(context.window.serverMembers.length,0);
  vm.runInContext(source.slice(source.indexOf('let mentionRoster'), source.indexOf('function _insertMention(')), context);
  context._mentionActive = true;
  context._mentionQuery = 'legal shota';
  context._showMentionDropdown('legal shota');
  assert.equal(pending.length,1,'Mentions request the channel-scoped roster');
  assert.match(pending[0].url,/id=81&members=mentions$/);
  pending.shift().resolve({success:true,has_access:true,members:[
    {id:1,username:'self',nickname:'Legal Shota'},
    {...members[999],nickname:'Legal Shota'},
    {id:2000,username:'other_account',nickname:'Legal Shota'},
  ]});
  await new Promise(resolve=>setImmediate(resolve));
  const suggestions=dom.window.document.querySelectorAll('.mention-item');
  assert.equal(suggestions.length,2,'The sender is excluded even if returned by the API');
  assert.match(suggestions[0].textContent,/Legal Shota/);
  assert.match(suggestions[0].textContent,/member999/,'Duplicate nicknames have an account cue');
  let selected; context._insertMention = (name,id) => {selected=[name,id];};
  suggestions[0].click(); assert.deepEqual(selected,['Legal Shota','1000']);
  context._showMentionDropdown('every');
  const everyone=dom.window.document.querySelector('.mention-item');
  assert.match(everyone.textContent,/@everyone/);
  everyone.click(); assert.deepEqual(selected,['everyone','']);
  context.currentChannelId = 82;
  context._mentionActive=true;
  context._mentionQuery='new'; context._showMentionDropdown('new');
  context._mentionActive=false;
  pending.shift().resolve({success:true,has_access:true,members});
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(dom.window.document.getElementById('mentionDropdown').style.display,'none','Dismissed mention popup stays dismissed');
  console.log('PASS: bounded sidebar, complete directory search, real profile IDs, request deduplication, access denial, retries and stale-channel protection');
})().catch(error => { console.error(error); process.exitCode=1; });
