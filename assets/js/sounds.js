/* Project-supplied Ecollab SFX. Playback failures never change application state. */
'use strict';
(() => {
 if(window.EcollabSounds||window.top!==window.self)return;
 const base=window.ECOLLAB_CALLS_CONFIG?.baseUrl||window.ECOLLAB?.baseUrl||window.ECOLLAB_BASE||'';
 const names=new Set(['notification','ring','hop-in','hop-out','logo']),active=new Set(),last=new Map();
 let generation=0;
 const ready=window.EcollabMediaSettings?.ready||Promise.resolve();
 function deafened(){return !!window.EcollabCalls?.deafened||(typeof vcDeafened!=='undefined'&&vcDeafened);}
 function allowed(preview){const s=window._userSettings||{};return !deafened()&&(preview||!(s.notification_sound===0||s.notification_sound===false));}
 function configure(audio){const prefs=window.EcollabMediaSettings?.preferences,s=window._userSettings||{};const volume=Number(s.output_volume??prefs?.volume??100);audio.volume=.5*Math.max(0,Math.min(100,Number.isFinite(volume)?volume:100))/100;const device=s.output_device||prefs?.speaker;if(device&&audio.setSinkId)audio.setSinkId(device).catch(()=>{});}
 function create(name){const audio=new Audio();audio.preload='none';audio.src=base+'/assets/sounds/ecollab/'+name+(audio.canPlayType('audio/ogg; codecs="vorbis"')?'.ogg':'.mp3');return audio;}
 function stop(audio){audio.pause();audio.currentTime=0;active.delete(audio);}
 async function play(name,options={}){
  if(!names.has(name))return false;
  const requestedGeneration=generation;await ready;if(requestedGeneration!==generation||!allowed(options.preview))return false;
  const now=Date.now();if(!options.preview&&now-(last.get(name)||0)<(name==='notification'?1200:350))return false;
  last.set(name,now);if(options.preview)for(const audio of [...active])if(!audio.loop)stop(audio);
  const audio=create(name);configure(audio);active.add(audio);audio.onended=audio.onerror=()=>active.delete(audio);
  try{await audio.play();return true;}catch{active.delete(audio);return false;}
 }
 function ring(){let cancelled=false;const audio=create('ring');audio.loop=true;active.add(audio);
  ready.then(async()=>{if(cancelled||!active.has(audio)||!allowed(false))return;configure(audio);try{await audio.play();}catch{/* Browser permission may block ringing until a gesture. */}});
  return {stop(){cancelled=true;stop(audio);}};
 }
 function refresh(){for(const audio of [...active]){if(!allowed(false))stop(audio);else configure(audio);}}
 window.addEventListener('ecollab:settings-applied',refresh);window.addEventListener('ecollab-media-settings-changed',refresh);
 window.addEventListener('pagehide',()=>{generation++;for(const audio of [...active])stop(audio);});
 document.addEventListener('click',event=>{const button=event.target.closest?.('[data-sound-preview]');if(!button)return;play(button.dataset.soundPreview,{preview:true}).then(ok=>{const status=document.getElementById('soundPreviewStatus');if(status)status.textContent=ok?'Playing preview.':'Audio could not play. Check deafen, browser audio permissions and output volume.';});});
 window.EcollabSounds={play,ring};
})();
