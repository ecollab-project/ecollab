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
  if(id==='whiteboard') initWB();
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
function handleNotif(el,msg){el.classList.remove('unread');const d=el.querySelector('.ndd');if(d)d.remove();updateNB();toast(msg,'info','🔔');}
function clearNotifs(){document.querySelectorAll('.ndi').forEach(i=>{i.classList.remove('unread');const d=i.querySelector('.ndd');if(d)d.remove();});document.getElementById('nbadge').style.display='none';document.getElementById('sideNB').style.display='none';toast('All notifications read','success','✓');}
function updateNB(){const c=document.querySelectorAll('.ndi.unread').length;const b=document.getElementById('nbadge');const sb=document.getElementById('sideNB');if(c===0){b.style.display='none';sb.style.display='none';}else{b.textContent=c;sb.textContent=c;}}

// ═══ SEARCH ═══
function handleSearch(v){if(v.length>0)showSD();else hideSD();}
function showSD(){if(document.getElementById('gsearch').value.length>0)document.getElementById('sdrop').classList.add('show');}
function hideSD(){document.getElementById('sdrop').classList.remove('show');}

// ═══ TABS ═══
function switchTab(btn,cid){const m=btn.closest('.md')||btn.closest('.page-section');m.querySelectorAll('.tb').forEach(b=>b.classList.remove('active'));m.querySelectorAll('.tc').forEach(c=>c.classList.remove('active'));btn.classList.add('active');const c=document.getElementById(cid);if(c)c.classList.add('active');}

// ═══ SERVER FILTER ═══
function filterServers(btn, cat){
  document.querySelectorAll('#serverTagFilter .tf').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#serverList .server-row').forEach(row=>{
    row.style.display=(cat==='all'||row.dataset.cat.includes(cat))?'flex':'none';
  });
}

// ═══ ACTIONS ═══
function joinServer(btn,name){if(btn.classList.contains('btn-joined')){toast('Already in '+name,'info','✓');return;}btn.className='btn-joined';btn.textContent='✓ Joined';toast('Joined '+name+'!','success','✅');}
function joinClass(name){toast('Joining '+name+'...','info','🏠');setTimeout(()=>toast('Connected to '+name,'success','✅'),800);}
function doCreateRoom(){const n=document.getElementById('newRoomName').value;if(!n){toast('Enter a room name','error','❌');return;}closeModal('createRoomModal');const list=document.getElementById('roomsPageList');const d=document.createElement('div');d.className='server-row';d.setAttribute('data-cat','cs');d.innerHTML=`<div class="srv-av" style="background:rgba(233,30,140,.15)">🏠</div><div class="srv-body"><div class="srv-name">${n}</div><div class="srv-desc">Custom study room</div></div><div class="srv-right"><button class="btn-join" onclick="joinServer(this,'${n}')">Join</button></div>`;list.querySelector('.sch').after(d);toast('Room "'+n+'" created!','success','✅');document.getElementById('newRoomName').value='';}
function doJoinRoom(){const v=document.getElementById('joinRoomInput').value;if(!v){toast('Enter a code','error','❌');return;}closeModal('joinRoomModal');toast('Joined room: '+v,'success','✅');}
function doUpload(){closeModal('uploadModal');toast('File uploaded!','success','✅');const list=document.getElementById('filesList');const d=document.createElement('div');d.className='file-row';d.innerHTML=`<div class="fi-ico" style="background:rgba(124,58,237,.15)">📄</div><div class="fi-name">new_file_${Date.now()}.pdf</div><div class="fi-meta">CS 305 · You</div><div class="fi-size">—</div><button class="btn-sm btn-outline" onclick="toast('Downloading...','info','⬇')">Download</button>`;list.insertBefore(d,list.firstChild);}
function doEnroll(){const c=document.getElementById('enrollCode').value;if(!c){toast('Enter a course code','error','❌');return;}closeModal('enrollModal');toast('Enrolled in '+c+'!','success','✅');}
function saveNote(){const t=document.getElementById('noteTitle').value;if(!t){toast('Enter a title','error','❌');return;}closeModal('newNoteModal');const list=document.getElementById('notesList');const c=document.createElement('div');c.className='note-card';c.onclick=()=>openModal('viewNoteModal',t);c.innerHTML=`<div class="note-title">${t}</div><div class="note-preview">${document.getElementById('noteContent').value||'No content yet...'}</div><div class="note-meta"><span>Today</span><div style="display:flex;gap:5px"><button class="btn-sm btn-outline" style="padding:3px 7px" onclick="event.stopPropagation();openModal('editNoteModal','${t}')">Edit</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;padding:3px 7px;border-radius:5px;font-size:9.5px;cursor:pointer" onclick="event.stopPropagation();this.closest('.note-card').remove();toast('Note deleted','info','🗑')">Delete</button></div></div>`;list.insertBefore(c,list.firstChild);toast('Note saved!','success','✅');}
function sendMsg(){const inp=document.getElementById('msgInput');const msg=inp.value.trim();if(!msg)return;const list=document.getElementById('msgList');const d=document.createElement('div');d.className='msg-row';d.style.flexDirection='row-reverse';d.innerHTML=`<div class="m-av" style="background:linear-gradient(135deg,#2563eb,#06b6d4)">J</div><div style="align-items:flex-end;display:flex;flex-direction:column"><div class="m-name" style="text-align:right">You</div><div class="m-bubble mine"><div class="m-txt">${msg}</div><div class="m-time" style="text-align:right">Just now</div></div></div>`;list.appendChild(d);inp.value='';list.scrollTop=list.scrollHeight;}
function doLogout(){closeModal('logoutModal');toast('Signing out...','info','🚪');setTimeout(()=>{document.body.innerHTML='<div style="display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:14px;background:#070b14;color:#f1f5f9;font-family:Plus Jakarta Sans,sans-serif"><div style="font-size:30px">🔷</div><div style="font-size:22px;font-weight:800">Ecollab</div><div style="color:#94a3b8;font-size:13px">You have been signed out.</div><button onclick="location.reload()" style="margin-top:10px;padding:9px 22px;background:linear-gradient(135deg,#e91e8c,#7c3aed);border:none;border-radius:9px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">Sign In Again</button></div>';},1000);}

