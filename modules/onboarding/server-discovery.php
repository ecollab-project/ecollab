<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';

AuthMiddleware::startSession();
AuthMiddleware::requireAuth();   // must be logged in

// If already done onboarding, go straight to chat
if (!empty($_SESSION['onboarding_done'])) {
    header('Location: ' . BASE_URL . '/modules/chat/chat.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'there';
$firstName = explode(' ', trim($fullName))[0];
$gradient = $_SESSION['avatar_color_gradient'] ?? '#a855f7,#ec4899';
[$c1, $c2] = array_pad(explode(',', $gradient, 2), 2, '#ec4899');
$initials = strtoupper(mb_substr($firstName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Find Your Communities – <?= APP_NAME ?></title>
  <meta name="robots" content="noindex,nofollow">
  <meta name="csrf-token" content="<?= htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/variables.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/onboarding.css">
</head>
<body>

  <canvas id="particles"></canvas>
  <div class="orb orb1"></div>
  <div class="orb orb2"></div>

  <div class="ob-page">

    <!-- Top bar -->
    <div class="ob-topbar">
      <a href="<?= BASE_URL ?>/index.php" class="ob-logo">
        <span class="ob-logo-ico">🌿</span>
        <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?>
      </a>
      <div class="ob-user">
        <div class="ob-avatar" style="background:linear-gradient(135deg,<?= $c1 ?>,<?= $c2 ?>)">
          <?= $initials ?>
        </div>
        <span class="ob-username"><?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    </div>

    <!-- Hero -->
    <div class="ob-hero">
      <div class="ob-hero-badge">🎉 Account created!</div>
      <h1 class="ob-hero-title">
        Welcome, <span class="ob-hero-grad"><?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></span>
      </h1>
      <p class="ob-hero-sub">
        Here are communities that match your interests.<br>
        Join the ones you like, or skip and explore later.
      </p>

      <!-- Match legend -->
      <div class="ob-legend">
        <div class="ob-legend-item">
          <span class="ob-match-badge ob-match-high">High Match</span>
          <span>80 %+ overlap</span>
        </div>
        <div class="ob-legend-item">
          <span class="ob-match-badge ob-match-med">Good Match</span>
          <span>50–79 %</span>
        </div>
        <div class="ob-legend-item">
          <span class="ob-match-badge ob-match-low">Suggested</span>
          <span>&lt; 50 %</span>
        </div>
      </div>
    </div>

    <!-- Server grid -->
    <div class="ob-grid-wrap">
      <div class="ob-grid" id="serverGrid">
        <!-- Skeleton loaders while fetching -->
        <?php for ($i = 0; $i < 6; $i++): ?>
          <div class="ob-card ob-skeleton"></div>
        <?php endfor; ?>
      </div>

      <!-- Empty state (hidden until JS shows it) -->
      <div class="ob-empty" id="obEmpty" style="display:none;">
        <div class="ob-empty-ico">🔍</div>
        <div class="ob-empty-title">No matching servers yet</div>
        <p class="ob-empty-sub">It looks like there aren't any public servers that match your interests right now. You can always browse and join servers from inside the app.</p>
      </div>
    </div>

    <!-- Bottom action bar -->
    <div class="ob-action-bar">
      <div class="ob-selected-info" id="obSelectedInfo">
        <span id="obSelectedCount">0</span> server<span id="obSelectedPlural">s</span> selected
      </div>
      <div class="ob-action-btns">
        <button class="ob-skip-btn" id="obSkipBtn" onclick="skipOnboarding()">
          Skip for now
        </button>
        <button class="ob-join-btn" id="obJoinBtn" onclick="joinSelected()" disabled>
          <span id="obJoinLabel">Join Selected</span>
        </button>
      </div>
    </div>

  </div>

  <script>
    const CSRF_TOKEN  = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const BASE_URL    = <?= json_encode(BASE_URL) ?>;
    const CHAT_URL    = BASE_URL + '/modules/chat/chat.php';
  </script>
  <script src="<?= BASE_URL ?>/assets/js/onboarding/server-discovery.js" defer></script>
</body>
</html>
