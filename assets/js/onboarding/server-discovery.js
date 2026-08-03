'use strict';

// ── State ──────────────────────────────────────────────────────────────────
const selectedIds = new Set();
let allServers    = [];

// ── Init ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadSuggestions();
  initParticles();
});

// ── Load suggestions from API ──────────────────────────────────────────────
async function loadSuggestions() {
  try {
    const res  = await fetch(BASE_URL + '/API/onboarding/get-server-suggestions.php');
    const data = await res.json();

    if (!data.success) throw new Error(data.error || 'Failed');

    allServers = data.servers || [];
    renderGrid(allServers);
  } catch (e) {
    renderError(e.message);
  }
}

// ── Render grid ────────────────────────────────────────────────────────────
function renderGrid(servers) {
  const grid  = document.getElementById('serverGrid');
  const empty = document.getElementById('obEmpty');
  if (!grid) return;

  if (!servers.length) {
    grid.innerHTML = '';
    if (empty) empty.style.display = '';
    return;
  }
  if (empty) empty.style.display = 'none';

  grid.innerHTML = servers.map(s => buildCard(s)).join('');
}

function buildCard(s) {
  const score       = s.score ?? 0;
  const matchClass  = score >= 80 ? 'ob-match-high' : score >= 50 ? 'ob-match-med' : 'ob-match-low';
  const matchLabel  = score >= 80 ? 'High Match'    : score >= 50 ? 'Good Match'    : 'Suggested';
  const matchedTags = (s.matched_tags || []).slice(0, 3);
  const otherTags   = (s.tags || []).filter(t => !matchedTags.includes(t)).slice(0, 3);
  const memberStr   = s.member_count >= 1000
    ? (s.member_count / 1000).toFixed(1) + 'k'
    : String(s.member_count);

  const icon = s.icon_emoji
    ? `<div class="ob-card-icon">${s.icon_emoji}</div>`
    : `<div class="ob-card-icon ob-card-icon-default">${s.name.charAt(0).toUpperCase()}</div>`;

  const tagHtml = [
    ...matchedTags.map(t => `<span class="ob-tag ob-tag-match">${esc(t)}</span>`),
    ...otherTags.map(t  => `<span class="ob-tag">${esc(t)}</span>`),
  ].join('');

  const matchedLine = matchedTags.length
    ? `<div class="ob-card-matched">Matches: ${matchedTags.map(t => `<b>${esc(t)}</b>`).join(', ')}</div>`
    : '';

  return `
    <div class="ob-card" id="card-${s.id}" data-id="${s.id}" onclick="toggleCard(${s.id})">
      <div class="ob-card-header">
        ${icon}
        <div class="ob-card-meta">
          <div class="ob-card-name">
            ${esc(s.name)}
            ${s.is_verified ? '<span class="ob-verified" title="Verified">✓</span>' : ''}
          </div>
          <div class="ob-card-stats">
            <span>👥 ${memberStr}</span>
            <span class="ob-type-badge ob-type-${s.type}">${capitalize(s.type)}</span>
          </div>
        </div>
        <span class="ob-match-badge ${matchClass}">${matchLabel} ${score}%</span>
      </div>
      <p class="ob-card-desc">${esc(s.description || 'No description.')}</p>
      ${tagHtml ? `<div class="ob-card-tags">${tagHtml}</div>` : ''}
      ${matchedLine}
      <div class="ob-card-select">
        <div class="ob-check" id="check-${s.id}">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <polyline points="2 6 5 9 10 3" stroke="#fff" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <span class="ob-select-label" id="sellabel-${s.id}">Join this community</span>
      </div>
    </div>
  `;
}

// ── Toggle card selection ──────────────────────────────────────────────────
function toggleCard(id) {
  const card    = document.getElementById('card-' + id);
  const check   = document.getElementById('check-' + id);
  const label   = document.getElementById('sellabel-' + id);
  if (!card) return;

  if (selectedIds.has(id)) {
    selectedIds.delete(id);
    card.classList.remove('selected');
    if (check) check.classList.remove('checked');
    if (label) label.textContent = 'Join this community';
  } else {
    selectedIds.add(id);
    card.classList.add('selected');
    if (check) check.classList.add('checked');
    if (label) label.textContent = '✓ Selected';
  }

  updateActionBar();
}

