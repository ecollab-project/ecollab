Chart.defaults.color='#64748b';
Chart.defaults.font.family='Plus Jakarta Sans';
Chart.defaults.font.size=10;

// ═══ NAV ═══
function showPage(id, navEl) {
  document.querySelectorAll('.page-section').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  const p=document.getElementById('page-'+id);
  if(p) p.classList.add('active');
  if(navEl) navEl.classList.add('active');
  else { const n=document.getElementById('nav-'+id); if(n) n.classList.add('active'); }
  closeAllDD();
  if(id==='activity') initActChart2();
  if(id==='insights') initInsChart();
  if(id==='calendar') renderCal();
  if(id==='whiteboard') window.location.href=(window.ECOLLAB_BASE||'')+'/modules/collaboration/coworkspaces.php';
  if(id==='messages'||id==='chat') goToChat();
}

// ═══ MODALS ═══
const noteData={'Neural Networks - Key Concepts':'Backpropagation: computes gradients via chain rule.\n\nActivation Functions:\n• ReLU: max(0,x)\n• Sigmoid: 1/(1+e^-x)\n• Tanh: output -1 to 1\n\nGradient Descent: θ = θ - α∇L\n\nKey: Always normalize your inputs!','DSA - Two Pointers':'Use two pointers for O(n) solutions on sorted arrays.\n\nTemplate:\ni=0, j=n-1\nwhile i < j:\n  sum = arr[i] + arr[j]\n  if sum == target: return\n  elif sum < target: i++\n  else: j--\n\nTime: O(n), Space: O(1)'};
const helpData={'Getting Started':'Welcome to Ecollab!\n\n1. Set up your profile and add interests\n2. Enroll in courses using course codes\n3. Join study rooms for your subjects\n4. Connect with study buddies\n5. Use AI Assistant anytime\n\nTip: Complete your profile for better recommendations!','Study Rooms':'Study Rooms are real-time collaborative spaces.\n\n• Join existing rooms from Discover\n• Create your own room\n• Use whiteboard, chat, and file sharing\n• Rooms support public or private access\n\nTip: Rooms auto-close after 24h of inactivity.','AI Assistant':'Available 24/7 to help you study.\n\n• Summarize chapters or topics\n• Generate quiz questions\n• Create personalized study plans\n• Explain difficult concepts\n\nTip: Be specific! "Summarize CS 305 Chapter 5" works better than "help me study".'};
const achData={'Neural Explorer':['🧠','Complete 10 Neural Networks sessions. Deep dive into AI!'],'Consistent Learner':['🔥','Study for 7 consecutive days. Your dedication is incredible!'],'Active Participant':['💬','Send 50 messages in study rooms. You are a valued community member!'],'Team Player':['👫','Join 10 group sessions. Collaboration leads to success!'],'First Login':['🎯','Welcome to Ecollab! Your learning journey starts now.'],'Quiz Master':['📝','Complete 10 quizzes. Knowledge tested is knowledge retained!']};

