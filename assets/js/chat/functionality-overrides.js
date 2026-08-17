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

  // ── Remove hard-coded demo notification content. The real notification
  // service populates this area shortly after the page loads.
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

  // ── Extras menu: every action now opens a real feature instead of a
  // "coming soon" toast. These tools are implemented by collab-tools and
  // collab-extra and are already loaded on the chat page.
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
        input.value = url.trim();
        input.focus();
        if (typeof window.sendMessage === 'function') window.sendMessage();
      },
    };

    const handler = routes[action];
    if (handler) return handler();
    toast('This action is not available in the current chat.', 'info');
  };
  window.__openExtrasAction = window.openExtrasAction;

  // ── Real microphone recording ──────────────────────────────────────────
  let recorder = null;
  let recorderStream = null;
  let recordedBlob = null;
  let recordedUrl = null;
  let previewAudio = null;

  function previewBar() {
    return document.getElementById('voicePreviewBar');
  }

  function resetPreviewAudio() {
    if (previewAudio) {
      previewAudio.pause();
      previewAudio.removeAttribute('src');
      previewAudio.load();
      previewAudio.remove();
      previewAudio = null;
    }
    if (recordedUrl) {
      URL.revokeObjectURL(recordedUrl);
      recordedUrl = null;
    }
  }

  function resetRecorderState() {
    if (recorderStream) {
      recorderStream.getTracks().forEach(track => track.stop());
      recorderStream = null;
    }
    recorder = null;
    resetPreviewAudio();
    recordedBlob = null;
    const recordBar = document.getElementById('voiceRecordBar');
    const bar = previewBar();
    if (recordBar) recordBar.style.display = 'none';
    if (bar) bar.style.display = 'none';
    const mic = document.getElementById('micBtn');
    if (mic) { mic.style.color = ''; mic.title = 'Voice message'; }
  }

  function ensurePreviewAudio() {
    if (!recordedBlob) return null;
    if (!recordedUrl) recordedUrl = URL.createObjectURL(recordedBlob);
    if (!previewAudio) {
      previewAudio = document.createElement('audio');
      previewAudio.id = 'realVoicePreviewAudio';
      previewAudio.preload = 'metadata';
      previewAudio.style.display = 'none';
      const bar = previewBar();
      if (bar) bar.appendChild(previewAudio);
      previewAudio.addEventListener('loadedmetadata', () => {
        const duration = document.getElementById('previewDuration');
        if (duration && Number.isFinite(previewAudio.duration)) duration.textContent = formatDuration(previewAudio.duration);
      });
      previewAudio.addEventListener('timeupdate', updatePreviewProgress);
      previewAudio.addEventListener('ended', () => setPreviewPlayIcon(false));
    }
    previewAudio.src = recordedUrl;
    previewAudio.load();
    return previewAudio;
  }

  function formatDuration(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
  }

  function updatePreviewProgress() {
    if (!previewAudio) return;
    const current = document.getElementById('previewCurrentTime');
    const progress = document.getElementById('previewProgress');
    if (current) current.textContent = formatDuration(previewAudio.currentTime);
    if (progress && previewAudio.duration) {
      progress.style.width = `${Math.min(100, (previewAudio.currentTime / previewAudio.duration) * 100)}%`;
    }
  }

  function setPreviewPlayIcon(playing) {
    const icon = document.getElementById('previewPlayIcon');
    if (!icon) return;
    icon.innerHTML = playing
      ? '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>'
      : '<path d="M8 5v14l11-7z"/>';
  }

  window.startRecording = async function () {
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      toast('Voice recording is not supported by this browser.', 'error');
      return;
    }

    resetRecorderState();
    try {
      recorderStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mimeCandidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus'];
      const mimeType = mimeCandidates.find(m => MediaRecorder.isTypeSupported(m)) || '';
      recorder = new MediaRecorder(recorderStream, mimeType ? { mimeType } : undefined);
      const chunks = [];
      recorder.addEventListener('dataavailable', e => { if (e.data?.size) chunks.push(e.data); });
      recorder.addEventListener('stop', () => {
        const type = recorder?.mimeType || 'audio/webm';
        recordedBlob = new Blob(chunks, { type });
        ensurePreviewAudio();
        const bar = previewBar();
        if (bar) bar.style.display = 'flex';
        const duration = document.getElementById('previewDuration');
        if (duration && previewAudio?.duration) duration.textContent = formatDuration(previewAudio.duration);
        setPreviewPlayIcon(false);
        toast('Recording ready — preview it before sending.', 'success');
      });

      recorder.start(250);
      const recordBar = document.getElementById('voiceRecordBar');
      if (recordBar) recordBar.style.display = 'flex';
      const mic = document.getElementById('micBtn');
      if (mic) { mic.style.color = '#ef4444'; mic.title = 'Stop recording'; }
      toast('🔴 Recording…', 'info');
    } catch (e) {
      resetRecorderState();
      toast(e.name === 'NotAllowedError' ? 'Microphone permission was denied.' : 'Could not access the microphone.', 'error');
    }
  };

  window.stopRecordingToPreview = function () {
    if (!recorder || recorder.state === 'inactive') return;
    recorder.stop();
    if (recorderStream) {
      recorderStream.getTracks().forEach(track => track.stop());
      recorderStream = null;
    }
    const recordBar = document.getElementById('voiceRecordBar');
    if (recordBar) recordBar.style.display = 'none';
    const mic = document.getElementById('micBtn');
    if (mic) { mic.style.color = ''; mic.title = 'Voice message'; }
  };

  window.togglePreviewPlay = function () {
    const audio = ensurePreviewAudio();
    if (!audio) return;
    if (audio.paused) {
      audio.play().then(() => setPreviewPlayIcon(true)).catch(() => toast('Could not play the recording.', 'error'));
    } else {
      audio.pause();
      setPreviewPlayIcon(false);
    }
  };

  window.scrubPreview = function (event, el) {
    const audio = ensurePreviewAudio();
    if (!audio || !audio.duration) return;
    const rect = el.getBoundingClientRect();
    const pct = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
    audio.currentTime = pct * audio.duration;
    updatePreviewProgress();
  };

  window.discardRecording = function () {
    resetRecorderState();
    toast('Recording discarded.', 'info');
  };

  window.cancelRecording = function () {
    if (recorder && recorder.state !== 'inactive') recorder.stop();
    resetRecorderState();
    toast('Recording cancelled.', 'info');
  };

  window.reRecord = function () {
    resetRecorderState();
    window.startRecording();
  };

  window.sendRecording = async function () {
    if (!recordedBlob) {
      toast('There is no recording to send.', 'error');
      return;
    }
    const channelId = Number(window.ECOLLAB?.currentChannelId || 0);
    if (!channelId) {
      toast('Open a text channel first.', 'error');
      return;
    }

    try {
      const ext = recordedBlob.type.includes('ogg') ? 'ogg' : 'webm';
      const form = new FormData();
      form.append('file', recordedBlob, `voice-${Date.now()}.${ext}`);
      toast('Uploading voice message…', 'info');

      const uploadRes = await fetch(base() + '/API/chat/upload-file.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf() },
        body: form,
      });
      const upload = await uploadRes.json().catch(() => ({}));
      if (!uploadRes.ok || !upload.success) throw new Error(upload.error || 'Voice upload failed');

      const message = await jsonPost('/API/chat/send-message.php', {
        channel_id: channelId,
        content: '🎤 Voice message',
        content_type: 'file',
        attachment_path: upload.file_path,
        attachment_name: upload.file_name,
        attachment_size: upload.file_size,
        attachment_mime: upload.mime_type,
      });

      resetRecorderState();
      if (typeof window.renderMessages === 'function') {
        const current = await fetch(base() + `/API/chat/get-messages.php?channel_id=${channelId}`, {
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrf() },
        });
        const data = await current.json();
        if (data.messages) window.renderMessages(data.messages, false);
      }
      if (window.wsSend && message.message) window.wsSend({ type: 'message', message: message.message });
      toast('🎤 Voice message sent.', 'success');
    } catch (e) {
      toast('Failed to send voice message: ' + e.message, 'error');
    }
  };

  // Keep the recording toggle tied to the real recorder.
  window.toggleVoiceRecord = function () {
    if (recorder && recorder.state === 'recording') window.stopRecordingToPreview();
    else if (recordedBlob) window.togglePreviewPlay();
    else window.startRecording();
  };

  document.addEventListener('DOMContentLoaded', clearDemoNotifications, { once: true });
  if (document.readyState !== 'loading') clearDemoNotifications();
})();
