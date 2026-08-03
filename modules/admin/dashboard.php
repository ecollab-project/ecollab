<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/csrf/csrf.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH . '/services/UserService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
RoleMiddleware::requireRole(['admin', 'super_admin', 'moderator']);

$csrfToken  = AuthMiddleware::csrfToken();
$activePage = $_GET['page'] ?? 'overview';

$userService = new UserService();
$dashData    = $userService->getAdminDashboardData($user['id']);

$grad     = $user['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
$parts    = explode(',', $grad . ',#7c3aed');
$c1       = trim($parts[0]);
$c2       = trim($parts[1]);
$initials = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));
$name     = htmlspecialchars($user['full_name'] ?: $user['username']);

$stats = $dashData['stats'] ?? [
    'total_users'        => 1248,
    'active_students'    => 892,
    'sessions_today'     => 47,
    'messages_today'     => 312,
    'servers_count'      => 3,
    'active_channels'    => 24,
    'ai_accuracy'        => 98.7,
    'reports_pending'    => 8,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard – <?= APP_NAME ?></title>
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/admin-dashboard.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/dashboard-mobile.css">
  <script>window.ECOLLAB_BASE = <?= json_encode(BASE_URL) ?>;</script>
</head>
<body>

<?php include ROOT_PATH . '/includes/layout/sidebar-admin.php'; ?>


<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="main-content">
  <header class="topbar">
    <div class="tb-left">
      <div class="tb-search">
        <span style="color:var(--muted);font-size:13px">🔍</span>
        <input placeholder="Search users, channels, servers..." oninput="filterUsersTable(this.value)" autocomplete="off">
      </div>
    </div>
    <div class="tb-right">
      <div class="notif-wrap" id="nWrap">
        <button class="tb-btn" id="nBtn" onclick="toggleNotif()">
          🔔
          <span class="n-badge" id="nBadge"><?= (int)($stats['reports_pending'] ?? 8) ?></span>
        </button>
        <div class="n-drop" id="nDrop">
          <div class="n-head"><span>Notifications</span><span class="n-clear" onclick="clearNotifs()">Mark all read</span></div>
          <div class="n-item unread" onclick="showPage('reports');closeNotif()"><div class="n-dot"></div><div class="n-ico" style="background:rgba(239,68,68,0.15)">🚩</div><div><div class="n-msg">New report: Flagged message in #general</div><div class="n-time">2h ago</div></div></div>
          <div class="n-item unread" onclick="showPage('users');closeNotif()"><div class="n-dot"></div><div class="n-ico" style="background:rgba(34,197,94,0.15)">👥</div><div><div class="n-msg">New user registered: Sara_Kim</div><div class="n-time">3h ago</div></div></div>
          <div class="n-item" onclick="showPage('syshealth');closeNotif()"><div class="n-ico" style="background:rgba(245,158,11,0.15)">⚠</div><div><div class="n-msg">High memory usage detected (67%)</div><div class="n-time">4h ago</div></div></div>
        </div>
      </div>
      <div class="prof-wrap" id="pWrap">
        <div class="tb-prof" onclick="togglePDrop()">
          <div class="av-sm" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?></div>
          <div><div class="pn"><?= $name ?></div><div class="pr"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$user['role']??'Admin'))) ?></div></div>
          <span style="font-size:10px;color:var(--muted)">▼</span>
        </div>
        <div class="p-drop" id="pDrop">
          <div class="p-head"><div class="pdn"><?= $name ?></div><div class="pdr"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$user['role']??'Admin'))) ?></div></div>
          <div class="pd-item" onclick="showPage('settings')">⚙️ Settings</div>
          <div class="pd-item" onclick="openModal('changePasswordModal')">🔒 Change Password</div>
          <div class="pd-item" onclick="goToChat()">💬 Go to Chat</div>
          <div class="pd-sep"></div>
          <div class="pd-item red" onclick="openModal('logoutModal')">🚪 Sign Out</div>
        </div>
      </div>
    </div>
  </header>

  <div class="page-content">

    <!-- ══ OVERVIEW PAGE ══ -->
    <div class="page-section active" id="page-overview">
      <div class="page-title-row">
        <div><div class="page-title">Dashboard</div><div class="page-sub">Welcome back, <?= $name ?>! Here's what's happening on <?= APP_NAME ?>.</div></div>
        <button class="btn-primary" onclick="openModal('createUserModal')">+ Create User</button>
      </div>
      <div class="stats-grid">
        <div class="stat-card pink" onclick="showPage('users')">
          <div class="stat-header"><div class="stat-label">Total Users</div><div class="stat-icon">👥</div></div>
          <div class="stat-value"><?= number_format((int)($stats['total_users']??1248)) ?></div>
          <div class="stat-change">▲ +12.5% from last month</div>
        </div>
        <div class="stat-card blue" onclick="showPage('users')">
          <div class="stat-header"><div class="stat-label">Active Students</div><div class="stat-icon">🎓</div></div>
          <div class="stat-value"><?= number_format((int)($stats['active_students']??892)) ?></div>
          <div class="stat-change">▲ +8.3% from last month</div>
        </div>
        <div class="stat-card green" onclick="openModal('studySessionsModal')">
          <div class="stat-header"><div class="stat-label">Study Sessions Today</div><div class="stat-icon">📚</div></div>
          <div class="stat-value"><?= (int)($stats['sessions_today']??47) ?></div>
          <div class="stat-change">▲ +15.2% from yesterday</div>
        </div>
        <div class="stat-card purple" onclick="openModal('messagesTodayModal')">
          <div class="stat-header"><div class="stat-label">Messages Today</div><div class="stat-icon">💬</div></div>
          <div class="stat-value"><?= number_format((int)($stats['messages_today']??312)) ?></div>
          <div class="stat-change">▲ +10.7% from yesterday</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent Users</div>
          <button class="btn-sm btn-outline" onclick="exportData('users')">⬇ Export Data</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>User</th><th>Role</th><th>Course/Program</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody id="recentUsersTable">
<?php foreach (array_slice($dashData['recent_users'] ?? [], 0, 6) as $ru):
  $ruI = strtoupper(substr($ru['full_name'] ?? $ru['username'] ?? '?', 0, 1));
  $ruG = $ru['avatar_color_gradient'] ?? '#ff4fd8,#7c5cff';
  $ruN = htmlspecialchars($ru['username'] ?? '');
  $ruR = htmlspecialchars(ucfirst($ru['role'] ?? 'student'));
  $ruS = $ru['status'] === 'active' ? '<span class="pill active"><span class="pill-dot"></span>Active</span>' : '<span class="pill offline">Offline</span>';
  $ruJ = htmlspecialchars($ru['joined_label'] ?? '');
  $ruC = htmlspecialchars($ru['course_name'] ?? 'N/A');
?>
              <tr>
                <td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,<?= htmlspecialchars($ruG) ?>)"><?= htmlspecialchars($ruI) ?></div><div><div class="u-name-main"><?= $ruN ?></div><div class="u-handle">@<?= strtolower($ruN) ?></div></div></div></td>
                <td><?= $ruR ?></td>
                <td><?= $ruC ?></td>
                <td><?= $ruS ?></td>
                <td style="color:var(--muted)"><?= $ruJ ?></td>
                <td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('<?= $ruN ?>','<?= htmlspecialchars($ruI) ?>','<?= htmlspecialchars($ruG) ?>','<?= $ruR ?>','<?= $ruC ?>','Active','<?= $ruJ ?>')">View</button><button class="btn-more" onclick="openContextMenu(event,'<?= $ruN ?>')">More ▾</button></div></td>
              </tr>
<?php endforeach; ?>
<?php if (empty($dashData['recent_users'])): ?>
              <tr><td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,#ff4fd8,#7c5cff)">F</div><div><div class="u-name-main">Fatima_Student</div><div class="u-handle">@fatima.student</div></div></div></td><td>Student</td><td>Computer Science</td><td><span class="pill active"><span class="pill-dot"></span>Active</span></td><td style="color:var(--muted)">2h ago</td><td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('Fatima_Student','F','#ff4fd8,#7c5cff','Student','Computer Science','Active','Jan 12 2025')">View</button><button class="btn-more" onclick="openContextMenu(event,'Fatima_Student')">More ▾</button></div></td></tr>
              <tr><td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,#3b82f6,#00d4ff)">J</div><div><div class="u-name-main">John_Doe</div><div class="u-handle">@john.doe</div></div></div></td><td>Student</td><td>Computer Science</td><td><span class="pill active"><span class="pill-dot"></span>Active</span></td><td style="color:var(--muted)">3h ago</td><td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('John_Doe','J','#3b82f6,#00d4ff','Student','Computer Science','Active','Jan 15 2025')">View</button><button class="btn-more" onclick="openContextMenu(event,'John_Doe')">More ▾</button></div></td></tr>
              <tr><td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,#22c55e,#16a34a)">S</div><div><div class="u-name-main">Sara_Kim</div><div class="u-handle">@sara.kim</div></div></div></td><td>Student</td><td>Information Tech</td><td><span class="pill active"><span class="pill-dot"></span>Active</span></td><td style="color:var(--muted)">5h ago</td><td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('Sara_Kim','S','#22c55e,#16a34a','Student','Information Tech','Active','Feb 3 2025')">View</button><button class="btn-more" onclick="openContextMenu(event,'Sara_Kim')">More ▾</button></div></td></tr>
              <tr><td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,#ef4444,#dc2626)">A</div><div><div class="u-name-main">Adam_Smith</div><div class="u-handle">@adam.smith</div></div></div></td><td>Facilitator</td><td>Computer Science</td><td><span class="pill active"><span class="pill-dot"></span>Active</span></td><td style="color:var(--muted)">1d ago</td><td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('Adam_Smith','A','#ef4444,#dc2626','Facilitator','Computer Science','Active','Dec 20 2024')">View</button><button class="btn-sm btn-outline" onclick="openModal('editRoleModal','Adam_Smith')">Edit Role</button></div></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-header"><div class="card-title">Study Rooms Overview</div><button class="text-link" onclick="openModal('joinRoomModal')">Join Room</button></div>
          <div style="padding:8px 14px 4px;font-size:11px;color:var(--muted)">Active Rooms</div>
          <div class="rooms-list">
