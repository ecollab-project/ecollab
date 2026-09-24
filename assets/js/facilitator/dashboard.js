Chart.defaults.color='#64748b';
Chart.defaults.font.family='Plus Jakarta Sans';
Chart.defaults.font.size=10;

// ═══ NAV ═══
function showPage(id, navEl) {
  document.querySelectorAll('.page-section').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>{n.classList.remove('active');n.classList.remove('active-soft');});
  const p=document.getElementById('page-'+id);
  if(p) p.classList.add('active');
  if(navEl) navEl.classList.add('active');
  else { const n=document.getElementById('nav-'+id); if(n) n.classList.add('active'); }
  closeAllDD();
  if(id==='engagement') initEngChart2();
  if(id==='whiteboard') initWB();
}

// ═══ MODALS ═══
const annData={};

function openModal(id, param) {
  closeAllDD();
  if(param) {
    const setText=(eid,text)=>{const el=document.getElementById(eid);if(el)el.textContent=text;};
    if(id==='memberDetailModal'){setText('mdTitle',param+' — Profile');setText('mdName',param);}
    if(id==='kickModal')setText('kickTarget',param);
    if(id==='warnModal')setText('warnTarget',param);
    if(id==='muteModal')setText('muteTarget',param);
    if(id==='changeRoleModal'){const t=document.getElementById('crTarget');if(t)t.value=param;}
    if(id==='sessionDetailModal')setText('sdTitle',param);
    if(id==='reportDetailModal')setText('rdTitle2','Report: '+param);
    if(id==='viewAnnModal'){setText('vaTitle',param);setText('vaName',param);const d=annData[param];if(d)setText('vaBody',d[1]);}
    if(id==='editAnnModal'){setText('eaTitle','Edit: '+param);const t=document.getElementById('eaAnnTitle');if(t)t.value=param;}
  }
  const o=document.getElementById(id);
  if(!o){console.warn('Modal not present:',id);return;}
  o.classList.add('show');document.body.style.overflow='hidden';
}
function closeModal(id){const o=document.getElementById(id);if(o){o.classList.remove('show');document.body.style.overflow='';}}
document.querySelectorAll('.mo').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeModal(o.id);}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.mo.show').forEach(o=>closeModal(o.id));closeAllDD();}});

// ═══ DROPDOWNS ═══
function toggleNotif(){const d=document.getElementById('ndrop');if(!d)return;const open=d.classList.contains('show');closeAllDD();if(!open)d.classList.add('show');}
function togglePDrop(){const d=document.getElementById('pdrop');if(!d)return;const open=d.classList.contains('show');closeAllDD();if(!open)d.classList.add('show');}
function closeAllDD(){document.getElementById('ndrop')?.classList.remove('show');document.getElementById('pdrop')?.classList.remove('show');hideSD();}
document.addEventListener('click',e=>{if(!e.target.closest('#nBtn'))document.getElementById('ndrop')?.classList.remove('show');if(!e.target.closest('#pchip'))document.getElementById('pdrop')?.classList.remove('show');if(!e.target.closest('#swrap'))hideSD();});

// ═══ NOTIFICATIONS ═══
function handleNotif(el,msg){el.classList.remove('unread');const d=el.querySelector('.ndd');if(d)d.remove();updateNB();toast(msg,'info','🔔');}
function clearNotifs(){document.querySelectorAll('.ndi').forEach(i=>{i.classList.remove('unread');const d=i.querySelector('.ndd');if(d)d.remove();});document.getElementById('nbadge').style.display='none';toast('All notifications read','success','✓');}
function updateNB(){const c=document.querySelectorAll('.ndi.unread').length;const b=document.getElementById('nbadge');if(c===0)b.style.display='none';else b.textContent=c;}

// ═══ SEARCH ═══
function handleSearch(v){if(v.length>0)showSD();else hideSD();}
function showSD(){const search=document.getElementById('gsearch'),drop=document.getElementById('sdrop');if(search&&drop&&search.value.length>0)drop.classList.add('show');}
function hideSD(){document.getElementById('sdrop')?.classList.remove('show');}

