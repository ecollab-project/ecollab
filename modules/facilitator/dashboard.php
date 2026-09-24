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
RoleMiddleware::requireRole(['facilitator', 'admin', 'super_admin', 'moderator']);

$csrfToken  = AuthMiddleware::csrfToken();
$activePage = $_GET['page'] ?? 'dashboard';

$userService = new UserService();
$dashData    = $userService->getFacilitatorDashboardData($user['id']);

$grad     = $user['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
$avatarUrl = (string)($user['avatar_url'] ?? '');
$avatarStyle = $avatarUrl !== '' ? "background-image:url('" . htmlspecialchars($avatarUrl, ENT_QUOTES) . "');background-size:cover;background-position:center" : "background:linear-gradient(135deg," . htmlspecialchars($grad) . ")";
$parts    = explode(',', $grad . ',#7c3aed');
$c1       = trim($parts[0]);
$c2       = trim($parts[1]);
$initials = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));
$name     = htmlspecialchars($user['full_name'] ?: $user['username']);

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$stats = $dashData['stats'] ?? ['total_members' => 0, 'active_today' => 0, 'messages_today' => 0, 'study_sessions' => 0];

// Load facilitator-managed servers before any page or modal renders.
// Previously this list was initialized halfway through the Dashboard markup,
// so direct navigation to Announcements/Settings could render empty selects.
$facDashboardServers = [];
try {
  require_once ROOT_PATH . '/services/ServerMonitoringService.php';
  $facDashboardServers = (new ServerMonitoringService())->getFacilitatorServers((int)$user['id']);
} catch (Throwable $e) {
  error_log('[FacilitatorDashboard] server list: ' . $e->getMessage());
}
$facOwnedServers = array_values(array_filter(
  $facDashboardServers,
  static fn(array $srv): bool => (int)($srv['owner_id'] ?? 0) === (int)$user['id']
));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Facilitator Dashboard – <?= APP_NAME ?></title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/facilitator-dashboard.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/dashboard-mobile.css">
  <script>
    window.ECOLLAB_BASE = <?= json_encode(BASE_URL) ?>;
  </script>
</head>