// ═══ AI ═══
const aiR={'Summarize Neural Networks Ch.5':'**Chapter 5 — CNNs**\n\nKey concepts:\n• Convolutional layers detect local features\n• Pooling layers reduce dimensionality\n• Filters/kernels learn feature detectors\n• Popular architectures: LeNet → VGG → ResNet\n\nWant me to quiz you on this?','Quiz me on DSA':'**DSA Quiz — Q1/5**\n\nTime complexity of merge sort (worst case)?\n\nA) O(n)\nB) O(n log n) ✓\nC) O(n²)\nD) O(log n)\n\nType A, B, C, or D!','Create my study plan for this week':'**Your Study Plan for This Week**\n\n📅 Mon–Tue: CS 305 Chapter 6 CNNs (2h/day)\n📅 Wed: CS 201 Trees & Graphs (1.5h)\n📅 Thu: CS 210 Normalization (2h)\n📅 Fri: Review + Quizzes (1h)\n📅 Sat: AI Chatbot Project (3h)\n📅 Sun: Rest & light review\n\nTotal: ~14h — on track for your 20h goal!'};
function sendAI(){const inp=document.getElementById('aiInput');const msg=inp.value.trim();if(!msg)return;appendAI('You',msg,'ai-msg me','ai-label me');inp.value='';const r=aiR[msg]||'Great question! Based on your CS 305 and CS 201 courses, I suggest reviewing the lecture notes first. I can generate practice problems or explain specific concepts — what would help most?';setTimeout(()=>appendAI('AI Assistant',r,'ai-msg ai','ai-label ai'),600);}
function aiQP(p){document.getElementById('aiInput').value=p;sendAI();}
function appendAI(who,text,bc,lc){const log=document.getElementById('aiLog');const d=document.createElement('div');d.innerHTML=`<div class="${lc}">${who}</div><div class="${bc}">${text}</div>`;log.appendChild(d);log.scrollTop=log.scrollHeight;}

// ═══ CALENDAR ═══
function renderCal(){const g=document.getElementById('calGrid');if(!g||g.children.length>0)return;const events=[19,22,24,25,26,28];const start=4;const days=31;for(let i=0;i<start;i++){const d=document.createElement('div');d.className='cal-day other';d.textContent=30-start+i+1;g.appendChild(d);}for(let i=1;i<=days;i++){const d=document.createElement('div');d.className='cal-day'+(i===19?' today':'')+(events.includes(i)?' has-event':'');d.textContent=i;d.onclick=()=>toast('May '+i+(events.includes(i)?' — session scheduled':''),'info','📅');g.appendChild(d);}for(let i=1;i<=(7-(days+start)%7)%7;i++){const d=document.createElement('div');d.className='cal-day other';d.textContent=i;g.appendChild(d);}}