// ═══ TABS ═══
function switchTab(btn,cid){const m=btn.closest('.md')||btn.closest('.page-section');m.querySelectorAll('.tb').forEach(b=>b.classList.remove('active'));m.querySelectorAll('.tc').forEach(c=>c.classList.remove('active'));btn.classList.add('active');const c=document.getElementById(cid);if(c)c.classList.add('active');}

// ═══ ACTIONS ═══
async function createAnnouncement(){
  const serverId=Number(document.getElementById('annServer')?.value||0);
  const title=(document.getElementById('annTitle')?.value||'').trim();
  const message=(document.getElementById('annBody')?.value||'').trim();
  if(!serverId){toast('Choose which server receives the announcement','error','❌');return;}
  if(!title||!message){toast('Please fill title and message','error','❌');return;}
  try{
    const res=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/server-tools.php?action=announcement',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({server_id:serverId,title,message})});
    const data=await res.json();if(!res.ok)throw new Error(data.error||'Unable to publish');
    closeModal('createAnnModal');document.getElementById('annTitle').value='';document.getElementById('annBody').value='';
    toast('Published to the server announcement channel and members notified','success','📢');
  }catch(e){toast(e.message||'Unable to publish announcement','error','❌');}
}
function deleteAnn(btn){if(!confirm('Delete this announcement?'))return;btn.closest('.ann-item').remove();toast('Announcement deleted','success','🗑');}
function startSession(){const t=document.getElementById('sessTitle').value;if(!t){toast('Enter a session title','error','❌');return;}closeModal('startSessionModal');toast('Study session "'+t+'" started!','success','🎓');document.getElementById('sessTitle').value='';}
async function doKick(){
  const name=(document.getElementById('kickName')?.textContent||'').trim();
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||FAC_DATA?.csrfToken||'';
  const channelId=Number(FAC_DATA?.channelId||0);
  if(!name||!channelId){toast('Member context is missing; refresh the dashboard.','error','❌');return;}
  try{
    const r=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/dashboard-data.php?action=kick_member',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({channel_id:channelId,username:name,csrf_token:csrf})});
    const d=await r.json().catch(()=>({}));
    if(!r.ok||!d.success)throw new Error(d.error||'Unable to remove member');
    closeModal('kickModal');toast('Member removed from the server','success','👢');
  }catch(e){toast(e.message,'error','❌');}
}
function doUpload(){
  closeModal('uploadResourceModal');
  toast('Use Collaboration to upload a resource so it is stored and associated with the server.','info','📁');
  window.location.href=(window.ECOLLAB_BASE||'')+'/modules/collaboration/coworkspaces.php';
}
async function resolveReport(btn, action){
  const item=btn?.closest('.report-item');
  const messageId=Number(item?.dataset?.messageId||0);
  if(!messageId){toast('This report has no message ID attached; it cannot be resolved safely.','error','❌');return;}
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||FAC_DATA?.csrfToken||'';
  const resolution=(action==='removed'||action==='dismissed')?action:'dismissed';
  try{
    const r=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/dashboard-data.php?action=resolve_report',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({message_id:messageId,resolution,csrf_token:csrf})});
    const d=await r.json().catch(()=>({}));
    if(!r.ok||!d.success)throw new Error(d.error||'Unable to resolve report');
    item.style.opacity='.4';toast('Report '+resolution,'success','✅');
  }catch(e){toast(e.message,'error','❌');}
}
function setAnnType(btn){document.querySelectorAll('.ann-type-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
function sendFacMsg(){const inp=document.getElementById('msgInput');const msg=inp.value.trim();if(!msg)return;const feed=document.getElementById('msgFeed');const d=document.createElement('div');d.style.display='flex';d.style.gap='8px';d.innerHTML=`<div class="ract-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed);font-size:9px;font-weight:700">PR</div><div><div style="font-size:10px;font-weight:700;color:var(--pink);margin-bottom:2px">Prof. Reyes (You) · Just now</div><div style="background:rgba(233,30,140,.1);border-radius:0 9px 9px 9px;padding:8px 11px;font-size:12px;line-height:1.5">${msg}</div></div>`;feed.appendChild(d);inp.value='';feed.scrollTop=feed.scrollHeight;}
function doLogout(){closeModal('logoutModal');toast('Signing out...','info','🚪');setTimeout(()=>{document.body.innerHTML='<div style="display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:14px;background:#070b14;color:#f1f5f9;font-family:Plus Jakarta Sans,sans-serif"><div style="font-size:30px">🔷</div><div style="font-size:22px;font-weight:800">Ecollab</div><div style="color:#94a3b8;font-size:13px">You have been signed out.</div><button onclick="location.reload()" style="margin-top:10px;padding:9px 22px;background:linear-gradient(135deg,#e91e8c,#7c3aed);border:none;border-radius:9px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">Sign In Again</button></div>';},1000);}

// ═══ AI ═══
const aiR={'Summarize channel activity this week':'**CS 305 Weekly Summary**\n\nThis week your channel performed very well:\n\n• 38 active members (52.8% engagement rate)\n• 156 messages sent today (+23% from yesterday)\n• 12 study sessions completed\n• Top contributor: Fatima_Student (245 pts)\n\nEngagement is trending up — great job!','Suggest ways to improve engagement':'**Engagement Improvement Tips**\n\n1. 📊 Post weekly polls — boosts participation by avg. 30%\n2. 🎯 Create study challenges with badges\n3. 📢 Schedule regular announcements (2-3x/week)\n4. 🤝 Pair inactive members with active ones\n5. 🏆 Highlight top contributors weekly\n\nYour current engagement rate of 64.7% is above average!','Draft a quiz reminder announcement':'**Draft Announcement:**\n\n📌 **Quiz 2 – This Friday!**\n\nHello CS 305! 👋 A reminder that Quiz 2 will be held this Friday. \n\n📚 Review Topics:\n• Chapter 4: Activation Functions\n• Chapter 5: Backpropagation\n\nJoin the study session tomorrow 3-5PM for review. Good luck! 🎓\n\nYou can copy and post this directly!'};
function sendAI(){const inp=document.getElementById('aiInput');const msg=inp.value.trim();if(!msg)return;const log=document.getElementById('aiLog');const ud=document.createElement('div');ud.innerHTML=`<div style="font-size:9.5px;font-weight:700;color:var(--cyan);margin-bottom:2px;text-align:right">Prof. Reyes</div><div style="background:rgba(6,182,212,.1);border-radius:9px;padding:8px 11px;font-size:12px;line-height:1.6;max-width:88%;align-self:flex-end;margin-left:auto">${msg}</div>`;log.appendChild(ud);inp.value='';log.scrollTop=log.scrollHeight;const r=aiR[msg]||'That\'s a great question! Based on your CS 305 channel data, I can help you analyze trends, create content, or manage your members. Could you be more specific about what you need?';setTimeout(()=>{const ad=document.createElement('div');ad.innerHTML=`<div style="font-size:9.5px;font-weight:700;color:#a78bfa;margin-bottom:2px">AI Assistant</div><div style="background:rgba(124,58,237,.1);border-radius:9px;padding:8px 11px;font-size:12px;line-height:1.6;max-width:88%;white-space:pre-wrap">${r}</div>`;log.appendChild(ad);log.scrollTop=log.scrollHeight;},600);}
function aiQP(p){document.getElementById('aiInput').value=p;sendAI();}

// ═══ HEATMAP ═══
function buildHeatmap(){
  const container=document.getElementById('heatmapContainer');
  if(!container||container.children.length>0)return;
  const days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  const hours=24;
  const colorLevels=['rgba(30,41,59,.4)','rgba(124,58,237,.15)','rgba(233,30,140,.3)','rgba(233,30,140,.55)','rgba(233,30,140,.8)','#e91e8c'];
  days.forEach(day=>{
    const row=document.createElement('div');row.className='hm-row';
    const label=document.createElement('div');label.style.cssText='font-size:9px;color:#64748b;width:28px;flex-shrink:0;text-align:right;margin-right:5px;line-height:18px;height:18px;display:flex;align-items:center;justify-content:flex-end';label.textContent=day;row.appendChild(label);
    const grid=document.createElement('div');grid.className='hm-grid';grid.style.gridTemplateColumns=`repeat(${hours},1fr)`;
    for(let h=0;h<hours;h++){
      const cell=document.createElement('div');cell.className='hm-cell';
      let level;
      if(h<6)level=Math.random()<.3?1:0;
      else if(h<9)level=Math.floor(Math.random()*2)+1;
      else if(h<17)level=Math.floor(Math.random()*3)+2;
      else if(h<22)level=Math.floor(Math.random()*2)+3;
      else level=Math.floor(Math.random()*2)+1;
      if(day==='Sat'||day==='Sun')level=Math.max(0,level-2);
      cell.style.background=colorLevels[Math.min(level,5)];
      cell.title=`${day} ${h}:00 — Activity: ${['None','Low','Moderate','High','Very High','Peak'][Math.min(level,5)]}`;
      cell.onclick=()=>toast(`${day} ${h}:00 — Activity level: ${['None','Low','Moderate','High','Very High','Peak'][Math.min(level,5)]}`, 'info','🔥');
      grid.appendChild(cell);
    }
    row.appendChild(grid);container.appendChild(row);
  });
}

// ═══ CHARTS ═══
function initEngChart(){
  const ctx=document.getElementById('engChart');
  if(!ctx||ctx._c)return;
  ctx._c=new Chart(ctx,{type:'line',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[18,25,22,30,78,42,35],borderColor:'#e91e8c',backgroundColor:'rgba(233,30,140,0.08)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#e91e8c',pointRadius:3.5,pointHoverRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.raw+' engagements'}}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},ticks:{color:'#64748b',font:{size:9}}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},ticks:{color:'#64748b',stepSize:25,font:{size:9}},min:0,max:100}}}});
}
let e2=false;
function initEngChart2(){if(e2)return;e2=true;const ctx=document.getElementById('engChart2');if(!ctx)return;ctx._c=new Chart(ctx,{type:'line',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[18,25,22,30,78,42,35],borderColor:'#06b6d4',backgroundColor:'rgba(6,182,212,0.08)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#06b6d4',pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},min:0,max:100}}}});}

