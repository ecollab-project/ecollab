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

  // Shared by the collaboration-hub IIFE below.
  window.ecollabBase = base;
  window.ecollabCsrf = csrf;
  window.ecollabEsc = esc;

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

/* ───────────────────────────────────────────────────────────────────────────
 * CHAT COLLABORATION ACCESS + DOCUMENTS
 *
 * Students/member roles get the focused collaboration surface: Notes +
 * Documents. Facilitator-level roles retain the existing advanced tools.
 * Documents are backed by the existing channel-membership protected
 * ONLYOFFICE document API and open in the existing editor route.
 * ───────────────────────────────────────────────────────────────────────── */
(function configureChatCollaborationHub(){
  'use strict';

  const base = window.ecollabBase || (() => window.ECOLLAB?.baseUrl || '');
  const csrf = window.ecollabCsrf || (() => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '');
  const esc = window.ecollabEsc || (value => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));

  const privilegedRoles = new Set(['facilitator', 'moderator', 'admin', 'super_admin']);
  const role = String(window.ECOLLAB?.role || 'student').toLowerCase();
  const isPrivileged = privilegedRoles.has(role);
  const memberTools = new Set(['notes', 'documents']);

  function collabPanel() { return document.getElementById('collabHub'); }
  function collabTabBar() { return collabPanel()?.querySelector('.collab-tab-bar'); }

  function setPane(tool) {
    const panel = collabPanel();
    if (!panel) return;
    panel.querySelectorAll('.collab-tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tool === tool);
    });
    panel.querySelectorAll('.collab-pane').forEach(pane => {
      pane.style.display = pane.id === `collabPane_${tool}` ? 'flex' : 'none';
    });
  }

  function ensureDocumentsUI() {
    const panel = collabPanel();
    const bar = collabTabBar();
    if (!panel || !bar) return false;

    let tab = bar.querySelector('.collab-tab-btn[data-tool="documents"]');
    if (!tab) {
      tab = document.createElement('button');
      tab.className = 'collab-tab-btn';
      tab.dataset.tool = 'documents';
      tab.type = 'button';
      tab.innerHTML = '<span class="tab-icon">📄</span>Documents';
      tab.addEventListener('click', () => window._switchCollabTool?.('documents'));
      bar.insertBefore(tab, bar.children[1] || null);
    }

    let pane = document.getElementById('collabPane_documents');
    if (!pane) {
      pane = document.createElement('div');
      pane.id = 'collabPane_documents';
      pane.className = 'collab-pane';
      pane.style.flexDirection = 'column';
      panel.appendChild(pane);
    }
    return true;
  }

  function filterTabs() {
    const panel = collabPanel();
    if (!panel) return;
    panel.querySelectorAll('.collab-tab-btn').forEach(btn => {
      const tool = btn.dataset.tool;
      btn.style.display = (!isPrivileged && !memberTools.has(tool)) ? 'none' : '';
    });
  }

  async function loadDocuments() {
    const pane = document.getElementById('collabPane_documents');
    const channelId = Number(window.ECOLLAB?.currentChannelId || 0);
    if (!pane || !channelId) return;

    pane.innerHTML = `
      <div class="collab-loading"><div class="collab-spinner"></div></div>`;
    try {
      const response = await fetch(`${base()}/API/collaboration/documents.php?channel_id=${encodeURIComponent(channelId)}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.error || 'Could not load documents.');

      const documents = Array.isArray(data.documents) ? data.documents : [];
      pane.innerHTML = `
        <div class="collab-documents-head" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;">
          <div>
            <div style="font-size:16px;font-weight:800;color:var(--text-primary);">Documents</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Real-time Word, Excel and PowerPoint collaboration.</div>
          </div>
          <button type="button" class="collab-btn-sm" id="collabCreateDocumentBtn">＋ New</button>
        </div>
        <div id="collabDocumentCreate" style="display:none;margin-bottom:12px;padding:10px;border:1px solid var(--border-color,rgba(148,163,184,.15));border-radius:10px;background:rgba(15,23,42,.35);">
          <div style="display:flex;gap:7px;flex-wrap:wrap;">
            <input id="collabDocumentTitle" type="text" maxlength="220" placeholder="Document name" style="flex:1;min-width:160px;background:var(--bg-secondary,#111827);border:1px solid rgba(148,163,184,.18);border-radius:7px;padding:8px 9px;color:var(--text-primary,#fff);font:inherit;">
            <select id="collabDocumentType" style="background:var(--bg-secondary,#111827);border:1px solid rgba(148,163,184,.18);border-radius:7px;padding:8px;color:var(--text-primary,#fff);font:inherit;">
              <option value="docx">Word</option>
              <option value="xlsx">Excel</option>
              <option value="pptx">PowerPoint</option>
            </select>
            <button type="button" class="collab-btn-sm" id="collabCreateDocumentSubmit">Create</button>
          </div>
          <div id="collabDocumentError" style="display:none;margin-top:7px;color:#fca5a5;font-size:11px;"></div>
        </div>
        <div id="collabDocumentList" style="display:flex;flex-direction:column;gap:7px;min-height:40px;"></div>`;

      const list = document.getElementById('collabDocumentList');
      if (!documents.length) {
        list.innerHTML = `
          <div style="padding:28px 14px;text-align:center;border:1px dashed rgba(148,163,184,.18);border-radius:10px;color:var(--text-muted);">
            <div style="font-size:28px;margin-bottom:7px;">📄</div>
            <div style="font-size:13px;font-weight:700;color:var(--text-primary);">No documents yet</div>
            <div style="font-size:11px;margin-top:4px;">Create a shared document for this channel.</div>
          </div>`;
      } else {
        list.innerHTML = documents.map(doc => {
          const type = String(doc.file_type || 'docx').toLowerCase();
          const icon = type === 'xlsx' ? '📊' : (type === 'pptx' ? '📽️' : '📄');
          const editor = `${base()}/modules/collaboration/documents/editor.php?channel_id=${encodeURIComponent(channelId)}&id=${encodeURIComponent(doc.id)}`;
          return `
            <div class="collab-document-row" style="display:flex;align-items:center;gap:9px;padding:10px;border:1px solid rgba(148,163,184,.12);border-radius:9px;background:rgba(15,23,42,.28);">
              <div style="font-size:22px;flex:0 0 auto;">${icon}</div>
              <div style="min-width:0;flex:1;">
                <div style="font-size:12px;font-weight:700;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(doc.title || doc.file_name || 'Untitled Document')}</div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">${esc(type.toUpperCase())} · v${esc(doc.version || 1)}</div>
              </div>
              <button type="button" class="collab-btn-sm" data-editor-url="${esc(editor)}">Open</button>
            </div>`;
        }).join('');
      }

      document.getElementById('collabCreateDocumentBtn')?.addEventListener('click', () => {
        const form = document.getElementById('collabDocumentCreate');
        if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
        document.getElementById('collabDocumentTitle')?.focus();
      });
      document.getElementById('collabCreateDocumentSubmit')?.addEventListener('click', createDocument);
      list.querySelectorAll('[data-editor-url]').forEach(button => {
        button.addEventListener('click', () => {
          const url = button.getAttribute('data-editor-url');
          if (url) window.open(url, '_blank', 'noopener');
        });
      });
    } catch (error) {
      pane.innerHTML = `<div class="collab-err">⚠ ${esc(error.message || 'Could not load documents.')}</div>`;
    }
  }

  async function createDocument() {
    const titleEl = document.getElementById('collabDocumentTitle');
    const typeEl = document.getElementById('collabDocumentType');
    const errorEl = document.getElementById('collabDocumentError');
    const channelId = Number(window.ECOLLAB?.currentChannelId || 0);
    if (!channelId) return;
    const title = titleEl?.value?.trim() || 'Untitled Document';
    const type = typeEl?.value || 'docx';
    if (errorEl) { errorEl.style.display = 'none'; errorEl.textContent = ''; }

    try {
      const response = await fetch(`${base()}/API/collaboration/documents.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({ channel_id: channelId, title, type, csrf_token: csrf() }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.error || 'Could not create document.');
      const id = Number(data.document?.id || 0);
      if (!id) throw new Error('The document was created without an editor id.');
      const editor = `${base()}/modules/collaboration/documents/editor.php?channel_id=${encodeURIComponent(channelId)}&id=${encodeURIComponent(id)}`;
      window.open(editor, '_blank', 'noopener');
      loadDocuments();
      toast('Document created.', 'success');
    } catch (error) {
      if (errorEl) { errorEl.textContent = error.message || 'Could not create document.'; errorEl.style.display = 'block'; }
      toast(error.message || 'Could not create document.', 'error');
    }
  }

  function switchTool(tool) {
    const requested = String(tool || 'notes');
    if (!isPrivileged && !memberTools.has(requested)) return originalSwitch('notes');
    if (requested === 'documents') {
      ensureDocumentsUI();
      setPane('documents');
      loadDocuments();
      return;
    }
    originalSwitch(requested);
  }

  const originalSwitch = window._switchCollabTool;
  const originalOpen = window.openCollabHub;
  if (typeof originalSwitch !== 'function' || typeof originalOpen !== 'function') return;

  function openHub(tool) {
    const requested = String(tool || 'notes');
    const safeTool = (!isPrivileged && !memberTools.has(requested)) ? 'notes' : requested;
    ensureDocumentsUI();
    filterTabs();
    if (safeTool === 'documents') {
      const panel = collabPanel();
      if (!panel) return;
      panel.style.display = 'flex';
      requestAnimationFrame(() => panel.classList.add('collab-open'));
      setPane('documents');
      loadDocuments();
      return;
    }
    originalOpen(safeTool);
    filterTabs();
  }

  window._switchCollabTool = switchTool;
  window.openCollabHub = openHub;

  function initialise() {
    if (!ensureDocumentsUI()) return;
    filterTabs();
    if (!isPrivileged) {
      const panel = collabPanel();
      if (panel) panel.querySelectorAll('.collab-tab-btn').forEach(btn => {
        if (!memberTools.has(btn.dataset.tool)) btn.style.display = 'none';
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, { once: true });
  else initialise();
})();
