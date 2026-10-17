/* Account chat preferences; processing is scoped to changed messages. */
(() => {
 'use strict';if(window.EcollabPreferences)return;
 const enabled=key=>!(window._userSettings?.[key]===0||window._userSettings?.[key]===false);
 function decorate(message){if(!message)return;
  message.querySelectorAll('img').forEach(img=>{img.loading='lazy';img.decoding='async';});
  message.querySelectorAll('video').forEach(video=>{video.autoplay=false;video.preload='none';});
  for(const image of message.querySelectorAll('.msg-content>img,.dm-message-bubble>a>img,.ec-media-sticker img')){
   const animated=/\.(gif|webp)(?:[?#]|$)/i.test(image.getAttribute('src')||'')||!!image.closest('.ec-media-sticker');
   const blocked=!enabled('media_previews')||animated&&!enabled('autoplay_gifs');
   const holder=image.closest('.ec-media-sticker')||image.closest('a')||image;
   let control=holder.nextElementSibling;if(!control?.classList.contains('ec-preview-control'))control=null;
   if(blocked){image.hidden=true;holder.hidden=true;if(!control){control=document.createElement('button');control.type='button';control.className='ec-preview-control';control.textContent=enabled('media_previews')?'Show animation':'Show image';control.onclick=event=>{event.preventDefault();image.hidden=false;holder.hidden=false;control.hidden=true;};holder.after(control);}control.hidden=false;}
   else {image.hidden=false;holder.hidden=false;if(control)control.hidden=true;}
  }
  for(const video of message.querySelectorAll('.msg-content>video')){const blocked=!enabled('media_previews');video.hidden=blocked;if(blocked)video.pause();let link=video.nextElementSibling;if(!link?.classList.contains('ec-video-link'))link=null;if(blocked&&!link){link=document.createElement('a');link.className='ec-video-link';link.href=video.src;link.target='_blank';link.rel='noopener';link.textContent='Open video attachment';video.after(link);}if(link)link.hidden=!blocked;}
  const links=[...message.querySelectorAll('.msg-text a[href],.dm-message-bubble a[href]')].filter(a=>!a.closest('.ec-link-preview')&&!a.hasAttribute('download')&&!a.querySelector('img')).map(a=>{try{const u=new URL(a.href);return ['https:','http:'].includes(u.protocol)&&!u.pathname.includes('/uploads/')?u:null;}catch{return null;}}).filter(Boolean).slice(0,2);
  const urls=new Set(links.map(u=>u.href));message.querySelectorAll('.ec-link-preview').forEach(card=>{if(!urls.has(card.href))card.remove();else card.hidden=!enabled('link_previews');});
  if(enabled('link_previews'))for(const url of links){if([...message.querySelectorAll('.ec-link-preview')].some(card=>card.href===url.href))continue;const card=document.createElement('a');card.className='ec-link-preview';card.href=url.href;card.target='_blank';card.rel='noopener noreferrer';const title=document.createElement('strong');title.textContent=url.hostname;const detail=document.createElement('small');detail.textContent=url.pathname==='/'?'Open website':url.pathname;card.append(title,detail);(message.querySelector('.msg-content,.dm-message-bubble')||message).append(card);}
  message.querySelectorAll('.link-preview,.url-preview,.embed-preview').forEach(el=>el.hidden=!enabled('link_previews'));
 }
 function apply(settings){if(settings)window._userSettings={...window._userSettings,...settings};const root=document.documentElement;
  for(const key of ['show_timestamps','typing_indicators','link_previews','read_receipts'])root.classList.toggle('ec-no-'+key,!enabled(key));
  root.classList.toggle('ec-jarred-disabled',!enabled('jarred_enabled'));
  for(const id of ['messagesArea','dmMessagesArea']){const area=document.getElementById(id);if(!area)continue;area.setAttribute('role','log');area.setAttribute('aria-label',id==='messagesArea'?'Channel messages':'Direct messages');area.setAttribute('aria-live',window._userSettings?.screen_reader_mode?'polite':'off');area.setAttribute('aria-relevant','additions text');area.querySelectorAll('[data-msg-id]').forEach(decorate);}
  document.querySelectorAll('.ec-composer-footer>span:first-child').forEach(e=>e.textContent=enabled('enter_to_send')?'Enter to send · Shift+Enter for a new line':'Enter for a new line · Ctrl+Enter to send');
 }
 const uid=document.querySelector('meta[name="ecollab-user"]')?.content;
 const channel=uid&&uid!=='0'&&typeof BroadcastChannel==='function'?new BroadcastChannel('ecollab-settings-'+uid):null;
 let refreshTimer,lastSnapshot;
 if(channel)channel.onmessage=()=>{clearTimeout(refreshTimer);refreshTimer=setTimeout(async()=>{try{const base=window.ECOLLAB?.baseUrl||window.ECOLLAB_BASE||'';const response=await fetch(base+'/API/profile/settings.php',{credentials:'same-origin'});if(!response.ok)return;const data=await response.json();if(data.settings)window.EcollabAccountSettings?.apply(data.settings,true);}catch{}},100);};
 window.addEventListener('pagehide',event=>{if(!event.persisted)channel?.close();});
 window.EcollabPreferences={enabled,decorate,apply};window.addEventListener('ecollab:settings-applied',e=>{apply(e.detail);const snapshot=JSON.stringify(e.detail);if(lastSnapshot&&snapshot!==lastSnapshot&&!e.fromSettingsSync)channel?.postMessage({type:'refresh'});lastSnapshot=snapshot;});apply();
})();
