(function(){
  'use strict';
  const base=()=>window.ECOLLAB?.baseUrl||'';
  const csrf=()=>window.ECOLLAB?.csrfToken||document.querySelector('meta[name="csrf-token"]')?.content||'';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function json(url,opts={}){
    const r=await fetch(url,{credentials:'same-origin',...opts,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf(),...(opts.headers||{})}});
    const d=await r.json().catch(()=>({}));
    if(!r.ok||d.success===false) throw new Error(d.error||d.message||`Request failed (${r.status})`);
    return d;
  }
  function ensureStyles(){
    if(document.getElementById('ec-profile-view-styles')) return;
    const s=document.createElement('style');s.id='ec-profile-view-styles';s.textContent=`
      #profileCardModal.ec-profile-overlay{z-index:12000;align-items:center;justify-content:center;background:rgba(3,6,15,.78);backdrop-filter:blur(7px)}
      #profileCardModal.ec-profile-overlay .ec-profile-card{width:min(920px,94vw);height:min(650px,90vh);background:#303238;border:1px solid rgba(255,255,255,.08);border-radius:18px;overflow:hidden;display:flex;box-shadow:0 30px 90px rgba(0,0,0,.65);color:#f2f3f5}
      .ec-profile-side{width:245px;background:#202226;padding:18px 12px;overflow:auto;border-right:1px solid rgba(255,255,255,.06)}
      .ec-profile-side-title{font-size:12px;font-weight:800;color:#fff;padding:9px 10px;margin-bottom:8px}
      .ec-profile-nav{padding:9px 10px;border-radius:7px;color:#b5bac1;font-size:12px;font-weight:600;margin-bottom:3px}.ec-profile-nav.active,.ec-profile-nav:hover{background:#3f4148;color:#fff}
      .ec-profile-main{flex:1;min-width:0;overflow:auto;background:#303238;position:relative}
      .ec-profile-banner{height:150px;background:linear-gradient(135deg,#a855f7,#ec4899);position:relative}
      .ec-profile-close{position:absolute;right:14px;top:14px;width:34px;height:34px;border:0;border-radius:50%;background:rgba(0,0,0,.3);color:#fff;font-size:22px;cursor:pointer}
      .ec-profile-content{padding:0 28px 28px;position:relative}.ec-profile-avatar{width:112px;height:112px;border-radius:50%;border:7px solid #303238;position:relative;margin-top:-56px;display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:800;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.25);background-size:cover!important;background-position:center!important;overflow:hidden}
      .ec-profile-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:-42px;margin-bottom:34px}.ec-pbtn{border:0;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:800;cursor:pointer}.ec-pbtn.primary{background:#5865f2;color:#fff}.ec-pbtn.secondary{background:#1e1f22;color:#fff;border:1px solid #4b4d52}.ec-pbtn.success{background:#248046;color:#fff}.ec-pbtn:disabled{opacity:.65;cursor:default}
      .ec-profile-name{font-size:25px;font-weight:800;color:#fff}.ec-profile-handle{font-size:13px;color:#b5bac1;margin-top:2px}.ec-profile-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.ec-pill{font-size:10px;padding:4px 8px;border-radius:999px;background:#1e1f22;color:#c9ccd1;border:1px solid #45474d}
      .ec-profile-section{margin-top:24px;padding:14px;border:1px solid rgba(255,255,255,.06);border-radius:12px;background:linear-gradient(145deg,rgba(255,255,255,.025),rgba(88,101,242,.035));transition:.18s transform,.18s border-color}.ec-profile-section:hover{transform:translateY(-1px);border-color:rgba(168,85,247,.28)}.ec-profile-section h4{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#b5bac1;margin:0 0 8px}.ec-profile-bio{font-size:13px;line-height:1.6;color:#dbdee1}.ec-profile-tags{display:flex;flex-wrap:wrap;gap:6px}.ec-profile-tag{font-size:11px;padding:5px 9px;border-radius:7px;background:#1e1f22;color:#c9ccd1;border:1px solid #45474d}
      .ec-profile-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.ec-compat-card{margin-top:10px;padding:12px;border-radius:10px;background:#1e1f22;border:1px solid #45474d}.ec-compat-head{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:800;margin-bottom:9px}.ec-compat-score{font-size:18px;color:#c084fc}.ec-compat-bars{display:grid;gap:7px}.ec-compat-row{display:grid;grid-template-columns:72px 1fr 32px;align-items:center;gap:7px;font-size:10px;color:#b5bac1}.ec-compat-track{height:6px;border-radius:999px;background:#34363c;overflow:hidden}.ec-compat-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#5865f2,#c084fc,#ec4899)}.ec-stat{cursor:pointer;transition:.16s transform}.ec-stat:hover{transform:translateY(-2px)}.ec-stat{background:#1e1f22;border-radius:9px;padding:10px;text-align:center}.ec-stat b{display:block;font-size:16px;color:#fff}.ec-stat span{font-size:10px;color:#949ba4}
      .ec-profile-right{width:350px;background:#2b2d31;border-left:1px solid rgba(255,255,255,.06);padding:24px;overflow:auto}.ec-board-tabs{display:flex;gap:18px;border-bottom:1px solid #45474d;margin-bottom:18px}.ec-board-tab{padding:0 0 10px;color:#949ba4;font-size:12px;font-weight:700}.ec-board-tab.active{color:#fff;border-bottom:2px solid #fff}.ec-widget-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.ec-widget{min-height:92px;border-radius:10px;background:#36383e;border:1px solid #45474d;padding:12px;display:flex;align-items:flex-end;font-size:12px;font-weight:700;color:#fff;position:relative;overflow:hidden}.ec-widget::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(88,101,242,.25),rgba(236,72,153,.1));opacity:.7}.ec-widget span{position:relative;z-index:1}.ec-empty-board{font-size:12px;line-height:1.5;color:#949ba4;background:#36383e;border:1px solid #45474d;border-radius:10px;padding:16px}
      @media(max-width:800px){#profileCardModal.ec-profile-overlay .ec-profile-card{height:92vh;width:96vw}.ec-profile-side{display:none}.ec-profile-right{display:none}.ec-profile-content{padding:0 18px 24px}.ec-profile-banner{height:120px}.ec-profile-avatar{width:92px;height:92px;font-size:34px;margin-top:-46px}.ec-profile-actions{margin-top:-30px;margin-bottom:28px}.ec-profile-name{font-size:21px}}
    `;document.head.appendChild(s);
  }
  function ensureModal(){
    let m=document.getElementById('profileCardModal'); if(!m) return null;
    ensureStyles();m.classList.add('ec-profile-overlay');
    m.innerHTML=`<div class="ec-profile-card" role="dialog" aria-modal="true">
      <aside class="ec-profile-side"><div class="ec-profile-side-title">Profile</div><div class="ec-profile-nav active">Overview</div><div class="ec-profile-nav">Activity</div><div class="ec-profile-nav">Connections</div><div class="ec-profile-nav">Mutual Servers</div><div class="ec-profile-nav">Study</div></aside>
      <main class="ec-profile-main"><div class="ec-profile-banner" id="ecProfileBanner"><button class="ec-profile-close" id="ecProfileClose">×</button></div>
        <div class="ec-profile-content"><div class="ec-profile-avatar" id="ecProfileAvatar">?</div>
          <div class="ec-profile-actions" id="ecProfileActions"></div>
          <div class="ec-profile-name" id="ecProfileName">Loading…</div><div class="ec-profile-handle" id="ecProfileHandle"></div><div class="ec-profile-meta" id="ecProfileMeta"></div>
          <section class="ec-profile-section"><h4>About Me</h4><div class="ec-profile-bio" id="ecProfileBio">Loading profile…</div></section>
          <section class="ec-profile-section"><h4>Interests & Hobbies</h4><div class="ec-profile-tags" id="ecProfileTags"></div></section>
          <section class="ec-profile-section"><h4>Study Profile</h4><div class="ec-profile-stats"><div class="ec-stat"><b id="ecProfileStyle">—</b><span>Study Style</span></div><div class="ec-stat"><b id="ecProfileGoal">—</b><span>Goal</span></div><div class="ec-stat" id="ecMatchStat"><b id="ecProfileCompat">—</b><span>Match</span></div></div><div class="ec-compat-card" id="ecCompatCard" style="display:none"><div class="ec-compat-head"><span>Compatibility with you</span><span class="ec-compat-score" id="ecCompatTotal">—</span></div><div class="ec-compat-bars" id="ecCompatBars"></div></div></section>
          <section class="ec-profile-section"><h4>Study Activity</h4><div class="ec-profile-stats"><div class="ec-stat"><b id="ecProfileStreak">—</b><span>Streak</span></div><div class="ec-stat"><b id="ecProfileHours">—</b><span>Study Hours</span></div><div class="ec-stat"><b id="ecProfileServers">—</b><span>Mutual Servers</span></div></div></section>
        </div>
      </main>
      <aside class="ec-profile-right"><div class="ec-board-tabs"><div class="ec-board-tab active">Board</div><div class="ec-board-tab">Activity</div><div class="ec-board-tab">Wishlist</div></div><div id="ecProfileBoard"></div></aside>
    </div>`;
    m.onclick=e=>{if(e.target===m)close();};document.getElementById('ecProfileClose').onclick=close;return m;
  }
  function close(){const m=document.getElementById('profileCardModal');if(m){m.classList.remove('open');m.style.display='none';}}
  async function open(target){
    const m=ensureModal();if(!m||!target)return;
    m.style.display='flex';m.classList.add('open');
    const loading=['ecProfileName','ecProfileHandle','ecProfileBio'];loading.forEach(id=>{const e=document.getElementById(id);if(e)e.textContent='Loading…';});
    try{
      const param=typeof target==='number'||/^\d+$/.test(String(target))?`user_id=${target}`:`name=${encodeURIComponent(target)}`;
      const d=await json(`${base()}/API/profile/get-profile.php?${param}`);const p=d.profile||{};window.__ecProfileViewed=p;
      const grad=p.avatar_color_gradient||'#a855f7,#ec4899';
      document.getElementById('ecProfileBanner').style.background=`linear-gradient(135deg,${grad})`;
      const av=document.getElementById('ecProfileAvatar');if(p.avatar_url){av.style.background=`url("${String(p.avatar_url).replace(/"/g,'%22')}") center/cover no-repeat`;av.textContent='';}else{av.style.background=`linear-gradient(135deg,${grad})`;av.textContent=(p.full_name||p.username||'?')[0].toUpperCase();}
      document.getElementById('ecProfileName').textContent=p.full_name||p.username||'User';
      document.getElementById('ecProfileHandle').textContent=p.username?`@${p.username}`:'';
      const meta=[];if(p.role)meta.push(p.role==='facilitator'?'Facilitator':p.role.charAt(0).toUpperCase()+p.role.slice(1));if(p.year_level)meta.push(p.year_level);if(p.academic_program)meta.push(p.academic_program);document.getElementById('ecProfileMeta').innerHTML=meta.map(x=>`<span class="ec-pill">${esc(x)}</span>`).join('');
      document.getElementById('ecProfileBio').textContent=(p.bio||'No bio yet.').trim();
      const tags=[...(p.interests||'').split(','),...(p.hobbies||'').split(',')].map(x=>x.trim()).filter(Boolean);document.getElementById('ecProfileTags').innerHTML=tags.length?tags.map(x=>`<span class="ec-profile-tag">${esc(x)}</span>`).join(''):'<span style="font-size:12px;color:#949ba4">No interests listed.</span>';
      document.getElementById('ecProfileStyle').textContent=p.study_style||'—';document.getElementById('ecProfileGoal').textContent=p.goals||'—';document.getElementById('ecProfileCompat').textContent=p.compatibility_score!=null?`${p.compatibility_score}%`:'—';const cc=document.getElementById('ecCompatCard'),cb=p.compatibility_breakdown;if(Number(p.id)!==Number(window.ECOLLAB?.userId)&&p.compatibility_score!=null&&cb){cc.style.display='block';document.getElementById('ecCompatTotal').textContent=p.compatibility_score+'%';document.getElementById('ecCompatBars').innerHTML=[['Subjects',cb.subjects],['Study style',cb.style],['Interests',cb.interests],['Hobbies',cb.hobbies]].map(([label,val])=>'<div class="ec-compat-row"><span>'+label+'</span><div class="ec-compat-track"><div class="ec-compat-fill" style="width:'+Math.max(0,Math.min(100,Number(val)||0))+'%"></div></div><b>'+Math.round(Number(val)||0)+'%</b></div>').join('');}else cc.style.display='none';document.getElementById('ecProfileStreak').textContent=p.streak_days?`${p.streak_days}d`:'—';document.getElementById('ecProfileHours').textContent=p.study_hours?`${p.study_hours}h`:'—';document.getElementById('ecProfileServers').textContent=Array.isArray(p.mutual_servers)?p.mutual_servers.length:'0';
      document.querySelectorAll('.ec-profile-nav').forEach((el,i)=>{el.style.cursor='pointer';el.onclick=()=>{document.querySelectorAll('.ec-profile-nav').forEach(n=>n.classList.remove('active'));el.classList.add('active');if(i===0)return;if(i===1)document.querySelector('.ec-profile-section:last-of-type')?.scrollIntoView({behavior:'smooth',block:'center'});if(i===2&&Number(p.id)!==Number(window.ECOLLAB?.userId))openDM(p.id,p.full_name||p.username,p.avatar_color_gradient||'');if(i===3)document.getElementById('ecProfileBoard')?.scrollIntoView({behavior:'smooth'});if(i===4&&typeof window.openPeerMatchingModal==='function'){close();window.openPeerMatchingModal();}}});const ms=document.getElementById('ecMatchStat');if(ms)ms.onclick=()=>{if(typeof window.openPeerMatchingModal==='function'){close();window.openPeerMatchingModal();}};const board=document.getElementById('ecProfileBoard');const servers=Array.isArray(p.mutual_servers)?p.mutual_servers:[];board.innerHTML=servers.length?`<div class="ec-widget-grid">${servers.slice(0,6).map(s=>`<div class="ec-widget"><span>⭐ ${esc(s)}</span></div>`).join('')}</div>`:`<div class="ec-empty-board">${p.id===Number(window.ECOLLAB?.userId)?'Your profile is ready. Use Dashboard to manage your study workspace.':'No mutual servers yet.'}</div>`;
      const actions=document.getElementById('ecProfileActions');const me=Number(window.ECOLLAB?.userId||0);actions.innerHTML='';
      if(Number(p.id)===me){actions.innerHTML=`<button class="ec-pbtn primary" id="ecDashboardBtn">🏠 Dashboard</button><button class="ec-pbtn secondary" id="ecEditProfileBtn">✎ Edit Profile</button>`;document.getElementById('ecDashboardBtn').onclick=()=>{if(typeof window.goToDashboard==='function')window.goToDashboard();else window.location.href=base()+'/modules/student/dashboard.php';};document.getElementById('ecEditProfileBtn').onclick=()=>{close();window.location.href=base()+'/modules/chat/settings.php?section=profile';};}
      else{
        const msg=document.createElement('button');msg.className='ec-pbtn primary';msg.textContent='💬 Message';msg.onclick=()=>openDM(p.id,p.full_name||p.username,p.avatar_color_gradient||'');actions.appendChild(msg);
        const conn=document.createElement('button');conn.className='ec-pbtn secondary';conn.id='ecConnectBtn';conn.textContent=p.connection_status==='accepted'?'Connected ✓':p.connection_status==='pending'?'⏳ Pending…':'＋ Connect';conn.disabled=p.connection_status==='accepted'||p.connection_status==='pending';if(!conn.disabled)conn.onclick=async()=>{try{const r=await json(`${base()}/API/friendship/send-request.php`,{method:'POST',body:JSON.stringify({addressee_id:Number(p.id)})});conn.textContent=r.status==='accepted'?'Connected ✓':'⏳ Pending…';conn.disabled=true;window.showToast?.(r.status==='accepted'?'Already connected':'Connection request sent!','success');}catch(e){window.showToast?.(e.message||'Could not connect','info');}};actions.appendChild(conn);
      }
    }catch(e){document.getElementById('ecProfileName').textContent='Profile unavailable';document.getElementById('ecProfileBio').textContent=e.message||'Could not load profile.';}
  }

  // Route the profile Message button into the existing Chat DM UI.
  // Do not open the legacy Thread/private-message panel or create a second DM UI.
  async function openDM(id,name,gradient){
    close();
    try{
      if(typeof window.switchView==='function'){
        window.switchView('home',document.querySelector('.sidebar-nav-item'));
      }
      if(typeof window.openDmConversation==='function'){
        await window.openDmConversation(Number(id),name,gradient||'');
        return;
      }
      window.showToast?.('Chat DM is not ready yet.','info');
    }catch(e){
      window.showToast?.(e.message||'Could not open conversation','info');
    }
  }

  function install(){
    window.openFullProfileCard=target=>{const t=target||window._miniProfileUserId||window._miniProfileUsername;return open(t);};
    window.__real_openFullProfileCard=window.openFullProfileCard;
    window.openMiniProfile=function(event,name,role,avatarGrad,initials,userId){
      if(event?.stopPropagation)event.stopPropagation();window._miniProfileUserId=Number(userId||0);window._miniProfileUsername=name||'';window._miniProfileGradient=avatarGrad||'';
      const mp=document.getElementById('miniProfile');if(mp){mp.dataset.userId=String(userId||0);mp.style.display='none';}
      return open(Number(userId||0)||name);
    };
    window.__real_openMiniProfile=window.openMiniProfile;
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
})();

