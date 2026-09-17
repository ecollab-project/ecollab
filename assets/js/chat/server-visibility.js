/* Server visibility controls for Chat workspace creation. */
(function () {
  'use strict';

  const BASE = () => window.ECOLLAB?.baseUrl || '';
  const CSRF = () => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  function modal() {
    let el = document.getElementById('serverVisibilityCreateModal');
    if (el) return el;

    el = document.createElement('div');
    el.id = 'serverVisibilityCreateModal';
    el.innerHTML = `
      <div class="svc-box" role="dialog" aria-modal="true" aria-labelledby="svcTitle">
        <div class="svc-head">
          <div>
            <div id="svcTitle" class="svc-title">Create a server</div>
            <div class="svc-subtitle">Set who can discover and enter it.</div>
          </div>
          <button type="button" class="svc-close" id="svcClose" aria-label="Close">×</button>
        </div>
        <div class="svc-body">
          <label class="svc-label" for="svcName">Server name</label>
          <input id="svcName" class="svc-input" type="text" maxlength="80" autocomplete="off" placeholder="e.g. BSIT Study Group">

          <label class="svc-label" for="svcTemplate">Template</label>
          <select id="svcTemplate" class="svc-input">
            <option value="custom">Custom</option>
            <option value="study-group">Study Group</option>
            <option value="research">Research</option>
            <option value="gaming">Gaming</option>
          </select>

          <div class="svc-label">Visibility</div>
          <div class="svc-options">
            <label class="svc-option selected" data-value="public">
              <input type="radio" name="svcVisibility" value="public" checked>
              <span class="svc-radio"></span>
              <span><strong>Public</strong><small>Appears in public recommendations and keeps the normal public join flow.</small></span>
            </label>
            <label class="svc-option" data-value="private">
              <input type="radio" name="svcVisibility" value="private">
              <span class="svc-radio"></span>
              <span><strong>Private 🔒</strong><small>Hidden from public recommendations. Access requires membership through an invite, owner/facilitator grant, or supported join mechanism.</small></span>
            </label>
          </div>

          <div id="svcError" class="svc-error" hidden></div>
        </div>
        <div class="svc-foot">
          <button type="button" class="svc-btn secondary" id="svcCancel">Cancel</button>
          <button type="button" class="svc-btn primary" id="svcCreate">Create server</button>
        </div>
      </div>`;

    const style = document.createElement('style');
    style.id = 'serverVisibilityCreateStyles';
    style.textContent = `
      #serverVisibilityCreateModal{position:fixed;inset:0;z-index:120000;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.74);backdrop-filter:blur(5px)}
      #serverVisibilityCreateModal .svc-box{width:min(520px,92vw);background:#111827;color:#f8fafc;border:1px solid rgba(148,163,184,.16);border-radius:16px;box-shadow:0 25px 90px rgba(0,0,0,.55);font-family:Inter,system-ui,sans-serif}
      #serverVisibilityCreateModal .svc-head{display:flex;align-items:flex-start;justify-content:space-between;padding:18px 20px;border-bottom:1px solid rgba(148,163,184,.1)}
      #serverVisibilityCreateModal .svc-title{font-size:16px;font-weight:800}.svc-subtitle{font-size:11px;color:#94a3b8;margin-top:3px}.svc-close{border:0;background:transparent;color:#94a3b8;font-size:24px;line-height:1;cursor:pointer;padding:0 2px}.svc-close:hover{color:#f8fafc}
      #serverVisibilityCreateModal .svc-body{padding:18px 20px}.svc-label{display:block;font-size:12px;font-weight:700;color:#cbd5e1;margin:0 0 7px}.svc-input{width:100%;box-sizing:border-box;height:40px;margin:0 0 15px;padding:0 11px;border:1px solid #334155;border-radius:9px;background:#0f172a;color:#f8fafc;color-scheme:dark;font:500 12px Inter,system-ui,sans-serif;outline:none}.svc-input:focus{border-color:#8b5cf6;box-shadow:0 0 0 2px rgba(139,92,246,.14)}
      .svc-options{display:grid;gap:8px}.svc-option{display:flex;gap:10px;align-items:flex-start;padding:11px;border:1px solid rgba(148,163,184,.14);border-radius:10px;background:#0f172a;cursor:pointer}.svc-option:hover{border-color:rgba(168,85,247,.35)}.svc-option.selected{border-color:rgba(168,85,247,.55);background:rgba(168,85,247,.07)}.svc-option input{position:absolute;opacity:0;pointer-events:none}.svc-radio{width:15px;height:15px;border:1px solid #64748b;border-radius:50%;margin-top:2px;flex:0 0 auto;position:relative}.svc-option.selected .svc-radio{border-color:#a855f7}.svc-option.selected .svc-radio:after{content:"";position:absolute;inset:3px;border-radius:50%;background:#a855f7}.svc-option strong{display:block;font-size:12px}.svc-option small{display:block;color:#94a3b8;font-size:10px;line-height:1.45;margin-top:3px}.svc-error{margin-top:12px;padding:9px 10px;border:1px solid rgba(248,113,113,.3);border-radius:8px;background:rgba(127,29,29,.14);color:#fca5a5;font-size:11px}.svc-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid rgba(148,163,184,.1)}.svc-btn{height:36px;padding:0 14px;border-radius:8px;font:700 12px Inter,system-ui,sans-serif;cursor:pointer}.svc-btn.secondary{border:1px solid #334155;background:#172033;color:#cbd5e1}.svc-btn.primary{border:1px solid rgba(168,85,247,.55);background:#7c3aed;color:#fff}.svc-btn:disabled{opacity:.55;cursor:wait}
    `;
    document.head.appendChild(style);
    document.body.appendChild(el);

    el.querySelector('#svcClose').onclick = close;
    el.querySelector('#svcCancel').onclick = close;
    el.addEventListener('click', event => { if (event.target === el) close(); });
    el.querySelectorAll('input[name="svcVisibility"]').forEach(input => {
      input.addEventListener('change', () => {
        el.querySelectorAll('.svc-option').forEach(o => o.classList.toggle('selected', o.dataset.value === input.value));
      });
    });
    el.querySelector('#svcCreate').onclick = create;
    el.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    return el;
  }

  function open() {
    const el = modal();
    el.style.display = 'flex';
    el.querySelector('#svcError').hidden = true;
    el.querySelector('#svcName').focus();
  }

  function close() {
    const el = document.getElementById('serverVisibilityCreateModal');
    if (el) el.style.display = 'none';
  }

  async function create() {
    const el = modal();
    const name = el.querySelector('#svcName').value.trim();
    const template = el.querySelector('#svcTemplate').value;
    const type = el.querySelector('input[name="svcVisibility"]:checked')?.value || 'public';
    const error = el.querySelector('#svcError');
    const button = el.querySelector('#svcCreate');

    error.hidden = true;
    if (!name) {
      error.textContent = 'Server name is required.';
      error.hidden = false;
      return;
    }

    button.disabled = true;
    button.textContent = 'Creating…';
    try {
      const response = await fetch(`${BASE()}/API/server/create-server.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF()},
        body: JSON.stringify({name, template, type})
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.success === false) throw new Error(data.error || 'Unable to create server.');

      close();
      const target = new URL(window.location.href);
      target.search = `?server_id=${encodeURIComponent(data.server_id)}`;
      window.location.href = target.toString();
    } catch (errorValue) {
      error.textContent = errorValue.message || 'Unable to create server.';
      error.hidden = false;
    } finally {
      button.disabled = false;
      button.textContent = 'Create server';
    }
  }

  function install() {
    if (!document.querySelector('.workspace-add')) return false;
    if (window.__ecollabServerVisibilityInstalled) return true;
    window.__ecollabServerVisibilityInstalled = true;

    document.addEventListener('click', event => {
      const add = event.target.closest?.('.workspace-add');
      if (!add) return;
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      open();
    }, true);
    return true;
  }

  function boot() {
    if (install()) return;
    let tries = 0;
    const timer = setInterval(() => {
      if (install() || ++tries >= 20) clearInterval(timer);
    }, 250);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
