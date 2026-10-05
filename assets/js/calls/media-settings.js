'use strict';
(() => {
  if (window.EcollabMediaSettings) return;
  let saved = {};
  try { saved = JSON.parse(localStorage.getItem('ec_media_settings') || '{}'); } catch (_) {}
  let prefs = { microphone: '', camera: '', speaker: '', noise: true, quality: '1080p', ...saved };
  const audio = () => ({ echoCancellation: true, noiseSuppression: !!prefs.noise, autoGainControl: true, ...(prefs.microphone ? {deviceId: prefs.microphone} : {}) });
  const video = () => ({ resolution: {width:1280,height:720,frameRate:30}, ...(prefs.camera ? {deviceId:prefs.camera} : {}) });
  function screenOptions(quality = prefs.quality) {
    const resolution = quality === '720p' ? {width:1280,height:720,frameRate:30} : quality === '1080p' ? {width:1920,height:1080,frameRate:30} : undefined;
    return { capture: {audio:true, contentHint:'detail', ...(resolution ? {resolution} : {resolution:{frameRate:30}})}, publish: {screenShareEncoding:{maxBitrate:quality==='720p'?3000000:quality==='1080p'?6000000:10000000,maxFramerate:30}, degradationPreference:'maintain-resolution'} };
  }
  async function apply(room, next) {
    if (!room) throw new Error('Join a call first.');
    const old = prefs;
    const local = room.localParticipant;
    try {
      if (next.microphone !== old.microphone) await room.switchActiveDevice('audioinput', next.microphone || 'default');
      if (next.camera !== old.camera) await room.switchActiveDevice('videoinput', next.camera || 'default');
      if (next.speaker !== old.speaker) await room.switchActiveDevice('audiooutput', next.speaker || 'default');
      prefs = next;
      const mic = local.getTrackPublication('microphone')?.track;
      if (mic) await mic.restartTrack(audio());
      localStorage.setItem('ec_media_settings', JSON.stringify(prefs));
    } catch (e) { prefs = old; throw e; }
  }
  async function open(room) {
    if (!room || document.getElementById('ecMediaSettings')) return;
    const parentFocus = document.activeElement;
    const panel = document.createElement('div'); panel.id='ecMediaSettings'; panel.className='ec-media-settings';
    panel.innerHTML='<form role="dialog" aria-modal="true" aria-labelledby="ecMediaTitle"><h2 id="ecMediaTitle">Call settings</h2><label>Microphone<select name="microphone"></select></label><label>Camera<select name="camera"></select></label><label>Speaker<select name="speaker"></select></label><label><input name="noise" type="checkbox"> Browser noise suppression</label><label>Screen share quality<select name="quality"><option value="720p">720p</option><option value="1080p">1080p</option><option value="source">Source resolution</option></select></label><p>Screen quality applies to the next share. Available quality depends on the source, network and device.</p><p role="status"></p><div class="actions"><button type="button">Close</button><button type="submit">Save</button></div></form>';
    document.body.appendChild(panel);
    const form=panel.querySelector('form'), status=panel.querySelector('[role=status]');
    const close=()=>{panel.remove();parentFocus?.focus();};
    panel.querySelector('[type=button]').onclick=close;
    panel.onkeydown=e=>{if(e.key==='Escape')close(); if(e.key==='Tab'){const list=[...form.querySelectorAll('button,select,input')].filter(x=>!x.disabled);const a=list[0],b=list.at(-1);if(e.shiftKey&&document.activeElement===a){e.preventDefault();b.focus();}else if(!e.shiftKey&&document.activeElement===b){e.preventDefault();a.focus();}}};
    for(const key of ['microphone','camera','speaker']) form.elements[key].add(new Option('System default',''));
    try {
      const devices=await navigator.mediaDevices.enumerateDevices();
      for(const d of devices){const key={audioinput:'microphone',videoinput:'camera',audiooutput:'speaker'}[d.kind];if(key)form.elements[key].add(new Option(d.label||`${key} ${form.elements[key].length}`,d.deviceId));}
    } catch(e){status.textContent=e.message;}
    for(const key of ['microphone','camera','speaker','quality'])form.elements[key].value=prefs[key];
    form.elements.noise.checked=prefs.noise;
    if(!navigator.mediaDevices.getSupportedConstraints().noiseSuppression){form.elements.noise.disabled=true;status.textContent='Noise suppression is not supported by this browser.';}
    if(!('setSinkId' in HTMLMediaElement.prototype)){form.elements.speaker.disabled=true;status.textContent+=' Speaker selection uses your device settings in this browser.';}
    form.onsubmit=async e=>{e.preventDefault();const button=form.querySelector('[type=submit]');button.disabled=true;try{await apply(room,{microphone:form.elements.microphone.value,camera:form.elements.camera.value,speaker:form.elements.speaker.disabled?prefs.speaker:form.elements.speaker.value,noise:form.elements.noise.checked,quality:form.elements.quality.value});close();}catch(err){status.textContent=err.message;}finally{button.disabled=false;}};
    form.querySelector('select').focus();
  }
  window.EcollabMediaSettings={audio,video,screenOptions,apply,open,get preferences(){return {...prefs};}};
})();