// ═══ WHITEBOARD ═══
let wbTool='pen',wbDraw=false,wbLX=0,wbLY=0,wbCtx;
function initWB(){const c=document.getElementById('wbCanvas');if(!c||c._init)return;c._init=true;c.width=c.offsetWidth||800;wbCtx=c.getContext('2d');wbCtx.lineCap='round';wbCtx.lineJoin='round';c.addEventListener('mousedown',e=>{wbDraw=true;const r=c.getBoundingClientRect();wbLX=e.clientX-r.left;wbLY=e.clientY-r.top;});c.addEventListener('mousemove',e=>{if(!wbDraw)return;const r=c.getBoundingClientRect();const x=e.clientX-r.left,y=e.clientY-r.top;wbCtx.globalCompositeOperation=wbTool==='eraser'?'destination-out':'source-over';wbCtx.strokeStyle=document.getElementById('wbColor').value;wbCtx.lineWidth=document.getElementById('wbSize').value;wbCtx.beginPath();wbCtx.moveTo(wbLX,wbLY);wbCtx.lineTo(x,y);wbCtx.stroke();wbLX=x;wbLY=y;});c.addEventListener('mouseup',()=>wbDraw=false);c.addEventListener('mouseleave',()=>wbDraw=false);}
function setTool(t,btn){wbTool=t;document.querySelectorAll('.wbt').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
function clearWB(){if(wbCtx)wbCtx.clearRect(0,0,document.getElementById('wbCanvas').width,document.getElementById('wbCanvas').height);toast('Canvas cleared','info','🗑');}

// ═══ CHARTS ═══
function initActChart(){
  const ctx=document.getElementById('actChart');
  if(!ctx||ctx._c)return;
  ctx._c=new Chart(ctx,{type:'line',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[2,3.5,2.5,3.5,6.4,1.8,0.8],borderColor:'#e91e8c',backgroundColor:'rgba(233,30,140,0.07)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#e91e8c',pointRadius:3.5,pointHoverRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.raw+'h'}}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},ticks:{color:'#64748b',font:{size:9}}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false},ticks:{color:'#64748b',stepSize:2,font:{size:9}},min:0,max:8}}}});
}
let a2=false,iC=false;
function initActChart2(){if(a2)return;a2=true;const ctx=document.getElementById('actChart2');if(!ctx)return;ctx._c=new Chart(ctx,{type:'line',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[2,3.5,2.5,3.5,6.4,1.8,0.8],borderColor:'#06b6d4',backgroundColor:'rgba(6,182,212,0.07)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#06b6d4',pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}}}}});}
function initInsChart(){if(iC)return;iC=true;const ctx=document.getElementById('insChart');if(!ctx)return;ctx._c=new Chart(ctx,{type:'bar',data:{labels:['CS 305','CS 201','CS 210','CS 410','CS 101'],datasets:[{data:[7.2,4.1,3.5,2.8,1.0],backgroundColor:['rgba(233,30,140,0.7)','rgba(124,58,237,0.7)','rgba(37,99,235,0.7)','rgba(22,163,74,0.7)','rgba(217,119,6,0.7)'],borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},border:{display:false}}}}});}

// ═══ TOAST ═══
function toast(msg,type='info',icon='ℹ️'){const c=document.getElementById('tc');const t=document.createElement('div');t.className='toast '+type;t.innerHTML=`<span class="tic">${icon}</span><span class="tmsg">${msg}</span><span class="tcl" onclick="this.parentElement.remove()">✕</span>`;c.appendChild(t);setTimeout(()=>{t.style.transition='all .3s';t.style.opacity='0';t.style.transform='translateX(50px)';setTimeout(()=>t.remove(),300);},3500);}

// ═══ INIT ═══
window.addEventListener('load',()=>setTimeout(initActChart,100));

// ═══ UNIFIED AUTH INTEGRATION ═══
function doLogout(){
  closeModal('logoutModal');
  toast('Signing out...','info','🚪');
  fetch((window.ECOLLAB_BASE||'')+'/API/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:'{}'})
    .then(()=>{ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php'; })
    .catch(()=>{ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php'; });
}
function goToChat(){ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/chat/chat.php'; }