// ═══ WHITEBOARD ═══
let wbTool='pen',wbDraw=false,wbLX=0,wbLY=0,wbCtx;
function initWB(){const c=document.getElementById('wbCanvas');if(!c||c._init)return;c._init=true;c.width=c.offsetWidth||800;wbCtx=c.getContext('2d');wbCtx.lineCap='round';wbCtx.lineJoin='round';c.addEventListener('mousedown',e=>{wbDraw=true;const r=c.getBoundingClientRect();wbLX=e.clientX-r.left;wbLY=e.clientY-r.top;});c.addEventListener('mousemove',e=>{if(!wbDraw)return;const r=c.getBoundingClientRect();const x=e.clientX-r.left,y=e.clientY-r.top;wbCtx.globalCompositeOperation=wbTool==='eraser'?'destination-out':'source-over';wbCtx.strokeStyle=document.getElementById('wbColor').value;wbCtx.lineWidth=document.getElementById('wbSize').value;wbCtx.beginPath();wbCtx.moveTo(wbLX,wbLY);wbCtx.lineTo(x,y);wbCtx.stroke();wbLX=x;wbLY=y;});c.addEventListener('mouseup',()=>wbDraw=false);c.addEventListener('mouseleave',()=>wbDraw=false);}
function setTool(t,btn){wbTool=t;document.querySelectorAll('.wbt').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
function clearWB(){if(wbCtx)wbCtx.clearRect(0,0,document.getElementById('wbCanvas').width,document.getElementById('wbCanvas').height);toast('Canvas cleared','info','🗑');}

// ═══ TOAST ═══
function toast(msg,type='info',icon='ℹ️'){const c=document.getElementById('tc');const t=document.createElement('div');t.className='toast '+type;t.innerHTML=`<span class="tic">${icon}</span><span class="tmsg">${msg}</span><span class="tcl" onclick="this.parentElement.remove()">✕</span>`;c.appendChild(t);setTimeout(()=>{t.style.transition='all .3s';t.style.opacity='0';t.style.transform='translateX(50px)';setTimeout(()=>t.remove(),300);},3500);}

// ═══ INIT ═══
window.addEventListener('load',()=>{
  setTimeout(()=>{
    buildHeatmap();
    initEngChart();
  },100);
});

// ═══ UNIFIED AUTH INTEGRATION ═══
function doLogout(){
  closeModal('logoutModal');
  toast('Signing out...','info','🚪');
  fetch((window.ECOLLAB_BASE||'')+'/API/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:'{}'})
    .then(()=>{ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php'; })
    .catch(()=>{ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php'; });
}
function goToChat(){ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/chat/chat.php'; }

/**
 * Navigate to the chat module and select a specific channel
 * (optionally on a specific server).
 * Used by the "My Channels & Servers" card and the channel-switch
 * modal. The chat module reads ?server_id= / ?channel_id= on load
 * (see modules/chat/chat.php and assets/js/chat/chat.js
 * DOMContentLoaded handler) and switches to that server/channel
 * automatically.
 *
 * Accepts either a channel_id (preferred) or a channel name (legacy
 * fallback for older onclick markup that only has the name).
 */
function switchChannel(channelIdOrName, serverId){
  const base = window.ECOLLAB_BASE || '';
  const params = new URLSearchParams();
  if (serverId) params.set('server_id', serverId);

  if (typeof channelIdOrName === 'number' || /^\d+$/.test(String(channelIdOrName))) {
    params.set('channel_id', channelIdOrName);
  } else {
    params.set('channel_name', channelIdOrName);
  }
  window.location.href = `${base}/modules/chat/chat.php?${params.toString()}`;
}

window.addEventListener('DOMContentLoaded',()=>{const p=new URLSearchParams(location.search).get('page');if(p)showPage(p);});

async function saveChannelSettings(){
  const serverId=Number(document.getElementById('settingsServer')?.value||0);
  if(!serverId){toast('Choose a server first','error','❌');return;}
  const name=(document.querySelector('#page-chsettings .fg input.fi')?.value||document.getElementById('settingsServer')?.selectedOptions?.[0]?.text||'').trim();
  const description=(document.querySelector('#page-chsettings textarea.fta')?.value||'').trim();
  const toggles=[...document.querySelectorAll('#page-chsettings .toggle')];
  const visibility=toggles[0]?.classList.contains('on')?'public':'private';
  const lockChannels=!!toggles[1]?.classList.contains('on');
  try{
    const res=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/server-tools.php?action=settings',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({server_id:serverId,name,description,visibility,lock_channels:lockChannels})});
    const data=await res.json();if(!res.ok)throw new Error(data.error||'Unable to save settings');
    toast('Server settings and channel permissions updated','success','💾');
  }catch(e){toast(e.message||'Unable to save settings','error','❌');}
}

function escFac(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
async function facOwnedServers(){
  const r=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/server-tools.php?action=servers');const d=await r.json();if(!r.ok)throw new Error(d.error||'Unable to load servers');return d.servers||[];
}
function facServerPicker(pageId,servers,onChange){
  const page=document.getElementById(pageId);if(!page||page.querySelector('.fac-server-picker'))return;
  const row=page.querySelector('.page-title-row');if(!row)return;
  const wrap=document.createElement('div');wrap.className='fac-server-picker';wrap.style.cssText='margin:0 0 12px;padding:12px 14px;background:var(--card);border:1px solid var(--border);border-radius:10px';
  wrap.innerHTML='<label class="fl">Server</label><select class="fi"><option value="">Choose a server...</option>'+servers.map(s=>'<option value="'+s.id+'">'+escFac(s.name)+'</option>').join('')+'</select>';
  row.insertAdjacentElement('afterend',wrap);wrap.querySelector('select').addEventListener('change',e=>onChange(Number(e.target.value||0)));
}
async function facGet(action,sid){if(!sid)return null;const r=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/server-tools.php?action='+encodeURIComponent(action)+'&server_id='+sid);const d=await r.json();if(!r.ok)throw new Error(d.error||'Unable to load data');return d;}
async function loadFacResources(sid){const el=document.getElementById('resourcesList');if(!el||!sid)return;try{const d=await facGet('resources',sid);el.innerHTML=(d.files||[]).map(f=>'<div class="ract-row"><div style="font-size:18px">📎</div><div class="ract-msg" style="flex:1"><strong>'+escFac(f.original_name||f.file_name)+'</strong><div style="font-size:10px;color:var(--muted2)">#'+escFac(f.channel_name||'server')+' · '+escFac(f.username)+'</div></div><a class="btn-sm btn-outline" href="'+escFac((window.ECOLLAB_BASE||'')+'/'+String(f.file_path||'').replace(/^\//,''))+'" download>Download</a></div>').join('')||'<div class="dashboard-empty-state">No files in this server.</div>';}catch(e){toast(e.message,'error','❌');}}
async function loadFacWorkspace(sid){const el=document.getElementById('filesList');if(!el||!sid)return;try{const d=await facGet('workspace',sid);el.innerHTML=(d.items||[]).map(x=>'<div class="ract-row"><div style="font-size:18px">'+(x.item_type==='whiteboard'?'🧠':'📎')+'</div><div class="ract-msg" style="flex:1"><strong>'+escFac(x.item_name)+'</strong><div style="font-size:10px;color:var(--muted2)">#'+escFac(x.channel_name||'server')+' · '+escFac(x.username)+'</div></div>'+(x.item_type==='file'?'<a class="btn-sm btn-outline" href="'+escFac((window.ECOLLAB_BASE||'')+'/'+String(x.item_path||'').replace(/^\//,''))+'" download>Download</a>':'<span class="status-pill sp-a">Whiteboard</span>')+'</div>').join('')||'<div class="dashboard-empty-state">No workspace files or whiteboards.</div>';}catch(e){toast(e.message,'error','❌');}}
async function loadFacActivity(sid){const page=document.getElementById('page-useractivity');const tb=page?.querySelector('tbody');if(!tb||!sid)return;try{const d=await facGet('activity',sid);tb.innerHTML=(d.users||[]).map(u=>'<tr><td><strong>'+escFac(u.full_name||u.username)+'</strong><div class="u-handle">@'+escFac(u.username)+'</div></td><td>'+Number(u.messages||0)+'</td><td>'+Number(u.voice_joins||0)+'</td><td colspan="2">'+Math.round(Number(u.voice_seconds||0)/60)+' min voice</td><td>'+escFac(u.last_message||'—')+'</td><td><span class="status-pill sp-a">Member</span></td></tr>').join('')||'<tr><td colspan="7" class="dashboard-empty-state">No other users in this server.</td></tr>';}catch(e){toast(e.message,'error','❌');}}

async function facModerate(sid,uid,kind){if(!confirm(kind+' this user?'))return;try{const r=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/server-tools.php?action=moderate',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({server_id:sid,target_user_id:uid,kind,reason:'Facilitator action from report'})});const d=await r.json();if(!r.ok)throw new Error(d.error||'Action failed');toast('User '+kind+' action applied','success','✅');loadFacReports(sid);loadFacBanned(sid);}catch(e){toast(e.message,'error','❌');}}
async function loadFacReports(sid){const page=document.getElementById('page-reports');const card=document.getElementById('facReportsList')||page?.querySelector('.card');if(!card||!sid)return;try{const d=await facGet('reports',sid);card.innerHTML='<div class="ch-bar"><div class="ch-title">Server Reports</div></div>'+((d.reports||[]).map(r=>'<div class="report-item"><div class="ri-title">🚩 '+escFac(r.reason)+' · '+escFac(r.reported_username||'content report')+'</div><div class="ri-meta">Reported by '+escFac(r.reporter_username)+' · '+escFac(r.status)+'</div>'+(r.reported_user_id?'<div class="ri-actions"><button class="btn-sm btn-outline" onclick="facModerate('+sid+','+Number(r.reported_user_id)+',\'mute\')">Mute</button><button class="btn-sm" onclick="facModerate('+sid+','+Number(r.reported_user_id)+',\'kick\')">Kick</button><button class="btn-sm" onclick="facModerate('+sid+','+Number(r.reported_user_id)+',\'ban\')">Ban</button></div>':'')+'</div>').join('')||'<div class="dashboard-empty-state">No reports for this server.</div>');}catch(e){toast(e.message,'error','❌');}}
async function loadFacBanned(sid){const page=document.getElementById('page-banned');const card=document.getElementById('facBannedList')||page?.querySelector('.card');if(!card||!sid)return;try{const d=await facGet('banned',sid);card.innerHTML='<div class="ch-bar"><div class="ch-title">Banned Members</div></div>'+((d.users||[]).map(u=>'<div class="ract-row"><div class="ract-msg" style="flex:1"><strong>'+escFac(u.full_name||u.username)+'</strong> · '+escFac(u.reason||'No reason')+'</div><button class="btn-sm btn-outline" onclick="facModerate('+sid+','+Number(u.target_user_id)+',\'unban\')">Unban</button></div>').join('')||'<div class="dashboard-empty-state">No banned users for this server.</div>');}catch(e){toast(e.message,'error','❌');}}
async function loadFacPermissions(sid){
  if(!sid)return;
  try{const d=await facGet('permissions',Number(sid)),p=d.permissions||{};[['permInvites','allow_member_invites'],['permMessages','allow_member_messages'],['permVoice','allow_voice'],['permPolls','allow_polls'],['permFiles','allow_files']].forEach(([id,k])=>document.getElementById(id)?.classList.toggle('on',Number(p[k]??1)===1));}catch(e){toast(e.message,'error','❌');}
}
async function saveFacPermissions(){
  const sid=Number(document.getElementById('permServer')?.value||0);if(!sid){toast('Choose a server first','error','❌');return;}
  const all=confirm('Apply these permission changes to ALL servers you own?\n\nOK = all owned servers\nCancel = only the selected server');
  if(!all&&!confirm('Apply these permission changes only to the selected server?'))return;
  const on=id=>!!document.getElementById(id)?.classList.contains('on');
  try{const r=await fetch((window.ECOLLAB_BASE||'')+'/API/facilitator/server-tools.php?action=permissions',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({server_id:sid,apply_scope:all?'all':'server',allow_member_invites:on('permInvites'),allow_member_messages:on('permMessages'),allow_voice:on('permVoice'),allow_polls:on('permPolls'),allow_files:on('permFiles')})});const d=await r.json();if(!r.ok)throw new Error(d.error||'Unable to save permissions');toast('Permissions saved','success','✅');}catch(e){toast(e.message,'error','❌');}
}
async function initFacilitatorServerPages(){try{const s=await facOwnedServers();facServerPicker('page-resources',s,loadFacResources);facServerPicker('page-files',s,loadFacWorkspace);facServerPicker('page-useractivity',s,loadFacActivity);facServerPicker('page-reports',s,loadFacReports);facServerPicker('page-banned',s,loadFacBanned);facServerPicker('page-polls',s,()=>toast('Polls and quizzes will use channels from the selected server','info','📊'));}catch(e){console.error('Facilitator server pages:',e);}}
window.addEventListener('DOMContentLoaded',initFacilitatorServerPages);
