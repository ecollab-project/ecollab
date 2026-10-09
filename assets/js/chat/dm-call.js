/** Shared LiveKit calls. Loaded on every authenticated page, with chat's socket reused. */
'use strict';
(() => {
  if (window.EcollabCalls || window.top !== window.self) return;
  const config = window.ECOLLAB_CALLS_CONFIG || window.ECOLLAB || {};
  const base = config.baseUrl || '';
  let call=null, socket=null, authed=false, reconnect=0, timer=null, sdkPromise=null, releaseLock=null;
  const tabId=crypto.randomUUID();
  const bus=typeof BroadcastChannel==='function'?new BroadcastChannel('ecollab-calls-'+config.userId):null;
  const notice=message=>{if(typeof window.showToast==='function')window.showToast(message,'info');else {const el=document.createElement('div');el.className='ec-call';el.setAttribute('role','status');el.textContent=message;el.style.padding='20px';document.body.appendChild(el);setTimeout(()=>el.remove(),7000);}};
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const media=()=>window.EcollabMediaSettings;
  const busy=()=>!!call || !!window.EcollabLiveKit?.room;
  async function claim() {
    if(!navigator.locks) return true;
    return new Promise(resolve=>navigator.locks.request('ecollab-active-call-'+config.userId,{ifAvailable:true},lock=>{
      if(!lock){resolve(false);return;}
      return new Promise(release=>{releaseLock=release;resolve(true);});
    }));
  }
  function send(data) {
    if(typeof window.wsSend==='function') return window.wsSend(data);
    if(socket?.readyState===WebSocket.OPEN&&authed){socket.send(JSON.stringify(data));return true;}
    return false;
  }
  function announce(c){bus?.postMessage({tabId,key:c.groupId?'group-'+c.groupId:'call-'+c.logId});}
  if(bus)bus.onmessage=({data})=>{if(data.tabId!==tabId&&call?.state==='incoming'&&data.key===(call.groupId?'group-'+call.groupId:'call-'+call.logId))cleanup(call);};
  function ring(c) {
    c.ringer=new Audio(base+'/assets/sounds/'+(c.state==='incoming'?'incoming-ring.mp3':'ringback.mp3'));
    c.ringer.loop=true;c.ringer.volume=.5;c.ringer.play().catch(()=>{});
    c.timeout=setTimeout(()=>{if(call===c&&['incoming','outgoing'].includes(c.state))end(true);},45000);
  }
  function stopRing(c){clearTimeout(c.timeout);c.ringer?.pause();c.ringer=null;}
  function cleanup(c) {
    if(!c)return;
    if(call===c)call=null;
    if(c.room) document.getElementById('ecMediaSettings')?.remove();
    stopRing(c); c.closed=true;
    c.room?.disconnect();
    for(const [track,el] of c.audio||[]){track.detach(el);el.remove();}
    for(const [track,el] of c.videos||[]){track.detach(el);el.remove();}
    c.panel?.remove();
    releaseLock?.();releaseLock=null;
  }
  async function sdk() {
    if(window.LivekitClient)return window.LivekitClient;
    if(!sdkPromise)sdkPromise=new Promise((resolve,reject)=>{const s=document.createElement('script');s.nonce=config.scriptNonce||'';s.crossOrigin='anonymous';s.src='https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js';s.onload=()=>resolve(window.LivekitClient);s.onerror=()=>{sdkPromise=null;reject(Error('Call media library could not load.'));};document.head.appendChild(s);});
    return sdkPromise;
  }
  async function token(c) {
    const res=await fetch(base+'/API/dm/'+(c.groupId?'livekit-group-token.php':'livekit-call-token.php'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':config.csrfToken||document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify(c.groupId?{group_id:c.groupId}:{call_id:c.logId})});
    const data=await res.json();if(!res.ok||!data.success)throw Error(data.error||'Call authorization failed.');return data;
  }
  function attachAudio(c,track){if(track.kind!=='audio'||c.audio.has(track))return;const el=track.attach();el.autoplay=true;el.muted=c.deafened;el.hidden=true;document.body.appendChild(el);c.audio.set(track,el);media().playback(c.room);el.play().catch(()=>{c.message='Tap the call window to hear audio.';render(c);});}
  function reconcileAudio(c){for(const p of c.room.remoteParticipants.values())for(const pub of p.trackPublications.values())if(pub.track?.kind==='audio')attachAudio(c,pub.track);}
  async function connect(c) {
    const [LK,auth]=await Promise.all([sdk(),token(c)]);if(call!==c)return;
    if(c.groupId)c.name=auth.room_name||c.name;
    await media().ready;if(call!==c)return;
    const room=new LK.Room({adaptiveStream:true,dynacast:true,audioCaptureDefaults:media().audio(),videoCaptureDefaults:media().video(),disconnectOnPageLeave:true});c.room=room;
    const update=()=>{if(call===c){reconcileAudio(c);render(c);}};
    for(const event of ['ParticipantConnected','ParticipantDisconnected','TrackMuted','TrackUnmuted','LocalTrackPublished','LocalTrackUnpublished','TrackSubscribed'])room.on(LK.RoomEvent[event],update);
    room.on(LK.RoomEvent.ActiveSpeakersChanged,()=>{if(call===c)syncSpeaking(c);});
    room.on(LK.RoomEvent.TrackUnsubscribed,track=>{const el=c.audio.get(track);if(el){track.detach(el);el.remove();c.audio.delete(track);}update();});
    room.on(LK.RoomEvent.Reconnecting,()=>{c.message='Reconnecting…';render(c);});
    room.on(LK.RoomEvent.Reconnected,()=>{c.message='';render(c);});
    room.on(LK.RoomEvent.Disconnected,()=>{if(call===c){notice('Call disconnected.');end();}});
    await room.connect(auth.url,auth.token);if(call!==c){room.disconnect();return;}
    // Camera failure must not destroy a successfully connected audio call.
    try{await room.localParticipant.setMicrophoneEnabled(true,media().audio());}catch(e){c.message='Microphone unavailable. Choose a microphone in Settings.';}
    if(c.video)try{await room.localParticipant.setCameraEnabled(true,media().video());}catch(e){c.message='Camera unavailable. You joined with audio only.';}
    if(call!==c){room.disconnect();return;}
    try{await media().initialize(room);}catch(e){c.message=e.message;}
    c.state='active';stopRing(c);announce(c);reconcileAudio(c);render(c);
    await room.startAudio().catch(()=>{c.message='Tap the call window to hear audio.';render(c);});
  }
  function make(data){return {...data,deafened:false,minimized:false,expanded:false,audio:new Map(),videos:[],message:'',closed:false};}
  async function start(video) {
    if(busy()){notice('Leave your current call before starting another.');return;}
    const dm=typeof DM!=='undefined'?DM:{};
    const groupId=Number(dm.activeGroupId)||null,peerId=Number(dm.activePartnerId)||null;
    if(!groupId&&!peerId)return;
    if(!await claim()){notice('You already have a call open in another tab.');return;}
    const c=make({groupId,peerId,video:!!video,name:groupId?(dm.activeGroupName||'Group call'):(dm.activePartnerName||'Direct call'),state:'outgoing'});call=c;render(c);
    if(groupId){
      try{await connect(c);if(call===c&&!send({type:'dm_group_call_start',group_id:groupId,is_video:!!video})){c.message='Connected, but invitations could not be sent. Reconnect chat to invite members.';render(c);}}catch(e){cleanup(c);notice(e.message);}
    }else{
      if(!send({type:'dm_call_offer',target_user_id:peerId,is_video:!!video,media:'livekit'})){cleanup(c);notice('Chat is reconnecting. Try the call again.');return;}
      ring(c);
    }
  }
  async function accept(audioOnly=false) {
    const c=call;if(!c||c.state!=='incoming'||c.busy)return;
    c.busy=true;render(c);
    if(!await claim()){c.busy=false;c.message='Another tab already has an active call.';render(c);return;}
    announce(c);c.video=c.video&&!audioOnly;c.state='connecting';stopRing(c);render(c);
    try{await connect(c);if(call!==c)return;if(!c.groupId&&!send({type:'dm_call_answer',target_user_id:c.peerId,log_id:c.logId,media:'livekit'}))throw Error('Signaling disconnected. Please call again.');}catch(e){end();notice(e.message);}finally{c.busy=false;if(call===c)render(c);}
  }
  function end(timedOut=false){const c=call;if(!c)return;if(!c.groupId&&c.peerId&&c.logId)send({type:c.state==='incoming'&&!timedOut?'dm_call_decline':'dm_call_end',target_user_id:c.peerId,log_id:c.logId});cleanup(c);}
  async function action(name){const c=call;if(!c||c.busy)return;const p=c.room?.localParticipant;
    if(name==='minimize'){c.minimized=!c.minimized;layout(c);return;}
    if(name==='expand'){c.expanded=!c.expanded;c.minimized=false;layout(c);return;}
    if(name==='end'){end();return;}if(name==='accept'||name==='audio'){accept(name==='audio');return;}
    if(name==='settings'){media().open(c.room);return;}if(!p)return;
    c.busy=true;render(c);
    try{if(name==='mic')await p.setMicrophoneEnabled(!p.isMicrophoneEnabled,media().audio());
      if(name==='camera')await p.setCameraEnabled(!p.isCameraEnabled,media().video());
      if(name==='screen'){const opts=media().screenOptions();await p.setScreenShareEnabled(!p.isScreenShareEnabled,opts.capture,opts.publish);}
      if(name==='deafen'){c.deafened=!c.deafened;for(const el of c.audio.values())el.muted=c.deafened;}
      if(name==='resume'){await c.room.startAudio();for(const el of c.audio.values())await el.play();c.message='';}
    }catch(e){c.message=e.message||'Could not change this setting.';}finally{c.busy=false;if(call===c)render(c);}
  }
  function button(actionName,label,pressed=false,extra=''){return `<button type="button" data-action="${actionName}" ${['mic','camera','screen','deafen'].includes(actionName)?`aria-pressed="${pressed}"`:''} class="${extra}">${label}</button>`;}
  function layout(c) {
    const panel=c.panel;if(!panel)return;
    panel.classList.toggle('is-minimized',c.minimized);
    const compact=c.minimized && c.state==='active' && !c.groupId;
    const dock=document.getElementById('vcConnectedBar');
    const desktop=window.matchMedia('(min-width:1025px) and (pointer:fine)').matches;
    const sidebar=compact && desktop && dock && !c.position;
    panel.classList.toggle('is-compact-direct',compact);
    panel.classList.toggle('is-sidebar-docked',!!sidebar);
    if(sidebar){if(panel.parentNode!==dock.parentNode)dock.before(panel);}
    else if(panel.parentNode!==document.body)document.body.appendChild(panel);
    for(const prop of ['left','top','right','bottom','transform'])panel.style.removeProperty(prop);
    if(c.minimized&&c.position){const box=panel.getBoundingClientRect();c.position.left=Math.max(8,Math.min(innerWidth-box.width-8,c.position.left));c.position.top=Math.max(8,Math.min(innerHeight-box.height-8,c.position.top));Object.assign(panel.style,{left:c.position.left+'px',top:c.position.top+'px',right:'auto',bottom:'auto',transform:'none'});}
    panel.classList.toggle('is-expanded',c.expanded&&!c.minimized&&c.state==='active');
    const mini=panel.querySelector('[data-action=minimize]');
    if(mini){mini.textContent=c.minimized?'Restore':'Minimize';mini.setAttribute('aria-expanded',String(!c.minimized));}
    const expand=panel.querySelector('[data-action=expand]');
    if(expand){expand.textContent=c.expanded?'Dock':'Expand';expand.hidden=c.minimized;expand.setAttribute('aria-expanded',String(c.expanded));}
  }
  function syncSpeaking(c) { render(c); }
  function installDrag(c, panel) {
    let drag=null;
    panel.addEventListener('pointerdown',e=>{
      if(!c.minimized||!e.target.closest('header,.ec-call-compact-status')||e.target.closest('button')||e.button>0)return;
      const box=panel.getBoundingClientRect();drag={id:e.pointerId,x:e.clientX,y:e.clientY,left:box.left,top:box.top};
      if(panel.classList.contains('is-sidebar-docked')){c.position={left:box.left,top:box.top};layout(c);}
      panel.setPointerCapture?.(e.pointerId);e.preventDefault();
    });
    panel.addEventListener('pointermove',e=>{
      if(!drag||drag.id!==e.pointerId)return;
      const box=panel.getBoundingClientRect();
      c.position={left:Math.max(8,Math.min(innerWidth-box.width-8,drag.left+e.clientX-drag.x)),top:Math.max(8,Math.min(innerHeight-box.height-8,drag.top+e.clientY-drag.y))};
      layout(c);
    });
    const stop=()=>{drag=null;};panel.addEventListener('pointerup',stop);panel.addEventListener('pointercancel',stop);
  }
  function render(c){
    if(call!==c)return;
    let panel=c.panel;
    if(!panel){
      panel=document.createElement('section');panel.className='ec-call';panel.setAttribute('role','region');panel.setAttribute('aria-label','Call');document.body.appendChild(panel);c.panel=panel;
      panel.innerHTML='<header><div class="ec-call-heading"></div><div class="ec-call-window-actions"></div></header><div class="ec-call-screen"></div><div class="ec-call-grid"></div><div class="ec-call-actions"></div><div class="ec-call-status" role="status"></div><div class="ec-call-compact"><div class="ec-call-compact-status"><i></i><strong>Call Connected</strong></div><div class="ec-call-compact-name"></div><div class="ec-call-compact-actions"><button type="button" data-action="minimize" aria-label="Restore call" title="Restore call"><svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg></button><button type="button" class="danger" data-action="end" aria-label="End call" title="End call"><svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.62 10.79a15.05 15.05 0 006.59 6.59l2.2-2.2a1 1 0 011.01-.24c1.12.37 2.33.57 3.58.57a1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 01.99.86c.12 1.25.41 2.45.87 3.57a1 1 0 01-.24 1.01l-2.21 2.35z"/></svg></button></div></div>';
      panel.onclick=e=>{const b=e.target.closest('[data-action]');if(b)action(b.dataset.action);else c.room?.startAudio().then(()=>{c.message='';panel.querySelector('.ec-call-status').textContent='';}).catch(()=>{});};
      installDrag(c,panel);c.tiles=new Map();
    }
    const focused=panel.contains(document.activeElement)?document.activeElement.dataset.action:null;
    panel.classList.toggle('is-ringing',['incoming','outgoing'].includes(c.state));
    const p=c.room?.localParticipant,active=c.state==='active',count=c.room?c.room.remoteParticipants.size+1:0;
    const status=active?`${count} connected${c.groupId?' · up to 50':''}`:c.state==='incoming'?`Incoming ${c.video?'video':'voice'} call`:c.state==='outgoing'?'Calling…':'Connecting…';
    panel.querySelector('.ec-call-heading').innerHTML=`<strong>${escape(c.name)}</strong><small>${status}</small>`;
    panel.querySelector('.ec-call-window-actions').innerHTML=(active?button('expand',c.expanded?'Dock':'Expand'):'')+button('minimize',c.minimized?'Restore':'Minimize');
    panel.querySelector('.ec-call-actions').innerHTML=c.state==='incoming'?button('accept','Accept',false,'accept')+(c.video?button('audio','Audio only'):'')+button('end','Decline',false,'danger'):active?button('mic',p.isMicrophoneEnabled?'Mute':'Unmute',!p.isMicrophoneEnabled)+button('camera',p.isCameraEnabled?'Camera off':'Camera on',p.isCameraEnabled)+button('screen',p.isScreenShareEnabled?'Stop sharing':'Share screen',p.isScreenShareEnabled)+button('deafen',c.deafened?'Hear audio':'Deafen',c.deafened)+button('settings','Settings')+button('end','Leave',false,'danger'):button('end','Cancel',false,'danger');
    panel.querySelector('.ec-call-status').textContent=c.message||'';
    panel.querySelector('.ec-call-compact-name').textContent=c.name+(p&&!p.isMicrophoneEnabled?' · Muted':'');
    panel.querySelectorAll('button').forEach(b=>{if(c.busy&&!['end','minimize','expand'].includes(b.dataset.action))b.disabled=true;});
    if(active){
      const participants=[p,...c.room.remoteParticipants.values()],speakers=c.room.activeSpeakers||[];
      const sorted=participants.filter(x=>x!==p).sort((a,b)=>(speakers.indexOf(a)<0?999:speakers.indexOf(a))-(speakers.indexOf(b)<0?999:speakers.indexOf(b)));
      const visible=[p,...sorted.slice(0,5)],keys=new Set(visible.map(x=>x.identity));
      for(const [key,tile] of c.tiles){if(!keys.has(key)){if(tile.track)tile.track.detach(tile.video);tile.remove();c.tiles.delete(key);}}
      const grid=panel.querySelector('.ec-call-grid');
      visible.forEach((participant,index)=>{
        let tile=c.tiles.get(participant.identity);
        if(!tile){tile=document.createElement('div');tile.className='ec-call-tile';tile.innerHTML='<em></em><span></span>';c.tiles.set(participant.identity,tile);}
        tile.dataset.local=String(participant===p);tile.classList.toggle('is-speaking',participant.isSpeaking&&participant.isMicrophoneEnabled);
        const name=participant===p?'You':participant.name||'Participant';tile.querySelector('em').textContent=name.charAt(0).toUpperCase();tile.querySelector('span').textContent=name+' · '+(participant.isMicrophoneEnabled?'Mic on':'Muted');
        const pub=participant.getTrackPublication('camera'),track=pub?.track&&!pub.isMuted?pub.track:null;
        if(tile.track!==track){if(tile.track)tile.track.detach(tile.video);tile.video?.remove();tile.track=track;tile.video=null;if(track){const el=document.createElement('video');el.autoplay=true;el.playsInline=true;el.muted=true;track.attach(el);tile.prepend(el);tile.video=el;el.play().catch(()=>{});}}
        tile.classList.toggle('has-video',!!track);
        if(grid.children[index]!==tile)grid.insertBefore(tile,grid.children[index]||null);
      });
      const share=participants.map(x=>x.getTrackPublication('screen_share')).find(pub=>pub?.track&&!pub.isMuted)?.track||null;
      if(c.screenTrack!==share){if(c.screenTrack)c.screenTrack.detach(c.screenVideo);c.screenVideo?.remove();c.screenTrack=share;c.screenVideo=null;if(share){const el=document.createElement('video');el.autoplay=true;el.playsInline=true;el.muted=true;share.attach(el);panel.querySelector('.ec-call-screen').appendChild(el);c.screenVideo=el;el.play().catch(()=>{});}}
      c.videos=[...c.tiles.values()].filter(t=>t.track).map(t=>[t.track,t.video]);if(c.screenTrack)c.videos.push([c.screenTrack,c.screenVideo]);
      if(count>6)panel.querySelector('.ec-call-status').append(' Showing you and five participants, prioritizing active speakers.');
      if(!navigator.mediaDevices?.getDisplayMedia)panel.querySelector('[data-action=screen]').disabled=true;
    }
    layout(c);if(focused)panel.querySelector(`[data-action="${focused}"]`)?.focus({preventScroll:true});
  }
  async function handle(data){
    if(!/^dm_(?:call_|group_call_)/.test(data.type||''))return false;
    if(data.type==='dm_call_offer'||data.type==='dm_group_call_start'){
      if(call && ((data.type==='dm_call_offer' && call.logId===Number(data.log_id)) || (data.type==='dm_group_call_start' && call.groupId===Number(data.group_id)))) return true;
      if(busy()){if(!data.group_id)send({type:'dm_call_decline',target_user_id:data.from_user_id,log_id:data.log_id});else send({type:'dm_group_call_busy',group_id:data.group_id,target_user_id:data.from_user_id});return true;}
      if(data.type==='dm_call_offer'&&data.media!=='livekit'){send({type:'dm_call_decline',target_user_id:data.from_user_id,log_id:data.log_id});notice('The caller needs to refresh eCollab before calling.');return true;}
      call=make({state:'incoming',peerId:Number(data.from_user_id),groupId:data.type==='dm_group_call_start'?Number(data.group_id):null,logId:Number(data.log_id)||null,name:data.from_username||'Incoming call',video:!!data.is_video});ring(call);render(call);return true;
    }
    const c=call;
    if(data.type==='dm_call_offer_sent'){if(c&&!c.groupId&&c.state==='outgoing'){c.logId=Number(data.log_id);announce(c);}else if(data.log_id&&data.target_user_id)send({type:'dm_call_end',log_id:data.log_id,target_user_id:data.target_user_id});return true;}
    if(data.type==='dm_group_call_busy'){if(c?.groupId===Number(data.group_id)){c.message=(data.from_username||'A member')+' is busy.';render(c);}return true;}
    if(!c||c.groupId||Number(data.log_id)!==c.logId||Number(data.from_user_id)!==c.peerId)return true;
    if(data.type==='dm_call_answer'&&c.state==='outgoing'){stopRing(c);c.state='connecting';render(c);try{await connect(c);}catch(e){end();notice(e.message);}}
    if(['dm_call_end','dm_call_decline'].includes(data.type)){cleanup(c);notice(data.type==='dm_call_decline'?'Call declined.':'Call ended.');}
    return true;
  }
  // On chat/whiteboard pages socket-core calls handle; other pages use this minimal authenticated socket.
  async function connectSignals(){
    if(document.querySelector('script[src*="/chat/socket.js"],script[src*="/chat/socket-core.js"]'))return;
    if(!config.wsUrl||!config.userId)return;
    try{const res=await fetch(base+'/API/auth/ws-token.php',{credentials:'same-origin'});if(res.status===401)return;if(!res.ok)throw Error('Signaling unavailable');const data=await res.json();socket=new WebSocket(config.wsUrl);socket.onopen=()=>socket.send(JSON.stringify({type:'auth',ws_token:data.token}));socket.onmessage=e=>{try{const d=JSON.parse(e.data);if(d.type==='auth_ok'){authed=true;reconnect=0;}else handle(d).catch(console.error);}catch(_){}};socket.onclose=()=>{authed=false;clearTimeout(timer);timer=setTimeout(connectSignals,Math.min(30000,2000*2**reconnect++));};socket.onerror=()=>{};}catch(_){timer=setTimeout(connectSignals,10000);}
  }
  window.addEventListener('ecollab-media-settings-changed',()=>{if(call?.room)media().playback(call.room);});
  window.EcollabCalls={handle,busy:()=>!!call,get room(){return call?.room;}};
  window.startDmCall=start;window.endDmCall=end;
  for(const data of window.__ecollabPendingCalls || []) handle(data).catch(console.error);
  delete window.__ecollabPendingCalls;
  window.startDmGroupLiveKitCall=async(groupId,video)=>{if(busy())return notice('Leave your current call first.');if(!await claim())return notice('A call is active in another tab.');const c=make({groupId:Number(groupId),video:!!video,name:'Group call',state:'connecting'});call=c;render(c);try{await connect(c);if(call===c)send({type:'dm_group_call_start',group_id:c.groupId,is_video:c.video});}catch(e){cleanup(c);notice(e.message);}};
  window.addEventListener('pagehide',()=>{if(call)end();clearTimeout(timer);if(socket){socket.onclose=null;socket.close();}});
  window.addEventListener('pageshow',e=>{if(e.persisted)connectSignals();});
  window.addEventListener('resize',()=>{if(call?.panel)layout(call);});
  // Defer until all page scripts have run, avoiding a second chat connection.
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',connectSignals,{once:true});else connectSignals();
})();



