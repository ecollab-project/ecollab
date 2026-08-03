/**
 * forgot-password.js — Forgot password / OTP / reset flow
 * Three views: email → otp → new-password
 */

'use strict';

let fpUserId = null;
let resendTimer = null;

function getCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// ── View switcher ─────────────────────────────────────────────────────────
function showView(id) {
  document.querySelectorAll('.fp-view').forEach(v => {
    v.style.display = v.id === id ? 'block' : 'none';
  });
}

// ── Alert helpers ─────────────────────────────────────────────────────────
function showAlert(viewId, msg, type = 'error') {
  const view = document.getElementById(viewId);
  if (!view) return;
  let el = view.querySelector('.alert');
  if (!el) {
    el = document.createElement('div');
    view.prepend(el);
  }
  el.className = 'alert alert-' + type;
  el.textContent = msg;
  el.style.display = 'flex';
}

function setLoading(btnId, loading, label = '') {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  if (loading) {
    btn.innerHTML = '<span class="btn-spinner"></span>' + (label || 'Please wait…');
    btn.disabled = true;
  } else {
    btn.textContent = label;
    btn.disabled = false;
  }
}

// ── Step 1: Submit email ──────────────────────────────────────────────────
async function submitForgot() {
  const email = document.getElementById('fpEmail')?.value.trim() || '';
  if (!email) { showAlert('viewEmail', 'Please enter your email address.'); return; }

  setLoading('fpSubmitBtn', true, 'Sending code…');

  try {
    const res = await fetch('../../API/auth/forgot-password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify({ email }),
    });
    const data = await res.json();

    if (data.success) {
      fpUserId = data.user_id || null;
      // Populate OTP view
      const emailSpan = document.getElementById('otpEmailDisplay');
      if (emailSpan) emailSpan.textContent = email;
      showView('viewOtp');
      document.querySelector('.otp-input')?.focus();
      startResendTimer();
      // DEV: show OTP in console
      if (data.otp_debug) console.info('[DEV] OTP:', data.otp_debug);
    } else {
      showAlert('viewEmail', data.error || 'Failed to send code. Please try again.');
    }
  } catch {
    showAlert('viewEmail', 'Network error. Please try again.');
  } finally {
    setLoading('fpSubmitBtn', false, 'Send Reset Code');
  }
}

// ── Step 2: OTP handling ──────────────────────────────────────────────────
function handleOtpInput(el, idx) {
  // Move to next field on digit entry
  const val = el.value.replace(/\D/g, '');
  el.value = val.slice(-1);
  if (val && idx < 5) {
    document.querySelectorAll('.otp-input')[idx + 1]?.focus();
  }
  el.classList.toggle('filled', el.value !== '');
}

function handleOtpKeydown(el, idx, e) {
  if (e.key === 'Backspace' && !el.value && idx > 0) {
    const prev = document.querySelectorAll('.otp-input')[idx - 1];
    if (prev) { prev.value = ''; prev.classList.remove('filled'); prev.focus(); }
  }
  if (e.key === 'Enter') verifyOtp();
}

function handleOtpPaste(e) {
  e.preventDefault();
  const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
  const inputs = document.querySelectorAll('.otp-input');
  [...text.slice(0, 6)].forEach((ch, i) => {
    if (inputs[i]) { inputs[i].value = ch; inputs[i].classList.add('filled'); }
  });
  inputs[Math.min(text.length, 5)]?.focus();
}

function getOtpValue() {
  return [...document.querySelectorAll('.otp-input')].map(i => i.value).join('');
}

async function verifyOtp() {
  const otp = getOtpValue();
  if (otp.length < 6) { showAlert('viewOtp', 'Please enter all 6 digits.'); return; }
  if (!fpUserId) { showAlert('viewOtp', 'Session expired. Please start again.'); return; }

  setLoading('otpVerifyBtn', true, 'Verifying…');

  try {
    const res = await fetch('../../API/auth/verify-otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify({ user_id: fpUserId, otp }),
    });
    const data = await res.json();

    if (data.success) {
      showView('viewReset');
      document.getElementById('fpResetToken').value = data.reset_token || '';
      document.getElementById('newPassword')?.focus();
    } else {
      showAlert('viewOtp', data.error || 'Incorrect code. Please try again.');
      document.querySelectorAll('.otp-input').forEach(i => { i.value = ''; i.classList.remove('filled'); });
      document.querySelector('.otp-input')?.focus();
    }
  } catch {
    showAlert('viewOtp', 'Network error. Please try again.');
  } finally {
    setLoading('otpVerifyBtn', false, 'Verify Code');
  }
}