(function(){
  'use strict';
  const base=()=>window.ECOLLAB?.baseUrl||'';
  window.openUserSettings=function(){window.location.href=base()+'/modules/chat/settings.php';};
})();

// Final DM routing guard: legacy Thread/private-message UI must never be used
// for profile or mini-profile messaging. Reuse the single DM component.
(function(){
  function routeToExistingDm(userId,name,gradient){
    const id=Number(userId||0);
    if(!id)return;
    try{ window.closeMiniProfile?.(); }catch(_){ }
    try{ window.switchView?.('home',document.querySelector('.sidebar-nav-item')); }catch(_){ }
    if(typeof window.openDmConversation==='function'){
      return window.openDmConversation(id,name||'User',gradient||'');
    }
    window.showToast?.('Chat DM is not ready yet.','info');
  }
  window.openThreadDM=function(userId,displayName){
    const grad=window._miniProfileGradient||'';
    return routeToExistingDm(userId,displayName,grad);
  };
  window.openThreadDMFromMiniProfile=function(){
    const mp=document.getElementById('miniProfile');
    const id=Number(window._miniProfileUserId||mp?.dataset?.userId||0);
    const name=document.getElementById('mpName')?.textContent||window._miniProfileUsername||'User';
    const grad=window._miniProfileGradient||'';
    return routeToExistingDm(id,name,grad);
  };
})();