<?php

declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';

AuthMiddleware::startSession();
AuthMiddleware::redirectIfAuthed();

// Show success toast if coming from password reset
$resetSuccess = ($_GET['reset'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – <?= APP_NAME ?></title>
  <meta name="robots" content="noindex,nofollow">
  <meta name="csrf-token" content="<?= htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/variables.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/auth.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/auth-mobile.css">
</head>

<body>

  <!-- Blobs -->
  <div class="orb orb1"></div>
  <div class="orb orb2"></div>

  <!-- Particle canvas -->
  <canvas id="particles"></canvas>

  <div class="page">

    <!-- Logo -->
    <a href="<?= BASE_URL ?>/index.php" class="nav-logo">
      <div class="ico">🌿</div>
      <?= APP_NAME ?>
    </a>

    <div class="center">
      <div class="card">

        <!-- Floating brain chip -->
        <div class="brain-chip">🧠</div>

        <!-- Back button -->
        <a href="<?= BASE_URL ?>/index.php" class="back-btn" title="Go back">
          <svg viewBox="0 0 24 24">
            <polyline points="15 18 9 12 15 6" />
          </svg>
        </a>

        <!-- Alert area (server-side + JS target) -->
        <?php
          $ssoError = trim($_GET['sso_error'] ?? '');
        ?>
        <?php if ($resetSuccess): ?>
          <div class="alert alert-success" id="loginAlert">✓ Password updated successfully. Please log in.</div>
        <?php elseif ($ssoError !== ''): ?>
          <div class="alert alert-error" id="loginAlert"><?= htmlspecialchars($ssoError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
          <div class="alert alert-error" id="loginAlert" style="display:none;"></div>
        <?php endif; ?>

        <!-- Hero text -->
        <div class="hero-text">
          <h1>
            <span class="white">Welcome</span><br>
            <span class="grad">Back</span>
          </h1>
          <p>Connect with your Fatima peers and AI study partners.</p>
        </div>

        <!-- Form box -->
        <div class="form-box">
          <!-- Email / Student ID -->
          <div class="input-wrap">
            <div class="input-row">
              <input type="text" id="email" placeholder="Email / Student ID" autocomplete="username" inputmode="email" />
              <span class="input-suffix">@fatima.edu.ph</span>
            </div>
          </div>

          <div class="field-divider"></div>

          <!-- Password -->
          <div class="input-wrap">
            <div class="input-row">
              <input type="password" id="password" placeholder="Password" autocomplete="current-password" />
              <button class="eye-btn" onclick="togglePassword()" type="button" aria-label="Toggle password">
                <svg id="eye-icon" viewBox="0 0 24 24">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                  <circle cx="12" cy="12" r="3" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Options row -->
        <div class="options-row">
          <label class="remember">
            <input type="checkbox" id="remember" checked>
            <div class="check-box">
              <svg viewBox="0 0 12 12">
                <polyline points="2 6 5 9 10 3" />
              </svg>
            </div>
            Remember me
          </label>
          <a href="forgot-password.php" class="forgot">Forgot Password?</a>
        </div>

        <!-- Login button -->
        <button class="login-btn" id="loginBtn" onclick="handleLogin()">Login</button>

        <!-- Google SSO -->
        <a href="<?= BASE_URL ?>/API/auth/oauth-init.php?provider=google" class="social-btn">
          <svg class="g-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05" />
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
          </svg>
          <span>Continue with Google</span>
        </a>

        <!-- Microsoft SSO -->
        <a href="<?= BASE_URL ?>/API/auth/oauth-init.php?provider=microsoft" class="social-btn">
          <div class="ms-icon">
            <span style="background:#F25022;"></span>
            <span style="background:#7FBA00;"></span>
            <span style="background:#00A4EF;"></span>
            <span style="background:#FFB900;"></span>
          </div>
          <div class="s-text">
            <span>Continue with Microsoft</span>
            <span class="s-sub">University SSO</span>
          </div>
        </a>

        <!-- Sign up link -->
        <div class="signup-text">
          Don't have an account? <a href="signup.php">Sign Up</a>
        </div>

        <!-- Badge -->
        <div class="badge">
          <div class="b-ico">🌿</div>
          Built for Fatima Computing
        </div>

      </div>
    </div>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/auth/login.js" defer></script>
</body>

</html>