function openModal(id, param) {
  closeAllDD();
  if(param) {
    if(id==='roomDetailModal'){document.getElementById('rdTitle').textContent=param;document.getElementById('rdName').textContent=param;}
    if(id==='serverDetailModal'){document.getElementById('sdTitle').textContent=param;document.getElementById('sdName').textContent=param;}
    if(id==='sessionDetailModal') document.getElementById('sessDtTitle').textContent=param;
    if(id==='courseDetailModal') document.getElementById('cdt').textContent=param;
    if(id==='profileModal'){document.getElementById('pmTitle').textContent=param;document.getElementById('pmName').textContent=param;}
    if(id==='dmModal') document.getElementById('dmTitle').textContent='Message '+param;
    if(id==='achModal'){const d=achData[param]||['🏆','Achievement unlocked!'];document.getElementById('amTitle').textContent=param;document.getElementById('amName').textContent=param;document.getElementById('amIcon').textContent=d[0];document.getElementById('amDesc').textContent=d[1];}
    if(id==='viewNoteModal'){document.getElementById('vnTitle').textContent=param;document.getElementById('vnContent').textContent=noteData[param]||'Note content...';}
    if(id==='editNoteModal') document.getElementById('enTitle').textContent='Edit: '+param;
    if(id==='helpArticleModal'){document.getElementById('haTitle').textContent=param;document.getElementById('haBody').textContent=helpData[param]||'Loading...';}
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
function handleNotif(el,msg){el.classList.remove('unread');const d=el.querySelector('.ndd');if(d)d.remove();updateNB();if(el.dataset.chatLink)goToChat();else toast(msg,'info','🔔');}
function clearNotifs(){document.querySelectorAll('.ndi').forEach(i=>{i.classList.remove('unread');const d=i.querySelector('.ndd');if(d)d.remove();});document.getElementById('nbadge').style.display='none';const s=document.getElementById('sideNB');if(s)s.style.display='none';toast('All notifications read','success','✓');}
function updateNB(){const c=document.querySelectorAll('.ndi.unread').length;const b=document.getElementById('nbadge');const sb=document.getElementById('sideNB');if(c===0){b.style.display='none';if(sb)sb.style.display='none';}else{b.textContent=c;b.style.display='';if(sb){sb.textContent=c;sb.style.display='';}}}

// ═══ SEARCH ═══
function handleSearch(v){if(v.length>0)showSD();else hideSD();}
function showSD(){const g=document.getElementById('gsearch');if(g&&g.value.length>0)document.getElementById('sdrop').classList.add('show');}
function hideSD(){const d=document.getElementById('sdrop');if(d)d.classList.remove('show');}

// ═══ TABS ═══
function switchTab(btn,cid){const m=btn.closest('.md')||btn.closest('.page-section');m.querySelectorAll('.tb').forEach(b=>b.classList.remove('active'));m.querySelectorAll('.tc').forEach(c=>c.classList.remove('active'));btn.classList.add('active');const c=document.getElementById(cid);if(c)c.classList.add('active');}

// ═══ SERVER FILTER ═══
function filterServers(btn, cat){document.querySelectorAll('#serverTagFilter .tf').forEach(t=>t.classList.remove('active'));btn.classList.add('active');document.querySelectorAll('#serverList .server-row').forEach(row=>{row.style.display=(cat==='all'||row.dataset.cat.includes(cat))?'flex':'none';});}

// ═══ ACTIONS ═══
function joinServer(btn,name){const sid=btn?.dataset?.serverId;if(!sid){toast('Refresh the dashboard to load live server controls','info','↻');return;}if(btn.dataset.busy==='1')return;btn.dataset.busy='1';btn.disabled=true;const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';fetch((window.ECOLLAB_BASE||'')+'/API/dashboard/join-server.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({server_id:Number(sid),csrf_token:csrf})}).then(async r=>{const d=await r.json().catch(()=>({}));if(!r.ok||!d.success)throw new Error(d.error||'Unable to join server');btn.className='btn-joined';btn.textContent='✓ Joined';toast('Joined '+name+'!','success','✅');refreshStudentDashboardLive();}).catch(e=>{btn.disabled=false;btn.dataset.busy='0';toast(e.message,'error','❌');});}
function joinClass(name){toast('Courses are now servers. Open '+name+' in Chat.','info','💬');goToChat();}
function doCreateRoom(){const n=document.getElementById('newRoomName').value;if(!n){toast('Enter a room name','error','❌');return;}closeModal('createRoomModal');toast('Study-room creation is managed from Chat.','info','💬');goToChat();}
function doJoinRoom(){const v=document.getElementById('joinRoomInput').value;if(!v){toast('Enter a code','error','❌');return;}closeModal('joinRoomModal');toast('Open Chat to join the study room.','info','💬');goToChat();}
function doUpload(){toast('Files are managed server-wide from Collaboration.','info','📁');window.location.href=(window.ECOLLAB_BASE||'')+'/modules/collaboration/coworkspaces.php';}
function doEnroll(){toast('Course enrollment is replaced by server membership.','info','💬');goToChat();}
function saveNote(){const t=document.getElementById('noteTitle').value;if(!t){toast('Enter a title','error','❌');return;}closeModal('newNoteModal');toast('Note saving is handled by the connected collaboration tools.','info','📝');}
function sendMsg(){goToChat();}