<body>

  <?php $activePage = $activePage;
  include ROOT_PATH . '/includes/layout/sidebar-facilitator.php'; ?>


  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
  <div class="main">
    <header class="header">
      <div class="search-wrap">
        <span style="color:var(--muted2);font-size:12px">🔍</span>
        <input type="text" placeholder="Search members, messages, resources..." oninput="handleSearch(this.value)" autocomplete="off">
      </div>
      <div class="hdr-right">
        <div class="notif-btn" id="nBtn" onclick="toggleNotif()">🔔
          <div class="nbadge">2</div>
          <div class="ndrop" id="ndrop">
            <div class="ndhead">
              <div class="ndtitle">Notifications</div>
              <div class="ndclear" onclick="clearNotifs()">Mark all read</div>
            </div>
            <div class="ndi unread" onclick="handleNotif(this,'New report')">
              <div class="ndd"></div>
              <div class="ndico" style="background:rgba(220,38,38,.15)">🚩</div>
              <div>
                <div class="ndmsg">New report: Flagged message in #general</div>
                <div class="ndtime">1 hour ago</div>
              </div>
            </div>
            <div class="ndi unread" onclick="handleNotif(this,'New member')">
              <div class="ndd"></div>
              <div class="ndico" style="background:rgba(22,163,74,.15)">👥</div>
              <div>
                <div class="ndmsg">4 new members joined CS 305 this week</div>
                <div class="ndtime">3 hours ago</div>
              </div>
            </div>
          </div>
        </div>
        <div class="prof-chip" id="pchip" onclick="togglePDrop()">
          <div class="av" style="<?= $avatarStyle ?>"><?= $avatarUrl === '' ? htmlspecialchars($initials) : '' ?></div>
          <div>
            <div class="pn"><?= $name ?></div>
            <div class="pr">Facilitator</div>
          </div>
          <span style="color:var(--muted);font-size:10px;margin-left:2px">▼</span>
          <div class="ddmenu" id="pdrop">
            <div class="dhead">
              <div class="dun"><?= $name ?></div>
              <div class="dur">Facilitator</div>
            </div>
            <div class="ditem" onclick="showPage('chsettings')">⚙️ Channel Settings</div>
            <div class="ditem" onclick="openModal('editProfileModal')">✏️ Edit Profile</div>
            <div class="ditem" onclick="goToChat()">💬 Go to Chat</div>
            <div class="dsep"></div>
            <div class="ditem red" onclick="openModal('logoutModal')">🚪 Sign Out</div>
          </div>
        </div>
      </div>
    </header>

    <div class="content">

      <!-- DASHBOARD PAGE -->
      <div class="page-section active" id="page-dashboard">
        <div class="welcome-bar">
          <div>
            <div class="wb-sub">Welcome back, <?= $name ?> 👑</div>
            <div class="wb-title"><?= htmlspecialchars($greeting) ?>, <?= $name ?></div>
            <div class="ch-tag" onclick="openModal('channelSwitchModal')">
              Channel: <strong><?= htmlspecialchars($dashData['channel']['name'] ?? 'CS 305 – Neural Networks') ?></strong>
              <span class="ch-tag-arr">▾</span>
            </div>
          </div>
          <button class="btn-channel-settings" onclick="showPage('chsettings')">⚙ Channel Settings</button>
        </div>

        <div class="stat-row">
          <div class="stat-card sc1" onclick="showPage('members')">
            <div class="sc-top">
              <div class="sc-label">Total Members</div>
              <div class="sc-icon">👥</div>
            </div>
            <div class="sc-val"><?= (int)($stats['total_members'] ?? 0) ?></div>
            <div class="sc-sub">Current member count</div>
          </div>
          <div class="stat-card sc2" onclick="openModal('activeModal')">
            <div class="sc-top">
              <div class="sc-label">Active Today</div>
              <div class="sc-icon">⚡</div>
            </div>
            <div class="sc-val"><?= (int)($stats['active_today'] ?? 0) ?></div>
            <div class="sc-sub"><?= round((int)($stats['active_today'] ?? 0) / max(1, (int)($stats['total_members'] ?? 0)) * 100, 1) ?>% of members</div>
          </div>
          <div class="stat-card sc3" onclick="openModal('messagesModal')">
            <div class="sc-top">
              <div class="sc-label">Messages Today</div>
              <div class="sc-icon">💬</div>
            </div>
            <div class="sc-val"><?= (int)($stats['messages_today'] ?? 0) ?></div>
            <div class="sc-sub">Recorded today</div>
          </div>
          <div class="stat-card sc4" onclick="showPage('sessions')">
            <div class="sc-top">
              <div class="sc-label">Study Sessions</div>
              <div class="sc-icon">🕐</div>
            </div>
            <div class="sc-val"><?= (int)($stats['study_sessions'] ?? 0) ?></div>
            <div class="sc-sub">Recorded sessions</div>
          </div>
        </div>

        <div class="main-grid">
          <div class="card">
            <div class="ch-bar">
              <div>
                <div class="ch-title">User Activity Overview</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px">
                <button class="period-btn" onclick="openModal('periodModal')">This Week ▾</button>
              </div>
            </div>
            <div class="activity-sub">See how your members are participating in the channel.</div>
            <div style="overflow-x:auto">
              <table class="act-table">
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Messages</th>
                    <th>Sessions</th>
                    <th>WB Edits</th>
                    <th>Files</th>
                    <th>Last Active</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="activityTableBody">
                  <?php foreach ($dashData['activity'] ?? [] as $a):
                    $aInit = strtoupper(substr($a['full_name'] ?? $a['username'] ?? '?', 0, 1));
                    $aGrad = $a['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
                    $statusClass = match ($a['activity_status'] ?? 'active') {
                      'very_active' => 'sp-va',
                      'active' => 'sp-a',
                      'moderate' => 'sp-m',
                      default => 'sp-i'
                    };
                    $statusLabel = match ($a['activity_status'] ?? 'active') {
                      'very_active' => 'Very Active',
                      'active' => 'Active',
                      'moderate' => 'Moderate',
                      default => 'Inactive'
                    };
                  ?>
                    <tr onclick="openModal('memberDetailModal','<?= htmlspecialchars($a['username'] ?? '') ?>')">
                      <td>
                        <div class="user-cell">
                          <div class="u-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($aGrad) ?>)"><?= htmlspecialchars($aInit) ?></div>
                          <div>
                            <div class="u-name"><?= htmlspecialchars($a['full_name'] ?? $a['username'] ?? '') ?></div>
                            <div class="u-handle">@<?= htmlspecialchars($a['username'] ?? '') ?></div>
                          </div>
                        </div>
                      </td>
                      <td><?= (int)($a['messages'] ?? 0) ?></td>
                      <td><?= (int)($a['sessions'] ?? 0) ?></td>
                      <td><?= (int)($a['wb_edits'] ?? 0) ?></td>
                      <td><?= (int)($a['files_uploaded'] ?? 0) ?></td>
                      <td style="color:var(--muted2)"><?= htmlspecialchars($a['last_active'] ?? '—') ?></td>
                      <td><span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr><td colspan="7" class="dashboard-empty-state">No member activity recorded yet.</td></tr>
                </tbody>
              </table>
            </div>
            <div class="view-all-row" onclick="showPage('useractivity')">View Full Activity Report →</div>
          </div>

          <!-- RIGHT: ENGAGEMENT CHART + RECENT ACTIVITY -->
          <div>
            <div class="card" style="margin-bottom:12px">
              <div class="ch-bar">
                <div class="ch-title">Engagement Trend</div><button class="period-btn" onclick="openModal('periodModal')">Week ▾</button>
              </div>
              <div style="height:180px;padding:10px 12px"><canvas id="engChart"></canvas></div>
            </div>

            <!-- MY SERVERS -->
            <?php $membership = $dashData['membership'] ?? []; ?>
            <div class="card" style="margin-bottom:12px">
              <div class="ch-bar">
                <div class="ch-title">My Servers</div>
                <a class="period-btn" style="text-decoration:none" href="?page=servermonitoring">View All</a>
              </div>
              <div style="padding:4px 12px 10px;font-size:11px;color:var(--muted2)"><?= count($facDashboardServers) ?> server<?= count($facDashboardServers) === 1 ? '' : 's' ?> you own, facilitate, or manage</div>
              <?php foreach (array_slice($facDashboardServers, 0, 5) as $srv): ?>
                <div class="ract-row">
                  <div class="ract-av" style="background:rgba(124,92,255,.15);font-size:16px"><?= htmlspecialchars($srv['icon_emoji'] ?? '🖥') ?></div>
                  <div class="ract-msg" style="flex:1"><strong><?= htmlspecialchars($srv['name'] ?? '') ?></strong> <span class="visibility-badge <?= (($srv['type'] ?? '') === 'private' || ($srv['type'] ?? '') === 'academic') ? 'visibility-private' : 'visibility-public' ?>"><?= (($srv['type'] ?? '') === 'private' || ($srv['type'] ?? '') === 'academic') ? '🔒 Private' : '🌐 Public' ?></span><span style="color:var(--muted2)"> · <?= (int)($srv['member_count'] ?? 0) ?> members</span></div>
                  <a class="btn-sm btn-outline" style="text-decoration:none" href="<?= BASE_URL ?>/modules/admin/server-monitor.php?server_id=<?= (int)($srv['id'] ?? 0) ?>&scope=facilitator">Manage</a>
                </div>
              <?php endforeach; ?>
              <?php if (empty($facDashboardServers)): ?><div class="dashboard-empty-state">No servers are assigned to your facilitator account.</div><?php endif; ?>
            </div>

            <div class="card">
              <div class="ch-bar">
                <div class="ch-title">Recent Activity</div><button class="period-btn" onclick="showPage('chlogs')">View All</button>
              </div>

              <?php foreach (array_slice($dashData['recent_activity'] ?? [], 0, 4) as $act):
                $rInit = strtoupper(substr($act['username'] ?? '?', 0, 1));
                $rGrad = $act['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
              ?>
                <div class="ract-row">
                  <div class="ract-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($rGrad) ?>)"><?= htmlspecialchars($rInit) ?></div>
                  <div class="ract-msg" style="flex:1"><?= htmlspecialchars($act['message'] ?? '') ?></div>
                  <div class="ract-time"><?= htmlspecialchars($act['time_ago'] ?? '') ?></div>
                </div>
              <?php endforeach; ?>
              <div class="dashboard-empty-state">No recent activity recorded yet.</div>
            </div>
          </div>
        </div>

        <!-- BOTTOM: UPCOMING SESSIONS + PENDING REPORTS -->
        <div class="main-grid">
          <div class="card">
            <div class="ch-bar">
              <div class="ch-title">Upcoming Sessions</div><button class="btn-primary" style="font-size:10.5px;padding:5px 10px" onclick="openModal('startSessionModal')">+ Schedule</button>
            </div>
            <?php foreach (array_slice($dashData['upcoming_sessions'] ?? [], 0, 3) as $sess):
              $sd = !empty($sess['start_time']) ? date('d', strtotime($sess['start_time'])) : '24';
              $sm = !empty($sess['start_time']) ? strtoupper(date('M', strtotime($sess['start_time']))) : 'MAY';
              $st = !empty($sess['start_time']) ? date('g:i A', strtotime($sess['start_time'])) : '3:00 PM';
            ?>
              <div class="ract-row">
                <div style="text-align:center;width:36px;flex-shrink:0">
                  <div style="font-size:16px;font-weight:800;color:var(--pink)"><?= $sd ?></div>
                  <div style="font-size:9px;text-transform:uppercase;color:var(--muted2)"><?= $sm ?></div>
                </div>
                <div style="flex:1;margin-left:10px">
                  <div style="font-size:12.5px;font-weight:700"><?= htmlspecialchars($sess['name'] ?? '') ?></div>
                  <div style="font-size:10.5px;color:var(--muted2)"><?= $st ?> · <?= (int)($sess['rsvp_count'] ?? 0) ?> RSVPs</div>
                </div>
                <button class="btn-sm btn-outline" onclick="openModal('sessionDetailModal','<?= htmlspecialchars($sess['name'] ?? '') ?>')">View</button>
              </div>
            <?php endforeach; ?>
            <?php if (empty($dashData['upcoming_sessions'])): ?>
              <div class="ract-row">
                <div style="text-align:center;width:36px;flex-shrink:0">
                  <div style="font-size:16px;font-weight:800;color:var(--pink)">24</div>
                  <div style="font-size:9px;text-transform:uppercase;color:var(--muted2)">MAY</div>
                </div>
                <div style="flex:1;margin-left:10px">
                  <div style="font-size:12.5px;font-weight:700">Backpropagation Study Group</div>
                  <div style="font-size:10.5px;color:var(--muted2)">3:00 PM · 8 RSVPs</div>
                </div><button class="btn-sm btn-outline" onclick="openModal('sessionDetailModal','Backpropagation Study Group')">View</button>
              </div>
              <div class="ract-row">
                <div style="text-align:center;width:36px;flex-shrink:0">
                  <div style="font-size:16px;font-weight:800;color:var(--pink)">26</div>
                  <div style="font-size:9px;text-transform:uppercase;color:var(--muted2)">MAY</div>
                </div>
                <div style="flex:1;margin-left:10px">
                  <div style="font-size:12.5px;font-weight:700">Chapter 5 Q&A</div>
                  <div style="font-size:10.5px;color:var(--muted2)">4:00 PM · 14 RSVPs</div>
                </div><button class="btn-sm btn-outline" onclick="openModal('sessionDetailModal','Chapter 5 QA')">View</button>
              </div>
            <?php endif; ?>
          </div>

          <div class="card">
            <div class="ch-bar">
              <div class="ch-title">Pending Reports</div><span style="background:rgba(220,38,38,.15);color:var(--red);padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700">2 pending</span>
            </div>
            <div class="report-item">
              <div class="ri-header">
                <div class="ri-ico" style="background:rgba(220,38,38,.15)">🚩</div>
                <div class="ri-title">Flagged Message: "Inappropriate..."</div>
              </div>
              <div class="ri-meta">Reported by Mia_Wong · #general · 1h ago</div>
              <div class="ri-actions"><button class="btn-sm" style="background:rgba(22,163,74,.15);color:var(--green);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="resolveReport(this,'approved')">Approve</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="resolveReport(this,'dismissed')">Dismiss</button></div>
            </div>
            <div class="report-item">
              <div class="ri-header">
                <div class="ri-ico" style="background:rgba(217,119,6,.15)">⚠</div>
                <div class="ri-title">Reported User: spam_user99</div>
              </div>
              <div class="ri-meta">Reported by Alex_Chen · 3h ago</div>
              <div class="ri-actions"><button class="btn-sm" style="background:rgba(220,38,38,.15);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="openModal('kickModal','spam_user99')">Kick</button><button class="btn-sm btn-outline" onclick="openModal('reportDetailModal','Reported User')">Detail</button></div>
            </div>
          </div>
        </div>
      </div>

      <?php
$facMonitorServers = [];
try {
  require_once ROOT_PATH . '/services/ServerMonitoringService.php';
  $facMonitorServers = (new ServerMonitoringService())->getFacilitatorServers((int)$user['id']);
} catch (Throwable $e) {
  $facMonitorServers = [];
}
?>
      <div class="page-section" id="page-servermonitoring">
        <div class="page-title-row">
          <div>
            <div class="page-title">My Servers</div>
            <div class="page-sub">Manage the servers you facilitate, own, or administer.</div>
          </div>
        </div>
        <div class="card">
          <div class="ch-bar"><div class="ch-title">My Servers</div></div>
          <?php foreach ($facMonitorServers as $srv): ?>
            <div class="ract-row">
              <div class="ract-av" style="background:rgba(124,92,255,.15);font-size:16px"><?= htmlspecialchars($srv['icon_emoji'] ?? '🖥') ?></div>
              <div class="ract-msg" style="flex:1">
                <strong><?= htmlspecialchars($srv['name'] ?? '') ?></strong>
                <span style="color:var(--muted2)"> · <?= (int)($srv['member_count'] ?? 0) ?> members</span>
              </div>
              <a class="btn-sm btn-outline" style="text-decoration:none" href="<?= BASE_URL ?>/modules/admin/server-monitor.php?server_id=<?= (int)($srv['id'] ?? 0) ?>&scope=facilitator">Manage</a>
            </div>
          <?php endforeach; ?>
          <?php if (empty($facMonitorServers)): ?>
            <div class="dashboard-empty-state">No facilitator-managed servers are assigned to your account.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ALL OTHER PAGES (extracted verbatim from HTML) -->
      <div class="page-section" id="page-mychannel">
        <div class="page-title-row">
          <div>
            <div class="page-title">My Channel</div>
            <div class="page-sub"><?= htmlspecialchars($dashData['channel']['name'] ?? 'CS 305 – Neural Networks') ?> overview.</div>
          </div><button class="btn-primary" onclick="openModal('editChannelModal')">✏️ Edit Channel</button>
        </div>
        <div class="g2">
          <div class="card">
            <div class="ch-bar">
              <div class="ct">Channel Info</div>
            </div>
            <div style="padding:14px">
              <div class="info-grid">
                <div class="ig-item">
                  <div class="ig-label">Channel Name</div>
                  <div class="ig-val"><?= htmlspecialchars($dashData['channel']['name'] ?? 'CS 305 – Neural Networks') ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Total Members</div>
                  <div class="ig-val"><?= (int)($stats['total_members'] ?? 72) ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Created</div>
                  <div class="ig-val"><?= htmlspecialchars($dashData['channel']['created_at'] ?? 'Jan 15, 2025') ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Status</div>
                  <div class="ig-val" style="color:var(--green)">● Active</div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Facilitator</div>
                  <div class="ig-val"><?= $name ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Server</div>
                  <div class="ig-val"><?= htmlspecialchars($dashData['channel']['server_name'] ?? 'CS Department') ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="ch-bar">
              <div class="ct">Quick Stats</div>
            </div>
            <div style="padding:14px">
              <div class="info-grid">
                <div class="ig-item">
                  <div class="ig-label">Total Messages</div>
                  <div class="ig-val"><?= number_format((int)($dashData['channel']['message_count'] ?? 4832)) ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Study Sessions</div>
                  <div class="ig-val"><?= (int)($stats['study_sessions'] ?? 48) ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Files Shared</div>
                  <div class="ig-val"><?= (int)($dashData['channel']['file_count'] ?? 127) ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Announcements</div>
                  <div class="ig-val">24</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="page-section" id="page-members">
        <div class="page-title-row">
          <div>
            <div class="page-title">Members</div>
            <div class="page-sub"><?= (int)($stats['total_members'] ?? 72) ?> members in <?= htmlspecialchars($dashData['channel']['name'] ?? 'CS 305') ?></div>
          </div>
          <div style="display:flex;gap:7px"><button class="btn-sec" onclick="openModal('inviteMemberModal')">+ Invite Member</button><button class="btn-primary" onclick="openModal('exportModal')">⬇ Export</button></div>
        </div>
        <div class="card">
          <div class="ch-bar">
            <div class="ch-title">All Members</div>
            <div style="display:flex;gap:7px"><input class="fi" style="width:200px;height:30px;padding:0 10px;font-size:11.5px" placeholder="Search members..." oninput="filterMembers(this.value)"><select class="fi" style="width:130px;height:30px;padding:0 8px;font-size:11.5px" onchange="filterMemberStatus(this.value)">
                <option>All Status</option>
                <option>Very Active</option>
                <option>Active</option>
                <option>Moderate</option>
                <option>Inactive</option>
              </select></div>
          </div>
          <div style="overflow-x:auto">
            <table class="mb-table">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>Role</th>
                  <th>Messages</th>
                  <th>Sessions</th>
                  <th>Joined</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="membersTableBody"><?php foreach ($dashData['activity'] ?? [] as $m): $mInit = strtoupper(substr($m['full_name'] ?? $m['username'] ?? '?', 0, 1));
                                              $mGrad = $m['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
                                              $sc = match ($m['activity_status'] ?? 'active') {
                                                'very_active' => 'sp-va',
                                                'active' => 'sp-a',
                                                'moderate' => 'sp-m',
                                                default => 'sp-i'
                                              };
                                              $sl = match ($m['activity_status'] ?? 'active') {
                                                'very_active' => 'Very Active',
                                                'active' => 'Active',
                                                'moderate' => 'Moderate',
                                                default => 'Inactive'
                                              }; ?><tr onclick="openModal('memberDetailModal','<?= htmlspecialchars($m['username'] ?? '') ?>','<?= htmlspecialchars($m['full_name'] ?? '') ?>','<?= htmlspecialchars($mGrad) ?>')">
                    <td>
                      <div class="user-cell">
                        <div class="u-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($mGrad) ?>"><?= htmlspecialchars($mInit) ?></div>
                        <div>
                          <div class="u-name"><?= htmlspecialchars($m['full_name'] ?? $m['username'] ?? '') ?></div>
                          <div class="u-handle">@<?= htmlspecialchars($m['username'] ?? '') ?></div>
                        </div>
                      </div>
                    </td>
                    <td><span class="status-pill sp-a">Student</span></td>
                    <td><?= (int)($m['messages'] ?? 0) ?></td>
                    <td><?= (int)($m['sessions'] ?? 0) ?></td>
                    <td style="color:var(--muted2)"><?= htmlspecialchars($m['joined_date'] ?? '—') ?></td>
                    <td><span class="status-pill <?= $sc ?>"><?= $sl ?></span></td>
                    <td>
                      <div style="display:flex;gap:5px"><button class="btn-sm btn-outline" onclick="event.stopPropagation();openModal('memberDetailModal','<?= htmlspecialchars($m['username'] ?? '') ?>')">View</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="event.stopPropagation();openModal('kickModal','<?= htmlspecialchars($m['username'] ?? '') ?>')">Kick</button></div>
                    </td>
                  </tr><?php endforeach; ?><?php if (empty($dashData['activity'])): ?><tr onclick="openModal('memberDetailModal','Fatima_Student')">
                    <td>
                      <div class="user-cell">
                        <div class="u-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed)">F</div>
                        <div>
                          <div class="u-name">Fatima_Student</div>
                          <div class="u-handle">@fatima.student</div>
                        </div>
                      </div>
                    </td>
                    <td><span class="status-pill sp-a">Student</span></td>
                    <td>142</td>
                    <td>8</td>
                    <td style="color:var(--muted2)">Jan 15</td>
                    <td><span class="status-pill sp-va">Very Active</span></td>
                    <td>
                      <div style="display:flex;gap:5px"><button class="btn-sm btn-outline" onclick="event.stopPropagation()">View</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="event.stopPropagation()">Kick</button></div>
                    </td>
                  </tr><?php endif; ?></tbody>
            </table>
          </div>
          <div class="view-all-row" onclick="toast('Loading...','info','👥')">Load More ↓</div>
        </div>
      </div>
      <div class="page-section" id="page-useractivity">
        <div class="page-title-row">
          <div>
            <div class="page-title">User Activity</div>
          </div><button class="btn-sec" onclick="openModal('exportModal')">⬇ Export</button>
        </div>
        <div class="card">
          <div class="ch-bar">
            <div class="ch-title">Activity Table</div><button class="period-btn" onclick="openModal('periodModal')">This Week ▾</button>
          </div>
          <div style="overflow-x:auto">
            <table class="act-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Messages</th>
                  <th>Sessions</th>
                  <th>WB Edits</th>
                  <th>Files</th>
                  <th>Last Active</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody><?php foreach ($dashData['activity'] ?? [] as $a): $aI = strtoupper(substr($a['full_name'] ?? $a['username'] ?? '?', 0, 1));
                        $aG = $a['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
                        $sc = match ($a['activity_status'] ?? 'active') {
                          'very_active' => 'sp-va',
                          'active' => 'sp-a',
                          'moderate' => 'sp-m',
                          default => 'sp-i'
                        };
                        $sl = match ($a['activity_status'] ?? 'active') {
                          'very_active' => 'Very Active',
                          'active' => 'Active',
                          'moderate' => 'Moderate',
                          default => 'Inactive'
                        }; ?><tr onclick="openModal('memberDetailModal','<?= htmlspecialchars($a['username'] ?? '') ?>')">
                    <td>
                      <div class="user-cell">
                        <div class="u-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($aG) ?>"><?= htmlspecialchars($aI) ?></div>
                        <div>
                          <div class="u-name"><?= htmlspecialchars($a['full_name'] ?? $a['username'] ?? '') ?></div>
                          <div class="u-handle">@<?= htmlspecialchars($a['username'] ?? '') ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= (int)($a['messages'] ?? 0) ?></td>
                    <td><?= (int)($a['sessions'] ?? 0) ?> <span class="delta neu">-</span></td>
                    <td><?= (int)($a['wb_edits'] ?? 0) ?></td>
                    <td><?= (int)($a['files_uploaded'] ?? 0) ?></td>
                    <td style="color:var(--muted2)"><?= htmlspecialchars($a['last_active'] ?? '—') ?></td>
                    <td><span class="status-pill <?= $sc ?>"><?= $sl ?></span></td>
                  </tr><?php endforeach; ?><?php if (empty($dashData['activity'])): ?><tr>
                    <td>
                      <div class="user-cell">
                        <div class="u-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed)">F</div>
                        <div>
                          <div class="u-name">Fatima_Student</div>
                          <div class="u-handle">@fatima</div>
                        </div>
                      </div>
                    </td>
                    <td>42 <span class="delta pos">+12%</span></td>
                    <td>3 <span class="delta pos">+1</span></td>
                    <td>8</td>
                    <td>4</td>
                    <td style="color:var(--muted2)">10m ago</td>
                    <td><span class="status-pill sp-va">Very Active</span></td>
                  </tr><?php endif; ?></tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="page-section" id="page-announcements">
        <div class="page-title-row"><div><div class="page-title">Announcements</div><div class="page-sub">Announcements are posted to the selected server's view-only #announcements channel.</div></div><button class="btn-primary" onclick="openModal('createAnnModal')">📢 Create Announcement</button></div>
        <div class="card" id="annList"><div class="dashboard-empty-state">Choose a server to view its published announcements.</div></div>
      </div>
      <div class="page-section" id="page-reports">
        <div class="page-title-row"><div><div class="page-title">Reports</div><div class="page-sub">Reports from chat in servers you own.</div></div></div>
        <div class="card" id="facReportsList"><div class="dashboard-empty-state">Choose a server to view reports.</div></div>
      </div>
      <div class="page-section" id="page-modqueue">
        <div class="page-title-row">
          <div>
            <div class="page-title">Moderation Queue</div>
          </div>
        </div>
        <div class="card">
          <div class="ch-bar">
            <div class="ch-title">Queue</div><span style="background:rgba(217,119,6,.15);color:var(--yellow);padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700">3 items</span>
          </div>
          <div class="report-item">
            <div class="ri-header">
              <div class="ri-ico" style="background:rgba(6,182,212,.15)">💬</div>
              <div class="ri-title">Message contains link — needs review</div>
            </div>
            <div class="ri-meta">by John_Doe · #resources · 30m ago</div>
            <div class="ri-actions"><button class="btn-sm" style="background:rgba(22,163,74,.15);color:var(--green);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="resolveReport(this,'allowed')">Allow</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="resolveReport(this,'removed')">Remove</button></div>
          </div>
          <div class="report-item">
            <div class="ri-header">
              <div class="ri-ico" style="background:rgba(220,38,38,.15)">🚫</div>
              <div class="ri-title">Profanity filter triggered — #general</div>
            </div>
            <div class="ri-meta">Auto-flagged · 2h ago</div>
            <div class="ri-actions"><button class="btn-sm" style="background:rgba(22,163,74,.15);color:var(--green);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="resolveReport(this,'allowed')">Allow</button><button class="btn-sm" style="background:rgba(220,38,38,.1);color:var(--red);border:none;cursor:pointer;border-radius:6px;padding:4px 9px;font-size:10.5px" onclick="resolveReport(this,'removed')">Remove</button></div>
          </div>
        </div>
      </div>
      <div class="page-section" id="page-banned">
        <div class="page-title-row"><div><div class="page-title">Banned Users</div></div></div>
        <div class="card" id="facBannedList"><div class="dashboard-empty-state">Choose a server to view banned users.</div></div>
      </div>
      <div class="page-section" id="page-chlogs">
        <div class="page-title-row">
          <div>
            <div class="page-title">Channel Logs</div>
          </div><button class="btn-sec" onclick="openModal('exportModal')">⬇ Export</button>
        </div>
        <div class="card" id="logsList">
          <div class="ch-bar">
            <div class="ch-title">Activity Log</div>
          </div><?php foreach (array_slice($dashData['recent_activity'] ?? [], 0, 8) as $act): $rI = strtoupper(substr($act['username'] ?? '?', 0, 1));
                  $rG = $act['avatar_color_gradient'] ?? '#e91e8c,#7c3aed'; ?><div class="ract-row">
              <div class="ract-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($rG) ?>"><?= htmlspecialchars($rI) ?></div>
              <div class="ract-msg" style="flex:1"><?= htmlspecialchars($act['message'] ?? '') ?></div>
              <div class="ract-time"><?= htmlspecialchars($act['time_ago'] ?? '') ?></div>
            </div><?php endforeach; ?><?php if (empty($dashData['recent_activity'])): ?><div class="ract-row">
              <div class="ract-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed)">F</div>
              <div class="ract-msg" style="flex:1">Fatima_Student posted in <span class="ract-link">#general</span></div>
              <div class="ract-time">10m ago</div>
            </div>
            <div class="ract-row">
              <div class="ract-av" style="background:linear-gradient(135deg,#16a34a,#0d9488)">A</div>
              <div class="ract-msg" style="flex:1">Alex Chen edited whiteboard</div>
              <div class="ract-time">25m ago</div>
            </div>
            <div class="ract-row">
              <div class="ract-av" style="background:linear-gradient(135deg,#2563eb,#06b6d4)">J</div>
              <div class="ract-msg" style="flex:1">John_Doe uploaded <span class="ract-link">lecture_notes_ch5.pdf</span></div>
              <div class="ract-time">2h ago</div>
            </div><?php endif; ?><div class="view-all-row" onclick="toast('Loading more logs...','info','📋')">Load More ↓</div>
        </div>
      </div>
      <div class="page-section" id="page-sessions">
        <div class="page-title-row"><div><div class="page-title">Study Sessions</div></div><button class="btn-primary" onclick="openModal('startSessionModal')">🎓 Schedule Session</button></div>
        <div class="card" id="studySessionsList"><div class="dashboard-empty-state">No study sessions to display.</div></div>
      </div>
      <div class="page-section" id="page-engagement">
        <div class="page-title-row">
          <div>
            <div class="page-title">Engagement</div>
          </div>
        </div>
        <div class="g2" style="align-items:start">
          <div class="card">
            <div class="ch-bar">
              <div class="ch-title">Engagement Trend</div><button class="period-btn" onclick="openModal('periodModal')">This Week ▾</button>
            </div>
            <div style="height:180px;padding:10px 12px"><canvas id="engChart2"></canvas></div>
          </div>
          <div class="card">
            <div class="ch-bar">
              <div class="ct">Engagement Stats</div>
            </div>
            <div style="padding:14px">
              <div class="info-grid">
                <div class="ig-item">
                  <div class="ig-label">Engagement Rate</div>
                  <div class="ig-val" style="color:var(--green)"><?= round((int)($stats['active_today'] ?? 38) / max(1, (int)($stats['total_members'] ?? 72)) * 100, 1) ?>%</div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Avg Messages/Day</div>
                  <div class="ig-val"><?= round((int)($stats['messages_today'] ?? 0) / 1, 1) ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Active Members</div>
                  <div class="ig-val"><?= (int)($stats['active_today'] ?? 38) ?></div>
                </div>
                <div class="ig-item">
                  <div class="ig-label">Retention Rate</div>
                  <div class="ig-val" style="color:var(--muted2)">N/A</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="page-section" id="page-leaderboards">
        <div class="page-title-row">
          <div>
            <div class="page-title">Leaderboards</div>
          </div>
        </div>
        <div class="card">
          <div class="ch-bar">
            <div class="ch-title">Top Contributors This Month</div>
          </div><?php $ranks = ['🥇', '🥈', '🥉'];
                foreach (array_slice($dashData['activity'] ?? [], 0, 5) as $ri => $m): $mI = strtoupper(substr($m['full_name'] ?? $m['username'] ?? '?', 0, 1));
                  $mG = $m['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
                  $rnk = $ri < 3 ? $ranks[$ri] : '<span style="color:var(--muted2)">' . ($ri + 1) . '</span>'; ?><div class="contrib-row" onclick="openModal('memberDetailModal','<?= htmlspecialchars($m['username'] ?? '') ?>')">
              <div class="contrib-rank"><?= $rnk ?></div>
              <div class="contrib-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($mG) ?>"><?= htmlspecialchars($mI) ?></div>
              <div class="contrib-body">
                <div class="contrib-name"><?= htmlspecialchars($m['full_name'] ?? $m['username'] ?? '') ?></div>
                <div class="contrib-meta">Messages: <?= (int)($m['messages'] ?? 0) ?> · Sessions: <?= (int)($m['sessions'] ?? 0) ?></div>
              </div>
              <div class="contrib-pts"><?= max(10, (int)($m['messages'] ?? 0) + (int)($m['sessions'] ?? 0) * 15) ?> <span class="pts-label">pts</span></div>
            </div><?php endforeach; ?><?php if (empty($dashData['activity'])): ?><div class="contrib-row">
              <div class="contrib-rank r1">🥇</div>
              <div class="contrib-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed)">F</div>
              <div class="contrib-body">
                <div class="contrib-name">Fatima_Student</div>
                <div class="contrib-meta">Messages: 142 · Sessions: 8</div>
              </div>
              <div class="contrib-pts">245 <span class="pts-label">pts</span></div>
            </div>
            <div class="contrib-row">
              <div class="contrib-rank r2">🥈</div>
              <div class="contrib-av" style="background:linear-gradient(135deg,#2563eb,#06b6d4)">J</div>
              <div class="contrib-body">
                <div class="contrib-name">John_Doe</div>
                <div class="contrib-meta">Messages: 98 · Sessions: 6</div>
              </div>
              <div class="contrib-pts">176 <span class="pts-label">pts</span></div>
            </div><?php endif; ?>
        </div>
      </div>
      <div class="page-section" id="page-resources">
        <div class="page-title-row"><div><div class="page-title">Resources</div><div class="page-sub">Files sent inside servers you own.</div></div></div>
        <div class="card" id="resourcesList"><div class="dashboard-empty-state">Choose a server to view its files.</div></div>
      </div>
      <div class="page-section" id="page-roles">
        <div class="page-title-row"><div><div class="page-title">Roles & Permissions</div><div class="page-sub">Change permissions for servers you own. Roles cannot be created here.</div></div><button class="btn-primary" onclick="saveFacPermissions()">💾 Save Permissions</button></div>
        <div class="card"><div style="padding:14px">
          <div class="fg"><label class="fl">Server</label><select class="fi" id="permServer" onchange="loadFacPermissions(this.value)"><option value="">Choose a server...</option><?php foreach($facOwnedServers as $srv): ?><option value="<?= (int)$srv['id'] ?>"><?= htmlspecialchars($srv['name']) ?></option><?php endforeach; ?></select></div>
          <div class="perm-setting"><span>Members can invite users</span><div class="toggle on" id="permInvites" onclick="this.classList.toggle('on')"></div></div>
          <div class="perm-setting"><span>Members can send messages</span><div class="toggle on" id="permMessages" onclick="this.classList.toggle('on')"></div></div>
          <div class="perm-setting"><span>Members can join voice channels</span><div class="toggle on" id="permVoice" onclick="this.classList.toggle('on')"></div></div>
          <div class="perm-setting"><span>Members can create/respond to polls</span><div class="toggle on" id="permPolls" onclick="this.classList.toggle('on')"></div></div>
          <div class="perm-setting"><span>Members can upload files</span><div class="toggle on" id="permFiles" onclick="this.classList.toggle('on')"></div></div>
        </div></div>
      </div>
      <div class="page-section" id="page-files">
        <div class="page-title-row"><div><div class="page-title">Files & Links</div><div class="page-sub">Public Coworkspace documents and whiteboards are listed here in view-only mode.</div></div></div>
        <div class="card" id="filesList"><div class="dashboard-empty-state">Choose a server to view public Coworkspace resources.</div></div>
      </div>
      <div class="page-section" id="page-messages">
        <div class="page-title-row">
          <div>
            <div class="page-title">Messages</div>
          </div>
          <div style="display:flex;gap:7px"><button class="btn-primary" onclick="goToChat()">💬 Open Full Chat</button></div>
        </div>
        <div class="card">
          <div class="ch-bar">
            <div class="ch-title">Recent Messages — #general</div>
          </div>
          <div id="msgFeed" style="height:280px;overflow-y:auto;padding:10px 14px;display:flex;flex-direction:column;gap:9px">
            <div style="display:flex;gap:8px">
              <div class="ract-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed)">F</div>
              <div>
                <div style="font-size:10px;font-weight:700;color:var(--muted2);margin-bottom:2px">Fatima_Student · 10m ago</div>
                <div style="background:rgba(255,255,255,.05);border-radius:0 9px 9px 9px;padding:8px 11px;font-size:12px;line-height:1.5">Has anyone reviewed Chapter 5 slides yet?</div>
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <div class="ract-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>;font-size:9px"><?= $avatarUrl === '' ? htmlspecialchars($initials) : '' ?></div>
              <div>
                <div style="font-size:10px;font-weight:700;color:var(--pink);margin-bottom:2px"><?= $name ?> (You) · 5m ago</div>
                <div style="background:rgba(233,30,140,.1);border-radius:0 9px 9px 9px;padding:8px 11px;font-size:12px;line-height:1.5">Great initiative! Office hours are Wed 3-5 PM 📌</div>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:7px;padding:0 13px 13px"><input class="fi" id="msgInput" style="height:35px" placeholder="Type a message..." onkeydown="if(event.key==='Enter')sendFacMsg()"><button class="btn-primary" onclick="sendFacMsg()">Send →</button></div>
        </div>
      </div>
      <div class="page-section" id="page-whiteboard">
        <div class="page-title-row">
          <div>
            <div class="page-title">Whiteboard</div>
          </div>
          <div style="display:flex;gap:7px"><button class="btn-sec" onclick="clearWB()">🗑 Clear</button><button class="btn-primary" onclick="toast('Saved!','success','💾')">💾 Save</button></div>
        </div>
        <div class="card">
          <div style="padding:13px">
            <div class="wb-tools"><button class="wbt active" id="wbPen" onclick="setTool('pen',this)">✏️ Pen</button><button class="wbt" id="wbEraser" onclick="setTool('eraser',this)">🧹 Eraser</button><button class="wbt" id="wbRect" onclick="setTool('rect',this)">⬜ Rect</button><label style="display:flex;align-items:center;gap:5px;font-size:10.5px;color:var(--muted2)">Color:<input type="color" id="wbColor" value="#e91e8c"></label><label style="display:flex;align-items:center;gap:5px;font-size:10.5px;color:var(--muted2)">Size:<input type="range" id="wbSize" min="1" max="20" value="3" style="width:70px"></label></div><canvas class="wb-canvas" id="wbCanvas" height="420"></canvas>
          </div>
        </div>
      </div>
      <div class="page-section" id="page-polls">
        <div class="page-title-row">
          <div><div class="page-title">Polls</div></div>
        </div>
        <div class="card">
          <div class="ch-bar"><div class="ch-title">Active Polls</div></div>
          <div id="facPollsList" class="dashboard-empty-state">Select one of your servers to view polls created in its chat channels.</div>
        </div>
      </div>
      <div class="page-section" id="page-chsettings">
        <div class="page-title-row">
          <div>
            <div class="page-title">Server Settings</div>
          </div><button class="btn-primary" onclick="saveChannelSettings()">💾 Save Changes</button>
        </div>
        <div class="card" style="margin-bottom:12px;padding:14px"><div class="fg" style="margin:0"><label class="fl">Server to manage</label><select class="fi" id="settingsServer"><option value="">Choose a server...</option><?php foreach($facOwnedServers as $srv): ?><option value="<?= (int)$srv['id'] ?>"><?= htmlspecialchars($srv['name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="g2">
          <div class="card">
            <div class="ch-bar">
              <div class="ct">General</div>
            </div>
            <div style="padding:14px">
              <div class="fg"><label class="fl">Channel Name</label><input class="fi" value="<?= htmlspecialchars($dashData['channel']['name'] ?? 'CS 305 – Neural Networks') ?>"></div>
              <div class="fg"><label class="fl">Description</label><textarea class="fta" style="min-height:55px"><?= htmlspecialchars($dashData['channel']['description'] ?? 'A channel for CS 305 Neural Networks course discussions.') ?></textarea></div>
            </div>
          </div>
          <div class="card">
            <div class="ch-bar">
              <div class="ct">Privacy & Access</div>
            </div>
            <div style="padding:14px">
              <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border2)">
                <div>
                  <div style="font-size:12px;font-weight:600">Public Channel</div>
                </div>
                <div class="toggle" onclick="this.classList.toggle('on')"></div>
              </div>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border2)">
                <div>
                  <div style="font-size:12px;font-weight:600">Require Approval</div>
                </div>
                <div class="toggle on" onclick="this.classList.toggle('on')"></div>
              </div>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0">
                <div>
                  <div style="font-size:12px;font-weight:600">Slow Mode</div>
                </div>
                <div class="toggle" onclick="this.classList.toggle('on')"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- MODALS -->
  <div class="mo" id="logoutModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Sign Out</div>
        <div class="mx" onclick="closeModal('logoutModal')">✕</div>
      </div>
      <div class="mb">
        <div class="micon mi-yellow">🚪</div>
        <div style="font-size:16px;font-weight:800;margin-bottom:7px">Sign out of Ecollab?</div>
        <div style="color:var(--muted2);font-size:12px">You'll need to sign in again.</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('logoutModal')">Cancel</button><button class="btn-primary" style="background:linear-gradient(135deg,var(--red),#b91c1c)" onclick="doLogout()">🚪 Sign Out</button></div>
    </div>
  </div>
  <div class="mo" id="memberDetailModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="mdTitle">Member Detail</div>
        <div class="mx" onclick="closeModal('memberDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;align-items:center;gap:11px;margin-bottom:13px">
          <div id="mdAv" style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#e91e8c,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800">F</div>
          <div>
            <div id="mdName" style="font-size:14px;font-weight:800"></div>
            <div style="font-size:10.5px;color:var(--muted2)">Student · CS 305</div>
          </div>
        </div>
        <div class="info-grid">
          <div class="ig-item">
            <div class="ig-label">Messages Sent</div>
            <div class="ig-val" id="mdMessages">—</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Sessions</div>
            <div class="ig-val" id="mdSessions">—</div>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('memberDetailModal')">Close</button><button class="btn-primary" style="background:rgba(220,38,38,.15);color:var(--red)" onclick="openModal('kickModal',document.getElementById('mdTitle').textContent)">Kick</button></div>
    </div>
  </div>
  <div class="mo" id="kickModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Kick Member</div>
        <div class="mx" onclick="closeModal('kickModal')">✕</div>
      </div>
      <div class="mb">
        <div class="micon mi-red">⚠</div>
        <div style="font-size:14px;font-weight:800;margin-bottom:5px">Remove <span id="kickName">member</span>?</div>
        <div style="color:var(--muted2);font-size:12px">They will lose access to this channel.</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('kickModal')">Cancel</button><button class="btn-primary" style="background:linear-gradient(135deg,var(--red),#b91c1c)" onclick="doKick()">Remove</button></div>
    </div>
  </div>
  <div class="mo" id="inviteMemberModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Invite Member</div>
        <div class="mx" onclick="closeModal('inviteMemberModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Email or Username</label><input class="fi" id="inviteInput" placeholder="Enter email or @username"></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('inviteMemberModal')">Cancel</button><button class="btn-primary" onclick="closeModal('inviteMemberModal');toast('Invitation sent!','success','✅')">Send Invite</button></div>
    </div>
  </div>
  <div class="mo" id="createAnnModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt">Create Announcement</div>
        <div class="mx" onclick="closeModal('createAnnModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Server</label><select class="fi" id="annServer"><option value="">Choose a server...</option><?php foreach($facOwnedServers as $srv): ?><option value="<?= (int)$srv['id'] ?>"><?= htmlspecialchars($srv['name']) ?></option><?php endforeach; ?></select></div>
        <div class="fg"><label class="fl">Title</label><input class="fi" id="annTitle" placeholder="Announcement title"></div>
        <div class="fg"><label class="fl">Message</label><textarea class="fta" id="annBody" style="min-height:80px" placeholder="Write your announcement..."></textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('createAnnModal')">Cancel</button><button class="btn-primary" onclick="createAnnouncement()">📢 Publish</button></div>
    </div>
  </div>
  <div class="mo" id="editAnnModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt" id="eaTitle">Edit Announcement</div>
        <div class="mx" onclick="closeModal('editAnnModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Title</label><input class="fi" id="eaInput"></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('editAnnModal')">Cancel</button><button class="btn-primary" onclick="closeModal('editAnnModal');toast('Updated!','success','✅')">Save</button></div>
    </div>
  </div>
  <div class="mo" id="startSessionModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Start Study Session</div>
        <div class="mx" onclick="closeModal('startSessionModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Session Name</label><input class="fi" placeholder="e.g. Chapter 5 Review"></div>
        <div class="frow">
          <div class="fg"><label class="fl">Date</label><input class="fi" type="date"></div>
          <div class="fg"><label class="fl">Time</label><input class="fi" type="time"></div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('startSessionModal')">Cancel</button><button class="btn-primary" onclick="closeModal('startSessionModal');toast('Session started!','success','🎓')">🎓 Start</button></div>
    </div>
  </div>
  <div class="mo" id="uploadResourceModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Add Resource</div>
        <div class="mx" onclick="closeModal('uploadResourceModal')">✕</div>
      </div>
      <div class="mb">
        <div style="border:2px dashed var(--border);border-radius:11px;padding:26px;text-align:center;cursor:pointer" onclick="toast('File picker opened','info','📁')">
          <div style="font-size:28px;margin-bottom:7px">📁</div>
          <div style="font-size:12.5px;font-weight:600">Drop files or click to browse</div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('uploadResourceModal')">Cancel</button><button class="btn-primary" onclick="closeModal('uploadResourceModal');toast('Uploaded!','success','✅')">⬆ Upload</button></div>
    </div>
  </div>
  <div class="mo" id="exportModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Export Data</div>
        <div class="mx" onclick="closeModal('exportModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;flex-direction:column;gap:7px"><button class="btn-primary" style="justify-content:center" onclick="closeModal('exportModal');toast('Exported as CSV!','success','⬇')">📊 Export as CSV</button><button class="btn-sec" style="justify-content:center" onclick="closeModal('exportModal');toast('Exported as PDF!','success','⬇')">📄 Export as PDF</button></div>
      </div>
    </div>
  </div>
  <div class="mo" id="resolveReportModal">
    <div class="md md-md">
      <div class="mh">
        <div>
          <div class="mt">🚩 Resolve Report</div>
          <div class="resolve-subtitle">Choose an action for this reported user.</div>
        </div>
        <div class="mx" onclick="closeModal('resolveReportModal')">✕</div>
      </div>
      <div class="mb">
        <div class="resolve-target" id="resolveReportUser">Reported user</div>
        <div class="resolve-actions">
          <button type="button" class="resolve-action resolve-suspend" onclick="submitReportResolution('suspend',1440)">
            <span class="resolve-action-icon">⏸</span>
            <span><strong>Suspend for 24 Hours</strong><small>View-only access. The user can see the server but cannot interact.</small></span>
          </button>
          <button type="button" class="resolve-action resolve-mute" onclick="submitReportResolution('mute',1440)">
            <span class="resolve-action-icon">🔇</span>
            <span><strong>Mute for 24 Hours</strong><small>Only chat channels are muted. Other allowed server features remain available.</small></span>
          </button>
          <button type="button" class="resolve-action resolve-ban" onclick="submitReportResolution('ban',null)">
            <span class="resolve-action-icon">🚫</span>
            <span><strong>Ban from Server</strong><small>Remove the user and prevent them from joining this server again.</small></span>
          </button>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('resolveReportModal')">Cancel</button></div>
    </div>
  </div>
  <div class="mo" id="reportDetailModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt" id="rdtTitle">Report Detail</div>
        <div class="mx" onclick="closeModal('reportDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div style="color:var(--muted2);font-size:12.5px;line-height:1.7">Reported content is under review. Action can be taken from the Reports page.</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('reportDetailModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="periodModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Select Period</div>
        <div class="mx" onclick="closeModal('periodModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;flex-direction:column;gap:7px"><button class="btn-primary" style="justify-content:center" onclick="closeModal('periodModal');toast('This Week','info','📅')">This Week</button><button class="btn-sec" style="justify-content:center" onclick="closeModal('periodModal');toast('This Month','info','📅')">This Month</button><button class="btn-sec" style="justify-content:center" onclick="closeModal('periodModal');toast('All Time','info','📅')">All Time</button></div>
      </div>
    </div>
  </div>
  <div class="mo" id="editProfileModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Edit Profile</div>
        <div class="mx" onclick="closeModal('editProfileModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Display Name</label><input class="fi" value="<?= $name ?>"></div>
        <div class="fg"><label class="fl">Email</label><input class="fi" value="<?= htmlspecialchars($user['email'] ?? '') ?>"></div>
        <div class="fg"><label class="fl">Bio</label><textarea class="fta" style="min-height:55px">Facilitator for CS 305.</textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('editProfileModal')">Cancel</button><button class="btn-primary" onclick="closeModal('editProfileModal');toast('Saved!','success','✅')">💾 Save</button></div>
    </div>
  </div>
  <div class="mo" id="sessionDetailModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt" id="sdt">Session Detail</div>
        <div class="mx" onclick="closeModal('sessionDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div class="info-grid">
          <div class="ig-item">
            <div class="ig-label">Participants</div>
            <div class="ig-val">8</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Duration</div>
            <div class="ig-val">1.5 hrs</div>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('sessionDetailModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="aiModal">
    <div class="md md-lg">
      <div class="mh">
        <div class="mt">🤖 AI Assistant</div>
        <div class="mx" onclick="closeModal('aiModal')">✕</div>
      </div>
      <div class="mb">
        <div class="ai-log" id="aiLog">
          <div>
            <div class="ai-label ai">AI Assistant</div>
            <div class="ai-msg ai">Hello <?= $name ?>! I can help analyze member activity, generate reports, or draft announcements. What would you like help with?</div>
          </div>
        </div>
        <div style="display:flex;gap:7px;margin-top:9px"><input class="fi" id="aiInput" placeholder="Ask anything..." onkeydown="if(event.key==='Enter')sendAI()"><button class="btn-primary" onclick="sendAI()">Send →</button></div>
      </div>
    </div>
  </div>
  <div class="mo" id="createPollModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Create Poll</div>
        <div class="mx" onclick="closeModal('createPollModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Question</label><input class="fi" placeholder="Enter your poll question"></div>
        <div class="fg"><label class="fl">Options</label><input class="fi" placeholder="Option 1" style="margin-bottom:5px"><input class="fi" placeholder="Option 2" style="margin-bottom:5px"><input class="fi" placeholder="Option 3"></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('createPollModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createPollModal');toast('Poll created!','success','📊')">Create</button></div>
    </div>
  </div>
  <div class="mo" id="createQuizModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Create Quiz</div>
        <div class="mx" onclick="closeModal('createQuizModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Quiz Title</label><input class="fi" placeholder="e.g. Neural Networks Basics"></div>
        <div class="fg"><label class="fl">Time Limit</label><select class="fi">
            <option>15 minutes</option>
            <option>30 minutes</option>
            <option>45 minutes</option>
            <option>60 minutes</option>
          </select></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('createQuizModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createQuizModal');toast('Quiz created!','success','📝')">Create</button></div>
    </div>
  </div>
  <div class="mo" id="createRoleModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Create Role</div>
        <div class="mx" onclick="closeModal('createRoleModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Role Name</label><input class="fi" placeholder="e.g. Teaching Assistant"></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('createRoleModal')">Cancel</button><button class="btn-primary" onclick="closeModal('createRoleModal');toast('Role created!','success','✅')">Create</button></div>
    </div>
  </div>
  <div class="mo" id="channelSwitchModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Switch Channel</div>
        <div class="mx" onclick="closeModal('channelSwitchModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;flex-direction:column;gap:7px" id="channelSwitchList"><?php foreach ($dashData['my_channels'] ?? [] as $ch): ?><div style="padding:10px 12px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:9px;cursor:pointer;display:flex;align-items:center;gap:9px" onclick="switchChannel(<?= (int)($ch['id'] ?? 0) ?>, <?= (int)($ch['server_id'] ?? 0) ?>);closeModal('channelSwitchModal')">
              <div style="font-size:14px"><?= htmlspecialchars($ch['icon_emoji'] ?? '#') ?></div>
              <div>
                <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($ch['name'] ?? '') ?></div>
                <div style="font-size:10.5px;color:var(--muted2)"><?= (int)($ch['member_count'] ?? 0) ?> members</div>
              </div>
            </div><?php endforeach; ?><?php if (empty($dashData['my_channels'])): ?><div style="padding:10px 12px;background:rgba(233,30,140,.08);border:1px solid rgba(233,30,140,.2);border-radius:9px;cursor:pointer;display:flex;align-items:center;gap:9px" onclick="closeModal('channelSwitchModal')">
              <div style="font-size:14px">🧠</div>
              <div>
                <div style="font-size:12.5px;font-weight:700;color:var(--pink)">CS 305 – Neural Networks ✓</div>
                <div style="font-size:10.5px;color:var(--muted2)">72 members · Active</div>
              </div>
            </div><?php endif; ?></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('channelSwitchModal')">Close</button></div>
    </div>
  </div>

  <div class="toast-container" id="tc"></div>

  <script>
    var FAC_DATA = <?= json_encode([
                      'engData'    => $dashData['engagement_chart'] ?? [38, 42, 35, 48, 52, 45, 38],
                      'userId'     => $user['id'],
                      'csrfToken'  => $csrfToken,
                      'channelId'  => $dashData['channel']['id'] ?? null,
                    ], JSON_HEX_TAG) ?>;
    if (!FAC_DATA.engData.length) FAC_DATA.engData = [38, 42, 35, 48, 52, 45, 38];
  </script>
  <script src="<?= BASE_URL ?>/assets/js/facilitator/dashboard.js" defer></script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ai-markdown.css">

  <script src="https://cdn.jsdelivr.net/npm/marked@18.0.9/lib/marked.umd.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.4.13/dist/purify.min.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/ai-markdown.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/ai-session.js" defer></script>

  <script>
    // ── Mobile sidebar ─────────────────────────────────────────────────
    (function() {
      var sidebar = document.querySelector('.sidebar');
      var overlay = document.getElementById('sidebarOverlay');
      var header = document.querySelector('.header, .topbar');

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

      function checkMobile() {
        var isMob = window.innerWidth <= 768;
        var ham = document.getElementById('mobHamburger');
        if (ham) ham.style.display = isMob ? 'flex' : 'none';
      }
      window.addEventListener('resize', checkMobile);
      checkMobile();
    })();

    function toggleSidebar() {
      var s = document.querySelector('.sidebar');
      var o = document.getElementById('sidebarOverlay');
      if (s) s.classList.toggle('open');
      if (o) o.classList.toggle('open');
    }

    function closeSidebar() {
      var s = document.querySelector('.sidebar');
      var o = document.getElementById('sidebarOverlay');
      if (s) s.classList.remove('open');
      if (o) o.classList.remove('open');
    }
  </script>
</body>

</html>