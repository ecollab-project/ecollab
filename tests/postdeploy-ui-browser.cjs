'use strict';
// Actual chat template/styles and controllers, isolated from external APIs/data.
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const chat=fs.readFileSync(path.join(root,'assets/js/chat/chat.js'),'utf8');
const modalFunctions=chat.slice(chat.indexOf('// ── Modals ──'),chat.indexOf('// ── Sidebar filter ──'));
const avatarFunctions=chat.slice(chat.indexOf('function mentionLinks('),chat.indexOf('const nativeMentionState'))
  +chat.slice(chat.indexOf('function chatAvatarUrl('),chat.indexOf('const API_BASE'))
  +chat.slice(chat.indexOf('function messageAvatarMarkup('),chat.indexOf('function buildPollWidget('));
let html=execFileSync(process.env.PHP_BIN||'php',['-d','session.save_path=/tmp','tests/responsive-render-fixture.php','chat'],{cwd:root,encoding:'utf8'});
html=html.replace(/<script\b[\s\S]*?<\/script>/gi,'')
  .replace(/(href|src)="https?:\/\/[^/"\s]+(\/assets\/[^"\s]+)"/g,'$1="$2"')
  .replace('</body>','<button id="fixtureOpen" style="position:fixed;top:4px;left:4px;z-index:999" onclick="openModal(\'addChannelModal\')">Open fixture dialog</button><script src="/fixture-modal.js"></script><script src="/assets/js/chat/modal-accessibility.js"></script></body>');
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://fixture');
  if(url.pathname==='/fixture'){res.setHeader('Content-Type','text/html');return res.end(html);}
  if(url.pathname==='/fixture-modal.js'){res.setHeader('Content-Type','text/javascript');return res.end(modalFunctions+avatarFunctions);}
  if(url.pathname==='/fixture-avatar.svg'){
    res.setHeader('Content-Type','image/svg+xml');
    return res.end('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40"><rect width="80" height="40" fill="orange"/></svg>');
  }
  const file=path.resolve(root,'.'+url.pathname);
  if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
(async()=>{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+server.address().port;
  const browser=await chromium.launch(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{});
  try{
    const page=await browser.newPage({viewport:{width:1384,height:855}});
    await page.route('**/*',route=>route.request().url().startsWith(base)?route.continue():route.abort());
    await page.goto(base+'/fixture');
    const closed=await page.locator('.modal-overlay').evaluateAll(els=>els.map(e=>({inert:e.inert,hidden:e.getAttribute('aria-hidden'),visibility:getComputedStyle(e).visibility})));
    assert(closed.length>5&&closed.every(e=>e.inert&&e.hidden==='true'&&e.visibility==='hidden'),'Inactive dialogs must be hidden and inert');
    await page.locator('#fixtureOpen').click();
    await page.locator('#addChannelModal').getByRole('button',{name:'Create Channel',exact:true}).waitFor({state:'visible'});
    assert(await page.locator('#addChannelModal').evaluate(e=>e.getAttribute('aria-modal')==='true'&&!e.inert));
    await page.locator('#addChannelModal .modal-close').focus();
    await page.keyboard.press('Shift+Tab');
    assert(await page.locator('#addChannelModal').getByRole('button',{name:'Create Channel',exact:true}).evaluate(e=>e===document.activeElement),'Reverse Tab stays in dialog');
    await page.keyboard.press('Tab');
    assert(await page.locator('#addChannelModal .modal-close').evaluate(e=>e===document.activeElement),'Forward Tab wraps');
    await page.keyboard.press('Escape');
    await page.waitForFunction(()=>document.getElementById('addChannelModal').inert);
    assert(await page.locator('#fixtureOpen').evaluate(e=>e===document.activeElement),'Escape returns focus');
    await page.evaluate(()=>{openModal('addChannelModal');setTimeout(()=>{closeModal('addChannelModal');openModal('addChannelModal');},30);});
    await page.waitForTimeout(300);
    assert(await page.locator('#addChannelModal').isVisible(),'Reopening cancels the previous close timer');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(220);
    // Dynamic overlays use their existing open class and cleanup callbacks too.
    await page.evaluate(()=>{const m=document.createElement('div');m.id='dynamicModal';m.className='modal-overlay';m.innerHTML='<div class="modal"><button class="modal-close" onclick="closeModal(\'dynamicModal\')">Close dynamic</button></div>';document.body.append(m);openModal(m.id);});
    await page.getByRole('button',{name:'Close dynamic'}).waitFor({state:'visible'});
    await page.keyboard.press('Escape');
    await page.waitForFunction(()=>document.getElementById('dynamicModal').inert);
    await page.evaluate(()=>{
      window.escHtml=value=>String(value??'').replace(/[<>"&']/g,char=>'&#'+char.charCodeAt(0)+';');
      window.formatTime=()=>'9:00';
      window._renderMsgContent=()=>'<span>Fixture message</span>';
      const list=document.getElementById('messagesArea');
      for(const [id,name,url] of [[101,'Photo','/fixture-avatar.svg'],[102,'Broken','/missing-avatar.png'],[103,'Initial','']]){
        list.append(buildMessageElement({id,sender_id:id,full_name:name,username:name,avatar_url:url}));
      }
      document.documentElement.classList.add('compact-mode');
    });
    await page.locator('[data-msg-id="101"] .msg-avatar-photo.is-loaded').waitFor({state:'visible'});
    await page.locator('[data-msg-id="102"] .msg-avatar-photo').waitFor({state:'detached'});
    assert.equal(await page.locator('[data-msg-id="101"] .msg-avatar-face span').isVisible(),false);
    assert.equal(await page.locator('[data-msg-id="102"] .msg-avatar-face span').innerText(),'B');
    assert.equal(await page.locator('[data-msg-id="103"] .msg-avatar-face span').innerText(),'I');
    const sizes=[320,390,540,768,1384];
    for(const width of sizes){
      await page.setViewportSize({width,height:844});
      await page.evaluate(()=>document.getElementById('chatInputWrapper').style.display='block');
      const dimensions=await page.evaluate(()=>({
        input:document.getElementById('chatInputField').getBoundingClientRect().toJSON(),
        send:document.querySelector('#chatInputContainer .send-btn').getBoundingClientRect().toJSON(),
        tools:['attachBtn','extrasBtn','emojiBtn','gifBtn','micBtn','aiAssistBtn'].map(id=>document.getElementById(id).getBoundingClientRect().toJSON()),
        heading:getComputedStyle(document.querySelector('.chat-channel-heading')).display,
        scrollWidth:document.documentElement.scrollWidth,
        avatars:[...document.querySelectorAll('.msg-avatar-face')].map(face=>{
          const rect=face.getBoundingClientRect();
          const parent=face.parentElement.getBoundingClientRect();
          const header=face.closest('.message-group').querySelector('.msg-header').getBoundingClientRect();
          return {width:rect.width,height:rect.height,slot:parent.width,gap:header.left-rect.right};
        }),
      }));
      assert(dimensions.scrollWidth<=width+2,'Page overflows at '+width);
      assert.equal(dimensions.avatars.length,3);
      for(const avatar of dimensions.avatars){
        assert.equal(avatar.width,28,'Compact avatar remains square and fits its slot');
        assert.equal(avatar.height,avatar.width);
        assert.equal(avatar.slot,avatar.width);
        assert(avatar.gap>=8,'Avatar leaves space before the sender name');
      }
      if(width<=420)console.log('Narrow composer',width,await page.locator('#chatInputField').evaluate(e=>{const rows=[];for(let p=e;p&&rows.length<5;p=p.parentElement){const s=getComputedStyle(p);rows.push({tag:p.tagName,id:p.id,class:p.className,width:p.getBoundingClientRect().width,display:s.display,flex:s.flex,columns:s.gridTemplateColumns});}return rows;}));
      if(width<=600){
        assert(dimensions.input.width>=200,'Text field remains usable at '+width);
        assert(Math.abs(dimensions.input.y-dimensions.send.y)<=2,'Send stays beside the editor');
        assert(dimensions.send.height>=44,'Send has a 44px touch target');
        assert(dimensions.tools.every(row=>row.width>=44&&row.height>=44&&row.top>=dimensions.input.bottom),
          'Secondary tools remain reachable below the editor');
        assert(dimensions.tools.every(row=>Math.abs(row.top-dimensions.tools[0].top)<=2),
          'Secondary tools fit on one row');
      }
      assert.equal(dimensions.heading,width<=860?'none':'flex','Only one channel heading on mobile');
      for(const id of ['attachBtn','extrasBtn','emojiBtn','gifBtn','micBtn'])assert(await page.locator('#'+id).isVisible(),id+' remains available');
    }
    await page.addScriptTag({path:path.join(root,'assets/js/chat/peer-matching.js')});
    await page.evaluate(()=>{
      window.chatSidebarAvatar=()=>'';
      window.pmFetch=async()=>({leaderboard:[
        {peer_id:2,full_name:'Connected peer',already_connected:true,score_total:64,shared_subjects:1,shared_interests:1,shared_hobbies:0},
        {peer_id:3,full_name:'Pending peer',request_status:'pending',score_total:50,shared_subjects:1,shared_interests:0,shared_hobbies:0},
        {peer_id:4,full_name:'Available peer',score_total:40,shared_subjects:0,shared_interests:1,shared_hobbies:0},
      ]});
      showMatchingDialog(document.getElementById('peerMatchingModal'));
      return _pmRenderLeaderboard(document.getElementById('pmModalBody'));
    });
    assert.equal(await page.locator('.pm-leaderboard-row button:disabled').count(),2);
    assert.equal(await page.locator('.pm-leaderboard-row button:not(:disabled)').count(),1);
    assert.equal(await page.locator('.pm-lb-score').first().innerText(),'64%');
    console.log('PASS: real-template modal isolation, focus trap/return, dynamic overlays, reopen race, 320/390/540/768/1384 layouts and Top connection states');
    await page.close();
  }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);server.close();process.exitCode=1;});