// ═══ AI ═══
const aiR={'Summarize Neural Networks Ch.5':'**Chapter 5 — CNNs**\n\nKey concepts:\n• Convolutional layers detect local features\n• Pooling layers reduce dimensionality\n• Filters/kernels learn feature detectors\n• Popular architectures: LeNet → VGG → ResNet\n\nWant me to quiz you on this?','Quiz me on DSA':'**DSA Quiz — Q1/5**\n\nTime complexity of merge sort (worst case)?\n\nA) O(n)\nB) O(n log n) ✓\nC) O(n²)\nD) O(log n)\n\nType A, B, C, or D!','Create my study plan for this week':'**Your Study Plan for This Week**\n\n📅 Mon–Tue: CS 305 Chapter 6 CNNs (2h/day)\n📅 Wed: CS 201 Trees & Graphs (1.5h)\n📅 Thu: CS 210 Normalization (2h)\n📅 Fri: Review + Quizzes (1h)\n📅 Sat: AI Chatbot Project (3h)\n📅 Sun: Rest & light review\n\nTotal: ~14h — on track for your 20h goal!'};
function sendAI(){const inp=document.getElementById('aiInput');const msg=inp.value.trim();if(!msg)return;appendAI('You',msg,'ai-msg me','ai-label me');inp.value='';const r=aiR[msg]||'Great question! Based on your current servers and activity, I suggest opening the relevant server in Chat and using the connected study resources.';setTimeout(()=>appendAI('AI Assistant',r,'ai-msg ai','ai-label ai'),600);}
function aiQP(p){document.getElementById('aiInput').value=p;sendAI();}
function appendAI(who,text,bc,lc){const log=document.getElementById('aiLog');if(!log)return;const d=document.createElement('div');d.innerHTML=`<div class="${lc}">${who}</div><div class="${bc}">${text}</div>`;log.appendChild(d);log.scrollTop=log.scrollHeight;}

// ═══ CALENDAR ═══
function renderCal(){const g=document.getElementById('calGrid');if(!g||g.children.length>0)return;const now=new Date();const days=new Date(now.getFullYear(),now.getMonth()+1,0).getDate();const start=new Date(now.getFullYear(),now.getMonth(),1).getDay();for(let i=0;i<start;i++){const d=document.createElement('div');d.className='cal-day other';d.textContent='';g.appendChild(d);}for(let i=1;i<=days;i++){const d=document.createElement('div');d.className='cal-day'+(i===now.getDate()?' today':'');d.textContent=i;g.appendChild(d);}}

// ═══ WHITEBOARD ═══
function initWB(){window.location.href=(window.ECOLLAB_BASE||'')+'/modules/collaboration/coworkspaces.php';}
function setTool(t,btn){document.querySelectorAll('.wbt').forEach(b=>b.classList.remove('active'));if(btn)btn.classList.add('active');}
function clearWB(){toast('Whiteboards are now opened from Coworkspaces.','info','🤝');window.location.href=(window.ECOLLAB_BASE||'')+'/modules/collaboration/coworkspaces.php';}

// ═══ CHARTS ═══
function initActChart(){const ctx=document.getElementById('actChart');if(!ctx||ctx._c)return;ctx._c=new Chart(ctx,{type:'line',data:{labels:['6d','5d','4d','3d','2d','1d','Today'],datasets:[{data:[0,0,0,0,0,0,0],borderColor:'#e91e8c',backgroundColor:'rgba(233,30,140,0.07)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#e91e8c',pointRadius:3.5,pointHoverRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.raw+'h'}}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},ticks:{color:'#64748b',font:{size:9}}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},ticks:{color:'#64748b',stepSize:2,font:{size:9}},min:0}}}});}
let a2=false,iC=false;
function initActChart2(){if(a2)return;a2=true;const ctx=document.getElementById('actChart2');if(!ctx)return;ctx._c=new Chart(ctx,{type:'line',data:{labels:['6d','5d','4d','3d','2d','1d','Today'],datasets:[{data:[0,0,0,0,0,0,0],borderColor:'#06b6d4',backgroundColor:'rgba(6,182,212,0.07)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#06b6d4',pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}}}}});}
function initInsChart(){if(iC)return;iC=true;const ctx=document.getElementById('insChart');if(!ctx)return;ctx._c=new Chart(ctx,{type:'bar',data:{labels:[],datasets:[{data:[],backgroundColor:'rgba(124,58,237,0.7)',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}}}}});}

// ═══ TOAST ═══
function toast(msg,type='info',icon='ℹ️'){const c=document.getElementById('tc');if(!c)return;const t=document.createElement('div');t.className='toast '+type;const a=document.createElement('span');a.className='tic';a.textContent=icon;const b=document.createElement('span');b.className='tmsg';b.textContent=msg;const x=document.createElement('span');x.className='tcl';x.textContent='✕';x.onclick=()=>t.remove();t.append(a,b,x);c.appendChild(t);setTimeout(()=>{t.style.transition='all .3s';t.style.opacity='0';t.style.transform='translateX(50px)';setTimeout(()=>t.remove(),300);},3500);}

