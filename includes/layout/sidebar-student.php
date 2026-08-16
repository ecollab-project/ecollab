<?php
/**
 * sidebar-student.php — Student dashboard sidebar
 * Expects: $user (array from AuthMiddleware::requireAuth()), $activePage (string)
 */
$activePage = $activePage ?? 'dashboard';
$grad       = $user['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
[$c1, $c2]  = array_map('trim', explode(',', $grad . ',#7c3aed'));
$initials   = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));
$unread     = $unreadCount ?? 3;

// Nav items: [pageId, icon, label, badge?]
$navMain = [
    ['dashboard',   '🏠', 'Dashboard',     null],
    ['discover',    '🔍', 'Discover',       null],
    ['courses',     '📚', 'My Courses',     null],
    ['rooms',       '🏠', 'Study Rooms',    null],
    ['messages',    '💬', 'Messages',       $unread],
    ['notifpage',   '🔔', 'Notifications',  $unread],
    ['calendar',    '📅', 'Calendar',       null],
];
$navCollab = [
    ['whiteboard',  '🎨', 'Whiteboard',    null],
    ['files',       '📁', 'Files & Resources', null],
    ['notes',       '📝', 'Notes',         null],
];
$navAnalytics = [
    ['activity',     '📊', 'My Activity',   null],
    ['insights',     '💡', 'Study Insights',null],
    ['achievements', '🏆', 'Achievements',  null],
];
$navSupport = [
    ['help',        '❓', 'Help Center',    null],
];

function navItem(string $page, string $icon, string $label, $badge, string $activePage): string {
    $a = $page === $activePage ? ' active' : '';
    $b = $badge ? "<span class=\"nav-badge\">{$badge}</span>" : '';
    return "<div class=\"nav-item{$a}\" id=\"nav-{$page}\" onclick=\"showPage('{$page}',this)\"><span class=\"nav-ic\">{$icon}</span>{$label}{$b}</div>";
}
?>
<aside class="sidebar">
  <div class="logo" onclick="showPage('dashboard')">
    <div class="logo-icon">🔷</div>
    <span class="logo-text">Ecollab</span>
  </div>

  <div class="nav-pad">
    <?php foreach ($navMain as [$id,$ic,$lbl,$bdg]): echo navItem($id,$ic,$lbl,$bdg,$activePage); endforeach; ?>
  </div>

  <div class="nav-section-title">Collaboration</div>
  <div class="nav-pad" style="padding-top:0">
    <?php foreach ($navCollab as [$id,$ic,$lbl,$bdg]): echo navItem($id,$ic,$lbl,$bdg,$activePage); endforeach; ?>
  </div>

  <div class="nav-section-title">Analytics</div>
  <div class="nav-pad" style="padding-top:0">
    <?php foreach ($navAnalytics as [$id,$ic,$lbl,$bdg]): echo navItem($id,$ic,$lbl,$bdg,$activePage); endforeach; ?>
  </div>

  <div class="nav-section-title">Support</div>
  <div class="nav-pad" style="padding-top:0">
    <?php foreach ($navSupport as [$id,$ic,$lbl,$bdg]): echo navItem($id,$ic,$lbl,$bdg,$activePage); endforeach; ?>
    <div class="nav-item" onclick="openModal('reportModal')"><span class="nav-ic">🚩</span>Report Issue</div>
    <div class="nav-item" onclick="openModal('feedbackModal')"><span class="nav-ic">💭</span>Feedback</div>
    <div class="nav-item" onclick="goToChat()"><span class="nav-ic">💬</span>Go to Chat</div>
  </div>

  <div class="ai-widget" style="margin-top:auto">
    <div class="ai-widget-top">🤖 AI Study Assistant <span class="ai-beta">BETA</span></div>
    <div class="ai-desc">Get help, summaries, and study recommendations.</div>
    <button class="btn-ai" onclick="openModal('aiModal')">✦ Ask AI Assistant</button>
  </div>

  <div class="cur-room" onclick="openModal('roomDetailModal','CS 305')">
    <div class="cr-name">CS 305</div>
    <div class="cr-sub">Neural Networks</div>
    <div class="cr-status"><div class="cr-dot"></div>In Progress</div>
  </div>
</aside>

<!-- Peer matching is shared by student pages. The script safely no-ops when the
     Find Study Buddies modal is not present. -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/peer-matching.css?v=1">
<script src="<?= BASE_URL ?>/assets/js/peer-matching.js?v=1" defer></script>
