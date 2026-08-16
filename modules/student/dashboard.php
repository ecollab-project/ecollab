<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/csrf/csrf.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH . '/services/UserService.php';

AuthMiddleware::startSession();
$user       = AuthMiddleware::requireAuth();
RoleMiddleware::requireRole(['student', 'facilitator', 'admin', 'super_admin', 'moderator']);

$csrfToken  = AuthMiddleware::csrfToken();
$activePage = $_GET['page'] ?? 'dashboard';

// Fetch live data
$userService  = new UserService();
$dashData     = $userService->getStudentDashboardData($user['id']);

$grad         = $user['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
$parts        = explode(',', $grad . ',#7c3aed');
$c1           = trim($parts[0]);
$c2           = trim($parts[1]);
$initials     = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));
$firstName    = explode(' ', trim($user['full_name'] ?: $user['username']))[0];

$hour         = (int)date('G');
$greeting     = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$unreadCount  = $dashData['unread_notifications'] ?? 3;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard – <?= APP_NAME ?></title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/student-dashboard.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/dashboard-mobile.css">
  <script>
    window.ECOLLAB_BASE = <?= json_encode(BASE_URL) ?>;
  </script>
</head>

<body>

  <?php include ROOT_PATH . '/includes/layout/sidebar-student.php'; ?>

  <!-- ═══════════ MAIN ═══════════ -->

  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
  <div class="main">
    <header class="header">
      <div class="search-wrap" id="swrap">
        <span style="color:var(--muted2);font-size:12px">🔍</span>
        <input type="text" placeholder="Search for courses, rooms, or people..." id="gsearch"
          oninput="handleSearch(this.value)" onfocus="showSD()" onblur="setTimeout(hideSD,200)" autocomplete="off">
        <div class="sdrop" id="sdrop">
          <div class="sd-cat">Courses</div>
          <?php foreach ($dashData['courses'] ?? [] as $c): ?>
            <div class="sd-row" onclick="showPage('courses');toast('Opened <?= htmlspecialchars($c['course_code'] ?? '') ?>','info','📚')">
              📚 <?= htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['name'] ?? '')) ?>
            </div>
          <?php endforeach; ?>
          <div class="sd-cat">People</div>
          <div class="sd-row" onclick="openModal('findBuddiesModal')">👫 Find Study Buddies</div>
        </div>
      </div>
      <div class="hdr-right">
        <div class="notif-btn" id="nBtn" onclick="toggleNotif()">🔔
          <div class="nbadge" id="nbadge"><?= $unreadCount ?></div>
          <div class="ndrop" id="ndrop">
            <div class="ndhead">
              <div class="ndtitle">Notifications</div>
              <div class="ndclear" onclick="clearNotifs()">Mark all read</div>
            </div>
            <?php foreach (array_slice($dashData['notifications'] ?? [], 0, 4) as $notif): ?>
              <div class="ndi <?= $notif['is_read'] ? '' : 'unread' ?>" onclick="handleNotif(this,'<?= htmlspecialchars($notif['title'] ?? '') ?>')">
                <?php if (!$notif['is_read']): ?><div class="ndd"></div><?php endif; ?>
                <div class="ndico" style="background:rgba(233,30,140,.15)"><?= htmlspecialchars($notif['icon'] ?? '🔔') ?></div>
                <div>
                  <div class="ndmsg"><?= htmlspecialchars($notif['message'] ?? '') ?></div>
                  <div class="ndtime"><?= htmlspecialchars($notif['time_ago'] ?? '') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="prof-chip" id="pchip" onclick="togglePDrop()">
          <div class="av" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?></div>
          <div>
            <div class="pn"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
            <div class="pr">BSCS Student</div>
          </div>
          <span style="color:var(--muted);font-size:10px;margin-left:2px">▼</span>
          <div class="ddmenu" id="pdrop">
            <div class="dhead">
              <div class="dun"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
              <div class="dur">Student</div>
            </div>
            <div class="ditem" onclick="openModal('editProfileModal')">✏️ Edit Profile</div>
            <div class="ditem" onclick="showPage('insights')">📊 My Progress</div>
            <div class="ditem" onclick="showPage('achievements')">🏆 Achievements</div>
            <div class="ditem" onclick="openModal('settingsModal')">⚙️ Settings</div>
            <div class="ditem" onclick="goToChat()">💬 Go to Chat</div>
            <div class="dsep"></div>
            <div class="ditem red" onclick="openModal('logoutModal')">🚪 Sign Out</div>
          </div>
        </div>
      </div>
    </header>

    <div class="content">

      <!-- ════ DASHBOARD PAGE ════ -->
      <div class="page-section active" id="page-dashboard">
        <div class="dash-header">
          <div>
            <div class="greeting"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?>! 👋</div>
            <div class="greeting-sub">Ready to continue your learning journey?</div>
          </div>
          <div class="quote-box">
            <div class="quote-txt">"The beautiful thing about learning is that nobody can take it away from you."</div>
            <div class="quote-author">— B.B. King</div>
          </div>
        </div>

        <!-- STAT CARDS -->
        <div class="stats-row">
          <div class="stat-card c1" onclick="showPage('courses')">
            <div class="sc-top">
              <div class="sc-label">Courses Enrolled</div>
              <div class="sc-icon">👥</div>
            </div>
            <div class="sc-val"><?= count($dashData['courses'] ?? []) ?: 5 ?></div>
            <div class="sc-ch">+1 this month</div>
          </div>
          <div class="stat-card c2" onclick="openModal('sessionsModal')">
            <div class="sc-top">
              <div class="sc-label">Study Sessions</div>
              <div class="sc-icon">📖</div>
            </div>
            <div class="sc-val"><?= $dashData['total_sessions'] ?? 24 ?></div>
            <div class="sc-ch">+8 this week</div>
          </div>
          <div class="stat-card c3" onclick="openModal('hoursModal')">
            <div class="sc-top">
              <div class="sc-label">Hours Studied</div>
              <div class="sc-icon">📈</div>
            </div>
            <div class="sc-val"><?= number_format((float)($dashData['hours_studied'] ?? 18.6), 1) ?></div>
            <div class="sc-ch">+4.2 this week</div>
          </div>
          <div class="stat-card c4" onclick="showPage('achievements')">
            <div class="sc-top">
              <div class="sc-label">Achievements</div>
              <div class="sc-icon">🏆</div>
            </div>
            <div class="sc-val"><?= $dashData['achievement_count'] ?? 12 ?></div>
            <div class="sc-ch">+2 new badges</div>
          </div>
        </div>

        <!-- MAIN GRID: SERVERS + COURSES -->
        <div class="main-grid">
          <!-- LEFT: PUBLIC SERVERS -->
          <div class="sc-card">
            <div class="sch">
              <div>
                <div class="sct">Public Servers For You</div>
              </div>
              <button class="view-all" onclick="showPage('discover')">View All</button>
            </div>
            <div class="sch-sub">Recommended servers based on your interests and goals</div>
            <div class="tag-filter" id="serverTagFilter">
              <div class="tf active" onclick="filterServers(this,'all')">All</div>
              <div class="tf" onclick="filterServers(this,'cs')">Computer Science</div>
              <div class="tf" onclick="filterServers(this,'ai')">AI & ML</div>
              <div class="tf" onclick="filterServers(this,'prog')">Programming</div>
              <div class="tf" onclick="filterServers(this,'web')">Web Dev</div>
              <div class="tf" onclick="filterServers(this,'ds')">Data Science</div>
              <div class="tf tf-more" onclick="openModal('moreTagsModal')">More <span>▾</span></div>
            </div>
            <div id="serverList">
              <?php foreach ($dashData['recommended_servers'] ?? [] as $srv): ?>
                <div class="server-row" data-cat="<?= htmlspecialchars($srv['tags'] ?? 'cs') ?>"
                  onclick="openModal('serverDetailModal','<?= htmlspecialchars($srv['name'] ?? '') ?>')">
                  <div class="srv-av" style="background:rgba(233,30,140,.15)"><?= htmlspecialchars($srv['icon_emoji'] ?? '🤖') ?></div>
                  <div class="srv-body">
                    <div class="srv-name"><?= htmlspecialchars($srv['name'] ?? '') ?></div>
                    <div class="srv-desc"><?= htmlspecialchars($srv['description'] ?? '') ?></div>
                    <div class="srv-tags">
                      <?php foreach (explode(',', $srv['tag_labels'] ?? '') as $t): if (trim($t)): ?>
                          <span class="srv-tag"><?= htmlspecialchars(trim($t)) ?></span>
                      <?php endif;
                      endforeach; ?>
                    </div>
                  </div>
                  <div class="srv-right">
                    <div class="srv-count"><?= number_format((int)($srv['member_count'] ?? 0)) ?><span>members</span></div>
                    <div class="srv-online"><?= (int)($srv['online_count'] ?? 0) ?> online</div>
                    <button class="btn-join" onclick="event.stopPropagation();joinServer(this,'<?= htmlspecialchars($srv['name'] ?? '') ?>')">Join</button>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (empty($dashData['recommended_servers'])): ?>
                <div class="server-row" data-cat="ai cs" onclick="openModal('serverDetailModal','AI & Machine Learning Hub')">
                  <div class="srv-av" style="background:rgba(233,30,140,.15)">🤖</div>
                  <div class="srv-body">
                    <div class="srv-name">AI & Machine Learning Hub</div>
                    <div class="srv-desc">Discuss AI concepts, share resources, and build projects together.</div>
                    <div class="srv-tags"><span class="srv-tag">AI</span><span class="srv-tag">Machine Learning</span></div>
                  </div>
                  <div class="srv-right">
                    <div class="srv-count">2.1K<span>members</span></div>
                    <div class="srv-online">168 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'AI Hub')">Join</button>
                  </div>
                </div>
                <div class="server-row" data-cat="cs prog" onclick="openModal('serverDetailModal','DSA & Problem Solvers')">
                  <div class="srv-av" style="background:rgba(6,182,212,.15)">💻</div>
                  <div class="srv-body">
                    <div class="srv-name">DSA & Problem Solvers</div>
                    <div class="srv-desc">Practice data structures, algorithms, and problem solving.</div>
                    <div class="srv-tags"><span class="srv-tag">DSA</span><span class="srv-tag">Algorithms</span></div>
                  </div>
                  <div class="srv-right">
                    <div class="srv-count">1.8K<span>members</span></div>
                    <div class="srv-online">142 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'DSA')">Join</button>
                  </div>
                </div>
                <div class="server-row" data-cat="web prog" onclick="openModal('serverDetailModal','Web Dev Community')">
                  <div class="srv-av" style="background:rgba(22,163,74,.15)">🌐</div>
                  <div class="srv-body">
                    <div class="srv-name">Web Dev Community</div>
                    <div class="srv-desc">HTML, CSS, JS and modern frameworks discussions.</div>
                    <div class="srv-tags"><span class="srv-tag">Web Dev</span><span class="srv-tag">Frontend</span></div>
                  </div>
                  <div class="srv-right">
                    <div class="srv-count">3.4K<span>members</span></div>
                    <div class="srv-online">215 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'Web Dev')">Join</button>
                  </div>
                </div>
                <div class="server-row" data-cat="cs" onclick="openModal('serverDetailModal','Study Buddies Worldwide')">
                  <div class="srv-av" style="background:rgba(217,119,6,.15)">👫</div>
                  <div class="srv-body">
                    <div class="srv-name">Study Buddies Worldwide</div>
                    <div class="srv-desc">Find study partners and stay motivated together!</div>
                    <div class="srv-tags"><span class="srv-tag">Study Group</span></div>
                  </div>
                  <div class="srv-right">
                    <div class="srv-count">1.2K<span>members</span></div>
                    <div class="srv-online">93 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'Study Buddies')">Join</button>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <div class="explore-more" onclick="showPage('discover')"><span>Explore More Servers</span><span>›</span></div>
          </div>

          <!-- RIGHT: YOUR COURSES + FRIENDS ONLINE -->
          <div>
            <div class="sc-card" style="margin-bottom:12px">
              <div class="sch">
                <div class="sct">Your Courses</div><button class="view-all" onclick="showPage('courses')">View All</button>
              </div>
              <div>
                <?php
                $courseColors = ['var(--pink)', '#a78bfa', '#60a5fa', 'var(--green)', 'var(--yellow)'];
                $courseGrads  = ['linear-gradient(90deg,var(--pink),var(--purple))', 'linear-gradient(90deg,var(--purple),var(--indigo))', 'linear-gradient(90deg,var(--blue),var(--cyan))', 'linear-gradient(90deg,var(--green),var(--teal))', 'linear-gradient(90deg,var(--yellow),var(--orange))'];
                foreach (array_slice($dashData['courses'] ?? [], 0, 5) as $ci => $course):
                  $pct  = (int)($course['progress_percentage'] ?? rand(40, 90));
                  $clr  = $courseColors[$ci % count($courseColors)];
                  $grad = $courseGrads[$ci % count($courseGrads)];
                  $name = htmlspecialchars(($course['course_code'] ?? '') . ' - ' . ($course['name'] ?? 'Course'));
                ?>
                  <div class="course-row" onclick="openModal('courseDetailModal','<?= $name ?>')">
                    <div class="cr-left">
                      <div class="cr-name"><span class="course-clr" style="background:<?= $clr ?>"></span><?= $name ?></div>
                      <div class="cr-bar-bg">
                        <div class="cr-bar-fill" style="width:<?= $pct ?>%;background:<?= $grad ?>"></div>
                      </div>
                      <div class="cr-next">Next: <?= htmlspecialchars($course['next_topic'] ?? 'Upcoming lesson') ?></div>
                    </div>
                    <div class="cr-pct" style="color:<?= $clr ?>"><?= $pct ?>%</div>
                    <div class="cr-arr">›</div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($dashData['courses'])): ?>
                  <div class="course-row" onclick="openModal('courseDetailModal','CS 305 - Neural Networks')">
                    <div class="cr-left">
                      <div class="cr-name"><span class="course-clr" style="background:var(--pink)"></span>CS 305 - Neural Networks</div>
                      <div class="cr-bar-bg">
                        <div class="cr-bar-fill" style="width:78%;background:linear-gradient(90deg,var(--pink),var(--purple))"></div>
                      </div>
                      <div class="cr-next">Next: Backpropagation Basics · May 24</div>
                    </div>
                    <div class="cr-pct" style="color:var(--pink)">78%</div>
                    <div class="cr-arr">›</div>
                  </div>
                  <div class="course-row" onclick="openModal('courseDetailModal','CS 201 - Data Structures')">
                    <div class="cr-left">
                      <div class="cr-name"><span class="course-clr" style="background:#a78bfa"></span>CS 201 - Data Structures</div>
                      <div class="cr-bar-bg">
                        <div class="cr-bar-fill" style="width:65%;background:linear-gradient(90deg,var(--purple),var(--indigo))"></div>
                      </div>
                      <div class="cr-next">Next: Trees and Graphs · May 26</div>
                    </div>
                    <div class="cr-pct" style="color:#a78bfa">65%</div>
                    <div class="cr-arr">›</div>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- FRIENDS ONLINE -->
            <div class="sc-card">
              <div class="sch">
                <div class="sct">Friends Online</div><button class="view-all" onclick="openModal('allFriendsModal')">View All</button>
              </div>
              <?php foreach ($dashData['friends_online'] ?? [] as $friend):
                $fGrad = $friend['avatar_color_gradient'] ?? '#e91e8c,#7c3aed';
                $fInit = strtoupper(substr($friend['full_name'] ?: $friend['username'], 0, 1));
                $fStatus = $friend['status'] ?? 'studying';
                $statusClass = in_array($fStatus, ['studying', 'online']) ? 'st-green' : 'st-yellow';
                $statusText  = htmlspecialchars($friend['activity'] ?? "● " . ucfirst($fStatus));
                $statusColor = str_contains($statusClass, 'green') ? 'var(--green)' : 'var(--yellow)';
                $fName = htmlspecialchars($friend['username'] ?? '');
              ?>
                <div class="friend-row" onclick="openModal('profileModal','<?= $fName ?>')">
                  <div class="fr-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($fGrad) ?>)"><?= htmlspecialchars($fInit) ?><div class="fr-status <?= $statusClass ?>"></div>
                  </div>
                  <div class="fr-body">
                    <div class="fr-name"><?= $fName ?></div>
                    <div class="fr-act" style="color:<?= $statusColor ?>"><?= $statusText ?></div>
                  </div>
                  <button class="fr-btn" onclick="event.stopPropagation();openModal('dmModal','<?= $fName ?>')">Message</button>
                </div>
              <?php endforeach; ?>
              <?php if (empty($dashData['friends_online'])): ?>
                <div class="friend-row" onclick="openModal('profileModal','Fatima_Student')">
                  <div class="fr-av" style="background:linear-gradient(135deg,#ff4fd8,#7c3aed)">F<div class="fr-status st-green"></div>
                  </div>
                  <div class="fr-body">
                    <div class="fr-name">Fatima_Student</div>
                    <div class="fr-act" style="color:var(--green)">● Studying CS 305</div>
                  </div><button class="fr-btn" onclick="event.stopPropagation();openModal('dmModal','Fatima_Student')">Message</button>
                </div>
                <div class="friend-row" onclick="openModal('profileModal','Alex Chen')">
                  <div class="fr-av" style="background:linear-gradient(135deg,#2563eb,#06b6d4)">A<div class="fr-status st-green"></div>
                  </div>
                  <div class="fr-body">
                    <div class="fr-name">Alex Chen</div>
                    <div class="fr-act" style="color:var(--green)">● In CS 201 Study Room</div>
                  </div><button class="fr-btn" onclick="event.stopPropagation();openModal('dmModal','Alex Chen')">Message</button>
                </div>
              <?php endif; ?>
            </div>

            <!-- MY SERVERS & CHANNELS -->
            <?php $membership = $dashData['membership'] ?? []; ?>
            <div class="sc-card" style="margin-top:12px">
              <div class="sch">
                <div>
                  <div class="sct">My Servers &amp; Channels</div>
                </div>
                <button class="view-all" onclick="showPage('discover')">Browse</button>
              </div>
              <div class="sch-sub">
                <?= (int)($membership['servers_joined_count'] ?? 0) ?> server<?= ((int)($membership['servers_joined_count'] ?? 0)) === 1 ? '' : 's' ?> ·
                <?= (int)($membership['channels_joined_count'] ?? 0) ?> channel<?= ((int)($membership['channels_joined_count'] ?? 0)) === 1 ? '' : 's' ?>
                <?php if (($membership['servers_owned_count'] ?? 0) > 0): ?>
                  · <span style="color:var(--pink);font-weight:600">owns <?= (int)$membership['servers_owned_count'] ?></span>
                <?php endif; ?>
              </div>
              <div style="padding:0 15px 12px;display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto">
                <?php foreach (array_slice($membership['my_servers'] ?? [], 0, 6) as $srv):
                  $srvRole = $srv['server_role'] ?? 'member';
                  $roleBadge = match ($srvRole) {
                    'owner' => ['Owner', 'var(--pink)'],
                    'admin' => ['Admin', 'var(--blue)'],
                    'moderator' => ['Mod', 'var(--green)'],
                    default => [null, null],
                  };
                ?>
                  <div class="friend-row" style="padding:8px 0" onclick="openModal('serverDetailModal','<?= htmlspecialchars($srv['name'] ?? '') ?>')">
                    <div class="fr-av" style="background:rgba(233,30,140,.12);font-size:14px"><?= htmlspecialchars($srv['icon_emoji'] ?? '🖥') ?></div>
                    <div class="fr-body">
                      <div class="fr-name"><?= htmlspecialchars($srv['name'] ?? '') ?></div>
                      <div class="fr-act" style="color:var(--muted2)">
                        <?= (int)($srv['member_count'] ?? 0) ?> members · <?= (int)($srv['channel_count'] ?? 0) ?> channels
                        <?php if ($roleBadge[0]): ?>
                          · <span style="color:<?= $roleBadge[1] ?>;font-weight:600"><?= $roleBadge[0] ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($membership['my_servers'])): ?>
                  <div style="padding:14px 0;text-align:center;color:var(--muted2);font-size:12px">
                    You haven't joined any servers yet.
                    <div style="margin-top:6px"><button class="btn-join" onclick="showPage('discover')">Discover Servers</button></div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- BOTTOM ROW: ACTIVITY + SESSIONS -->
        <div class="main-grid" style="margin-bottom:12px">
          <div class="sc-card">
            <div class="act-header">
              <div class="sct">Activity Overview</div>
              <div class="act-period" onclick="openModal('periodModal')">This Week <span>▾</span></div>
            </div>
            <div class="chart-wrap"><canvas id="actChart"></canvas></div>
            <div class="act-stats">
              <div class="as-card">
                <div class="as-label">🔥 Study Streak</div>
                <div class="as-val" style="color:#fb923c"><?= (int)($dashData['study_streak'] ?? 7) ?> days</div>
                <div class="as-sub">Keep it up!</div>
              </div>
              <div class="as-card">
                <div class="as-label">🎯 Weekly Goal</div>
                <div class="as-val" style="color:var(--green)"><?= number_format((float)($dashData['hours_studied'] ?? 18), 1) ?> / 20 hrs</div>
                <div class="prog-mini">
                  <div class="prog-fill" style="width:<?= min(100, (int)(($dashData['hours_studied'] ?? 18) / 20 * 100)) ?>%;background:linear-gradient(90deg,var(--green),var(--teal))"></div>
                </div>
                <div class="as-sub"><?= min(100, (int)(($dashData['hours_studied'] ?? 18) / 20 * 100)) ?>% completed</div>
              </div>
              <div class="as-card">
                <div class="as-label">⏱ Focus Time</div>
                <div class="as-val" style="color:var(--cyan)"><?= number_format((float)($dashData['focus_time'] ?? 14.2), 1) ?> hrs</div>
                <div class="as-sub">+2.1 hrs vs last week</div>
              </div>
            </div>
          </div>

          <div class="sc-card">
            <div class="sch">
              <div class="sct">Upcoming Study Sessions</div><button class="view-all" onclick="showPage('calendar')">View Calendar</button>
            </div>
            <?php foreach (array_slice($dashData['upcoming_sessions'] ?? [], 0, 3) as $sess):
              $sessDate = !empty($sess['start_time']) ? date('d', strtotime($sess['start_time'])) : '24';
              $sessMonth = !empty($sess['start_time']) ? strtoupper(date('M', strtotime($sess['start_time']))) : 'MAY';
              $sessName = htmlspecialchars($sess['name'] ?? 'Study Session');
              $sessSub  = htmlspecialchars($sess['description'] ?? '');
              $sessTime = !empty($sess['start_time']) ? date('g:i A', strtotime($sess['start_time'])) . ' – ' . date('g:i A', strtotime($sess['end_time'] ?? $sess['start_time'])) : '3:00 PM – 5:00 PM';
            ?>
              <div class="sess-row" onclick="openModal('sessionDetailModal','<?= $sessName ?>')">
                <div class="sr-date">
                  <div class="sr-day"><?= $sessDate ?></div>
                  <div class="sr-month"><?= $sessMonth ?></div>
                </div>
                <div class="sr-body">
                  <div class="sr-name"><?= $sessName ?></div>
                  <div class="sr-sub"><?= $sessSub ?></div>
                  <div class="sr-time"><?= $sessTime ?></div>
                </div>
                <div class="sr-right">
                  <button class="btn-join" style="font-size:10px;padding:4px 10px" onclick="event.stopPropagation();joinClass('<?= $sessName ?>')">Join</button>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($dashData['upcoming_sessions'])): ?>
              <div class="sess-row" onclick="openModal('sessionDetailModal','CS 305 Study Session')">
                <div class="sr-date">
                  <div class="sr-day">24</div>
                  <div class="sr-month">MAY</div>
                </div>
                <div class="sr-body">
                  <div class="sr-name">CS 305 Study Session</div>
                  <div class="sr-sub">Neural Networks · Chapter 4</div>
                  <div class="sr-time">3:00 PM – 5:00 PM</div>
                </div>
                <div class="sr-right">
                  <div class="sr-avs">
                    <div class="sr-av" style="background:linear-gradient(135deg,#ff4fd8,#7c3aed)">F</div>
                    <div class="sr-av" style="background:linear-gradient(135deg,#2563eb,#06b6d4)">A</div>
                    <div class="sr-more">+8</div>
                  </div><button class="btn-join" style="font-size:10px;padding:4px 10px" onclick="event.stopPropagation();joinClass('CS 305 Session')">Join</button>
                </div>
              </div>
              <div class="sess-row" onclick="openModal('sessionDetailModal','Group Project Meeting')">
                <div class="sr-date">
                  <div class="sr-day">25</div>
                  <div class="sr-month">MAY</div>
                </div>
                <div class="sr-body">
                  <div class="sr-name">Group Project Meeting</div>
                  <div class="sr-sub">AI Chatbot Development</div>
                  <div class="sr-time">10:00 AM – 12:00 PM</div>
                </div>
                <div class="sr-right"><button class="btn-join" style="font-size:10px;padding:4px 10px" onclick="event.stopPropagation();joinClass('Project Meeting')">Join</button></div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- BOTTOM ROW 2: ACHIEVEMENTS + QUICK ACTIONS -->
        <div class="bottom-grid">
          <div class="sc-card">
            <div class="sch">
              <div class="sct">Recent Achievements</div><button class="view-all" onclick="showPage('achievements')">View All</button>
            </div>
            <div class="ach-grid">
              <div class="ach-card" onclick="openModal('achModal','Neural Explorer')">
                <div class="ach-bg" style="background:linear-gradient(135deg,rgba(124,58,237,.25),rgba(124,58,237,.08))">🧠</div>
                <div class="ach-name">Neural Explorer</div>
                <div class="ach-desc">Complete 10 Neural Networks sessions</div>
                <div class="ach-date">May 20, 2025</div>
              </div>
              <div class="ach-card" onclick="openModal('achModal','Consistent Learner')">
                <div class="ach-bg" style="background:linear-gradient(135deg,rgba(220,38,38,.2),rgba(220,38,38,.06))">🔥</div>
                <div class="ach-name">Consistent Learner</div>
                <div class="ach-desc">Study for 7 days in a row</div>
                <div class="ach-date">May 19, 2025</div>
              </div>
              <div class="ach-card" onclick="openModal('achModal','Active Participant')">
                <div class="ach-bg" style="background:linear-gradient(135deg,rgba(37,99,235,.2),rgba(37,99,235,.06))">💬</div>
                <div class="ach-name">Active Participant</div>
                <div class="ach-desc">Send 50 messages in study rooms</div>
                <div class="ach-date">May 18, 2025</div>
              </div>
              <div class="ach-card" onclick="openModal('achModal','Team Player')">
                <div class="ach-bg" style="background:linear-gradient(135deg,rgba(22,163,74,.2),rgba(22,163,74,.06))">👫</div>
                <div class="ach-name">Team Player</div>
                <div class="ach-desc">Join 10 group sessions</div>
                <div class="ach-date">May 17, 2025</div>
              </div>
            </div>
          </div>
          <div class="sc-card">
            <div class="sch">
              <div class="sct">Quick Actions</div>
            </div>
            <div class="qa-grid">
              <button class="qa-btn qa-pink" onclick="openModal('joinRoomModal')">🏠 Join Study Room</button>
              <button class="qa-btn qa-purple" onclick="openModal('createRoomModal')">✦ Create Study Room</button>
              <button class="qa-btn qa-blue" onclick="openModal('uploadModal')">📤 Upload Resource</button>
              <button class="qa-btn qa-green" onclick="openModal('aiModal')">🤖 Ask AI Assistant</button>
              <button class="qa-btn qa-yellow" onclick="openModal('quizModal')">📝 Take Quiz</button>
              <button class="qa-btn qa-teal" onclick="openModal('findBuddiesModal')">👫 Find Study Buddies</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ════ DISCOVER PAGE ════ -->
      <div class="page-section" id="page-discover">
        <div class="page-title-row">
          <div>
            <div class="page-title">Discover</div>
            <div class="page-sub">Find servers, rooms, and study partners.</div>
          </div><button class="btn-primary" onclick="openModal('createRoomModal')">+ Create Room</button>
        </div>
        <div class="sc-card">
          <div class="sch">
            <div class="sct">Public Study Rooms & Servers</div>
          </div>
          <div class="server-row" onclick="openModal('serverDetailModal','AI & Machine Learning Hub')">
            <div class="srv-av" style="background:rgba(233,30,140,.15)">🤖</div>
            <div class="srv-body">
              <div class="srv-name">AI & Machine Learning Hub</div>
              <div class="srv-desc">Discuss AI, ML concepts, and build intelligent solutions.</div>
              <div class="srv-tags"><span class="srv-tag">AI</span><span class="srv-tag">ML</span><span class="srv-tag">Python</span></div>
            </div>
            <div class="srv-right">
              <div class="srv-online">168 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'AI Hub')">Join</button>
            </div>
          </div>
          <div class="server-row" onclick="openModal('serverDetailModal','DSA & Problem Solvers')">
            <div class="srv-av" style="background:rgba(6,182,212,.15)">💻</div>
            <div class="srv-body">
              <div class="srv-name">DSA & Problem Solvers</div>
              <div class="srv-desc">Practice problems and ace your interviews.</div>
              <div class="srv-tags"><span class="srv-tag">Algorithms</span><span class="srv-tag">LeetCode</span></div>
            </div>
            <div class="srv-right">
              <div class="srv-online">142 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'DSA')">Join</button>
            </div>
          </div>
          <div class="server-row" onclick="openModal('serverDetailModal','Web Dev Community')">
            <div class="srv-av" style="background:rgba(22,163,74,.15)">🌐</div>
            <div class="srv-body">
              <div class="srv-name">Web Development Community</div>
              <div class="srv-desc">Build modern web apps and share resources.</div>
              <div class="srv-tags"><span class="srv-tag">React</span><span class="srv-tag">CSS</span></div>
            </div>
            <div class="srv-right">
              <div class="srv-online">215 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'Web Dev')">Join</button>
            </div>
          </div>
          <div class="explore-more" onclick="loadMoreServers()"><span>Load More Servers</span><span>↓</span></div>
        </div>
      </div>

      <!-- ════ COURSES PAGE ════ -->
      <div class="page-section" id="page-courses">
        <div class="page-title-row">
          <div>
            <div class="page-title">My Courses</div>
            <div class="page-sub">Track enrolled courses and progress.</div>
          </div><button class="btn-primary" onclick="openModal('enrollModal')">+ Enroll Course</button>
        </div>
        <div class="g2">
          <?php foreach ($dashData['courses'] ?? [] as $ci => $course):
            $pct  = (int)($course['progress_percentage'] ?? 65);
            $name = htmlspecialchars(($course['course_code'] ?? '') . ' — ' . ($course['name'] ?? ''));
            $clr  = $courseColors[$ci % count($courseColors)];
            $grd  = $courseGrads[$ci % count($courseGrads)];
          ?>
            <div class="card" onclick="openModal('courseDetailModal','<?= $name ?>')" style="cursor:pointer">
              <div class="ch">
                <div class="ct"><?= $name ?></div><span style="color:<?= $clr ?>;font-weight:800;font-size:13px"><?= $pct ?>%</span>
              </div>
              <div class="cb">
                <div class="prog-bar">
                  <div class="prog-fill2" style="width:<?= $pct ?>%;background:<?= $grd ?>"></div>
                </div>
                <div style="font-size:10.5px;color:var(--muted2);margin-top:7px">📅 Next: <?= htmlspecialchars($course['next_topic'] ?? 'Upcoming lesson') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($dashData['courses'])): ?>
            <div class="card" style="cursor:pointer" onclick="openModal('courseDetailModal','CS 305 - Neural Networks')">
              <div class="ch">
                <div class="ct">CS 305 — Neural Networks</div><span style="color:var(--pink);font-weight:800;font-size:13px">78%</span>
              </div>
              <div class="cb">
                <div class="prog-bar">
                  <div class="prog-fill2" style="width:78%;background:linear-gradient(90deg,var(--pink),var(--purple))"></div>
                </div>
                <div style="font-size:10.5px;color:var(--muted2);margin-top:7px">📅 Next: Backpropagation Basics · May 24</div>
              </div>
            </div>
            <div class="card" style="cursor:pointer" onclick="openModal('courseDetailModal','CS 201 - Data Structures')">
              <div class="ch">
                <div class="ct">CS 201 — Data Structures</div><span style="color:#a78bfa;font-weight:800;font-size:13px">65%</span>
              </div>
              <div class="cb">
                <div class="prog-bar">
                  <div class="prog-fill2" style="width:65%;background:linear-gradient(90deg,var(--purple),var(--indigo))"></div>
                </div>
                <div style="font-size:10.5px;color:var(--muted2);margin-top:7px">📅 Next: Trees and Graphs · May 26</div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ════ STUDY ROOMS PAGE ════ -->
      <div class="page-section" id="page-rooms">
        <div class="page-title-row">
          <div>
            <div class="page-title">Study Rooms</div>
            <div class="page-sub">Join or create collaborative study spaces.</div>
          </div><button class="btn-primary" onclick="openModal('createRoomModal')">+ Create Room</button>
        </div>
        <div class="sc-card" id="roomsPageList">
          <div class="sch">
            <div class="sct">Active Rooms</div>
          </div>
          <?php foreach ($dashData['study_rooms'] ?? [] as $room): ?>
            <div class="server-row" onclick="openModal('roomDetailModal','<?= htmlspecialchars($room['name'] ?? '') ?>')">
              <div class="srv-av" style="background:rgba(233,30,140,.15)"><?= htmlspecialchars($room['icon'] ?? '🏠') ?></div>
              <div class="srv-body">
                <div class="srv-name"><?= htmlspecialchars($room['name'] ?? '') ?></div>
                <div class="srv-desc"><?= htmlspecialchars($room['description'] ?? '') ?></div>
              </div>
              <div class="srv-right">
                <div class="srv-online"><?= (int)($room['active_members'] ?? 0) ?> online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'<?= htmlspecialchars($room['name'] ?? '') ?>')">Join</button>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($dashData['study_rooms'])): ?>
            <div class="server-row" onclick="openModal('roomDetailModal','CS 305 Neural Networks Study Group')">
              <div class="srv-av" style="background:rgba(233,30,140,.15)">🧠</div>
              <div class="srv-body">
                <div class="srv-name">CS 305 - Neural Networks Study Group</div>
                <div class="srv-desc">Discuss algorithms, backpropagation, and more</div>
              </div>
              <div class="srv-right">
                <div class="srv-online">12 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'CS 305 Room')">Join</button>
              </div>
            </div>
            <div class="server-row" onclick="openModal('roomDetailModal','DSA Practice Group')">
              <div class="srv-av" style="background:rgba(6,182,212,.15)">💻</div>
              <div class="srv-body">
                <div class="srv-name">Data Structures & Algorithms</div>
                <div class="srv-desc">Practice problems and share solutions</div>
              </div>
              <div class="srv-right">
                <div class="srv-online">18 online</div><button class="btn-join" onclick="event.stopPropagation();joinServer(this,'DSA Room')">Join</button>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ════ MESSAGES PAGE ════ -->
      <div class="page-section" id="page-messages">
        <div class="page-title-row">
          <div>
            <div class="page-title">Messages</div>
            <div class="page-sub">Direct messages and group chats.</div>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn-primary" onclick="goToChat()">💬 Open Full Chat</button>
            <button class="btn-sec" onclick="openModal('newMsgModal')">+ New Message</button>
          </div>
        </div>
        <div class="card">
          <div class="ch">
            <div class="ct">Recent Conversations</div>
          </div>
          <div class="msg-list" id="msgList">
            <div class="msg-row">
              <div class="m-av" style="background:linear-gradient(135deg,#e91e8c,#7c3aed)">F</div>
              <div>
                <div class="m-name">Fatima_Student</div>
                <div class="m-bubble">
                  <div class="m-txt">Hey! Did you get the lecture notes for chapter 5?</div>
                  <div class="m-time">2:34 PM</div>
                </div>
              </div>
            </div>
            <div class="msg-row" style="flex-direction:row-reverse">
              <div class="m-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?></div>
              <div style="align-items:flex-end;display:flex;flex-direction:column">
                <div class="m-name" style="text-align:right">You</div>
                <div class="m-bubble mine">
                  <div class="m-txt">Yes! I uploaded them in Files & Resources 📁</div>
                  <div class="m-time" style="text-align:right">2:35 PM</div>
                </div>
              </div>
            </div>
          </div>
          <div class="m-input-wrap">
            <input class="m-input" id="msgInput" placeholder="Type a message..." onkeydown="if(event.key==='Enter')sendMsg()">
            <button class="btn-primary" style="padding:7px 13px" onclick="sendMsg()">Send →</button>
          </div>
        </div>
      </div>

      <!-- ════ NOTIFICATIONS PAGE ════ -->
      <div class="page-section" id="page-notifpage">
        <div class="page-title-row">
          <div>
            <div class="page-title">Notifications</div>
            <div class="page-sub">Stay updated with your activity.</div>
          </div><button class="btn-sec" onclick="clearNotifs()">Mark all read</button>
        </div>
        <div class="card" id="notifPageList">
          <?php foreach ($dashData['notifications'] ?? [] as $notif): ?>
            <div class="ndi <?= $notif['is_read'] ? '' : 'unread' ?>" onclick="handleNotif(this,'<?= htmlspecialchars($notif['title'] ?? '') ?>')">
              <?php if (!$notif['is_read']): ?><div class="ndd"></div><?php endif; ?>
              <div class="ndico" style="background:rgba(233,30,140,.15)"><?= htmlspecialchars($notif['icon'] ?? '🔔') ?></div>
              <div>
                <div class="ndmsg"><?= htmlspecialchars($notif['message'] ?? '') ?></div>
                <div class="ndtime"><?= htmlspecialchars($notif['time_ago'] ?? '') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($dashData['notifications'])): ?>
            <div class="ndi unread" onclick="handleNotif(this,'Fatima replied')">
              <div class="ndd"></div>
              <div class="ndico" style="background:rgba(233,30,140,.15)">💬</div>
              <div>
                <div class="ndmsg">Fatima_Student replied to your post in AI & ML Hub</div>
                <div class="ndtime">2 hours ago</div>
              </div>
            </div>
            <div class="ndi unread" onclick="handleNotif(this,'Quiz available')">
              <div class="ndd"></div>
              <div class="ndico" style="background:rgba(22,163,74,.15)">✅</div>
              <div>
                <div class="ndmsg">New quiz available: Neural Networks Basics</div>
                <div class="ndtime">3 hours ago</div>
              </div>
            </div>
            <div class="ndi unread" onclick="handleNotif(this,'Achievement')">
              <div class="ndd"></div>
              <div class="ndico" style="background:rgba(217,119,6,.15)">🏆</div>
              <div>
                <div class="ndmsg">Achievement Unlocked: Consistent Learner!</div>
                <div class="ndtime">1 day ago</div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ════ CALENDAR PAGE ════ -->
      <div class="page-section" id="page-calendar">
        <div class="page-title-row">
          <div>
            <div class="page-title">Calendar</div>
            <div class="page-sub">Manage your study schedule.</div>
          </div><button class="btn-primary" onclick="openModal('addEventModal')">+ Add Event</button>
        </div>
        <div class="g2" style="align-items:start">
          <div class="card">
            <div class="ch">
              <div class="ct" id="calTitle"><?= date('F Y') ?></div>
              <div style="display:flex;gap:5px"><button class="btn-sm btn-outline" onclick="prevMonth()">‹</button><button class="btn-sm btn-outline" onclick="nextMonth()">›</button></div>
            </div>
            <div style="padding:13px 15px">
              <div class="cal-hdays">
                <div class="cal-hd">Su</div>
                <div class="cal-hd">Mo</div>
                <div class="cal-hd">Tu</div>
                <div class="cal-hd">We</div>
                <div class="cal-hd">Th</div>
                <div class="cal-hd">Fr</div>
                <div class="cal-hd">Sa</div>
              </div>
              <div class="cal-grid" id="calGrid"></div>
            </div>
          </div>
          <div class="card">
            <div class="ch">
              <div class="ct">Upcoming Events</div>
            </div>
            <?php foreach (array_slice($dashData['upcoming_sessions'] ?? [], 0, 3) as $sess):
              $d  = !empty($sess['start_time']) ? date('d', strtotime($sess['start_time'])) : '24';
              $m  = !empty($sess['start_time']) ? strtoupper(date('M', strtotime($sess['start_time']))) : 'MAY';
              $t  = !empty($sess['start_time']) ? date('g:i A', strtotime($sess['start_time'])) : '3:00 PM';
            ?>
              <div class="sess-row" onclick="openModal('sessionDetailModal','<?= htmlspecialchars($sess['name'] ?? '') ?>')">
                <div class="sr-date">
                  <div class="sr-day"><?= $d ?></div>
                  <div class="sr-month"><?= $m ?></div>
                </div>
                <div class="sr-body">
                  <div class="sr-name"><?= htmlspecialchars($sess['name'] ?? '') ?></div>
                  <div class="sr-sub"><?= htmlspecialchars($sess['description'] ?? '') ?></div>
                  <div class="sr-time"><?= $t ?></div>
                </div>
                <button class="btn-join" style="font-size:10px;padding:4px 10px" onclick="event.stopPropagation();joinClass('<?= htmlspecialchars($sess['name'] ?? '') ?>')">Join</button>
              </div>
            <?php endforeach; ?>
            <?php if (empty($dashData['upcoming_sessions'])): ?>
              <div class="sess-row">
                <div class="sr-date">
                  <div class="sr-day">24</div>
                  <div class="sr-month">MAY</div>
                </div>
                <div class="sr-body">
                  <div class="sr-name">CS 305 Study Session</div>
                  <div class="sr-sub">Neural Networks - Chapter 4</div>
                  <div class="sr-time">3:00 PM – 5:00 PM</div>
                </div><button class="btn-join" style="font-size:10px;padding:4px 10px" onclick="joinClass('CS 305')">Join</button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ════ WHITEBOARD PAGE ════ -->
      <div class="page-section" id="page-whiteboard">
        <div class="page-title-row">
          <div>
            <div class="page-title">Whiteboard</div>
            <div class="page-sub">Collaborate in real-time.</div>
          </div>
          <div style="display:flex;gap:7px"><button class="btn-sec" onclick="clearWB()">🗑 Clear</button><button class="btn-primary" onclick="saveWB()">💾 Save</button></div>
        </div>
        <div class="card">
          <div class="cb">
            <div class="wb-tools"><button class="wbt active" id="wbPen" onclick="setTool('pen',this)">✏️ Pen</button><button class="wbt" id="wbEraser" onclick="setTool('eraser',this)">🧹 Eraser</button><button class="wbt" id="wbRect" onclick="setTool('rect',this)">⬜ Rect</button><label style="display:flex;align-items:center;gap:5px;font-size:10.5px;color:var(--muted2)">Color:<input type="color" id="wbColor" value="#e91e8c" style="width:24px;height:19px;border:none;background:none;cursor:pointer;border-radius:4px"></label><label style="display:flex;align-items:center;gap:5px;font-size:10.5px;color:var(--muted2)">Size:<input type="range" id="wbSize" min="1" max="20" value="3" style="width:70px"></label></div><canvas class="wb-canvas" id="wbCanvas" height="420"></canvas>
          </div>
        </div>
      </div>

      <!-- ════ FILES PAGE ════ -->
      <div class="page-section" id="page-files">
        <div class="page-title-row">
          <div>
            <div class="page-title">Files & Resources</div>
            <div class="page-sub">Access and share study materials.</div>
          </div><button class="btn-primary" onclick="openModal('uploadModal')">⬆ Upload</button>
        </div>
        <div class="card">
          <div class="ch">
            <div class="ct">Recent Files</div>
          </div>
          <div id="filesList">
            <?php foreach ($dashData['files'] ?? [] as $file):
              $ext = strtolower(pathinfo($file['file_name'] ?? '', PATHINFO_EXTENSION));
              $ico = in_array($ext, ['pdf']) ? '📄' : (in_array($ext, ['xlsx', 'csv']) ? '📊' : (in_array($ext, ['docx', 'doc']) ? '📋' : '📎'));
              $bg  = $ext === 'pdf' ? 'rgba(220,38,38,.15)' : ($ext === 'xlsx' ? 'rgba(37,99,235,.15)' : 'rgba(217,119,6,.15)');
            ?>
              <div class="file-row">
                <div class="fi-ico" style="background:<?= $bg ?>"><?= $ico ?></div>
                <div class="fi-name"><?= htmlspecialchars($file['file_name'] ?? '') ?></div>
                <div class="fi-meta"><?= htmlspecialchars($file['course_code'] ?? '') ?> · <?= htmlspecialchars($file['uploader'] ?? 'You') ?></div>
                <div class="fi-size"><?= htmlspecialchars($file['file_size_formatted'] ?? '') ?></div>
                <button class="btn-sm btn-outline" onclick="toast('Downloading...','info','⬇')">Download</button>
              </div>
            <?php endforeach; ?>
            <?php if (empty($dashData['files'])): ?>
              <div class="file-row">
                <div class="fi-ico" style="background:rgba(220,38,38,.15)">📄</div>
                <div class="fi-name">lecture_notes_ch5.pdf</div>
                <div class="fi-meta">CS 305 · You</div>
                <div class="fi-size">2.4 MB</div><button class="btn-sm btn-outline" onclick="toast('Downloading...','info','⬇')">Download</button>
              </div>
              <div class="file-row">
                <div class="fi-ico" style="background:rgba(37,99,235,.15)">📊</div>
                <div class="fi-name">DSA_cheatsheet.xlsx</div>
                <div class="fi-meta">CS 201 · Fatima</div>
                <div class="fi-size">845 KB</div><button class="btn-sm btn-outline" onclick="toast('Downloading...','info','⬇')">Download</button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ════ NOTES PAGE ════ -->
      <div class="page-section" id="page-notes">
        <div class="page-title-row">
          <div>
            <div class="page-title">Notes</div>
            <div class="page-sub">Your personal study notes.</div>
          </div><button class="btn-primary" onclick="openModal('newNoteModal')">+ New Note</button>
        </div>
        <div id="notesList">
          <?php foreach ($dashData['notes'] ?? [] as $note): ?>
            <div class="note-card" onclick="openModal('viewNoteModal','<?= htmlspecialchars($note['title'] ?? '') ?>')">
              <div class="note-title"><?= htmlspecialchars($note['title'] ?? '') ?></div>
              <div class="note-preview"><?= htmlspecialchars(substr($note['content'] ?? '', 0, 120)) ?>...</div>
              <div class="note-meta"><span><?= htmlspecialchars($note['course_code'] ?? '') ?> · <?= htmlspecialchars($note['updated_label'] ?? 'Today') ?></span></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($dashData['notes'])): ?>
            <div class="note-card" onclick="openModal('viewNoteModal','Neural Networks - Key Concepts')">
              <div class="note-title">Neural Networks — Key Concepts</div>
              <div class="note-preview">Backpropagation, gradient descent, activation functions. ReLU vs Sigmoid comparison...</div>
              <div class="note-meta"><span>CS 305 · Today</span>
                <div style="display:flex;gap:5px"><button class="btn-sm btn-outline" style="padding:3px 7px" onclick="event.stopPropagation();openModal('editNoteModal','Neural Networks')">Edit</button></div>
              </div>
            </div>
            <div class="note-card" onclick="openModal('viewNoteModal','DSA - Two Pointers')">
              <div class="note-title">DSA — Two Pointers Technique</div>
              <div class="note-preview">Use two pointers for O(n) solutions on sorted arrays. Start from both ends...</div>
              <div class="note-meta"><span>CS 201 · Yesterday</span></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ════ ACTIVITY PAGE ════ -->
      <div class="page-section" id="page-activity">
        <div class="page-title-row">
          <div>
            <div class="page-title">My Activity</div>
          </div>
        </div>
        <div class="ins-grid">
          <div class="ins-card">
            <div class="ins-label">Total Sessions</div>
            <div class="ins-val" style="color:#60a5fa"><?= $dashData['total_sessions'] ?? 24 ?></div>
          </div>
          <div class="ins-card">
            <div class="ins-label">Hours This Week</div>
            <div class="ins-val" style="color:var(--green)"><?= number_format((float)($dashData['hours_studied'] ?? 18.6), 1) ?>h</div>
          </div>
          <div class="ins-card">
            <div class="ins-label">Study Streak</div>
            <div class="ins-val" style="color:#fb923c"><?= (int)($dashData['study_streak'] ?? 7) ?> days 🔥</div>
          </div>
          <div class="ins-card">
            <div class="ins-label">Messages Sent</div>
            <div class="ins-val" style="color:#a78bfa"><?= $dashData['messages_sent'] ?? 342 ?></div>
          </div>
        </div>
        <div class="card">
          <div class="ch">
            <div class="ct">Activity This Week</div>
          </div>
          <div style="padding:13px;height:170px"><canvas id="actChart2"></canvas></div>
        </div>
      </div>

      <!-- ════ INSIGHTS PAGE ════ -->
      <div class="page-section" id="page-insights">
        <div class="page-title-row">
          <div>
            <div class="page-title">Study Insights</div>
          </div>
        </div>
        <div class="ins-grid">
          <div class="ins-card">
            <div class="ins-label">Study Streak</div>
            <div class="ins-val" style="color:#fb923c"><?= (int)($dashData['study_streak'] ?? 7) ?> days 🔥</div>
          </div>
          <div class="ins-card">
            <div class="ins-label">Weekly Goal</div>
            <div class="ins-val" style="color:var(--green)"><?= min(100, (int)(($dashData['hours_studied'] ?? 18) / 20 * 100)) ?>%</div>
          </div>
          <div class="ins-card">
            <div class="ins-label">Quiz Accuracy</div>
            <div class="ins-val" style="color:var(--cyan)"><?= $dashData['quiz_accuracy'] ?? 84 ?>%</div>
          </div>
          <div class="ins-card">
            <div class="ins-label">Best Subject</div>
            <div class="ins-val" style="color:var(--pink);font-size:15px"><?= htmlspecialchars($dashData['best_subject'] ?? 'CS 305') ?></div>
          </div>
        </div>
        <div class="card">
          <div class="ch">
            <div class="ct">Hours Per Course</div>
          </div>
          <div style="padding:13px;height:170px"><canvas id="insChart"></canvas></div>
        </div>
      </div>

      <!-- ════ ACHIEVEMENTS PAGE ════ -->
      <div class="page-section" id="page-achievements">
        <div class="page-title-row">
          <div>
            <div class="page-title">Achievements</div>
            <div class="page-sub"><?= (int)($dashData['achievement_count'] ?? 12) ?> earned</div>
          </div>
        </div>
        <div class="card" style="padding:14px">
          <div class="acp-grid">
            <div class="acp" onclick="openModal('achModal','Neural Explorer')">
              <div style="font-size:24px;margin-bottom:7px">🧠</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Neural Explorer</div>
              <div style="font-size:9.5px;color:var(--muted2)">10 Neural Networks</div>
            </div>
            <div class="acp" onclick="openModal('achModal','Consistent Learner')">
              <div style="font-size:24px;margin-bottom:7px">🔥</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Consistent Learner</div>
              <div style="font-size:9.5px;color:var(--muted2)">7-day streak</div>
            </div>
            <div class="acp" onclick="openModal('achModal','Active Participant')">
              <div style="font-size:24px;margin-bottom:7px">💬</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Active Participant</div>
              <div style="font-size:9.5px;color:var(--muted2)">50 messages</div>
            </div>
            <div class="acp" onclick="openModal('achModal','Team Player')">
              <div style="font-size:24px;margin-bottom:7px">👫</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Team Player</div>
              <div style="font-size:9.5px;color:var(--muted2)">10 group sessions</div>
            </div>
            <div class="acp" onclick="openModal('achModal','First Login')">
              <div style="font-size:24px;margin-bottom:7px">🎯</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">First Login</div>
              <div style="font-size:9.5px;color:var(--muted2)">Welcome!</div>
            </div>
            <div class="acp" onclick="openModal('achModal','Quiz Master')">
              <div style="font-size:24px;margin-bottom:7px">📝</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Quiz Master</div>
              <div style="font-size:9.5px;color:var(--muted2)">10 quizzes done</div>
            </div>
            <div class="acp locked">
              <div style="font-size:24px;margin-bottom:7px">💯</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Perfect Score</div>
              <div style="font-size:9.5px;color:var(--muted2)">100% on quiz</div>
            </div>
            <div class="acp locked">
              <div style="font-size:24px;margin-bottom:7px">🚀</div>
              <div style="font-size:10.5px;font-weight:700;margin-bottom:2px">Rocket Learner</div>
              <div style="font-size:9.5px;color:var(--muted2)">50h in one week</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ════ HELP PAGE ════ -->
      <div class="page-section" id="page-help">
        <div class="page-title-row">
          <div>
            <div class="page-title">Help Center</div>
            <div class="page-sub">Find answers and support.</div>
          </div>
        </div>
        <div class="card">
          <div class="cb"><input class="fi" placeholder="🔍 Search help topics..."></div>
          <div class="help-row" onclick="openModal('helpArticleModal','Getting Started')">
            <div class="help-ico" style="background:rgba(233,30,140,.15)">🚀</div>
            <div>
              <div class="help-title">Getting Started with Ecollab</div>
              <div class="help-sub">Learn the basics and set up your profile</div>
            </div><span style="color:var(--muted2);margin-left:auto">›</span>
          </div>
          <div class="help-row" onclick="openModal('helpArticleModal','Study Rooms')">
            <div class="help-ico" style="background:rgba(6,182,212,.15)">🏠</div>
            <div>
              <div class="help-title">How to Use Study Rooms</div>
              <div class="help-sub">Join, create, and manage study rooms</div>
            </div><span style="color:var(--muted2);margin-left:auto">›</span>
          </div>
          <div class="help-row" onclick="openModal('helpArticleModal','AI Assistant')">
            <div class="help-ico" style="background:rgba(124,58,237,.15)">🤖</div>
            <div>
              <div class="help-title">Using the AI Study Assistant</div>
              <div class="help-sub">Get the most out of AI-powered help</div>
            </div><span style="color:var(--muted2);margin-left:auto">›</span>
          </div>
          <div class="help-row" onclick="openModal('reportModal')">
            <div class="help-ico" style="background:rgba(220,38,38,.15)">🚩</div>
            <div>
              <div class="help-title">Report a Problem</div>
              <div class="help-sub">Submit a bug or technical issue</div>
            </div><span style="color:var(--muted2);margin-left:auto">›</span>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->

  <!-- ═══════════ MODALS (preserved verbatim from HTML) ═══════════ -->
  <div class="mo" id="roomDetailModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="rdTitle">Study Room</div>
        <div class="mx" onclick="closeModal('roomDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div style="background:rgba(233,30,140,.07);border:1px solid rgba(233,30,140,.15);border-radius:11px;padding:13px;margin-bottom:13px;display:flex;align-items:center;gap:12px">
          <div style="width:42px;height:42px;border-radius:11px;background:rgba(233,30,140,.2);display:flex;align-items:center;justify-content:center;font-size:19px">🧠</div>
          <div style="flex:1">
            <div style="font-size:14px;font-weight:800" id="rdName">CS 305 Neural Networks Study Group</div>
            <div style="font-size:11px;color:var(--muted2)">12 members active · Live now</div>
          </div><button class="btn-join" onclick="closeModal('roomDetailModal');toast('Joined room!','success','✅')">Join</button>
        </div>
        <div class="info-grid">
          <div class="ig-item">
            <div class="ig-label">Host</div>
            <div class="ig-val">Mike_Lee</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Max Members</div>
            <div class="ig-val">25</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Status</div>
            <div class="ig-val" style="color:var(--green)">● Live</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Type</div>
            <div class="ig-val">Study Room</div>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('roomDetailModal')">Close</button><button class="btn-primary" onclick="closeModal('roomDetailModal');toast('Joined!','success','✅')">✓ Join Room</button></div>
    </div>
  </div>
  <div class="mo" id="serverDetailModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="sdTitle">Server</div>
        <div class="mx" onclick="closeModal('serverDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div style="background:rgba(233,30,140,.07);border:1px solid rgba(233,30,140,.15);border-radius:11px;padding:13px;margin-bottom:13px;display:flex;align-items:center;gap:12px">
          <div style="width:42px;height:42px;border-radius:11px;background:rgba(233,30,140,.2);display:flex;align-items:center;justify-content:center;font-size:19px">🤖</div>
          <div style="flex:1">
            <div style="font-size:14px;font-weight:800" id="sdName">Server</div>
            <div style="font-size:11px;color:var(--muted2)">Members online</div>
          </div><button class="btn-join" onclick="closeModal('serverDetailModal');toast('Joined server!','success','✅')">Join Server</button>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('serverDetailModal')">Close</button><button class="btn-primary" onclick="closeModal('serverDetailModal');toast('Joined!','success','✅')">✓ Join</button></div>
    </div>
  </div>
  <div class="mo" id="createRoomModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Create Study Room</div>
        <div class="mx" onclick="closeModal('createRoomModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Room Name</label><input class="fi" id="newRoomName" placeholder="e.g. CS 305 Study Group"></div>
        <div class="fg"><label class="fl">Topic</label><input class="fi" placeholder="What will you study?"></div>
        <div class="frow">
          <div class="fg"><label class="fl">Max Members</label><select class="fi">
              <option>5</option>
              <option selected>10</option>
              <option>20</option>
              <option>30</option>
            </select></div>
          <div class="fg"><label class="fl">Privacy</label><select class="fi">
              <option>Public</option>
              <option>Private</option>
            </select></div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('createRoomModal')">Cancel</button><button class="btn-primary" onclick="doCreateRoom()">✓ Create</button></div>
    </div>
  </div>
  <div class="mo" id="joinRoomModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Join Study Room</div>
        <div class="mx" onclick="closeModal('joinRoomModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Room Code or Name</label><input class="fi" id="joinRoomInput" placeholder="Enter code..."></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('joinRoomModal')">Cancel</button><button class="btn-primary" onclick="doJoinRoom()">Join</button></div>
    </div>
  </div>
  <div class="mo" id="aiModal">
    <div class="md md-lg">
      <div class="mh">
        <div class="mt">✦ AI Study Assistant <span style="font-size:9.5px;background:rgba(233,30,140,.2);color:var(--pink);padding:2px 6px;border-radius:4px;margin-left:6px">BETA</span></div>
        <div class="mx" onclick="closeModal('aiModal')">✕</div>
      </div>
      <div class="mb">
        <div class="ai-log" id="aiLog">
          <div>
            <div class="ai-label ai">AI Assistant</div>
            <div class="ai-msg ai">Hello <?= htmlspecialchars($firstName) ?>! I can summarize topics, quiz you on material, or help create study plans. What would you like help with?</div>
          </div>
        </div>
        <div style="display:flex;gap:5px;margin-bottom:9px;flex-wrap:wrap"><button class="btn-sm btn-outline" onclick="aiQP('Summarize Neural Networks Ch.5')">📝 Summarize</button><button class="btn-sm btn-outline" onclick="aiQP('Quiz me on DSA')">🧠 Quiz me</button><button class="btn-sm btn-outline" onclick="aiQP('Create my study plan for this week')">📅 Study plan</button></div>
        <div style="display:flex;gap:7px"><input class="fi" id="aiInput" placeholder="Ask anything..." onkeydown="if(event.key==='Enter')sendAI()"><button class="btn-primary" onclick="sendAI()">Send →</button></div>
      </div>
    </div>
  </div>
  <div class="mo" id="uploadModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Upload Resource</div>
        <div class="mx" onclick="closeModal('uploadModal')">✕</div>
      </div>
      <div class="mb">
        <div style="border:2px dashed var(--border);border-radius:11px;padding:26px;text-align:center;cursor:pointer;margin-bottom:11px" onclick="toast('File picker opened','info','📁')">
          <div style="font-size:28px;margin-bottom:7px">📁</div>
          <div style="font-size:12.5px;font-weight:600;margin-bottom:3px">Drop files or click to browse</div>
          <div style="font-size:11px;color:var(--muted2)">PDF, DOCX, PNG, XLS up to 50MB</div>
        </div>
        <div class="fg"><label class="fl">Course</label><select class="fi">
            <option>CS 305 — Neural Networks</option>
            <option>CS 201 — Data Structures</option>
            <option>CS 210 — Database Systems</option>
          </select></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('uploadModal')">Cancel</button><button class="btn-primary" onclick="doUpload()">⬆ Upload</button></div>
    </div>
  </div>
  <div class="mo" id="sessionDetailModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="sessDtTitle">Study Session</div>
        <div class="mx" onclick="closeModal('sessionDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div class="info-grid">
          <div class="ig-item">
            <div class="ig-label">Date</div>
            <div class="ig-val">May 24, 2025</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Time</div>
            <div class="ig-val">3:00 – 5:00 PM</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Participants</div>
            <div class="ig-val">11 joined</div>
          </div>
          <div class="ig-item">
            <div class="ig-label">Room</div>
            <div class="ig-val">CS 305 Room</div>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('sessionDetailModal')">Close</button><button class="btn-primary" onclick="closeModal('sessionDetailModal');toast('Joined session!','success','✅')">Join Session</button></div>
    </div>
  </div>
  <div class="mo" id="courseDetailModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="cdt">Course Details</div>
        <div class="mx" onclick="closeModal('courseDetailModal')">✕</div>
      </div>
      <div class="mb">
        <div class="tab-bar">
          <div class="tb active" onclick="switchTab(this,'cdO')">Overview</div>
          <div class="tb" onclick="switchTab(this,'cdA')">Assignments</div>
          <div class="tb" onclick="switchTab(this,'cdR')">Resources</div>
        </div>
        <div class="tc active" id="cdO">
          <div class="info-grid">
            <div class="ig-item">
              <div class="ig-label">Progress</div>
              <div class="ig-val" style="color:var(--pink)">78%</div>
            </div>
            <div class="ig-item">
              <div class="ig-label">Next Class</div>
              <div class="ig-val">May 24</div>
            </div>
            <div class="ig-item">
              <div class="ig-label">Grade</div>
              <div class="ig-val" style="color:#60a5fa">A-</div>
            </div>
            <div class="ig-item">
              <div class="ig-label">Professor</div>
              <div class="ig-val">Prof. Martinez</div>
            </div>
          </div>
          <div class="prog-bar" style="margin-top:12px">
            <div class="prog-fill2" style="width:78%;background:linear-gradient(90deg,var(--pink),var(--purple))"></div>
          </div>
        </div>
        <div class="tc" id="cdA">
          <div style="padding:9px 0;border-bottom:1px solid var(--border2)">
            <div style="font-size:12px;font-weight:600">Assignment 3 — Due: Tomorrow 11:59 PM</div>
          </div>
        </div>
        <div class="tc" id="cdR">
          <div style="padding:9px 0;display:flex;align-items:center;gap:9px;cursor:pointer">
            <div style="font-size:20px">📄</div>
            <div>
              <div style="font-size:12px;font-weight:600">Lecture Notes</div>
              <div style="font-size:10.5px;color:var(--muted2)">PDF · 2.4 MB</div>
            </div><button class="btn-sm btn-outline" style="margin-left:auto" onclick="toast('Downloading...','info','⬇')">Download</button>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('courseDetailModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="achModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt" id="amTitle">Achievement</div>
        <div class="mx" onclick="closeModal('achModal')">✕</div>
      </div>
      <div class="mb" style="text-align:center">
        <div style="font-size:50px;margin-bottom:11px" id="amIcon">🔥</div>
        <div style="font-size:17px;font-weight:800;margin-bottom:5px" id="amName">Achievement</div>
        <div style="color:var(--muted2);font-size:12px;line-height:1.6;margin-bottom:12px" id="amDesc">Achievement description.</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('achModal')">Close</button><button class="btn-primary" onclick="closeModal('achModal');toast('Shared!','success','🔗')">🔗 Share</button></div>
    </div>
  </div>
  <div class="mo" id="profileModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt" id="pmTitle">Profile</div>
        <div class="mx" onclick="closeModal('profileModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;align-items:center;gap:11px;margin-bottom:13px">
          <div style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#e91e8c,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800">F</div>
          <div>
            <div style="font-size:14px;font-weight:800" id="pmName">Profile</div>
            <div style="font-size:10.5px;color:var(--muted2)">BSCS · Online</div>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('profileModal')">Close</button><button class="btn-primary" onclick="closeModal('profileModal');openModal('dmModal',document.getElementById('pmName').textContent)">💬 Message</button></div>
    </div>
  </div>
  <div class="mo" id="dmModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt" id="dmTitle">Direct Message</div>
        <div class="mx" onclick="closeModal('dmModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Message</label><textarea class="fta" placeholder="Write a message..."></textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('dmModal')">Cancel</button><button class="btn-primary" onclick="closeModal('dmModal');toast('Message sent!','success','✅')">Send →</button></div>
    </div>
  </div>
  <div class="mo" id="newMsgModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">New Message</div>
        <div class="mx" onclick="closeModal('newMsgModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">To</label><input class="fi" placeholder="Search user..."></div>
        <div class="fg"><label class="fl">Message</label><textarea class="fta" placeholder="Write a message..."></textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('newMsgModal')">Cancel</button><button class="btn-primary" onclick="closeModal('newMsgModal');toast('Message sent!','success','✅')">Send →</button></div>
    </div>
  </div>
  <div class="mo" id="editProfileModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Edit Profile</div>
        <div class="mx" onclick="closeModal('editProfileModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;align-items:center;gap:11px;margin-bottom:13px">
          <div class="av" style="width:48px;height:48px;font-size:17px;border:3px solid rgba(233,30,140,.4);background:linear-gradient(135deg,<?= htmlspecialchars($c1) ?>,<?= htmlspecialchars($c2) ?>)"><?= htmlspecialchars($initials) ?></div><button class="btn-sec" onclick="toast('Photo picker opened','info','📷')">Change Photo</button>
        </div>
        <div class="frow">
          <div class="fg"><label class="fl">First Name</label><input class="fi" id="editFirst" value="<?= htmlspecialchars($firstName) ?>"></div>
          <div class="fg"><label class="fl">Last Name</label><input class="fi" value=""></div>
        </div>
        <div class="fg"><label class="fl">Email</label><input class="fi" value="<?= htmlspecialchars($user['email'] ?? '') ?>"></div>
        <div class="fg"><label class="fl">Bio</label><textarea class="fta" style="min-height:55px">CS student passionate about AI and web dev.</textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('editProfileModal')">Cancel</button><button class="btn-primary" onclick="saveProfile()">💾 Save</button></div>
    </div>
  </div>
  <div class="mo" id="settingsModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt">Settings</div>
        <div class="mx" onclick="closeModal('settingsModal')">✕</div>
      </div>
      <div class="mb">
        <div class="tab-bar">
          <div class="tb active" onclick="switchTab(this,'sG')">General</div>
          <div class="tb" onclick="switchTab(this,'sN')">Notifications</div>
          <div class="tb" onclick="switchTab(this,'sP')">Privacy</div>
        </div>
        <div class="tc active" id="sG">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border)">
            <div>
              <div style="font-size:12px;font-weight:600">Dark Mode</div>
            </div>
            <div class="toggle on" onclick="this.classList.toggle('on')"></div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0">
            <div>
              <div style="font-size:12px;font-weight:600">Language</div>
            </div><select class="fi" style="width:120px">
              <option>English</option>
              <option>Filipino</option>
            </select>
          </div>
        </div>
        <div class="tc" id="sN">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border)">
            <div>
              <div style="font-size:12px;font-weight:600">Messages</div>
            </div>
            <div class="toggle on" onclick="this.classList.toggle('on')"></div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0">
            <div>
              <div style="font-size:12px;font-weight:600">Study Reminders</div>
            </div>
            <div class="toggle on" onclick="this.classList.toggle('on')"></div>
          </div>
        </div>
        <div class="tc" id="sP">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0">
            <div>
              <div style="font-size:12px;font-weight:600">Show Online Status</div>
            </div>
            <div class="toggle on" onclick="this.classList.toggle('on')"></div>
          </div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('settingsModal')">Cancel</button><button class="btn-primary" onclick="closeModal('settingsModal');saveSettings()">💾 Save</button></div>
    </div>
  </div>
  <div class="mo" id="logoutModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Sign Out</div>
        <div class="mx" onclick="closeModal('logoutModal')">✕</div>
      </div>
      <div class="mb">
        <div class="micon mi-yellow">🚪</div>
        <div style="font-size:16px;font-weight:800;margin-bottom:7px">Sign out of Ecollab?</div>
        <div style="color:var(--muted2);font-size:12px">You'll need to sign in again to access your account.</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('logoutModal')">Cancel</button><button class="btn-primary" style="background:linear-gradient(135deg,var(--red),#b91c1c)" onclick="doLogout()">🚪 Sign Out</button></div>
    </div>
  </div>
  <div class="mo" id="sessionsModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Study Sessions</div>
        <div class="mx" onclick="closeModal('sessionsModal')">✕</div>
      </div>
      <div class="mb">
        <div style="text-align:center;margin-bottom:13px">
          <div style="font-size:46px;font-weight:800;color:#60a5fa"><?= $dashData['total_sessions'] ?? 24 ?></div>
          <div style="color:var(--muted2)">Total sessions this month</div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('sessionsModal')">Close</button><button class="btn-primary" onclick="closeModal('sessionsModal');showPage('insights')">View Insights</button></div>
    </div>
  </div>
  <div class="mo" id="hoursModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Hours Studied</div>
        <div class="mx" onclick="closeModal('hoursModal')">✕</div>
      </div>
      <div class="mb">
        <div style="text-align:center;margin-bottom:13px">
          <div style="font-size:46px;font-weight:800;color:var(--green)"><?= number_format((float)($dashData['hours_studied'] ?? 18.6), 1) ?></div>
          <div style="color:var(--muted2)">Hours this week</div>
        </div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('hoursModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="periodModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Select Period</div>
        <div class="mx" onclick="closeModal('periodModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;flex-direction:column;gap:7px"><button class="btn-primary" style="justify-content:center" onclick="closeModal('periodModal');toast('This Week selected','info','📅')">This Week</button><button class="btn-sec" style="justify-content:center" onclick="closeModal('periodModal');toast('This Month','info','📅')">This Month</button><button class="btn-sec" style="justify-content:center" onclick="closeModal('periodModal');toast('All Time','info','📅')">All Time</button></div>
      </div>
    </div>
  </div>
  <div class="mo" id="addEventModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Add Event</div>
        <div class="mx" onclick="closeModal('addEventModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Title</label><input class="fi" placeholder="e.g. CS 305 Study Session"></div>
        <div class="frow">
          <div class="fg"><label class="fl">Date</label><input class="fi" type="date"></div>
          <div class="fg"><label class="fl">Time</label><input class="fi" type="time"></div>
        </div>
        <div class="fg"><label class="fl">Type</label><select class="fi">
            <option>Class</option>
            <option>Study Session</option>
            <option>Meeting</option>
            <option>Exam</option>
          </select></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('addEventModal')">Cancel</button><button class="btn-primary" onclick="closeModal('addEventModal');toast('Event added!','success','✅')">Add Event</button></div>
    </div>
  </div>
  <div class="mo" id="newNoteModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt">New Note</div>
        <div class="mx" onclick="closeModal('newNoteModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Title</label><input class="fi" id="noteTitle" placeholder="Note title..."></div>
        <div class="fg"><label class="fl">Course</label><select class="fi">
            <option>None</option>
            <option>CS 305</option>
            <option>CS 201</option>
          </select></div>
        <div class="fg"><label class="fl">Content</label><textarea class="fta" id="noteContent" style="min-height:90px" placeholder="Write your note..."></textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('newNoteModal')">Cancel</button><button class="btn-primary" onclick="saveNote()">💾 Save Note</button></div>
    </div>
  </div>
  <div class="mo" id="viewNoteModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="vnTitle">Note</div>
        <div class="mx" onclick="closeModal('viewNoteModal')">✕</div>
      </div>
      <div class="mb">
        <div id="vnContent" style="font-size:12.5px;line-height:1.7;color:var(--muted2)">Loading...</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('viewNoteModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="editNoteModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="enTitle">Edit Note</div>
        <div class="mx" onclick="closeModal('editNoteModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Title</label><input class="fi" value="Note Title"></div>
        <div class="fg"><label class="fl">Content</label><textarea class="fta" style="min-height:90px">Note content...</textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('editNoteModal')">Cancel</button><button class="btn-primary" onclick="closeModal('editNoteModal');toast('Note updated','success','✅')">💾 Save</button></div>
    </div>
  </div>
  <div class="mo" id="enrollModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Enroll in Course</div>
        <div class="mx" onclick="closeModal('enrollModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Course Code</label><input class="fi" id="enrollCode" placeholder="e.g. CS 401"></div>
        <div class="fg"><label class="fl">Enrollment Key</label><input class="fi" type="password" placeholder="Key (if required)"></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('enrollModal')">Cancel</button><button class="btn-primary" onclick="doEnroll()">✓ Enroll</button></div>
    </div>
  </div>
  <div class="mo" id="reportModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Report an Issue</div>
        <div class="mx" onclick="closeModal('reportModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Issue Type</label><select class="fi">
            <option>Bug / Error</option>
            <option>Performance</option>
            <option>Content Issue</option>
            <option>Other</option>
          </select></div>
        <div class="fg"><label class="fl">Description</label><textarea class="fta" placeholder="Describe the issue..."></textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('reportModal')">Cancel</button><button class="btn-primary" onclick="submitReport()">Submit</button></div>
    </div>
  </div>
  <div class="mo" id="feedbackModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">Send Feedback</div>
        <div class="mx" onclick="closeModal('feedbackModal')">✕</div>
      </div>
      <div class="mb">
        <div class="fg"><label class="fl">Type</label><select class="fi">
            <option>👍 Positive</option>
            <option>💡 Suggestion</option>
            <option>🐛 Bug</option>
          </select></div>
        <div class="fg"><label class="fl">Feedback</label><textarea class="fta" placeholder="Share your thoughts..."></textarea></div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('feedbackModal')">Cancel</button><button class="btn-primary" onclick="closeModal('feedbackModal');toast('Feedback sent! Thank you!','success','💬')">Send</button></div>
    </div>
  </div>
  <div class="mo" id="allFriendsModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt">Friends Online</div>
        <div class="mx" onclick="closeModal('allFriendsModal')">✕</div>
      </div>
      <div class="mb" style="padding:0"><?php foreach ($dashData['friends_online'] ?? [] as $fr): $fN = htmlspecialchars($fr['username'] ?? '');
                                          $fI = strtoupper(substr($fr['full_name'] ?? $fr['username'] ?? '?', 0, 1)); ?><div class="friend-row" onclick="openModal('profileModal','<?= $fN ?>')">
            <div class="fr-av" style="background:linear-gradient(135deg,<?= htmlspecialchars($fr['avatar_color_gradient'] ?? '#e91e8c,#7c3aed') ?>)"><?= htmlspecialchars($fI) ?><div class="fr-status st-green"></div>
            </div>
            <div class="fr-body">
              <div class="fr-name"><?= $fN ?></div>
              <div class="fr-act" style="color:var(--green)">● Online</div>
            </div><button class="fr-btn" onclick="event.stopPropagation();openModal('dmModal','<?= $fN ?>')">Message</button>
          </div><?php endforeach; ?></div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('allFriendsModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="quizModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt">Take a Quiz</div>
        <div class="mx" onclick="closeModal('quizModal')">✕</div>
      </div>
      <div class="mb">
        <div class="tab-bar">
          <div class="tb active" onclick="switchTab(this,'qA')">Available</div>
          <div class="tb" onclick="switchTab(this,'qH')">History</div>
        </div>
        <div class="tc active" id="qA">
          <div style="padding:9px 0;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border2)">
            <div style="font-size:20px">🧠</div>
            <div style="flex:1">
              <div style="font-size:12.5px;font-weight:600">Neural Networks Basics</div>
              <div style="font-size:10.5px;color:var(--muted2)">CS 305 · 15 questions</div>
            </div><button class="btn-primary" style="font-size:10.5px;padding:5px 11px" onclick="closeModal('quizModal');toast('Quiz started!','success','📝')">Start</button>
          </div>
        </div>
        <div class="tc" id="qH">
          <div style="padding:9px 0;display:flex;align-items:center;gap:10px">
            <div style="font-size:20px">✅</div>
            <div style="flex:1">
              <div style="font-size:12.5px;font-weight:600">Neural Networks Basics</div>
              <div style="font-size:10.5px;color:var(--muted2)">Score: 85% · 2 days ago</div>
            </div><span style="background:rgba(22,163,74,.12);color:var(--green);padding:2px 7px;border-radius:20px;font-size:10px;font-weight:600">Passed</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="mo" id="findBuddiesModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt">Find Study Buddies</div>
        <div class="mx" onclick="closeModal('findBuddiesModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;gap:7px;margin-bottom:13px"><select class="fi">
            <option>CS 305 — Neural Networks</option>
            <option>CS 201</option>
          </select><button class="btn-primary" onclick="toast('Finding matches...','info','🔍')">🔍 Find</button></div>
      </div>
    </div>
  </div>
  <div class="mo" id="helpArticleModal">
    <div class="md md-md">
      <div class="mh">
        <div class="mt" id="haTitle">Help Article</div>
        <div class="mx" onclick="closeModal('helpArticleModal')">✕</div>
      </div>
      <div class="mb">
        <div id="haBody" style="font-size:12.5px;color:var(--muted2);line-height:1.7">Loading...</div>
      </div>
      <div class="mf"><button class="btn-sec" onclick="closeModal('helpArticleModal')">Close</button></div>
    </div>
  </div>
  <div class="mo" id="moreTagsModal">
    <div class="md md-sm">
      <div class="mh">
        <div class="mt">More Categories</div>
        <div class="mx" onclick="closeModal('moreTagsModal')">✕</div>
      </div>
      <div class="mb">
        <div style="display:flex;flex-wrap:wrap;gap:7px"><span class="tf" onclick="closeModal('moreTagsModal');filterServers(this,'math')">Mathematics</span><span class="tf" onclick="closeModal('moreTagsModal');filterServers(this,'sec')">Security</span><span class="tf" onclick="closeModal('moreTagsModal');filterServers(this,'mob')">Mobile Dev</span><span class="tf" onclick="closeModal('moreTagsModal');filterServers(this,'game')">Game Dev</span></div>
      </div>
    </div>
  </div>

  <div class="toast-container" id="tc"></div>

  <!-- Inject live data for JS -->
  <script>
    var DASH_DATA = <?= json_encode([
                      'activityData'   => $dashData['activity_chart'] ?? [2, 3.5, 2.5, 3.5, 6.4, 1.8, 0.8],
                      'courseLabels'   => array_map(fn($c) => $c['course_code'] ?? 'Course', $dashData['courses'] ?? []),
                      'courseHours'    => array_map(fn($c) => (float)($c['hours_spent'] ?? rand(1, 8)), $dashData['courses'] ?? []),
                      'notifCount'     => $dashData['unread_notifications'] ?? 3,
                      'userId'         => $user['id'],
                      'csrfToken'      => $csrfToken,
                    ], JSON_HEX_TAG) ?>;

    // Defaults for empty data
    if (!DASH_DATA.activityData.length) DASH_DATA.activityData = [2, 3.5, 2.5, 3.5, 6.4, 1.8, 0.8];
    if (!DASH_DATA.courseLabels.length) DASH_DATA.courseLabels = ['CS 305', 'CS 201', 'CS 210', 'CS 410', 'CS 101'];
    if (!DASH_DATA.courseHours.length) DASH_DATA.courseHours = [7.2, 4.1, 3.5, 2.8, 1.0];
  </script>

  <script src="<?= BASE_URL ?>/assets/js/student/dashboard.js" defer></script>
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