// ── Resend timer ──────────────────────────────────────────────────────────
function startResendTimer(seconds = 60) {
  clearInterval(resendTimer);
  const link = document.getElementById('resendLink');
  const counter = document.getElementById('resendCounter');
  if (!link || !counter) return;

  link.classList.add('disabled');
  let remaining = seconds;
  counter.textContent = remaining;

  resendTimer = setInterval(() => {
    remaining--;
    counter.textContent = remaining;
    if (remaining <= 0) {
      clearInterval(resendTimer);
      link.classList.remove('disabled');
      counter.textContent = '';
    }
  }, 1000);
}

async function resendOtp() {
  const link = document.getElementById('resendLink');
  if (link?.classList.contains('disabled')) return;
  const email = document.getElementById('fpEmail')?.value.trim() || '';
  if (!email) { showView('viewEmail'); return; }
  await submitForgot();
  startResendTimer();
}

// ── Step 3: Reset password ────────────────────────────────────────────────
function toggleResetEye(fieldId, iconId) {
  const input = document.getElementById(fieldId);
  const icon = document.getElementById(iconId);
  if (!input || !icon) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  icon.innerHTML = isPass
    ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`
    : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
}

async function submitReset() {
  const newPass = document.getElementById('newPassword')?.value || '';
  const confirm = document.getElementById('confirmNewPassword')?.value || '';
  const token = document.getElementById('fpResetToken')?.value || '';

  if (newPass.length < 8) { showAlert('viewReset', 'Password must be at least 8 characters.'); return; }
  if (newPass !== confirm) { showAlert('viewReset', 'Passwords do not match.'); return; }

  setLoading('resetSubmitBtn', true, 'Updating password…');

  try {
    const res = await fetch('../../API/auth/reset-password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify({ reset_token: token, new_password: newPass, confirm_password: confirm }),
    });
    const data = await res.json();

    if (data.success) {
      showAlert('viewReset', 'Password updated! Redirecting to login…', 'success');
      setTimeout(() => { window.location.href = 'login.php?reset=1'; }, 1800);
    } else {
      showAlert('viewReset', data.error || 'Reset failed. Please try again.');
    }
  } catch {
    showAlert('viewReset', 'Network error. Please try again.');
  } finally {
    setLoading('resetSubmitBtn', false, 'Set New Password');
  }
}

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  showView('viewEmail');

  // Attach OTP paste handler to first input
  document.querySelector('.otp-input')?.addEventListener('paste', handleOtpPaste);

  // Focus on email field
  document.getElementById('fpEmail')?.focus();

  initParticles();
});

// ── Particle canvas (shared) ──────────────────────────────────────────────
function initParticles() {
  const canvas = document.getElementById('particles');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  resize();
  window.addEventListener('resize', resize, { passive: true });
  const particles = Array.from({ length: 50 }, () => ({
    x: Math.random() * window.innerWidth, y: Math.random() * window.innerHeight,
    r: Math.random() * 1.8 + 0.4,
    vx: (Math.random() - 0.5) * 0.35, vy: (Math.random() - 0.5) * 0.35,
    alpha: Math.random() * 0.5 + 0.15,
    color: Math.random() > 0.5 ? '255,45,117' : '168,85,247',
  }));
  function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach(p => {
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = canvas.width; if (p.x > canvas.width) p.x = 0;
      if (p.y < 0) p.y = canvas.height; if (p.y > canvas.height) p.y = 0;
      ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${p.color},${p.alpha})`;
      ctx.shadowBlur = 6; ctx.shadowColor = `rgba(${p.color},0.5)`;
      ctx.fill(); ctx.shadowBlur = 0;
    });
    requestAnimationFrame(animate);
  }
  animate();
}

// Expose
window.submitForgot = submitForgot;
window.verifyOtp = verifyOtp;
window.submitReset = submitReset;
window.handleOtpInput = handleOtpInput;
window.handleOtpKeydown = handleOtpKeydown;
window.resendOtp = resendOtp;
window.toggleResetEye = toggleResetEye;