<?php foreach ($dashData['study_rooms'] ?? [] as $room): ?>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name"><?= htmlspecialchars($room['name'] ?? '') ?></span><span class="room-count"><?= (int)($room['active_members'] ?? 0) ?>/<?= (int)($room['max_members'] ?? 25) ?></span><button class="btn-join" onclick="joinRoom('<?= htmlspecialchars($room['name'] ?? '') ?>')">Join</button></div>
<?php endforeach; ?>
<?php if (empty($dashData['study_rooms'])): ?>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name">toastDEV#zWw9Rm</span><span class="room-count">12/25</span><button class="btn-join" onclick="joinRoom('toastDEV#zWw9Rm')">Join</button></div>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name">Data-Structures-Discuss</span><span class="room-count">15/30</span><button class="btn-join" onclick="joinRoom('Data-Structures-Discuss')">Join</button></div>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name">AI Study Group</span><span class="room-count">10/20</span><button class="btn-join" onclick="joinRoom('AI Study Group')">Join</button></div>
<?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Daily Active Sessions</div></div>
          <div class="chart-wrap" style="height:175px"><canvas id="sessionsChart"></canvas></div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-header"><div class="card-title">Active Rooms</div><button class="text-link" onclick="showPage('channels')">View All</button></div>
          <div class="active-rooms-list">
            <div class="active-room-item" onclick="openModal('roomDetailModal')" style="cursor:pointer"><div class="room-avatar-placeholder" style="background:rgba(0,212,255,0.15)">🤖</div><span class="ar-name">Study Room 5</span><span class="ar-count">👥 18</span></div>
            <div class="active-room-item" onclick="openModal('roomDetailModal')" style="cursor:pointer"><div class="room-avatar-placeholder" style="background:rgba(124,92,255,0.15)">🧪</div><span class="ar-name">AI Lab 1</span><span class="ar-count">👥 12</span></div>
            <div class="active-room-item" onclick="openModal('roomDetailModal')" style="cursor:pointer"><div class="room-avatar-placeholder" style="background:rgba(34,197,94,0.15)">🔬</div><span class="ar-name">AI Lab 2</span><span class="ar-count">👥 9</span></div>
            <div class="active-room-item" onclick="openModal('roomDetailModal')" style="cursor:pointer"><div class="room-avatar-placeholder" style="background:rgba(239,68,68,0.15)">📝</div><span class="ar-name">Thesis Group</span><span class="ar-count">👥 15</span></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Engagement by Channel</div></div>
          <div class="chart-wrap" style="height:175px"><canvas id="engagementChart"></canvas></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">Moderation Queue</div><button class="text-link" onclick="showPage('moderation')">View All</button></div>
        <div class="mod-grid">
          <div class="mod-item"><div class="mod-header"><div class="mod-icon red">🚫</div><div><div class="mod-text">Flagged: "Check this out 🍄 spam"</div><div class="mod-sub">Reported by Sara_Kim</div></div></div><div class="mod-actions"><button class="btn-approve" onclick="moderateAction('approve','spam message','Sara_Kim')">Approve</button><button class="btn-deny" onclick="moderateAction('deny','spam message','Sara_Kim')">Deny</button></div></div>
          <div class="mod-item"><div class="mod-header"><div class="mod-icon blue">🤖</div><div><div class="mod-text">Pending: AI Match Request</div><div class="mod-sub">Fatima_Student & John_Doe</div></div></div><div class="mod-actions"><button class="btn-pink" onclick="openModal('aiMatchModal')">View</button></div></div>
          <div class="mod-item"><div class="mod-header"><div class="mod-icon red">💬</div><div><div class="mod-text">Flagged: "Click here to win..."</div><div class="mod-sub">Reported by John_Doe</div></div></div><div class="mod-actions"><button class="btn-approve" onclick="moderateAction('approve','phishing','John_Doe')">Approve</button><button class="btn-deny" onclick="moderateAction('deny','phishing','John_Doe')">Deny</button></div></div>
          <div class="mod-item"><div class="mod-header"><div class="mod-icon yellow">⚠</div><div><div class="mod-text">Reported Content: Study Room 2</div><div class="mod-sub"></div></div></div><div class="mod-actions"><button class="btn-pink" onclick="openModal('reportDetailModal')">View</button></div></div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-header"><div class="card-title">AI System Health</div></div>
          <div class="health-body">
            <div class="health-metric"><div class="health-label">Matching Accuracy</div><div class="health-value" style="color:var(--green)"><?= number_format((float)($stats['ai_accuracy']??98.7),1) ?>%</div><div class="health-change">▲ +2.1% from last week</div></div>
            <canvas id="ringChart" width="70" height="70"></canvas>
            <div class="health-metric"><div class="health-label">Avg Response Time</div><div class="health-value">1.2s</div><div class="health-change neg">▼ -0.3s</div></div>
            <div class="health-metric"><div class="health-label">System Uptime</div><div class="health-value" style="color:var(--green)">99.9%</div><div class="health-change">▲ +0.1%</div></div>
          </div>
          <button class="btn-details" onclick="showPage('syshealth')">View Details</button>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">System Log</div><button class="text-link" onclick="showPage('activitylogs')">View All</button></div>
          <div class="log-list" id="systemLogList">
<?php foreach (array_slice($dashData['system_logs'] ?? [], 0, 5) as $log):
  $dotColor = match($log['level'] ?? 'info') { 'error' => 'red', 'critical' => 'red', 'warning' => 'yellow', 'success' => 'green', default => 'blue' };
?>
            <div class="log-item"><div class="log-dot <?= $dotColor ?>"></div><div class="log-time"><?= htmlspecialchars($log['timestamp'] ?? '') ?></div><div class="log-msg"><?= htmlspecialchars($log['message'] ?? '') ?></div></div>
<?php endforeach; ?>
<?php if (empty($dashData['system_logs'])): ?>
            <div class="log-item"><div class="log-dot green"></div><div class="log-time">2025-05-19 14:32:21</div><div class="log-msg">John_Doe logged in</div></div>
            <div class="log-item"><div class="log-dot green"></div><div class="log-time">2025-05-19 14:15:10</div><div class="log-msg">New user registered: Sara_Kim</div></div>
            <div class="log-item"><div class="log-dot yellow"></div><div class="log-time">2025-05-19 13:45:33</div><div class="log-msg">Report submitted in #general</div></div>
            <div class="log-item"><div class="log-dot blue"></div><div class="log-time">2025-05-19 13:20:05</div><div class="log-msg">System backup completed</div></div>
            <div class="log-item"><div class="log-dot blue"></div><div class="log-time">2025-05-19 12:10:42</div><div class="log-msg">System cleanup completed</div></div>
<?php endif; ?>
          </div>
        </div>
      </div>
      <!-- MY SERVERS & CHANNELS -->
<?php $membership = $dashData['membership'] ?? []; ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">My Servers &amp; Channels</div>
          <button class="text-link" onclick="showPage('servers')">Manage Servers</button>
        </div>
        <div style="padding:0 14px 6px;font-size:11px;color:var(--muted)">
          You're a member of <?= (int)($membership['servers_joined_count'] ?? 0) ?> server<?= ((int)($membership['servers_joined_count'] ?? 0)) === 1 ? '' : 's' ?>
          and <?= (int)($membership['channels_joined_count'] ?? 0) ?> channel<?= ((int)($membership['channels_joined_count'] ?? 0)) === 1 ? '' : 's' ?>.
<?php if (($membership['servers_owned_count'] ?? 0) > 0): ?>
          You personally own <span style="color:var(--pink);font-weight:600"><?= (int)$membership['servers_owned_count'] ?> server<?= ((int)$membership['servers_owned_count']) === 1 ? '' : 's' ?></span>.
<?php endif; ?>
        </div>
        <div class="rooms-list">
<?php foreach (array_slice($membership['my_servers'] ?? [], 0, 5) as $srv):
  $srvRole = $srv['server_role'] ?? 'member';
  $roleLabel = match($srvRole) {
    'owner' => 'Owner', 'admin' => 'Admin', 'moderator' => 'Moderator', default => 'Member',
  };
