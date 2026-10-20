/* Server discovery + explicit visibility controls. */
(function(){
  'use strict';
  let selectedServerTemplate='custom';
  let serverVisibility='public';
  let channelVisibility='public';
  window._privateChannelSelectedUsers = new Set();

  const base=()=>window.ECOLLAB?.baseUrl||'';
  const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const csrf=()=>window.ECOLLAB?.csrfToken||document.querySelector('meta[name="csrf-token"]')?.content||'';
  async function req(path,action,body){
    const res=await fetch(base()+path+'?action='+encodeURIComponent(action),{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf()},
      body:JSON.stringify(body||{})
    });
    const d=await res.json().catch(()=>({}));
    if(!res.ok||d.success===false)throw new Error(d.error||'Request failed');
    return d;
  }
  async function get(path,params={}){
    const qs=new URLSearchParams(params); const res=await fetch(base()+path+'?'+qs,{credentials:'same-origin',cache:'no-store'});
    const d=await res.json().catch(()=>({})); if(!res.ok||d.success===false)throw new Error(d.error||'Request failed'); return d;
  }

  window.selectServerTemplate=function(template){
    selectedServerTemplate=template||'custom';
    document.getElementById('addServerChoices')?.style.setProperty('display','none');
    document.getElementById('addServerForm')?.style.setProperty('display','block');
    const emoji={ 'study-group':'📚', research:'🔬', gaming:'🎮', custom:'⚙️' }[selectedServerTemplate]||'⚙️';
    const e=document.getElementById('serverFormEmoji'); if(e)e.textContent=emoji;
    window.selectServerVisibility('public');
  };
  window.backToServerChoices=function(){
    document.getElementById('addServerForm')?.style.setProperty('display','none');
    document.getElementById('addServerChoices')?.style.setProperty('display','block');
  };
  window.selectServerVisibility=function(v){
    serverVisibility=v==='private'?'private':'public';
    document.querySelectorAll('[data-server-visibility]').forEach(b=>{
      const on=b.dataset.serverVisibility===serverVisibility;
      b.style.borderColor=on?'rgba(168,85,247,.5)':'var(--border)';
      b.style.background=on?'rgba(168,85,247,.1)':'var(--bg-tertiary)';
    });
    const h=document.getElementById('serverVisibilityHelp');
    if(h)h.textContent=serverVisibility==='public'
      ?'Public servers appear in recommendations and can be joined directly.'
      :'Private servers are not listed publicly; users need an invite link or must be added by server management.';
  };
  window.createServer=async function(){
    const name=document.getElementById('newServerName')?.value?.trim();
    const desc=document.getElementById('newServerDesc')?.value?.trim()||'';
    if(!name){showToast('Server name is required','info');return;}
    try{
      const d=await req('/API/server/create-server.php','create',{name,description:desc,template:selectedServerTemplate,type:serverVisibility,visibility:serverVisibility});
      showToast('✅ Server created','success');
      closeModal('addServerModal');
      setTimeout(()=>window.location.href=base()+'/modules/chat/chat.php?server_id='+encodeURIComponent(d.server_id),250);
    }catch(e){showToast(e.message||'Failed to create server','error');}
  };

  window.loadPublicServerRecommendations=async function(){
    const el=document.getElementById('publicServerRecommendations'); if(!el)return;
    el.innerHTML='<div style="text-align:center;color:var(--text-muted);font-size:11px;padding:12px;">Loading…</div>';
    try{
      const d=await get('/API/server/public.php',{action:'list'});
      if(!d.servers?.length){el.innerHTML='<div style="text-align:center;color:var(--text-muted);font-size:11px;padding:12px;">No public servers available yet.</div>';return;}
      el.innerHTML=d.servers.map(s=>`<div style="display:flex;align-items:center;gap:9px;padding:8px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">
        <div style="font-size:20px;">${esc(s.icon_emoji||'🌐')}</div>
        <div style="flex:1;min-width:0;"><div style="font-size:12px;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(s.name)}</div><div style="font-size:10px;color:var(--text-muted);">${Number(s.member_count||0)} members · ${esc(s.category||'Community')}</div></div>
        <button onclick="joinPublicServer(${Number(s.id)})" style="padding:5px 9px;border:1px solid rgba(34,197,94,.3);background:rgba(34,197,94,.08);color:#4ade80;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;">Join</button>
      </div>`).join('');
    }catch(e){el.innerHTML='<div style="text-align:center;color:#f87171;font-size:11px;padding:12px;">Could not load public servers.</div>';}
  };
  window.joinPublicServer=async function(serverId){
    try{const d=await req('/API/server/public.php','join',{server_id:Number(serverId)});showToast('✅ Joined '+(d.name||'server'),'success');closeModal('addServerModal');setTimeout(()=>location.reload(),250);}
    catch(e){showToast(e.message||'Could not join server','error');}
  };

  window.selectChannelVisibility=function(v){
    channelVisibility=v==='private'?'private':'public';
    document.querySelectorAll('[data-channel-visibility]').forEach(b=>{
      const on=b.dataset.channelVisibility===channelVisibility;
      b.style.borderColor=on?'rgba(168,85,247,.5)':'var(--border)';
      b.style.background=on?'rgba(168,85,247,.1)':'var(--bg-tertiary)';
    });
    const h=document.getElementById('channelVisibilityHelp');
    if(h)h.textContent=channelVisibility==='public'
      ?'Public channels are visible to every member of this server.'
      :'Private channels are visible only to selected server members, the channel owner, and authorized server managers.';
    const sec=document.getElementById('privateMembersSection');
    if(sec){sec.style.display=channelVisibility==='private'?'block':'none';if(channelVisibility==='private')loadPrivateMembers();}
  };
  window.togglePrivateMembersSection=function(){window.selectChannelVisibility(channelVisibility==='private'?'public':'private');};

  async function loadPrivateMembers(){
    const list=document.getElementById('privateMembersList'), loading=document.getElementById('privateMembersLoading'); if(!list)return;
    if(loading)loading.style.display='block'; list.innerHTML=''; window._privateChannelSelectedUsers=new Set();
    try{
      const sid=Number(window.ECOLLAB?.currentServerId||window.currentServerId||0);
      const d=await get('/API/server/members.php',{action:'list',server_id:sid});
      const members=(d.members||[]).filter(m=>Number(m.id||m.user_id)!==Number(window.ECOLLAB?.userId||0));
      if(loading)loading.style.display='none';
      list.innerHTML=members.map(m=>{
        const id=Number(m.id||m.user_id), name=esc(m.full_name||m.username||'User');
        return `<label style="display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:7px;background:var(--bg-tertiary);cursor:pointer;"><input type="checkbox" data-private-member="${id}" onchange="togglePrivateChannelMember(${id},this.checked)"> <span style="font-size:12px;color:var(--text-primary);">${name}</span><span style="font-size:10px;color:var(--text-muted);margin-left:auto;">${esc(m.server_role||'member')}</span></label>`;
      }).join('')||'<div style="font-size:11px;color:var(--text-muted);text-align:center;padding:10px;">No other server members.</div>';
    }catch(e){if(loading)loading.textContent='Could not load server members.';}
  }
  window.togglePrivateChannelMember=function(id,on){if(on)window._privateChannelSelectedUsers.add(Number(id));else window._privateChannelSelectedUsers.delete(Number(id));};

  const oldCreate=window.createChannel;
  window.createChannel=async function(){
    const name=document.getElementById('newChannelName')?.value?.trim(), desc=document.getElementById('newChannelDesc')?.value?.trim()||'';
    const type=document.querySelector('.channel-type-opt.active')?.dataset?.type||'text';
    if(!name){showToast('Channel name is required','info');return;}
    try{
      const d=await req('/API/chat/create-channel.php','create',{server_id:Number(window.ECOLLAB?.currentServerId||window.currentServerId||0),name,description:desc,type,is_private:channelVisibility==='private'?1:0});
      const id=Number(d.channel?.id||0);
      if(channelVisibility==='private'&&id){
        for(const uid of window._privateChannelSelectedUsers){
          await req('/API/chat/channel-members.php','', {action:'add',channel_id:id,user_id:Number(uid)});
        }
      }
      window._privateChannelSelectedUsers=new Set();
      closeModal('addChannelModal'); showToast('✅ Channel #'+name+' created','success');
      if(typeof loadServerChannels==='function')loadServerChannels(Number(window.ECOLLAB?.currentServerId||window.currentServerId||0));else location.reload();
    }catch(e){showToast(e.message||'Failed to create channel','error');}
  };

  const oldOpen=window.openAddChannelModal;
  window.openAddChannelModal=function(defaultType='text'){
    const type=['text','voice','whiteboard'].includes(defaultType)?defaultType:'text';
    if(typeof oldOpen==='function')oldOpen(type);

    // Each sidebar section has its own + button, so the creation modal should
    // be locked to that channel type instead of asking the user to choose again.
    const modal=document.getElementById('addChannelModal');
    if(modal){
      modal.querySelectorAll('.channel-type-opt').forEach(opt=>{
        opt.style.display=opt.dataset.type===type?'block':'none';
        opt.style.pointerEvents='none';
      });
      const labels={text:'Create Text Channel',voice:'Create Voice Channel',whiteboard:'Create Whiteboard Channel'};
      const title=modal.querySelector('.modal-header span');
      if(title)title.textContent=labels[type]||'Create Channel';
      const typeLabel=modal.querySelector('.modal-body label');
      if(typeLabel)typeLabel.textContent='Channel Type';
    }
    window.selectChannelVisibility('public');
  };

  document.addEventListener('DOMContentLoaded',()=>{
    window.selectServerVisibility('public');
    window.selectChannelVisibility('public');
    setTimeout(()=>window.loadPublicServerRecommendations(),150);
  });
})();