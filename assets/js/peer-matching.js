/* Ecollab Phase 4.7 — real peer matching UI. */
(function () {
    'use strict';

    const state = {
        matches: [],
        loading: false
    };

    function baseUrl() {
        return String(window.ECOLLAB_BASE || '').replace(/\/$/, '');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function ensureResults(modal) {
        let results = modal.querySelector('#peerMatchResults');

        if (!results) {
            results = document.createElement('div');
            results.id = 'peerMatchResults';
            results.className = 'peer-match-results';
            modal.querySelector('.mb').appendChild(results);
        }

        return results;
    }

    function renderLoading(results) {
        results.innerHTML = '<div class="peer-match-state"><span class="peer-match-spinner"></span><span>Finding compatible study buddies…</span></div>';
    }

    function renderEmpty(results) {
        results.innerHTML = '<div class="peer-match-state"><div class="peer-match-empty-icon">👥</div><div><strong>No matches yet</strong><div>Complete more subjects, interests, hobbies, or study preferences to improve your recommendations.</div></div></div>';
    }

    function renderError(results, message) {
        results.innerHTML = '<div class="peer-match-state peer-match-error"><div>⚠️</div><div>' + escapeHtml(message) + '</div></div>';
    }

    function renderMatches(results, matches) {
        if (!matches.length) {
            renderEmpty(results);
            return;
        }

        results.innerHTML = matches.map(function (match) {
            const tags = Array.isArray(match.tags) ? match.tags.slice(0, 4) : [];
            const pct = Math.round(Number(match.pct) || 0);
            const component = match.components || {};
            const grad = escapeHtml(match.grad || '#a855f7,#ec4899');
            const name = escapeHtml(match.name || 'Study Buddy');
            const detail = escapeHtml(match.detail || 'Student');
            const id = Number(match.id) || 0;

            return '<article class="peer-match-card" data-peer-id="' + id + '">' +
                '<div class="peer-match-avatar" style="background:linear-gradient(135deg,' + grad + ')">' +
                    escapeHtml((match.initials || name.charAt(0) || '?').toUpperCase()) +
                    '<span class="peer-match-online ' + (match.online ? 'is-online' : '') + '"></span>' +
                '</div>' +
                '<div class="peer-match-main">' +
                    '<div class="peer-match-heading">' +
                        '<div><div class="peer-match-name">' + name + '</div><div class="peer-match-detail">' + detail + '</div></div>' +
                        '<div class="peer-match-score"><strong>' + pct + '%</strong><span>match</span></div>' +
                    '</div>' +
                    '<div class="peer-match-bar"><span style="width:' + Math.max(0, Math.min(100, pct)) + '%"></span></div>' +
                    '<div class="peer-match-components">' +
                        componentPill('Subjects', component.subjects) +
                        componentPill('Style', component.style) +
                        componentPill('Interests', component.interests) +
                        componentPill('Hobbies', component.hobbies) +
                    '</div>' +
                    (tags.length ? '<div class="peer-match-tags">' + tags.map(function (tag) {
                        return '<span>' + escapeHtml(tag) + '</span>';
                    }).join('') + '</div>' : '') +
                    '<div class="peer-match-actions">' +
                        '<button type="button" class="btn-sec peer-match-message" data-peer-id="' + id + '" data-peer-name="' + name + '">Message</button>' +
                        '<button type="button" class="btn-primary peer-match-connect" data-peer-id="' + id + '" data-peer-name="' + name + '">Connect</button>' +
                    '</div>' +
                '</div>' +
            '</article>';
        }).join('');
    }

    function componentPill(label, value) {
        const score = Math.round(Number(value) || 0);
        return '<span><b>' + escapeHtml(label) + '</b> ' + score + '%</span>';
    }

    async function fetchMatches() {
        const response = await fetch(baseUrl() + '/API/chat/get-matches.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });

        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The matching service returned an invalid response.');
        }

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to load study buddy matches.');
        }

        return Array.isArray(payload.matches) ? payload.matches : [];
    }

    async function loadMatches() {
        const modal = document.getElementById('findBuddiesModal');
        if (!modal || state.loading) return;

        const results = ensureResults(modal);
        state.loading = true;
        renderLoading(results);

        try {
            state.matches = await fetchMatches();
            renderMatches(results, state.matches);
        } catch (error) {
            renderError(results, error.message || 'Unable to load matches.');
        } finally {
            state.loading = false;
        }
    }

    function openMessage(name) {
        if (typeof window.openModal === 'function') {
            window.openModal('dmModal', name);
        }
    }

    async function connect(peerId, name, button) {
        if (!peerId || !button) return;

        button.disabled = true;
        button.textContent = 'Sending…';

        try {
            const response = await fetch(baseUrl() + '/API/chat/peer-request.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ user_id: peerId })
            });

            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Unable to send connection request.');
            }

            button.textContent = '✓ Sent';
            button.classList.add('peer-match-sent');
            if (typeof window.toast === 'function') {
                window.toast('Connection request sent to ' + name, 'success', '👫');
            }
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Connect';
            if (typeof window.toast === 'function') {
                window.toast(error.message || 'Unable to connect.', 'error', '❌');
            }
        }
    }

    function wireModal() {
        const modal = document.getElementById('findBuddiesModal');
        if (!modal) return;

        const findButton = modal.querySelector('.mb > div:first-child button');
        if (findButton) {
            findButton.onclick = loadMatches;
        }

        modal.addEventListener('click', function (event) {
            const messageButton = event.target.closest('.peer-match-message');
            if (messageButton) {
                openMessage(messageButton.dataset.peerName || 'Study Buddy');
                return;
            }

            const connectButton = event.target.closest('.peer-match-connect');
            if (connectButton) {
                connect(Number(connectButton.dataset.peerId), connectButton.dataset.peerName || 'Study Buddy', connectButton);
            }
        });
    }

    function observeModalOpen() {
        const modal = document.getElementById('findBuddiesModal');
        if (!modal) return;

        const observer = new MutationObserver(function () {
            if (modal.classList.contains('show') && !state.matches.length && !state.loading) {
                loadMatches();
            }
        });

        observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireModal();
        observeModalOpen();
    });
})();