?>
          <div class="room-item">
            <span class="room-hash"><?= htmlspecialchars($srv['icon_emoji'] ?? '🖥') ?></span>
            <span class="room-name"><?= htmlspecialchars($srv['name'] ?? '') ?></span>
            <span class="room-count"><?= (int)($srv['member_count'] ?? 0) ?> members · <?= (int)($srv['channel_count'] ?? 0) ?> channels</span>
            <span class="pill <?= $srvRole === 'owner' ? 'active' : 'offline' ?>" style="margin-left:8px"><?= $roleLabel ?></span>
            <button class="btn-join" onclick="openModal('serverDetailModal','<?= htmlspecialchars($srv['name'] ?? '') ?>')">Manage</button>
          </div>
<?php endforeach; ?>
<?php if (empty($membership['my_servers'])): ?>
          <div class="room-item">
            <span class="room-name" style="color:var(--muted)">You're not a member of any servers yet.</span>
          </div>
<?php endif; ?>
        </div>
      </div>

    </div>

    <!-- ══ USERS PAGE ══ -->
    <div class="page-section" id="page-users">
      <div class="page-title-row"><div><div class="page-title">Users</div><div class="page-sub">Manage platform users, roles, and activity.</div></div><button class="btn-primary" onclick="openModal('createUserModal')">+ Add User</button></div>
      <div class="card">
        <div class="filter-bar"><div class="filter-search"><span style="color:var(--muted)">🔍</span><input placeholder="Search users..." oninput="filterUsersTable(this.value)"></div><select class="select-filter"><option>All Courses</option><option>Computer Science</option><option>Information Tech</option></select><select class="select-filter"><option>All Roles</option><option>Student</option><option>Facilitator</option><option>Moderator</option><option>Admin</option></select><select class="select-filter"><option>All Status</option><option>Active</option><option>Offline</option><option>Banned</option></select><button class="btn-sm btn-outline" onclick="exportData('users')" style="margin-left:auto">⬇ Export</button></div>
        <div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Course</th><th>Status</th><th>Join Date</th><th>Actions</th></tr></thead><tbody id="usersTable">
<?php foreach ($dashData['all_users'] ?? [] as $u):
  $uI = strtoupper(substr($u['full_name'] ?? $u['username'] ?? '?', 0, 1));
  $uG = $u['avatar_color_gradient'] ?? '#ff4fd8,#7c5cff';
  $uN = htmlspecialchars($u['username'] ?? '');
  $uR = htmlspecialchars(ucfirst($u['role'] ?? 'student'));
  $uC = htmlspecialchars($u['course_name'] ?? 'N/A');
  $uS = $u['status'] === 'active';
  $uJ = htmlspecialchars($u['joined_label'] ?? '');
  $roleColor = match($u['role']??'student') { 'facilitator'=>'rgba(245,158,11,0.12);color:var(--yellow)', 'moderator'=>'rgba(0,212,255,0.12);color:var(--blue)', 'admin','super_admin'=>'rgba(255,79,216,0.12);color:var(--pink)', default=>'rgba(34,197,94,0.12);color:var(--green)' };
?>
          <tr>
            <td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,<?= htmlspecialchars($uG) ?>)"><?= htmlspecialchars($uI) ?></div><div><div class="u-name-main"><?= $uN ?></div><div class="u-handle">@<?= strtolower($uN) ?></div></div></div></td>
            <td><span class="pill" style="background:<?= $roleColor ?>"><?= $uR ?></span></td>
            <td><?= $uC ?></td>
            <td><?= $uS ? '<span class="pill active"><span class="pill-dot"></span>Active</span>' : '<span class="pill offline">Offline</span>' ?></td>
            <td style="color:var(--muted)"><?= $uJ ?></td>
            <td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('<?= $uN ?>','<?= htmlspecialchars($uI) ?>','<?= htmlspecialchars($uG) ?>','<?= $uR ?>','<?= $uC ?>','<?= $uS?'Active':'Offline' ?>','<?= $uJ ?>')">View</button><button class="btn-sm btn-outline" onclick="openModal('muteModal','<?= $uN ?>')">Mute</button><button class="btn-sm" style="background:rgba(245,158,11,0.15);color:var(--yellow)" onclick="openModal('kickModal','<?= $uN ?>')">Kick</button><button class="btn-deny" onclick="openModal('banModal','<?= $uN ?>')">Ban</button></div></td>
          </tr>
<?php endforeach; ?>
<?php if (empty($dashData['all_users'])): ?>
          <tr><td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,#ff4fd8,#7c5cff)">F</div><div><div class="u-name-main">Fatima_Student</div><div class="u-handle">@fatima.student</div></div></div></td><td><span class="pill" style="background:rgba(34,197,94,0.12);color:var(--green)">Student</span></td><td>Computer Science</td><td><span class="pill active"><span class="pill-dot"></span>Active</span></td><td style="color:var(--muted)">Jan 12, 2025</td><td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('Fatima_Student','F','#ff4fd8,#7c5cff','Student','Computer Science','Active','Jan 12 2025')">View</button><button class="btn-sm btn-outline" onclick="openModal('muteModal','Fatima_Student')">Mute</button><button class="btn-sm" style="background:rgba(245,158,11,0.15);color:var(--yellow)" onclick="openModal('kickModal','Fatima_Student')">Kick</button><button class="btn-deny" onclick="openModal('banModal','Fatima_Student')">Ban</button></div></td></tr>
          <tr><td><div class="user-cell"><div class="u-avatar" style="background:linear-gradient(135deg,#ef4444,#dc2626)">A</div><div><div class="u-name-main">Adam_Smith</div><div class="u-handle">@adam.smith</div></div></div></td><td><span class="pill" style="background:rgba(245,158,11,0.12);color:var(--yellow)">Facilitator</span></td><td>Computer Science</td><td><span class="pill active"><span class="pill-dot"></span>Active</span></td><td style="color:var(--muted)">Dec 20, 2024</td><td><div class="action-btns"><button class="btn-view" onclick="openUserProfile('Adam_Smith','A','#ef4444,#dc2626','Facilitator','Computer Science','Active','Dec 20 2024')">View</button><button class="btn-sm btn-outline" onclick="openModal('editRoleModal','Adam_Smith')">Edit Role</button><button class="btn-deny" onclick="openModal('banModal','Adam_Smith')">Ban</button></div></td></tr>
