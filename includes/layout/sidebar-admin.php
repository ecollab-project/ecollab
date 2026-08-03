<?php
/**
 * sidebar-admin.php — Admin dashboard sidebar
 * Expects: $user (array), $activePage (string)
 */
$activePage = $activePage ?? 'overview';
$grad       = $user['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
[$c1, $c2]  = array_map('trim', explode(',', $grad . ',#7c3aed'));
$initials   = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));

function aNavItem(string $page, string $icon, string $label, string $active): string {
    $a = $page === $active ? ' active' : '';
    return "<div class=\"nav-item{$a}\" onclick=\"showPage('{$page}',this)\"><span class=\"nav-icon\">{$icon}</span>{$label}</div>";
}
?>
<aside class="sidebar">
  <div class="logo">
    <div class="logo-icon">🔷</div>
    <span class="logo-text">Ecollab</span>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Main</div>
    <?= aNavItem('overview', '⊞', 'Overview', $activePage) ?>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">User Management</div>
    <?= aNavItem('users',      '👥', 'Users',              $activePage) ?>
    <?= aNavItem('roles',      '🛡', 'Roles & Permissions', $activePage) ?>
    <?= aNavItem('courses',    '🔖', 'Course & Tags',       $activePage) ?>
    <?= aNavItem('aimatching', '✨', 'AI Matching',          $activePage) ?>
    <?= aNavItem('reports',    '🚩', 'Reports',              $activePage) ?>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">System Management</div>
    <?= aNavItem('servers',    '🖥', 'Servers',    $activePage) ?>
    <?= aNavItem('channels',   '#',  'Channels',   $activePage) ?>
    <?= aNavItem('settings',   '⚙',  'Settings',   $activePage) ?>
    <?= aNavItem('moderation', '🔨', 'Moderation', $activePage) ?>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Analytics & Insights</div>
    <?= aNavItem('analytics',    '📊', 'Analytics',     $activePage) ?>
    <?= aNavItem('activitylogs', '🕐', 'Activity Logs', $activePage) ?>
    <?= aNavItem('syshealth',    '💗', 'System Health', $activePage) ?>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Communication</div>
    <?= aNavItem('announcements', '📢', 'Announcements',     $activePage) ?>
    <?= aNavItem('feedback',      '💬', 'Feedback & Reports', $activePage) ?>
    <div class="nav-item" onclick="goToChat()"><span class="nav-icon">💬</span>Go to Chat</div>
  </div>

  <div class="sidebar-footer">
    <div class="u-info">
      <div class="av-sm" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?></div>
      <div>
        <div class="sf-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
        <div class="sf-ver"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))) ?></div>
      </div>
    </div>
  </div>
</aside>
