'use strict';
(() => {
  if (window.EcollabMediaSettings) return;
  const defaults = {microphone:'',camera:'',speaker:'',noise:true,echo:true,gain:true,quality:'1080p',cameraQuality:'720p',cameraFps:30,volume:100,fit:'contain',mirror:true};
  let saved={};
  try { saved=JSON.parse(localStorage.getItem('ec_media_settings')||'{}'); } catch (_) {}
  function normalize(value) {
    const p={...defaults,...value};
    for(const key of ['microphone','camera','speaker']) p[key]=typeof p[key]==='string'?p[key]:'';
    for(const key of ['noise','echo','gain','mirror']) p[key]=!!p[key];
    p.quality=['720p','1080p','source'].includes(p.quality)?p.quality:'1080p';
    p.cameraQuality=['360p','720p','1080p'].includes(p.cameraQuality)?p.cameraQuality:'720p';
    p.cameraFps=Number(p.cameraFps)===15?15:30;
    p.volume=Number.isFinite(Number(p.volume))?Math.max(0,Math.min(100,Number(p.volume))):100;
    p.fit=p.fit==='cover'?'cover':'contain';
    return p;
  }
  let prefs=normalize(saved), applying=false;
  const accountKeys={input_device:'microphone',output_device:'speaker',noise_suppression:'noise',echo_cancellation:'echo',auto_gain_control:'gain',output_volume:'volume'};
  function fromAccount(settings){const value={};for(const [key,local] of Object.entries(accountKeys)){if(settings[key]!==undefined)value[local]=settings[key];}prefs=normalize({...prefs,...value});decorate();}
  window.addEventListener('ecollab:settings-applied',e=>fromAccount(e.detail||{}));
  const base=window.ECOLLAB_CALLS_CONFIG?.baseUrl||window.ECOLLAB_BASE||'';
  const ready=(typeof fetch==='function'?fetch(base+'/API/profile/settings.php',{credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(d=>{if(d?.settings)fromAccount(d.settings);}):Promise.resolve()).catch(()=>{});
  if(window._userSettings)fromAccount(window._userSettings);
  const audio=(p=prefs)=>({echoCancellation:p.echo,noiseSuppression:p.noise,autoGainControl:p.gain,...(p.microphone?{deviceId:p.microphone}:{})});
  const video=(p=prefs)=>({resolution:{width:p.cameraQuality==='360p'?640:p.cameraQuality==='1080p'?1920:1280,height:p.cameraQuality==='360p'?360:p.cameraQuality==='1080p'?1080:720,frameRate:p.cameraFps},...(p.camera?{deviceId:p.camera}:{})});
  function screenOptions(quality=prefs.quality) {
    const resolution=quality==='720p'?{width:1280,height:720,frameRate:30}:quality==='1080p'?{width:1920,height:1080,frameRate:30}:undefined;
    return {capture:{audio:true,contentHint:'detail',...(resolution?{resolution}:{video:{frameRate:30}})},publish:{screenShareEncoding:{maxBitrate:quality==='720p'?3000000:quality==='1080p'?6000000:10000000,maxFramerate:30},degradationPreference:'maintain-resolution'}};
  }
  function decorate() {
    document.documentElement.dataset.callFit=prefs.fit;
    document.documentElement.dataset.callMirror=String(prefs.mirror);
  }
  function playback(room) {
    room?.remoteParticipants?.forEach(p=>p.trackPublications.forEach(pub=>{
      if(pub.track?.kind==='audio') pub.track.setVolume?.(prefs.volume/100);
    }));
  }
  async function initialize(room) {
    await ready; decorate(); playback(room);
    if(prefs.speaker && 'setSinkId' in HTMLMediaElement.prototype) {
      const success=await room.switchActiveDevice('audiooutput',prefs.speaker);
      if(success===false)throw Error('Saved speaker is unavailable. Select another speaker in Settings.');
    }
  }
  async function restartMuted(track,options) {
    const wasMuted=track.isMuted;
    try { await track.restartTrack(options); }
    finally { if(wasMuted&&!track.isMuted)await track.mute(); }
  }
  async function apply(room,value) {
    if(!room)throw Error('Join a call first.');
    if(applying)throw Error('Wait for the current settings change.');
    applying=true;
    const next=normalize({...prefs,...value}),old=prefs,undo=[];
    try {
      for(const [key,kind] of [['microphone','audioinput'],['camera','videoinput'],['speaker','audiooutput']]){
        if(next[key]===old[key])continue;
        const result=await room.switchActiveDevice(kind,next[key]||'default');
        if(result===false)throw Error('The selected '+key+' is unavailable.');
        undo.push(()=>room.switchActiveDevice(kind,old[key]||'default'));
      }
      const mic=room.localParticipant.getTrackPublication('microphone')?.track;
      const camera=room.localParticipant.getTrackPublication('camera')?.track;
      // Appearance/volume changes never reacquire devices or alter mute state.
      if(mic&&['noise','echo','gain'].some(key=>next[key]!==old[key])){
        undo.push(()=>restartMuted(mic,audio(old)));
        await restartMuted(mic,audio(next));
      }
      if(camera&&room.localParticipant.isCameraEnabled&&['cameraQuality','cameraFps'].some(key=>next[key]!==old[key])){
        undo.push(()=>restartMuted(camera,video(old)));
        await restartMuted(camera,video(next));
      }
      const account={};for(const [key,local] of Object.entries(accountKeys)){if(next[local]!==old[local])account[key]=next[local];}
      if(Object.keys(account).length){
        const csrf=window.ECOLLAB_CALLS_CONFIG?.csrfToken||document.querySelector('meta[name="csrf-token"]')?.content||'';
        const response=await fetch(base+'/API/profile/settings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(account)});
        const data=await response.json().catch(()=>({}));if(!response.ok)throw Error(data.error||'Unable to save account audio settings.');
        window._userSettings={...window._userSettings,...account};
      }
      prefs=next;decorate();playback(room);
      try{localStorage.setItem('ec_media_settings',JSON.stringify(prefs));}catch(_){}
      window.dispatchEvent(new CustomEvent('ecollab-media-settings-changed'));
    }catch(error){
      let rollbackFailed=false;
      for(const fn of undo.reverse()){try{if(await fn()===false)rollbackFailed=true;}catch(_){rollbackFailed=true;}}
      if(rollbackFailed)error.message+=' Some devices could not be restored; check your active microphone and camera.';
      throw error;
    }finally{applying=false;}
  }
  async function open(room) {
    if(!room)return;
    const existing=document.getElementById('ecMediaSettings');
    if(existing){existing.querySelector('button')?.focus();return;}
    const parentFocus=document.activeElement;
    const panel=document.createElement('div');panel.id='ecMediaSettings';panel.className='ec-media-settings';
    panel.innerHTML=`<form role="dialog" aria-modal="true" aria-labelledby="ecMediaTitle">
      <header><div><h2 id="ecMediaTitle">Call settings</h2><p>Voice channels, direct and group calls</p></div><button type="button" data-close aria-label="Close settings">✕</button></header>
      <fieldset><legend>Audio</legend>
        <label>Microphone<select name="microphone"></select></label>
        <label>Speaker<select name="speaker"></select></label>
        <label>Playback volume <output id="ecCallVolume">100%</output><input name="volume" type="range" min="0" max="100" step="5"></label>
        <label class="ec-setting-check"><input name="noise" type="checkbox"> Noise suppression</label>
        <label class="ec-setting-check"><input name="echo" type="checkbox"> Echo cancellation</label>
        <label class="ec-setting-check"><input name="gain" type="checkbox"> Automatic microphone gain</label>
      </fieldset>
      <fieldset><legend>Camera</legend>
        <label>Camera<select name="camera"></select></label>
        <div class="ec-setting-columns"><label>Quality<select name="cameraQuality"><option value="360p">360p · low bandwidth</option><option value="720p">720p</option><option value="1080p">1080p</option></select></label>
        <label>Frame rate<select name="cameraFps"><option value="15">15 fps</option><option value="30">30 fps</option></select></label></div>
        <label>Camera display<select name="fit"><option value="contain">Fit · show full frame</option><option value="cover">Fill · crop to tile</option></select></label>
        <label class="ec-setting-check"><input name="mirror" type="checkbox"> Mirror my preview only</label>
      </fieldset>
      <fieldset><legend>Screen sharing</legend><label>Quality for next share<select name="quality"><option value="720p">720p</option><option value="1080p">1080p</option><option value="source">Source resolution</option></select></label></fieldset>
      <p class="ec-settings-help">Quality depends on your device and connection. Preview fit and mirroring change only your view. On phones, playback volume may use the device buttons.</p>
      <p role="status" aria-live="polite"></p><footer class="actions"><button type="button" data-close>Cancel</button><button type="submit">Save settings</button></footer>
      </form>`;
    document.body.appendChild(panel);
    const form=panel.querySelector('form'),status=panel.querySelector('[role=status]');
    let saving=false,closed=false;
    const close=()=>{if(saving)return;closed=true;panel.remove();room.off?.('disconnected',disconnected);parentFocus?.isConnected&&parentFocus.focus();};
    const disconnected=()=>{saving=false;close();};room.on?.('disconnected',disconnected);
    panel.querySelectorAll('[data-close]').forEach(b=>b.onclick=close);
    panel.onkeydown=e=>{
      if(e.key==='Escape'){e.stopPropagation();close();}
      if(e.key==='Tab'){const list=[...form.querySelectorAll('button,select,input')].filter(x=>!x.disabled);const first=list[0],last=list.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
    };
    form.querySelector('button').focus();
    for(const key of ['microphone','camera','speaker'])form.elements[key].add(new Option('System default',''));
    try{
      const devices=await navigator.mediaDevices.enumerateDevices();if(closed)return;
      for(const d of devices){const key={audioinput:'microphone',videoinput:'camera',audiooutput:'speaker'}[d.kind];if(key)form.elements[key].add(new Option(d.label||`${key} ${form.elements[key].length}`,d.deviceId));}
    }catch(e){status.textContent=e.message;}
    for(const key of ['microphone','camera','speaker','quality','cameraQuality','cameraFps','fit','volume']){
      const field=form.elements[key];
      if(['microphone','camera','speaker'].includes(key)&&prefs[key]&&![...field.options].some(x=>x.value===prefs[key]))field.add(new Option('Saved device (not currently available)',prefs[key]));
      field.value=prefs[key];
    }
    const supported=navigator.mediaDevices?.getSupportedConstraints?.()||{};
    for(const [key,constraint]of [['noise','noiseSuppression'],['echo','echoCancellation'],['gain','autoGainControl']]){
      const el=form.elements[key];el.checked=prefs[key];el.disabled=!supported[constraint];if(el.disabled)el.closest('label').title='Not supported by this browser';
    }
    form.elements.mirror.checked=prefs.mirror;
    form.elements.speaker.disabled=!('setSinkId' in HTMLMediaElement.prototype);
    if(form.elements.speaker.disabled)form.elements.speaker.options[0].textContent='Use device sound settings';
    const volume=()=>{form.querySelector('output').textContent=form.elements.volume.value+'%';};volume();form.elements.volume.oninput=volume;
    form.onsubmit=async e=>{
      e.preventDefault();if(saving)return;saving=true;
      const button=form.querySelector('[type=submit]');button.disabled=true;button.textContent='Applying…';
      const next={};for(const key of Object.keys(defaults)){const field=form.elements[key];next[key]=field.disabled?prefs[key]:field.type==='checkbox'?field.checked:field.value;}
      try{await apply(room,next);saving=false;close();}catch(err){status.textContent=err.message;}finally{saving=false;button.disabled=false;button.textContent='Save settings';}
    };
  }
  decorate();
  window.EcollabMediaSettings={ready,audio,video,screenOptions,apply,open,initialize,playback,get preferences(){return {...prefs};}};
})();

