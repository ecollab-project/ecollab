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

// ── Login form submit ────────────────────────────────────────────────────────
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
    if (data.success) {
      showAlert('Login successful! Redirecting…', 'success');
      setTimeout(() => { window.location.href = data.redirect || '/'; }, 700);
    } else {
      showAlert(data.error || 'Login failed. Please try again.');
      setLoading(false);
    }
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
