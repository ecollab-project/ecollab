/* Ecollab Phase 4.1 — persistent AI sessions.
 * Loaded after the existing dashboard JS. It replaces the old demo-only
 * sendAI()/aiQP() behavior with real session/message persistence.
 */
(function () {
  'use strict';

  const base = window.ECOLLAB_BASE || '';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  let currentSessionId = null;
  let initialized = false;
  let sessionLoad = null;
  let sending = false;

  async function request(path, options = {}) {
    const headers = Object.assign({
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    }, options.headers || {});
    if (options.method && options.method !== 'GET') headers['X-CSRF-Token'] = csrf();

    const response = await fetch(base + path, Object.assign({}, options, { headers }));
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[c]));
  }

  function injectStyles() {
    if (document.getElementById('ecollab-ai-session-style')) return;
    const style = document.createElement('style');
    style.id = 'ecollab-ai-session-style';
    style.textContent = `
      #aiModal .ec-ai-shell{display:grid;grid-template-columns:190px minmax(0,1fr);gap:12px;min-height:390px}
      #aiModal .ec-ai-sidebar{border-right:1px solid var(--border2,#273244);padding-right:10px;overflow:auto}
      #aiModal .ec-ai-side-title{font-size:10px;font-weight:800;color:var(--muted2,#94a3b8);text-transform:uppercase;letter-spacing:.08em;margin:3px 0 8px}
      #aiModal .ec-ai-new{width:100%;margin-bottom:9px}
      #aiModal .ec-ai-session{display:flex;align-items:center;gap:6px;width:100%;padding:8px;border:1px solid transparent;border-radius:8px;background:transparent;color:var(--text,#e5e7eb);text-align:left;cursor:pointer;font:inherit}
      #aiModal .ec-ai-session:hover,#aiModal .ec-ai-session.active{background:rgba(124,58,237,.12);border-color:rgba(124,58,237,.25)}
      #aiModal .ec-ai-session-name{font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
      #aiModal .ec-ai-session-menu{opacity:.55;border:0;background:none;color:inherit;cursor:pointer}
      #aiModal .ec-ai-main{min-width:0;display:flex;flex-direction:column}
      #aiModal .ec-ai-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
      #aiModal .ec-ai-title{font-size:12px;font-weight:800}
      #aiModal .ec-ai-actions{display:flex;gap:5px}
      #aiModal .ec-ai-log{flex:1;min-height:260px;max-height:360px;overflow:auto;padding:3px}
      #aiModal .ec-ai-empty{display:flex;align-items:center;justify-content:center;height:220px;color:var(--muted2,#94a3b8);font-size:12px;text-align:center}
      #aiModal .ec-ai-compose{display:flex;gap:7px;margin-top:8px}
      #aiModal .ec-ai-compose input{flex:1}
      #aiModal .ec-ai-loading{opacity:.65}
      #aiModal .ec-ai-msg{
  word-break:break-word;
  overflow-wrap:anywhere;
  line-height:1.55;
}
      #aiModal .ec-ai-prompts{display:flex;gap:5px;flex-wrap:wrap;margin:7px 0}
      #aiModal .ec-ai-prompt{font-size:9.5px;padding:5px 8px}
      @media(max-width:700px){#aiModal .ec-ai-shell{grid-template-columns:1fr}#aiModal .ec-ai-sidebar{border-right:0;border-bottom:1px solid var(--border2,#273244);padding:0 0 8px;max-height:130px}}
    `;
    document.head.appendChild(style);
  }

  function getModal() {
    return document.getElementById('aiModal');
  }

  function buildUI() {
    const modal = getModal();
    if (!modal || initialized) return;
    const body = modal.querySelector('.mb');
    if (!body) return;

    injectStyles();
    body.innerHTML = `
      <div class="ec-ai-shell">
        <aside class="ec-ai-sidebar">
          <button class="btn-primary ec-ai-new" type="button" id="ecAiNew">＋ New chat</button>
          <div class="ec-ai-side-title">Your conversations</div>
          <div id="ecAiSessions"></div>
        </aside>
        <section class="ec-ai-main">
          <div class="ec-ai-header">
            <div class="ec-ai-title" id="ecAiTitle">New AI Conversation</div>
            <div class="ec-ai-actions">
              <button class="btn-sm btn-outline" type="button" id="ecAiRename">Rename</button>
              <button class="btn-sm btn-outline" type="button" id="ecAiDelete">Delete</button>
            </div>
          </div>
          <div class="ai-log ec-ai-log" id="ecAiLog">
            <div class="ec-ai-empty">Start a new conversation or select an existing one.</div>
          </div>
          <div class="ec-ai-prompts" id="ecAiPrompts"></div>
          <div class="ec-ai-compose">
            <input class="fi" id="ecAiInput" maxlength="4000" placeholder="Ask anything...">
            <button class="btn-primary" type="button" id="ecAiSend">Send →</button>
          </div>
        </section>
      </div>
    `;

    document.getElementById('ecAiNew').addEventListener('click', newSession);
    document.getElementById('ecAiSend').addEventListener('click', sendPersistentAI);
    document.getElementById('ecAiRename').addEventListener('click', renameCurrent);
    document.getElementById('ecAiDelete').addEventListener('click', deleteCurrent);
    document.getElementById('ecAiInput').addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendPersistentAI();
      }
    });

    initialized = true;
    loadPrompts();
  }

  async function loadPrompts() {
    try {
      const data = await request('/API/ai/quick-prompts.php');
      const box = document.getElementById('ecAiPrompts');
      if (!box) return;
      box.innerHTML = '';
      data.prompts.forEach(prompt => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn-sm btn-outline ec-ai-prompt';
        button.textContent = prompt.label;
        button.addEventListener('click', () => {
          const input = document.getElementById('ecAiInput');
          if (input) {
            input.value = prompt.prompt_text;
            input.focus();
          }
        });
        box.appendChild(button);
      });
    } catch (error) {
      console.warn('[Ecollab AI] quick prompts:', error.message);
    }
  }

  async function loadSessions(selectLatest = true) {
    const box = document.getElementById('ecAiSessions');
    if (!box) return;

    try {
      const data = await request('/API/ai/sessions.php');
      box.innerHTML = '';

      if (!data.sessions.length) {
        box.innerHTML = '<div style="font-size:10px;color:var(--muted2);padding:5px">No conversations yet.</div>';
        if (selectLatest) await newSession(false);
        return;
      }

      data.sessions.forEach(session => {
        const row = document.createElement('div');
        row.className = 'ec-ai-session' + (session.id === currentSessionId ? ' active' : '');
        row.dataset.id = String(session.id);

        const name = document.createElement('span');
        name.className = 'ec-ai-session-name';
        name.textContent = session.session_title;

        const menu = document.createElement('button');
        menu.className = 'ec-ai-session-menu';
        menu.type = 'button';
        menu.textContent = '⋯';
        menu.title = 'Rename';
        menu.addEventListener('click', e => {
          e.stopPropagation();
          currentSessionId = session.id;
          renameCurrent();
        });

        row.append(name, menu);
        row.addEventListener('click', () => openSession(session.id));
        box.appendChild(row);
      });

      if (selectLatest && !currentSessionId) {
        await openSession(data.sessions[0].id);
      } else {
        markActive();
      }
    } catch (error) {
      box.innerHTML = `<div style="font-size:10px;color:#ef4444;padding:5px">${escapeHtml(error.message)}</div>`;
    }
  }

  function markActive() {
    document.querySelectorAll('#ecAiSessions .ec-ai-session').forEach(row => {
      row.classList.toggle('active', Number(row.dataset.id) === currentSessionId);
    });
  }

  async function newSession(reload = true) {
    try {
      const data = await request('/API/ai/sessions.php', {
        method: 'POST',
        body: JSON.stringify({ title: 'New AI Conversation' })
      });
      currentSessionId = data.session.id;
      if (reload) await loadSessions(false);
      await renderSession(data.session, []);
      document.getElementById('ecAiInput')?.focus();
    } catch (error) {
      toast(error.message, 'error', '❌');
    }
  }

  async function openSession(id) {
    try {
      const data = await request('/API/ai/session.php?id=' + encodeURIComponent(id));
      currentSessionId = data.session.id;
      renderSession(data.session, data.messages);
      markActive();
    } catch (error) {
      toast(error.message, 'error', '❌');
    }
  }

  function renderSession(session, messages) {
    const title = document.getElementById('ecAiTitle');
    const log = document.getElementById('ecAiLog');
    if (!title || !log) return;

    title.textContent = session.session_title || 'AI Conversation';
    log.innerHTML = '';

    if (!messages.length) {
      log.innerHTML = '<div class="ec-ai-empty">Ask your first question. Your conversation will be saved automatically.</div>';
      return;
    }

    messages.forEach(message => appendMessage(message.role, message.content));
    log.scrollTop = log.scrollHeight;
  }

  function appendMessage(role, content) {
    const log = document.getElementById('ecAiLog');
    if (!log) return;

    const wrapper = document.createElement('div');
    wrapper.style.marginBottom = '9px';

    const label = document.createElement('div');
    label.style.cssText = 'font-size:9.5px;font-weight:700;margin-bottom:2px;';
    label.style.color = role === 'user' ? 'var(--cyan,#06b6d4)' : 'var(--purple,#a78bfa)';
    label.textContent = role === 'user' ? 'You' : 'AI Assistant';

    const bubble = document.createElement('div');
    bubble.className = 'ai-msg ' + (role === 'user' ? 'me' : 'ai') + ' ec-ai-msg';

    if (
      role === 'assistant' &&
      typeof window.renderAiMarkdownInto === 'function'
    ) {
      window.renderAiMarkdownInto(bubble, content);
    } else {
      bubble.textContent = content;
    }

    wrapper.append(label, bubble);
    log.appendChild(wrapper);
  }

  async function sendPersistentAI() {
    if (sending) return;
    const legacyInput = document.getElementById('aiInput');
    const prompt = (document.getElementById('ecAiInput') || legacyInput)?.value.trim();
    if (!prompt) return;
    sending = true;
    buildUI();
    const input = document.getElementById('ecAiInput');
    const button = document.getElementById('ecAiSend');
    if (!input) { sending = false; return; }
    input.value = prompt;
    input.disabled = true;
    if (button) { button.disabled = true; button.textContent = 'Sending…'; }
    let loading;
    try {
      await initPersistentAI();
      if (!currentSessionId) throw new Error('Could not start an AI conversation. Try New chat or reopen the assistant.');
      const log = document.getElementById('ecAiLog');
      if (log?.querySelector('.ec-ai-empty')) log.innerHTML = '';
      appendMessage('user', prompt);
      input.value = '';
      loading = document.createElement('div');
      loading.className = 'ec-ai-loading';
      loading.setAttribute('role', 'status');
      loading.textContent = 'Jarred is thinking…';
      log?.appendChild(loading);
      if (log) log.scrollTop = log.scrollHeight;
      const data = await request('/API/ai/message.php', {
        method: 'POST', body: JSON.stringify({ session_id: currentSessionId, prompt })
      });
      if (!data.message || typeof data.message.content !== 'string') throw new Error('The assistant returned an invalid response. Please try again.');
      loading.remove();
      appendMessage('assistant', data.message.content);
      await loadSessions(false);
      markActive();
    } catch (error) {
      loading?.remove();
      const log = document.getElementById('ecAiLog');
      if (log) {
        const notice = document.createElement('div');
        notice.className = 'ec-ai-error';
        notice.setAttribute('role', 'alert');
        notice.style.cssText = 'color:var(--ec-danger,#fca5a5);padding:12px;font-size:13px';
        notice.textContent = error.message;
        log.appendChild(notice);
      }
      input.value = prompt;
    } finally {
      sending = false;
      input.disabled = false;
      if (button) { button.disabled = false; button.textContent = 'Send →'; }
      input.focus();
      const log = document.getElementById('ecAiLog');
      if (log) log.scrollTop = log.scrollHeight;
    }
  }

  async function renameCurrent() {
    if (!currentSessionId) {
      toast('Open a conversation first.', 'info', 'ℹ️');
      return;
    }

    const current = document.getElementById('ecAiTitle')?.textContent || '';
    const title = window.prompt('Conversation name:', current);
    if (title === null) return;

    try {
      const data = await request('/API/ai/session.php', {
        method: 'PATCH',
        body: JSON.stringify({
          session_id: currentSessionId,
          title: title
        })
      });
      document.getElementById('ecAiTitle').textContent = data.session.session_title;
      await loadSessions(false);
    } catch (error) {
      toast(error.message, 'error', '❌');
    }
  }

  async function deleteCurrent() {
    if (!currentSessionId) {
      toast('Open a conversation first.', 'info', 'ℹ️');
      return;
    }

    if (!window.confirm('Delete this AI conversation and its messages?')) return;

    try {
      await request('/API/ai/session.php', {
        method: 'DELETE',
        body: JSON.stringify({ session_id: currentSessionId })
      });
      currentSessionId = null;
      await loadSessions(true);
      toast('AI conversation deleted.', 'success', '🗑️');
    } catch (error) {
      toast(error.message, 'error', '❌');
    }
  }

  function initPersistentAI() {
    buildUI();
    if (!initialized) return Promise.resolve();
    if (!sessionLoad) sessionLoad = loadSessions(true).finally(() => { sessionLoad = null; });
    return sessionLoad;
  }

  // Preserve the existing dashboard entry points.
  window.initPersistentAI = initPersistentAI;
  window.sendAI = sendPersistentAI;
  window.aiQP = function (prompt) {
    buildUI();
    const input = document.getElementById('ecAiInput');
    if (input) {
      input.value = prompt;
      sendPersistentAI();
    }
  };

  function watchModal() {
    const modal = getModal();
    if (!modal) return;
    // The dashboards currently call openModal('aiModal'). Observe visibility
    // changes so existing buttons keep working without a full dashboard rewrite.
    const observer = new MutationObserver(() => {
      const visible = modal.classList.contains('show') || modal.classList.contains('open') || modal.style.display === 'flex';
      if (visible) initPersistentAI();
    });
    observer.observe(modal, { attributes: true, attributeFilter: ['class', 'style'] });
    if (modal.classList.contains('show') || modal.classList.contains('open') || modal.style.display === 'flex') initPersistentAI();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watchModal, { once: true });
  else watchModal();
})();