<?php endif; ?>
        </tbody></table></div>
        <div class="pagination"><div class="page-info">Showing 1–<?= min(10,count($dashData['all_users']??[])?:6) ?> of <?= number_format((int)($stats['total_users']??1248)) ?> users</div><div class="page-btns"><button class="page-btn">‹</button><button class="page-btn active">1</button><button class="page-btn" onclick="showToast('Page 2','info','📄')">2</button><button class="page-btn" onclick="showToast('Page 3','info','📄')">3</button><button class="page-btn">…</button><button class="page-btn">›</button></div></div>
      </div>
    </div>

    <!-- ══ ROLES PAGE ══ -->
    <div class="page-section" id="page-roles">
      <div class="page-title-row"><div><div class="page-title">Roles & Permissions</div></div><button class="btn-primary" onclick="openModal('createRoleModal')">+ Create Role</button></div>
      <div class="card"><div class="roles-grid">
        <div class="role-card"><div class="role-card-icon" style="background:rgba(34,197,94,0.15)">🎓</div><div class="role-card-name">Student</div><div class="role-card-count"><?= number_format((int)($stats['active_students']??892)) ?> members</div><div class="role-card-perms"><span class="perm-tag granted">Read Messages</span><span class="perm-tag granted">Send Messages</span><span class="perm-tag granted">Join Rooms</span><span class="perm-tag">Manage Rooms</span></div><div style="display:flex;gap:6px"><button class="btn-sm btn-outline" onclick="openModal('editPermsModal','Student')">Edit Permissions</button></div></div>
        <div class="role-card"><div class="role-card-icon" style="background:rgba(245,158,11,0.15)">📚</div><div class="role-card-name">Facilitator</div><div class="role-card-count">148 members</div><div class="role-card-perms"><span class="perm-tag granted">All Student</span><span class="perm-tag granted">Manage Rooms</span><span class="perm-tag granted">Pin Messages</span><span class="perm-tag granted">Mute Users</span></div><div style="display:flex;gap:6px"><button class="btn-sm btn-outline" onclick="openModal('editPermsModal','Facilitator')">Edit Permissions</button></div></div>
        <div class="role-card"><div class="role-card-icon" style="background:rgba(0,212,255,0.15)">🛡</div><div class="role-card-name">Moderator</div><div class="role-card-count">24 members</div><div class="role-card-perms"><span class="perm-tag granted">All Facilitator</span><span class="perm-tag granted">Kick Users</span><span class="perm-tag granted">Ban Users</span><span class="perm-tag granted">Manage Reports</span></div><div style="display:flex;gap:6px"><button class="btn-sm btn-outline" onclick="openModal('editPermsModal','Moderator')">Edit Permissions</button></div></div>
        <div class="role-card"><div class="role-card-icon" style="background:rgba(255,79,216,0.15)">👑</div><div class="role-card-name">Admin</div><div class="role-card-count">6 members</div><div class="role-card-perms"><span class="perm-tag granted">All Permissions</span><span class="perm-tag granted">Manage Roles</span><span class="perm-tag granted">System Access</span><span class="perm-tag granted">Delete Servers</span></div><div style="display:flex;gap:6px"><button class="btn-sm btn-outline" onclick="openModal('editPermsModal','Admin')">Edit Permissions</button><button class="btn-sm" style="background:rgba(239,68,68,0.1);color:var(--red);border:none;opacity:0.4;cursor:not-allowed">Protected</button></div></div>
      </div></div>
    </div>

    <!-- ══ COURSES PAGE ══ -->
    <div class="page-section" id="page-courses">
      <div class="page-title-row"><div><div class="page-title">Course & Tags</div></div><div style="display:flex;gap:10px"><button class="btn-secondary" onclick="openModal('createTagModal')">+ Add Tag</button><button class="btn-primary" onclick="openModal('createCourseModal')">+ Create Course</button></div></div>
      <div class="card"><div class="card-header"><div class="card-title">Active Courses & Tags</div></div><div class="tags-grid" id="tagsGrid">
        <span class="tag-item" style="background:rgba(255,79,216,0.15);color:var(--pink)">Computer Science <span class="tag-x" onclick="removeTag(this,'Computer Science')">✕</span></span>
        <span class="tag-item" style="background:rgba(0,212,255,0.15);color:var(--blue)">Information Technology <span class="tag-x" onclick="removeTag(this,'IT')">✕</span></span>
        <span class="tag-item" style="background:rgba(34,197,94,0.15);color:var(--green)">Data Structures <span class="tag-x" onclick="removeTag(this,'DSA')">✕</span></span>
        <span class="tag-item" style="background:rgba(124,92,255,0.15);color:#a78bfa">Algorithms <span class="tag-x" onclick="removeTag(this,'Algorithms')">✕</span></span>
        <span class="tag-item" style="background:rgba(245,158,11,0.15);color:var(--yellow)">Machine Learning <span class="tag-x" onclick="removeTag(this,'ML')">✕</span></span>
        <span class="tag-item" style="background:rgba(239,68,68,0.15);color:var(--red)">Deep Learning <span class="tag-x" onclick="removeTag(this,'DL')">✕</span></span>
        <span class="tag-item" style="background:rgba(255,79,216,0.15);color:var(--pink)">Web Development <span class="tag-x" onclick="removeTag(this,'Web')">✕</span></span>
        <span class="tag-item" style="background:rgba(0,212,255,0.15);color:var(--blue)">Mobile Development <span class="tag-x" onclick="removeTag(this,'Mobile')">✕</span></span>
        <span class="tag-item" style="background:rgba(34,197,94,0.15);color:var(--green)">Database Systems <span class="tag-x" onclick="removeTag(this,'DB')">✕</span></span>
        <span class="tag-item" style="background:rgba(124,92,255,0.15);color:#a78bfa">Networking <span class="tag-x" onclick="removeTag(this,'Networks')">✕</span></span>
      </div></div>
    </div>

    <!-- ══ AI MATCHING PAGE ══ -->
    <div class="page-section" id="page-aimatching">
      <div class="page-title-row"><div><div class="page-title">AI Matching</div></div><button class="btn-primary" onclick="openModal('aiConfigModal')">⚙ Configure Rules</button></div>
      <div class="card"><div class="card-header"><div class="card-title">Matching Statistics</div><button class="btn-sm btn-outline" onclick="showToast('Refreshing...','info','🔄')">🔄 Refresh</button></div>
        <div class="matching-stats"><div class="ms-card"><div class="ms-label">Matching Accuracy</div><div class="ms-val" style="color:var(--green)"><?= number_format((float)($stats['ai_accuracy']??98.7),1) ?>%</div></div><div class="ms-card"><div class="ms-label">Matches Today</div><div class="ms-val" style="color:var(--blue)">234</div></div><div class="ms-card"><div class="ms-label">Active Study Groups</div><div class="ms-val" style="color:#a78bfa"><?= (int)($stats['sessions_today']??47) ?></div></div></div>
        <div class="chart-wrap" style="height:200px"><canvas id="matchingChart"></canvas></div>
      </div>
      <div class="card"><div class="card-header"><div class="card-title">Pending Match Requests</div></div><div class="table-wrap"><table><thead><tr><th>Students</th><th>Compatibility</th><th>Tags</th><th>Actions</th></tr></thead><tbody>
        <tr><td>Fatima_Student & John_Doe</td><td><span style="color:var(--green);font-weight:700">94%</span></td><td>Computer Science, Data Structures</td><td><div class="action-btns"><button class="btn-approve" onclick="showToast('Match approved!','success','✅')">Approve</button><button class="btn-deny" onclick="showToast('Match denied','error','❌')">Deny</button><button class="btn-view" onclick="openModal('aiMatchModal')">Preview</button></div></td></tr>
        <tr><td>Mike_Lee & Sara_Kim</td><td><span style="color:var(--yellow);font-weight:700">78%</span></td><td>Information Tech, Algorithms</td><td><div class="action-btns"><button class="btn-approve" onclick="showToast('Match approved!','success','✅')">Approve</button><button class="btn-deny" onclick="showToast('Match denied','error','❌')">Deny</button><button class="btn-view" onclick="openModal('aiMatchModal')">Preview</button></div></td></tr>
      </tbody></table></div></div>
    </div>

    <!-- ══ REPORTS PAGE ══ -->
    <div class="page-section" id="page-reports">
      <div class="page-title-row"><div><div class="page-title">Reports</div><div class="page-sub">Review flagged messages and content.</div></div></div>
      <div class="card">
        <div class="filter-bar"><div class="filter-search"><span style="color:var(--muted)">🔍</span><input placeholder="Search reports..."></div><select class="select-filter"><option>All Types</option><option>Message</option><option>User</option><option>Content</option></select><span style="background:rgba(239,68,68,0.15);color:var(--red);padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;margin-left:auto"><?= (int)($stats['reports_pending']??8) ?> pending</span></div>
        <div id="reportsContainer">
          <div class="report-item"><div class="ri-icon" style="background:rgba(239,68,68,0.15)">🚫</div><div class="ri-body"><div class="ri-title">Flagged Message: "Check this out 🍄 spam"</div><div class="ri-meta">Reported by Sara_Kim • #general • 2h ago</div></div><div class="ri-actions"><button class="btn-approve" onclick="resolveReport(this,'approved')">Approve</button><button class="btn-deny" onclick="resolveReport(this,'denied')">Deny</button><button class="btn-view" onclick="openModal('reportDetailModal')">Detail</button></div></div>
          <div class="report-item"><div class="ri-icon" style="background:rgba(239,68,68,0.15)">💬</div><div class="ri-body"><div class="ri-title">Flagged Message: "Click here to win..."</div><div class="ri-meta">Reported by John_Doe • #announcements • 3h ago</div></div><div class="ri-actions"><button class="btn-approve" onclick="resolveReport(this,'approved')">Approve</button><button class="btn-deny" onclick="resolveReport(this,'denied')">Deny</button><button class="btn-view" onclick="openModal('reportDetailModal')">Detail</button></div></div>
          <div class="report-item"><div class="ri-icon" style="background:rgba(245,158,11,0.15)">⚠</div><div class="ri-body"><div class="ri-title">Reported User: spam_user123</div><div class="ri-meta">Reported by Jessica_Lee • 6h ago</div></div><div class="ri-actions"><button class="btn-deny" onclick="openModal('banModal','spam_user123')">Ban User</button><button class="btn-view" onclick="openModal('reportDetailModal')">Detail</button></div></div>
        </div>
      </div>
    </div>

    <!-- ══ SERVERS PAGE ══ -->
    <div class="page-section" id="page-servers">
      <div class="page-title-row"><div><div class="page-title">Servers</div></div><button class="btn-primary" onclick="openModal('createServerModal')">+ Create Server</button></div>
      <div class="card"><div class="card-header"><div class="card-title">All Servers</div></div><div id="serversList">
<?php foreach ($dashData['servers'] ?? [] as $srv): $sI=htmlspecialchars($srv['icon_emoji']??'🖥'); $sN=htmlspecialchars($srv['name']??''); $sC=(int)($srv['member_count']??0); ?>
        <div class="server-item"><div class="srv-icon" style="background:rgba(255,79,216,0.15)"><?= $sI ?></div><div class="srv-name"><?= $sN ?></div><div class="srv-count">👥 <?= number_format($sC) ?> members</div><div class="srv-status-dot"></div><div class="action-btns" style="margin-left:12px"><button class="btn-view" onclick="openModal('serverDetailModal','<?= $sN ?>')">Manage</button><button class="btn-sm btn-outline" onclick="openModal('serverPermsModal','<?= $sN ?>')">Permissions</button><button class="btn-deny" onclick="openModal('deleteServerModal','<?= $sN ?>')">Delete</button></div></div>
