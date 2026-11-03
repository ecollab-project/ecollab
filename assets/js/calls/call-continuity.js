/* Keep media in its original document; browse the authenticated workspace separately. */
'use strict';
(() => {
  if (window.top !== window.self || window.EcollabCallContinuity) return;
  const config=window.ECOLLAB_CALLS_CONFIG||{},uid=Number(config.userId);
  if (!uid) return;
  const root=new URL(config.baseUrl||'/',location.origin).pathname.replace(/\/$/,''),tab=crypto.randomUUID();
  const stateKey='ecollab-call-state-'+uid,commandKey='ecollab-call-command-'+uid;
  const bus=typeof BroadcastChannel==='function'?new BroadcastChannel('ecollab-call-continuity-'+uid):null;
  let remote=null,localWasActive=false,sequence=0,panel,workspace=null,note='',noteUntil=0;
  const handled=new Set(),requests=new Map();
  const put=(key,value)=>{try{localStorage.setItem(key,JSON.stringify(value));}catch{}};
  const read=key=>{try{return JSON.parse(localStorage.getItem(key)||'null');}catch{return null;}};
  function snapshot(){
    const dm=window.EcollabCalls?.snapshot;
    if(dm?.active)return {active:true,state:dm.state,kind:dm.kind||'direct',muted:!!dm.muted,deafened:!!dm.deafened};
    if(window.EcollabCalls?.room)return {active:true,state:'active',kind:'direct',muted:!window.EcollabCalls.room.localParticipant?.isMicrophoneEnabled,deafened:!!window.EcollabCalls.deafened};
    const room=window.EcollabLiveKit?.room;
    if(room)return {active:true,state:room.state==='connected'?'active':'connecting',kind:'voice',muted:!room.localParticipant?.isMicrophoneEnabled,deafened:typeof vcDeafened!=='undefined'&&!!vcDeafened};
    if(typeof vcActive!=='undefined'&&vcActive)return {active:true,state:'active',kind:'voice',muted:typeof vcMicMuted!=='undefined'&&!!vcMicMuted,deafened:typeof vcDeafened!=='undefined'&&!!vcDeafened};
    return {active:false};
  }
  function validState(s){return s&&s.type==='state'&&s.userId===uid&&typeof s.tab==='string'&&s.tab!==tab&&typeof s.at==='number'&&Date.now()-s.at<180000&&s.at<Date.now()+5000&&typeof s.active==='boolean';}
  function remoteState(){
    const stored=read(stateKey);
    if(validState(stored)&&(!remote||stored.tab!==remote.tab||stored.sequence>remote.sequence))remote=stored;
    if(remote&&Date.now()-remote.at>=180000)remote=null;
    return !snapshot().active&&remote?.active?remote:null;
  }
  function publish(s){
    const packet={type:'state',userId:uid,tab,at:Date.now(),sequence:++sequence,...s};
    if(s.active||localWasActive)put(stateKey,packet);
    bus?.postMessage(packet);localWasActive=s.active;
  }
  function say(text){note=text;noteUntil=Date.now()+6000;render();}
  function eligible(value){
    let url;try{url=new URL(value,location.href);}catch{return null;}
    if(url.origin!==location.origin||url.username||url.password||!/^https?:$/.test(url.protocol))return null;
    const path=url.pathname.slice(root.length);
    if(!url.pathname.startsWith(root+'/')||!(/\/modules\//.test(path)||path==='/'||path==='/index.php'))return null;
    if(/^\/(?:API|security)\//.test(path)||/^\/modules\/auth\//.test(path))return null;
    if(url.pathname===location.pathname&&url.search===location.search&&url.hash)return null;
    return url;
  }
  function openWorkspace(value){
    const url=eligible(value);if(!url)return false;
    try{
      // Reuse only a same-origin workspace. Never take over an external resource tab.
      if(workspace&&!workspace.closed&&workspace.location.origin===location.origin){workspace.location.href=url.href;workspace.focus();return true;}
    }catch{workspace=null;}
    const child=window.open(url.href,'_blank');
    if(!child){say('Allow the workspace tab to open, then try again. Your call is still connected.');return false;}
    child.opener=null;workspace=child;child.focus();return true;
  }
  function navigate(value){
    let url;try{url=new URL(value,location.href);}catch{return false;}
    if(!/^https?:$/.test(url.protocol))return false;
    if(snapshot().active&&eligible(url))return openWorkspace(url);
    location.assign(url.href);return true;
  }
  async function control(action){
    const s=snapshot();if(!s.active)return;
    if(action==='focus'){window.focus();return;}
    if(!['end','mic','deafen'].includes(action)||s.state!=='active'&&action!=='end')return;
    if(s.kind==='voice'){
      if(action==='end')await window.disconnectVoice?.();
      else if(action==='mic')await window.toggleVcMic?.();
      else window.toggleVcDeafen?.();
    }else await window.EcollabCalls?.control(action);
    refresh();
  }
  function sendCommand(action){
    const owner=remoteState();if(!owner)return;
    const packet={type:'command',userId:uid,tab,target:owner.tab,id:crypto.randomUUID(),at:Date.now(),action};
    put(commandKey,packet);bus?.postMessage(packet);
    requests.set(packet.id,setTimeout(()=>{requests.delete(packet.id);say('The call tab has not responded. Switch to your Call tab to check it.');},4000));
  }
  async function receive(data){
    if(!data||data.userId!==uid||data.tab===tab)return;
    if(data.type==='probe'){if(snapshot().active)refresh();return;}
    if(data.type==='state'){
      if(!validState(data)||remote?.tab===data.tab&&data.sequence<=remote.sequence)return;
      remote=data;render();return;
    }
    if(data.type==='result'&&data.target===tab&&requests.has(data.id)){
      clearTimeout(requests.get(data.id));requests.delete(data.id);if(!data.ok)say('Use the Call tab to change this control.');return;
    }
    if(data.type!=='command'||data.target!==tab||typeof data.id!=='string'||handled.has(data.id)||!['end','mic','deafen','focus'].includes(data.action)||Math.abs(Date.now()-Number(data.at))>10000)return;
    handled.add(data.id);if(handled.size>100)handled.delete(handled.values().next().value);
    let ok=true;try{await control(data.action);}catch{ok=false;}
    const result={type:'result',userId:uid,tab,target:data.tab,id:data.id,ok};
    put(commandKey,result);bus?.postMessage(result);
  }
  function ensurePanel(){
    if(panel)return;
    panel=document.createElement('aside');panel.id='ecCallContinuityPanel';panel.className='ec-call-continuity';panel.hidden=true;panel.setAttribute('aria-label','Ongoing call');
    const status=document.createElement('p');status.dataset.continuityStatus='';status.setAttribute('role','status');panel.append(status);
    const row=document.createElement('div');row.className='ec-call-continuity-actions';
    for(const [action,label] of [['workspace','Open workspace'],['focus','Show call'],['mic','Mute'],['deafen','Mute audio'],['end','Leave call']]){
      const button=document.createElement('button');button.type='button';button.dataset.continuity=action;button.textContent=label;
      button.addEventListener('click',()=>{
        if(action==='workspace')openWorkspace((config.baseUrl||'')+'/modules/chat/chat.php');
        else if(snapshot().active)control(action).catch(()=>say('The call control could not be updated.'));
        else{sendCommand(action);if(action==='focus')say('Switch to your eCollab Call tab if it is not brought forward.');}
      });row.append(button);
    }
    panel.append(row);document.body.append(panel);
  }
  function render(){
    if(!document.body)return;ensurePanel();const local=snapshot(),other=remoteState(),current=local.active?local:other;
    panel.hidden=!current;if(!current)return;
    const status=panel.querySelector('[data-continuity-status]');
    const text=noteUntil>Date.now()?note:local.active?'Your call stays connected here. Open a workspace tab to keep browsing.':'Your call is connected in another eCollab tab.';
    if(status.textContent!==text)status.textContent=text;
    for(const button of panel.querySelectorAll('button')){
      const action=button.dataset.continuity;button.hidden=action==='workspace'?!local.active:action==='focus'?local.active:local.active&&action!=='end';
      button.disabled=['mic','deafen'].includes(action)&&current.state!=='active';
      if(action==='mic')button.textContent=current.muted?'Unmute':'Mute';
      if(action==='deafen')button.textContent=current.deafened?'Unmute audio':'Mute audio';
    }
  }
  function refresh(){
    const current=snapshot();
    if(current.active&&!document.title.startsWith('Call • '))document.title='Call • '+document.title;
    if(!current.active&&document.title.startsWith('Call • '))document.title=document.title.slice(7);
    if(current.active||localWasActive)publish(current);render();
  }
  async function endAll(){
    if(snapshot().active)await control('end');else if(remoteState())sendCommand('end');
  }
  if(bus)bus.onmessage=event=>receive(event.data);
  window.addEventListener('storage',event=>{if(event.key===stateKey||event.key===commandKey){try{receive(JSON.parse(event.newValue||'null'));}catch{}}});
  // Bubble after page-specific handlers so AJAX forms and in-page tools retain their behavior.
  window.addEventListener('click',event=>{
    if(event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    const link=event.target.closest?.('a[href]');if(!link||link.hasAttribute('download')||link.target&&!['_self','_top'].includes(link.target))return;
    if(new URL(link.href).origin===location.origin&&new URL(link.href).pathname===root+'/modules/auth/logout.php'){endAll().catch(()=>{});return;}
    if(!snapshot().active)return;
    const url=eligible(link.href);if(!url)return;event.preventDefault();openWorkspace(url);
  });
  window.addEventListener('submit',event=>{
    if(event.defaultPrevented||!snapshot().active)return;const form=event.target;
    if(form.target&&!['_self','_top'].includes(form.target)||!eligible(form.action))return;
    const name='ecollab-workspace-'+crypto.randomUUID(),child=window.open('',name);
    if(!child){event.preventDefault();say('Allow the workspace tab to open, then submit again.');return;}
    child.opener=null;workspace=child;form.target=name;
  });
  window.navigation?.addEventListener('navigate',event=>{
    const destination=new URL(event.destination.url);
    if(destination.origin===location.origin&&destination.pathname===root+'/modules/auth/logout.php'){endAll().catch(()=>{});return;}
    if(event.defaultPrevented||!event.cancelable||!event.canIntercept||event.navigationType==='reload'||event.hashChange||event.formData||event.downloadRequest||!snapshot().active)return;
    const url=eligible(event.destination.url);if(!url)return;event.preventDefault();openWorkspace(url);
  });
  window.addEventListener('beforeunload',event=>{if(snapshot().active){event.preventDefault();event.returnValue='';}});
  window.addEventListener('pagehide',()=>{if(localWasActive)publish({active:false});});
  // Logout is an explicit request to end media, including a call owned by another tab.
  const nativeFetch=window.fetch;
  if(nativeFetch)window.fetch=function(input,options){
    try{const url=new URL(input?.url||String(input),location.href);if(url.origin===location.origin&&url.pathname===root+'/API/auth/logout.php'&&String(options?.method||input?.method||'GET').toUpperCase()==='POST')endAll().catch(()=>{});}catch{}
    return nativeFetch.call(this,input,options);
  };
  window.EcollabCallContinuity={refresh,navigate,remoteActive:()=>!!remoteState(),endAll};
  bus?.postMessage({type:'probe',userId:uid,tab});refresh();setInterval(refresh,5000);
})();
