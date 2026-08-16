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
  <title>Sign Up – <?= APP_NAME ?></title>
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
      <div class="card signup-card">

        <div class="brain-chip">🧠</div>

        <button class="back-btn" id="topBackBtn" onclick="goBack()" title="Go back">
          <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6" /></svg>
        </button>

        <div class="step-header">
          <div class="step-label" id="stepLabel">Personal Info</div>
          <div class="step-counter" id="stepCounter">Step 1 of 5</div>
        </div>
        <div class="steps" id="stepDots">
          <div class="step-dot active" id="dot1"></div>
          <div class="step-dot" id="dot2"></div>
          <div class="step-dot" id="dot3"></div>
          <div class="step-dot" id="dot4"></div>
          <div class="step-dot" id="dot5"></div>
        </div>

        <div class="step-panel active" id="panel1">
          <div class="form-box">
            <div class="input-wrap"><div class="input-row"><input type="text" id="fullName" placeholder="Full Name" autocomplete="name"></div></div>
            <div class="field-divider"></div>
            <div class="input-wrap"><div class="input-row"><input type="text" id="email" placeholder="Email / Student ID" autocomplete="off" inputmode="email"><span class="input-suffix">@fatima.edu.ph</span></div></div>
            <div class="field-divider"></div>
            <div class="input-wrap" id="otpWrap" style="display:none;"><div class="input-row otp-row"><input type="text" id="otpInput" placeholder="Enter 6-digit code" maxlength="6" inputmode="numeric" autocomplete="one-time-code"><button class="otp-send-btn" id="otpSendBtn" onclick="sendOtp()" type="button">Send Code</button></div><p class="otp-hint" id="otpHint"></p></div>
            <div class="field-divider" id="otpDivider" style="display:none;"></div>
            <div class="input-wrap"><div class="input-row"><input type="password" id="password" placeholder="Password" oninput="updateStrength(this.value)" autocomplete="new-password"><button class="eye-btn" onclick="toggleEye('password','eyePass')" type="button" aria-label="Toggle password"><svg id="eyePass" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg></button></div></div>
            <div class="strength-wrap"><div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div></div>
            <div class="input-wrap"><div class="input-row"><input type="password" id="confirmPass" placeholder="Confirm Password" autocomplete="new-password"><button class="eye-btn" onclick="toggleEye('confirmPass','eyeConf')" type="button" aria-label="Toggle confirm password"><svg id="eyeConf" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg></button></div></div>
          </div>
          <p class="err-msg" id="err1"></p>
          <button class="signup-btn" onclick="nextStep(1)">Next →</button>
        </div>

        <div class="step-panel" id="panel2">
          <div class="form-box">
            <div class="input-wrap"><div class="input-row"><select id="course" onchange="this.classList.add('filled')"><option value="" disabled selected>Select your Course</option><option>BS Computer Science</option><option>BS Information Technology</option><option>BS Information Systems</option><option>BS Computer Engineering</option></select></div></div>
            <div class="field-divider"></div>
            <div class="input-wrap"><div class="input-row"><select id="year" onchange="this.classList.add('filled')"><option value="" disabled selected>Select your Year Level</option><option>1st Year</option><option>2nd Year</option><option>3rd Year</option><option>4th Year</option></select></div></div>
          </div>
          <p class="err-msg" id="err2"></p>
          <div class="nav-btns"><button class="btn-back" onclick="goBack()">← Back</button><button class="btn-next" onclick="nextStep(2)">Next →</button></div>
        </div>

        <div class="step-panel" id="panel3">
          <div class="signup-scroll-box">
            <div class="tag-section"><div class="tag-section-label">🎯 Collaboration Style</div><div class="tags-wrap">
              <span class="tag" data-group="collab" data-slug="solo-learning" onclick="toggleTag(this)">Solo Learning</span><span class="tag" data-group="collab" data-slug="team-projects" onclick="toggleTag(this)">Team Projects</span><span class="tag" data-group="collab" data-slug="hackathons" onclick="toggleTag(this)">Hackathons</span><span class="tag" data-group="collab" data-slug="study-groups" onclick="toggleTag(this)">Study Groups</span><span class="tag" data-group="collab" data-slug="mentoring" onclick="toggleTag(this)">Mentoring</span><span class="tag" data-group="collab" data-slug="peer-tutoring" onclick="toggleTag(this)">Peer Tutoring</span>
            </div></div>
            <div class="tag-section"><div class="tag-section-label">🚀 Goals</div><div class="tags-wrap">
              <span class="tag" data-group="goal" data-slug="pass-exams" onclick="toggleTag(this)">Pass Exams</span><span class="tag" data-group="goal" data-slug="build-portfolio" onclick="toggleTag(this)">Build a Portfolio</span><span class="tag" data-group="goal" data-slug="learn-new-skills" onclick="toggleTag(this)">Learn New Skills</span><span class="tag" data-group="goal" data-slug="find-teammates" onclick="toggleTag(this)">Find Teammates</span><span class="tag" data-group="goal" data-slug="networking" onclick="toggleTag(this)">Networking</span><span class="tag" data-group="goal" data-slug="freelancing" onclick="toggleTag(this)">Freelancing</span><span class="tag" data-group="goal" data-slug="startup-building" onclick="toggleTag(this)">Startup Building</span>
            </div></div>
            <div class="tag-section"><div class="tag-section-label">📅 Availability</div><div class="tags-wrap">
              <span class="tag" data-group="avail" data-slug="weekday-mornings" onclick="toggleTag(this)">Weekday Mornings</span><span class="tag" data-group="avail" data-slug="weekday-evenings" onclick="toggleTag(this)">Weekday Evenings</span><span class="tag" data-group="avail" data-slug="weekends" onclick="toggleTag(this)">Weekends</span><span class="tag" data-group="avail" data-slug="late-nights" onclick="toggleTag(this)">Late Nights</span><span class="tag" data-group="avail" data-slug="flexible" onclick="toggleTag(this)">Flexible</span>
            </div></div>
          </div>
          <p class="err-msg" id="err3"></p><div class="nav-btns"><button class="btn-back" onclick="goBack()">← Back</button><button class="btn-next" onclick="nextStep(3)">Next →</button></div>
        </div>

        <div class="step-panel" id="panel4">
          <div class="signup-scroll-box">
            <div class="tag-section"><div class="tag-section-label">💻 Tech</div><div class="tags-wrap">
              <span class="tag" data-group="tech" data-slug="ai" onclick="toggleTag(this)">AI</span><span class="tag" data-group="tech" data-slug="web-dev" onclick="toggleTag(this)">Web Development</span><span class="tag" data-group="tech" data-slug="mobile-dev" onclick="toggleTag(this)">Mobile Development</span><span class="tag" data-group="tech" data-slug="cybersecurity" onclick="toggleTag(this)">Cybersecurity</span><span class="tag" data-group="tech" data-slug="data-science" onclick="toggleTag(this)">Data Science</span><span class="tag" data-group="tech" data-slug="ui-ux" onclick="toggleTag(this)">UI/UX Design</span><span class="tag" data-group="tech" data-slug="cloud" onclick="toggleTag(this)">Cloud Computing</span><span class="tag" data-group="tech" data-slug="devops" onclick="toggleTag(this)">DevOps</span><span class="tag" data-group="tech" data-slug="game-dev" onclick="toggleTag(this)">Game Development</span>
            </div></div>
            <div class="tag-section"><div class="tag-section-label">📚 Academic</div><div class="tags-wrap">
              <span class="tag" data-group="academic" data-slug="mathematics" onclick="toggleTag(this)">Mathematics</span><span class="tag" data-group="academic" data-slug="programming" onclick="toggleTag(this)">Programming</span><span class="tag" data-group="academic" data-slug="research" onclick="toggleTag(this)">Research</span><span class="tag" data-group="academic" data-slug="science" onclick="toggleTag(this)">Science</span><span class="tag" data-group="academic" data-slug="engineering" onclick="toggleTag(this)">Engineering</span><span class="tag" data-group="academic" data-slug="business" onclick="toggleTag(this)">Business</span><span class="tag" data-group="academic" data-slug="public-speaking" onclick="toggleTag(this)">Public Speaking</span><span class="tag" data-group="academic" data-slug="writing" onclick="toggleTag(this)">Writing</span>
            </div></div>
            <div class="tag-section"><div class="tag-section-label">🎨 Creative</div><div class="tags-wrap">
              <span class="tag" data-group="creative" data-slug="graphic-design" onclick="toggleTag(this)">Graphic Design</span><span class="tag" data-group="creative" data-slug="video-editing" onclick="toggleTag(this)">Video Editing</span><span class="tag" data-group="creative" data-slug="photography" onclick="toggleTag(this)">Photography</span><span class="tag" data-group="creative" data-slug="music" onclick="toggleTag(this)">Music</span><span class="tag" data-group="creative" data-slug="animation" onclick="toggleTag(this)">Animation</span><span class="tag" data-group="creative" data-slug="content-creation" onclick="toggleTag(this)">Content Creation</span>
            </div></div>
          </div>
          <p class="err-msg" id="err4"></p><div class="nav-btns"><button class="btn-back" onclick="goBack()">← Back</button><button class="btn-next" onclick="nextStep(4)">Next →</button></div>
        </div>

        <div class="step-panel" id="panel5">
          <div class="signup-scroll-box"><p class="step-hint">Pick your hobbies — we'll use these for smarter peer matching.</p><div class="hobby-builder" id="hobbyBuilder"><div class="hobby-main-grid" id="hobbyMainGrid"></div></div><div id="selectedHobbies" class="selected-hobbies-list" style="display:none;"><div class="tag-section-label" style="margin-bottom:8px;">✅ Your Hobbies</div><div id="hobbyChips"></div></div></div>
          <div class="check-row" style="margin-top:8px;"><label class="remember"><input type="checkbox" id="terms"><div class="check-box"><svg viewBox="0 0 12 12"><polyline points="2 6 5 9 10 3" /></svg></div>I agree to the&nbsp;<a href="<?= BASE_URL ?>/terms.php" class="terms-link">Terms &amp; Privacy Policy</a></label></div>
          <p class="err-msg" id="err5"></p><div class="nav-btns"><button class="btn-back" onclick="goBack()">← Back</button><button class="btn-next" id="submitBtn" onclick="doSignup()">Create Account</button></div>
        </div>

        <div id="ssoButtons">
          <a href="<?= BASE_URL ?>/API/auth/oauth-init.php?provider=google" class="social-btn"><svg class="g-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" /><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" /><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05" /><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" /></svg><span>Continue with Google</span></a>
          <a href="<?= BASE_URL ?>/API/auth/oauth-init.php?provider=microsoft" class="social-btn"><div class="ms-icon"><span style="background:#F25022;"></span><span style="background:#7FBA00;"></span><span style="background:#00A4EF;"></span><span style="background:#FFB900;"></span></div><div class="s-text"><span>Continue with Microsoft</span><span class="s-sub">University SSO</span></div></a>
        </div>

        <div class="login-text">Already have an account? <a href="login.php">Login</a></div>
        <div class="badge"><div class="b-ico">🌿</div>Built for Fatima Computing</div>

      </div>
    </div>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/auth/signup.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/auth/signup-network-fix.js" defer></script>
</body>

</html>
