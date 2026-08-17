/* Ecollab Threads v2 — Reddit-style scoped discussions. */
(function () {
  'use strict';

  const base = () => window.ECOLLAB?.baseUrl || '';
  const csrf = () => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
  const api = (window.ECOLLAB?.baseUrl || '') + '/API/threads/index.php';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (m,t='info') => window.showToast?.(m,t);

  async function request(url, options={}) {
    const res = await fetch(url, { credentials:'same-origin', ...options, headers:{'Content-Type':'application/json','X-CSRF-Token':csrf(),...(options.headers||{})} });
    const data = await res.json().catch(()=>({}));
    if (!res.ok || data.success === false) throw new Error(data.error || data.message || `Request failed (${res.status})`);
    return data;
  }

  let activeScope = 'all';
  let activeThreadId = 0;
  let originalSwitchView = null;
  let initialized = false;

  function scopeLabel(scope) {
    return scope === 'public' ? '🌐 Public' : scope === 'server' ? '⭐ Server' : '🔒 Channel';
  }
  function scopeClass(scope) { return `thread-scope-${scope}`; }
  function relTime(s) {
    if (!s) return '';
    const d=new Date(s), diff=(Date.now()-d.getTime())/1000;
    if(diff<60)return'now'; if(diff<3600)return`${Math.floor(diff/60)}m`; if(diff<86400)return`${Math.floor(diff/3600)}h`;
    return d.toLocaleDateString([], {month:'short',day:'numeric'});
  }
  function authorName(t){return t.author_name || t.author_username || 'Unknown';}
  function gradient(g){return g || '#a855f7,#ec4899';}

  function ensureStyles(){
    if(document.getElementById('threadsV2Styles')) return;
    const s=document.createElement('style'); s.id='threadsV2Styles';
    s.textContent=`
      #threadsV2View{height:100%;display:flex;flex-direction:column;min-width:0;background:var(--bg-primary)}
      .tv2-head{height:58px;flex-shrink:0;display:flex;align-items:center;gap:12px;padding:0 20px;border-bottom:1px solid var(--border);background:var(--bg-secondary)}
      .tv2-title{font-size:16px;font-weight:800;color:var(--text-primary)} .tv2-sub{font-size:11px;color:var(--text-muted);margin-top:2px}
      .tv2-tabs{display:flex;gap:6px;padding:12px 20px 8px;border-bottom:1px solid var(--border);overflow-x:auto}
      .tv2-tab{border:1px solid var(--border);background:var(--bg-tertiary);color:var(--text-muted);padding:7px 12px;border-radius:999px;font:600 11px Inter,sans-serif;cursor:pointer;white-space:nowrap}.tv2-tab.active{color:#c084fc;border-color:rgba(168,85,247,.4);background:rgba(168,85,247,.12)}
      .tv2-body{flex:1;overflow-y:auto;padding:14px 20px}.tv2-feed{max-width:920px;margin:0 auto}.tv2-card{background:var(--bg-tertiary);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px;transition:.12s}.tv2-card:hover{border-color:rgba(168,85,247,.28)}
      .tv2-meta{display:flex;align-items:center;gap:7px;font-size:10px;color:var(--text-muted);margin-bottom:8px}.tv2-scope{padding:2px 7px;border-radius:999px;background:rgba(168,85,247,.1);color:#c084fc;font-weight:700}.tv2-card h3{margin:0 0 6px;font-size:15px;color:var(--text-primary);line-height:1.35}.tv2-card p{margin:0;color:var(--text-secondary);font-size:12px;line-height:1.55;white-space:pre-wrap;word-break:break-word}.tv2-author{font-weight:700;color:var(--text-primary)}
      .tv2-actions{display:flex;align-items:center;gap:7px;margin-top:12px}.tv2-vote{border:1px solid var(--border);background:var(--bg-card);color:var(--text-muted);border-radius:7px;padding:5px 8px;cursor:pointer;font:600 11px Inter,sans-serif}.tv2-vote.active{color:#c084fc;border-color:rgba(168,85,247,.45);background:rgba(168,85,247,.1)}.tv2-replies{color:var(--text-muted);font-size:11px;margin-left:3px}.tv2-open{margin-left:auto;border:1px solid rgba(168,85,247,.25);background:rgba(168,85,247,.08);color:#c084fc;border-radius:7px;padding:5px 10px;cursor:pointer;font:600 11px Inter,sans-serif}
      .tv2-empty{text-align:center;padding:70px 20px;color:var(--text-muted);font-size:13px}.tv2-empty strong{display:block;color:var(--text-secondary);font-size:15px;margin-bottom:5px}
      .tv2-modal{position:fixed;inset:0;z-index:12000;background:rgba(0,0,0,.7);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;padding:20px}.tv2-modal.open{display:flex}.tv2-box{width:min(760px,96vw);max-height:88vh;display:flex;flex-direction:column;background:var(--bg-secondary);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.65)}.tv2-modal-head{padding:15px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}.tv2-modal-title{font-size:15px;font-weight:800;color:var(--text-primary);flex:1}.tv2-close{border:0;background:none;color:var(--text-muted);font-size:21px;cursor:pointer}.tv2-modal-body{padding:16px;overflow-y:auto}.tv2-field{width:100%;box-sizing:border-box;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:9px;padding:10px 12px;color:var(--text-primary);font:13px Inter,sans-serif;outline:none;margin-bottom:10px}.tv2-field:focus{border-color:rgba(168,85,247,.5)}textarea.tv2-field{min-height:130px;resize:vertical}.tv2-footer{padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}.tv2-btn{border:1px solid var(--border);background:var(--bg-tertiary);color:var(--text-secondary);border-radius:8px;padding:8px 14px;cursor:pointer;font:600 12px Inter,sans-serif}.tv2-btn.primary{background:linear-gradient(135deg,#a855f7,#ec4899);border:0;color:#fff}.tv2-scope-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px}.tv2-scope-opt{padding:10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-tertiary);cursor:pointer}.tv2-scope-opt.active{border-color:rgba(168,85,247,.5);background:rgba(168,85,247,.1)}.tv2-scope-opt b{display:block;color:var(--text-primary);font-size:12px}.tv2-scope-opt span{display:block;color:var(--text-muted);font-size:10px;margin-top:3px}
      .tv2-detail{max-width:920px;margin:0 auto}.tv2-reply{display:flex;gap:10px;padding:12px 0;border-top:1px solid var(--border)}.tv2-avatar{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}.tv2-reply-body{flex:1;min-width:0}.tv2-reply-text{font-size:12px;color:var(--text-secondary);line-height:1.5;white-space:pre-wrap;word-break:break-word}.tv2-reply-actions{display:flex;gap:5px;margin-top:6px}.tv2-back{border:1px solid var(--border);background:var(--bg-tertiary);color:var(--text-secondary);border-radius:8px;padding:6px 10px;cursor:pointer;font:600 11px Inter,sans-serif}
      @media(max-width:700px){.tv2-scope-grid{grid-template-columns:1fr}.tv2-head{padding:0 12px}.tv2-body,.tv2-tabs{padding-left:12px;padding-right:12px}}
    `; document.head.appendChild(s);
  }

  function ensureModal(){
    if(document.getElementById('threadsV2CreateModal')) return;
    document.body.insertAdjacentHTML('beforeend',`
      <div class="tv2-modal" id="threadsV2CreateModal" onclick="if(event.target===this)window.closeThreadCreateModal()">
        <div class="tv2-box"><div class="tv2-modal-head"><div class="tv2-modal-title">Start a Discussion</div><button class="tv2-close" onclick="closeThreadCreateModal()">×</button></div>
          <div class="tv2-modal-body"><input id="tv2CreateTitle" class="tv2-field" maxlength="180" placeholder="Discussion title / topic…"><textarea id="tv2CreateBody" class="tv2-field" placeholder="Explain the topic and ask for opinions…"></textarea>
            <div class="tv2-scope-grid"><div class="tv2-scope-opt active" data-create-scope="public" onclick="selectThreadCreateScope('public')"><b>🌐 Public</b><span>Visible system-wide</span></div><div class="tv2-scope-opt" data-create-scope="server" onclick="selectThreadCreateScope('server')"><b>⭐ Server</b><span>Everyone in this server</span></div><div class="tv2-scope-opt" data-create-scope="channel" onclick="selectThreadCreateScope('channel')"><b>🔒 Channel</b><span>Everyone with channel access</span></div></div>
            <div id="tv2CreateContext" style="font-size:11px;color:var(--text-muted);padding:8px 10px;background:rgba(168,85,247,.05);border:1px solid rgba(168,85,247,.12);border-radius:8px"></div>
          </div><div class="tv2-footer"><button class="tv2-btn" onclick="closeThreadCreateModal()">Cancel</button><button class="tv2-btn primary" onclick="createThreadV2()">Post Discussion</button></div></div>
      </div>
      <div class="tv2-modal" id="threadsV2DetailModal" onclick="if(event.target===this)closeThreadDetail()"><div class="tv2-box"><div class="tv2-modal-head"><button class="tv2-back" onclick="closeThreadDetail()">← Back</button><div class="tv2-modal-title" id="tv2DetailTitle">Discussion</div><button class="tv2-close" onclick="closeThreadDetail()">×</button></div><div class="tv2-modal-body" id="tv2DetailBody"></div></div></div>`);
  }

  function contextText(){
    const sid=Number(window.ECOLLAB?.currentServerId||0), cid=Number(window.ECOLLAB?.currentChannelId||0);
    const server=document.querySelector('.workspace-icon.active')?.title || document.getElementById('wsName')?.textContent || 'current server';
    const channel=document.querySelector(`.channel-item[data-channel-id="${cid}"]`)?.dataset.channelName || document.getElementById('channelTitle')?.textContent || 'current channel';
    const el=document.getElementById('tv2CreateContext');
    if(el) el.textContent=`Current context: ${server}${cid?' / #'+channel:''}`;
  }

  window.selectThreadCreateScope=function(scope){
    document.querySelectorAll('[data-create-scope]').forEach(x=>x.classList.toggle('active',x.dataset.createScope===scope));
    window._threadCreateScope=scope; contextText();
  };
  window.closeThreadCreateModal=function(){document.getElementById('threadsV2CreateModal')?.classList.remove('open');};
  window.openThreadCreateModal=function(){ensureStyles();ensureModal();window._threadCreateScope='public';document.querySelectorAll('[data-create-scope]').forEach(x=>x.classList.toggle('active',x.dataset.createScope==='public'));document.getElementById('tv2CreateTitle').value='';document.getElementById('tv2CreateBody').value='';contextText();document.getElementById('threadsV2CreateModal').classList.add('open');setTimeout(()=>document.getElementById('tv2CreateTitle')?.focus(),50);};

  window.createThreadV2=async function(){
    const title=document.getElementById('tv2CreateTitle')?.value.trim(), body=document.getElementById('tv2CreateBody')?.value.trim();
    const scope=window._threadCreateScope||'public', serverId=Number(window.ECOLLAB?.currentServerId||0), channelId=Number(window.ECOLLAB?.currentChannelId||0);
    if(!title||!body){toast('Add a title and discussion body.','info');return;}
    try{await request(api,{method:'POST',body:JSON.stringify({action:'create',title,body,scope,server_id:serverId,channel_id:channelId})});closeThreadCreateModal();toast('Discussion posted.','success');if(window._currentNavView==='threads')loadFeed('all');}
    catch(e){toast(e.message,'error');}
  };

  function threadCard(t){
    const g=gradient(t.author_gradient), init=authorName(t).charAt(0).toUpperCase();
    return `<article class="tv2-card"><div class="tv2-meta"><span class="tv2-scope ${scopeClass(t.scope)}">${scopeLabel(t.scope)}</span><span>by <span class="tv2-author">${esc(authorName(t))}</span></span><span>· ${relTime(t.created_at)}</span>${t.server_name?`<span>· ${esc(t.server_name)}</span>`:''}${t.channel_name?`<span>· #${esc(t.channel_name)}</span>`:''}</div><h3>${esc(t.title)}</h3><p>${esc(t.body)}</p><div class="tv2-actions"><button class="tv2-vote ${Number(t.my_vote)===1?'active':''}" onclick="voteThreadV2('thread',${t.id},${Number(t.my_vote)===1?0:1})">▲ ${Number(t.score)||0}</button><button class="tv2-vote ${Number(t.my_vote)===-1?'active':''}" onclick="voteThreadV2('thread',${t.id},${Number(t.my_vote)===-1?0:-1})">▼</button><span class="tv2-replies">💬 ${Number(t.reply_count)||0} replies</span><button class="tv2-open" onclick="openThreadDetail(${t.id})">Open discussion →</button></div></article>`;
  }

  async function loadFeed(scope='all'){
    activeScope=scope; const body=document.getElementById('tv2Feed'); if(!body)return; body.innerHTML='<div class="tv2-empty">Loading discussions…</div>';
    const sid=Number(window.ECOLLAB?.currentServerId||0), cid=Number(window.ECOLLAB?.currentChannelId||0);
    try{const d=await request(`${api}?scope=${encodeURIComponent(scope)}&server_id=${sid}&channel_id=${cid}&limit=50`);body.innerHTML=d.threads?.length?d.threads.map(threadCard).join(''):'<div class="tv2-empty"><strong>No discussions yet</strong>Start the first discussion for this scope.</div>';}
    catch(e){body.innerHTML=`<div class="tv2-empty"><strong>Could not load discussions</strong>${esc(e.message)}</div>`;}
  }
  window.loadThreadsV2=loadFeed;

  window.voteThreadV2=async function(target,id,vote){try{const d=await request(api,{method:'POST',body:JSON.stringify({action:'vote',target,id,vote})});const b=document.querySelector(`button[onclick*="voteThreadV2('${target}',${id},"]`);if(b)b.blur();loadFeed(activeScope);}catch(e){toast(e.message,'error');}};

  function renderView(){
    ensureStyles();ensureModal();
    const overlay=document.getElementById('navViewOverlay'); if(!overlay)return;
    overlay.innerHTML=`<div id="threadsV2View"><div class="tv2-head"><div style="font-size:22px">💬</div><div><div class="tv2-title">Discussions</div><div class="tv2-sub">Reddit-style topics for Ecollab — public, server-wide, or channel-wide.</div></div><button class="tv2-btn primary" style="margin-left:auto" onclick="openThreadCreateModal()">+ Start Discussion</button></div><div class="tv2-tabs"><button class="tv2-tab active" data-scope="all" onclick="setThreadScope('all',this)">All visible</button><button class="tv2-tab" data-scope="public" onclick="setThreadScope('public',this)">🌐 Public</button><button class="tv2-tab" data-scope="server" onclick="setThreadScope('server',this)">⭐ This Server</button><button class="tv2-tab" data-scope="channel" onclick="setThreadScope('channel',this)">🔒 This Channel</button></div><div class="tv2-body"><div id="tv2Feed" class="tv2-feed"></div></div></div>`;
    loadFeed(activeScope);
  }
  window.setThreadScope=function(scope,el){document.querySelectorAll('.tv2-tab').forEach(x=>x.classList.remove('active'));el?.classList.add('active');loadFeed(scope);};

  window.openThreadDetail=async function(id){
    ensureStyles();ensureModal();activeThreadId=id;const body=document.getElementById('tv2DetailBody');const modal=document.getElementById('threadsV2DetailModal');if(!body||!modal)return;modal.classList.add('open');body.innerHTML='<div class="tv2-empty">Loading discussion…</div>';
    try{const d=await request(`${api}?action=get&id=${id}`);const t=d.thread;document.getElementById('tv2DetailTitle').textContent=t.title;body.innerHTML=`<div class="tv2-detail"><div class="tv2-meta"><span class="tv2-scope">${scopeLabel(t.scope)}</span><span>by <b>${esc(authorName(t))}</b> · ${relTime(t.created_at)}</span></div><h2 style="font-size:19px;color:var(--text-primary);margin:0 0 8px">${esc(t.title)}</h2><p style="font-size:13px;line-height:1.65;color:var(--text-secondary);white-space:pre-wrap">${esc(t.body)}</p><div class="tv2-actions" style="margin:14px 0"><button class="tv2-vote ${Number(t.my_vote)===1?'active':''}" onclick="voteDetailV2('thread',${t.id},${Number(t.my_vote)===1?0:1})">▲ ${Number(t.score)||0}</button><button class="tv2-vote ${Number(t.my_vote)===-1?'active':''}" onclick="voteDetailV2('thread',${t.id},${Number(t.my_vote)===-1?0:-1})">▼</button><span class="tv2-replies">${d.replies?.length||0} replies</span></div><div id="tv2Replies">${(d.replies||[]).map(replyCard).join('')||'<div class="tv2-empty" style="padding:30px 0">No replies yet. Be the first to give an opinion.</div>'}</div><div style="margin-top:14px"><textarea id="tv2ReplyInput" class="tv2-field" placeholder="Share your opinion…"></textarea><button class="tv2-btn primary" onclick="replyThreadV2(${t.id})">Post Reply</button></div></div>`;}
    catch(e){body.innerHTML=`<div class="tv2-empty"><strong>Could not open discussion</strong>${esc(e.message)}</div>`;}
  };
  function replyCard(r){const g=gradient(r.author_gradient),init=authorName(r).charAt(0).toUpperCase();return `<div class="tv2-reply"><div class="tv2-avatar" style="background:linear-gradient(135deg,${g})">${init}</div><div class="tv2-reply-body"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px"><b style="color:var(--text-primary)">${esc(authorName(r))}</b> · ${relTime(r.created_at)}</div><div class="tv2-reply-text">${esc(r.body)}</div><div class="tv2-reply-actions"><button class="tv2-vote ${Number(r.my_vote)===1?'active':''}" onclick="voteDetailV2('reply',${r.id},${Number(r.my_vote)===1?0:1})">▲ ${Number(r.score)||0}</button><button class="tv2-vote ${Number(r.my_vote)===-1?'active':''}" onclick="voteDetailV2('reply',${r.id},${Number(r.my_vote)===-1?0:-1})">▼</button></div></div></div>`;}
  window.voteDetailV2=async function(target,id,vote){try{await request(api,{method:'POST',body:JSON.stringify({action:'vote',target,id,vote})});openThreadDetail(activeThreadId);}catch(e){toast(e.message,'error');}};
  window.replyThreadV2=async function(id){const input=document.getElementById('tv2ReplyInput'),body=input?.value.trim();if(!body)return;try{await request(api,{method:'POST',body:JSON.stringify({action:'reply',thread_id:id,body})});input.value='';openThreadDetail(id);}catch(e){toast(e.message,'error');}};
  window.closeThreadDetail=function(){document.getElementById('threadsV2DetailModal')?.classList.remove('open');activeThreadId=0;};

  function installSwitch(){
    if(initialized)return; initialized=true;
    originalSwitchView=window.switchView;
    window.switchView=function(viewName,el){
      if(viewName==='threads'){
        document.querySelectorAll('.sidebar-nav-item').forEach(n=>n.classList.remove('active'));el?.classList.add('active');window._currentNavView='threads';
        const chatMain=document.querySelector('.chat-main');let overlay=document.getElementById('navViewOverlay');
        if(chatMain)chatMain.style.display='none';
        if(!overlay){overlay=document.createElement('div');overlay.id='navViewOverlay';overlay.style.cssText='flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden;background:var(--bg-primary);';chatMain?.parentNode.insertBefore(overlay,chatMain.nextSibling);}else overlay.style.display='flex';
        renderView(); return;
      }
      if(originalSwitchView)originalSwitchView(viewName,el);
    };
    window.__real_switchView=window.switchView;
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',installSwitch,{once:true});else installSwitch();
  window.addEventListener('load',installSwitch,{once:true});
})();
