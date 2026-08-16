/* signup-network-fix.js
 * Defensive signup submit handler.
 * Keeps the existing 5-step UI but reports the actual server response instead
 * of incorrectly turning JSON/HTTP errors into the generic "Network error".
 */
(function () {
  'use strict';

  window.doSignup = async function () {
    if (typeof validate === 'function' && !validate(5)) return;
    if (typeof setLoading === 'function') setLoading(true);

    const tags = typeof getAllSelectedTags === 'function' ? getAllSelectedTags() : [];
    const collab = tags.filter(t => t.group === 'collab').map(t => t.slug);
    const goals = tags.filter(t => t.group === 'goal').map(t => t.slug);
    const avail = tags.filter(t => t.group === 'avail').map(t => t.slug);
    const interests = tags
      .filter(t => ['tech', 'academic', 'creative'].includes(t.group))
      .map(t => t.slug);

    const selectedHobbies = Array.isArray(hobbies) ? hobbies : [];
    const studyStyle = collab.includes('solo-learning')
      ? 'solo'
      : collab.includes('team-projects') ? 'group' : 'mixed';

    const payload = {
      full_name: document.getElementById('fullName')?.value.trim() || '',
      email: document.getElementById('email')?.value.trim() || '',
      password: document.getElementById('password')?.value || '',
      course: document.getElementById('course')?.value || '',
      year_level: typeof mapYearLevel === 'function'
        ? mapYearLevel(document.getElementById('year')?.value || '') : 0,
      collab_style: collab,
      goals,
      availability: avail,
      interests,
      hobbies: selectedHobbies,
      terms_agreed: document.getElementById('terms')?.checked || false,
      study_style: studyStyle,
      primary_goal: goals[0] || '',
    };

    try {
      const res = await fetch('../../API/auth/signup.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': typeof getCsrf === 'function' ? getCsrf() : '',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });

      const raw = await res.text();
      let data = null;
      try {
        data = raw ? JSON.parse(raw) : null;
      } catch (parseError) {
        console.error('Signup returned non-JSON:', raw);
        if (typeof showErr === 'function') {
          showErr(5, `Server returned an invalid response (HTTP ${res.status}). Check the browser console.`);
        }
        if (typeof setLoading === 'function') setLoading(false);
        return;
      }

      if (!res.ok || !data?.success) {
        console.error('Signup API error:', res.status, data);
        if (typeof showErr === 'function') {
          showErr(5, data?.error || `Registration failed (HTTP ${res.status}).`);
        }
        if (typeof setLoading === 'function') setLoading(false);
        return;
      }

      const btn = document.getElementById('submitBtn');
      if (btn) {
        btn.textContent = '✓ Account Created!';
        btn.style.background = 'linear-gradient(135deg, #0f9, #0cf)';
        btn.style.boxShadow = '0 0 30px rgba(0,255,180,0.4)';
        btn.disabled = true;
      }

      window.setTimeout(() => {
        window.location.href = data.redirect || '../student/dashboard.php';
      }, 900);
    } catch (error) {
      console.error('Signup request failed:', error);
      if (typeof showErr === 'function') {
        showErr(5, 'Could not reach the signup API. Check that Apache/PHP is running and inspect DevTools → Network for API/auth/signup.php.');
      }
      if (typeof setLoading === 'function') setLoading(false);
    }
  };
})();
