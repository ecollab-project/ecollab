/**
 * login.js — Login page interactions
 * Handles: form submission, password toggle, particles, CSRF, Remember Me.
 */

'use strict';

// ── Helpers ────────────────────────────────────────────────────────────────
function getCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function showAlert(msg, type = 'error') {
  let el = document.getElementById('loginAlert');
  if (!el) {
    el = document.createElement('div');
    el.id = 'loginAlert';
    const form = document.querySelector('.form-box');
    form?.parentNode.insertBefore(el, form);
  }
  el.className = 'alert alert-' + type;
  el.textContent = msg;
  el.style.display = 'flex';
}

function clearAlert() {
  const el = document.getElementById('loginAlert');
  if (el) el.style.display = 'none';
}

function setLoading(loading) {
  const btn = document.getElementById('loginBtn');
  if (!btn) return;
  if (loading) {
    btn.innerHTML = '<span class="btn-spinner"></span>Logging in…';
    btn.disabled = true;
  } else {
    btn.textContent = 'Login';
    btn.disabled = false;
  }
}

// ── Password toggle ─────────────────────────────────────────────────────────
function togglePassword() {
  const input = document.getElementById('password');
  const icon = document.getElementById('eye-icon');
  if (!input || !icon) return;

  if (input.type === 'password') {
    input.type = 'text';
    icon.innerHTML = `
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
      <line x1="1" y1="1" x2="23" y2="23"/>`;
  } else {
    input.type = 'password';
    icon.innerHTML = `
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>`;
  }
}

// ── Login + OTP verification ──────────────────────────────────────────────
let pendingLoginUserId = 0;
let otpExpiresAt = 0;
let otpTimer = null;

function showOtpModal(userId, debugOtp = '') {
  pendingLoginUserId = Number(userId) || 0;
  if (!pendingLoginUserId) {
    showAlert('Unable to start login verification. Please try again.');
    setLoading(false);
    return;
  }

  const modal = document.getElementById('loginOtpModal');
  const input = document.getElementById('loginOtp');
  const debug = document.getElementById('otpDebug');

  document.getElementById('email')?.setAttribute('disabled', 'disabled');
  document.getElementById('password')?.setAttribute('disabled', 'disabled');
  document.getElementById('loginBtn')?.setAttribute('disabled', 'disabled');

  if (debugOtp && debug) {
    debug.hidden = false;
    debug.textContent = 'DEV OTP: ' + debugOtp;
  } else if (debug) {
    debug.hidden = true;
    debug.textContent = '';
  }

  otpExpiresAt = Date.now() + (10 * 60 * 1000);
  updateOtpCountdown();
  clearInterval(otpTimer);
  otpTimer = setInterval(updateOtpCountdown, 1000);

  modal.hidden = false;
  modal.setAttribute('aria-hidden', 'false');
  input.value = '';
  setTimeout(() => input.focus(), 50);
}

function hideOtpModal() {
  const modal = document.getElementById('loginOtpModal');
  if (modal) {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }
  clearInterval(otpTimer);
  otpTimer = null;
  pendingLoginUserId = 0;

  document.getElementById('email')?.removeAttribute('disabled');
  document.getElementById('password')?.removeAttribute('disabled');
  setLoading(false);
}

function updateOtpCountdown() {
  const el = document.getElementById('otpCountdown');
  if (!el) return;
  const seconds = Math.max(0, Math.ceil((otpExpiresAt - Date.now()) / 1000));
  const min = Math.floor(seconds / 60);
  const sec = String(seconds % 60).padStart(2, '0');
  el.textContent = seconds > 0
    ? `Code expires in ${min}:${sec}`
    : 'Code expired. Please log in again.';
  if (seconds === 0) clearInterval(otpTimer);
}

async function verifyLoginOtp() {
  clearAlert();
  const otp = document.getElementById('loginOtp')?.value.replace(/\D/g, '') || '';

  if (!pendingLoginUserId) {
    showAlert('Your login verification session is missing. Please log in again.');
    hideOtpModal();
    return;
  }
  if (otp.length !== 6) {
    showAlert('Enter the 6-digit verification code.');
    return;
  }
  if (Date.now() >= otpExpiresAt) {
    showAlert('The verification code has expired. Please log in again.');
    return;
  }

  const btn = document.getElementById('verifyOtpBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="btn-spinner"></span>Verifying…';
  }

  try {
    const res = await fetch('../../API/auth/verify-otp.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf(),
      },
      body: JSON.stringify({
        user_id: pendingLoginUserId,
        otp,
      }),
    });

    const data = await res.json();

    if (data.success) {
      showAlert('Verification successful! Redirecting…', 'success');
      clearInterval(otpTimer);
      window.location.href = data.redirect || '/';
      return;
    }

    showAlert(data.error || 'Incorrect verification code.');
  } catch {
    showAlert('Network error. Please try again.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Verify & Login';
    }
  }
}

