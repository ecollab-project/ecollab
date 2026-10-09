import Uppy from '@uppy/core';
import Tus from '@uppy/tus';

// One lightweight transfer panel; preserve eCollab's existing composer and attachment previews.
const active = new Set();
const base = () => (window.ECOLLAB?.baseUrl || '').replace(/\/$/, '');
const token = () => window.ECOLLAB?.csrfToken || '';
function panel(name) {
  let stack = document.getElementById('chatUploadTransfers');
  if (!stack) {
    stack = document.createElement('section'); stack.id = 'chatUploadTransfers';
    stack.setAttribute('aria-label', 'Attachment transfers'); document.body.append(stack);
  }
  const row = document.createElement('div'); row.className = 'chat-upload-transfer';
  const label = document.createElement('strong'); label.textContent = name;
  const status = document.createElement('span'); status.setAttribute('role','status'); status.textContent = 'Preparing upload…';
  const progress = document.createElement('progress'); progress.max = 100; progress.value = 0; progress.setAttribute('aria-label','Upload progress');
  const pause = document.createElement('button'); pause.type = 'button'; pause.textContent = 'Pause';
  const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = 'Cancel';
  row.append(label, status, progress, pause, cancel); stack.append(row);
  return {row,status,progress,pause,cancel};
}
async function refreshToken() {
  const r = await fetch(base()+'/API/auth/csrf-token.php', {credentials:'same-origin',cache:'no-store'});
  const d = await r.json();
  if (!r.ok || !d.token) throw new Error('Please sign in again before uploading.');
  window.ECOLLAB.csrfToken = d.token;
}
function checkedUrl(raw) {
  const url = new URL(raw, window.location.href);
  const expected = new URL(base()+'/API/chat/resumable-upload.php', window.location.href);
  if (url.origin !== expected.origin || url.pathname !== expected.pathname) throw new Error('Invalid upload response.');
  return url.href;
}
async function upload(file, context) {
  if (!['channel','dm','group'].includes(context.kind) || !Number.isInteger(Number(context.id)) || Number(context.id) < 1) throw new Error('Select a chat before uploading.');
  if (!file.size || file.size > 20*1024*1024) throw new Error('File must be between 1 byte and 20 MB.');
  if (active.size >= 2) throw new Error('Wait for an attachment upload to finish.');
  const reservation = {}; active.add(reservation);
  let uppy, ui, url, paused = false, cancelled = false;
  try {
    await refreshToken();
    ui = panel(file.name);
    // Uppy Tus fingerprints include the Uppy instance ID, file size and lastModified.
    // Scope the instance to owner + target so another account/chat cannot resume this upload.
    uppy = new Uppy({id:'ecollab-'+window.ECOLLAB.userId+'-'+context.kind+'-'+context.id,autoProceed:false,restrictions:{maxNumberOfFiles:1,maxFileSize:20*1024*1024}});
    uppy.use(Tus, {
      endpoint:base()+'/API/chat/resumable-upload.php',limit:1,chunkSize:1024*1024,withCredentials:true,
      // Keep the URL until its 24-hour server expiry, including if the final GET is interrupted.
      retryDelays:[0,1000,3000,5000,10000],removeFingerprintOnSuccess:false,
      allowedMetaFields:['name','kind','target'],
      // Set once: XMLHttpRequest appends duplicate header values, breaking CSRF verification.
      onBeforeRequest:request => { request.setHeader('X-CSRF-Token', token()); },
      onShouldRetry:(error,attempt,options,next) => {
        if (cancelled || [401,403,404,410,413,415,429].includes(error.originalResponse?.getStatus())) return false;
        ui.status.textContent = 'Connection interrupted. Retrying…'; return next(error);
      },
    });
    const id = uppy.addFile({name:file.name,type:file.type,data:file,meta:{kind:context.kind,target:String(context.id)}});
    const done = new Promise((resolve,reject) => {
      uppy.on('upload-progress', (f,p) => {
        const percent = Math.min(100,Math.round(100*p.bytesUploaded/(p.bytesTotal || file.size)));
        ui.progress.value = percent; ui.status.textContent = paused ? 'Paused' : percent+'% uploaded';
      });
      uppy.on('upload-error', (f,error) => reject(new Error(error.message || 'Upload failed. Select this file again to resume.')));
      uppy.on('upload-success', async (f,response) => {
        try {
          if (cancelled) return;
          url = checkedUrl(response.uploadURL);
          ui.pause.disabled = true; ui.status.textContent = 'Checking attachment…';
          const r = await fetch(url, {credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':token()}});
          const data = await r.json();
          if (!r.ok || !data.success) throw new Error(data.error || 'Attachment validation failed.');
          if (!cancelled) resolve(data);
        } catch(error) { reject(error); }
      });
      ui.pause.onclick = () => { paused = Boolean(uppy.pauseResume(id)); ui.pause.textContent = paused ? 'Resume' : 'Pause'; ui.status.textContent = paused ? 'Paused' : 'Resuming…'; };
      ui.cancel.onclick = () => {
        cancelled = true;
        const location = uppy.getFile(id)?.tus?.uploadUrl;
        uppy.cancelAll();
        if (location) fetch(checkedUrl(location),{method:'DELETE',credentials:'same-origin',headers:{'Tus-Resumable':'1.0.0','X-CSRF-Token':token()}}).catch(()=>{});
        reject(new Error('Upload cancelled.'));
      };
    });
    // upload() also resolves on failure; events above provide a rejected promise for the caller.
    uppy.upload().catch(error => uppy.emit('upload-error',uppy.getFile(id),error));
    return await done;
  } finally {
    uppy?.destroy(); ui?.row.remove(); active.delete(reservation);
    if (!document.querySelector('#chatUploadTransfers .chat-upload-transfer')) document.getElementById('chatUploadTransfers')?.remove();
  }
}
window.EcollabUploads = {upload};
window.addEventListener('beforeunload', event => { if(active.size){event.preventDefault();event.returnValue='';} });
