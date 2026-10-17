/* Deliberate playback only; no dashboard splash screen or background audio. */
'use strict';
(() => {
 const dialog=document.getElementById('ecBrandVideoDialog'),video=document.getElementById('ecBrandVideo');if(!dialog||!video)return;
 const status=document.getElementById('ecBrandVideoStatus');let opener=null,version=0;
 function play(){const request=++version;status.textContent='';try{const promise=video.play();promise?.catch(()=>{if(request===version&&dialog.open)status.textContent='Press Play in the video controls. If playback is unavailable, use Open video below.';});}catch{status.textContent='Use Open video below if this browser cannot play the animation.';}}
 document.querySelectorAll('[data-brand-video]').forEach(button=>button.addEventListener('click',()=>{
  opener=button;if(!dialog.open)dialog.showModal();document.documentElement.classList.add('ec-brand-modal-open');
  if(!video.getAttribute('src')){video.src=video.dataset.src;video.load();}
  video.currentTime=0;play();
 }));
 document.getElementById('ecBrandVideoClose').addEventListener('click',()=>dialog.close());
 document.getElementById('ecBrandVideoReplay').addEventListener('click',()=>{video.currentTime=0;play();});
 dialog.addEventListener('close',()=>{++version;video.pause();video.currentTime=0;status.textContent='';document.documentElement.classList.remove('ec-brand-modal-open');opener?.focus();});
 dialog.addEventListener('click',event=>{if(event.target!==dialog)return;const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();});
 video.addEventListener('error',()=>{if(dialog.open)status.textContent='The animation could not load. Try Open video below or check your connection.';});
 window.addEventListener('pagehide',()=>{++version;video.pause();});
})();
