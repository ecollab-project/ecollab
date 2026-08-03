<?php
/**
 * sidebar-facilitator.php — Facilitator dashboard sidebar
 * Expects: $user (array), $activePage (string)
 */
$activePage = $activePage ?? 'dashboard';
$grad       = $user['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
[$c1, $c2]  = array_map('trim', explode(',', $grad . ',#7c3aed'));
$initials   = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));

function fNavItem(string $page, string $icon, string $label, $badge, string $active, string $extraClass = ''): string {
    $a = $page === $active ? ' active' : ($extraClass ? " {$extraClass}" : '');
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
    <?= fNavItem('dashboard', '🏠', 'Dashboard', null, $activePage) ?>
    <?= fNavItem('mychannel', '📡', 'My Channel', null, $activePage) ?>
  </div>

  <div class="nav-section-title">Channel Management</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('overview',      '📊', 'Overview',           null, $activePage) ?>
    <?= fNavItem('members',       '👥', 'Members',            null, $activePage) ?>
    <?= fNavItem('roles',         '🛡', 'Roles & Permissions',null, $activePage) ?>
    <?= fNavItem('announcements', '📢', 'Announcements',       null, $activePage) ?>
    <?= fNavItem('resources',     '📚', 'Resources',           null, $activePage) ?>
    <?= fNavItem('files',         '🔗', 'Files & Links',       null, $activePage) ?>
    <?= fNavItem('chsettings',    '⚙',  'Channel Settings',   null, $activePage) ?>
  </div>

  <div class="nav-section-title">Activity & Analytics</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('useractivity',  '📈', 'User Activity',      null, $activePage, 'active-soft') ?>
    <?= fNavItem('messages',      '💬', 'Messages',            null, $activePage) ?>
    <?= fNavItem('sessions',      '🎓', 'Study Sessions',      null, $activePage) ?>
    <?= fNavItem('engagement',    '💡', 'Engagement',           null, $activePage) ?>
    <?= fNavItem('leaderboards',  '🏆', 'Leaderboards',        null, $activePage) ?>
  </div>

  <div class="nav-section-title">Moderation</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('reports',   '🚩', 'Reports',          2,    $activePage) ?>
    <?= fNavItem('modqueue',  '⚠',  'Moderation Queue', null, $activePage) ?>
    <?= fNavItem('banned',    '🚫', 'Banned Users',     null, $activePage) ?>
    <?= fNavItem('chlogs',    '📋', 'Channel Logs',     null, $activePage) ?>
  </div>

  <div class="nav-section-title">Tools</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('whiteboard', '🎨', 'Whiteboard',    null, $activePage) ?>
    <?= fNavItem('polls',      '📊', 'Polls & Quizzes',null, $activePage) ?>
    <div class="nav-item" onclick="openModal('aiModal')"><span class="nav-ic">🤖</span>AI Assistant</div>
    <div class="nav-item" onclick="goToChat()"><span class="nav-ic">💬</span>Go to Chat</div>
  </div>

  <!-- Channel selector -->
  <div class="channel-selector" onclick="openModal('channelSwitchModal')" style="margin-top:auto">
    <div class="cs-top">
      <div class="cs-av"><?= htmlspecialchars($initials) ?></div>
      <div class="cs-name">CS 305 – Neural Networks</div>
      <div class="cs-arr">▾</div>
    </div>
    <div class="cs-status"><div class="cs-dot"></div>Active</div>
  </div>

  <!-- Profile card -->
  <div class="prof-card" onclick="openModal('editProfileModal')">
    <div class="pc-top">
      <div class="pc-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?><div class="pc-online"></div></div>
      <div>
        <div class="pc-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
        <div class="pc-role">✅ Channel Administrator</div>
      </div>
    </div>
  </div>
</aside>
