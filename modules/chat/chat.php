<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();

$csrfToken = AuthMiddleware::csrfToken();

$channelService = new ChannelService();
$servers        = $channelService->getServersForUser($user['id']);

// Deep-link support: dashboards ("My Servers & Channels" cards) can
// link directly to a specific server via ?server_id=. Only honour
// it if the user is actually a member of that server (otherwise
// fall back to the default first server).
$requestedServerId = (int)($_GET['server_id'] ?? 0);
$firstServer = null;
if ($requestedServerId > 0) {
  foreach ($servers as $srv) {
    if ((int)$srv['id'] === $requestedServerId) {
      $firstServer = $srv;
      break;
    }
  }
}
$firstServer    = $firstServer ?? ($servers[0] ?? null);
$channels       = $firstServer ? $channelService->getChannelsForUser((int)$firstServer['id'], $user['id']) : [];

// Gradient first color for current user avatar
$gradColors  = explode(',', $user['avatar_color_gradient'] ?? '#a855f7,#ec4899');
$avatarStyle = 'background:linear-gradient(135deg,' . htmlspecialchars($gradColors[0]) . ',' . htmlspecialchars($gradColors[1] ?? $gradColors[0]) . ')';
$initials    = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ecollab — Chat</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/chat.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/whiteboard.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/whiteboard-mobile.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/collab-tools.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/peer-matching.css">
  <style>
    #wbOverlay {
      display: none !important;
    }
  </style>
</head>