<?php endforeach; ?>
<?php if (empty($dashData['servers'])): ?>
        <div class="server-item"><div class="srv-icon" style="background:rgba(255,79,216,0.15)">🖥</div><div class="srv-name">Main Campus Server</div><div class="srv-count">👥 892 members</div><div class="srv-status-dot"></div><div class="action-btns" style="margin-left:12px"><button class="btn-view" onclick="openModal('serverDetailModal','Main Campus Server')">Manage</button><button class="btn-sm btn-outline">Permissions</button><button class="btn-deny" onclick="openModal('deleteServerModal','Main Campus Server')">Delete</button></div></div>
        <div class="server-item"><div class="srv-icon" style="background:rgba(0,212,255,0.15)">🖥</div><div class="srv-name">CS Department</div><div class="srv-count">👥 456 members</div><div class="srv-status-dot"></div><div class="action-btns" style="margin-left:12px"><button class="btn-view" onclick="openModal('serverDetailModal','CS Department')">Manage</button><button class="btn-sm btn-outline">Permissions</button><button class="btn-deny" onclick="openModal('deleteServerModal','CS Department')">Delete</button></div></div>
<?php endif; ?>
      </div></div>
    </div>

    <!-- ══ CHANNELS PAGE ══ -->
    <div class="page-section" id="page-channels">
      <div class="page-title-row"><div><div class="page-title">Channels</div></div><button class="btn-primary" onclick="openModal('createChannelModal')">+ Create Channel</button></div>
      <div class="card"><div class="card-header"><div class="card-title">All Channels</div></div><div id="channelsList">
        <div class="channel-item"><div class="ch-type-icon">#</div><span class="ch-name">#general</span><span class="ch-badge">Text</span><span style="color:var(--muted);font-size:11px;margin-left:auto;margin-right:12px"><?= number_format((int)($stats['total_users']??892)) ?> members</span><div class="action-btns"><button class="btn-view" onclick="openModal('editChannelModal','#general')">Edit</button><button class="btn-sm btn-outline">Permissions</button><button class="btn-deny" onclick="openModal('deleteChannelModal','#general')">Delete</button></div></div>
        <div class="channel-item"><div class="ch-type-icon">#</div><span class="ch-name">#announcements</span><span class="ch-badge">Text</span><span style="color:var(--muted);font-size:11px;margin-left:auto;margin-right:12px"><?= number_format((int)($stats['total_users']??892)) ?> members</span><div class="action-btns"><button class="btn-view" onclick="openModal('editChannelModal','#announcements')">Edit</button><button class="btn-sm btn-outline">Permissions</button><button class="btn-deny" onclick="openModal('deleteChannelModal','#announcements')">Delete</button></div></div>
        <div class="channel-item"><div class="ch-type-icon">🔊</div><span class="ch-name">Voice Room 1</span><span class="ch-badge">Voice</span><span style="color:var(--muted);font-size:11px;margin-left:auto;margin-right:12px">12 active</span><div class="action-btns"><button class="btn-view" onclick="openModal('editChannelModal','Voice Room 1')">Edit</button><button class="btn-sm btn-outline">Permissions</button><button class="btn-deny" onclick="openModal('deleteChannelModal','Voice Room 1')">Delete</button></div></div>
        <div class="channel-item"><div class="ch-type-icon">🎨</div><span class="ch-name">Whiteboard Studio</span><span class="ch-badge">Whiteboard</span><span style="color:var(--muted);font-size:11px;margin-left:auto;margin-right:12px">8 active</span><div class="action-btns"><button class="btn-view" onclick="openModal('editChannelModal','Whiteboard Studio')">Edit</button><button class="btn-sm btn-outline">Permissions</button><button class="btn-deny" onclick="openModal('deleteChannelModal','Whiteboard Studio')">Delete</button></div></div>
      </div></div>
    </div>

    <!-- ══ SETTINGS PAGE ══ -->
    <div class="page-section" id="page-settings">
      <div class="page-title-row"><div><div class="page-title">Settings</div></div><button class="btn-primary" onclick="saveSettings()">💾 Save Changes</button></div>
      <div class="card">
        <div class="settings-tabs" id="settingsTabs"><div class="settings-tab active" onclick="switchSettingsTab(this,'general')">General</div><div class="settings-tab" onclick="switchSettingsTab(this,'appearance')">Appearance</div><div class="settings-tab" onclick="switchSettingsTab(this,'security')">Security</div><div class="settings-tab" onclick="switchSettingsTab(this,'notifications')">Notifications</div><div class="settings-tab" onclick="switchSettingsTab(this,'privacy')">Privacy</div></div>
        <div class="settings-body" id="settingsGeneral"><div class="settings-row"><div><div class="settings-label">Platform Name</div><div class="settings-desc">Displayed across the platform.</div></div><input class="form-input" style="width:200px" value="<?= APP_NAME ?>" id="platformName"></div><div class="settings-row"><div><div class="settings-label">Maintenance Mode</div><div class="settings-desc">Temporarily disable access.</div></div><div class="toggle" onclick="toggleSetting(this,'Maintenance Mode')"></div></div><div class="settings-row"><div><div class="settings-label">Registration Open</div><div class="settings-desc">Allow new users to register.</div></div><div class="toggle on" onclick="toggleSetting(this,'Registration')"></div></div><div class="settings-row"><div><div class="settings-label">AI Matching</div><div class="settings-desc">Enable AI-powered matching.</div></div><div class="toggle on" onclick="toggleSetting(this,'AI Matching')"></div></div></div>
        <div class="settings-body" id="settingsAppearance" style="display:none"><div class="settings-row"><div><div class="settings-label">Dark Mode</div></div><div class="toggle on" onclick="toggleSetting(this,'Dark Mode')"></div></div><div class="settings-row"><div><div class="settings-label">Compact Mode</div></div><div class="toggle" onclick="toggleSetting(this,'Compact Mode')"></div></div></div>
        <div class="settings-body" id="settingsSecurity" style="display:none"><div class="settings-row"><div><div class="settings-label">Two-Factor Auth</div><div class="settings-desc">Require 2FA for admins.</div></div><div class="toggle on" onclick="toggleSetting(this,'2FA')"></div></div><div class="settings-row"><div><div class="settings-label">Session Timeout</div></div><select class="form-input" style="width:160px"><option>30 minutes</option><option selected>1 hour</option><option>4 hours</option></select></div><div class="settings-row"><div><div class="settings-label">Change Password</div></div><button class="btn-secondary" onclick="openModal('changePasswordModal')">Change Password</button></div></div>
        <div class="settings-body" id="settingsNotifications" style="display:none"><div class="settings-row"><div><div class="settings-label">New User Registered</div></div><div class="toggle on" onclick="toggleSetting(this,'New User Notifications')"></div></div><div class="settings-row"><div><div class="settings-label">Moderation Alerts</div></div><div class="toggle on" onclick="toggleSetting(this,'Moderation Alerts')"></div></div></div>
        <div class="settings-body" id="settingsPrivacy" style="display:none"><div class="settings-row"><div><div class="settings-label">Activity Logging</div></div><div class="toggle on" onclick="toggleSetting(this,'Activity Logging')"></div></div><div class="settings-row"><div><div class="settings-label">Export All Data</div></div><button class="btn-secondary" onclick="exportData('all')">Export All Data</button></div></div>
      </div>
    </div>

    <!-- ══ MODERATION PAGE ══ -->
    <div class="page-section" id="page-moderation">
      <div class="page-title-row"><div><div class="page-title">Moderation</div></div><button class="btn-primary" onclick="openModal('issueBanModal')">🔨 Issue Action</button></div>
      <div class="card">
        <div class="filter-bar"><button class="log-filter-btn active" onclick="filterModLog(this,'all')">All</button><button class="log-filter-btn" onclick="filterModLog(this,'ban')">Bans</button><button class="log-filter-btn" onclick="filterModLog(this,'kick')">Kicks</button><button class="log-filter-btn" onclick="filterModLog(this,'warn')">Warnings</button><button class="log-filter-btn" onclick="filterModLog(this,'mute')">Mutes</button><button class="btn-sm btn-outline" style="margin-left:auto" onclick="exportData('modlogs')">⬇ Export</button></div>
        <div id="modLogContainer">
          <div class="log-entry" data-type="ban"><div class="log-type-badge ban">BAN</div><div class="le-info"><div class="le-main">spam_user123 was permanently banned</div><div class="le-sub">By Moderator: Adam_Smith • Reason: Spamming</div></div><div class="le-time">2h ago</div><button class="btn-view" style="margin-left:8px" onclick="openModal('modActionDetailModal','ban')">View</button></div>
          <div class="log-entry" data-type="kick"><div class="log-type-badge kick">KICK</div><div class="le-info"><div class="le-main">trollUser99 was kicked from Study Room 2</div><div class="le-sub">By Moderator: Sara_Kim • Reason: Disruptive behavior</div></div><div class="le-time">4h ago</div><button class="btn-view" style="margin-left:8px" onclick="openModal('modActionDetailModal','kick')">View</button></div>
          <div class="log-entry" data-type="warn"><div class="log-type-badge warn">WARN</div><div class="le-info"><div class="le-main">Warning issued to David_Wilson</div><div class="le-sub">By Moderator: Adam_Smith • Reason: Inappropriate language</div></div><div class="le-time">6h ago</div><button class="btn-view" style="margin-left:8px" onclick="openModal('modActionDetailModal','warn')">View</button></div>
          <div class="log-entry" data-type="mute"><div class="log-type-badge mute">MUTE</div><div class="le-info"><div class="le-main">Mike_Lee muted for 1 hour</div><div class="le-sub">By Moderator: Sara_Kim • Reason: Repeated spam</div></div><div class="le-time">8h ago</div><button class="btn-view" style="margin-left:8px" onclick="openModal('modActionDetailModal','mute')">View</button></div>
        </div>
      </div>
    </div>

    <!-- ══ ANALYTICS PAGE ══ -->
    <div class="page-section" id="page-analytics">
      <div class="page-title-row"><div><div class="page-title">Analytics</div></div><div style="display:flex;gap:10px"><select class="select-filter"><option>Last 7 days</option><option>Last 30 days</option><option>Last 90 days</option></select><button class="btn-secondary" onclick="exportData('analytics')">⬇ Export</button></div></div>
      <div class="analytics-grid"><div class="card"><div class="card-header"><div class="card-title">Daily Active Users</div></div><div class="chart-wrap" style="height:180px"><canvas id="dauChart"></canvas></div></div><div class="card"><div class="card-header"><div class="card-title">Study Session Frequency</div></div><div class="chart-wrap" style="height:180px"><canvas id="sessFreqChart"></canvas></div></div></div>
      <div class="card"><div class="card-header"><div class="card-title">Course Activity Distribution</div></div><div class="chart-wrap" style="height:200px"><canvas id="courseChart"></canvas></div></div>
    </div>

    <!-- ══ ACTIVITY LOGS PAGE ══ -->
    <div class="page-section" id="page-activitylogs">
      <div class="page-title-row"><div><div class="page-title">Activity Logs</div></div><button class="btn-secondary" onclick="exportData('logs')">⬇ Export Logs</button></div>
      <div class="card">
        <div class="filter-bar"><button class="log-filter-btn active" onclick="this.parentElement.querySelectorAll('.log-filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active')">All</button><button class="log-filter-btn" onclick="this.parentElement.querySelectorAll('.log-filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active')">System</button><button class="log-filter-btn" onclick="this.parentElement.querySelectorAll('.log-filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active')">Users</button><button class="log-filter-btn" onclick="this.parentElement.querySelectorAll('.log-filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active')">Moderation</button></div>
        <div class="log-list" style="padding:14px 18px">
