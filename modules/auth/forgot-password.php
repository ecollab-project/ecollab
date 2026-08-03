<?php

declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';

AuthMiddleware::startSession();
AuthMiddleware::redirectIfAuthed();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password – <?= APP_NAME ?></title>
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

  <div class="orb orb1"></div>
  <div class="orb orb2"></div>
  <canvas id="particles"></canvas>

  <div class="page">

    <a href="<?= BASE_URL ?>/index.php" class="nav-logo">
      <div class="ico">🌿</div>
      <?= APP_NAME ?>
    </a>

    <div class="center">
      <div class="card">

        <div class="brain-chip">🔐</div>

        <a href="login.php" class="back-btn" title="Back to login">
          <svg viewBox="0 0 24 24">
            <polyline points="15 18 9 12 15 6" />
          </svg>
        </a>

        <!-- ─── VIEW 1: Enter email ─────────────────────────────────── -->
        <div class="fp-view" id="viewEmail">
          <div class="hero-text">
            <h1><span class="white">Forgot</span><br><span class="grad">Password?</span></h1>
            <p>Enter your email and we'll send a verification code.</p>
          </div>

          <div class="form-box">
            <div class="input-wrap">
              <div class="input-row">
                <input type="email" id="fpEmail" placeholder="Your email address" autocomplete="email" inputmode="email">
                <span class="input-suffix">@fatima.edu.ph</span>
              </div>
            </div>
          </div>

          <button class="forgot-btn" id="fpSubmitBtn" onclick="submitForgot()">Send Reset Code</button>

          <div class="guest-text">
            Remember your password? <a href="login.php">Login</a>
          </div>

          <div class="badge" style="margin-top:10px;">
            <div class="b-ico">🌿</div>
            Built for Fatima Computing
          </div>
        </div>

        <!-- ─── VIEW 2: Enter OTP ──────────────────────────────────── -->
        <div class="fp-view" id="viewOtp" style="display:none;">
          <div class="hero-text">
            <h1><span class="white">Check</span><br><span class="grad">Your Email</span></h1>
            <p>We sent a 6-digit code to<br><strong id="otpEmailDisplay" style="color:#FF4D88;"></strong></p>
          </div>

          <div class="otp-group">
            <?php for ($i = 0; $i < 6; $i++): ?>
              <input type="text"
                class="otp-input"
                maxlength="1"
                inputmode="numeric"
                pattern="[0-9]"
                autocomplete="one-time-code"
                oninput="handleOtpInput(this, <?= $i ?>)"
                onkeydown="handleOtpKeydown(this, <?= $i ?>, event)"
                <?= $i === 0 ? 'onpaste="handleOtpPaste(event)"' : '' ?>>
            <?php endfor; ?>
          </div>

          <div class="resend-row">
            Didn't get it?
            <span class="resend-link disabled" id="resendLink" onclick="resendOtp()">
              Resend <span id="resendCounter"></span>
            </span>
          </div>

          <button class="forgot-btn" id="otpVerifyBtn" onclick="verifyOtp()">Verify Code</button>

          <div class="guest-text">
            <a href="login.php">← Back to login</a>
          </div>
        </div>

        <!-- ─── VIEW 3: New password ───────────────────────────────── -->
        <div class="fp-view" id="viewReset" style="display:none;">
          <div class="hero-text">
            <h1><span class="white">New</span><br><span class="grad">Password</span></h1>
            <p>Choose a strong password for your account.</p>
          </div>

          <!-- Hidden reset token set by JS -->
          <input type="hidden" id="fpResetToken">

          <div class="form-box">
            <div class="input-wrap">
              <div class="input-row">
                <input type="password" id="newPassword" placeholder="New password" autocomplete="new-password">
                <button class="eye-btn" onclick="toggleResetEye('newPassword','eyeNew')" type="button">
                  <svg id="eyeNew" viewBox="0 0 24 24">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
              </div>
            </div>
            <div class="field-divider"></div>
            <div class="input-wrap">
              <div class="input-row">
                <input type="password" id="confirmNewPassword" placeholder="Confirm password" autocomplete="new-password">
                <button class="eye-btn" onclick="toggleResetEye('confirmNewPassword','eyeConf2')" type="button">
                  <svg id="eyeConf2" viewBox="0 0 24 24">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <button class="forgot-btn" id="resetSubmitBtn" onclick="submitReset()">Set New Password</button>
        </div>

      </div>
    </div>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/auth/forgot-password.js" defer></script>
</body>

</html>