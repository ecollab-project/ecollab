<?php

declare(strict_types=1);


require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security/csrf/csrf.php';
require_once __DIR__ . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();

// If user is already logged in, redirect to their dashboard
$isAuthed = !empty($_SESSION['user_id']);
$dashUrl  = $isAuthed ? match ($_SESSION['role'] ?? 'student') {
  'admin', 'super_admin', 'moderator' => './modules/admin/dashboard.php',
  'facilitator'                        => './modules/facilitator/dashboard.php',
  default                              => './modules/student/dashboard.php',
} : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= APP_NAME ?> — Connect. Learn. Grow. Together.</title>
  <meta name="description" content="AI-powered peer matching and collaborative learning for Fatima Computing students and beyond.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/variables.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/landing.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/landing-mobile.css">
</head>

<body>

  <!-- Particles -->
  <div class="particles" id="particles"></div>

  <!-- ── NAV ─────────────────────────────────────────────────────────────── -->
  <nav>
    <div class="nav-logo">
      <div class="icon">🌿</div>
      <?= APP_NAME ?>
    </div>
    <ul class="nav-links">
      <li><a href="#hero">Home</a></li>
      <li><a href="#features">Features</a></li>
      <li><a href="#about">About Us</a></li>
      <li><a href="#how-it-works">How it Works</a></li>
      <li><a href="#pricing">Pricing</a></li>
      <li><a href="#testimonials">Blog</a></li>
    </ul>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if ($isAuthed): ?>
        <a href="<?= htmlspecialchars($dashUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-login">Dashboard</a>
        <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="btn-primary">Logout</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/modules/auth/login.php" class="btn-login">Login</a>
        <a href="<?= BASE_URL ?>/modules/auth/signup.php" class="btn-primary">Get Started</a>
      <?php endif; ?>
    </div>
  </nav>

  <!-- ── HERO ─────────────────────────────────────────────────────────────── -->
  <section class="hero" id="hero">
    <div class="hero-badge">Sharpe</div>
    <h1>
      Connect. <span class="grad">Learn.</span><br>
      <span class="grad">Grow.</span> Together.
    </h1>
    <p>AI-powered peer matching and collaborative learning that connects <span style="color:var(--pink);font-weight:600">Fatima</span> students and beyond.</p>

    <!-- Tablet Mockup -->
    <div class="tablet-wrap">
      <div class="float-icon fi1">🤖</div>
      <div class="float-icon fi2">📚</div>
      <div class="float-icon fi3">🧠</div>
      <div class="float-icon fi4">⚡</div>
      <div class="float-icon fi5">💡</div>

      <div class="tablet-outer">
        <div class="tablet-screen">
          <!-- Sidebar -->
          <div class="tab-sidebar">
            <div class="logo-row">
              <div class="logo-icon">🌿</div>
              <?= APP_NAME ?>
            </div>
            <div class="active-row">
              <div class="dot" style="background:rgba(255,45,117,0.2)">🏠</div>
              Dashboard
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">💬</div>Messages
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">📁</div>Projects
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">📖</div>Library
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">📅</div>Schedule
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">🔧</div>Settings
            </div>
            <div class="nav-row" style="margin-top:20px">
              <div class="dot" style="background:rgba(255,255,255,0.05)">📊</div>Resources
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">🤖</div>AI Match
            </div>
            <div class="nav-row">
              <div class="dot" style="background:rgba(255,255,255,0.05)">🏆</div>Rewards
            </div>
          </div>
          <!-- Main Panel -->
          <div class="tab-main">
            <div class="tab-topbar">
              <div>
                <strong>AI Collaboration Hub</strong>
                <span style="margin-left:10px;opacity:0.5">• Study Room Active</span>
              </div>
              <div class="topbar-right">
                <div class="av" style="background:linear-gradient(135deg,#FF2D75,#9F3BFF)">J</div>
                <div class="av" style="background:linear-gradient(135deg,#FF4D4D,#FF2D75)">A</div>
                <div class="av" style="background:linear-gradient(135deg,#9F3BFF,#3B82FF)">K</div>
                <span style="font-size:.6rem;color:rgba(255,255,255,0.4)">+12 online</span>
              </div>
            </div>
            <div class="brain-area">
              <div class="user-card uc1">
                <div class="av2" style="background:linear-gradient(135deg,#FF2D75,#9F3BFF)">FA</div>
                <div>
                  <div class="uc-name">Fatima A.</div>
                  <div class="uc-sub">CS Student</div>
                </div>
              </div>
              <div class="user-card uc2">
                <div class="av2" style="background:linear-gradient(135deg,#3B82FF,#9F3BFF)">OH</div>
                <div>
                  <div class="uc-name">Omar H.</div>
                  <div class="uc-sub">Study Buddy</div>
                </div>
              </div>
              <div class="user-card uc3">
                <div class="av2" style="background:linear-gradient(135deg,#FF4D4D,#FF2D75)">LA</div>
                <div>
                  <div class="uc-name">Leyla A.</div>
                  <div class="uc-sub">Collaborator</div>
                </div>
              </div>
              <div class="user-card uc4">
                <div class="av2" style="background:linear-gradient(135deg,#10B981,#3B82FF)">MK</div>
                <div>
                  <div class="uc-name">Maya K.</div>
                  <div class="uc-sub">Project Lead</div>
                </div>
              </div>
              <div class="brain-svg-wrap">
                <div class="brain-glow"></div>
                <svg class="brain-svg" viewBox="0 0 220 180" xmlns="http://www.w3.org/2000/svg" width="220" height="180">
                  <defs>
                    <radialGradient id="bgrad" cx="50%" cy="50%">
                      <stop offset="0%" stop-color="#FF2D75" stop-opacity="0.9" />
                      <stop offset="40%" stop-color="#9F3BFF" stop-opacity="0.7" />
                      <stop offset="100%" stop-color="#FF4D4D" stop-opacity="0.3" />
                    </radialGradient>
                    <filter id="glow">
                      <feGaussianBlur stdDeviation="3" result="blur" />
                      <feMerge>
                        <feMergeNode in="blur" />
                        <feMergeNode in="SourceGraphic" />
                      </feMerge>
                    </filter>
                    <filter id="glow2">
                      <feGaussianBlur stdDeviation="5" result="blur" />
                      <feMerge>
                        <feMergeNode in="blur" />
                        <feMergeNode in="SourceGraphic" />
                      </feMerge>
                    </filter>
                  </defs>
                  <g opacity="0.4" stroke="#FF2D75" stroke-width="1" fill="none">
                    <line x1="110" y1="90" x2="50" y2="40" filter="url(#glow)" />
                    <line x1="110" y1="90" x2="170" y2="40" filter="url(#glow)" />
                    <line x1="110" y1="90" x2="50" y2="140" filter="url(#glow)" />
                    <line x1="110" y1="90" x2="170" y2="140" filter="url(#glow)" />
                    <line x1="110" y1="90" x2="20" y2="90" filter="url(#glow)" />
                    <line x1="110" y1="90" x2="200" y2="90" filter="url(#glow)" />
                    <line x1="50" y1="40" x2="170" y2="40" stroke="#9F3BFF" />
                    <line x1="50" y1="140" x2="170" y2="140" stroke="#9F3BFF" />
                  </g>
                  <g opacity="0.6" stroke="#FF2D75" stroke-width="1.5" fill="none" stroke-dasharray="4 3">
                    <line x1="110" y1="90" x2="90" y2="30">
                      <animate attributeName="stroke-dashoffset" from="0" to="-14" dur="1.5s" repeatCount="indefinite" />
                    </line>
                    <line x1="110" y1="90" x2="140" y2="155">
                      <animate attributeName="stroke-dashoffset" from="0" to="-14" dur="2s" repeatCount="indefinite" />
                    </line>
                  </g>
                  <path d="M110,30 C130,20 155,25 165,40 C175,55 172,70 160,80 C170,88 175,100 165,115 C155,130 140,135 125,130 C120,145 110,152 100,148 C90,144 80,135 82,122 C68,118 55,110 52,95 C49,80 58,68 70,62 C60,50 62,33 75,26 C88,19 100,30 110,30Z" fill="url(#bgrad)" opacity="0.85" filter="url(#glow2)" />
                  <path d="M90,50 C95,45 105,47 108,55 C112,50 120,48 125,55 C128,62 122,70 115,72 C118,78 115,86 108,88 C101,90 94,84 92,78 C85,76 80,68 84,60 C86,55 88,52 90,50Z" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1" />
                  <path d="M95,100 C100,96 108,98 112,105 C116,100 124,100 126,108 C128,116 120,122 113,120 C110,126 102,126 98,120 C91,118 87,110 92,104Z" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="1" />
                  <rect x="96" y="76" width="28" height="28" rx="6" fill="rgba(255,45,117,0.2)" stroke="rgba(255,45,117,0.5)" stroke-width="1.5" filter="url(#glow)" />
                  <text x="110" y="95" text-anchor="middle" fill="#FF2D75" font-size="11" font-weight="bold" font-family="monospace">AI</text>
                  <g filter="url(#glow)">
                    <circle cx="50" cy="40" r="5" fill="#FF2D75" opacity="0.9">
                      <animate attributeName="r" values="5;7;5" dur="2s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="170" cy="40" r="5" fill="#9F3BFF" opacity="0.9">
                      <animate attributeName="r" values="5;7;5" dur="2.5s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="50" cy="140" r="5" fill="#FF4D4D" opacity="0.9">
                      <animate attributeName="r" values="5;7;5" dur="1.8s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="170" cy="140" r="5" fill="#FF2D75" opacity="0.9">
                      <animate attributeName="r" values="5;7;5" dur="3s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="20" cy="90" r="4" fill="#9F3BFF" opacity="0.7" />
                    <circle cx="200" cy="90" r="4" fill="#9F3BFF" opacity="0.7" />
                  </g>
                </svg>
              </div>
              <div style="position:absolute;bottom:14px;right:18px;background:rgba(255,45,117,0.15);border:1px solid rgba(255,45,117,0.3);border-radius:10px;padding:6px 12px;font-size:.6rem;display:flex;align-items:center;gap:6px;">
                <span style="width:8px;height:8px;border-radius:50%;background:#FF2D75;animation:pulse-glow 1.5s infinite;display:inline-block"></span>
                AI Matching Active
              </div>
            </div>
            <div style="display:flex;gap:8px;padding:10px 16px;border-top:1px solid rgba(255,255,255,0.05)">
              <div style="background:rgba(255,45,117,0.12);border:1px solid rgba(255,45,117,0.2);border-radius:8px;padding:5px 12px;font-size:.6rem;color:var(--pink);cursor:pointer;">📋 Quick Details</div>
              <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:5px 12px;font-size:.6rem;color:rgba(255,255,255,0.5);cursor:pointer;">🔍 Find Peers</div>
              <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:5px 12px;font-size:.6rem;color:rgba(255,255,255,0.5);cursor:pointer;">💬 Chat</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="connect-btn-wrap">
      <a href="<?= BASE_URL ?>/modules/auth/signup.php" class="connect-btn">Connect Now</a>
    </div>
  </section>

  <!-- ── BUILT FOR BANNER ──────────────────────────────────────────────────── -->
  <div class="banner" id="about">
    <div class="banner-icon">🤖</div>
    <h3>Built for <span>Fatima</span> Computing Students &amp; Beyond</h3>
    <div class="banner-icon" style="background:rgba(159,59,255,0.15);border-color:rgba(159,59,255,0.25);">🎓</div>
  </div>

  <!-- ── HOW IT WORKS ──────────────────────────────────────────────────────── -->
  <section class="how-works" id="how-it-works">
    <h2 class="sec-title">How it Works</h2>
    <div class="how-grid">
      <div class="how-card">
        <div class="how-num">📝</div>
        <h4>Sign Up</h4>
        <p>Create your free account and set up your profile with your skills, courses, and study goals.</p>
      </div>
      <div class="how-card">
        <div class="how-num" style="background:rgba(255,45,117,0.15);">🤖</div>
        <h4>AI Match</h4>
        <p>Our AI analyzes your profile and intelligently matches you with compatible study partners.</p>
      </div>
      <div class="how-card">
        <div class="how-num">🤝</div>
        <h4>Collaborate</h4>
        <p>Connect through voice chat, video sessions, and the collaborative whiteboard in real time.</p>
      </div>
      <div class="how-card">
        <div class="how-num" style="background:rgba(159,59,255,0.15);">🚀</div>
        <h4>Grow</h4>
        <p>Track your progress, earn rewards, and achieve more than you ever could studying alone.</p>
      </div>
    </div>
  </section>

  <!-- ── KEY FEATURES ──────────────────────────────────────────────────────── -->
  <section class="features" id="features">
    <h2 class="sec-title">Key Features</h2>
    <div class="feat-grid">
      <div class="feat-card highlight">
        <div class="feat-icon">🤖</div>
        <h4>AI Matching <span class="feat-badge">NEW</span></h4>
        <p>Intelligent peer matching powered by ML to connect you with the best study partners.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon">🏠</div>
        <h4>Smart Study Rooms</h4>
        <p>Real-time collaborative spaces with note sharing, timers, and focus modes.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon">🎙️</div>
        <h4>Voice Chat</h4>
        <p>Crystal-clear, low-latency voice chat with noise cancellation built in.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon">📹</div>
        <h4>Video Chat</h4>
        <p>HD video sessions with screen sharing and virtual backgrounds for any study session.</p>
      </div>
      <div class="feat-card highlight">
        <div class="feat-icon">🖊️</div>
        <h4>Collaborative Whiteboard</h4>
        <p>Infinite canvas for diagrams, sketching, and brainstorming together in real time.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon">📈</div>
        <h4>Progress Tracking</h4>
        <p>Detailed analytics on your study habits, streaks, and subject mastery over time.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon">📂</div>
        <h4>Resource Tracking</h4>
        <p>Organize and share resources, references, and study materials across sessions.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon">🏆</div>
        <h4>Rewards</h4>
        <p>Earn badges, XP, and climb leaderboards as you hit your learning milestones.</p>
      </div>
    </div>
  </section>

  <!-- ── TESTIMONIALS ──────────────────────────────────────────────────────── -->
  <section class="testimonials" id="testimonials">
    <h2 class="sec-title">Testimonials</h2>
    <div class="test-grid">
      <div class="test-card">
        <div class="test-stars">★★★★★</div>
        <p class="test-text">"Ecollab completely changed how I study. The AI matching is incredibly accurate — I found my perfect study group within minutes of signing up."</p>
        <div class="test-user">
          <div class="test-av" style="background:linear-gradient(135deg,#FF2D75,#9F3BFF)">FA</div>
          <div>
            <div class="test-name">Fatima Al-Khaled</div>
            <div class="test-role">CS Student, Fatima University</div>
          </div>
        </div>
      </div>
      <div class="test-card">
        <div class="test-stars">★★★★★</div>
        <p class="test-text">"The collaborative whiteboard is a game-changer. Being able to solve problems together visually has boosted my understanding enormously."</p>
        <div class="test-user">
          <div class="test-av" style="background:linear-gradient(135deg,#3B82FF,#9F3BFF)">OH</div>
          <div>
            <div class="test-name">Omar Hassan</div>
            <div class="test-role">Computer Science, 3rd Year</div>
          </div>
        </div>
      </div>
      <div class="test-card">
        <div class="test-stars">★★★★★</div>
        <p class="test-text">"I went from struggling alone to top of my class, all thanks to Ecollab. The progress tracking kept me accountable and the rewards system is genuinely motivating."</p>
        <div class="test-user">
          <div class="test-av" style="background:linear-gradient(135deg,#FF4D4D,#FF2D75)">LA</div>
          <div>
            <div class="test-name">Leyla Ahmed</div>
            <div class="test-role">Software Engineering Student</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ── PRICING ───────────────────────────────────────────────────────────── -->
  <section class="pricing" id="pricing">
    <h2 class="sec-title">Pricing</h2>
    <p class="sec-sub">Every collaboration tool, AI feature, and study room — free for every student, facilitator, and admin.</p>
    <div class="pricing-grid pricing-grid-single">
      <div class="price-card pro">
        <div class="rec-badge">Free Forever</div>
        <div class="price-tier">Free</div>
        <div class="price-amt">$0 <span>/mo</span></div>
        <ul class="price-features">
          <li>AI-Powered Peer Matching</li>
          <li>Unlimited Study Rooms</li>
          <li>Voice &amp; Video Chat</li>
          <li>Whiteboard &amp; Live Docs</li>
          <li>Collaboration Tools (Notes, Tasks, Flashcards, Quizzes &amp; more)</li>
          <li>Progress Analytics</li>
          <li>Community Access</li>
          <li>Role-based Dashboards (Student, Facilitator, Admin)</li>
        </ul>
        <a href="<?= BASE_URL ?>/modules/auth/signup.php" class="price-btn filled">Get Started</a>
      </div>
    </div>
    <p class="pricing-note">
      Every account gets the full feature set. Access within a server or channel is
      determined by your role (student, facilitator, or admin) and by what each
      server's owners and moderators choose to share — not by a paid plan.
    </p>
  </section>


  <!-- ── FOOTER ────────────────────────────────────────────────────────────── -->
  <footer>
    <div class="footer-brand">
      <div class="logo-row">
        <div class="logo-icon">🌿</div>
        <?= APP_NAME ?>
      </div>
      <p>AI-powered peer matching and collaborative learning for students everywhere.</p>
      <div class="footer-socials">
        <a href="#" class="social-btn">𝕏</a>
        <a href="#" class="social-btn">in</a>
        <a href="#" class="social-btn">📷</a>
        <a href="#" class="social-btn">f</a>
      </div>
    </div>
    <div class="footer-links">
      <h5>Product</h5>
      <a href="#">Features</a>
      <a href="#">Pricing</a>
      <a href="#">Blog</a>
      <a href="#">Changelog</a>
    </div>
    <div class="footer-links">
      <h5>Company</h5>
      <a href="#">About</a>
      <a href="#">Careers</a>
      <a href="#">Contact</a>
      <a href="#">Press</a>
    </div>
    <div class="footer-links">
      <h5>Legal</h5>
      <a href="#">Privacy</a>
      <a href="#">Terms</a>
      <a href="#">Cookies</a>
    </div>
  </footer>

  <div class="footer-bottom">
    © <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved. | Privacy | Terms
  </div>

  <script src="<?= BASE_URL ?>/assets/js/landing.js" defer></script>
</body>

</html>