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
    <?= fNavItem('servermonitoring', '🖥️', 'My Servers', null, $activePage) ?>
  </div>

  <div class="nav-section-title">Server Management</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('roles',         '🛡', 'Roles & Permissions',null, $activePage) ?>
    <?= fNavItem('announcements', '📢', 'Announcements',       null, $activePage) ?>
    <?= fNavItem('resources',     '📚', 'Resources',           null, $activePage) ?>
    <?= fNavItem('files',         '🔗', 'Files & Links',       null, $activePage) ?>
    <?= fNavItem('chsettings',    '⚙',  'Server Settings',    null, $activePage) ?>
  </div>

  <div class="nav-section-title">Activity & Analytics</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('useractivity',  '📈', 'User Activity',      null, $activePage, 'active-soft') ?>
    <?= fNavItem('sessions',      '🎓', 'Study Sessions',      null, $activePage) ?>
  </div>

  <div class="nav-section-title">Moderation</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('reports',   '🚩', 'Reports',          null, $activePage) ?>
    <?= fNavItem('banned',    '🚫', 'Banned Users',     null, $activePage) ?>
  </div>

  <div class="nav-section-title">Tools</div>
  <div class="nav-pad" style="padding-top:0">
    <?= fNavItem('polls',      '📊', 'Polls',          null, $activePage) ?>
    <div class="nav-item" onclick="window.location.href='<?= BASE_URL ?>/modules/collaboration/index.php'"><span class="nav-ic">🧩</span>Collaboration Hub</div>
    <div class="nav-item" onclick="openModal('aiModal')"><span class="nav-ic">🤖</span>AI Assistant</div>
  </div>

  <!-- Current server -->
  <?php
    $sidebarServerName = $sidebarServer['name'] ?? ($dashData['channel']['name'] ?? 'My Servers');
    $sidebarServerIcon = $sidebarServer['icon_emoji'] ?? '🖥️';
    $sidebarServerStatus = ucfirst((string)($sidebarServer['status'] ?? 'active'));
  ?>
  <div class="channel-selector facilitator-server-card" style="margin-top:auto" title="<?= htmlspecialchars($sidebarServerName) ?>">
    <div class="cs-top">
      <div class="cs-av"><?= htmlspecialchars($sidebarServerIcon) ?></div>
      <div class="cs-name"><?= htmlspecialchars($sidebarServerName) ?></div>
    </div>
    <div class="cs-status"><div class="cs-dot"></div><?= htmlspecialchars($sidebarServerStatus) ?></div>
  </div>

  <!-- Profile card -->
  <div class="prof-card" onclick="openModal('editProfileModal')">
    <div class="pc-top">
      <div class="pc-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?><div class="pc-online"></div></div>
      <div>
        <div class="pc-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
        <div class="pc-role">✅ Facilitator</div>
      </div>
    </div>
  </div>
</aside>