<body>

  <!-- MOBILE OVERLAY -->
  <div class="mobile-overlay" id="mobileOverlay" onclick="closeSidebar()"></div>

  <!-- MOBILE TOPBAR -->
  <div class="mobile-topbar" id="mobileTopbar">
    <div class="mob-hamburger" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </div>
    <div class="mob-channel-info">
      <span style="color:var(--accent-purple);font-size:16px;">#</span>
      <span class="mob-channel-name" id="mobChannelName">channels</span>
    </div>
    <div class="mob-right-btn" onclick="toggleNotifications()" title="Notifications">&#x1F514;</div>
  </div>

  <!-- WORKSPACE SWITCHER -->
  <div class="workspace-switcher" id="workspaceSwitcher">
    <?php foreach ($servers as $idx => $srv): ?>
      <div class="workspace-icon<?= $idx === 0 ? ' active' : '' ?>"
        data-server-id="<?= (int)$srv['id'] ?>"
        data-ws="<?= $idx ?>"
        onclick="switchWorkspace(<?= $idx ?>, <?= (int)$srv['id'] ?>)"
        title="<?= htmlspecialchars($srv['name']) ?>">
        <?= htmlspecialchars($srv['icon_emoji'] ?: strtoupper(substr($srv['name'], 0, 1))) ?>
      </div>
    <?php endforeach; ?>
    <div class="workspace-sep"></div>
    <div class="workspace-add" onclick="openModal('addServerModal')" title="Add Workspace">+</div>
  </div>

  <!-- LEFT SIDEBAR -->
  <div class="sidebar-left" id="sidebarLeft">
    <div class="sidebar-workspace-header" id="wsHeader" onclick="openUserSettings()">
      <div class="ws-icon" id="wsIcon"><?= htmlspecialchars($firstServer['icon_emoji'] ?? '⭐') ?></div>
      <div class="ws-name" id="wsName"><?= htmlspecialchars($firstServer['name'] ?? 'Ecollab') ?></div>
      <div class="ws-chevron">▾</div>
    </div>

    <div class="sidebar-search">
      <div class="sidebar-search-wrap">
        <span class="sidebar-search-icon">🔍</span>
        <input type="text" id="sidebarSearch" placeholder="Search" oninput="filterSidebar(this.value)" />
        <span class="search-kbd">⌘K</span>
      </div>
    </div>

    <div class="sidebar-scroll">
      <div class="sidebar-nav">
        <div class="sidebar-nav-item active" onclick="switchView('home', this)">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" />
          </svg>
          Home
        </div>
        <div class="sidebar-nav-item" onclick="switchView('mentions', this)">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10h5v-2h-5c-4.34 0-8-3.66-8-8s3.66-8 8-8 8 3.66 8 8v1.43c0 .79-.71 1.57-1.5 1.57s-1.5-.78-1.5-1.57V12c0-2.76-2.24-5-5-5s-5 2.24-5 5 2.24 5 5 5c1.38 0 2.64-.56 3.54-1.47.65.89 1.77 1.47 2.96 1.47 1.97 0 3.5-1.6 3.5-3.57V12c0-5.52-4.48-10-10-10zm0 13c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z" />
          </svg>
          Mentions
        </div>
        <div class="sidebar-nav-item" onclick="switchView('bookmarks', this)">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z" />
          </svg>
          Bookmarks
        </div>
        <div class="sidebar-nav-item" onclick="switchView('threads', this)">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" />
          </svg>
          Threads
        </div>
        <div class="sidebar-nav-item" onclick="switchView('drafts', this)">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zM6 20V4h7v5h5v11H6z" />
          </svg>
          Drafts
        </div>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-header">
          <span class="sidebar-section-title">Channels</span>
          <span class="sidebar-section-add" onclick="openAddChannelModal('text')">+</span>
        </div>
        <div id="channelList">
          <?php foreach ($channels as $ch): if ($ch['type'] !== 'voice'): ?>
              <?php $isNew = !empty($ch['is_new']) && $ch['is_new'] == 1; ?>
              <div class="channel-item <?= $ch === reset($channels) ? 'active' : '' ?>"
                data-channel-id="<?= (int)$ch['id'] ?>"
                data-channel-name="<?= htmlspecialchars($ch['name']) ?>"
                <?= $isNew ? 'data-is-new="1"' : '' ?>
                onclick="switchChannel(this, <?= (int)$ch['id'] ?>)">
                <?php if ($ch['type'] === 'announcement'): ?>
                  <svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24" style="color:var(--accent-yellow);flex-shrink:0;margin-right:2px;">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6V11c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" />
                  </svg>
                <?php elseif (!empty($ch['is_private'])): ?>
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" style="color:var(--text-muted);flex-shrink:0;margin-right:2px;" title="Private channel">
                    <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                  </svg>
                <?php else: ?>
                  <span class="channel-hash">#</span>
                <?php endif; ?>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                  <?= htmlspecialchars($ch['name']) ?>
                  <?php if ($isNew): ?>
                    <span class="ch-new-badge" style="font-size:9px;background:rgba(168,85,247,0.18);color:#c084fc;border-radius:4px;padding:1px 5px;font-weight:700;vertical-align:middle;">new</span>
                  <?php endif; ?>
                </span>
                <?php if ((int)$ch['unread_count'] > 0): ?>
                  <span class="channel-unread"><?= (int)$ch['unread_count'] ?></span>
                <?php endif; ?>
              </div>
          <?php endif;
          endforeach; ?>
        </div>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-header">
          <span class="sidebar-section-title">Voice Channels</span>
          <span class="sidebar-section-add" onclick="openAddChannelModal('voice')">+</span>
        </div>
        <div id="voiceChannelList">
          <?php foreach ($channels as $ch): if ($ch['type'] === 'voice'): ?>
              <div class="voice-channel" data-channel-id="<?= (int)$ch['id'] ?>" data-vc="<?= htmlspecialchars($ch['slug']) ?>"
                onclick="joinVoice('<?= htmlspecialchars($ch['slug']) ?>', this, <?= (int)$ch['id'] ?>)">
                <svg width="15" height="15" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" />
                </svg>
                <?= htmlspecialchars($ch['name']) ?>
                <span class="vc-count"><?= (int)$ch['member_count'] ?></span>
              </div>
          <?php endif;
          endforeach; ?>
        </div>
      </div>


      <div class="sidebar-section" id="whiteboardSection">
        <div class="sidebar-section-header">
          <span class="sidebar-section-title">Whiteboard Channels</span>
          <span class="sidebar-section-add" onclick="openAddChannelModal('whiteboard')" title="Add whiteboard channel">+</span>
        </div>
        <div id="whiteboardChannelList">
          <?php foreach ($channels as $ch): if ($ch['type'] === 'whiteboard'): ?>
              <div class="channel-item wb-channel-item" data-channel-id="<?= (int)$ch['id'] ?>" data-channel-name="<?= htmlspecialchars($ch['name']) ?>"
                onclick="openWhiteboardChannel(<?= (int)$ch['id'] ?>, '<?= htmlspecialchars($ch['name']) ?>')" title="Open <?= htmlspecialchars($ch['name']) ?>">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" style="color:var(--accent-purple);flex-shrink:0;">
                  <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
                </svg>
                <span class="channel-name" style="flex:1;"><?= htmlspecialchars($ch['name']) ?></span>
                <span class="wb-live-badge" style="display:none;">
                  <span class="wb-live-dot"></span>Live
                </span>
              </div>
          <?php endif;
          endforeach; ?>
        </div>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-header">
          <span class="sidebar-section-title">Direct Messages</span>
          <span class="sidebar-section-add" onclick="openNewDMModal()">+</span>
        </div>
        <div id="dmList"></div>
      </div>
    </div>

    <!-- VOICE CONNECTED BAR (shown when in a voice channel) -->
    <div class="vc-connected-bar" id="vcConnectedBar" style="display:none;">
      <div class="vcb-status">
        <span class="vcb-dot"></span>
        <span class="vcb-label">Voice Connected</span>
      </div>
      <div class="vcb-room" id="vcbRoomName">â</div>
      <div class="vcb-actions">
        <div class="vcb-btn" onclick="toggleVcPanelFromBar()" title="Open Voice Panel">
          <svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z" />
          </svg>
        </div>
        <div class="vcb-btn vcb-leave" onclick="leaveVoice()" title="Disconnect">
          <svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24">
            <path d="M6.62 10.79a15.05 15.05 0 006.59 6.59l2.2-2.2a1 1 0 011.01-.24c1.12.37 2.33.57 3.58.57a1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1c0 1.25.2 2.45.57 3.57a1 1 0 01-.24 1.01l-2.21 2.21z" />
          </svg>
        </div>
      </div>
    </div>

    <!-- USER PROFILE FOOTER -->
    <div class="user-profile-footer" onclick="openUserSettings()">
      <div class="user-avatar">
        <div class="avatar-placeholder avatar-lg" style="<?= $avatarStyle ?>;border-radius:50%;">
          <?= htmlspecialchars($initials) ?>
        </div>
        <div class="online-dot"></div>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          <?= htmlspecialchars($user['username']) ?>
        </div>
        <div style="font-size:11px;color:var(--accent-green);">● Online</div>
      </div>
      <div style="display:flex;gap:6px;">
        <div class="footer-icon-btn" onclick="toggleMute(event)" title="Mute" id="muteBtn">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
        </div>
        <div class="footer-icon-btn" onclick="toggleDeafen(event)" title="Deafen" id="deafenBtn">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z" />
          </svg>
        </div>
        <div class="footer-icon-btn" onclick="openUserSettings()" title="Settings">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M19.14,12.94c0.04-0.3,0.06-0.61,0.06-0.94c0-0.32-0.02-0.64-0.07-0.94l2.03-1.58c0.18-0.14,0.23-0.41,0.12-0.61 l-1.92-3.32c-0.12-0.22-0.37-0.29-0.59-0.22l-2.39,0.96c-0.5-0.38-1.03-0.7-1.62-0.94L14.4,2.81c-0.04-0.24-0.24-0.41-0.48-0.41 h-3.84c-0.24,0-0.43,0.17-0.47,0.41L9.25,5.35C8.66,5.59,8.12,5.92,7.63,6.29L5.24,5.33c-0.22-0.08-0.47,0-0.59,0.22L2.74,8.87 C2.62,9.08,2.66,9.34,2.86,9.48l2.03,1.58C4.84,11.36,4.8,11.69,4.8,12s0.02,0.64,0.07,0.94l-2.03,1.58 c-0.18,0.14-0.23,0.41-0.12,0.61l1.92,3.32c0.12,0.22,0.37,0.29,0.59,0.22l2.39-0.96c0.5,0.38,1.03,0.7,1.62,0.94l0.36,2.54 c0.05,0.24,0.24,0.41,0.48,0.41h3.84c0.24,0,0.44-0.17,0.47-0.41l0.36-2.54c0.59-0.24,1.13-0.56,1.62-0.94l2.39,0.96 c0.22,0.08,0.47,0,0.59-0.22l1.92-3.32c0.12-0.22,0.07-0.47-0.12-0.61L19.14,12.94z M12,15.6c-1.98,0-3.6-1.62-3.6-3.6 s1.62-3.6,3.6-3.6s3.6,1.62,3.6,3.6S13.98,15.6,12,15.6z" />
          </svg>
        </div>
        <div class="footer-icon-btn" onclick="handleLogout()" title="Log Out" style="color:#ef4444;">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
          </svg>
        </div>
      </div>
    </div>
  </div>

  <!-- CHAT MAIN -->
  <div class="chat-main" id="chatMain">

    <!-- CHAT HEADER -->
    <div class="chat-header">
      <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
        <span style="font-size:22px;color:var(--text-muted);font-weight:300;">#</span>
        <div>
          <div style="font-size:15px;font-weight:700;color:var(--text-primary);line-height:1.2;" id="channelTitle">Select a channel</div>
          <div class="channel-desc" id="channelDesc" style="font-size:12px;color:var(--text-muted);"></div>
        </div>
      </div>
      <div class="header-sep"></div>
      <div style="display:flex;align-items:center;gap:4px;">
        <!-- Manage private channel members - shown only when current channel is private and user is owner/admin -->
        <button class="header-icon-btn" id="manageChannelBtn" onclick="openPrivateChannelManager()" title="Manage Channel Members" style="display:none;">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
          </svg>
        </button>
        <button class="header-icon-btn header-search" onclick="openSearchModal()" title="Search">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
          </svg>
        </button>
        <button class="header-icon-btn" onclick="togglePinnedMessages()" title="Pinned Messages">
          📌
        </button>
        <button class="collab-open-btn" onclick="openCollabHub()" title="Collaboration Tools">
          🤝 <span>Collab</span>
        </button>
        <button class="collab-open-btn" onclick="openPeerMatchingModal()" title="Find Study Partners" style="background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.25);color:#60a5fa;">
          🔍 <span>Match</span>
        </button>
        <button class="header-icon-btn header-members" onclick="openMembersPanel()" title="Members">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
          </svg>
        </button>
        <div class="notif-wrap" style="position:relative;">
          <button class="header-icon-btn" id="notifBtn" onclick="toggleNotifications()" title="Notifications">
            <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" />
            </svg>
            <span class="notif-badge" id="notifBadge" style="display:none;">3</span>
          </button>
          <div class="notif-dropdown" id="notifDropdown" style="display:none;">
            <div class="notif-header">
              <div class="notif-title">Notifications</div>
              <div class="notif-mark-read" onclick="markAllRead()">Mark all read</div>
            </div>
            <div id="notifList">
              <div class="notif-item unread">
                <div class="notif-dot"></div>
                <div class="notif-content">
                  <div class="notif-text"><strong>John Doe</strong> replied to your message</div>
                  <div class="notif-time">2 min ago</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MESSAGES AREA -->
    <div class="messages-area" id="messagesArea">
      <div class="messages-placeholder" id="messagesPlaceholder">
        <div style="text-align:center;padding:60px 20px;">
          <div style="font-size:48px;margin-bottom:16px;">💬</div>
          <div style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:8px;">Select a channel to start chatting</div>
          <div style="font-size:13px;color:var(--text-muted);">Choose a channel from the sidebar to view messages.</div>
        </div>
      </div>
      <!-- Typing indicator -->
      <div class="typing-indicator" id="typingIndicator" style="display:none;">
        <div class="typing-dots">
          <div class="typing-dot"></div>
          <div class="typing-dot"></div>
          <div class="typing-dot"></div>
        </div>
        <span id="typingText">Someone is typing...</span>
      </div>
    </div>

    <!-- CHAT INPUT -->
    <div class="chat-input-wrapper" id="chatInputWrapper" style="display:none;">

      <!-- ATTACHMENT MENU -->
      <div class="attach-menu" id="attachMenu">
        <div class="attach-section-label">Upload from device</div>
        <div class="attach-item" onclick="triggerFileInput('fileMedia')">
          <div class="a-icon a-blue">🖼️</div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Images &amp; Videos</div>
            <div style="font-size:11px;color:var(--text-muted);">JPG, PNG, MP4, GIF…</div>
          </div>
        </div>
        <div class="attach-item" onclick="triggerFileInput('fileDoc')">
          <div class="a-icon a-purple">📄</div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Files &amp; Documents</div>
            <div style="font-size:11px;color:var(--text-muted);">PDF, DOCX, ZIP…</div>
          </div>
        </div>
        <div class="attach-sep"></div>
        <div class="attach-item" onclick="triggerFileInput('fileAudio')">
          <div class="a-icon a-green">🎵</div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Audio File</div>
            <div style="font-size:11px;color:var(--text-muted);">MP3, WAV, M4A…</div>
          </div>
        </div>
      </div>

      <!-- EXTRAS MENU -->
      <div class="extras-menu" id="extrasMenu">
        <div class="attach-section-label">Create</div>
        <div class="attach-item" onclick="openExtrasAction('poll')">
          <div class="a-icon a-blue">📊</div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Create a Poll</div>
            <div style="font-size:11px;color:var(--text-muted);">Gather votes from members</div>
          </div>
        </div>
        <div class="attach-item" onclick="openExtrasAction('code')">
          <div class="a-icon a-orange">💻</div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Code Snippet</div>
            <div style="font-size:11px;color:var(--text-muted);">Share formatted code</div>
          </div>
        </div>
      </div>

      <!-- EMOJI PICKER -->
      <div class="emoji-picker" id="emojiPicker" style="bottom:calc(100% + 8px);right:0;left:auto;"></div>

      <!-- HIDDEN FILE INPUTS -->
      <input type="file" id="fileMedia" accept="image/*,video/*" multiple style="position:fixed;top:-9999px;left:-9999px;opacity:0;" onchange="handleFileUpload(this,'media')">
      <input type="file" id="fileDoc" multiple style="position:fixed;top:-9999px;left:-9999px;opacity:0;" onchange="handleFileUpload(this,'file')">
      <input type="file" id="fileAudio" accept="audio/*" style="position:fixed;top:-9999px;left:-9999px;opacity:0;" onchange="handleFileUpload(this,'audio')">

      <!-- REPLY BAR -->
      <div id="replyBar" style="display:none;align-items:center;gap:10px;background:rgba(168,85,247,0.07);border:1px solid rgba(168,85,247,0.22);border-radius:10px 10px 0 0;padding:8px 14px;margin-bottom:-2px;position:relative;">
        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" style="color:var(--accent-purple);flex-shrink:0;">
          <path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" />
        </svg>
        <div style="flex:1;min-width:0;">
          <div style="font-size:11px;font-weight:700;color:var(--accent-purple);margin-bottom:1px;">Replying to <span id="replyAuthor"></span></div>
          <div id="replyPreview" style="font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:400px;"></div>
        </div>
        <button onclick="cancelReply()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;line-height:1;padding:2px 4px;border-radius:4px;transition:0.12s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--text-muted)'">×</button>
      </div>

      <!-- INPUT CONTAINER -->
      <div class="chat-input-container" id="chatInputContainer">
        <div class="chat-input-main">
          <button class="input-btn-icon" id="attachBtn" title="Attach files" onclick="toggleAttachMenu(event)">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="16" />
              <line x1="8" y1="12" x2="16" y2="12" />
            </svg>
          </button>
          <button class="input-btn-icon" id="extrasBtn" title="More options" onclick="toggleExtrasMenu(event)">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
              <circle cx="5" cy="12" r="1.9" />
              <circle cx="12" cy="12" r="1.9" />
              <circle cx="19" cy="12" r="1.9" />
            </svg>
          </button>
          <input class="chat-input-field" id="chatInputField" placeholder="Message…" oninput="handleTyping(event)" onkeydown="handleKeyDown(event)" />
          <!-- GIF PICKER -->
          <div class="gif-picker" id="gifPicker" style="position:absolute;bottom:calc(100% + 6px);right:160px;left:auto;">
            <input class="gif-search" placeholder="Search GIFs..." oninput="filterGifs(this.value)" />
            <div class="gif-grid" id="gifGrid"></div>
          </div>
          <button class="input-btn-icon" id="emojiBtn" title="Emoji" onclick="toggleEmojiPicker(event)">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10" />
              <path d="M8 14s1.5 2 4 2 4-2 4-2" />
              <line x1="9" y1="9" x2="9.01" y2="9" />
              <line x1="15" y1="9" x2="15.01" y2="9" />
            </svg>
          </button>
          <button class="input-btn-icon" id="gifBtn" title="GIF" onclick="toggleGifPicker(event)" style="font-size:12px;font-weight:800;letter-spacing:-0.5px;color:var(--text-muted);">GIF</button>
          <button class="input-btn-icon" id="micBtn" title="Voice message" onclick="toggleVoiceRecord()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" />
              <path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
          </button>
        </div>

        <!-- VOICE RECORDING BAR -->
        <div id="voiceRecordBar" style="display:none;align-items:center;gap:10px;padding:8px 12px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:10px;margin:0 8px 6px;">
          <div style="width:10px;height:10px;border-radius:50%;background:#ef4444;animation:pulse 1s infinite;flex-shrink:0;"></div>
          <span id="recTimer" style="font-size:13px;font-weight:700;color:#ef4444;font-variant-numeric:tabular-nums;min-width:34px;">0:00</span>
          <div id="recWaveform" style="flex:1;display:flex;align-items:center;gap:2px;height:24px;overflow:hidden;"></div>
          <button onclick="cancelRecording()" title="Cancel" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:2px 4px;line-height:1;">✕</button>
          <button onclick="stopRecordingToPreview()" title="Stop & Preview" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:8px;color:#ef4444;cursor:pointer;font-size:12px;font-weight:700;padding:5px 12px;font-family:'Inter',sans-serif;">■ Stop</button>
        </div>

        <!-- VOICE PREVIEW BAR -->
        <div id="voicePreviewBar" style="display:none;align-items:center;gap:10px;padding:8px 12px;background:rgba(168,85,247,0.06);border:1px solid rgba(168,85,247,0.2);border-radius:10px;margin:0 8px 6px;">
          <button onclick="togglePreviewPlay()" style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#a855f7,#ec4899);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg id="previewPlayIcon" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
              <path d="M8 5v14l11-7z" />
            </svg>
          </button>
          <span id="previewCurrentTime" style="font-size:11px;color:var(--text-muted);font-variant-numeric:tabular-nums;min-width:28px;">0:00</span>
          <div id="previewWaveStatic" style="flex:1;display:flex;align-items:center;gap:2px;height:20px;overflow:hidden;cursor:pointer;" onclick="scrubPreview(event,this)">
            <div id="previewProgress" style="height:3px;background:linear-gradient(135deg,#a855f7,#ec4899);border-radius:99px;width:0%;transition:width 0.1s linear;position:absolute;"></div>
          </div>
          <span id="previewDuration" style="font-size:11px;color:var(--text-muted);min-width:28px;">0:00</span>
          <button onclick="reRecord()" title="Re-record" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:2px 4px;" title="Record again">🔄</button>
          <button onclick="discardRecording()" title="Discard" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px;padding:2px 4px;">🗑</button>
          <button onclick="sendRecording()" style="background:linear-gradient(135deg,#a855f7,#ec4899);border:none;border-radius:8px;color:#fff;cursor:pointer;font-size:12px;font-weight:700;padding:5px 12px;font-family:'Inter',sans-serif;">Send 🎤</button>
        </div>

        <div class="chat-input-actions">
          <div class="input-spacer"></div>
          <button class="find-partner-btn" onclick="openFullMatchesModal()">🔍 Find Study Partner</button>
          <button class="ai-assist-btn" id="aiAssistBtn" onclick="generateAIReply()">✨ AI Assist <span class="ai-assist-chevron">▾</span></button>
          <button class="send-btn" onclick="sendMessage()">Send</button>
        </div>
      </div>
    </div>

  </div><!-- end chat-main -->

  <!-- VOICE CHANNEL VIEW -->
  <div class="voice-channel-view" id="voiceChannelView">
    <div class="vc-ambient vc-ambient-1"></div>
    <div class="vc-ambient vc-ambient-2"></div>
    <div class="vc-ambient vc-ambient-3"></div>
    <div class="vc-header">
      <div class="vc-header-icon">
        <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
          <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" />
        </svg>
      </div>
      <div class="vc-header-meta">
        <div class="vc-header-title" id="vcRoomTitle">Voice Channel</div>
        <div class="vc-header-subtitle" id="vcRoomSubtitle">0 participants</div>
      </div>
      <div class="vc-header-right">
        <div class="vc-stat-badge speaking-badge" id="vcSpeakingBadge" style="display:none;">
          <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 9v6h4l5 5V4L7 9H3z" />
          </svg>
          <span id="vcSpeakingCount">1</span> Speaking
        </div>
        <div class="vc-stat-badge">
          <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24">
            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
          </svg>
          <span id="vcMemberCount">0</span>
        </div>
        <button class="vc-invite-btn" onclick="openModal('vcInviteModal')">
          <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z" />
          </svg>
          Invite
        </button>
        <div class="vc-icon-btn" onclick="leaveVoice()" title="Leave Voice">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
          </svg>
        </div>
      </div>
    </div>
    <div class="vc-body">
      <div class="vc-room-info">
        <div class="vc-room-icon">
          <svg width="36" height="36" fill="#fff" viewBox="0 0 24 24">
            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" />
          </svg>
        </div>
        <div class="vc-room-details">
          <div class="vc-room-title" id="vcRoomTitleLarge">Study Lounge</div>
          <div class="vc-room-sub" id="vcRoomSubLarge">Voice Channel</div>
          <div class="vc-tags">
            <div class="vc-tag">🔊 Voice</div>
            <div class="vc-tag">🟢 Active</div>
          </div>
        </div>
      </div>
      <div class="vc-section-header" id="vcScreenSection" style="display:none;">
        <div class="vc-section-label">Screen Share</div>
        <div class="vc-section-line"></div>
        <div class="vc-section-count" id="vcScreenSectionCount">0</div>
      </div>
      <div class="vc-screen-grid" id="vcScreenGrid"></div>
      <div class="vc-section-header">
        <div class="vc-section-label">Speaking</div>
        <div class="vc-section-line"></div>
        <div class="vc-section-count" id="vcSpeakingSection">0</div>
      </div>
      <div class="vc-speaking-grid" id="vcSpeakingGrid"></div>
      <div class="vc-section-header">
        <div class="vc-section-label">Listening</div>
        <div class="vc-section-line"></div>
        <div class="vc-section-count" id="vcListeningSection">0</div>
      </div>
      <div class="vc-listening-grid" id="vcListeningGrid"></div>
    </div>
    <div class="vc-controls-bar">
      <div class="vc-bar-grp">
        <div class="vc-ctrl-btn" id="vcScreenBtn" onclick="toggleScreenShare()" title="Screen Share">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M20 18c1.1 0 1.99-.9 1.99-2L22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zM4 6h16v10H4V6z" />
          </svg>
          <span class="vc-ctrl-tooltip">Screen Share</span>
        </div>
        <div class="vc-ctrl-btn" id="vcCamBtn" onclick="toggleCamera()" title="Camera">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z" />
          </svg>
          <span class="vc-ctrl-tooltip">Camera</span>
        </div>
        <div class="vc-ctrl-btn" id="vcWbBtn" onclick="openWhiteboard()" title="Whiteboard">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
          </svg>
          <span class="vc-ctrl-tooltip">Whiteboard</span>
        </div>
      </div>
      <div class="vc-bar-grp center">
        <div class="vc-btn-lg mic-btn" id="vcMicBtn" onclick="toggleVcMic()">
          <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
          <span class="vc-ctrl-tooltip">Mute</span>
        </div>
        <div class="vc-btn-lg leave-btn" onclick="leaveVoice()">
          <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
            <path d="M20.384 15.604a1.562 1.562 0 0 0-.032-.317c-.116-.52-.576-.96-1.44-1.344-.288-.128-.624-.256-1.008-.384l-.752-.272c-.912-.32-1.52-.528-1.824-.624a4.222 4.222 0 0 0-1.168-.176c-.56 0-.992.24-1.296.72-.096.144-.208.352-.336.624-.128.256-.24.464-.336.608-.16.24-.352.368-.576.368-.128 0-.272-.04-.416-.112-.048-.016-.128-.064-.256-.128-1.504-.8-2.672-1.936-3.504-3.44-.08-.144-.128-.224-.128-.272-.08-.16-.112-.304-.112-.432 0-.224.12-.416.368-.576.144-.08.352-.192.608-.32.256-.128.464-.24.624-.352.48-.32.72-.752.72-1.296 0-.384-.064-.8-.176-1.264-.096-.304-.304-.896-.624-1.776l-.272-.768c-.128-.384-.256-.72-.384-1.008-.384-.88-.816-1.344-1.344-1.44a1.562 1.562 0 0 0-.32-.032c-.544 0-1.168.2-1.872.592C5.456 3.8 4.864 4.36 4.496 5.08c-.4.8-.592 1.6-.592 2.4 0 .384.032.752.112 1.12.464 2.128 1.68 4.256 3.664 6.24 1.952 1.952 4.064 3.168 6.224 3.632.384.08.752.128 1.12.128.8 0 1.6-.192 2.4-.576.72-.352 1.28-.944 1.648-1.776.384-.704.576-1.328.576-1.888-.016-.256-.016-.544-.264-.756z" />
          </svg>
          <span class="vc-ctrl-tooltip">Leave Call</span>
        </div>
        <div class="vc-btn-lg deaf-btn" id="vcDeafBtn" onclick="toggleVcDeafen()">
          <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z" />
          </svg>
          <span class="vc-ctrl-tooltip">Deafen</span>
        </div>
      </div>
      <div class="vc-bar-grp">
        <div class="vc-ctrl-btn" onclick="openMicTest()" title="Mic Test">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            <line x1="12" y1="19" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            <line x1="8" y1="23" x2="16" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
          <span class="vc-ctrl-tooltip">Mic Test</span>
        </div>
        <div class="vc-ctrl-btn" onclick="openModal('vcNoiseCancelModal')" title="Noise Cancellation">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M4.5 11h-2C2.5 6.31 6.31 2.5 11 2.5v2C7.41 4.5 4.5 7.41 4.5 11zm17 0h-2c0-5.24-4.26-9.5-9.5-9.5v-2c6.35 0 11.5 5.15 11.5 11.5zM12 22c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6.5-2.5c0 3.59-2.91 6.5-6.5 6.5s-6.5-2.91-6.5-6.5H7c0 2.76 2.24 5 5 5s5-2.24 5-5h1.5z" />
          </svg>
          <span class="vc-ctrl-tooltip">Noise Cancel</span>
        </div>
        <div class="vc-ctrl-btn" onclick="openAudioSettings ? openAudioSettings() : openModal('vcAudioSettingsModal')" title="Audio Settings">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
            <path d="M19.14,12.94c0.04-0.3,0.06-0.61,0.06-0.94c0-0.32-0.02-0.64-0.07-0.94l2.03-1.58c0.18-0.14,0.23-0.41,0.12-0.61l-1.92-3.32c-0.12-0.22-0.37-0.29-0.59-0.22l-2.39,0.96c-0.5-0.38-1.03-0.7-1.62-0.94L14.4,2.81c-0.04-0.24-0.24-0.41-0.48-0.41h-3.84c-0.24,0-0.43,0.17-0.47,0.41L9.25,5.35C8.66,5.59,8.12,5.92,7.63,6.29L5.24,5.33c-0.22-0.08-0.47,0-0.59,0.22L2.74,8.87C2.62,9.08,2.66,9.34,2.86,9.48l2.03,1.58C4.84,11.36,4.8,11.69,4.8,12s0.02,0.64,0.07,0.94l-2.03,1.58c-0.18,0.14-0.23,0.41-0.12,0.61l1.92,3.32c0.12,0.22,0.37,0.29,0.59,0.22l2.39-.96c0.5,0.38,1.03,0.7,1.62,0.94l0.36,2.54c0.05,0.24,0.24,0.41,0.48,0.41h3.84c0.24,0,0.44-0.17,0.47-0.41l0.36-2.54c0.59-0.24,1.13-0.56,1.62-0.94l2.39,0.96c0.22,0.08,0.47,0,0.59-0.22l1.92-3.32c0.12-0.22,0.07-0.47-0.12-0.61L19.14,12.94z M12,15.6c-1.98,0-3.6-1.62-3.6-3.6s1.62-3.6,3.6-3.6s3.6,1.62,3.6,3.6S13.98,15.6,12,15.6z" />
          </svg>
          <span class="vc-ctrl-tooltip">Audio Settings</span>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT SIDEBAR -->
  <div class="sidebar-right" id="sidebarRight">
    <div class="right-panel">
      <div class="panel-title" style="display:flex;align-items:center;justify-content:space-between;">
        Active Now
        <span id="activeNowCountBadge" style="font-size:11px;font-weight:700;background:rgba(34,197,94,0.15);color:#22c55e;padding:2px 8px;border-radius:99px;min-width:20px;text-align:center;">0</span>
      </div>
      <div id="activeMembersList" style="display:flex;flex-direction:column;gap:3px;margin-bottom:8px;max-height:220px;overflow-y:auto;"></div>
      <button class="see-all-btn" onclick="openActiveNowModal()">See All</button>
    </div>
    <div class="right-panel">
      <div class="matches-header">
        <div class="matches-title">AI Suggested Matches ✨</div>
        <button class="refresh-btn" onclick="refreshMatches(this)">Refresh</button>
      </div>
      <div id="matchesList"></div>
      <button class="see-all-btn" style="margin-top:10px;" onclick="openFullMatchesModal()">See All Matches</button>
    </div>
    <div class="right-panel">
      <div class="panel-title">
        Members
        <span class="panel-badge" id="memberCountBadge"></span>
        <span style="margin-left:auto;font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;color:var(--accent-purple);" onclick="filterMembers('online')">Filter</span>
      </div>
      <div id="membersList"></div>
    </div>
  </div>

  <!-- PINNED MESSAGES MODAL -->
  <div class="modal-overlay" id="pinnedModal" onclick="if(event.target===this)closeModal('pinnedModal')">
    <div class="modal" style="max-width:520px;width:100%;max-height:80vh;">
      <div class="modal-header">
        <span style="font-size:16px;font-weight:800;">📌 Pinned Messages</span>
        <button class="modal-close" onclick="closeModal('pinnedModal')">×</button>
      </div>
      <div class="modal-body" style="padding:12px 16px;overflow-y:auto;max-height:60vh;" id="pinnedList">
        <div style="text-align:center;padding:30px;color:var(--text-muted);">No pinned messages</div>
      </div>
    </div>
  </div>

  <!-- ADD CHANNEL MODAL -->
  <div class="modal-overlay" id="addChannelModal" onclick="if(event.target===this)closeModal('addChannelModal')">
    <div class="modal" style="max-width:420px;width:100%;">
      <div class="modal-header">
        <span style="font-size:16px;font-weight:800;">Create Channel</span>
        <button class="modal-close" onclick="closeModal('addChannelModal')">×</button>
      </div>
      <div class="modal-body" style="padding:16px 20px;">
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Channel Type</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <div class="channel-type-opt active" data-type="text" onclick="selectChannelType(this,'text')" style="flex:1;min-width:100px;padding:10px 12px;border:1px solid rgba(168,85,247,0.4);border-radius:8px;cursor:pointer;background:rgba(168,85,247,0.08);">
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);"># Text</div>
              <div style="font-size:11px;color:var(--text-muted);">Send messages, images, links</div>
            </div>
            <div class="channel-type-opt" data-type="voice" onclick="selectChannelType(this,'voice')" style="flex:1;min-width:100px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;">
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);">🔊 Voice</div>
              <div style="font-size:11px;color:var(--text-muted);">Hang out with voice &amp; video</div>
            </div>
            <div class="channel-type-opt" data-type="whiteboard" onclick="selectChannelType(this,'whiteboard')" style="flex:1;min-width:100px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;">
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);">✏️ Whiteboard</div>
              <div style="font-size:11px;color:var(--text-muted);">Collaborate on a canvas</div>
            </div>
          </div>
        </div>
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Channel Name</label>
          <input type="text" id="newChannelName" placeholder="new-channel" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:13px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;" oninput="this.value=this.value.toLowerCase().replace(/\s+/g,'-').replace(/[^a-z0-9-]/g,'')">
        </div>
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Description (optional)</label>
          <input type="text" id="newChannelDesc" placeholder="What's this channel about?" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:13px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--bg-tertiary);border-radius:8px;border:1px solid var(--border);">
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Private Channel</div>
            <div style="font-size:11px;color:var(--text-muted);">Only selected members can access</div>
          </div>
          <div class="toggle-switch" id="privateChannelToggle" onclick="this.classList.toggle('on');togglePrivateMembersSection()">
            <div class="toggle-thumb"></div>
          </div>
        </div>
        <!-- Private channel member selector (shown only when private is ON) -->
        <div id="privateMembersSection" style="display:none;margin-top:2px;">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:8px;">Select Members with Access</div>
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px;">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="#22c55e">
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
              </svg> Unlocked = can see
            </span>
            <span style="display:inline-flex;align-items:center;gap:4px;">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="var(--text-muted)">
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
              </svg> Locked = no access
            </span>
          </div>
          <div id="privateMembersLoading" style="font-size:12px;color:var(--text-muted);text-align:center;padding:12px 0;">Loading members…</div>
          <div id="privateMembersList" style="max-height:160px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeModal('addChannelModal')">Cancel</button>
        <button class="save-btn" onclick="createChannel()">Create Channel</button>
      </div>
    </div>
  </div>

  <!-- MINI PROFILE POPUP -->
  <div id="miniProfile" style="display:none;position:fixed;background:var(--bg-secondary);border:1px solid var(--border);border-radius:14px;padding:0;min-width:250px;max-width:280px;z-index:10500;box-shadow:0 16px 48px rgba(0,0,0,0.7);overflow:hidden;">
    <div style="height:60px;background:var(--gradient-main);position:relative;"></div>
    <div style="padding:0 16px 16px;position:relative;">
      <div id="mpAvatar" style="width:56px;height:56px;border-radius:50%;border:3px solid var(--bg-secondary);position:absolute;top:-28px;left:16px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;"></div>
      <div style="padding-top:36px;">
        <div id="mpName" style="font-size:15px;font-weight:800;color:var(--text-primary);margin-bottom:2px;"></div>
        <div id="mpRole" style="font-size:12px;color:var(--text-muted);margin-bottom:12px;"></div>
        <div style="display:flex;gap:8px;">
          <button onclick="openFullProfileCard()" style="flex:1;padding:8px;background:var(--gradient-main);border:none;border-radius:8px;color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">View Profile</button>
          <button id="mpThreadBtn" onclick="openThreadDMFromMiniProfile()" style="padding:8px 12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-secondary);font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;display:flex;align-items:center;gap:5px;">
            <svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24">
              <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" />
            </svg>
            Thread
          </button>
        </div>
        <!-- Logout only shown on own mini profile -->
        <div id="mpLogoutRow" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
          <button onclick="handleLogout()" style="width:100%;padding:8px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);border-radius:8px;color:#ef4444;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;gap:6px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
              <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
            </svg>
            Log Out
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- TOAST CONTAINER -->
  <div id="toastContainer" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;"></div>

  <!-- CONNECTION REQUEST NOTIFICATION CONTAINER -->
  <!-- Incoming requests appear here as banners (bottom-right) -->
  <div id="connReqContainer" style="position:fixed;bottom:80px;right:24px;z-index:99990;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:320px;"></div>

  <!-- FULL PROFILE CARD MODAL -->
  <div class="modal-overlay" id="profileCardModal" onclick="if(event.target===this)closeModal('profileCardModal')" style="display:none;">
    <div class="modal" style="max-width:440px;width:100%;padding:0;overflow:hidden;border-radius:16px;">
      <!-- Banner -->
      <div id="pcBanner" style="height:80px;background:var(--gradient-main);position:relative;flex-shrink:0;">
        <button class="modal-close" onclick="closeModal('profileCardModal')" style="position:absolute;top:10px;right:12px;background:rgba(0,0,0,0.3);border:none;color:#fff;width:28px;height:28px;border-radius:50%;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;">×</button>
      </div>
      <!-- Avatar overlapping banner -->
      <div style="padding:0 20px 20px;position:relative;">
        <div id="pcAvatar" style="width:72px;height:72px;border-radius:50%;border:4px solid var(--bg-secondary);position:absolute;top:-36px;left:20px;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#fff;flex-shrink:0;"></div>
        <!-- Connect button top-right -->
        <div style="display:flex;justify-content:flex-end;padding-top:10px;margin-bottom:4px;">
          <button id="pcConnectBtn" onclick="profileCardConnect()" style="font-size:12px;padding:7px 16px;border-radius:8px;background:rgba(168,85,247,0.15);border:1px solid rgba(168,85,247,0.4);color:#c084fc;cursor:pointer;font-weight:700;font-family:'Inter',sans-serif;transition:0.15s;" onmouseover="this.style.background='rgba(168,85,247,0.25)'" onmouseout="this.style.background='rgba(168,85,247,0.15)'">Connect</button>
        </div>
        <!-- Name / username / role -->
        <div style="padding-top:12px;">
          <div id="pcFullName" style="font-size:18px;font-weight:800;color:var(--text-primary);"></div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:2px;flex-wrap:wrap;">
            <span id="pcUsername" style="font-size:13px;color:var(--text-muted);"></span>
            <span id="pcRoleBadge" style="font-size:11px;padding:2px 8px;border-radius:99px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.25);color:#c084fc;font-weight:600;"></span>
            <span id="pcYearProgram" style="font-size:11px;color:var(--text-muted);"></span>
          </div>
        </div>
        <!-- Divider -->
        <div style="height:1px;background:var(--border);margin:14px 0;"></div>
        <!-- Bio -->
        <div id="pcBioWrap" style="margin-bottom:12px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:5px;">About Me</div>
          <div id="pcBio" style="font-size:13px;color:var(--text-secondary);line-height:1.5;"></div>
        </div>
        <!-- Interests & hobbies -->
        <div id="pcInterestsWrap" style="margin-bottom:12px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:6px;">Interests & Hobbies</div>
          <div id="pcInterests" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
        </div>
        <!-- Study style + goal -->
        <div id="pcStudyWrap" style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
          <div id="pcStudyStyle" style="flex:1;min-width:120px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px 10px;">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);font-weight:700;margin-bottom:3px;">Study Style</div>
            <div class="pc-style-val" style="font-size:12px;font-weight:600;color:var(--text-secondary);"></div>
          </div>
          <div id="pcGoal" style="flex:1;min-width:120px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px 10px;">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);font-weight:700;margin-bottom:3px;">Primary Goal</div>
            <div class="pc-goal-val" style="font-size:12px;font-weight:600;color:var(--text-secondary);"></div>
          </div>
        </div>
        <!-- Stats row: streak / hours / compatibility -->
        <div style="display:flex;gap:8px;margin-bottom:12px;">
          <div style="flex:1;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px;text-align:center;">
            <div id="pcStreak" style="font-size:16px;font-weight:800;color:#f59e0b;">—</div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">🔥 Streak</div>
          </div>
          <div style="flex:1;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px;text-align:center;">
            <div id="pcHours" style="font-size:16px;font-weight:800;color:#3b82f6;">—</div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">📚 Hours</div>
          </div>
          <div id="pcCompatWrap" style="flex:1;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px;text-align:center;">
            <div id="pcCompat" style="font-size:16px;font-weight:800;color:#a855f7;">—</div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">✨ Match</div>
          </div>
        </div>
        <!-- Mutual servers -->
        <div id="pcMutualWrap" style="margin-bottom:4px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:6px;">Mutual Servers</div>
          <div id="pcMutual" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- USER DATA for JS -->
  <script>
    window.ECOLLAB = {
      userId: <?= (int)$user['id'] ?>,
      username: <?= json_encode($user['username']) ?>,
      fullName: <?= json_encode($user['full_name']) ?>,
      role: <?= json_encode($user['role']) ?>,
      avatarGradient: <?= json_encode($user['avatar_color_gradient']) ?>,
      initials: <?= json_encode($initials) ?>,
      csrfToken: <?= json_encode($csrfToken) ?>,
      currentServerId: <?= (int)($firstServer['id'] ?? 0) ?>,
      currentChannelId: null,
      wsUrl: '<?= defined("WS_URL") ? WS_URL : "ws://localhost:8080" ?>',
      baseUrl: <?= json_encode(BASE_URL) ?>,
      whiteboardStandalone: false,
    };

    // Safe stubs for functions defined in defer-loaded scripts.
    // Inline onclick handlers can fire before defer scripts execute —
    // these stubs forward the call once the real function is available.
    (function() {
      var _deferred = [
        'openExtrasAction', 'switchActiveTab', 'filterActiveNow', 'openActiveNowModal',
        'openMembersPanel', 'switchMembersTab', 'filterMembersModal',
        'openFullMatchesModal', 'filterMatchTab', 'refreshMatches', 'sendConnectionRequest',
        'switchView', 'openSearchModal', 'closeSearchOverlay', 'searchMessages',
        'openUserSettings', 'closePlatformSettingsOverlay', 'switchSettingsTab',
        'handleLogout', 'goToDashboard', 'applyTheme', 'applyFontSize', 'saveProfileSettings',
        'openMiniProfile', 'closeMiniProfile', 'togglePinnedMessages',
        'toggleAttachMenu', 'toggleExtrasMenu', 'toggleNotifications', 'markAllRead',
        'filterSidebar', 'generateAIReply', 'toggleSidebar', 'closeSidebar', 'filterMembers',
        'joinVoice', 'leaveVoice', 'toggleVcMic', 'toggleVcDeafen', 'toggleCamera',
        'toggleScreenShare', 'startStopScreenShare', 'openWhiteboard', 'openMicTest',
        'toggleMicTest', 'closeMicTest', 'toggleMicLoopback', 'saveAudioSettings', 'openAudioSettings',
        'confirmLeaveCall', 'disconnectVoice', 'toggleVcMinimize', 'toggleVcPanelFromBar',
        'addPollOption', 'submitPoll', 'msgReact', 'msgPin', 'msgEdit', 'msgDelete', 'showMsgMenu',
        'cancelReply', 'sendMessage', 'loadMoreMessages',
        'wbRequestClose', 'wbSaveAndEnd', 'wbEndWithoutSaving', 'wbDismissLeaveModal',
        'closeWhiteboard', 'openWhiteboardFromVoice', 'joinSession',
        'wbPickTool', 'wbClr', 'wbStroke', 'wbSzUp', 'wbSzDown', 'wbSetZoom', 'wbFit',
        'wbUndo', 'wbRedo', 'wbAddNew', 'wbLike', 'wbToggleTodo', 'wbTab',
        'wbToggleSidebar', 'wbSendChat', 'wbChatKey', 'wbCopyInvite', 'wbExportPng'
      ];
      _deferred.forEach(function(name) {
        if (typeof window[name] !== 'undefined') return;
        window[name] = function() {
          var args = arguments;
          // Retry up to 50 times (5s) waiting for the real function
          var tries = 0;
          var t = setInterval(function() {
            if (typeof window['__real_' + name] === 'function') {
              clearInterval(t);
              window['__real_' + name].apply(window, args);
            } else if (++tries > 50) {
              clearInterval(t);
              console.warn('[Ecollab] Function not loaded: ' + name);
            }
          }, 100);
        };
      });
    })();
  </script>

  <!--
    Script load order matters:
    1. socket.js  — defines wsSend(), connectWebSocket(), global WS state
                    auto-starts connectWebSocket() on DOMContentLoaded
    2. chat.js    — uses wsSend(), appendMessageToUI(), switchChannel()
    3. chat-features.js — reactions, polls, pins, emoji picker
    4. emoji.js   — emoji data (referenced by chat-features)
    5. voice.js   — WebRTC / voice channel UI
    6. whiteboard.js — collaborative whiteboard
    7. dm-notifications.js — DM badge polling
  -->
  <script src="<?= BASE_URL ?>/assets/js/chat/socket.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/chat.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/chat-features.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/emoji.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/voice.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/whiteboard.js" defer></script>
  <!-- ── Private Channel Manager Modal ────────────────────────────────── -->
  <div id="privateChannelManagerModal" style="display:none!important;position:fixed;inset:0;z-index:11000;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;" onclick="if(event.target===this)closePrivateChannelManager()">
    <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:16px;padding:0;width:520px;max-width:96vw;max-height:86vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,0.6);">
      <!-- Header -->
      <div style="padding:18px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
        <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" style="color:#a855f7;flex-shrink:0;">
          <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
        </svg>
        <div style="flex:1;">
          <div style="font-size:15px;font-weight:700;color:var(--text-primary);" id="pcmChannelName">Private Channel</div>
          <div style="font-size:11px;color:var(--text-muted);">Manage who can access this channel</div>
        </div>
        <button onclick="closePrivateChannelManager()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px;line-height:1;padding:4px;">×</button>
      </div>
      <!-- Tabs -->
      <div style="display:flex;border-bottom:1px solid var(--border);">
        <button id="pcmTabMembers" onclick="pcmSwitchTab('members')" style="flex:1;padding:10px;background:none;border:none;border-bottom:2px solid #a855f7;color:#a855f7;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:0.12s;">
          👥 Members
        </button>
        <button id="pcmTabRequests" onclick="pcmSwitchTab('requests')" style="flex:1;padding:10px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:0.12s;">
          📥 Requests <span id="pcmRequestBadge" style="display:none;background:#ef4444;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;">0</span>
        </button>
      </div>
      <!-- Content area -->
      <div style="flex:1;overflow-y:auto;padding:12px 16px;" id="pcmContent">
        <div style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">Loading…</div>
      </div>
      <!-- Footer -->
      <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;">
        <button onclick="closePrivateChannelManager()" style="padding:8px 18px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-secondary);font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">Close</button>
      </div>
    </div>
  </div>

  <!-- ── Request Access Banner (shown to non-members of private channels) ── -->
  <div id="channelAccessBanner" style="display:none;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:500;background:var(--bg-secondary);border:1px solid var(--border);border-radius:16px;padding:32px;text-align:center;max-width:360px;box-shadow:0 16px 48px rgba(0,0,0,0.5);">
    <div style="font-size:36px;margin-bottom:12px;">🔒</div>
    <div style="font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:8px;" id="accessBannerName">Private Channel</div>
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">This channel is private. Request access from the channel owner.</div>
    <div id="accessBannerStatus" style="display:none;margin-bottom:12px;font-size:12px;padding:6px 12px;border-radius:8px;"></div>
    <button id="accessBannerBtn" onclick="requestChannelAccess()" style="padding:10px 24px;background:var(--gradient-main);border:none;border-radius:10px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">
      Request Access
    </button>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/chat/dm-notifications.js" defer></script>
  <!--
    Collab tools load order:
    ot-engine.js        — pure OT algorithm (no deps, must come first)
    collab-tools.js     — task/code/timer/quiz/calendar tools
    collab-liveeditor.js — OT live document editor (overrides loadNotes)
  -->
  <script src="<?= BASE_URL ?>/assets/js/chat/ot-engine.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/collab-tools.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/collab-liveeditor.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/collab-extra.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/peer-matching.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/js/chat/server-channel-management.js" defer></script>


  <!-- ── VOICE CHANNEL MODALS ─────────────────────────────────────────────── -->

  <!-- Leave Voice Modal -->
  <div class="modal-overlay" id="vcLeaveModal" onclick="if(event.target===this)closeModal('vcLeaveModal')">
    <div class="modal modal-sm">
      <div class="modal-header">
        <span class="modal-title">Leave Voice Channel?</span>
        <button class="modal-close" onclick="closeModal('vcLeaveModal')">×</button>
      </div>
      <div class="modal-body" style="text-align:center;padding:28px 24px;">
        <div style="font-size:40px;margin-bottom:14px;">📵</div>
        <p style="color:var(--text-secondary);font-size:14px;line-height:1.6;">You will be disconnected from the voice channel. Others will stay connected.</p>
      </div>
      <div class="modal-footer" style="justify-content:center;gap:12px;">
        <button class="cancel-btn" onclick="closeModal('vcLeaveModal')">Stay</button>
        <button class="danger-btn" onclick="confirmLeaveCall()">Leave Call</button>
      </div>
    </div>
  </div>

  <!-- Screen Share Modal -->
  <div class="modal-overlay" id="vcScreenModal" onclick="if(event.target===this)closeScreenShare()">
    <div class="modal modal-md">
      <div class="modal-header">
        <span class="modal-title">🖥️ Screen Share</span>
        <button class="modal-close" onclick="closeScreenShare()">×</button>
      </div>
      <div class="modal-body">
        <div id="vcScreenPreview" style="width:100%;height:160px;background:var(--bg-tertiary);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:18px;border:2px dashed var(--border);color:var(--text-muted);font-size:13px;">
          Preview will appear here
        </div>
        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;">Quality</div>
          <div style="display:flex;gap:8px;">
            <button class="screen-quality-btn active" onclick="selectScreenQuality(this,'720p')" style="flex:1;padding:8px 0;background:var(--bg-tertiary);border:1px solid var(--border-accent);border-radius:8px;color:var(--accent-purple);font-size:12px;font-weight:700;cursor:pointer;">720p</button>
            <button class="screen-quality-btn" onclick="selectScreenQuality(this,'1080p')" style="flex:1;padding:8px 0;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-secondary);font-size:12px;font-weight:700;cursor:pointer;">1080p</button>
            <button class="screen-quality-btn" onclick="selectScreenQuality(this,'source')" style="flex:1;padding:8px 0;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-secondary);font-size:12px;font-weight:700;cursor:pointer;">Source</button>
          </div>
        </div>
        <div id="vcScreenLiveBadge" style="display:none;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:700;text-align:center;margin-bottom:10px;">🔴 LIVE</div>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeScreenShare()">Cancel</button>
        <button class="primary-btn" id="vcScreenStartBtn" onclick="startStopScreenShare()">Start Sharing</button>
      </div>
    </div>
  </div>

  <!-- Noise Cancellation Modal -->
  <div class="modal-overlay" id="vcNoiseCancelModal" onclick="if(event.target===this)closeModal('vcNoiseCancelModal')">
    <div class="modal modal-sm">
      <div class="modal-header">
        <span class="modal-title">🎙️ Noise Cancellation</span>
        <button class="modal-close" onclick="closeModal('vcNoiseCancelModal')">×</button>
      </div>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:8px;">
        <div class="noise-option active" onclick="selectNoiseMode(this,'off')" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;background:var(--bg-tertiary);border:1px solid var(--border-accent);cursor:pointer;">
          <div style="font-size:22px;">🔈</div>
          <div>
            <div class="no-name" style="font-size:13px;font-weight:700;color:var(--text-primary);">Off</div>
            <div style="font-size:11px;color:var(--text-muted);">No processing applied</div>
          </div>
        </div>
        <div class="noise-option" onclick="selectNoiseMode(this,'standard')" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;">
          <div style="font-size:22px;">🎙️</div>
          <div>
            <div class="no-name" style="font-size:13px;font-weight:700;color:var(--text-primary);">Standard</div>
            <div style="font-size:11px;color:var(--text-muted);">Reduces background noise</div>
          </div>
        </div>
        <div class="noise-option" onclick="selectNoiseMode(this,'aggressive')" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;">
          <div style="font-size:22px;">🔇</div>
          <div>
            <div class="no-name" style="font-size:13px;font-weight:700;color:var(--text-primary);">Aggressive</div>
            <div style="font-size:11px;color:var(--text-muted);">Heavily filters all background sounds</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeModal('vcNoiseCancelModal')">Cancel</button>
        <button class="primary-btn" onclick="saveNoiseMode()">Save</button>
      </div>
    </div>
  </div>

  <!-- Audio Settings Modal -->
  <div class="modal-overlay" id="vcAudioSettingsModal" onclick="if(event.target===this)closeModal('vcAudioSettingsModal')">
    <div class="modal modal-md">
      <div class="modal-header">
        <span class="modal-title">🔊 Audio Settings</span>
        <button class="modal-close" onclick="closeModal('vcAudioSettingsModal')">×</button>
      </div>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:18px;">
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:8px;">Input Device (Microphone)</label>
          <select id="audioInputSelect" style="width:100%;padding:10px 12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:13px;">
            <option>Default Microphone</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:8px;">Output Device (Speakers)</label>
          <select id="audioOutputSelect" style="width:100%;padding:10px 12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:13px;">
            <option>Default Speakers</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:8px;">Input Volume</label>
          <input type="range" min="0" max="100" value="80" style="width:100%;accent-color:var(--accent-purple);">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:8px;">Output Volume</label>
          <input type="range" min="0" max="100" value="100" style="width:100%;accent-color:var(--accent-purple);">
        </div>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeModal('vcAudioSettingsModal')">Close</button>
        <button class="primary-btn" onclick="closeModal('vcAudioSettingsModal')">Save</button>
      </div>
    </div>
  </div>

  <!-- SEARCH OVERLAY -->
  <div class="modal-overlay" id="searchOverlay" onclick="closeSearchOverlay(event)" style="display:none;">
    <div class="modal modal-md" style="margin-top:-200px;">
      <div class="modal-header">
        <div style="display:flex;align-items:center;gap:8px;flex:1;">
          <span>🔍</span>
          <input type="text" id="searchInput" style="flex:1;background:transparent;border:none;outline:none;font-size:16px;color:var(--text-primary);font-family:'Inter',sans-serif;" placeholder="Search messages..." oninput="searchMessages(this.value)" />
        </div>
        <button class="modal-close" onclick="closeModal('searchOverlay')">×</button>
      </div>
      <div class="modal-body" id="searchResults">
        <div style="text-align:center;color:var(--text-muted);font-size:13px;padding:20px 0;">Type to search messages in this channel...</div>
      </div>
    </div>
  </div>

  <!-- ACTIVE NOW MODAL -->
  <div class="modal-overlay" id="activeNowModal" onclick="if(event.target===this)closeModal('activeNowModal')" style="display:none;">
    <div class="modal modal-md">
      <div class="modal-header" style="border-bottom:1px solid var(--border);">
        <div>
          <div class="modal-title">🟢 Active Now</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Members currently active</div>
        </div>
        <button class="modal-close" onclick="closeModal('activeNowModal')">×</button>
      </div>
      <div class="modal-body" style="padding:12px 16px;">
        <div style="position:relative;margin-bottom:12px;">
          <input id="activeNowSearch" placeholder="Search active members…" oninput="filterActiveNow(this.value)"
            style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px 12px 8px 34px;font-size:13px;color:var(--text-primary);outline:none;font-family:inherit;" />
          <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);">🔍</span>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:14px;" id="activeNowTabs">
          <button onclick="switchActiveTab(this,'all')" class="filter-tab active" style="font-size:11px;padding:4px 12px;">All</button>
          <button onclick="switchActiveTab(this,'study')" class="filter-tab" style="font-size:11px;padding:4px 12px;">📚 Studying</button>
          <button onclick="switchActiveTab(this,'voice')" class="filter-tab" style="font-size:11px;padding:4px 12px;">🎙 In Voice</button>
          <button onclick="switchActiveTab(this,'idle')" class="filter-tab" style="font-size:11px;padding:4px 12px;">🟡 Idle</button>
        </div>
        <div id="activeNowList" style="display:flex;flex-direction:column;gap:4px;max-height:440px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>

  <!-- MEMBERS MODAL (full) -->
  <div class="modal-overlay" id="membersModal" onclick="if(event.target===this)closeModal('membersModal')" style="display:none;">
    <div class="modal modal-md">
      <div class="modal-header" style="border-bottom:1px solid var(--border);">
        <div>
          <div class="modal-title">👥 Members</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Channel members</div>
        </div>
        <button class="modal-close" onclick="closeModal('membersModal')">×</button>
      </div>
      <div class="modal-body" style="padding:12px 16px;">
        <div style="position:relative;margin-bottom:12px;">
          <input id="membersSearch" placeholder="Search members…" oninput="filterMembersModal(this.value)"
            style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px 12px 8px 34px;font-size:13px;color:var(--text-primary);outline:none;font-family:inherit;" />
          <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);">🔍</span>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:14px;" id="membersStatusTabs">
          <button onclick="switchMembersTab(this,'all')" class="filter-tab active" style="font-size:11px;padding:4px 12px;">All</button>
          <button onclick="switchMembersTab(this,'online')" class="filter-tab" style="font-size:11px;padding:4px 12px;">🟢 Online</button>
          <button onclick="switchMembersTab(this,'idle')" class="filter-tab" style="font-size:11px;padding:4px 12px;">🟡 Idle</button>
          <button onclick="switchMembersTab(this,'offline')" class="filter-tab" style="font-size:11px;padding:4px 12px;">⚫ Offline</button>
        </div>
        <div id="membersModalList" style="display:flex;flex-direction:column;gap:2px;max-height:440px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>

  <!-- MATCHES MODAL -->
  <div class="modal-overlay" id="matchesModal" style="display:none;">
    <div class="modal modal-lg">
      <div class="modal-header">
        <div class="modal-title">AI Suggested Matches ✨</div>
        <button class="modal-close" onclick="closeModal('matchesModal')">×</button>
      </div>
      <div class="modal-body">
        <div class="filter-tabs" style="display:flex;gap:8px;margin-bottom:16px;">
          <button class="filter-tab active" onclick="filterMatchTab(this,'all')">All</button>
          <button class="filter-tab" onclick="filterMatchTab(this,'professor')">Professors</button>
          <button class="filter-tab" onclick="filterMatchTab(this,'phd')">PhD Students</button>
          <button class="filter-tab" onclick="filterMatchTab(this,'student')">Undergrad</button>
        </div>
        <div id="fullMatchesList" style="max-height:440px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>

  <!-- POLL MODAL -->
  <div class="modal-overlay" id="pollModal" style="display:none;">
    <div class="modal modal-md">
      <div class="modal-header">
        <div class="modal-title">📊 Create a Poll</div>
        <button class="modal-close" onclick="closeModal('pollModal')">×</button>
      </div>
      <div class="modal-body">
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Poll Question</label>
          <input type="text" id="pollQuestion" placeholder="Ask a question..." style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;" />
        </div>
        <div style="margin-bottom:8px;">
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Options</label>
        </div>
        <div id="pollOptions" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;">
          <input type="text" class="poll-opt" placeholder="Option 1" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">
          <input type="text" class="poll-opt" placeholder="Option 2" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">
        </div>
        <button onclick="addPollOption()" style="background:none;border:1px dashed var(--border);border-radius:6px;padding:7px 14px;color:var(--text-muted);font-size:13px;cursor:pointer;font-family:'Inter',sans-serif;width:100%;">+ Add Option</button>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeModal('pollModal')">Cancel</button>
        <button class="save-btn" onclick="submitPoll()">Post Poll</button>
      </div>
    </div>
  </div>

  <!-- ADD SERVER MODAL -->
  <div class="modal-overlay" id="addServerModal" style="display:none;">
    <div class="modal modal-md">
      <div class="modal-header">
        <div class="modal-title">➕ Add a Server</div>
        <button class="modal-close" onclick="closeModal('addServerModal')">×</button>
      </div>
      <div class="modal-body">
        <div id="addServerChoices">
          <div style="text-align:center;margin-bottom:20px;">
            <div style="font-size:20px;font-weight:800;color:var(--text-primary);margin-bottom:6px;">Create your own</div>
            <div style="font-size:13px;color:var(--text-muted);">Set up a new server for your study group.</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
            <div onclick="selectServerTemplate('study-group')" style="padding:16px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;cursor:pointer;text-align:center;transition:border-color 0.12s;" onmouseover="this.style.borderColor='var(--accent-purple)'" onmouseout="this.style.borderColor='var(--border)'">
              <div style="font-size:26px;margin-bottom:6px;">📚</div>
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Study Group</div>
            </div>
            <div onclick="selectServerTemplate('research')" style="padding:16px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;cursor:pointer;text-align:center;transition:border-color 0.12s;" onmouseover="this.style.borderColor='var(--accent-purple)'" onmouseout="this.style.borderColor='var(--border)'">
              <div style="font-size:26px;margin-bottom:6px;">🔬</div>
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Research Lab</div>
            </div>
          </div>
          <div style="text-align:center;margin-top:14px;">
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:8px;">Have an invite?</div>
            <div style="display:flex;gap:8px;">
              <input type="text" id="inviteInput" placeholder="Paste invite link…" style="flex:1;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:9px 12px;font-size:13px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">
              <button class="save-btn" onclick="joinByInvite()" style="padding:9px 16px;white-space:nowrap;">Join Server</button>
            </div>
          </div>
        </div>
        <div id="addServerForm" style="display:none;">
          <div style="text-align:center;margin-bottom:20px;">
            <div id="serverFormEmoji" style="font-size:40px;margin-bottom:8px;">📚</div>
          </div>
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Server Name</label>
          <input type="text" id="newServerName" placeholder="My Server" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Description</label>
          <input type="text" id="newServerDesc" placeholder="What's this server about?" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;margin-bottom:14px;">
          <div>
            <button class="cancel-btn" onclick="backToServerChoices()" style="margin-right:8px;">← Back</button>
            <button class="save-btn" onclick="createServer()">Create Server ✨</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PLATFORM SETTINGS MODAL -->
  <div class="modal-overlay" id="platformSettingsModal" onclick="closePlatformSettingsOverlay(event)" style="display:none;">
    <div class="modal" style="max-width:680px;display:flex;height:520px;padding:0;overflow:hidden;border-radius:16px;">
      <!-- Nav -->
      <div style="width:200px;background:#0d1117;padding:16px 8px;flex-shrink:0;overflow-y:auto;border-radius:16px 0 0 16px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);padding:6px 10px 4px;">User Settings</div>
        <div class="ps-nav-item active" onclick="switchSettingsTab('profile',this)">My Account</div>
        <div class="ps-nav-item" onclick="switchSettingsTab('appearance',this)">Appearance</div>
        <div class="ps-nav-item" onclick="switchSettingsTab('voice-audio',this)">Voice &amp; Audio</div>
        <div class="ps-nav-item" onclick="switchSettingsTab('notifications-tab',this)">Notifications</div>
        <div style="height:1px;background:var(--border);margin:10px 8px;"></div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);padding:6px 10px 4px;">Navigation</div>
        <div class="ps-nav-item" onclick="goToDashboard()">
          <span style="margin-right:6px;">🏠</span> Dashboard
        </div>
        <div style="height:1px;background:var(--border);margin:10px 8px;"></div>
        <div class="ps-nav-item" style="color:#ef4444;" onclick="handleLogout()">Log Out</div>
        <div style="padding:16px 10px 0;font-size:10px;color:var(--text-muted);">Ecollab v2.0</div>
      </div>
      <!-- Content -->
      <div style="flex:1;overflow-y:auto;padding:24px 28px;background:var(--bg-primary);">
        <div id="ps-profile" class="ps-tab active-tab">
          <div style="font-size:20px;font-weight:800;color:var(--text-primary);margin-bottom:20px;">My Account</div>
          <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;padding:16px;background:var(--bg-secondary);border-radius:10px;">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--gradient-main);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;" id="psDisplayNameVal"><?= htmlspecialchars($initials) ?></div>
            <div>
              <div style="font-size:16px;font-weight:700;color:var(--text-primary);" id="psDisplayName"><?= htmlspecialchars($user['username']) ?></div>
              <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($user['role'] ?? 'Student') ?></div>
            </div>
          </div>
          <div style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Display Name</label>
            <input type="text" id="psEditName" placeholder="Your display name" value="<?= htmlspecialchars($user['username']) ?>"
              style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">
          </div>
          <div style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Email</label>
            <input type="text" value="<?= htmlspecialchars($user['email'] ?? '') ?>" readonly
              style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;color:var(--text-muted);outline:none;font-family:'Inter',sans-serif;">
          </div>
          <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
            <button onclick="closeModal('platformSettingsModal')" style="padding:9px 18px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-secondary);font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
            <button onclick="saveProfileSettings()" style="padding:9px 18px;background:var(--gradient-main);border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">Save Changes</button>
          </div>
        </div>
        <div id="ps-appearance" class="ps-tab">
          <div style="font-size:20px;font-weight:800;color:var(--text-primary);margin-bottom:20px;">Appearance</div>
          <div style="margin-bottom:16px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:10px;">Theme</label>
            <div style="display:flex;gap:10px;">
              <div onclick="applyTheme('dark',this)" style="flex:1;padding:12px;background:var(--bg-tertiary);border:1px solid var(--border-accent);border-radius:8px;cursor:pointer;text-align:center;font-size:13px;font-weight:600;color:var(--text-primary);">🌑 Dark</div>
              <div onclick="applyTheme('darker',this)" style="flex:1;padding:12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;cursor:pointer;text-align:center;font-size:13px;font-weight:600;color:var(--text-primary);">⬛ Darker</div>
              <div onclick="applyTheme('midnight',this)" style="flex:1;padding:12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;cursor:pointer;text-align:center;font-size:13px;font-weight:600;color:var(--text-primary);">🌌 Midnight</div>
            </div>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Font Size</label>
            <input type="range" min="12" max="18" value="14" oninput="applyFontSize(this.value)" style="width:100%;accent-color:var(--accent-purple);">
          </div>
        </div>
        <div id="ps-notifications-tab" class="ps-tab">
          <div style="font-size:20px;font-weight:800;color:var(--text-primary);margin-bottom:20px;">Notifications</div>
          <div style="color:var(--text-muted);font-size:13px;">Notification settings coming soon.</div>
        </div>
        <!-- ── VOICE & AUDIO TAB ─────────────────────────────────────── -->
        <div id="ps-voice-audio" class="ps-tab">
          <div style="font-size:20px;font-weight:800;color:var(--text-primary);margin-bottom:20px;">Voice &amp; Audio</div>

          <!-- Input device -->
          <div style="margin-bottom:20px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Input Device (Microphone)</label>
            <p style="font-size:11px;color:var(--text-muted);margin-bottom:8px;">Works with built-in mics, USB mics, and virtual mic apps (WO Mic, VB-Cable, etc.)</p>
            <select id="voiceRecordInputSelect" onchange="window._vcPreferredInput=this.value"
              style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:9px 12px;font-size:13px;outline:none;font-family:'Inter',sans-serif;">
              <option value="">Loading devices…</option>
            </select>
            <button onclick="_acquireMic && _populateDeviceSelects()" style="margin-top:8px;padding:6px 14px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.3);border-radius:7px;color:#c084fc;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">
              🔄 Refresh Devices
            </button>
          </div>

          <!-- Output device -->
          <div style="margin-bottom:20px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Output Device (Speaker / Headphones)</label>
            <select id="voiceRecordOutputSelect" onchange="window._vcPreferredOutput=this.value"
              style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:9px 12px;font-size:13px;outline:none;font-family:'Inter',sans-serif;">
              <option value="">Default Speaker</option>
            </select>
          </div>

          <!-- Voice record section -->
          <div style="background:var(--bg-secondary);border-radius:10px;padding:16px;margin-bottom:20px;">
            <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">🎤 Voice Message Recording</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">The selected input device will be used for voice messages in text channels.</div>
            <button onclick="openMicTest()"
              style="padding:8px 18px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.3);border-radius:8px;color:#c084fc;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">
              🎙️ Test Microphone
            </button>
          </div>

          <!-- Input volume -->
          <div style="margin-bottom:16px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Input Volume</label>
            <input type="range" min="0" max="200" value="100" style="width:100%;accent-color:var(--accent-purple);"
              oninput="this.nextElementSibling.textContent=this.value+'%'">
            <span style="font-size:11px;color:var(--text-muted);">100%</span>
          </div>

          <!-- Output volume -->
          <div style="margin-bottom:20px;">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;margin-bottom:6px;">Output Volume</label>
            <input type="range" min="0" max="200" value="100" style="width:100%;accent-color:var(--accent-purple);"
              oninput="this.nextElementSibling.textContent=this.value+'%'">
            <span style="font-size:11px;color:var(--text-muted);">100%</span>
          </div>

          <div style="display:flex;justify-content:flex-end;">
            <button onclick="if(window._vcPreferredInput||window._vcPreferredOutput){saveAudioSettings?.();closeModal('platformSettingsModal');} else closeModal('platformSettingsModal')"
              style="padding:9px 18px;background:linear-gradient(135deg,#a855f7,#ec4899);border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">
              Save
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- GIF PICKER CSS additions -->
  <style>
    .gif-picker {
      display: none;
      position: absolute;
      background: var(--bg-secondary);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px;
      width: 280px;
      z-index: 500;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    }

    .gif-picker.open {
      display: block;
    }

    .gif-search {
      width: 100%;
      background: var(--bg-tertiary);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 8px 12px;
      font-size: 13px;
      color: var(--text-primary);
      outline: none;
      font-family: 'Inter', sans-serif;
      box-sizing: border-box;
      margin-bottom: 10px;
    }

    .gif-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 6px;
      max-height: 200px;
      overflow-y: auto;
    }

    .gif-item {
      font-size: 30px;
      text-align: center;
      cursor: pointer;
      padding: 4px;
      border-radius: 8px;
      transition: background 0.1s;
    }

    .gif-item:hover {
      background: var(--bg-hover);
    }

    .filter-tab {
      padding: 6px 14px;
      background: var(--bg-tertiary);
      border: 1px solid var(--border);
      border-radius: 99px;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-muted);
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: all 0.12s;
    }

    .filter-tab.active {
      background: rgba(168, 85, 247, 0.15);
      border-color: rgba(168, 85, 247, 0.4);
      color: #c084fc;
    }

    .ps-nav-item {
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-secondary);
      cursor: pointer;
      transition: background 0.12s;
      margin-bottom: 2px;
    }

    .ps-nav-item:hover {
      background: var(--bg-hover);
      color: var(--text-primary);
    }

    .ps-nav-item.active {
      background: rgba(168, 85, 247, 0.15);
      color: #c084fc;
    }

    .ps-tab {
      display: none;
    }

    .ps-tab.active-tab {
      display: block;
    }
  </style>

  <!-- MIC TEST MODAL (Discord-style — hear yourself without others hearing) -->
  <div class="modal-overlay" id="vcMicTestModal" onclick="if(event.target===this)closeMicTest()">
    <div class="modal modal-md">
      <div class="modal-header">
        <span class="modal-title">🎙️ Mic Test</span>
        <button class="modal-close" onclick="closeMicTest()">×</button>
      </div>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:16px;">

        <!-- Input device -->
        <div>
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:6px;">Input Device</label>
          <select id="micTestInputSelect" style="width:100%;padding:10px 12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:13px;outline:none;font-family:'Inter',sans-serif;"></select>
        </div>

        <!-- Live waveform visualiser -->
        <div>
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:8px;">Input Level</label>
          <div style="background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;padding:10px 12px;height:56px;display:flex;align-items:center;gap:3px;overflow:hidden;" id="micTestWaveWrap">
            <canvas id="micTestCanvas" style="width:100%;height:36px;"></canvas>
          </div>
        </div>

        <!-- Volume meter bar -->
        <div>
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:6px;">Volume</label>
          <div style="height:8px;background:var(--bg-tertiary);border-radius:99px;overflow:hidden;border:1px solid var(--border);">
            <div id="micTestVolBar" style="height:100%;width:0%;background:linear-gradient(90deg,#22c55e,#a855f7,#ef4444);border-radius:99px;transition:width .05s linear;"></div>
          </div>
        </div>

        <!-- Hear yourself toggle -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;">
          <div>
            <div style="font-size:13px;font-weight:700;color:var(--text-primary);">Hear Yourself</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Plays your mic back through speakers locally — others cannot hear this</div>
          </div>
          <div id="micTestLoopbackToggle" onclick="toggleMicLoopback()" style="width:42px;height:24px;border-radius:99px;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;position:relative;transition:background .15s;flex-shrink:0;">
            <div id="micTestLoopbackKnob" style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#64748b;transition:left .15s,background .15s;"></div>
          </div>
        </div>

        <!-- Status -->
        <div id="micTestStatus" style="font-size:12px;color:var(--text-muted);text-align:center;">Click Start to begin testing your microphone.</div>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeMicTest()">Close</button>
        <button class="primary-btn" id="micTestStartBtn" onclick="toggleMicTest()">▶ Start Test</button>
      </div>
    </div>
  </div>

  <!-- Invite to Voice Modal -->
  <div class="modal-overlay" id="vcInviteModal" onclick="if(event.target===this)closeModal('vcInviteModal')">
    <div class="modal modal-sm">
      <div class="modal-header">
        <span class="modal-title">Invite to Voice</span>
        <button class="modal-close" onclick="closeModal('vcInviteModal')">×</button>
      </div>
      <div class="modal-body">
        <input type="text" placeholder="Search members..." style="width:100%;padding:10px 12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:13px;box-sizing:border-box;outline:none;">
        <div id="vcInviteList" style="margin-top:12px;display:flex;flex-direction:column;gap:6px;max-height:240px;overflow-y:auto;">
          <div style="text-align:center;color:var(--text-muted);font-size:13px;padding:20px 0;">No members to invite</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="cancel-btn" onclick="closeModal('vcInviteModal')">Done</button>
      </div>
    </div>
  </div>



  <!-- ═══════════════════════════════════════════════════════════════════
     FULL-SCREEN WHITEBOARD — collaborative canvas
     Triggered by: sidebar whiteboard channels, Join button, voice MoreMenu