async function handleLogin() {
  clearAlert();
  const identifier = document.getElementById('email')?.value.trim() || '';
  const password = document.getElementById('password')?.value || '';
  const remember = document.getElementById('remember')?.checked || false;

  if (!identifier) { showAlert('Please enter your email or Student ID.'); return; }
  if (!password) { showAlert('Please enter your password.'); return; }

  setLoading(true);
  try {
    const res = await fetch('../../API/auth/login.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf(),
      },
      body: JSON.stringify({ identifier, password, remember }),
    });

    const data = await res.json();

    if (data.success && data.otp_required) {
      showAlert(data.message || 'Verification code sent.', 'success');
      showOtpModal(data.user_id, data.otp_debug || '');
      return;
    }

    if (data.success) {
      showAlert('Login successful! Redirecting…', 'success');
      setTimeout(() => { window.location.href = data.redirect || '/'; }, 700);
      return;
    }

    showAlert(data.error || 'Login failed. Please try again.');
    setLoading(false);
  } catch {
    showAlert('Network error. Please try again.');
    setLoading(false);
  }
}

// ── Keyboard: Enter to submit ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('email')?.addEventListener('keydown', e => { if (e.key === 'Enter') document.getElementById('password')?.focus(); });
  document.getElementById('password')?.addEventListener('keydown', e => { if (e.key === 'Enter') handleLogin(); });

  // Input focus style
  document.querySelectorAll('.input-row input').forEach(inp => {
    inp.addEventListener('focus', () => inp.closest('.input-row').style.borderColor = 'rgba(255,45,117,0.5)');
    inp.addEventListener('blur', () => inp.closest('.input-row').style.borderColor = 'rgba(255,255,255,0.06)');
  });

  // Forgot password link
  document.querySelector('.forgot')?.addEventListener('click', e => {
    e.preventDefault();
    window.location.href = 'forgot-password.php';
  });

  document.getElementById('verifyOtpBtn')?.addEventListener('click', verifyLoginOtp);
  document.getElementById('otpBackBtn')?.addEventListener('click', () => window.location.reload());
  document.getElementById('otpBackLink')?.addEventListener('click', () => window.location.reload());
  document.getElementById('loginOtp')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') verifyLoginOtp();
  });
  document.getElementById('loginOtp')?.addEventListener('input', e => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
  });

  initParticles();
});

// ── Animated particle canvas ─────────────────────────────────────────────────
function initParticles() {
  const canvas = document.getElementById('particles');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  resize();
  window.addEventListener('resize', resize, { passive: true });

  const particles = Array.from({ length: 55 }, () => ({
    x: Math.random() * window.innerWidth,
    y: Math.random() * window.innerHeight,
    r: Math.random() * 1.8 + 0.4,
    vx: (Math.random() - 0.5) * 0.35,
    vy: (Math.random() - 0.5) * 0.35,
    alpha: Math.random() * 0.5 + 0.15,
    color: Math.random() > 0.5 ? '255,45,117' : '168,85,247',
  }));

  function drawConnections() {
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const dist = Math.hypot(dx, dy);
        if (dist < 110) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(255,45,117,${0.07 * (1 - dist / 110)})`;
          ctx.lineWidth = 0.7;
          ctx.stroke();
        }
      }
    }
  }

  function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawConnections();
    particles.forEach(p => {
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = canvas.width;
      if (p.x > canvas.width) p.x = 0;
      if (p.y < 0) p.y = canvas.height;
      if (p.y > canvas.height) p.y = 0;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${p.color},${p.alpha})`;
      ctx.shadowBlur = 6;
      ctx.shadowColor = `rgba(${p.color},0.5)`;
      ctx.fill();
      ctx.shadowBlur = 0;
    });
    requestAnimationFrame(animate);
  }
  animate();
}

// Expose globally for onclick attributes
window.handleLogin = handleLogin;
window.togglePassword = togglePassword;
window.verifyLoginOtp = verifyLoginOtp;