<?php foreach ($dashData['system_logs'] ?? [] as $log): $dotColor=match($log['level']??'info'){'error'=>'red','warn'=>'yellow','success'=>'green',default=>'blue'}; ?>
          <div class="log-item"><div class="log-dot <?= $dotColor ?>"></div><div class="log-time"><?= htmlspecialchars($log['timestamp']??'') ?></div><div class="log-msg"><?= htmlspecialchars($log['message']??'') ?></div></div>
<?php endforeach; ?>
<?php if (empty($dashData['system_logs'])): ?>
          <div class="log-item"><div class="log-dot green"></div><div class="log-time">2025-05-19 14:32:21</div><div class="log-msg">John_Doe logged in from 192.168.1.1</div></div>
          <div class="log-item"><div class="log-dot green"></div><div class="log-time">2025-05-19 14:15:10</div><div class="log-msg">New user registered: Sara_Kim</div></div>
          <div class="log-item"><div class="log-dot yellow"></div><div class="log-time">2025-05-19 13:45:33</div><div class="log-msg">Report submitted in #general by Fatima_Student</div></div>
          <div class="log-item"><div class="log-dot blue"></div><div class="log-time">2025-05-19 13:20:05</div><div class="log-msg">System backup completed: 2.3GB archived</div></div>
          <div class="log-item"><div class="log-dot red"></div><div class="log-time">2025-05-19 10:08:31</div><div class="log-msg">Ban issued: spam_user123 banned by Adam_Smith</div></div>
<?php endif; ?>
        </div>
        <div class="pagination"><div class="page-info">Showing 1–5 of <?= number_format((int)($stats['total_users']??4832)*4) ?> events</div><div class="page-btns"><button class="page-btn">‹</button><button class="page-btn active">1</button><button class="page-btn" onclick="showToast('Page 2','info','📄')">2</button><button class="page-btn">…</button><button class="page-btn">›</button></div></div>
      </div>
    </div>

    <!-- ══ SYSTEM HEALTH PAGE ══ -->
    <div class="page-section" id="page-syshealth">
      <div class="page-title-row"><div><div class="page-title">System Health</div></div><button class="btn-secondary" onclick="refreshHealth()">🔄 Refresh</button></div>
      <div class="card" style="margin-bottom:16px"><div class="card-header"><div class="card-title">Live Metrics</div><div class="status-badge"><div class="status-dot"></div>All Systems Operational</div></div>
        <div class="health-monitors">
          <div class="hm-card"><div class="hm-label">CPU Usage</div><div class="hm-value yellow" id="cpuVal">42%</div><div class="hm-bar"><div class="hm-fill" id="cpuBar" style="width:42%;background:linear-gradient(90deg,var(--yellow),var(--red))"></div></div></div>
          <div class="hm-card"><div class="hm-label">Memory Usage</div><div class="hm-value yellow" id="memVal">67%</div><div class="hm-bar"><div class="hm-fill" id="memBar" style="width:67%;background:linear-gradient(90deg,var(--blue),var(--purple))"></div></div></div>
          <div class="hm-card"><div class="hm-label">Server Uptime</div><div class="hm-value green">99.9%</div><div class="hm-bar"><div class="hm-fill" style="width:99.9%;background:linear-gradient(90deg,var(--green),var(--blue))"></div></div></div>
          <div class="hm-card"><div class="hm-label">Avg Response</div><div class="hm-value blue">1.2s</div><div class="hm-bar"><div class="hm-fill" style="width:30%;background:linear-gradient(90deg,var(--green),var(--blue))"></div></div></div>
          <div class="hm-card"><div class="hm-label">Error Rate</div><div class="hm-value green">0.02%</div><div class="hm-bar"><div class="hm-fill" style="width:2%;background:var(--green)"></div></div></div>
        </div>
      </div>
      <div class="card"><div class="card-header"><div class="card-title">Error Logs</div><button class="btn-sm btn-outline" onclick="openModal('clearLogsModal')">Clear Logs</button></div><div class="log-list" style="padding:14px 18px"><div class="log-item"><div class="log-dot yellow"></div><div class="log-time">2025-05-19 14:30:00</div><div class="log-msg">Warning: High memory usage detected (67%)</div></div><div class="log-item"><div class="log-dot green"></div><div class="log-time">2025-05-19 12:00:00</div><div class="log-msg">Info: Auto-scaling triggered, capacity increased</div></div><div class="log-item"><div class="log-dot green"></div><div class="log-time">2025-05-19 08:00:00</div><div class="log-msg">Info: Daily health check passed</div></div></div></div>
    </div>

    <!-- ══ ANNOUNCEMENTS PAGE ══ -->
    <div class="page-section" id="page-announcements">
      <div class="page-title-row"><div><div class="page-title">Announcements</div></div></div>
      <div class="card"><div class="card-header"><div class="card-title">Create Announcement</div></div>
        <div class="announcement-form">
          <div class="form-row"><div class="form-group"><label class="form-label">Title</label><input class="form-input" id="announcTitle" placeholder="Announcement title..."></div><div class="form-group"><label class="form-label">Priority</label><select class="form-input" id="announcPriority"><option>Normal</option><option>Important</option><option>Urgent</option></select></div></div>
          <div class="form-group"><label class="form-label">Message</label><textarea class="form-textarea" id="announcMsg" placeholder="Write your announcement..." style="min-height:100px"></textarea></div>
          <div class="form-row"><div class="form-group"><label class="form-label">Target Server</label><select class="form-input"><option>All Servers</option><?php foreach($dashData['servers']??[] as $s): ?><option><?=htmlspecialchars($s['name']??'')?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Schedule (optional)</label><input class="form-input" type="datetime-local"></div></div>
          <div style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn-primary" onclick="sendAnnouncement()">📢 Broadcast Now</button><button class="btn-secondary" onclick="scheduleAnnouncement()">🕐 Schedule Post</button><button class="btn-secondary" onclick="previewAnnouncement()">👁 Preview</button></div>
        </div>
      </div>
      <div class="card"><div class="card-header"><div class="card-title">Recent Announcements</div></div><div id="announcList">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:12px"><div style="width:8px;height:8px;border-radius:50%;background:var(--yellow);margin-top:5px;flex-shrink:0"></div><div style="flex:1"><div style="font-size:13px;font-weight:600">System Maintenance Scheduled</div><div style="font-size:11px;color:var(--muted);margin-top:2px">All Servers • 2025-05-18 09:00</div></div></div>
      </div></div>
    </div>

    <!-- ══ FEEDBACK PAGE ══ -->
    <div class="page-section" id="page-feedback">
      <div class="page-title-row"><div><div class="page-title">Feedback & Reports</div></div></div>
      <div class="card"><div class="card-header"><div class="card-title">User Feedback</div></div><div style="padding:20px;text-align:center;color:var(--muted)">No feedback submitted yet.</div></div>
    </div>

  </div>
</div>

<!-- CONTEXT MENU -->
<div class="context-menu" id="ctxMenu">
  <div class="ctx-item" onclick="editFromCtx()">✏️ Edit Profile</div>
  <div class="ctx-item" onclick="changeRoleFromCtx()">🎭 Change Role</div>
  <div class="ctx-sep"></div>
  <div class="ctx-item" onclick="muteFromCtx()">🔇 Mute User</div>
  <div class="ctx-item" onclick="kickFromCtx()">👟 Kick User</div>
  <div class="ctx-item danger" onclick="banFromCtx()">🚫 Ban User</div>
