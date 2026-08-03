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
const annData={
  'Quiz 2 Reminder':['📌','Don\'t forget! Quiz 2 will be on Friday. Make sure to review chapters 4 and 5.'],
  'New Resource Added':['📘','I\'ve added new lecture notes on Backpropagation to the Resources section.'],
  'Office Hours':['🕐','Office hours this week: Wednesday 3–5 PM. Feel free to drop by for questions.']
};

function openModal(id, param) {
  closeAllDD();
  if(param) {
    if(id==='memberDetailModal'){document.getElementById('mdTitle').textContent=param+' — Profile';document.getElementById('mdName').textContent=param;}
    if(id==='kickModal'){document.getElementById('kickTarget').textContent=param;}
    if(id==='warnModal'){document.getElementById('warnTarget').textContent=param;}
    if(id==='muteModal'){document.getElementById('muteTarget').textContent=param;}
    if(id==='changeRoleModal'){const t=document.getElementById('crTarget');if(t)t.value=param;}
    if(id==='sessionDetailModal'){document.getElementById('sdTitle').textContent=param;}
    if(id==='reportDetailModal'){document.getElementById('rdTitle2').textContent='Report: '+param;}
    if(id==='viewAnnModal'){document.getElementById('vaTitle').textContent=param;document.getElementById('vaName').textContent=param;const d=annData[param];if(d){document.getElementById('vaBody').textContent=d[1];}}
    if(id==='editAnnModal'){document.getElementById('eaTitle').textContent='Edit: '+param;const t=document.getElementById('eaAnnTitle');if(t)t.value=param;}
  }
  const o=document.getElementById(id);
  if(o){o.classList.add('show');document.body.style.overflow='hidden';}
}
function closeModal(id){const o=document.getElementById(id);if(o){o.classList.remove('show');document.body.style.overflow='';}}
document.querySelectorAll('.mo').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeModal(o.id);}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.mo.show').forEach(o=>closeModal(o.id));closeAllDD();}});

// ═══ DROPDOWNS ═══
function toggleNotif(){const d=document.getElementById('ndrop');const open=d.classList.contains('show');closeAllDD();if(!open)d.classList.add('show');}
function togglePDrop(){const d=document.getElementById('pdrop');const open=d.classList.contains('show');closeAllDD();if(!open)d.classList.add('show');}
function closeAllDD(){document.getElementById('ndrop').classList.remove('show');document.getElementById('pdrop').classList.remove('show');hideSD();}
document.addEventListener('click',e=>{if(!e.target.closest('#nBtn'))document.getElementById('ndrop').classList.remove('show');if(!e.target.closest('#pchip'))document.getElementById('pdrop').classList.remove('show');if(!e.target.closest('#swrap'))hideSD();});

// ═══ NOTIFICATIONS ═══
function handleNotif(el,msg){el.classList.remove('unread');const d=el.querySelector('.ndd');if(d)d.remove();updateNB();toast(msg,'info','🔔');}
function clearNotifs(){document.querySelectorAll('.ndi').forEach(i=>{i.classList.remove('unread');const d=i.querySelector('.ndd');if(d)d.remove();});document.getElementById('nbadge').style.display='none';toast('All notifications read','success','✓');}
function updateNB(){const c=document.querySelectorAll('.ndi.unread').length;const b=document.getElementById('nbadge');if(c===0)b.style.display='none';else b.textContent=c;}

// ═══ SEARCH ═══
function handleSearch(v){if(v.length>0)showSD();else hideSD();}
function showSD(){if(document.getElementById('gsearch').value.length>0)document.getElementById('sdrop').classList.add('show');}
function hideSD(){document.getElementById('sdrop').classList.remove('show');}

// ═══ TABS ═══
function switchTab(btn,cid){const m=btn.closest('.md')||btn.closest('.page-section');m.querySelectorAll('.tb').forEach(b=>b.classList.remove('active'));m.querySelectorAll('.tc').forEach(c=>c.classList.remove('active'));btn.classList.add('active');const c=document.getElementById(cid);if(c)c.classList.add('active');}

// ═══ ACTIONS ═══
function createAnnouncement(){const t=document.getElementById('annTitle').value;const b=document.getElementById('annBody').value;if(!t||!b){toast('Please fill title and message','error','❌');return;}closeModal('createAnnModal');const list=document.getElementById('annList');const d=document.createElement('div');d.className='ann-item';d.innerHTML=`<div class="ann-title">📌 ${t}</div><div class="ann-body">${b}</div><div class="ann-meta">Prof. Reyes · Just now</div><div class="ri-actions"><button class="btn-sm btn-outline" onclick="openModal('editAnnModal','${t}')">Edit</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="this.closest('.ann-item').remove();toast('Announcement deleted','success','🗑')">Delete</button></div>`;list.insertBefore(d,list.firstChild);document.getElementById('annTitle').value='';document.getElementById('annBody').value='';toast('Announcement posted!','success','📢');}
function deleteAnn(btn){if(!confirm('Delete this announcement?'))return;btn.closest('.ann-item').remove();toast('Announcement deleted','success','🗑');}
function startSession(){const t=document.getElementById('sessTitle').value;if(!t){toast('Enter a session title','error','❌');return;}closeModal('startSessionModal');toast('Study session "'+t+'" started!','success','🎓');document.getElementById('sessTitle').value='';}
function doKick(){closeModal('kickModal');toast('Member kicked from channel','success','👢');}
function doUpload(){closeModal('uploadResourceModal');toast('Resource uploaded!','success','✅');}
function resolveReport(btn, action){btn.closest('.report-item').style.opacity='.4';toast('Report '+action,'success','✅');}
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
    // Legacy: only a channel name was provided — chat module will
    // fall back to its default (first) channel, but we still pass
    // the name so it can be matched/highlighted if found.
    params.set('channel_name', channelIdOrName);
  }
  window.location.href = `${base}/modules/chat/chat.php?${params.toString()}`;
}
