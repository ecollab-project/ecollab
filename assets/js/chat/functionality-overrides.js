/*
 * Ecollab chat functional overrides.
 * Loaded last so feature actions cannot fall back to design-only placeholders.
 */
(function () {
  'use strict';

  const base = () => window.ECOLLAB?.baseUrl || '';
  const csrf = () => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';

  function toast(message, type = 'info') {
    if (typeof window.showToast === 'function') window.showToast(message, type);
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  async function jsonPost(path, payload) {
    const res = await fetch(base() + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf(),
      },
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
      throw new Error(data.error || data.message || `Request failed (${res.status})`);
    }
    return data;
  }

  function clearDemoNotifications() {
    const badge = document.getElementById('notifBadge');
    if (badge && badge.textContent.trim() === '3') {
      badge.textContent = '0';
      badge.style.display = 'none';
    }
    const list = document.getElementById('notifList');
    if (list && /John Doe|2 min ago/.test(list.textContent || '')) {
      list.innerHTML = '<div style="padding:24px 16px;text-align:center;color:var(--text-muted);font-size:13px;">Loading notifications…</div>';
    }
  }

  window.openExtrasAction = function (action) {
    if (typeof window.closeExtrasMenu === 'function') window.closeExtrasMenu();
    const routes = {
      poll: () => window.openModal?.('pollModal'),
      event: () => window.openCollabHub?.('calendar'),
      quiz: () => window.openCollabHub?.('quiz'),
      code: () => window.openCollabHub?.('code'),
      resource: () => window.openCollabHub?.('resources'),
      link: () => {
        const url = window.prompt('Paste the URL to share:');
        if (!url?.trim()) return;
        const input = document.getElementById('chatInputField');
        if (!input) return;
        input.value = url.trim(); input.focus();
        if (typeof window.sendMessage === 'function') window.sendMessage();
      },
    };
    const handler = routes[action];
    if (handler) return handler();
    toast('This action is not available in the current chat.', 'info');
  };
  window.__openExtrasAction = window.openExtrasAction;

  let recorder = null, recorderStream = null, recordedBlob = null, recordedUrl = null, previewAudio = null;
  function previewBar(){return document.getElementById('voicePreviewBar');}
  function resetPreviewAudio(){
    if(previewAudio){previewAudio.pause();previewAudio.removeAttribute('src');previewAudio.load();previewAudio.remove();previewAudio=null;}
    if(recordedUrl){URL.revokeObjectURL(recordedUrl);recordedUrl=null;}
  }
  function resetRecorderState(){
    if(recorderStream){recorderStream.getTracks().forEach(t=>t.stop());recorderStream=null;}
    recorder=null;resetPreviewAudio();recordedBlob=null;
    document.getElementById('voiceRecordBar')?.style && (document.getElementById('voiceRecordBar').style.display='none');
    previewBar()?.style && (previewBar().style.display='none');
    const mic=document.getElementById('micBtn');if(mic){mic.style.color='';mic.title='Voice message';}
  }
  function formatDuration(seconds){seconds=Math.max(0,Math.floor(Number(seconds)||0));return `${Math.floor(seconds/60)}:${String(seconds%60).padStart(2,'0')}`;}
  function ensurePreviewAudio(){
    if(!recordedBlob)return null;if(!recordedUrl)recordedUrl=URL.createObjectURL(recordedBlob);
    if(!previewAudio){previewAudio=document.createElement('audio');previewAudio.id='realVoicePreviewAudio';previewAudio.preload='metadata';previewAudio.style.display='none';previewBar()?.appendChild(previewAudio);previewAudio.addEventListener('loadedmetadata',()=>{const d=document.getElementById('previewDuration');if(d&&Number.isFinite(previewAudio.duration))d.textContent=formatDuration(previewAudio.duration);});previewAudio.addEventListener('timeupdate',updatePreviewProgress);previewAudio.addEventListener('ended',()=>setPreviewPlayIcon(false));}
    previewAudio.src=recordedUrl;previewAudio.load();return previewAudio;
  }
  function updatePreviewProgress(){
    if(!previewAudio)return;const c=document.getElementById('previewCurrentTime'),p=document.getElementById('previewProgress');if(c)c.textContent=formatDuration(previewAudio.currentTime);if(p&&previewAudio.duration)p.style.width=`${Math.min(100,(previewAudio.currentTime/previewAudio.duration)*100)}%`;
  }
  function setPreviewPlayIcon(playing){const i=document.getElementById('previewPlayIcon');if(i)i.innerHTML=playing?'<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>':'<path d="M8 5v14l11-7z"/>';}

  window.startRecording=async function(){
    if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){toast('Voice recording is not supported by this browser.','error');return;}
    resetRecorderState();
    try{
      recorderStream=await navigator.mediaDevices.getUserMedia({audio:true});
      const candidates=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus'];const mimeType=candidates.find(m=>MediaRecorder.isTypeSupported(m))||'';
      recorder=new MediaRecorder(recorderStream,mimeType?{mimeType}:undefined);const chunks=[];
      recorder.addEventListener('dataavailable',e=>{if(e.data?.size)chunks.push(e.data);});
      recorder.addEventListener('stop',()=>{recordedBlob=new Blob(chunks,{type:recorder?.mimeType||'audio/webm'});ensurePreviewAudio();const b=previewBar();if(b)b.style.display='flex';setPreviewPlayIcon(false);toast('Recording ready — preview it before sending.','success');});
      recorder.start(250);const rb=document.getElementById('voiceRecordBar');if(rb)rb.style.display='flex';const mic=document.getElementById('micBtn');if(mic){mic.style.color='#ef4444';mic.title='Stop recording';}toast('🔴 Recording…','info');
    }catch(e){resetRecorderState();toast(e.name==='NotAllowedError'?'Microphone permission was denied.':'Could not access the microphone.','error');}
  };
  window.stopRecordingToPreview=function(){if(!recorder||recorder.state==='inactive')return;recorder.stop();recorderStream?.getTracks().forEach(t=>t.stop());recorderStream=null;const rb=document.getElementById('voiceRecordBar');if(rb)rb.style.display='none';const mic=document.getElementById('micBtn');if(mic){mic.style.color='';mic.title='Voice message';}};
  window.togglePreviewPlay=function(){const a=ensurePreviewAudio();if(!a)return;if(a.paused)a.play().then(()=>setPreviewPlayIcon(true)).catch(()=>toast('Could not play the recording.','error'));else{a.pause();setPreviewPlayIcon(false);}};
  window.scrubPreview=function(event,el){const a=ensurePreviewAudio();if(!a||!a.duration)return;const r=el.getBoundingClientRect();a.currentTime=Math.max(0,Math.min(1,(event.clientX-r.left)/r.width))*a.duration;updatePreviewProgress();};
  window.discardRecording=function(){resetRecorderState();toast('Recording discarded.','info');};
  window.cancelRecording=function(){if(recorder&&recorder.state!=='inactive')recorder.stop();resetRecorderState();toast('Recording cancelled.','info');};
  window.reRecord=function(){resetRecorderState();window.startRecording();};
  window.sendRecording=async function(){
    if(!recordedBlob){toast('There is no recording to send.','error');return;}const channelId=Number(window.ECOLLAB?.currentChannelId||0);if(!channelId){toast('Open a text channel first.','error');return;}
    try{const ext=recordedBlob.type.includes('ogg')?'ogg':'webm';const form=new FormData();form.append('file',recordedBlob,`voice-${Date.now()}.${ext}`);toast('Uploading voice message…','info');const ur=await fetch(base()+'/API/chat/upload-file.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf()},body:form});const up=await ur.json().catch(()=>({}));if(!ur.ok||!up.success)throw new Error(up.error||'Voice upload failed');const msg=await jsonPost('/API/chat/send-message.php',{channel_id:channelId,content:'🎤 Voice message',content_type:'file',attachment_path:up.file_path,attachment_name:up.file_name,attachment_size:up.file_size,attachment_mime:up.mime_type});resetRecorderState();if(window.wsSend&&msg.message)window.wsSend({type:'message',message:msg.message});toast('🎤 Voice message sent.','success');}catch(e){toast('Failed to send voice message: '+e.message,'error');}
  };
  window.toggleVoiceRecord=function(){if(recorder&&recorder.state==='recording')window.stopRecordingToPreview();else if(recordedBlob)window.togglePreviewPlay();else window.startRecording();};

  document.addEventListener('DOMContentLoaded',clearDemoNotifications,{once:true});
  if(document.readyState!=='loading')clearDemoNotifications();

  (function loadThreadsV2(){
    if(document.getElementById('threadsV2Script'))return;
    const s=document.createElement('script');s.id='threadsV2Script';s.defer=true;s.src=base()+'/assets/js/chat/threads-v2.js?v=1';
    document.head.appendChild(s);
  })();
})();

/* Profile viewer is loaded last so it intentionally overrides the old
 * mini/full profile presentation without touching chat.js or chat-features.js. */
(function loadProfileView(){
  if(document.getElementById('profileViewScript')) return;
  const s=document.createElement('script');
  s.id='profileViewScript';
  s.defer=true;
  s.src=(window.ECOLLAB?.baseUrl||'')+'/assets/js/chat/profile-view.js?v=1';
  document.head.appendChild(s);
})();

/* Load the eye-friendly profile theme after profile-view.js injects its
 * runtime style element. !important rules in the stylesheet intentionally
 * win over the profile viewer's inline presentation styles. */
(function loadProfileViewTheme(){
  const id='profileViewThemeStyles';
  if(document.getElementById(id)) return;
  const link=document.createElement('link');
  link.id=id;
  link.rel='stylesheet';
  link.href=(window.ECOLLAB?.baseUrl||'')+'/assets/css/desktop/profile-view-overrides.css?v=1';
  document.head.appendChild(link);
})();