</div>

<!-- USER PROFILE PANEL -->
<div class="user-profile-panel" id="userProfilePanel">
  <div class="upp-header">
    <div class="upp-banner"></div>
    <div class="upp-avatar" id="uppAvatar"></div>
    <button class="upp-close" onclick="closeUserProfile()">✕</button>
  </div>
  <div class="upp-body">
    <div class="upp-name" id="uppName"></div>
    <div class="upp-handle" id="uppHandle"></div>
    <div class="upp-role-badge" id="uppRole"></div>
    <div class="upp-info-grid">
      <div class="upp-info-item"><div class="upp-info-label">Course</div><div class="upp-info-val" id="uppCourse"></div></div>
      <div class="upp-info-item"><div class="upp-info-label">Status</div><div class="upp-info-val" id="uppStatus"></div></div>
      <div class="upp-info-item"><div class="upp-info-label">Joined</div><div class="upp-info-val" id="uppJoined"></div></div>
      <div class="upp-info-item"><div class="upp-info-label">Messages</div><div class="upp-info-val">--</div></div>
    </div>
    <div class="upp-actions">
      <button class="btn-primary" onclick="openModal('editRoleModal',document.getElementById('uppName').textContent)">Change Role</button>
      <button class="btn-secondary" onclick="openModal('muteModal',document.getElementById('uppName').textContent)">Mute</button>
      <button class="btn-deny" onclick="openModal('banModal',document.getElementById('uppName').textContent)">Ban</button>
    </div>
  </div>
</div>
<div class="overlay" id="overlayPanel" onclick="closeUserProfile()"></div>

