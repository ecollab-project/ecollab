/**
 * peer-matching.js — Tag-based peer compatibility matching for Ecollab
 *
 * Features:
 *   1. Profile Editor  — study prefs + subject / hobby / interest tags
 *   2. Match Cards     — scored results with 4-dimension bar charts
 *   3. Advanced Search — filter by tag, study style, role, min score
 *   4. Requests Panel  — inbox + outbox with accept/decline
 *   5. Compatibility   — full breakdown modal between two users
 *   6. Leaderboard     — top compatible peers in your servers
 */

'use strict';

const PM_API = (window.ECOLLAB?.baseUrl || '') + '/API/chat/peer-match.php';

/* ── fetch helper ────────────────────────────────────────────────────────── */
async function pmFetch(action, params = {}, method = 'GET', body = null) {
  const qs  = new URLSearchParams({ action, ...params }).toString();
  const url = `${PM_API}?${qs}`;
  const opts = { method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' } };
  if (body) opts.body = JSON.stringify(body);
  const res  = await fetch(url, opts);
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Request failed');
  return data;
}
function pmEsc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── state ───────────────────────────────────────────────────────────────── */
let _pmTags       = null;   // {subjects, hobbies, interests}
let _pmProfile    = null;   // own profile
let _pmMatches    = [];     // current match list
let _pmActiveTab  = 'matches'; // matches|search|requests|leaderboard

/* ── open / close ────────────────────────────────────────────────────────── */
function openPeerMatchingModal() {
  const m = document.getElementById('peerMatchingModal');
  if (!m) return;
  m.style.display = 'flex';
  requestAnimationFrame(() => m.classList.add('modal-open'));
  if (!_pmTags) _pmInit();
  else _pmShowTab(_pmActiveTab);
}
window.openPeerMatchingModal = openPeerMatchingModal;

function closePeerMatchingModal() {
  const m = document.getElementById('peerMatchingModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display = 'none', 240); }
}
window.closePeerMatchingModal = closePeerMatchingModal;

async function _pmInit() {
  _pmSetLoading(true);
  try {
    [_pmTags, _pmProfile] = await Promise.all([
      pmFetch('get_tags'),
      pmFetch('get_profile'),
    ]);
    _pmShowTab(_pmActiveTab);
  } catch (e) {
    _pmSetError(e.message);
  } finally {
    _pmSetLoading(false);
  }
}

/* ── tab navigation ──────────────────────────────────────────────────────── */
function _pmShowTab(tab) {
  _pmActiveTab = tab;
  document.querySelectorAll('.pm-tab-btn').forEach(b => b.classList.toggle('pm-active', b.dataset.tab === tab));
  const body = document.getElementById('pmModalBody');
  if (!body) return;
  switch (tab) {
    case 'matches':     _pmRenderMatches(body);    break;
    case 'search':      _pmRenderSearch(body);     break;
    case 'requests':    _pmRenderRequests(body);   break;
    case 'leaderboard': _pmRenderLeaderboard(body); break;
    case 'profile':     _pmRenderProfileEditor(body); break;
  }
}
window._pmShowTab = _pmShowTab;

function _pmSetLoading(on) {
  const body = document.getElementById('pmModalBody');
  if (body && on) body.innerHTML = `<div class="pm-loading"><div class="collab-spinner"></div><span>Finding your best study partners…</span></div>`;
}
function _pmSetError(msg) {
  const body = document.getElementById('pmModalBody');
  if (body) body.innerHTML = `<div class="pm-error">⚠ ${pmEsc(msg)}</div>`;
}

/* ─────────────────────────────────────────────────────────────────────────────
   1. MATCH CARDS
   ───────────────────────────────────────────────────────────────────────────── */
async function _pmRenderMatches(body) {
  body.innerHTML = `
    <div class="pm-filter-bar">
      <select class="pm-select" id="pmFilterStyle" onchange="_pmApplyFilters()">
        <option value="">Any study style</option>
        <option value="solo">Solo</option>
        <option value="group">Group</option>
        <option value="mixed">Mixed</option>
      </select>
      <select class="pm-select" id="pmFilterSort" onchange="_pmApplyFilters()">
        <option value="score">Best match</option>
        <option value="subjects">Shared subjects</option>
        <option value="style">Study style</option>
        <option value="interests">Shared interests</option>
        <option value="hobbies">Shared hobbies</option>
      </select>
      <select class="pm-select" id="pmFilterRole" onchange="_pmApplyFilters()">
        <option value="">Any role</option>
        <option value="student">Students</option>
        <option value="facilitator">Facilitators</option>
      </select>
      <input type="number" class="pm-input" id="pmFilterMinScore"
        placeholder="Min %" min="0" max="99" style="width:72px"
        onchange="_pmApplyFilters()" />
      <button class="pm-refresh-btn" onclick="_pmApplyFilters()" title="Refresh">↻</button>
    </div>
    <div id="pmMatchList" class="pm-match-list">
      <div class="pm-loading"><div class="collab-spinner"></div><span>Loading matches…</span></div>
    </div>`;
  await _pmApplyFilters();
}

async function _pmApplyFilters() {
  const list = document.getElementById('pmMatchList');
  if (!list) return;
  list.innerHTML = `<div class="pm-loading"><div class="collab-spinner"></div></div>`;
  try {
    const params = {
      study_style: document.getElementById('pmFilterStyle')?.value  || '',
      sort:        document.getElementById('pmFilterSort')?.value   || 'score',
      role:        document.getElementById('pmFilterRole')?.value   || '',
      min_score:   document.getElementById('pmFilterMinScore')?.value || 0,
      limit:       24,
    };
    // Remove empty params
    Object.keys(params).forEach(k => { if (!params[k]) delete params[k]; });
    const { matches } = await pmFetch('get_matches', params);
    _pmMatches = matches;
    if (!matches.length) {
      list.innerHTML = `
        <div class="pm-empty">
          <div style="font-size:32px;margin-bottom:10px">🔍</div>
          <div style="font-size:14px;font-weight:600;margin-bottom:6px">No matches found</div>
          <div style="font-size:12px;color:var(--text-muted)">Complete your profile to get better matches</div>
          <button class="pm-btn-primary" style="margin-top:14px" onclick="_pmShowTab('profile')">Set Up Profile</button>
        </div>`;
      return;
    }
    list.innerHTML = matches.map(_pmMatchCard).join('');
  } catch (e) {
    list.innerHTML = `<div class="pm-error">⚠ ${pmEsc(e.message)}</div>`;
  }
}
window._pmApplyFilters = _pmApplyFilters;

function _pmMatchCard(m) {
  const init     = (m.name || '?')[0].toUpperCase();
  const [c1, c2] = (m.grad || '#a855f7,#ec4899').split(',');
  const onlineDot = m.is_online
    ? `<span class="pm-online-dot" title="Online now"></span>` : '';

  const scoreBar = (label, val, color) => `
    <div class="pm-score-row">
      <span class="pm-score-label">${label}</span>
      <div class="pm-score-track">
        <div class="pm-score-fill" style="width:${Math.round(val)}%;background:${color}"></div>
      </div>
      <span class="pm-score-num">${Math.round(val)}</span>
    </div>`;

  const tagList = [...(m.shared_subjects||[]).slice(0,2).map(s=>`${s.icon||''} ${s.name}`),
                   ...(m.shared_hobbies||[]).slice(0,1).map(h=>`${h.icon||''} ${h.name}`),
                   ...(m.shared_interests||[]).slice(0,1).map(i=>`${i.icon||''} ${i.name}`),
                   ...(m.tags||[])].filter(Boolean).slice(0,5);

  return `
    <div class="pm-card" data-uid="${m.id}">
      <div class="pm-card-top">
        <div class="pm-avatar" style="background:linear-gradient(135deg,${c1},${c2})">
          ${init}${onlineDot}
        </div>
        <div class="pm-card-info">
          <div class="pm-card-name">${pmEsc(m.name)}
            ${m.role !== 'student' ? `<span class="pm-role-badge">${pmEsc(m.role)}</span>` : ''}
          </div>
          ${m.style_label ? `<div class="pm-card-style">${pmEsc(m.style_label)}</div>` : ''}
          ${m.bio ? `<div class="pm-card-bio">${pmEsc(m.bio)}</div>` : ''}
        </div>
        <div class="pm-score-circle" style="background:conic-gradient(${_pmScoreColor(m.pct)} ${m.pct}%, var(--bg-tertiary) 0)">
          <div class="pm-score-inner">${m.pct}<span style="font-size:10px">%</span></div>
        </div>
      </div>

      ${tagList.length ? `
        <div class="pm-tag-strip">
          ${tagList.map(t=>`<span class="pm-tag">${pmEsc(t)}</span>`).join('')}
        </div>` : ''}

      <div class="pm-score-bars">
        ${scoreBar('Subjects',  m.score_subjects,  '#3b82f6')}
        ${scoreBar('Style',     m.score_style,     '#a855f7')}
        ${scoreBar('Interests', m.score_interests, '#f59e0b')}
        ${scoreBar('Hobbies',   m.score_hobbies,   '#22c55e')}
      </div>

      <div class="pm-card-actions">
        <button class="pm-btn-secondary" onclick="openCompatibilityModal(${m.id}, '${pmEsc(m.name)}')">
          📊 Details
        </button>
        ${m.already_connected
          ? `<button class="pm-btn-connected" disabled>✓ Connected</button>`
          : `<button class="pm-btn-primary" onclick="openSendRequestModal(${m.id}, '${pmEsc(m.name)}', '${pmEsc(m.pct)}')">
               🤝 Connect
             </button>`}
      </div>
    </div>`;
}

function _pmScoreColor(pct) {
  if (pct >= 80) return '#22c55e';
  if (pct >= 60) return '#a855f7';
  if (pct >= 40) return '#f59e0b';
  return '#64748b';
}

/* ─────────────────────────────────────────────────────────────────────────────
   2. ADVANCED SEARCH
   ───────────────────────────────────────────────────────────────────────────── */
function _pmRenderSearch(body) {
  const subjects  = _pmTags?.subjects  || [];
  const hobbies   = _pmTags?.hobbies   || [];
  const interests = _pmTags?.interests || [];

  body.innerHTML = `
    <div class="pm-search-bar">
      <input id="pmSearchQ" class="pm-input" placeholder="Search by name or bio…"
        oninput="_pmSearchDebounce()" style="flex:1" />
    </div>
    <div class="pm-search-filters">
      <select class="pm-select" id="pmSrchSubject" onchange="_pmRunSearch()">
        <option value="">Any subject</option>
        ${subjects.map(s=>`<option value="${s.id}">${pmEsc(s.icon)} ${pmEsc(s.name)}</option>`).join('')}
      </select>
      <select class="pm-select" id="pmSrchHobby" onchange="_pmRunSearch()">
        <option value="">Any hobby</option>
        ${hobbies.map(h=>`<option value="${h.id}">${pmEsc(h.icon)} ${pmEsc(h.name)}</option>`).join('')}
      </select>
      <select class="pm-select" id="pmSrchInterest" onchange="_pmRunSearch()">
        <option value="">Any interest</option>
        ${interests.map(i=>`<option value="${i.id}">${pmEsc(i.icon)} ${pmEsc(i.name)}</option>`).join('')}
      </select>
      <select class="pm-select" id="pmSrchStyle" onchange="_pmRunSearch()">
        <option value="">Any style</option>
        <option value="solo">Solo</option>
        <option value="group">Group</option>
        <option value="mixed">Mixed</option>
      </select>
    </div>
    <div id="pmSearchResults" class="pm-match-list">
      <div class="pm-empty" style="padding:30px;text-align:center;color:var(--text-muted)">
        Type a name or select filters to search
      </div>
    </div>`;
}

let _pmSearchTimer = null;
window._pmSearchDebounce = () => { clearTimeout(_pmSearchTimer); _pmSearchTimer = setTimeout(_pmRunSearch, 350); };

async function _pmRunSearch() {
  const results = document.getElementById('pmSearchResults');
  if (!results) return;
  const params = {
    q:           document.getElementById('pmSearchQ')?.value?.trim() || '',
    subject_id:  document.getElementById('pmSrchSubject')?.value  || '',
    hobby_id:    document.getElementById('pmSrchHobby')?.value    || '',
    interest_id: document.getElementById('pmSrchInterest')?.value || '',
    study_style: document.getElementById('pmSrchStyle')?.value    || '',
  };
  if (!Object.values(params).some(Boolean)) return;
  results.innerHTML = `<div class="pm-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { users } = await pmFetch('search_users', params);
    if (!users.length) { results.innerHTML = `<div class="pm-empty">No users found</div>`; return; }
    results.innerHTML = users.map(u => {
      const [c1,c2] = (u.avatar_color_gradient||'#a855f7,#ec4899').split(',');
      return `
        <div class="pm-search-row">
          <div class="pm-avatar pm-avatar-sm" style="background:linear-gradient(135deg,${c1},${c2})">
            ${(u.full_name||u.username||'?')[0].toUpperCase()}
            ${u.is_online ? '<span class="pm-online-dot"></span>' : ''}
          </div>
          <div class="pm-search-info">
            <div class="pm-card-name">${pmEsc(u.full_name||u.username)}</div>
            <div class="pm-card-bio">${pmEsc(u.bio||u.role||'')}</div>
          </div>
          <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
            ${u.pct > 0 ? `<span class="pm-score-badge" style="background:${_pmScoreColor(u.pct)}20;color:${_pmScoreColor(u.pct)}">${Math.round(u.pct)}%</span>` : ''}
            <button class="pm-btn-secondary pm-btn-xs" onclick="openCompatibilityModal(${u.id},'${pmEsc(u.full_name||u.username)}')">📊</button>
            <button class="pm-btn-primary pm-btn-xs" onclick="openSendRequestModal(${u.id},'${pmEsc(u.full_name||u.username)}',${Math.round(u.pct||0)})">Connect</button>
          </div>
        </div>`;
    }).join('');
  } catch (e) { results.innerHTML = `<div class="pm-error">⚠ ${pmEsc(e.message)}</div>`; }
}
window._pmRunSearch = _pmRunSearch;

/* ─────────────────────────────────────────────────────────────────────────────
   3. REQUESTS PANEL
   ───────────────────────────────────────────────────────────────────────────── */
async function _pmRenderRequests(body) {
  body.innerHTML = `<div class="pm-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { incoming, outgoing } = await pmFetch('list_requests');
    body.innerHTML = `
      <div class="pm-requests-section">
        <div class="pm-section-title">📥 Incoming (${incoming.length})</div>
        ${incoming.length ? incoming.map(r => _pmRequestCard(r, 'incoming')).join('') :
          `<div class="pm-empty">No incoming requests</div>`}
      </div>
      <div class="pm-requests-section">
        <div class="pm-section-title">📤 Outgoing (${outgoing.length})</div>
        ${outgoing.length ? outgoing.map(r => _pmRequestCard(r, 'outgoing')).join('') :
          `<div class="pm-empty">No outgoing requests</div>`}
      </div>`;
  } catch (e) { body.innerHTML = `<div class="pm-error">⚠ ${pmEsc(e.message)}</div>`; }
}

function _pmRequestCard(r, dir) {
  const [c1,c2]  = (r.avatar_color_gradient||'#a855f7,#ec4899').split(',');
  const name     = r.full_name || r.username;
  const statusClass = { pending:'pm-status-pending', accepted:'pm-status-accepted', declined:'pm-status-declined', expired:'pm-status-declined' }[r.status] || '';

  return `
    <div class="pm-request-card">
      <div class="pm-avatar pm-avatar-sm" style="background:linear-gradient(135deg,${c1},${c2})">
        ${(name||'?')[0].toUpperCase()}
      </div>
      <div class="pm-request-info">
        <div class="pm-card-name">${pmEsc(name)}</div>
        ${r.note ? `<div class="pm-request-note">"${pmEsc(r.note)}"</div>` : ''}
        ${r.matched_via ? `<div class="pm-request-via">via ${pmEsc(r.matched_via)}</div>` : ''}
        <div class="pm-request-meta">
          ${r.score > 0 ? `<span class="pm-score-badge" style="background:${_pmScoreColor(r.score)}20;color:${_pmScoreColor(r.score)}">${Math.round(r.score)}% match</span>` : ''}
          <span class="pm-status-badge ${statusClass}">${r.status}</span>
        </div>
      </div>
      ${dir === 'incoming' && r.status === 'pending' ? `
        <div class="pm-request-actions">
          <button class="pm-btn-primary pm-btn-xs" onclick="_pmRespond(${r.id},'accepted',this)">✓ Accept</button>
          <button class="pm-btn-decline pm-btn-xs" onclick="_pmRespond(${r.id},'declined',this)">✕ Decline</button>
        </div>` : ''}
    </div>`;
}

async function _pmRespond(reqId, response, btn) {
  btn.disabled = true;
  try {
    await pmFetch('respond_request', {}, 'POST', { request_id: reqId, response });
    if (window.showToast) showToast(response === 'accepted' ? '✓ Request accepted!' : '✕ Request declined', 'success');
    _pmShowTab('requests');
  } catch (e) {
    btn.disabled = false;
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window._pmRespond = _pmRespond;

/* ─────────────────────────────────────────────────────────────────────────────
   4. LEADERBOARD
   ───────────────────────────────────────────────────────────────────────────── */
async function _pmRenderLeaderboard(body) {
  body.innerHTML = `<div class="pm-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { leaderboard } = await pmFetch('get_leaderboard');
    if (!leaderboard.length) { body.innerHTML = `<div class="pm-empty">No data yet — complete your profile first</div>`; return; }
    body.innerHTML = `
      <div class="pm-section-title" style="margin-bottom:10px">🏆 Your Top Compatible Peers</div>
      <div class="pm-leaderboard-list">
        ${leaderboard.map((p, i) => {
          const [c1,c2] = (p.avatar_color_gradient||'#a855f7,#ec4899').split(',');
          const medals  = ['🥇','🥈','🥉'];
          return `
            <div class="pm-leaderboard-row">
              <span class="pm-lb-rank">${medals[i] || `#${i+1}`}</span>
              <div class="pm-avatar pm-avatar-sm" style="background:linear-gradient(135deg,${c1},${c2})">
                ${(p.full_name||p.username||'?')[0]}
                ${p.is_online?'<span class="pm-online-dot"></span>':''}
              </div>
              <div class="pm-lb-info">
                <div class="pm-card-name">${pmEsc(p.full_name||p.username)}</div>
                <div class="pm-card-bio">${p.shared_subjects} subjects · ${p.shared_interests} interests · ${p.shared_hobbies} hobbies</div>
              </div>
              <div class="pm-lb-score" style="color:${_pmScoreColor(p.score_total)}">${Math.round(p.score_total)}%</div>
              <button class="pm-btn-primary pm-btn-xs" onclick="openSendRequestModal(${p.peer_id},'${pmEsc(p.full_name||p.username)}',${Math.round(p.score_total)})">Connect</button>
            </div>`;
        }).join('')}
      </div>`;
  } catch (e) { body.innerHTML = `<div class="pm-error">⚠ ${pmEsc(e.message)}</div>`; }
}

/* ─────────────────────────────────────────────────────────────────────────────
   5. PROFILE EDITOR
   ───────────────────────────────────────────────────────────────────────────── */
function _pmRenderProfileEditor(body) {
  const tags     = _pmTags     || { subjects:[], hobbies:[], interests:[] };
  const profile  = _pmProfile  || { prefs:{}, subjects:[], hobbies:[], interests:[] };

  const mySubIds  = new Set(profile.subjects.map(s => String(s.subject_id)));
  const myHobIds  = new Set(profile.hobbies.map(h => String(h.hobby_id)));
  const myIntIds  = new Set(profile.interests.map(i => String(i.interest_id)));

  const grouped = (arr, key) => [...new Set(arr.map(x => x[key]))].map(cat => ({
    cat, items: arr.filter(x => x[key] === cat)
  }));

  const mkToggle = (item, selectedSet, type) => `
    <button class="pm-tag-toggle ${selectedSet.has(String(item.id)) ? 'pm-tag-selected' : ''}"
      data-type="${type}" data-id="${item.id}"
      onclick="_pmToggleTag(this,'${type}',${item.id})">
      ${item.icon||''} ${pmEsc(item.name)}
    </button>`;

  body.innerHTML = `
    <div class="pm-profile-form">

      <div class="pm-section-title">📚 Study Preferences</div>
      <div class="pm-prefs-grid">
        ${_pmPrefSelect('Study style','pmPrefStyle','study_style',
          ['solo','group','mixed'], profile.prefs.study_style||'mixed')}
        ${_pmPrefSelect('Session length','pmPrefSession','session_length',
          ['short','medium','long'], profile.prefs.session_length||'medium',
          ['< 1 hour','1–2 hours','2+ hours'])}
        ${_pmPrefSelect('Best time','pmPrefTime','time_preference',
          ['morning','afternoon','evening','night','flexible'], profile.prefs.time_preference||'flexible')}
        ${_pmPrefSelect('Learning mode','pmPrefMode','learning_mode',
          ['visual','auditory','reading','kinesthetic','mixed'], profile.prefs.learning_mode||'mixed')}
        ${_pmPrefSelect('Pace','pmPrefPace','pace',
          ['slow','moderate','fast','adaptive'], profile.prefs.pace||'moderate')}
        ${_pmPrefSelect('Communication','pmPrefComm','comm_style',
          ['frequent','occasional','minimal'], profile.prefs.comm_style||'occasional')}
      </div>

      <div class="pm-avail-row">
        <div class="pm-section-title" style="margin-bottom:8px">📅 Available days</div>
        <div class="pm-day-toggles">
          ${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map((d,i) => {
            const bit  = 1 << i;
            const avail = parseInt(profile.prefs.availability_days ?? 127);
            return `<button class="pm-day-btn ${(avail & bit) ? 'pm-day-on' : ''}"
              data-bit="${bit}" onclick="_pmToggleDay(this)">${d}</button>`;
          }).join('')}
        </div>
      </div>

      <div class="pm-section-title" style="margin-top:16px">🎓 Subjects I study / can tutor</div>
      <div class="pm-section-note">Select subjects you're studying or can help others with</div>
      ${grouped(tags.subjects,'category').map(({cat,items}) => `
        <div class="pm-tag-group">
          <div class="pm-tag-group-label">${pmEsc(cat||'Other')}</div>
          <div class="pm-tag-cloud">
            ${items.map(s => mkToggle(s, mySubIds,'subject')).join('')}
          </div>
        </div>`).join('')}

      <div class="pm-section-title" style="margin-top:16px">🎮 Hobbies</div>
      ${grouped(tags.hobbies,'category').map(({cat,items}) => `
        <div class="pm-tag-group">
          <div class="pm-tag-group-label">${pmEsc(cat||'Other')}</div>
          <div class="pm-tag-cloud">
            ${items.map(h => mkToggle(h, myHobIds,'hobby')).join('')}
          </div>
        </div>`).join('')}

      <div class="pm-section-title" style="margin-top:16px">💡 Interests</div>
      ${grouped(tags.interests,'category').map(({cat,items}) => `
        <div class="pm-tag-group">
          <div class="pm-tag-group-label">${pmEsc(cat||'Other')}</div>
          <div class="pm-tag-cloud">
            ${items.map(i => mkToggle(i, myIntIds,'interest')).join('')}
          </div>
        </div>`).join('')}

      <div class="pm-profile-footer">
        <div id="pmSaveStatus" class="notes-status"></div>
        <button class="pm-btn-primary" onclick="_pmSaveProfile()">💾 Save Profile & Find Matches</button>
      </div>
    </div>`;
}

function _pmPrefSelect(label, id, key, vals, cur, labels = null) {
  return `
    <div class="pm-pref-field">
      <label class="pm-pref-label">${label}</label>
      <select id="${id}" class="pm-select pm-select-full" data-key="${key}">
        ${vals.map((v,i) => `<option value="${v}" ${v===cur?'selected':''}>${pmEsc(labels?.[i]||v.replace(/_/g,' '))}</option>`).join('')}
      </select>
    </div>`;
}

window._pmToggleTag = function(btn, type, id) {
  btn.classList.toggle('pm-tag-selected');
};
window._pmToggleDay = function(btn) { btn.classList.toggle('pm-day-on'); };

async function _pmSaveProfile() {
  const status = document.getElementById('pmSaveStatus');
  if (status) status.textContent = 'Saving…';

  // Gather prefs
  const prefs = {};
  document.querySelectorAll('.pm-select-full[data-key]').forEach(sel => {
    prefs[sel.dataset.key] = sel.value;
  });
  // Availability bitmask
  let avail = 0;
  document.querySelectorAll('.pm-day-btn.pm-day-on').forEach(b => { avail |= parseInt(b.dataset.bit); });
  prefs.availability_days = avail || 127;

  // Gather selected tags
  const subjects  = [...document.querySelectorAll('.pm-tag-toggle.pm-tag-selected[data-type="subject"]')]
    .map(b => ({ id: parseInt(b.dataset.id), role: 'studying', proficiency: 'intermediate' }));
  const hobbies   = [...document.querySelectorAll('.pm-tag-toggle.pm-tag-selected[data-type="hobby"]')]
    .map(b => ({ id: parseInt(b.dataset.id) }));
  const interests = [...document.querySelectorAll('.pm-tag-toggle.pm-tag-selected[data-type="interest"]')]
    .map(b => ({ id: parseInt(b.dataset.id) }));

  try {
    await pmFetch('save_profile', {}, 'POST', { prefs, subjects, hobbies, interests });
    _pmProfile = await pmFetch('get_profile');
    if (status) status.textContent = '✓ Saved! Recomputing matches…';
    if (window.showToast) showToast('✓ Profile saved! Finding new matches…', 'success');
    setTimeout(() => _pmShowTab('matches'), 800);
  } catch (e) {
    if (status) status.textContent = '⚠ ' + e.message;
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window._pmSaveProfile = _pmSaveProfile;

/* ─────────────────────────────────────────────────────────────────────────────
   6. SEND REQUEST MODAL
   ───────────────────────────────────────────────────────────────────────────── */
function openSendRequestModal(userId, name, pct) {
  const m = document.getElementById('pmSendRequestModal');
  if (!m) return;
  document.getElementById('pmReqTargetName').textContent = name;
  document.getElementById('pmReqScore').textContent = pct > 0 ? `${pct}% compatibility` : '';
  document.getElementById('pmReqNote').value = '';
  m.dataset.targetId   = userId;
  m.dataset.targetName = name;
  m.dataset.pct        = pct;
  m.style.display = 'flex';
  requestAnimationFrame(() => m.classList.add('modal-open'));
}
window.openSendRequestModal = openSendRequestModal;

function closeSendRequestModal() {
  const m = document.getElementById('pmSendRequestModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display='none',220); }
}
window.closeSendRequestModal = closeSendRequestModal;

async function submitSendRequest() {
  const m          = document.getElementById('pmSendRequestModal');
  const userId     = parseInt(m?.dataset.targetId || 0);
  const note       = document.getElementById('pmReqNote')?.value?.trim();
  const btn        = document.getElementById('pmReqSendBtn');
  if (!userId) return;
  if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }

  try {
    const { status, score } = await pmFetch('send_request', {}, 'POST', {
      addressee_id: userId,
      note:         note,
      matched_via:  _pmBuildMatchedVia(userId),
    });
    closeSendRequestModal();
    if (window.showToast) showToast(
      status === 'accepted' ? '✓ Already connected!' : '🤝 Request sent!', 'success'
    );
    // Refresh match list to update button state
    _pmApplyFilters();
  } catch (e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Send Request'; }
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window.submitSendRequest = submitSendRequest;

function _pmBuildMatchedVia(userId) {
  const match = _pmMatches.find(m => m.id === userId);
  if (!match) return '';
  if (match.shared_subjects?.length)  return `subject:${match.shared_subjects[0].name}`;
  if (match.shared_interests?.length) return `interest:${match.shared_interests[0].name}`;
  if (match.shared_hobbies?.length)   return `hobby:${match.shared_hobbies[0].name}`;
  if (match.study_style)              return `style:${match.study_style}`;
  return 'score';
}

/* ─────────────────────────────────────────────────────────────────────────────
   7. COMPATIBILITY DETAIL MODAL
   ───────────────────────────────────────────────────────────────────────────── */
async function openCompatibilityModal(userId, name) {
  const m = document.getElementById('pmCompatModal');
  if (!m) return;
  m.innerHTML = `<div class="modal-box pm-compat-box">
    <div class="modal-header"><span>📊 Compatibility with ${pmEsc(name)}</span>
    <button class="modal-close" onclick="closePmCompatModal()">✕</button></div>
    <div class="modal-body"><div class="pm-loading"><div class="collab-spinner"></div></div></div>
  </div>`;
  m.style.display = 'flex';
  requestAnimationFrame(() => m.classList.add('modal-open'));

  try {
    const data = await pmFetch('get_compatibility', { user_id: userId });
    const s    = data.score;
    const w    = data.weights;

    const radarSVG = _pmRadarSVG([
      { label: 'Subjects',  val: s.subjects,  max: 100, color: '#3b82f6' },
      { label: 'Style',     val: s.style,     max: 100, color: '#a855f7' },
      { label: 'Interests', val: s.interests, max: 100, color: '#f59e0b' },
      { label: 'Hobbies',   val: s.hobbies,   max: 100, color: '#22c55e' },
    ]);

    const tagSection = (title, items, emptyMsg) => {
      if (!items?.length) return `<div class="pm-compat-section"><div class="pm-compat-section-title">${title}</div><div class="pm-empty-sm">${emptyMsg}</div></div>`;
      return `<div class="pm-compat-section">
        <div class="pm-compat-section-title">${title}</div>
        <div class="pm-tag-cloud">${items.map(i=>`<span class="pm-tag">${i.icon||''} ${pmEsc(i.name)}</span>`).join('')}</div>
      </div>`;
    };

    const prefCmp = (myV, theirV, label) => {
      const match = myV && theirV && myV === theirV;
      return `<div class="pm-pref-cmp ${match?'pm-pref-match':'pm-pref-diff'}">
        <span>${label}</span>
        <span>${pmEsc(myV||'–')}</span>
        <span>${match?'✓':'≠'}</span>
        <span>${pmEsc(theirV||'–')}</span>
      </div>`;
    };

    m.innerHTML = `
      <div class="modal-box pm-compat-box">
        <div class="modal-header">
          <span>📊 Compatibility with ${pmEsc(name)}</span>
          <button class="modal-close" onclick="closePmCompatModal()">✕</button>
        </div>
        <div class="modal-body pm-compat-body">
          <div class="pm-compat-top">
            <div class="pm-compat-total-wrap">
              <div class="pm-compat-total" style="color:${_pmScoreColor(s.total)}">${s.total}%</div>
              <div class="pm-compat-total-label">Overall Match</div>
            </div>
            ${radarSVG}
          </div>

          <div class="pm-compat-breakdown">
            ${[['Subjects',w.subjects,s.subjects,'#3b82f6'],
               ['Study Style',w.style,s.style,'#a855f7'],
               ['Interests',w.interests,s.interests,'#f59e0b'],
               ['Hobbies',w.hobbies,s.hobbies,'#22c55e']].map(([lbl,wt,val,col])=>`
              <div class="pm-breakdown-row">
                <span class="pm-breakdown-label">${lbl} <small>(${wt}% weight)</small></span>
                <div class="pm-score-track" style="flex:1">
                  <div class="pm-score-fill" style="width:${Math.round(val)}%;background:${col}"></div>
                </div>
                <span class="pm-score-num" style="color:${col}">${Math.round(val)}</span>
              </div>`).join('')}
          </div>

          ${tagSection('📚 Shared Subjects', data.shared_subjects, 'No subjects in common')}
          ${tagSection('💡 Shared Interests', data.shared_interests, 'No interests in common')}
          ${tagSection('🎮 Shared Hobbies', data.shared_hobbies, 'No hobbies in common')}

          <div class="pm-compat-section">
            <div class="pm-compat-section-title">📋 Study Style Comparison</div>
            <div class="pm-pref-cmp-header">
              <span>Preference</span><span>You</span><span></span><span>${pmEsc(name)}</span>
            </div>
            ${prefCmp(data.my_prefs?.study_style, data.their_prefs?.study_style, 'Study style')}
            ${prefCmp(data.my_prefs?.time_preference, data.their_prefs?.time_preference, 'Best time')}
            ${prefCmp(data.my_prefs?.pace, data.their_prefs?.pace, 'Pace')}
            ${prefCmp(data.my_prefs?.session_length, data.their_prefs?.session_length, 'Session length')}
            ${prefCmp(data.my_prefs?.comm_style, data.their_prefs?.comm_style, 'Communication')}
          </div>
        </div>
        <div class="modal-footer">
          <button class="pm-btn-primary" onclick="openSendRequestModal(${userId},'${pmEsc(name)}',${s.total})">🤝 Connect</button>
          <button class="timer-reset-btn" onclick="closePmCompatModal()">Close</button>
        </div>
      </div>`;
  } catch (e) {
    m.innerHTML = `<div class="modal-box pm-compat-box"><div class="modal-header">
      <span>Compatibility</span><button class="modal-close" onclick="closePmCompatModal()">✕</button>
    </div><div class="modal-body pm-error">⚠ ${pmEsc(e.message)}</div></div>`;
  }
}
window.openCompatibilityModal = openCompatibilityModal;

function closePmCompatModal() {
  const m = document.getElementById('pmCompatModal');
  if (m) { m.classList.remove('modal-open'); setTimeout(() => m.style.display='none',220); }
}
window.closePmCompatModal = closePmCompatModal;

/* ── Radar chart SVG ─────────────────────────────────────────────────────── */
function _pmRadarSVG(dims) {
  const cx=90, cy=90, r=65, n=dims.length;
  const angle = i => (Math.PI * 2 * i / n) - Math.PI / 2;
  const pt = (i, frac) => [cx + Math.cos(angle(i)) * r * frac, cy + Math.sin(angle(i)) * r * frac];

  // Grid rings
  let grid = '';
  for (let g=1;g<=4;g++) {
    const pts = dims.map((_,i)=>pt(i,g/4).join(',')).join(' ');
    grid += `<polygon points="${pts}" fill="none" stroke="var(--app-border)" stroke-width="0.8"/>`;
  }
  // Axes
  let axes = dims.map((_,i) => {
    const [x,y] = pt(i,1);
    return `<line x1="${cx}" y1="${cy}" x2="${x}" y2="${y}" stroke="var(--app-border)" stroke-width="0.8"/>`;
  }).join('');
  // Data polygon
  const dataPoints = dims.map((d,i) => pt(i, d.val/100).join(',')).join(' ');
  const dataPoly = `<polygon points="${dataPoints}" fill="rgba(168,85,247,0.2)" stroke="#a855f7" stroke-width="2"/>`;
  // Labels + dots
  let dots = '', labels = '';
  dims.forEach((d,i) => {
    const [dx,dy] = pt(i,1.22);
    const [px,py] = pt(i, d.val/100);
    labels += `<text x="${dx}" y="${dy}" text-anchor="middle" dominant-baseline="middle" font-size="9" fill="var(--text-muted)">${pmEsc(d.label)}</text>`;
    dots   += `<circle cx="${px}" cy="${py}" r="3" fill="${d.color}"/>`;
  });

  return `<svg class="pm-radar" viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg">
    ${grid}${axes}${dataPoly}${dots}${labels}
  </svg>`;
}

/* ── Override refreshMatches + _autoLoadMatches to use new API ───────────── */
window._pmOverrideRefreshMatches = true;

const _origRefreshMatches = window.refreshMatches;
window.refreshMatches = async function(btn) {
  if (btn) { btn.classList.add('spinning'); btn.disabled = true; }
  try {
    const { matches } = await pmFetch('get_matches', { limit: 20 });
    if (!matches?.length) { if (window.showToast) showToast('No matches yet — set up your profile!', 'info'); return; }

    window._allMatches.length = 0;
    matches.forEach(m => window._allMatches.push({
      id:     m.id, name: m.name, detail: m.detail, pct: m.pct,
      type:   m.type, tags: m.tags, grad: m.grad,
    }));

    const miniList = document.getElementById('matchesList');
    if (miniList) {
      miniList.innerHTML = matches.slice(0,3).map(m => {
        const [c1,c2] = (m.grad||'#a855f7,#ec4899').split(',');
        return `
          <div class="match-item" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
            <div style="position:relative;width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;">
              ${(m.name||'?')[0]}
              ${m.is_online?'<span style="position:absolute;bottom:0;right:0;width:9px;height:9px;background:#22c55e;border-radius:50%;border:2px solid var(--bg-secondary)"></span>':''}
            </div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:12px;font-weight:600;color:var(--text-primary);">${pmEsc(m.name)}</div>
              <div style="font-size:11px;color:var(--text-muted);">${pmEsc(m.style_label||m.detail||'')}</div>
              <div style="font-size:11px;color:#a855f7;font-weight:600;">${m.pct}% match</div>
            </div>
            <button onclick="event.stopPropagation();openSendRequestModal(${m.id},'${pmEsc(m.name)}',${m.pct})"
              style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:#c084fc;cursor:pointer;font-family:inherit;font-weight:600;">Connect</button>
          </div>`;
      }).join('');
    }
    if (window.showToast) showToast(`✨ Found ${matches.length} matches!`, 'success');
  } catch { if (_origRefreshMatches && btn) _origRefreshMatches(btn); }
  finally { if (btn) { btn.classList.remove('spinning'); btn.disabled = false; } }
};
