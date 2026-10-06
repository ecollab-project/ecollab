const fs=require('fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const src=fs.readFileSync('assets/js/chat/dm-notifications.js','utf8').replace(/if \(document.readyState === 'loading'\) \{[\s\S]*?\n\}\s*else\s*\{\s*_init\(\);\s*\}/,'');
const dom=new JSDOM('<div id="notifDropdown"><div id="notifList"></div></div><span id="notifBadge"></span>',{url:'https://ecollab.test/modules/chat/chat.php',runScripts:'outside-only'}),w=dom.window;
w.ECOLLAB={baseUrl:'https://ecollab.test',userId:1};w.fetch=async()=>({json:async()=>({})});w.setInterval=()=>0;w.clearInterval=()=>{};w.setTimeout=fn=>{fn();return 0};w.showToast=()=>{};w.apiFetch=async()=>({});w.eval(src+`;window.__testDM=DM;window.__testNOTIF=NOTIF;window.__renderNotifs=_renderNotifDropdown;window.__initNotifs=_init;window.__notificationUrl=_notificationUrl;loadDmList=async()=>{};`);
let actions=[];w.openDmConversation=async id=>actions.push(['dm',id]);w.openGroupConversation=async id=>actions.push(['group',id]);w.openThreadDetail=async id=>actions.push(['thread',id]);w.switchView=v=>actions.push(['view',v]);
w.eval(`window.__testDM.conversations=[{conversation_id:20,partner_id:2},{conversation_id:30,partner_id:3,unread_count:9}];`);
async function click(n){actions=[];w.eval('window.__testNOTIF.items='+JSON.stringify([{id:1,is_read:1,...n}])+';');await w._handleNotifClick(1,n.type,0);return actions;}
(async()=>{
assert.deepEqual(await click({type:'message',link_url:'https://ecollab.test/modules/chat/chat.php?dm=20'}),[['dm',2]],'old DM links open exact conversation instead of unread stranger');
assert.deepEqual(await click({type:'message',actor_id:4,link_url:'/modules/chat/chat.php?partner_id=4&message_id=8'}),[['dm',4]]);
assert.deepEqual(await click({type:'message',link_url:'/modules/chat/chat.php?group_id=6'}),[['group',6]]);
assert.deepEqual(await click({type:'message',link_url:'/modules/chat/chat.php?thread_id=9'}),[['view','threads'],['thread',9]]);
assert.deepEqual(await click({type:'system',link_url:'/modules/chat/chat.php?partner_id=2&missed_call=7'}),[['dm',2]]);
assert.deepEqual(await click({type:'message',link_url:'https://evil.test/modules/chat/chat.php?partner_id=2'}),[]);
assert.deepEqual(await click({type:'message',link_url:'javascript:alert(1)'}),[]);
for(const link of ['/modules/chat/chat.php?invite=abc','/modules/chat/chat.php?channel_invite=abc'])assert(w.__notificationUrl(link)?.href.includes(link));
w.eval(`window.__testNOTIF.items=[{id:99,type:'message',is_read:0,title:'<img src=x onerror=alert(1)>',body:'test',link_url:'/modules/chat/chat.php?partner_id=2'}];window.__renderNotifs();window.__initNotifs();`);
assert.equal(w.document.querySelector('#notifList img'),null,'notification text cannot inject markup');
const row=w.document.querySelector('[data-notif-id="99"]');assert.equal(row.getAttribute('role'),'button');assert.equal(row.tabIndex,0);actions=[];row.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));await Promise.resolve();await Promise.resolve();assert(actions.some(a=>a[0]==='dm'&&a[1]===2),'keyboard opens notification');
console.log('PASS exact DM, group, thread, missed-call routing; invite URL validation; unsafe links; keyboard and escaping');dom.window.close();
})().catch(e=>{console.error(e);dom.window.close();process.exitCode=1;});