<!-- ALL MODALS -->
<div class="modal-overlay" id="logoutModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Sign Out</div><button class="modal-close" onclick="closeModal('logoutModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-yellow">🚪</div><div style="font-size:16px;font-weight:700;margin-bottom:7px">Sign out of <?= APP_NAME ?>?</div><div style="color:var(--muted);font-size:12px">You'll need to sign in again.</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('logoutModal')">Cancel</button><button class="btn-danger" onclick="doLogout()">🚪 Sign Out</button></div></div></div>
<div class="modal-overlay" id="createUserModal"><div class="modal modal-md"><div class="modal-header"><div class="modal-title">Create User</div><button class="modal-close" onclick="closeModal('createUserModal')">✕</button></div><div class="modal-body"><div class="form-row"><div class="form-group"><label class="form-label">Username</label><input class="form-input" id="newUsername" placeholder="username"></div><div class="form-group"><label class="form-label">Full Name</label><input class="form-input" id="newFullName" placeholder="Full Name"></div></div><div class="form-row"><div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" id="newEmail" placeholder="email@domain.com"></div><div class="form-group"><label class="form-label">Role</label><select class="form-input" id="newRole"><option>student</option><option>facilitator</option><option>moderator</option><option>admin</option></select></div></div><div class="form-group"><label class="form-label">Temporary Password</label><input class="form-input" type="password" id="newPass" placeholder="Min 8 characters"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('createUserModal')">Cancel</button><button class="btn-primary" onclick="createUser()">✓ Create User</button></div></div></div>
<div class="modal-overlay" id="editRoleModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Change Role</div><button class="modal-close" onclick="closeModal('editRoleModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">User: <span id="erUsername"></span></label><select class="form-input" id="erNewRole"><option>student</option><option>facilitator</option><option>moderator</option><option>admin</option></select></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('editRoleModal')">Cancel</button><button class="btn-primary" onclick="changeRole()">Save</button></div></div></div>
<div class="modal-overlay" id="banModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Ban User</div><button class="modal-close" onclick="closeModal('banModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-red">🚫</div><div style="font-size:14px;font-weight:700;margin-bottom:7px">Ban <span id="banUsername"></span>?</div><div class="form-group"><label class="form-label">Reason</label><input class="form-input" id="banReason" placeholder="Reason for ban..."></div><div class="form-group"><label class="form-label">Duration</label><select class="form-input" id="banDuration"><option>1 day</option><option>7 days</option><option>30 days</option><option selected>Permanent</option></select></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('banModal')">Cancel</button><button class="btn-danger" onclick="banUser()">🚫 Ban User</button></div></div></div>
<div class="modal-overlay" id="kickModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Kick User</div><button class="modal-close" onclick="closeModal('kickModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-yellow">⚠</div><div style="font-size:14px;font-weight:700;margin-bottom:7px">Kick <span id="kickUsername"></span>?</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('kickModal')">Cancel</button><button class="btn-danger" onclick="kickUser()">Kick</button></div></div></div>
<div class="modal-overlay" id="muteModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Mute User</div><button class="modal-close" onclick="closeModal('muteModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">User: <span id="muteUsername"></span></label><select class="form-input"><option>15 minutes</option><option>1 hour</option><option>24 hours</option><option>1 week</option></select></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('muteModal')">Cancel</button><button class="btn-primary" onclick="closeModal('muteModal');showToast('User muted!','success','🔇')">Mute</button></div></div></div>
<div class="modal-overlay" id="reportDetailModal"><div class="modal modal-md"><div class="modal-header"><div class="modal-title">Report Detail</div><button class="modal-close" onclick="closeModal('reportDetailModal')">✕</button></div><div class="modal-body"><p style="color:var(--muted);font-size:12.5px">Content is under review. Take action to resolve this report.</p></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('reportDetailModal')">Close</button><button class="btn-danger" onclick="closeModal('reportDetailModal');showToast('Action taken','success','✅')">Take Action</button></div></div></div>
<div class="modal-overlay" id="aiMatchModal"><div class="modal modal-md"><div class="modal-header"><div class="modal-title">AI Match Preview</div><button class="modal-close" onclick="closeModal('aiMatchModal')">✕</button></div><div class="modal-body"><p style="color:var(--muted);font-size:12px;margin-bottom:12px">Compatibility analysis for this match:</p><div style="display:flex;justify-content:center;font-size:42px;font-weight:800;color:var(--green);margin:12px 0">94%</div><p style="text-align:center;font-size:12px;color:var(--muted)">Fatima_Student ↔ John_Doe<br>Common: Computer Science, DSA</p></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('aiMatchModal')">Close</button><button class="btn-primary" onclick="closeModal('aiMatchModal');showToast('Match approved!','success','✅')">Approve Match</button></div></div></div>
<div class="modal-overlay" id="createRoleModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Create Role</div><button class="modal-close" onclick="closeModal('createRoleModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Role Name</label><input class="form-input" placeholder="e.g. Teaching Assistant"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('createRoleModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createRoleModal');showToast('Role created!','success','✅')">Create</button></div></div></div>
<div class="modal-overlay" id="editPermsModal"><div class="modal modal-md"><div class="modal-header"><div class="modal-title">Edit Permissions: <span id="epRole"></span></div><button class="modal-close" onclick="closeModal('editPermsModal')">✕</button></div><div class="modal-body"><div id="epList"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('editPermsModal')">Cancel</button><button class="btn-primary" onclick="closeModal('editPermsModal');showToast('Permissions saved!','success','✅')">Save</button></div></div></div>
<div class="modal-overlay" id="deleteRoleModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Delete Role</div><button class="modal-close" onclick="closeModal('deleteRoleModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-red">⚠</div><div style="font-size:14px;font-weight:700">Delete <span id="drRole"></span> role?</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('deleteRoleModal')">Cancel</button><button class="btn-danger" onclick="closeModal('deleteRoleModal');showToast('Role deleted','success','✅')">Delete</button></div></div></div>
<div class="modal-overlay" id="createTagModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Add Tag</div><button class="modal-close" onclick="closeModal('createTagModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Tag Name</label><input class="form-input" id="newTagInput" placeholder="e.g. Cybersecurity"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('createTagModal')">Cancel</button><button class="btn-primary" onclick="addTag()">Add Tag</button></div></div></div>
<div class="modal-overlay" id="createCourseModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Create Course</div><button class="modal-close" onclick="closeModal('createCourseModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Course Name</label><input class="form-input" placeholder="e.g. BS Data Science"></div><div class="form-group"><label class="form-label">Course Code</label><input class="form-input" placeholder="e.g. BSDS"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('createCourseModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createCourseModal');showToast('Course created!','success','✅')">Create</button></div></div></div>
<div class="modal-overlay" id="aiConfigModal"><div class="modal modal-md"><div class="modal-header"><div class="modal-title">Configure AI Matching Rules</div><button class="modal-close" onclick="closeModal('aiConfigModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Min Compatibility Score</label><input class="form-input" type="range" min="50" max="99" value="75" oninput="document.getElementById('aiMinScore').textContent=this.value+'%'"><span id="aiMinScore" style="font-size:12px;color:var(--muted)">75%</span></div><div class="form-group"><label class="form-label">Max Group Size</label><select class="form-input"><option>2 (Pair)</option><option selected>4-6 (Small Group)</option><option>8-10 (Large Group)</option></select></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('aiConfigModal')">Cancel</button><button class="btn-primary" onclick="closeModal('aiConfigModal');showToast('AI rules saved!','success','✅')">Save Rules</button></div></div></div>
<div class="modal-overlay" id="createServerModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Create Server</div><button class="modal-close" onclick="closeModal('createServerModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Server Name</label><input class="form-input" placeholder="e.g. Engineering Department"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('createServerModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createServerModal');showToast('Server created!','success','✅')">Create</button></div></div></div>
<div class="modal-overlay" id="serverDetailModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title" id="sdmTitle">Server</div><button class="modal-close" onclick="closeModal('serverDetailModal')">✕</button></div><div class="modal-body"><p style="color:var(--muted);font-size:12.5px">Server management options.</p></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('serverDetailModal')">Close</button></div></div></div>
<div class="modal-overlay" id="deleteServerModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Delete Server</div><button class="modal-close" onclick="closeModal('deleteServerModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-red">⚠</div><div style="font-size:14px;font-weight:700">Delete <span id="delSrvName"></span>?</div><div style="color:var(--muted);font-size:12px;margin-top:5px">This action is irreversible.</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('deleteServerModal')">Cancel</button><button class="btn-danger" onclick="closeModal('deleteServerModal');showToast('Server deleted','success','✅')">Delete</button></div></div></div>
<div class="modal-overlay" id="createChannelModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Create Channel</div><button class="modal-close" onclick="closeModal('createChannelModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Channel Name</label><input class="form-input" placeholder="e.g. #resources"></div><div class="form-group"><label class="form-label">Type</label><select class="form-input"><option>Text</option><option>Voice</option><option>Whiteboard</option></select></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('createChannelModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createChannelModal');showToast('Channel created!','success','✅')">Create</button></div></div></div>
<div class="modal-overlay" id="editChannelModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Edit Channel: <span id="ecName"></span></div><button class="modal-close" onclick="closeModal('editChannelModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Channel Name</label><input class="form-input" id="ecInput"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('editChannelModal')">Cancel</button><button class="btn-primary" onclick="closeModal('editChannelModal');showToast('Channel updated!','success','✅')">Save</button></div></div></div>
<div class="modal-overlay" id="deleteChannelModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Delete Channel</div><button class="modal-close" onclick="closeModal('deleteChannelModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-red">⚠</div><div style="font-size:14px;font-weight:700">Delete <span id="delChName"></span>?</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('deleteChannelModal')">Cancel</button><button class="btn-danger" onclick="closeModal('deleteChannelModal');showToast('Channel deleted','success','✅')">Delete</button></div></div></div>
<div class="modal-overlay" id="issueBanModal"><div class="modal modal-md"><div class="modal-header"><div class="modal-title">Issue Moderation Action</div><button class="modal-close" onclick="closeModal('issueBanModal')">✕</button></div><div class="modal-body"><div class="form-row"><div class="form-group"><label class="form-label">Target User</label><input class="form-input" placeholder="Username..."></div><div class="form-group"><label class="form-label">Action Type</label><select class="form-input"><option>Warn</option><option>Mute</option><option>Kick</option><option>Ban</option></select></div></div><div class="form-group"><label class="form-label">Reason</label><textarea class="form-textarea" placeholder="Reason..." style="min-height:60px"></textarea></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('issueBanModal')">Cancel</button><button class="btn-danger" onclick="closeModal('issueBanModal');showToast('Action issued!','success','🔨')">Issue Action</button></div></div></div>
<div class="modal-overlay" id="modActionDetailModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Moderation Detail</div><button class="modal-close" onclick="closeModal('modActionDetailModal')">✕</button></div><div class="modal-body"><p style="color:var(--muted);font-size:12.5px">Full moderation action details.</p></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('modActionDetailModal')">Close</button></div></div></div>
<div class="modal-overlay" id="changePasswordModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Change Password</div><button class="modal-close" onclick="closeModal('changePasswordModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Current Password</label><input class="form-input" type="password"></div><div class="form-group"><label class="form-label">New Password</label><input class="form-input" type="password"></div><div class="form-group"><label class="form-label">Confirm New Password</label><input class="form-input" type="password"></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button><button class="btn-primary" onclick="closeModal('changePasswordModal');showToast('Password updated!','success','🔒')">Save</button></div></div></div>
<div class="modal-overlay" id="studySessionsModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Study Sessions Today</div><button class="modal-close" onclick="closeModal('studySessionsModal')">✕</button></div><div class="modal-body"><div style="text-align:center;font-size:52px;font-weight:800;color:var(--green)"><?= (int)($stats['sessions_today']??47) ?></div><div style="text-align:center;color:var(--muted);font-size:12px">Active sessions today across all servers</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('studySessionsModal')">Close</button></div></div></div>
<div class="modal-overlay" id="messagesTodayModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Messages Today</div><button class="modal-close" onclick="closeModal('messagesTodayModal')">✕</button></div><div class="modal-body"><div style="text-align:center;font-size:52px;font-weight:800;color:#a78bfa"><?= number_format((int)($stats['messages_today']??312)) ?></div><div style="text-align:center;color:var(--muted);font-size:12px">Total messages sent across all channels</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('messagesTodayModal')">Close</button></div></div></div>
<div class="modal-overlay" id="roomDetailModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Room Detail</div><button class="modal-close" onclick="closeModal('roomDetailModal')">✕</button></div><div class="modal-body"><p style="color:var(--muted);font-size:12.5px">Active room details and management options.</p></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('roomDetailModal')">Close</button><button class="btn-primary" onclick="closeModal('roomDetailModal');showToast('Joined room!','success','✅')">Join Room</button></div></div></div>
<div class="modal-overlay" id="joinRoomModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Join Room</div><button class="modal-close" onclick="closeModal('joinRoomModal')">✕</button></div><div class="modal-body"><div class="form-group"><label class="form-label">Room Code</label><input class="form-input" placeholder="Enter room code..."></div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('joinRoomModal')">Cancel</button><button class="btn-primary" onclick="closeModal('joinRoomModal');showToast('Joined!','success','✅')">Join</button></div></div></div>
<div class="modal-overlay" id="clearLogsModal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">Clear Logs</div><button class="modal-close" onclick="closeModal('clearLogsModal')">✕</button></div><div class="modal-body"><div class="modal-icon mi-yellow">⚠</div><div style="font-size:14px;font-weight:700">Clear all error logs?</div></div><div class="modal-footer"><button class="btn-secondary" onclick="closeModal('clearLogsModal')">Cancel</button><button class="btn-danger" onclick="closeModal('clearLogsModal');showToast('Logs cleared','success','✅')">Clear</button></div></div></div>

<div class="toast-container" id="toastContainer"></div>

<script>
var ADMIN_DATA = <?= json_encode([
  'stats'       => $stats,
  'sessData'    => $dashData['sessions_chart'] ?? [42, 38, 55, 47, 61, 52, 47],
  'engData'     => $dashData['engagement_chart'] ?? [320, 280, 410, 390, 440, 360, 312],
  'dauData'     => $dashData['dau_chart'] ?? [820, 760, 890, 930, 870, 950, 892],
  'userId'      => $user['id'],
  'csrfToken'   => $csrfToken,
  'aiAccuracy'  => (float)($stats['ai_accuracy'] ?? 98.7),
], JSON_HEX_TAG) ?>;
// Defaults
if(!ADMIN_DATA.sessData.length) ADMIN_DATA.sessData = [42,38,55,47,61,52,47];
if(!ADMIN_DATA.engData.length)  ADMIN_DATA.engData  = [320,280,410,390,440,360,312];
if(!ADMIN_DATA.dauData.length)  ADMIN_DATA.dauData  = [820,760,890,930,870,950,892];
</script>
<script src="<?= BASE_URL ?>/assets/js/admin/dashboard.js" defer></script>

<script>
// ── Mobile sidebar ─────────────────────────────────────────────────
(function(){
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var header  = document.querySelector('.header, .topbar');

  // Inject hamburger button into header
  if (header && !document.getElementById('mobHamburger')) {
    var ham = document.createElement('button');
    ham.id = 'mobHamburger';
    ham.className = 'mob-hamburger';
    ham.style.cssText = 'display:none;background:none;border:none;cursor:pointer;flex-direction:column;gap:4px;padding:4px;';
    ham.innerHTML = '<span></span><span></span><span></span>';
    ham.onclick = toggleSidebar;
    header.insertBefore(ham, header.firstChild);
  }

  function checkMobile(){
    var isMob = window.innerWidth <= 768;
    var ham = document.getElementById('mobHamburger');
    if (ham) ham.style.display = isMob ? 'flex' : 'none';
  }
  window.addEventListener('resize', checkMobile);
  checkMobile();
})();

function toggleSidebar(){
  var s = document.querySelector('.sidebar');
  var o = document.getElementById('sidebarOverlay');
  if(s) s.classList.toggle('open');
  if(o) o.classList.toggle('open');
}
function closeSidebar(){
  var s = document.querySelector('.sidebar');
  var o = document.getElementById('sidebarOverlay');
  if(s) s.classList.remove('open');
  if(o) o.classList.remove('open');
}
</script>
</body>
</html>
