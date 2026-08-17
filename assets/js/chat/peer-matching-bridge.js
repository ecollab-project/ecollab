/* Ecollab Phase 4.6 — peer matching integration bridge.
 * Uses the canonical Phase 4.6 endpoints instead of the legacy peer-match.php contract.
 */
(function () {
  'use strict';

  const state = { matches: [], loading: false };
  const base = () => String(window.ECOLLAB?.baseUrl || window.ECOLLAB_BASE || '').replace(/\/$/, '');
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || window.ECOLLAB?.csrfToken || '';
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

  async function getMatches() {
    const res = await fetch(base() + '/API/chat/get-matches.php', { credentials:'same-origin', headers:{Accept:'application/json'} });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Unable to load study buddy matches.');
    return data;
  }

  async function connect(id, name, button) {
    if (!id || !button) return;
    button.disabled = true; button.textContent = 'Sending…';
    try {
      const res = await fetch(base() + '/API/chat/peer-request.php', {
        method:'POST', credentials:'same-origin',
        headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},
        body:JSON.stringify({user_id:id})
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || 'Unable to send connection request.');
      button.textContent = '✓ Sent';
      if (window.toast) window.toast('Connection request sent to ' + name, 'success', '👫');
    } catch (e) {
      button.disabled = false; button.textContent = 'Connect';
      if (window.toast) window.toast(e.message || 'Unable to connect.', 'error', '❌');
    }
  }

  function matchCard(m) {
    const pct = Math.max(0, Math.min(100, Number(m.pct) || 0));
    const name = esc(m.name || 'Study Buddy');
    const grad = esc(m.grad || '#a855f7,#ec4899');
    const tags = Array.isArray(m.tags) ? m.tags.slice(0,4) : [];
    const c = m.components || {};
    const id = Number(m.id) || 0;
    return `<div class="pm-card" data-uid="${id}">
      <div class="pm-card-top"><div class="pm-avatar" style="background:linear-gradient(135deg,${grad})">
        ${esc((m.name || '?').slice(0,2).toUpperCase())}
        ${m.online ? '<span class="pm-online-dot"></span>' : ''}</div><div class="pm-card-info">
        <div class="pm-card-name">${name}</div><div class="pm-card-bio">${esc(m.detail || 'Student')}</div></div><div class="pm-score-circle" style="background:conic-gradient(#a855f7 ${pct}%, var(--bg-tertiary) 0)"><div class="pm-score-inner">${pct}<span style="font-size:10px">%</span></div></div></div>
        <div class="pm-score-bars"><div class="pm-score-row"><span class="pm-score-label">Subjects</span><div class="pm-score-track"><div class="pm-score-fill" style="width:${Math.round(c.subjects || 0)}%;background:#3b82f6"></div></div><span class="pm-score-num">${Math.round(c.subjects || 0)}</span></div><div class="pm-score-row"><span class="pm-score-label">Style</span><div class="pm-score-track"><div class="pm-score-fill" style="width:${Math.round(c.style || 0)}%;background:#a855f7"></div></div><span class="pm-score-num">${Math.round(c.style || 0)}</span></div><div class="pm-score-row"><span class="pm-score-label">Interests</span><div class="pm-score-track"><div class="pm-score-fill" style="width:${Math.round(c.interests || 0)}%;background:#f59e0b"></div></div><span class="pm-score-num">${Math.round(c.interests || 0)}</span></div><div class="pm-score-row"><span class="pm-score-label">Hobbies</span><div class="pm-score-track"><div class="pm-score-fill" style="width:${Math.round(c.hobbies || 0)}%;background:#22c55e"></div></div><span class="pm-score-num">${Math.round(c.hobbies || 0)}</span></div></div>
        ${tags.length ? `<div class="pm-tag-strip">${tags.map(t=>`<span class="pm-tag">${esc(t)}</span>`).join('')}</div>` : ''}
        <div class="pm-card-actions"><button type="button" class="pm-btn-secondary peer-match-message" data-peer-name="${name}">Message</button><button type="button" class="pm-btn-primary peer-match-connect" data-peer-id="${id}" data-peer-name="${name}">Connect</button></div>
      </div>
    </div>`;
  }

  function renderModal(body, data) {
    if (!data.matches.length) {
      body.innerHTML = `<div class="pm-empty"><div style="font-size:32px;margin-bottom:10px">👥</div><div style="font-size:14px;font-weight:600;margin-bottom:6px">${data.profile_ready === false ? 'Complete your profile first' : 'No matches yet'}</div><div style="font-size:12px;color:var(--text-muted)">${data.profile_ready === false ? 'Add at least one subject, interest, or hobby to start receiving study-buddy recommendations.' : 'No other compatible study buddies are available yet.'}</div></div>`;
      return;
    }
    body.innerHTML = data.matches.map(matchCard).join('');
  }

  async function loadModal() {
    const modal = document.getElementById('peerMatchingModal');
    const body = document.getElementById('pmModalBody');
    if (!modal || !body || state.loading) return;
    state.loading = true;
    body.innerHTML = '<div class="pm-loading"><div class="collab-spinner"></div><span>Finding your best study partners…</span></div>';
    try { const data = await getMatches(); state.matches = data.matches || []; renderModal(body, data); }
    catch (e) { body.innerHTML = `<div class="pm-error">⚠ ${esc(e.message)}</div>`; }
    finally { state.loading = false; }
  }

  function openModal() {
    const m = document.getElementById('peerMatchingModal');
    if (!m) return;
    m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open')); loadModal();
  }

  window.openPeerMatchingModal = openModal;
  window.closePeerMatchingModal = function () { const m=document.getElementById('peerMatchingModal'); if(m){m.classList.remove('modal-open');setTimeout(()=>m.style.display='none',240);} };

  document.addEventListener('click', function (e) {
    const msg = e.target.closest('.peer-match-message');
    if (msg && window.openModal) { window.openModal('dmModal', msg.dataset.peerName || 'Study Buddy'); return; }
    const btn = e.target.closest('.peer-match-connect');
    if (btn) connect(Number(btn.dataset.peerId), btn.dataset.peerName || 'Study Buddy', btn);
  });

  // The existing right-sidebar widget calls refreshMatches(). Keep that API,
  // but make empty/no-profile states explicit instead of leaving a blank box.
  const originalRefresh = window.refreshMatches;
  window.refreshMatches = async function (btn) {
    if (btn) { btn.disabled = true; btn.classList.add('spinning'); }
    try {
      const data = await getMatches();
      state.matches = data.matches || [];
      const list = document.getElementById('matchesList');
      if (list) {
        if (!state.matches.length) {
          list.innerHTML = `<div style="font-size:11px;color:var(--text-muted);padding:10px 0;line-height:1.5;">${data.profile_ready === false ? 'Complete your profile to get AI study-buddy suggestions.' : 'No compatible study buddies yet.'}</div>`;
        } else {
          list.innerHTML = state.matches.slice(0,3).map(m => `<div class="match-item" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);"><div style="position:relative;width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,${esc(m.grad || '#a855f7,#ec4899')});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;">${esc((m.name||'?').slice(0,1).toUpperCase())}</div><div style="flex:1;min-width:0;"><div style="font-size:12px;font-weight:600;color:var(--text-primary);">${esc(m.name)}</div><div style="font-size:11px;color:var(--text-muted);">${esc(m.detail || 'Student')}</div><div style="font-size:11px;color:#a855f7;font-weight:600;">${Math.round(m.pct || 0)}% match</div></div><button type="button" onclick="openSendRequestModal?.(${Number(m.id)},'${esc(m.name)}',${Math.round(m.pct || 0)})" style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:#c084fc;cursor:pointer;font-family:inherit;font-weight:600;">Connect</button></div>`).join('');
        }
      }
    } catch (e) {
      if (typeof originalRefresh === 'function') return originalRefresh(btn);
      const list = document.getElementById('matchesList');
      if (list) list.innerHTML = '<div style="font-size:11px;color:#ef4444;padding:10px 0;">Unable to load matches.</div>';
    } finally { if (btn) { btn.disabled=false; btn.classList.remove('spinning'); } }
  };

  document.addEventListener('DOMContentLoaded', function () { setTimeout(() => window.refreshMatches?.(), 150); });
})();