// ═══ INIT ═══
window.addEventListener('load',()=>setTimeout(initActChart,100));

// ═══ UNIFIED AUTH INTEGRATION ═══
function doLogout(){closeModal('logoutModal');toast('Signing out...','info','🚪');fetch((window.ECOLLAB_BASE||'')+'/API/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:'{}'}).then(()=>{window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php';}).catch(()=>{window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php';});}
function goToChat(){window.location.href=(window.ECOLLAB_BASE||'')+'/modules/chat/chat.php';}

// ═══ LIVE DASHBOARD INTEGRATION ═══
const dashboardLive={loading:false,timer:null,lastUpdated:null};
function dashboardLiveUrl(){const base=(window.ECOLLAB_BASE||'').replace(/\/$/,'');return base+'/API/dashboard/live.php?_='+Date.now();}
function setDashboardLiveStatus(text,ok=true){let el=document.getElementById('dashboardLiveStatus');if(!el){const host=document.querySelector('.dash-header');if(!host)return;el=document.createElement('div');el.id='dashboardLiveStatus';el.style.cssText='margin-top:8px;font-size:10px;color:#64748b;display:flex;align-items:center;gap:6px;';host.appendChild(el);}el.textContent='';const dot=document.createElement('span');dot.style.cssText='width:7px;height:7px;border-radius:50%;background:'+(ok?'#22c55e':'#f59e0b')+';display:inline-block';el.append(dot,document.createTextNode(text));}
function updateStudentLiveStats(data){const cards=document.querySelectorAll('.stats-row .stat-card .sc-val');if(cards[0]&&Array.isArray(data.courses))cards[0].textContent=String(data.courses.length);if(cards[1]&&data.total_sessions!==undefined)cards[1].textContent=String(data.total_sessions);if(cards[2]&&data.hours_studied!==undefined)cards[2].textContent=Number(data.hours_studied).toFixed(1);const badge=document.getElementById('nbadge'),sideBadge=document.getElementById('sideNB');const unread=Math.max(0,Number(data.unread_notifications||0));[badge,sideBadge].forEach(el=>{if(!el)return;el.textContent=String(unread);el.style.display=unread?'':'none';});if(Array.isArray(data.activity_chart)&&data.activity_chart.length===7)['actChart','actChart2'].forEach(id=>{const canvas=document.getElementById(id);if(canvas&&canvas._c){canvas._c.data.datasets[0].data=data.activity_chart.map(Number);canvas._c.update('none');}});}
function renderLiveServers(servers){const list=document.getElementById('serverList');if(!list||!Array.isArray(servers))return;list.innerHTML='';servers.forEach(s=>{const row=document.createElement('div');row.className='server-row';row.dataset.cat=String(s.tags||'');row.dataset.serverId=String(s.id||'');row.addEventListener('click',()=>openModal('serverDetailModal',String(s.name||'')));const av=document.createElement('div');av.className='srv-av';av.textContent=String(s.icon_emoji||'🤖');const body=document.createElement('div');body.className='srv-body';const name=document.createElement('div');name.className='srv-name';name.textContent=String(s.name||'');const desc=document.createElement('div');desc.className='srv-desc';desc.textContent=String(s.description||'');const tags=document.createElement('div');tags.className='srv-tags';String(s.tag_labels||'').split(',').map(x=>x.trim()).filter(Boolean).forEach(t=>{const tag=document.createElement('span');tag.className='srv-tag';tag.textContent=t;tags.appendChild(tag);});body.append(name,desc,tags);const right=document.createElement('div');right.className='srv-right';const count=document.createElement('div');count.className='srv-count';count.textContent=Number(s.member_count||0).toLocaleString();const cl=document.createElement('span');cl.textContent='members';count.appendChild(cl);const online=document.createElement('div');online.className='srv-online';online.textContent=String(Number(s.online_count||0))+' online';const btn=document.createElement('button');btn.className='btn-join';btn.dataset.serverId=String(s.id||'');btn.textContent='Join';btn.addEventListener('click',e=>{e.stopPropagation();joinServer(btn,String(s.name||''));});right.append(count,online,btn);row.append(av,body,right);list.appendChild(row);});}
function renderLiveCourses(courses){const list=document.getElementById('coursesPageList');if(!list||!Array.isArray(courses))return;list.innerHTML='';courses.forEach(s=>{const row=document.createElement('div');row.className='server-row';row.dataset.serverId=String(s.id||'');const av=document.createElement('div');av.className='srv-av';av.textContent=String(s.icon_emoji||'💬');const body=document.createElement('div');body.className='srv-body';const name=document.createElement('div');name.className='srv-name';name.textContent=String(s.name||'');const desc=document.createElement('div');desc.className='srv-desc';desc.textContent=String(s.description||'');const tags=document.createElement('div');tags.className='srv-tags';[''+Number(s.people_count||0)+' people',''+Number(s.channel_count||0)+' channels',s.is_owned?'Owner':String(s.server_role||'Member')].forEach(t=>{const x=document.createElement('span');x.className='srv-tag';x.textContent=t;tags.appendChild(x);});body.append(name,desc,tags);const right=document.createElement('div');right.className='srv-right';const open=document.createElement('button');open.className='btn-join';open.textContent='Open Chat';open.onclick=()=>window.location.href=(window.ECOLLAB_BASE||'')+'/modules/chat/chat.php?server_id='+encodeURIComponent(s.id);right.appendChild(open);row.append(av,body,right);list.appendChild(row);});}
function renderLiveFiles(files){const list=document.getElementById('filesList');if(!list||!Array.isArray(files))return;list.innerHTML='';files.forEach(f=>{const row=document.createElement('div');row.className='file-row';const icon=document.createElement('div');icon.className='fi-ico';icon.textContent=String(f.mime_type||'').startsWith('image/')?'🖼️':'📄';const name=document.createElement('div');name.className='fi-name';name.textContent=String(f.original_name||f.file_name||'Resource');const meta=document.createElement('div');meta.className='fi-meta';meta.textContent=String(f.server_name||'Server')+' · '+String(f.uploader||'');const size=document.createElement('div');size.className='fi-size';size.textContent=f.file_size?Math.max(1,Math.round(Number(f.file_size)/1024))+' KB':'';const open=document.createElement('button');open.className='btn-sm btn-outline';open.textContent='Open';open.onclick=()=>window.open(String(f.file_path||'#'),'_blank','noopener');row.append(icon,name,meta,size,open);list.appendChild(row);});}
function hideAchievements(){document.querySelectorAll('#page-achievements,.stat-card.c4,[onclick*="achievements"],#nav-achievements').forEach(el=>{el.style.display='none';});}
function routeCustomerService(){const text=String(this?.textContent||'').toLowerCase();if(text.includes('report issue')){const sel=document.getElementById('reportIssueType');if(sel)sel.style.background='#fff';} }
async function refreshStudentDashboardLive(){if(dashboardLive.loading)return;dashboardLive.loading=true;try{const response=await fetch(dashboardLiveUrl(),{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});if(response.status===401||response.status===403){setDashboardLiveStatus('Session refresh required',false);return;}if(!response.ok)throw new Error('HTTP '+response.status);const payload=await response.json();if(!payload.success||payload.role!=='student')throw new Error(payload.error||'Invalid dashboard response');const data=payload.data||{};updateStudentLiveStats(data);renderLiveServers(data.recommended_servers);renderLiveCourses(data.courses);renderLiveFiles(data.files);dashboardLive.lastUpdated=payload.generated_at||new Date().toISOString();const time=new Date(dashboardLive.lastUpdated);setDashboardLiveStatus('Live • chat synced '+time.toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}),true);hideAchievements();}catch(error){console.error('[dashboard/live]',error);setDashboardLiveStatus('Live refresh unavailable • showing last loaded data',false);hideAchievements();}finally{dashboardLive.loading=false;}}
function startStudentDashboardLive(){hideAchievements();refreshStudentDashboardLive();if(dashboardLive.timer)clearInterval(dashboardLive.timer);dashboardLive.timer=setInterval(refreshStudentDashboardLive,15000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshStudentDashboardLive();});}
window.addEventListener('load',startStudentDashboardLive);

// ═══ DASHBOARD → CHAT / COLLAB ROUTING ═══
document.addEventListener('click',e=>{const el=e.target.closest('a,button,.sc-card,.stat-card');if(!el)return;const text=String(el.textContent||'').trim().toLowerCase();if(text==='messages'||text.startsWith('messages')){e.preventDefault();e.stopPropagation();goToChat();return;}if(text.includes('whiteboard')&&!text.includes('whiteboard tools')){e.preventDefault();e.stopPropagation();window.location.href=(window.ECOLLAB_BASE||'')+'/modules/collaboration/coworkspaces.php';return;}});