// ── Update bottom action bar ───────────────────────────────────────────────
function updateActionBar() {
  const n       = selectedIds.size;
  const countEl = document.getElementById('obSelectedCount');
  const plural  = document.getElementById('obSelectedPlural');
  const joinBtn = document.getElementById('obJoinBtn');
  const joinLbl = document.getElementById('obJoinLabel');

  if (countEl) countEl.textContent = n;
  if (plural)  plural.textContent  = n === 1 ? '' : 's';
  if (joinBtn) joinBtn.disabled    = n === 0;
  if (joinLbl) joinLbl.textContent = n > 0 ? `Join ${n} Communit${n === 1 ? 'y' : 'ies'}` : 'Join Selected';
}

// ── Skip ───────────────────────────────────────────────────────────────────
async function skipOnboarding() {
  const btn = document.getElementById('obSkipBtn');
  if (btn) { btn.textContent = 'Skipping…'; btn.disabled = true; }

  try {
    // POST skip signal so server marks onboarding done
    await fetch(BASE_URL + '/API/onboarding/join-servers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ server_ids: [] }),
    });
  } catch (_) { /* ignore — still redirect */ }

  window.location.href = CHAT_URL;
}

// ── Join selected ──────────────────────────────────────────────────────────
async function joinSelected() {
  if (!selectedIds.size) return;

  const btn = document.getElementById('obJoinBtn');
  if (btn) {
    btn.innerHTML = '<span class="ob-spinner"></span> Joining…';
    btn.disabled = true;
  }

  try {
    const res  = await fetch(BASE_URL + '/API/onboarding/join-servers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ server_ids: [...selectedIds] }),
    });
    const data = await res.json();

    if (data.success) {
      if (btn) {
        btn.innerHTML = `✓ Joined ${data.joined}!`;
        btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
      }
      setTimeout(() => { window.location.href = CHAT_URL; }, 800);
    } else {
      showToast(data.error || 'Something went wrong.');
      if (btn) { btn.innerHTML = 'Try Again'; btn.disabled = false; }
    }
  } catch (e) {
    showToast('Network error. Redirecting anyway…');
    setTimeout(() => { window.location.href = CHAT_URL; }, 1500);
  }
}

// ── Render error state ─────────────────────────────────────────────────────
function renderError(msg) {
  const grid = document.getElementById('serverGrid');
  if (grid) grid.innerHTML = `
    <div class="ob-error">
      <div style="font-size:2rem;margin-bottom:10px;">⚠️</div>
      <div>${esc(msg || 'Could not load suggestions.')}</div>
      <button onclick="loadSuggestions()" style="margin-top:14px;padding:8px 18px;border-radius:8px;border:none;background:var(--accent-purple);color:#fff;cursor:pointer;font-weight:700;">Retry</button>
    </div>
  `;
}

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg) {
  const t = document.createElement('div');
  t.className = 'ob-toast';
  t.textContent = msg;
  document.body.appendChild(t);
  requestAnimationFrame(() => t.classList.add('show'));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3000);
}

// ── Helpers ────────────────────────────────────────────────────────────────
function esc(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

// ── Particle canvas ────────────────────────────────────────────────────────
function initParticles() {
  const canvas = document.getElementById('particles');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  resize();
  window.addEventListener('resize', resize, { passive: true });
  const pts = Array.from({ length: 45 }, () => ({
    x: Math.random() * window.innerWidth, y: Math.random() * window.innerHeight,
    r: Math.random() * 1.6 + 0.3,
    vx: (Math.random() - 0.5) * 0.3, vy: (Math.random() - 0.5) * 0.3,
    a: Math.random() * 0.4 + 0.1,
    col: Math.random() > 0.5 ? '168,85,247' : '236,72,153',
  }));
  (function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    pts.forEach((p, i) => {
      pts.slice(i + 1).forEach(q => {
        const d = Math.hypot(p.x - q.x, p.y - q.y);
        if (d < 100) {
          ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y);
          ctx.strokeStyle = `rgba(168,85,247,${0.05*(1-d/100)})`; ctx.lineWidth=0.6; ctx.stroke();
        }
      });
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = canvas.width;  if (p.x > canvas.width)  p.x = 0;
      if (p.y < 0) p.y = canvas.height; if (p.y > canvas.height) p.y = 0;
      ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
      ctx.fillStyle = `rgba(${p.col},${p.a})`; ctx.fill();
    });
    requestAnimationFrame(animate);
  })();
}
