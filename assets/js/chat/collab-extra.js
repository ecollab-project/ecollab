/**
 * collab-extra.js — 6 additional collaboration tools for Ecollab
 *
 * Tools: Flashcards | Mind Map | Peer Review | Chat Summary | Study Goals | Resource Library
 * All talk to /API/collab/collab-extra.php
 */

'use strict';

const EXTRA_API = (window.ECOLLAB?.baseUrl || '') + '/API/collab/collab-extra.php';

/* ── shared fetch ────────────────────────────────────────────────────────── */
async function extraFetch(tool, action, body = {}, method = 'POST') {
  const channelId = window.ECOLLAB?.currentChannelId;
  if (!channelId) { if (window.showToast) showToast('Open a channel first', 'info'); throw new Error('No channel'); }
  const url  = `${EXTRA_API}?tool=${tool}&action=${action}&channel_id=${channelId}`;
  const opts = {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' },
  };
  if (method !== 'GET') opts.body = JSON.stringify({ ...body, channel_id: channelId });
  const res  = await fetch(url, opts);
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function escX(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── tool loader called by collab-tools.js _loadCollabTool ──────────────── */
window._loadExtraTool = function(tool) {
  switch (tool) {
    case 'flashcards': loadFlashcards(); break;
    case 'mindmap':    loadMindMap();    break;
    case 'review':     loadReviewList(); break;
    case 'summary':    loadSummaryList(); break;
    case 'goals':      loadGoals();      break;
    case 'resources':  loadResources();  break;
  }
};

/* WS callbacks for extra tools */
window._collabExtraOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  const paneMap = {
    collab_flashcards_updated: 'flashcards',
    collab_mindmap_updated:    'mindmap',
    collab_review_created:     'review',
    collab_review_feedback:    'review',
    collab_summary_ready:      'summary',
    collab_goal_created:       'goals',
    collab_goal_updated:       'goals',
    collab_resource_added:     'resources',
    collab_resource_commented: 'resources',
  };
  const tool = paneMap[data.type];
  if (tool && window._collabActive === tool && window._collabOpen) window._loadExtraTool(tool);
  if (window.showToast && data.actor && data.actor !== window.ECOLLAB?.username) {
    const toastMap = {
      collab_flashcards_updated: `🃏 ${data.actor} updated flashcards`,
      collab_mindmap_updated:    `🧠 ${data.actor} updated the mind map`,
      collab_review_created:     `📋 ${data.actor} posted a review request: ${data.title || ''}`,
      collab_review_feedback:    `💬 ${data.actor} left feedback on a review`,
      collab_summary_ready:      `✨ ${data.actor} generated a chat summary`,
      collab_goal_created:       `🎯 ${data.actor} added a goal: ${data.title || ''}`,
      collab_goal_updated:       `🎯 ${data.actor} updated goal progress`,
      collab_resource_added:     `📚 ${data.actor} added a resource: ${data.title || ''}`,
      collab_resource_commented: `💬 ${data.actor} commented on a resource`,
    };
    if (toastMap[data.type]) showToast(toastMap[data.type], 'info');
  }
};

/* ─────────────────────────────────────────────────────────────────────────────
   1. FLASHCARDS
   ───────────────────────────────────────────────────────────────────────────── */
let _activeDeckId   = null;
let _flashcardIndex = 0;
let _flashcards     = [];
let _cardFlipped    = false;

async function loadFlashcards() {
  const pane = document.getElementById('collabPane_flashcards');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { decks } = await extraFetch('flashcards', 'list_decks', {}, 'GET');
    pane.innerHTML = `
      <div class="fc-header">
        <span class="collab-section-title">🃏 Flashcard Decks</span>
        <button class="collab-btn-sm" onclick="openCreateDeckModal()">+ New Deck</button>
      </div>
      <div class="fc-deck-list">
        ${decks.length ? decks.map(d => `
          <div class="fc-deck-card">
            <div class="fc-deck-info">
              <div class="fc-deck-title">${escX(d.title)}</div>
              <div class="fc-deck-meta">${d.card_count} cards · by ${escX(d.creator)}</div>
              ${d.description ? `<div class="fc-deck-desc">${escX(d.description)}</div>` : ''}
            </div>
            <div class="fc-deck-actions">
              <button class="collab-btn-sm" onclick="startStudySession(${d.id})">Study</button>
              <button class="collab-btn-xs" onclick="openAddCardModal(${d.id})">+ Card</button>
            </div>
          </div>`).join('') : `<div class="collab-empty">No decks yet. Create one to start studying!</div>`}
      </div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.loadFlashcards = loadFlashcards;

async function startStudySession(deckId) {
  _activeDeckId = deckId;
  const pane = document.getElementById('collabPane_flashcards');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { deck } = await extraFetch('flashcards', 'get_deck', { deck_id: deckId }, 'GET');
    _flashcards     = deck.cards || [];
    _flashcardIndex = 0;
    _cardFlipped    = false;
    if (!_flashcards.length) {
      pane.innerHTML = `<div class="collab-empty">No cards in this deck.<br>
        <button class="collab-btn-sm" style="margin-top:10px" onclick="openAddCardModal(${deckId})">Add First Card</button></div>`;
      return;
    }
    _renderFlashcard(pane, deck.title);
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.startStudySession = startStudySession;

function _renderFlashcard(pane, deckTitle) {
  const card  = _flashcards[_flashcardIndex];
  const total = _flashcards.length;
  pane.innerHTML = `
    <div class="fc-session-header">
      <button class="collab-btn-xs" onclick="loadFlashcards()">← Decks</button>
      <span class="fc-progress-label">${_flashcardIndex + 1} / ${total}</span>
      <span class="fc-deck-name">${escX(deckTitle)}</span>
    </div>
    <div class="fc-progress-bar"><div class="fc-progress-fill" style="width:${((_flashcardIndex+1)/total)*100}%"></div></div>
    <div class="fc-card-wrap">
      <div class="fc-card ${_cardFlipped ? 'fc-flipped' : ''}" onclick="flipCard()" id="fcCard">
        <div class="fc-card-face fc-card-front">
          <div class="fc-face-label">QUESTION</div>
          <div class="fc-card-text">${escX(card.front)}</div>
          ${card.hint ? `<div class="fc-hint">💡 ${escX(card.hint)}</div>` : ''}
          <div class="fc-flip-hint">Click to reveal answer →</div>
        </div>
        <div class="fc-card-face fc-card-back">
          <div class="fc-face-label">ANSWER</div>
          <div class="fc-card-text">${escX(card.back)}</div>
        </div>
      </div>
    </div>
    <div class="fc-controls ${_cardFlipped ? '' : 'fc-controls-hidden'}">
      <div class="fc-rating-label">How well did you know this?</div>
      <div class="fc-rating-row">
        <button class="fc-rating-btn fc-hard"  onclick="rateCard(1)">😓 Hard</button>
        <button class="fc-rating-btn fc-ok"    onclick="rateCard(2)">😐 OK</button>
        <button class="fc-rating-btn fc-easy"  onclick="rateCard(3)">😊 Easy</button>
      </div>
    </div>
    <div class="fc-nav-row">
      <button class="collab-btn-xs" onclick="fcPrev()" ${_flashcardIndex===0?'disabled':''}>‹ Prev</button>
      <button class="collab-btn-xs" onclick="fcNext()" ${_flashcardIndex===total-1?'disabled':''}>Next ›</button>
    </div>`;
}

function flipCard() {
  _cardFlipped = !_cardFlipped;
  const card     = document.getElementById('fcCard');
  const controls = document.querySelector('.fc-controls');
  if (card)     card.classList.toggle('fc-flipped', _cardFlipped);
  if (controls) controls.classList.toggle('fc-controls-hidden', !_cardFlipped);
}
window.flipCard = flipCard;

async function rateCard(rating) {
  const card = _flashcards[_flashcardIndex];
  await extraFetch('flashcards', 'rate_card', { card_id: card.id, rating }).catch(() => {});
  fcNext();
}
window.rateCard = rateCard;

function fcNext() {
  if (_flashcardIndex < _flashcards.length - 1) {
    _flashcardIndex++; _cardFlipped = false;
    _renderFlashcard(document.getElementById('collabPane_flashcards'), '');
  } else {
    if (window.showToast) showToast('🎉 Deck complete!', 'success');
    loadFlashcards();
  }
}
function fcPrev() {
  if (_flashcardIndex > 0) { _flashcardIndex--; _cardFlipped = false; _renderFlashcard(document.getElementById('collabPane_flashcards'), ''); }
}
window.fcNext = fcNext; window.fcPrev = fcPrev;

function openCreateDeckModal() {
  const m = document.getElementById('createDeckModal');
  if (m) { m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open')); }
}
window.openCreateDeckModal = openCreateDeckModal;
function closeCreateDeckModal() {
  const m = document.getElementById('createDeckModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeCreateDeckModal = closeCreateDeckModal;

async function submitCreateDeck() {
  const title = document.getElementById('deckTitleInput')?.value?.trim();
  if (!title) { if (window.showToast) showToast('Title required', 'info'); return; }
  const desc  = document.getElementById('deckDescInput')?.value?.trim();
  const cardsRaw = document.getElementById('deckCardsInput')?.value?.trim() || '';
  const cards = cardsRaw.split('\n').map(l => l.split('|')).filter(p => p.length >= 2)
    .map(([front, back, hint]) => ({ front: front.trim(), back: back.trim(), hint: (hint || '').trim() }));
  try {
    await extraFetch('flashcards', 'create_deck', { title, description: desc, cards });
    closeCreateDeckModal();
    loadFlashcards();
    if (window.showToast) showToast('🃏 Deck created!', 'success');
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitCreateDeck = submitCreateDeck;

function openAddCardModal(deckId) {
  _activeDeckId = deckId;
  const m = document.getElementById('addCardModal');
  if (m) { m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open')); }
}
window.openAddCardModal = openAddCardModal;
function closeAddCardModal() {
  const m = document.getElementById('addCardModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeAddCardModal = closeAddCardModal;

async function submitAddCard() {
  const front = document.getElementById('cardFrontInput')?.value?.trim();
  const back  = document.getElementById('cardBackInput')?.value?.trim();
  if (!front || !back) { if (window.showToast) showToast('Front and back required', 'info'); return; }
  const hint  = document.getElementById('cardHintInput')?.value?.trim();
  try {
    await extraFetch('flashcards', 'add_card', { deck_id: _activeDeckId, front, back, hint });
    closeAddCardModal();
    if (window.showToast) showToast('🃏 Card added!', 'success');
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitAddCard = submitAddCard;

/* ─────────────────────────────────────────────────────────────────────────────
   2. MIND MAP
   ───────────────────────────────────────────────────────────────────────────── */
let _mmGraph   = { nodes: [], edges: [] };
let _mmVersion = 0;
let _mmDrag    = null;
let _mmCanvas  = null;
let _mmCtx     = null;
let _mmSaveTimer = null;

async function loadMindMap() {
  const pane = document.getElementById('collabPane_mindmap');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { map } = await extraFetch('mindmap', 'get', {}, 'GET');
    _mmVersion = parseInt(map.version) || 0;
    _mmGraph   = JSON.parse(map.graph_json || '{"nodes":[],"edges":[]}');
    _renderMindMap(pane, map.title);
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.loadMindMap = loadMindMap;

function _renderMindMap(pane, title) {
  pane.innerHTML = `
    <div class="mm-toolbar">
      <input id="mmTitle" class="mm-title-input" value="${escX(title)}" placeholder="Map title…"/>
      <button class="collab-btn-xs" onclick="mmAddNode()">+ Node</button>
      <button class="collab-btn-xs collab-btn-run" onclick="mmSave(true)">💾 Save</button>
      <button class="collab-btn-xs" onclick="mmReset()">↺ Reset view</button>
      <span id="mmStatus" class="notes-status" style="margin-left:auto">Saved</span>
    </div>
    <canvas id="mmCanvas" class="mm-canvas" tabindex="0"></canvas>
    <div class="mm-legend">Click canvas to select · Double-click node to edit · Drag to move · Del to remove selected</div>`;

  _mmCanvas = document.getElementById('mmCanvas');
  _mmCtx    = _mmCanvas.getContext('2d');
  _mmResizeCanvas();
  _mmDrawAll();
  _mmAttachEvents();
}

let _mmSelected = null;
let _mmOffset   = { x: 0, y: 0 };
const NODE_R    = 46;
const NODE_COLORS = ['#a855f7','#3b82f6','#22c55e','#f59e0b','#ef4444','#06b6d4','#ec4899','#84cc16'];

function _mmResizeCanvas() {
  if (!_mmCanvas) return;
  const rect = _mmCanvas.parentElement.getBoundingClientRect();
  _mmCanvas.width  = rect.width  || 440;
  _mmCanvas.height = rect.height - 80 || 360;
  _mmDrawAll();
}

function _mmDrawAll() {
  if (!_mmCtx || !_mmCanvas) return;
  const ctx = _mmCtx;
  ctx.clearRect(0, 0, _mmCanvas.width, _mmCanvas.height);
  const cx = _mmCanvas.width / 2, cy = _mmCanvas.height / 2;

  // Edges
  ctx.strokeStyle = 'rgba(168,85,247,0.35)';
  ctx.lineWidth   = 2;
  for (const e of _mmGraph.edges) {
    const src = _mmGraph.nodes.find(n => n.id === e.src);
    const dst = _mmGraph.nodes.find(n => n.id === e.dst);
    if (!src || !dst) continue;
    ctx.beginPath();
    ctx.moveTo(cx + src.x, cy + src.y);
    ctx.lineTo(cx + dst.x, cy + dst.y);
    ctx.stroke();
  }

  // Nodes
  for (const node of _mmGraph.nodes) {
    const nx = cx + node.x, ny = cy + node.y;
    const isSelected = _mmSelected === node.id;

    // Shadow
    ctx.shadowColor = node.color || '#a855f7';
    ctx.shadowBlur  = isSelected ? 18 : 6;
    ctx.fillStyle   = node.color || '#a855f7';
    ctx.beginPath();
    ctx.ellipse(nx, ny, NODE_R + (isSelected ? 4 : 0), (NODE_R * 0.6) + (isSelected ? 2 : 0), 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.shadowBlur = 0;

    // Border
    if (isSelected) {
      ctx.strokeStyle = '#fff';
      ctx.lineWidth   = 2.5;
      ctx.beginPath();
      ctx.ellipse(nx, ny, NODE_R + 4, NODE_R * 0.6 + 2, 0, 0, Math.PI * 2);
      ctx.stroke();
    }

    // Label
    ctx.fillStyle   = '#fff';
    ctx.font        = node.root ? 'bold 13px sans-serif' : '12px sans-serif';
    ctx.textAlign   = 'center';
    ctx.textBaseline = 'middle';
    const label = (node.label || '').length > 16 ? node.label.slice(0, 14) + '…' : node.label;
    ctx.fillText(label, nx, ny);
  }
}

function _mmNodeAt(mx, my) {
  const cx = _mmCanvas.width / 2, cy = _mmCanvas.height / 2;
  for (const node of [..._mmGraph.nodes].reverse()) {
    const dx = (cx + node.x) - mx, dy = (cy + node.y) - my;
    if ((dx * dx) / ((NODE_R + 4) * (NODE_R + 4)) + (dy * dy) / ((NODE_R * 0.6 + 2) * (NODE_R * 0.6 + 2)) <= 1)
      return node;
  }
  return null;
}

function _mmAttachEvents() {
  if (!_mmCanvas) return;

  _mmCanvas.addEventListener('mousedown', e => {
    const rect = _mmCanvas.getBoundingClientRect();
    const mx = e.clientX - rect.left, my = e.clientY - rect.top;
    const node = _mmNodeAt(mx, my);
    if (node) {
      _mmSelected = node.id;
      _mmDrag     = { nodeId: node.id, startX: mx - node.x - _mmCanvas.width / 2, startY: my - node.y - _mmCanvas.height / 2 };
    } else {
      _mmSelected = null;
    }
    _mmDrawAll();
  });

  _mmCanvas.addEventListener('mousemove', e => {
    if (!_mmDrag) return;
    const rect = _mmCanvas.getBoundingClientRect();
    const mx   = e.clientX - rect.left, my = e.clientY - rect.top;
    const node = _mmGraph.nodes.find(n => n.id === _mmDrag.nodeId);
    if (node) {
      node.x = mx - _mmDrag.startX - _mmCanvas.width / 2;
      node.y = my - _mmDrag.startY - _mmCanvas.height / 2;
      _mmDrawAll();
    }
  });

  _mmCanvas.addEventListener('mouseup', () => {
    if (_mmDrag) { _mmDrag = null; _mmScheduleSave(); }
  });

  _mmCanvas.addEventListener('dblclick', e => {
    const rect = _mmCanvas.getBoundingClientRect();
    const node = _mmNodeAt(e.clientX - rect.left, e.clientY - rect.top);
    if (node) {
      const newLabel = prompt('Edit node label:', node.label);
      if (newLabel !== null && newLabel.trim()) {
        node.label = newLabel.trim().slice(0, 40);
        _mmDrawAll();
        _mmScheduleSave();
      }
    }
  });

  _mmCanvas.addEventListener('keydown', e => {
    if ((e.key === 'Delete' || e.key === 'Backspace') && _mmSelected) {
      if (_mmGraph.nodes.find(n => n.id === _mmSelected)?.root) return;
      _mmGraph.nodes  = _mmGraph.nodes.filter(n => n.id !== _mmSelected);
      _mmGraph.edges  = _mmGraph.edges.filter(ed => ed.src !== _mmSelected && ed.dst !== _mmSelected);
      _mmSelected = null;
      _mmDrawAll();
      _mmScheduleSave();
    }
  });

  window.addEventListener('resize', _mmResizeCanvas);
}

function mmAddNode() {
  const label  = prompt('Node label:');
  if (!label?.trim()) return;
  const id     = 'n' + Date.now();
  const color  = NODE_COLORS[_mmGraph.nodes.length % NODE_COLORS.length];
  const angle  = Math.random() * Math.PI * 2;
  const radius = 120 + Math.random() * 80;
  _mmGraph.nodes.push({ id, label: label.trim().slice(0, 40), x: Math.cos(angle) * radius, y: Math.sin(angle) * radius, color });
  // Auto-connect to selected or root
  const parent = _mmSelected || (_mmGraph.nodes.find(n => n.root)?.id);
  if (parent && parent !== id) _mmGraph.edges.push({ src: parent, dst: id });
  _mmDrawAll();
  _mmScheduleSave();
}
window.mmAddNode = mmAddNode;

function mmReset() { _mmGraph.nodes.forEach(n => { n.x = 0; n.y = 0; }); _mmDrawAll(); }
window.mmReset = mmReset;

function _mmScheduleSave() {
  const s = document.getElementById('mmStatus');
  if (s) s.textContent = 'Unsaved…';
  clearTimeout(_mmSaveTimer);
  _mmSaveTimer = setTimeout(() => mmSave(false), 1500);
}

async function mmSave(manual = false) {
  clearTimeout(_mmSaveTimer);
  const title   = document.getElementById('mmTitle')?.value?.trim() || 'Mind Map';
  const s       = document.getElementById('mmStatus');
  try {
    const res = await extraFetch('mindmap', 'save', { graph_json: JSON.stringify(_mmGraph), title, version: _mmVersion });
    _mmVersion = res.version;
    if (s) s.textContent = 'Saved ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    if (manual && window.showToast) showToast('🧠 Mind map saved', 'success');
  } catch (e) {
    if (s) s.textContent = '⚠ ' + e.message;
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window.mmSave = mmSave;

/* ─────────────────────────────────────────────────────────────────────────────
   3. PEER REVIEW
   ───────────────────────────────────────────────────────────────────────────── */
async function loadReviewList() {
  const pane = document.getElementById('collabPane_review');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { requests } = await extraFetch('review', 'list', {}, 'GET');
    pane.innerHTML = `
      <div class="review-header">
        <span class="collab-section-title">📋 Peer Reviews</span>
        <button class="collab-btn-sm" onclick="openCreateReviewModal()">+ Request Review</button>
      </div>
      <div class="review-list">
        ${requests.length ? requests.map(r => `
          <div class="review-card ${r.state === 'closed' ? 'review-closed' : ''}">
            <div class="review-card-left">
              <div class="review-card-title">${escX(r.title)}</div>
              <div class="review-card-meta">by ${escX(r.author)} · ${r.state === 'closed' ? '🔒 Closed' : '🟢 Open'}</div>
            </div>
            <button class="collab-btn-xs collab-btn-run" onclick="openReviewDetail(${r.id})">View →</button>
          </div>`).join('') : `<div class="collab-empty">No review requests yet.</div>`}
      </div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.loadReviewList = loadReviewList;

function openCreateReviewModal() {
  const m = document.getElementById('createReviewModal');
  if (m) { m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open')); }
}
window.openCreateReviewModal = openCreateReviewModal;
function closeCreateReviewModal() {
  const m = document.getElementById('createReviewModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeCreateReviewModal = closeCreateReviewModal;

async function submitCreateReview() {
  const title   = document.getElementById('reviewTitleInput')?.value?.trim();
  const content = document.getElementById('reviewContentInput')?.value?.trim();
  const fileUrl = document.getElementById('reviewFileUrl')?.value?.trim();
  if (!title) { if (window.showToast) showToast('Title required', 'info'); return; }
  try {
    await extraFetch('review', 'create', { title, content, file_url: fileUrl });
    closeCreateReviewModal();
    loadReviewList();
    if (window.showToast) showToast('📋 Review request posted!', 'success');
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitCreateReview = submitCreateReview;

async function openReviewDetail(requestId) {
  const m = document.getElementById('reviewDetailModal');
  if (!m) return;
  m.innerHTML = `<div class="modal-box" style="max-width:600px;width:94%"><div class="collab-loading" style="padding:40px"><div class="collab-spinner"></div></div></div>`;
  m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open'));
  try {
    const { request } = await extraFetch('review', 'get', { request_id: requestId }, 'GET');
    const myUid = parseInt(window.ECOLLAB?.userId);
    m.innerHTML = `
      <div class="modal-box" style="max-width:600px;width:94%">
        <div class="modal-header">
          <span>📋 ${escX(request.title)}</span>
          <button class="modal-close" onclick="closeReviewDetail()">✕</button>
        </div>
        <div class="modal-body">
          ${request.content ? `<div class="review-content-box">${escX(request.content)}</div>` : ''}
          ${request.file_url ? `<a href="${escX(request.file_url)}" target="_blank" class="review-file-link">📎 View attachment</a>` : ''}
          <div class="review-feedback-section">
            <div class="collab-section-title" style="margin-bottom:8px">Feedback (${request.feedback.length})</div>
            ${request.feedback.map(f => `
              <div class="review-feedback-item">
                <div class="review-feedback-meta">${escX(f.reviewer)} ${f.rating ? '· ' + '⭐'.repeat(f.rating) : ''}</div>
                <div class="review-feedback-text">${escX(f.comment)}</div>
              </div>`).join('') || '<div class="collab-empty" style="padding:10px">No feedback yet.</div>'}
          </div>
          ${request.state === 'open' && parseInt(request.author_id) !== myUid ? `
            <div class="review-add-feedback">
              <div class="collab-section-title" style="margin-bottom:6px">Add Feedback</div>
              <select id="feedbackRating" class="code-lang-select" style="width:100%;margin-bottom:6px">
                <option value="">No rating</option>
                <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                <option value="4">⭐⭐⭐⭐ Good</option>
                <option value="3">⭐⭐⭐ Average</option>
                <option value="2">⭐⭐ Needs work</option>
                <option value="1">⭐ Poor</option>
              </select>
              <textarea id="feedbackComment" class="collab-input" rows="3" placeholder="Your feedback…"></textarea>
            </div>` : ''}
        </div>
        <div class="modal-footer">
          ${request.state === 'open' && parseInt(request.author_id) !== myUid
            ? `<button class="timer-start-btn" onclick="submitReviewFeedback(${requestId})" style="padding:7px 18px;font-size:13px">Submit Feedback</button>` : ''}
          ${request.state === 'open' && parseInt(request.author_id) === myUid
            ? `<button class="collab-btn-xs" style="color:#ef4444" onclick="closeReviewRequest(${requestId})">Close Request</button>` : ''}
          <button class="timer-reset-btn" onclick="closeReviewDetail()">Close</button>
        </div>
      </div>`;
  } catch (e) {
    m.innerHTML = `<div class="modal-box" style="padding:30px"><div class="collab-err">⚠ ${escX(e.message)}</div></div>`;
  }
}
window.openReviewDetail = openReviewDetail;

function closeReviewDetail() {
  const m = document.getElementById('reviewDetailModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeReviewDetail = closeReviewDetail;

async function submitReviewFeedback(requestId) {
  const comment = document.getElementById('feedbackComment')?.value?.trim();
  const rating  = document.getElementById('feedbackRating')?.value;
  if (!comment) { if (window.showToast) showToast('Comment required', 'info'); return; }
  try {
    await extraFetch('review', 'add_feedback', { request_id: requestId, comment, rating: rating || null });
    closeReviewDetail();
    loadReviewList();
    if (window.showToast) showToast('💬 Feedback submitted!', 'success');
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitReviewFeedback = submitReviewFeedback;

async function closeReviewRequest(requestId) {
  await extraFetch('review', 'close', { request_id: requestId });
  closeReviewDetail(); loadReviewList();
}
window.closeReviewRequest = closeReviewRequest;

/* ─────────────────────────────────────────────────────────────────────────────
   4. CHAT SUMMARY
   ───────────────────────────────────────────────────────────────────────────── */
let _summaryGenerating = false;

async function loadSummaryList() {
  const pane = document.getElementById('collabPane_summary');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { summaries } = await extraFetch('summary', 'list', {}, 'GET');
    pane.innerHTML = `
      <div class="summary-header">
        <span class="collab-section-title">✨ Chat Summaries</span>
        <button class="collab-btn-sm ${_summaryGenerating ? 'disabled' : ''}"
          onclick="generateSummary()" id="genSummaryBtn">
          ${_summaryGenerating ? '⏳ Generating…' : '✨ Generate'}
        </button>
      </div>
      <div class="summary-count-row">
        <select id="summaryCountSel" class="code-lang-select">
          <option value="50">Last 50 messages</option>
          <option value="100" selected>Last 100 messages</option>
          <option value="200">Last 200 messages</option>
        </select>
      </div>
      <div class="summary-list">
        ${summaries.length ? summaries.map(s => `
          <div class="summary-card">
            <div class="summary-card-meta">✨ ${escX(s.generator)} · ${escX(s.generated_at?.slice(0,16) || '')} · ${s.message_count} msgs</div>
            <div class="summary-card-text">${escX(s.summary)}</div>
          </div>`).join('') : `<div class="collab-empty">No summaries yet. Generate one from recent chat messages.</div>`}
      </div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.loadSummaryList = loadSummaryList;

async function generateSummary() {
  if (_summaryGenerating) return;
  _summaryGenerating = true;
  const btn = document.getElementById('genSummaryBtn');
  if (btn) { btn.textContent = '⏳ Generating…'; btn.disabled = true; }
  const count = parseInt(document.getElementById('summaryCountSel')?.value || '100');
  try {
    const { summary, message_count } = await extraFetch('summary', 'generate', { message_count: count });
    if (window.showToast) showToast(`✨ Summary generated from ${message_count} messages`, 'success');
    loadSummaryList();
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
  finally {
    _summaryGenerating = false;
    const btn2 = document.getElementById('genSummaryBtn');
    if (btn2) { btn2.textContent = '✨ Generate'; btn2.disabled = false; }
  }
}
window.generateSummary = generateSummary;

/* ─────────────────────────────────────────────────────────────────────────────
   5. STUDY GOALS
   ───────────────────────────────────────────────────────────────────────────── */
async function loadGoals() {
  const pane = document.getElementById('collabPane_goals');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { goals } = await extraFetch('goals', 'list', {}, 'GET');
    pane.innerHTML = `
      <div class="goals-header">
        <span class="collab-section-title">🎯 Study Goals</span>
        <button class="collab-btn-sm" onclick="openCreateGoalModal()">+ New Goal</button>
      </div>
      <div class="goals-list">
        ${goals.length ? goals.map(g => `
          <div class="goal-card ${g.status === 'completed' ? 'goal-done' : ''}">
            <div class="goal-top-row">
              <span class="goal-scope-badge ${g.scope === 'group' ? 'badge-group' : 'badge-personal'}">${g.scope === 'group' ? '👥 Group' : '👤 Personal'}</span>
              <span class="goal-owner">${escX(g.owner)}</span>
              ${g.target_date ? `<span class="goal-due">📅 ${escX(g.target_date)}</span>` : ''}
            </div>
            <div class="goal-title">${escX(g.title)}</div>
            ${g.description ? `<div class="goal-desc">${escX(g.description)}</div>` : ''}
            <div class="goal-progress-wrap">
              <div class="goal-progress-bar">
                <div class="goal-progress-fill" style="width:${g.progress}%;background:${g.status === 'completed' ? '#22c55e' : '#a855f7'}"></div>
              </div>
              <span class="goal-progress-label">${g.progress}%</span>
            </div>
            ${g.milestones?.length ? `
              <div class="goal-milestones">
                ${g.milestones.map(ms => `
                  <div class="goal-milestone ${ms.done == 1 ? 'ms-done' : ''}">
                    <input type="checkbox" ${ms.done == 1 ? 'checked' : ''} onchange="toggleMilestone(${ms.id}, this)" />
                    <span>${escX(ms.label)}</span>
                  </div>`).join('')}
              </div>` : ''}
            <div class="goal-footer">
              <div class="goal-react-row">
                ${['👍','🔥','💪','🎉','❤️'].map(emoji => `
                  <button class="goal-react-btn ${g.my_reaction === emoji ? 'reacted' : ''}"
                    onclick="reactGoal(${g.id}, '${emoji}')">${emoji}</button>`).join('')}
                ${g.reaction_count > 0 ? `<span class="goal-react-count">${g.reaction_count}</span>` : ''}
              </div>
              ${parseInt(g.user_id) === parseInt(window.ECOLLAB?.userId) ? `
                <div class="goal-owner-controls">
                  <input type="range" min="0" max="100" value="${g.progress}"
                    class="goal-slider" oninput="this.nextElementSibling.textContent=this.value+'%'"
                    onchange="updateGoalProgress(${g.id}, this.value)" />
                  <span class="goal-slider-label">${g.progress}%</span>
                  <button class="collab-btn-xs" style="color:#ef4444" onclick="abandonGoal(${g.id})">Abandon</button>
                </div>` : ''}
            </div>
          </div>`).join('') : `<div class="collab-empty">No goals yet. Set one to track your study progress!</div>`}
      </div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.loadGoals = loadGoals;

function openCreateGoalModal() {
  const m = document.getElementById('createGoalModal');
  if (m) { m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open')); }
}
window.openCreateGoalModal = openCreateGoalModal;
function closeCreateGoalModal() {
  const m = document.getElementById('createGoalModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeCreateGoalModal = closeCreateGoalModal;

async function submitCreateGoal() {
  const title = document.getElementById('goalTitleInput')?.value?.trim();
  if (!title) { if (window.showToast) showToast('Title required', 'info'); return; }
  const msRaw = document.getElementById('goalMilestonesInput')?.value?.trim() || '';
  const milestones = msRaw.split('\n').map(l => l.trim()).filter(Boolean).map(label => ({ label }));
  try {
    await extraFetch('goals', 'create', {
      title,
      description: document.getElementById('goalDescInput')?.value?.trim(),
      scope:       document.getElementById('goalScopeSelect')?.value || 'group',
      target_date: document.getElementById('goalDateInput')?.value || null,
      milestones,
    });
    closeCreateGoalModal();
    loadGoals();
    if (window.showToast) showToast('🎯 Goal created!', 'success');
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitCreateGoal = submitCreateGoal;

async function updateGoalProgress(goalId, progress) {
  await extraFetch('goals', 'update_progress', { goal_id: goalId, progress: parseInt(progress) })
    .catch(e => { if (window.showToast) showToast('⚠ ' + e.message, 'info'); });
  loadGoals();
}
window.updateGoalProgress = updateGoalProgress;

async function toggleMilestone(milestoneId, checkbox) {
  try {
    await extraFetch('goals', 'toggle_milestone', { milestone_id: milestoneId });
  } catch (e) {
    checkbox.checked = !checkbox.checked; // revert
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window.toggleMilestone = toggleMilestone;

async function reactGoal(goalId, emoji) {
  await extraFetch('goals', 'react', { goal_id: goalId, emoji }).catch(() => {});
  loadGoals();
}
window.reactGoal = reactGoal;

async function abandonGoal(goalId) {
  if (!confirm('Abandon this goal?')) return;
  await extraFetch('goals', 'abandon', { goal_id: goalId });
  loadGoals();
}
window.abandonGoal = abandonGoal;

/* ─────────────────────────────────────────────────────────────────────────────
   6. RESOURCE LIBRARY
   ───────────────────────────────────────────────────────────────────────────── */
let _resourceFilter = { type: '', search: '' };

async function loadResources() {
  const pane = document.getElementById('collabPane_resources');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { resources } = await extraFetch('resources', 'list', _resourceFilter, 'GET');
    const TYPE_ICONS = { link:'🔗', pdf:'📄', video:'🎥', image:'🖼', file:'📁', note:'📝', other:'📌' };
    pane.innerHTML = `
      <div class="res-header">
        <span class="collab-section-title">📚 Resource Library</span>
        <button class="collab-btn-sm" onclick="openAddResourceModal()">+ Add</button>
      </div>
      <div class="res-filter-row">
        <input class="collab-input" style="flex:1" placeholder="Search…"
          value="${escX(_resourceFilter.search)}"
          oninput="_resourceFilter.search=this.value;_resSearchDebounce()" />
        <select class="code-lang-select" onchange="_resourceFilter.type=this.value;loadResources()">
          <option value="">All types</option>
          ${['link','pdf','video','image','file','note','other'].map(t =>
            `<option value="${t}" ${_resourceFilter.type===t?'selected':''}>${TYPE_ICONS[t]} ${t}</option>`).join('')}
        </select>
      </div>
      <div class="res-list">
        ${resources.length ? resources.map(r => `
          <div class="res-card">
            <div class="res-type-icon">${TYPE_ICONS[r.type] || '📌'}</div>
            <div class="res-card-body">
              <div class="res-card-title">
                ${r.url ? `<a href="${escX(r.url)}" target="_blank" class="res-link">${escX(r.title)}</a>` : escX(r.title)}
              </div>
              ${r.description ? `<div class="res-desc">${escX(r.description)}</div>` : ''}
              ${r.tags ? `<div class="res-tags">${r.tags.split(',').map(t=>`<span class="res-tag">${escX(t.trim())}</span>`).join('')}</div>` : ''}
              <div class="res-meta">by ${escX(r.adder)} · ${r.comment_count} comments</div>
            </div>
            <div class="res-actions">
              <button class="res-vote-btn ${r.voted?'voted':''}" onclick="voteResource(${r.id}, this)">
                ▲ <span class="res-vote-count">${r.vote_count}</span>
              </button>
              <button class="collab-btn-xs" onclick="openResourceComments(${r.id},'${escX(r.title)}')">💬</button>
              ${parseInt(r.added_by)===parseInt(window.ECOLLAB?.userId)
                ? `<button class="collab-btn-xs" style="color:#ef4444" onclick="deleteResource(${r.id})">🗑</button>` : ''}
            </div>
          </div>`).join('') : `<div class="collab-empty">No resources yet. Add the first one!</div>`}
      </div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escX(e.message)}</div>`; }
}
window.loadResources = loadResources;

let _resSearchTimer = null;
window._resSearchDebounce = () => { clearTimeout(_resSearchTimer); _resSearchTimer = setTimeout(loadResources, 400); };

async function voteResource(resourceId, btn) {
  try {
    const { voted } = await extraFetch('resources', 'vote', { resource_id: resourceId });
    const countEl = btn.querySelector('.res-vote-count');
    const delta   = voted ? 1 : -1;
    if (countEl) countEl.textContent = parseInt(countEl.textContent) + delta;
    btn.classList.toggle('voted', voted);
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.voteResource = voteResource;

async function deleteResource(resourceId) {
  if (!confirm('Delete this resource?')) return;
  await extraFetch('resources', 'delete', { resource_id: resourceId });
  loadResources();
}
window.deleteResource = deleteResource;

async function openResourceComments(resourceId, title) {
  const m = document.getElementById('resourceCommentsModal');
  if (!m) return;
  m.innerHTML = `<div class="modal-box" style="max-width:480px;width:94%"><div class="collab-loading" style="padding:30px"><div class="collab-spinner"></div></div></div>`;
  m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open'));
  const { comments } = await extraFetch('resources', 'get_comments', { resource_id: resourceId }, 'GET');
  m.innerHTML = `
    <div class="modal-box" style="max-width:480px;width:94%">
      <div class="modal-header"><span>💬 ${escX(title)}</span><button class="modal-close" onclick="closeResourceComments()">✕</button></div>
      <div class="modal-body">
        ${comments.map(c=>`
          <div style="padding:8px 0;border-bottom:1px solid var(--app-border)">
            <span style="font-size:11px;color:var(--text-muted)">${escX(c.username)}</span>
            <div style="font-size:13px;margin-top:2px">${escX(c.comment)}</div>
          </div>`).join('') || '<div class="collab-empty">No comments yet.</div>'}
        <div style="margin-top:10px;display:flex;gap:8px">
          <input id="resCommentInput" class="collab-input" placeholder="Add a comment…" style="flex:1"/>
          <button class="collab-btn-sm" onclick="submitResourceComment(${resourceId})">Send</button>
        </div>
      </div>
    </div>`;
}
window.openResourceComments = openResourceComments;

function closeResourceComments() {
  const m = document.getElementById('resourceCommentsModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeResourceComments = closeResourceComments;

async function submitResourceComment(resourceId) {
  const comment = document.getElementById('resCommentInput')?.value?.trim();
  if (!comment) return;
  await extraFetch('resources', 'comment', { resource_id: resourceId, comment });
  closeResourceComments();
  loadResources();
  if (window.showToast) showToast('💬 Comment added', 'success');
}
window.submitResourceComment = submitResourceComment;

function openAddResourceModal() {
  const m = document.getElementById('addResourceModal');
  if (m) { m.style.display = 'flex'; requestAnimationFrame(() => m.classList.add('modal-open')); }
}
window.openAddResourceModal = openAddResourceModal;
function closeAddResourceModal() {
  const m = document.getElementById('addResourceModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 220); }
}
window.closeAddResourceModal = closeAddResourceModal;

async function submitAddResource() {
  const title = document.getElementById('resTitleInput')?.value?.trim();
  if (!title) { if (window.showToast) showToast('Title required', 'info'); return; }
  try {
    await extraFetch('resources', 'add', {
      title,
      url:         document.getElementById('resUrlInput')?.value?.trim(),
      description: document.getElementById('resDescInput')?.value?.trim(),
      type:        document.getElementById('resTypeSelect')?.value || 'link',
      tags:        document.getElementById('resTagsInput')?.value?.trim(),
    });
    closeAddResourceModal();
    loadResources();
    if (window.showToast) showToast('📚 Resource added!', 'success');
  } catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitAddResource = submitAddResource;