═══════════════════════════════════════════════════════════════════ -->
  <style>
    /* ── live pulse for sidebar dot ── */
    @keyframes wbLivePulse {

      0%,
      100% {
        opacity: 1;
        transform: scale(1)
      }

      50% {
        opacity: .4;
        transform: scale(.75)
      }
    }

    /* ── Overlay wrapper ── */
    #wbOverlay {
      position: fixed;
      inset: 0;
      z-index: 3000;
      display: none;
      flex-direction: column;
      background: #0b0f1a;
      font-family: 'Inter', sans-serif;
      animation: wbSlideIn .22s ease;
    }

    #wbOverlay.wb-visible {
      display: flex;
    }

    @keyframes wbSlideIn {
      from {
        opacity: 0;
        transform: scale(.98)
      }

      to {
        opacity: 1;
        transform: scale(1)
      }
    }

    /* ── Header bar ── */
    .wb-hdr {
      height: 52px;
      flex-shrink: 0;
      background: #121826;
      border-bottom: 1px solid rgba(255, 255, 255, .07);
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 0 14px;
      z-index: 10;
    }

    .wb-hdr-logo {
      width: 30px;
      height: 30px;
      border-radius: 8px;
      flex-shrink: 0;
      background: linear-gradient(135deg, #a855f7, #ec4899);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
    }

    .wb-hdr-titles {
      line-height: 1;
    }

    .wb-hdr-title {
      font-size: 14px;
      font-weight: 700;
      color: #f1f5f9;
    }

    .wb-hdr-sub {
      font-size: 10px;
      color: #64748b;
      margin-top: 1px;
    }

    .wb-hdr-center {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    .wb-icon-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, .08);
      background: rgba(255, 255, 255, .04);
      color: #94a3b8;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      transition: all .15s;
      flex-shrink: 0;
    }

    .wb-icon-btn:hover {
      background: rgba(255, 255, 255, .1);
      color: #f1f5f9;
    }

    .wb-zoom-sel {
      background: rgba(255, 255, 255, .05);
      border: 1px solid rgba(255, 255, 255, .09);
      color: #f1f5f9;
      border-radius: 8px;
      padding: 0 10px;
      height: 32px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      outline: none;
      font-family: 'Inter', sans-serif;
    }

    .wb-fit-btn {
      height: 32px;
      padding: 0 14px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      background: rgba(255, 255, 255, .06);
      border: 1px solid rgba(255, 255, 255, .1);
      color: #94a3b8;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: all .15s;
      white-space: nowrap;
    }

    .wb-fit-btn:hover {
      color: #f1f5f9;
      background: rgba(255, 255, 255, .12);
    }

    .wb-hdr-right {
      display: flex;
      align-items: center;
      gap: 7px;
      flex-shrink: 0;
    }

    /* avatar stack */
    .wb-av-stack {
      display: flex;
      align-items: center;
    }

    .wb-av {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      border: 2px solid #121826;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 700;
      color: #fff;
      cursor: pointer;
      transition: transform .15s;
      flex-shrink: 0;
      margin-left: -7px;
    }

    .wb-av:first-child {
      margin-left: 0;
    }

    .wb-av:hover {
      transform: translateY(-2px) scale(1.1);
      z-index: 5;
    }

    .wb-av-more {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      border: 2px solid #121826;
      background: rgba(255, 255, 255, .08);
      margin-left: -7px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
      color: #64748b;
    }

    .wb-invite-btn {
      height: 32px;
      padding: 0 16px;
      border-radius: 8px;
      background: linear-gradient(135deg, #a855f7, #7c3aed);
      border: none;
      color: #fff;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: all .15s;
      white-space: nowrap;
      box-shadow: 0 2px 10px rgba(168, 85, 247, .4);
    }

    .wb-invite-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 16px rgba(168, 85, 247, .6);
    }

    .wb-close-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1px solid rgba(239, 68, 68, .25);
      background: rgba(239, 68, 68, .08);
      color: #94a3b8;
      cursor: pointer;
      font-size: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }

    .wb-close-btn:hover {
      background: rgba(239, 68, 68, .2);
      color: #ef4444;
      border-color: rgba(239, 68, 68, .5);
    }

    /* ── Body ── */
    .wb-body {
      flex: 1;
      display: flex;
      overflow: hidden;
      min-height: 0;
    }

    /* ── Left tool palette ── */
    .wb-tools {
      width: 50px;
      flex-shrink: 0;
      background: #121826;
      border-right: 1px solid rgba(255, 255, 255, .06);
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 10px 0;
      gap: 3px;
      overflow-y: auto;
    }

    .wb-tbtn {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      background: transparent;
      border: none;
      color: #94a3b8;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      transition: all .15s;
      position: relative;
      flex-shrink: 0;
    }

    .wb-tbtn:hover {
      background: rgba(255, 255, 255, .08);
      color: #f1f5f9;
    }

    .wb-tbtn.wb-active {
      background: rgba(168, 85, 247, .18);
      color: #a855f7;
      box-shadow: 0 0 0 1.5px rgba(168, 85, 247, .45);
    }

    .wb-tbtn.wb-active::before {
      content: '';
      position: absolute;
      left: -5px;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 18px;
      background: #a855f7;
      border-radius: 0 3px 3px 0;
    }

    /* tooltip */
    .wb-tbtn::after {
      content: attr(data-tip);
      position: absolute;
      left: calc(100% + 9px);
      top: 50%;
      transform: translateY(-50%);
      background: #1a2235;
      color: #f1f5f9;
      font-size: 11px;
      font-weight: 600;
      padding: 4px 9px;
      border-radius: 7px;
      white-space: nowrap;
      opacity: 0;
      pointer-events: none;
      transition: opacity .15s;
      border: 1px solid rgba(255, 255, 255, .08);
      box-shadow: 0 4px 12px rgba(0, 0, 0, .5);
      z-index: 99;
    }

    .wb-tbtn:hover::after {
      opacity: 1;
    }

    .wb-tsep {
      width: 26px;
      height: 1px;
      background: rgba(255, 255, 255, .06);
      margin: 3px 0;
      flex-shrink: 0;
    }

    .wb-tadd {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      background: rgba(168, 85, 247, .08);
      border: 1.5px dashed rgba(168, 85, 247, .35);
      color: #a855f7;
      cursor: pointer;
      font-size: 19px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }

    .wb-tadd:hover {
      background: rgba(168, 85, 247, .18);
    }

    /* ── Canvas area ── */
    .wb-canvas-wrap {
      flex: 1;
      position: relative;
      overflow: hidden;
      cursor: crosshair;
      background: #0f172a;
    }

    .wb-canvas-wrap::before {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      background-image: radial-gradient(circle, rgba(255, 255, 255, .065) 1px, transparent 1px);
      background-size: 28px 28px;
    }

    #wbCanvas {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      touch-action: none;
    }

    /* ── Canvas DOM objects (draggable) ── */
    #wbObjects {
      position: absolute;
      inset: 0;
      pointer-events: none;
    }

    .wb-draggable {
      position: absolute;
      pointer-events: all;
    }

    /* Whiteboard draggable title (reserved for future typed title blocks) */
    .wb-gd-title {
      cursor: move;
    }

    .wb-gd-title h2 {
      font-size: 28px;
      font-weight: 700;
      color: #a855f7;
      font-family: Georgia, serif;
      letter-spacing: -.5px;
      text-decoration: underline;
      text-underline-offset: 4px;
      text-decoration-color: rgba(168, 85, 247, .35);
    }

    .wb-gd-sub {
      font-size: 12px;
      color: #64748b;
      margin-top: 3px;
      font-family: monospace;
    }

    /* Algorithm code block */
    .wb-code-block {
      background: #1a2235;
      border: 1.5px solid rgba(168, 85, 247, .3);
      border-radius: 10px;
      padding: 14px;
      min-width: 220px;
      cursor: move;
      box-shadow: 0 4px 20px rgba(0, 0, 0, .4);
    }

    .wb-code-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: #a855f7;
      margin-bottom: 10px;
    }

    .wb-code-line {
      font-size: 12px;
      font-family: monospace;
      color: #94a3b8;
      margin-bottom: 4px;
      line-height: 1.5;
    }

    .wb-code-line .kw {
      color: #a855f7;
    }

    .wb-code-line .op {
      color: #ec4899;
    }

    .wb-code-line .fn {
      color: #22c55e;
    }

    .wb-code-line .num {
      color: #f59e0b;
    }

    /* Sticky note */
    .wb-sticky {
      min-width: 150px;
      max-width: 180px;
      min-height: 90px;
      border-radius: 10px;
      padding: 12px 12px 24px;
      cursor: move;
      box-shadow: 0 4px 20px rgba(0, 0, 0, .45);
      font-size: 12px;
      font-weight: 500;
      line-height: 1.5;
      position: relative;
      transition: transform .15s, box-shadow .15s;
    }

    .wb-sticky:hover {
      transform: rotate(-.5deg) scale(1.02);
      box-shadow: 0 8px 30px rgba(0, 0, 0, .55);
    }

    .wb-sticky-heart {
      position: absolute;
      bottom: 7px;
      right: 10px;
      font-size: 14px;
      cursor: pointer;
      transition: transform .15s;
    }

    .wb-sticky-heart:hover {
      transform: scale(1.3);
    }

    /* Notes panel */
    .wb-notes-box {
      background: rgba(0, 212, 255, .07);
      border: 1.5px solid rgba(0, 212, 255, .28);
      border-radius: 10px;
      padding: 13px;
      min-width: 190px;
      cursor: move;
      box-shadow: 0 4px 16px rgba(0, 0, 0, .3);
    }

    .wb-notes-title {
      font-size: 13px;
      font-weight: 700;
      color: #22d3ee;
      margin-bottom: 8px;
    }

    .wb-notes-row {
      display: flex;
      gap: 7px;
      font-size: 12px;
      color: #94a3b8;
      margin-bottom: 5px;
    }

    .wb-notes-dot {
      color: #a855f7;
      flex-shrink: 0;
    }

    /* Update Rule formula box */
    .wb-formula-box {
      background: rgba(168, 85, 247, .07);
      border: 1.5px solid rgba(168, 85, 247, .3);
      border-radius: 12px;
      padding: 16px 20px;
      cursor: move;
      box-shadow: 0 4px 20px rgba(0, 0, 0, .3);
    }

    .wb-formula-title {
      font-size: 13px;
      font-weight: 700;
      color: #a855f7;
      text-decoration: underline;
      margin-bottom: 12px;
    }

    .wb-formula-eq {
      font-size: 20px;
      font-weight: 700;
      color: #f1f5f9;
      letter-spacing: .03em;
      font-family: Georgia, serif;
      margin-bottom: 10px;
    }

    .wb-formula-anns {
      display: flex;
      gap: 18px;
    }

    .wb-ann {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
    }

    .wb-ann-bar {
      width: 2px;
      height: 14px;
      border-radius: 99px;
    }

    .wb-ann-label {
      font-size: 9px;
      font-weight: 700;
      text-align: center;
      line-height: 1.3;
    }

    /* Thought bubble */
    .wb-bubble {
      background: rgba(245, 158, 11, .1);
      border: 2px solid rgba(245, 158, 11, .38);
      border-radius: 40% 40% 40% 40% / 60% 60% 40% 40%;
      padding: 14px 18px;
      cursor: move;
      text-align: center;
      font-size: 12px;
      font-weight: 600;
      color: #f59e0b;
      line-height: 1.4;
    }

    /* To-do list */
    .wb-todo-box {
      background: rgba(15, 23, 42, .9);
      border: 1px solid rgba(255, 255, 255, .07);
      border-radius: 10px;
      padding: 13px;
      min-width: 190px;
      cursor: move;
      box-shadow: 0 4px 16px rgba(0, 0, 0, .4);
    }

    .wb-todo-title {
      font-size: 12px;
      font-weight: 700;
      color: #a855f7;
      margin-bottom: 9px;
    }

    .wb-todo-row {
      display: flex;
      align-items: center;
      gap: 7px;
      margin-bottom: 6px;
      font-size: 12px;
      cursor: pointer;
    }

    .wb-chk {
      width: 14px;
      height: 14px;
      border-radius: 3px;
      border: 1.5px solid #a855f7;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: all .15s;
    }

    .wb-chk.done {
      background: #a855f7;
    }

    .wb-chk.done::after {
      content: '✓';
      color: #fff;
      font-size: 9px;
      font-weight: 800;
    }

    .wb-todo-txt {
      color: #94a3b8;
      transition: color .15s;
    }

    .wb-todo-row:hover .wb-todo-txt {
      color: #f1f5f9;
    }

    /* Live cursors */
    .wb-cursor {
      position: absolute;
      pointer-events: none;
      z-index: 60;
    }

    .wb-cursor-dot {
      width: 11px;
      height: 11px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, .7);
      margin: -5px 0 0 -5px;
    }

    .wb-cursor-label {
      font-size: 10px;
      font-weight: 700;
      color: #fff;
      padding: 2px 7px;
      border-radius: 99px;
      white-space: nowrap;
      margin-top: 4px;
    }

    @keyframes wbCursorGlow {

      0%,
      100% {
        box-shadow: 0 0 6px 2px currentColor
      }

      50% {
        box-shadow: 0 0 14px 4px currentColor
      }
    }

    /* ── Bottom floating toolbar ── */
    .wb-bottom-bar {
      position: absolute;
      bottom: 14px;
      left: 50%;
      transform: translateX(-50%);
      background: #1a2235;
      border: 1px solid rgba(255, 255, 255, .09);
      border-radius: 99px;
      padding: 7px 16px;
      display: flex;
      align-items: center;
      gap: 9px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, .55);
      z-index: 20;
    }

    .wb-clr {
      width: 21px;
      height: 21px;
      border-radius: 50%;
      cursor: pointer;
      border: 2px solid transparent;
      transition: transform .15s;
    }

    .wb-clr:hover {
      transform: scale(1.2);
    }

    .wb-clr.sel {
      border-color: #fff;
      transform: scale(1.15);
    }

    .wb-vsep {
      width: 1px;
      height: 18px;
      background: rgba(255, 255, 255, .1);
      flex-shrink: 0;
    }

    .wb-stroke {
      height: 3px;
      border-radius: 99px;
      background: #fff;
      cursor: pointer;
      transition: all .15s;
      flex-shrink: 0;
    }

    .wb-stroke.sel {
      box-shadow: 0 0 0 2px rgba(168, 85, 247, .7);
    }

    .wb-sz-ctrl {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .wb-sz-btn {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: rgba(255, 255, 255, .08);
      border: none;
      color: #94a3b8;
      cursor: pointer;
      font-size: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }

    .wb-sz-btn:hover {
      background: rgba(255, 255, 255, .16);
      color: #f1f5f9;
    }

    .wb-sz-lbl {
      font-size: 12px;
      color: #64748b;
      min-width: 28px;
      text-align: center;
    }

    /* ── Status bar ── */
    .wb-status {
      height: 32px;
      flex-shrink: 0;
      background: #121826;
      border-top: 1px solid rgba(255, 255, 255, .06);
      display: flex;
      align-items: center;
      padding: 0 14px;
      gap: 12px;
    }

    .wb-status-item {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 11px;
      color: #64748b;
    }

    .wb-save-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #22c55e;
      animation: wbLivePulse 2s infinite;
    }

    .wb-share-btn {
      margin-left: auto;
      height: 24px;
      padding: 0 16px;
      border-radius: 99px;
      background: linear-gradient(135deg, #a855f7, #7c3aed);
      border: none;
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: all .15s;
      box-shadow: 0 2px 8px rgba(168, 85, 247, .4);
    }

    .wb-share-btn:hover {
      box-shadow: 0 3px 14px rgba(168, 85, 247, .6);
    }

    .wb-status-action {
      cursor: pointer;
    }

    .wb-status-action:hover {
      color: #f1f5f9;
    }

    /* ── Right sidebar ── */
    .wb-rsidebar {
      width: 310px;
      flex-shrink: 0;
      background: #121826;
      border-left: 1px solid rgba(255, 255, 255, .06);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .wb-tabs {
      display: flex;
      border-bottom: 1px solid rgba(255, 255, 255, .06);
      flex-shrink: 0;
    }

    .wb-tab {
      flex: 1;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
      font-size: 12px;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      transition: all .15s;
    }

    .wb-tab:hover {
      color: #f1f5f9;
    }

    .wb-tab.wb-tab-active {
      color: #a855f7;
      border-bottom-color: #a855f7;
    }

    .wb-panel {
      display: none;
      flex: 1;
      flex-direction: column;
      overflow: hidden;
    }

    .wb-panel.wb-panel-active {
      display: flex;
    }

    /* Members panel */
    .wb-section-lbl {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: #475569;
      padding: 10px 14px 4px;
      flex-shrink: 0;
    }

    .wb-member {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 8px 14px;
      cursor: pointer;
      transition: background .15s;
    }

    .wb-member:hover {
      background: rgba(255, 255, 255, .04);
    }

    .wb-m-info {
      flex: 1;
      min-width: 0;
    }

    .wb-m-name {
      font-size: 13px;
      font-weight: 600;
      color: #e2e8f0;
    }

    .wb-m-role {
      font-size: 11px;
      color: #64748b;
    }

    .wb-you {
      font-size: 9px;
      font-weight: 700;
      padding: 1px 6px;
      background: #a855f7;
      color: #fff;
      border-radius: 4px;
      margin-left: 4px;
    }

    .wb-badge-crown {
      font-size: 13px;
      margin-left: 2px;
    }

    .wb-badge-app {
      font-size: 9px;
      font-weight: 700;
      padding: 1px 5px;
      background: #3b82f6;
      color: #fff;
      border-radius: 4px;
    }

    /* Chat panel */
    .wb-chat-msgs {
      flex: 1;
      overflow-y: auto;
      padding: 10px 14px;
      display: flex;
      flex-direction: column;
      gap: 9px;
    }

    .wb-chat-msg {
      display: flex;
      gap: 8px;
    }

    .wb-msg-content {
      flex: 1;
      min-width: 0;
    }

    .wb-msg-hdr {
      display: flex;
      align-items: baseline;
      gap: 6px;
      margin-bottom: 2px;
    }

    .wb-msg-name {
      font-size: 13px;
      font-weight: 600;
    }

    .wb-msg-time {
      font-size: 10px;
      color: #475569;
    }

    .wb-msg-text {
      font-size: 13px;
      color: #94a3b8;
      line-height: 1.5;
    }

    .wb-msg-text .wb-mention {
      color: #a855f7;
      font-weight: 600;
    }

    .wb-chat-foot {
      padding: 10px 14px 13px;
      border-top: 1px solid rgba(255, 255, 255, .06);
      flex-shrink: 0;
      position: relative;
    }

    .wb-chat-inp {
      width: 100%;
      background: rgba(255, 255, 255, .05);
      border: 1px solid rgba(255, 255, 255, .08);
      border-radius: 10px;
      padding: 9px 42px 9px 12px;
      font-size: 13px;
      color: #f1f5f9;
      font-family: 'Inter', sans-serif;
      outline: none;
      resize: none;
      transition: border-color .15s;
    }

    .wb-chat-inp:focus {
      border-color: rgba(168, 85, 247, .4);
    }

    .wb-chat-inp::placeholder {
      color: #475569;
    }

    .wb-chat-send-btn {
      position: absolute;
      right: 22px;
      bottom: 20px;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: linear-gradient(135deg, #a855f7, #7c3aed);
      border: none;
      color: #fff;
      cursor: pointer;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }

    .wb-chat-send-btn:hover {
      transform: scale(1.1);
      box-shadow: 0 3px 10px rgba(168, 85, 247, .5);
    }

    .wb-chat-icons {
      display: flex;
      align-items: center;
      gap: 2px;
      padding-top: 5px;
    }

    .wb-chat-icon {
      width: 26px;
      height: 26px;
      border-radius: 6px;
      background: transparent;
      border: none;
      color: #475569;
      cursor: pointer;
      font-size: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }

    .wb-chat-icon:hover {
      background: rgba(255, 255, 255, .08);
      color: #f1f5f9;
    }

    /* Activity panel */
    .wb-activity {
      flex: 1;
      overflow-y: auto;
    }

    .wb-act-item {
      display: flex;
      gap: 9px;
      padding: 9px 14px;
      border-bottom: 1px solid rgba(255, 255, 255, .04);
    }

    .wb-act-icon {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      flex-shrink: 0;
    }

    .wb-act-body {
      flex: 1;
    }

    .wb-act-text {
      font-size: 12px;
      color: #94a3b8;
      line-height: 1.5;
    }

    .wb-act-text strong {
      color: #e2e8f0;
    }

    .wb-act-time {
      font-size: 10px;
      color: #475569;
      margin-top: 1px;
    }
  </style>

  <!-- THE OVERLAY -->
  <div id="wbOverlay">

    <!-- ── Top header ── -->
    <div class="wb-hdr">
      <div class="wb-hdr-logo">🖊️</div>
      <div class="wb-hdr-titles">
        <div class="wb-hdr-title" id="wbBoardName">Whiteboard Session</div>
        <div class="wb-hdr-sub">Collaborate • Ideate • Visualize</div>
      </div>

      <div class="wb-hdr-center">
        <!-- undo -->
        <button class="wb-icon-btn" title="Undo (Ctrl+Z)" onclick="wbUndo()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z" />
          </svg>
        </button>
        <!-- redo -->
        <button class="wb-icon-btn" title="Redo (Ctrl+Y)" onclick="wbRedo()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M18.4 10.6C16.55 8.99 14.15 8 11.5 8c-4.65 0-8.58 3.03-9.96 7.22L3.9 16c1.05-3.19 4.05-5.5 7.6-5.5 1.95 0 3.73.72 5.12 1.88L13 16h9V7l-3.6 3.6z" />
          </svg>
        </button>
        <!-- zoom -->
        <select class="wb-zoom-sel" id="wbZoom" onchange="wbSetZoom(this.value)">
          <option>50%</option>
          <option>75%</option>
          <option selected>100%</option>
          <option>125%</option>
          <option>150%</option>
          <option>200%</option>
        </select>
        <button class="wb-fit-btn" onclick="wbFit()">Fit to screen</button>
      </div>

      <div class="wb-hdr-right">
        <div class="wb-av-stack" id="wbAvStack">
          <!-- populated by wbUpdateMemberAvatars() -->
        </div>
        <button class="wb-invite-btn" onclick="wbCopyInvite()">Invite</button>
        <button class="wb-icon-btn wb-sidebar-toggle-btn" id="wbSidebarToggle" onclick="wbToggleSidebar()" title="Toggle sidebar">☰</button>
        <button class="wb-icon-btn" onclick="showToast('⚙️ Board options','info')">···</button>
        <button class="wb-close-btn" onclick="wbRequestClose()" title="Close whiteboard">✕</button>
      </div>
    </div>

    <!-- ── Body ── -->
    <div class="wb-body">

      <!-- LEFT TOOL PALETTE -->
      <div class="wb-tools" id="wbToolbar">
        <button class="wb-tbtn wb-active" data-tool="cursor" data-tip="Select / Move" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4 0l16 12.28-6.78.67 4.34 9.28-2 .95-4.3-9.19-4.26 3.01L4 0z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="pen" data-tip="Pen" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="highlight" data-tip="Highlighter" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M15.5 1.5a2.5 2.5 0 0 0-2.5 2.5c0 .52.16 1 .43 1.41L9 10H6l-4 4 3 3 1-1 1 1 4-4v-3l4.59-4.43c.41.27.9.43 1.41.43a2.5 2.5 0 0 0 0-5zM13 16l-3-3 1-1 3 3-1 1z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="eraser" data-tip="Eraser" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M15.14 3c-.51 0-1.02.2-1.41.59L2.59 14.73a2 2 0 0 0 0 2.82L5.03 20h9.72l6.63-6.63a2 2 0 0 0 0-2.83L16.55 3.59A1.98 1.98 0 0 0 15.14 3zm-3.71 14H6l-2-2 5-5 5 5-3.57 2z" />
          </svg>
        </button>
        <div class="wb-tsep"></div>
        <button class="wb-tbtn" data-tool="shapes" data-tip="Shapes" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <circle cx="17.5" cy="6.5" r="3.5" />
            <polygon points="3,21 12,21 7.5,14" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="text" data-tip="Text" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M5 4v3h5.5v12h3V7H19V4z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="sticky" data-tip="Sticky Note" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l6-6V5a2 2 0 0 0-2-2zm-5 15v-4h4l-4 4z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="arrow" data-tip="Arrow" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z" />
          </svg>
        </button>
        <div class="wb-tsep"></div>
        <button class="wb-tbtn" data-tool="image" data-tip="Insert Image" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="code" data-tip="Code Block" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="math" data-tip="Math / Equation" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm4 8h-3v3h-2v-3H8v-2h3V9h2v3h3v2z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="laser" data-tip="Laser Pointer" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <path d="M3.5 18.49l6-6.01 4 4L22 6.92l-1.41-1.41-7.09 7.97-4-4L2 16.99z" />
          </svg>
        </button>
        <button class="wb-tbtn" data-tool="frame" data-tip="Frame" onclick="wbPickTool(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="4 2" />
          </svg>
        </button>
        <div class="wb-tsep"></div>
        <button class="wb-tadd" onclick="wbAddNew()" title="Add element">+</button>
      </div>

      <!-- CANVAS -->
      <div class="wb-canvas-wrap" id="wbCanvasWrap">
        <canvas id="wbCanvas"></canvas>

        <!-- DOM objects layer (all draggable content) -->
        <div id="wbObjects">
          <!-- Whiteboard starts empty — content is synced via WebSocket / whiteboard.js -->
        </div><!-- end wbObjects -->

        <!-- Bottom color/stroke toolbar -->
        <div class="wb-bottom-bar">
          <div class="wb-clr sel" style="background:#a855f7;" onclick="wbClr(this,'#a855f7')"></div>
          <div class="wb-clr" style="background:#fff;" onclick="wbClr(this,'#fff')"></div>
          <div class="wb-clr" style="background:#64748b;" onclick="wbClr(this,'#64748b')"></div>
          <div class="wb-clr" style="background:#f59e0b;" onclick="wbClr(this,'#f59e0b')"></div>
          <div class="wb-clr" style="background:#22c55e;" onclick="wbClr(this,'#22c55e')"></div>
          <div class="wb-vsep"></div>
          <div class="wb-stroke sel" style="width:32px;" onclick="wbStroke(this,'solid')" title="Solid"></div>
          <div class="wb-stroke" style="width:32px;background:repeating-linear-gradient(90deg,#fff 0 5px,transparent 5px 9px);" onclick="wbStroke(this,'dashed')" title="Dashed"></div>
          <div class="wb-stroke" style="width:32px;background:repeating-linear-gradient(90deg,#fff 0 2px,transparent 2px 6px);" onclick="wbStroke(this,'dotted')" title="Dotted"></div>
          <div class="wb-vsep"></div>
          <div class="wb-sz-ctrl">
            <button class="wb-sz-btn" onclick="wbSzDown()">−</button>
            <span class="wb-sz-lbl" id="wbSzLbl">2px</span>
            <button class="wb-sz-btn" onclick="wbSzUp()">+</button>
          </div>
        </div>

      </div><!-- end wb-canvas-wrap -->

      <!-- RIGHT SIDEBAR -->
      <div class="wb-rsidebar" id="wbRightSidebar">
        <div class="wb-tabs">
          <div class="wb-tab wb-tab-active" onclick="wbTab(this,'wbPanMembers')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
              <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
            </svg>
            Members
          </div>
          <div class="wb-tab" onclick="wbTab(this,'wbPanChat')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
              <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" />
            </svg>
            Chat
          </div>
          <div class="wb-tab" onclick="wbTab(this,'wbPanActivity')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
              <path d="M13 2.05v2.02c3.95.49 7 3.85 7 7.93 0 3.21-1.81 6-4.72 7.72L13 18v4l7-3.81C22.98 15.76 24 12.76 24 12c0-5.74-4.15-10.52-11-11.95zM11 2.05C4.15 3.48 0 8.26 0 12c0 2.76 1.02 5.76 3 7.19L11 23v-4l-2.28-1.3C6.81 16 5 13.21 5 12c0-4.08 3.05-7.44 7-7.93V2.05z" />
            </svg>
            Activity
          </div>
          <button class="wb-sidebar-minimize-btn" id="wbSidebarMinimize" onclick="wbToggleSidebar()" title="Close panel">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
            </svg>
          </button>
        </div>

        <!-- MEMBERS -->
        <div class="wb-panel wb-panel-active" id="wbPanMembers">
          <div class="wb-section-lbl" id="wbMembersLabel">WHITEBOARD MEMBERS — 0</div>
          <div id="wbMemberList" style="flex:1;overflow-y:auto;">
            <!-- populated by wbUpdateMemberList() -->
          </div>
          <div style="padding:10px 14px;margin-top:auto;border-top:1px solid rgba(255,255,255,.06);">
            <button onclick="wbCopyInvite()" style="width:100%;padding:8px;border-radius:8px;background:rgba(168,85,247,.1);border:1px solid rgba(168,85,247,.28);color:#a855f7;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;" onmouseover="this.style.background='rgba(168,85,247,.2)'" onmouseout="this.style.background='rgba(168,85,247,.1)'">+ Invite Members</button>
          </div>
        </div>

        <!-- CHAT -->
        <div class="wb-panel" id="wbPanChat" style="flex-direction:column;">
          <div class="wb-section-lbl">CHAT</div>
          <div class="wb-chat-msgs" id="wbChatMsgs">
            <!-- messages populated dynamically -->
          </div>
          <div class="wb-chat-foot" style="position:relative;">
            <textarea class="wb-chat-inp" placeholder="Message whiteboard..." rows="2" id="wbChatInp" onkeydown="wbChatKey(event)"></textarea>
            <button class="wb-chat-send-btn" onclick="wbSendChat()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
              </svg>
            </button>
            <div class="wb-chat-icons">
              <button class="wb-chat-icon" onclick="showToast('😊 Emoji','info')">😊</button>
              <button class="wb-chat-icon" style="font-size:10px;font-weight:800;">GIF</button>
              <div style="flex:1;"></div>
            </div>
          </div>
        </div>

        <!-- ACTIVITY -->
        <div class="wb-panel" id="wbPanActivity">
          <div class="wb-section-lbl">ACTIVITY LOG</div>
          <div class="wb-activity" id="wbActivityLog">
            <!-- populated dynamically -->
          </div>
        </div>
      </div><!-- end wb-rsidebar -->

    </div><!-- end wb-body -->

    <!-- ── Status bar ── -->
    <div class="wb-status">
      <div class="wb-status-item">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
          <path d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3 7 3V5c0-1.1-.9-2-2-2z" />
        </svg>
        Whiteboard Session
      </div>
      <div class="wb-status-item">
        <div class="wb-save-dot"></div>
        <span id="wbSaveLabel">Saved 2m ago</span>
      </div>
      <div class="wb-status-item wb-status-action" onclick="wbExportPng()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
          <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z" />
        </svg>
        Export
      </div>
      <div class="wb-status-item wb-status-action" onclick="showToast('🖥️ Presentation mode!','info')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
          <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
        </svg>
        Present
      </div>
      <button class="wb-share-btn" onclick="wbCopyInvite()">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" style="margin-right:4px;">
          <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z" />
        </svg>
        Share Whiteboard
      </button>
    </div>

    <!-- ── Owner Leave Confirmation Modal ── -->
    <div id="wbLeaveModal" style="
    display:none; position:absolute; inset:0; z-index:9999;
    background:rgba(0,0,0,.6); backdrop-filter:blur(6px);
    align-items:center; justify-content:center; padding:20px;">
      <div style="
      background:#121826; border:1px solid rgba(255,255,255,.1);
      border-radius:16px; padding:28px 24px; max-width:340px; width:100%;
      box-shadow:0 24px 60px rgba(0,0,0,.7); animation:wbSlideIn .2s ease;">

        <!-- icon -->
        <div style="
        width:48px; height:48px; border-radius:12px; margin:0 auto 16px;
        background:rgba(239,68,68,.12); border:1.5px solid rgba(239,68,68,.3);
        display:flex; align-items:center; justify-content:center; font-size:22px;">
          ⚠️
        </div>

        <!-- title -->
        <div style="font-size:17px; font-weight:700; color:#f1f5f9; text-align:center; margin-bottom:8px;">
          End the session?
        </div>

        <!-- body -->
        <div style="font-size:13px; color:#94a3b8; text-align:center; line-height:1.6; margin-bottom:24px;">
          You're the session owner. Leaving will <strong style="color:#f1f5f9;">end the session for everyone</strong>.<br><br>
          Save a snapshot to chat so you can pick up where you left off.
        </div>

        <!-- actions -->
        <div style="display:flex; flex-direction:column; gap:10px;">

          <!-- Save & End -->
          <button onclick="wbSaveAndEnd()" style="
          width:100%; padding:11px 16px; border-radius:10px;
          background:linear-gradient(135deg,#a855f7,#7c3aed); border:none;
          color:#fff; font-size:14px; font-weight:700; cursor:pointer;
          font-family:'Inter',sans-serif; transition:all .15s;
          box-shadow:0 4px 16px rgba(168,85,247,.4); display:flex;
          align-items:center; justify-content:center; gap:8px;"
            onmouseover="this.style.transform='translateY(-1px)'"
            onmouseout="this.style.transform=''">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
              <path d="M17 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z" />
            </svg>
            Save snapshot &amp; end session
          </button>

          <!-- End without saving -->
          <button onclick="wbEndWithoutSaving()" style="
          width:100%; padding:11px 16px; border-radius:10px;
          background:rgba(239,68,68,.1); border:1.5px solid rgba(239,68,68,.3);
          color:#ef4444; font-size:14px; font-weight:600; cursor:pointer;
          font-family:'Inter',sans-serif; transition:all .15s; display:flex;
          align-items:center; justify-content:center; gap:8px;"
            onmouseover="this.style.background='rgba(239,68,68,.2)'"
            onmouseout="this.style.background='rgba(239,68,68,.1)'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
              <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
            </svg>
            End without saving
          </button>

          <!-- Cancel -->
          <button onclick="wbDismissLeaveModal()" style="
          width:100%; padding:9px 16px; border-radius:10px;
          background:transparent; border:1px solid rgba(255,255,255,.08);
          color:#64748b; font-size:13px; font-weight:600; cursor:pointer;
          font-family:'Inter',sans-serif; transition:all .15s;"
            onmouseover="this.style.color='#94a3b8';this.style.borderColor='rgba(255,255,255,.18)'"
            onmouseout="this.style.color='#64748b';this.style.borderColor='rgba(255,255,255,.08)'">
            Cancel — keep session open
          </button>

        </div>
      </div>
    </div>

  </div>

  <!-- ── Report Message Modal ─────────────────────────────────────── -->
  <div class="modal-overlay" id="reportModal" style="display:none;" onclick="if(event.target===this)closeModal('reportModal')">
    <div class="modal-box" style="max-width:420px;">
      <div class="modal-header">
        <span style="font-size:18px;">🚩</span>
        <span class="modal-title">Report Message</span>
        <button class="modal-close" onclick="closeModal('reportModal')">×</button>
      </div>
      <div class="modal-body" style="padding:16px 20px;">
        <input type="hidden" id="reportMsgId" value="">
        <p style="font-size:13px;color:var(--text-secondary);margin:0 0 14px;">
          Help keep Ecollab safe. Select a reason and we'll review it.
        </p>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
          <?php
          $reasons = [
            'spam'          => ['🗑️', 'Spam or self-promotion'],
            'harassment'    => ['😡', 'Harassment or bullying'],
            'inappropriate' => ['🔞', 'Inappropriate content'],
            'phishing'      => ['🎣', 'Phishing or scam'],
            'other'         => ['⚠️', 'Other'],
          ];
          foreach ($reasons as $val => [$icon, $label]): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:border-color 0.15s;"
              onmouseover="this.style.borderColor='var(--accent-purple)'" onmouseout="this.style.borderColor='var(--border)'">
              <input type="radio" name="reportReason" value="<?= $val ?>" style="accent-color:#a855f7;" <?= $val === 'other' ? 'checked' : '' ?>>
              <span style="font-size:15px;"><?= $icon ?></span>
              <span style="font-size:13px;color:var(--text-primary);"><?= $label ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <textarea id="reportDescription" placeholder="Additional details (optional)"
          style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:13px;color:var(--text-primary);outline:none;resize:vertical;min-height:70px;font-family:'Inter',sans-serif;box-sizing:border-box;"></textarea>
      </div>
      <div class="modal-footer" style="display:flex;gap:8px;padding:12px 20px;justify-content:flex-end;">
        <button class="cancel-btn" onclick="closeModal('reportModal')">Cancel</button>
        <button class="create-btn" style="background:linear-gradient(135deg,#ef4444,#dc2626);" onclick="submitReport()">Submit Report</button>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════
       COLLABORATION HUB PANEL
       ══════════════════════════════════════════════════ -->
  <div id="collabHub">
    <div class="collab-hub-header">
      <span class="collab-hub-title">🤝 Collaboration Tools</span>
      <button class="collab-hub-close" onclick="closeCollabHub()" title="Close">✕</button>
    </div>
    <div class="collab-tab-bar">
      <button class="collab-tab-btn" data-tool="notes" onclick="_switchCollabTool('notes')">
        <span class="tab-icon">📝</span>Notes
      </button>
      <button class="collab-tab-btn" data-tool="tasks" onclick="_switchCollabTool('tasks')">
        <span class="tab-icon">📋</span>Tasks
      </button>
      <button class="collab-tab-btn" data-tool="code" onclick="_switchCollabTool('code')">
        <span class="tab-icon">💻</span>Code
      </button>
      <button class="collab-tab-btn" data-tool="timer" onclick="_switchCollabTool('timer')">
        <span class="tab-icon">⏱</span>Timer
      </button>
      <button class="collab-tab-btn" data-tool="quiz" onclick="_switchCollabTool('quiz')">
        <span class="tab-icon">📝</span>Quiz
      </button>
      <button class="collab-tab-btn" data-tool="calendar" onclick="_switchCollabTool('calendar')">
        <span class="tab-icon">📅</span>Calendar
      </button>
      <button class="collab-tab-btn" data-tool="flashcards" onclick="_switchCollabTool('flashcards')">
        <span class="tab-icon">🃏</span>Cards
      </button>
      <button class="collab-tab-btn" data-tool="mindmap" onclick="_switchCollabTool('mindmap')">
        <span class="tab-icon">🧠</span>Mind Map
      </button>
      <button class="collab-tab-btn" data-tool="review" onclick="_switchCollabTool('review')">
        <span class="tab-icon">📋</span>Review
      </button>
      <button class="collab-tab-btn" data-tool="summary" onclick="_switchCollabTool('summary')">
        <span class="tab-icon">✨</span>Summary
      </button>
      <button class="collab-tab-btn" data-tool="goals" onclick="_switchCollabTool('goals')">
        <span class="tab-icon">🎯</span>Goals
      </button>
      <button class="collab-tab-btn" data-tool="resources" onclick="_switchCollabTool('resources')">
        <span class="tab-icon">📚</span>Library
      </button>
    </div>

    <div id="collabPane_notes" class="collab-pane"></div>
    <div id="collabPane_tasks" class="collab-pane"></div>
    <div id="collabPane_code" class="collab-pane"></div>
    <div id="collabPane_timer" class="collab-pane"></div>
    <div id="collabPane_quiz" class="collab-pane"></div>
    <div id="collabPane_calendar" class="collab-pane"></div>
    <div id="collabPane_flashcards" class="collab-pane"></div>
    <div id="collabPane_mindmap" class="collab-pane"></div>
    <div id="collabPane_review" class="collab-pane"></div>
    <div id="collabPane_summary" class="collab-pane"></div>
    <div id="collabPane_goals" class="collab-pane"></div>
    <div id="collabPane_resources" class="collab-pane"></div>
  </div>

  <!-- Task detail modal -->
  <div id="taskDetailModal" class="collab-modal-overlay" style="display:none">
    <div class="modal-box" style="width:90%;max-width:440px">
      <div class="modal-header">
        <span>Edit Task</span>
        <button class="modal-close" onclick="closeTaskDetail()">✕</button>
      </div>
      <div class="modal-body">
        <input id="tdTitle" class="collab-input" placeholder="Task title*" />
        <textarea id="tdDesc" class="collab-input" placeholder="Description" rows="3" style="resize:vertical"></textarea>
        <select id="tdPriority" class="code-lang-select" style="width:100%">
          <option value="low">🟢 Low</option>
          <option value="medium" selected>🟡 Medium</option>
          <option value="high">🔴 High</option>
          <option value="urgent">🟣 Urgent</option>
        </select>
        <input id="tdDue" class="collab-input" type="date" placeholder="Due date" />
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary)">
          <input type="checkbox" id="tdDone"> Mark as done
        </label>
      </div>
      <div class="modal-footer">
        <button class="collab-btn-xs" style="color:#ef4444;margin-right:auto" onclick="deleteTask()">🗑 Delete</button>
        <button class="timer-reset-btn" onclick="closeTaskDetail()">Cancel</button>
        <button class="timer-start-btn" style="padding:7px 20px;font-size:13px" onclick="saveTaskDetail()">Save</button>
      </div>
    </div>
  </div>

  <!-- Create Quiz modal -->
  <div id="createQuizModal" class="collab-modal-overlay" style="display:none">
    <div class="modal-box" style="width:92%;max-width:580px">
      <div class="modal-header">
        <span>📝 Create Quiz</span>
        <button class="modal-close" onclick="closeCreateQuiz()">✕</button>
      </div>
      <div class="modal-body">
        <input id="quizTitleInput" class="collab-input" placeholder="Quiz title*" style="margin-bottom:10px" />
        <div id="quizQuestionsContainer"></div>
        <button class="collab-btn-xs" style="width:100%;margin-top:4px" onclick="addQuizQuestion()">+ Add Question</button>
      </div>
      <div class="modal-footer">
        <button class="timer-reset-btn" onclick="closeCreateQuiz()">Cancel</button>
        <button class="timer-start-btn" style="padding:7px 20px;font-size:13px" onclick="submitCreateQuiz()">Create Quiz</button>
      </div>
    </div>
  </div>

  <!-- Take / Results Quiz modal -->
  <div id="takeQuizModal" class="collab-modal-overlay" style="display:none"></div>

  <!-- Calendar event modal -->
  <div id="calEventModal" class="collab-modal-overlay" style="display:none"></div>

  <!-- ══════════════════════════════════════════════════════════════
       PEER MATCHING MODAL
       ══════════════════════════════════════════════════════════════ -->
  <div id="peerMatchingModal">
    <div class="pm-shell">
      <div class="pm-header">
        <div>
          <div class="pm-header-title">🤝 Find Study Partners</div>
          <div class="pm-header-sub">Matched by subjects · study style · hobbies · interests</div>
        </div>
        <button class="pm-close" onclick="closePeerMatchingModal()">✕</button>
      </div>
      <div class="pm-tab-bar">
        <button class="pm-tab-btn pm-active" data-tab="matches" onclick="_pmShowTab('matches')">✨ Matches</button>
        <button class="pm-tab-btn" data-tab="search" onclick="_pmShowTab('search')">🔍 Search</button>
        <button class="pm-tab-btn" data-tab="requests" onclick="_pmShowTab('requests')">📬 Requests</button>
        <button class="pm-tab-btn" data-tab="leaderboard" onclick="_pmShowTab('leaderboard')">🏆 Top</button>
        <button class="pm-tab-btn" data-tab="profile" onclick="_pmShowTab('profile')">⚙ Profile</button>
      </div>
      <div id="pmModalBody"></div>
    </div>
  </div>

  <!-- Send request modal -->
  <div id="pmSendRequestModal">
    <div class="pm-req-box">
      <div class="modal-header">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--text-primary)" id="pmReqTargetName"></div>
          <div style="font-size:11px;color:var(--accent-purple);margin-top:2px" id="pmReqScore"></div>
        </div>
        <button class="modal-close" onclick="closeSendRequestModal()">✕</button>
      </div>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
        <label style="font-size:12px;color:var(--text-muted)">Add a personal note (optional)</label>
        <textarea id="pmReqNote" class="collab-input" rows="3"
          placeholder="Hi! I noticed we both study Computer Science and enjoy gaming. Would love to study together!"></textarea>
      </div>
      <div class="modal-footer">
        <button class="timer-reset-btn" onclick="closeSendRequestModal()">Cancel</button>
        <button id="pmReqSendBtn" class="pm-btn-primary" onclick="submitSendRequest()">🤝 Send Request</button>
      </div>
    </div>
  </div>

  <!-- Compatibility detail modal -->
  <div id="pmCompatModal"></div>

  <script src="<?= BASE_URL ?>/assets/js/chat/functionality-overrides.js" defer></script>
</body>

<!-- ── FLASHCARD MODALS ── -->
<div id="createDeckModal" class="collab-modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:480px;width:92%">
    <div class="modal-header"><span>🃏 Create Flashcard Deck</span><button class="modal-close" onclick="closeCreateDeckModal()">✕</button></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <input id="deckTitleInput" class="collab-input" placeholder="Deck title*" />
      <input id="deckDescInput" class="collab-input" placeholder="Description (optional)" />
      <label style="font-size:12px;color:var(--text-muted)">Cards (one per line: <code>front | back | hint</code>)</label>
      <textarea id="deckCardsInput" class="collab-input" rows="6" placeholder="What is OT? | Operational Transformation | A conflict-free editing algorithm"></textarea>
    </div>
    <div class="modal-footer">
      <button class="timer-reset-btn" onclick="closeCreateDeckModal()">Cancel</button>
      <button class="timer-start-btn" style="padding:7px 18px;font-size:13px" onclick="submitCreateDeck()">Create Deck</button>
    </div>
  </div>
</div>

<div id="addCardModal" class="collab-modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:420px;width:92%">
    <div class="modal-header"><span>🃏 Add Card</span><button class="modal-close" onclick="closeAddCardModal()">✕</button></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <textarea id="cardFrontInput" class="collab-input" rows="3" placeholder="Question / Front*"></textarea>
      <textarea id="cardBackInput" class="collab-input" rows="3" placeholder="Answer / Back*"></textarea>
      <input id="cardHintInput" class="collab-input" placeholder="Hint (optional)" />
    </div>
    <div class="modal-footer">
      <button class="timer-reset-btn" onclick="closeAddCardModal()">Cancel</button>
      <button class="timer-start-btn" style="padding:7px 18px;font-size:13px" onclick="submitAddCard()">Add Card</button>
    </div>
  </div>
</div>

<!-- ── PEER REVIEW MODALS ── -->
<div id="createReviewModal" class="collab-modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:520px;width:92%">
    <div class="modal-header"><span>📋 Request Peer Review</span><button class="modal-close" onclick="closeCreateReviewModal()">✕</button></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <input id="reviewTitleInput" class="collab-input" placeholder="Title*" />
      <textarea id="reviewContentInput" class="collab-input" rows="5" placeholder="Paste your work here for review…"></textarea>
      <input id="reviewFileUrl" class="collab-input" placeholder="Link to file/doc (optional)" />
    </div>
    <div class="modal-footer">
      <button class="timer-reset-btn" onclick="closeCreateReviewModal()">Cancel</button>
      <button class="timer-start-btn" style="padding:7px 18px;font-size:13px" onclick="submitCreateReview()">Post Request</button>
    </div>
  </div>
</div>
<div id="reviewDetailModal" class="collab-modal-overlay" style="display:none"></div>

<!-- ── STUDY GOALS MODAL ── -->
<div id="createGoalModal" class="collab-modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:460px;width:92%">
    <div class="modal-header"><span>🎯 New Study Goal</span><button class="modal-close" onclick="closeCreateGoalModal()">✕</button></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <input id="goalTitleInput" class="collab-input" placeholder="Goal title*" />
      <textarea id="goalDescInput" class="collab-input" rows="2" placeholder="Description (optional)"></textarea>
      <select id="goalScopeSelect" class="code-lang-select" style="width:100%">
        <option value="group">👥 Group goal (visible to channel)</option>
        <option value="personal">👤 Personal goal (only you)</option>
      </select>
      <input id="goalDateInput" class="collab-input" type="date" placeholder="Target date (optional)" />
      <label style="font-size:12px;color:var(--text-muted)">Milestones (one per line)</label>
      <textarea id="goalMilestonesInput" class="collab-input" rows="4" placeholder="Read chapter 1&#10;Complete exercises&#10;Review notes"></textarea>
    </div>
    <div class="modal-footer">
      <button class="timer-reset-btn" onclick="closeCreateGoalModal()">Cancel</button>
      <button class="timer-start-btn" style="padding:7px 18px;font-size:13px" onclick="submitCreateGoal()">Create Goal</button>
    </div>
  </div>
</div>

<!-- ── RESOURCE LIBRARY MODAL ── -->
<div id="addResourceModal" class="collab-modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:460px;width:92%">
    <div class="modal-header"><span>📚 Add Resource</span><button class="modal-close" onclick="closeAddResourceModal()">✕</button></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
      <input id="resTitleInput" class="collab-input" placeholder="Title*" />
      <input id="resUrlInput" class="collab-input" placeholder="URL (optional)" />
      <select id="resTypeSelect" class="code-lang-select" style="width:100%">
        <option value="link">🔗 Link</option>
        <option value="pdf">📄 PDF</option>
        <option value="video">🎥 Video</option>
        <option value="image">🖼 Image</option>
        <option value="file">📁 File</option>
        <option value="note">📝 Note</option>
        <option value="other">📌 Other</option>
      </select>
      <textarea id="resDescInput" class="collab-input" rows="2" placeholder="Description (optional)"></textarea>
      <input id="resTagsInput" class="collab-input" placeholder="Tags (comma-separated, optional)" />
    </div>
    <div class="modal-footer">
      <button class="timer-reset-btn" onclick="closeAddResourceModal()">Cancel</button>
      <button class="timer-start-btn" style="padding:7px 18px;font-size:13px" onclick="submitAddResource()">Add Resource</button>
    </div>
  </div>
</div>
<div id="resourceCommentsModal" class="collab-modal-overlay" style="display:none"></div>

</body>

</html>