<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$channelId = (int)($_GET['channel_id'] ?? 0);
if ($channelId <= 0) {
    http_response_code(400);
    exit('A whiteboard channel is required.');
}
$csrf = AuthMiddleware::csrfToken();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ecollab Whiteboard</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/whiteboard.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/whiteboard-mobile.css">
    <style>
        html,
        body {
            margin: 0;
            height: 100%;
            overflow: hidden;
            background: #0b0f1a
        }

        #wbOverlay {
            display: flex !important;
            position: relative !important;
            inset: auto !important;
            height: 100vh !important
        }

        .wb-page-back {
            color: #94a3b8;
            text-decoration: none;
            font-size: 12px;
            margin-right: 8px
        }

        .wb-version-panel {
            position: absolute;
            right: 14px;
            top: 58px;
            z-index: 80;
            width: 270px;
            max-height: 60vh;
            overflow: auto;
            background: #121826;
            border: 1px solid rgba(255, 255, 255, .12);
            padding: 12px;
            border-radius: 10px;
            display: none
        }

        .wb-version-panel.open {
            display: block
        }

        .wb-version-panel h3 {
            font-size: 12px;
            color: #f1f5f9;
            margin: 0 0 9px
        }

        .wb-version-row {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 0;
            border-top: 1px solid rgba(255, 255, 255, .06);
            font-size: 11px;
            color: #cbd5e1
        }

        .wb-version-row a {
            color: #a855f7;
            margin-left: auto
        }

        .wb-page-btn {
            height: 30px;
            padding: 0 10px;
            border: 1px solid rgba(255, 255, 255, .12);
            background: rgba(255, 255, 255, .06);
            color: #e2e8f0;
            border-radius: 7px;
            cursor: pointer;
            font-size: 11px
        }

        .wb-locked #wbCanvas {
            cursor: not-allowed !important
        }

        .wb-lock-label {
            font-size: 11px;
            color: #fbbf24;
            margin-right: 5px
        }
    </style>
</head>

<body>
    <div id="wbOverlay" class="wb-visible">
        <div class="wb-hdr"><a class="wb-page-back" href="<?= BASE_URL ?>/modules/chat/chat.php">Back to chat</a>
            <div class="wb-hdr-logo">&#9997;</div>
            <div class="wb-hdr-titles">
                <div class="wb-hdr-title" id="wbBoardName">Whiteboard Session</div>
                <div class="wb-hdr-sub">Collaborate in real time</div>
            </div>
            <div class="wb-hdr-center"><button class="wb-icon-btn" onclick="wbUndo()" title="Undo">&#8630;</button><button class="wb-icon-btn" onclick="wbRedo()" title="Redo">&#8631;</button><select class="wb-zoom-sel" id="wbZoom" onchange="wbSetZoom(this.value)">
                    <option>50%</option>
                    <option>75%</option>
                    <option selected>100%</option>
                    <option>125%</option>
                    <option>150%</option>
                </select></div>
            <div class="wb-hdr-right"><span id="wbLockLabel" class="wb-lock-label"></span>
                <div class="wb-av-stack" id="wbAvStack"></div><button class="wb-page-btn" onclick="wbSaveVersion()">Save version</button><button class="wb-page-btn" onclick="wbToggleVersions()">Versions</button><button id="wbLockButton" class="wb-page-btn" onclick="wbToggleLock()" hidden>Lock</button>
            </div>
        </div>
        <div class="wb-body">
            <div class="wb-tools"><button class="wb-tbtn wb-active" data-tool="cursor" data-tip="Select" onclick="wbPickTool(this)">&#9654;</button><button class="wb-tbtn" data-tool="pen" data-tip="Pen" onclick="wbPickTool(this)">&#9998;</button><button class="wb-tbtn" data-tool="highlight" data-tip="Highlight" onclick="wbPickTool(this)">&#9644;</button><button class="wb-tbtn" data-tool="eraser" data-tip="Eraser" onclick="wbPickTool(this)">&#9003;</button>
                <div class="wb-tsep"></div><button class="wb-tbtn" data-tool="text" data-tip="Text" onclick="wbPickTool(this)">T</button><button class="wb-tbtn" data-tool="sticky" data-tip="Sticky note" onclick="wbPickTool(this)">&#9632;</button><button class="wb-tbtn" data-tool="arrow" data-tip="Arrow" onclick="wbPickTool(this)">&#8594;</button>
            </div>
            <div class="wb-canvas-wrap" id="wbCanvasWrap"><canvas id="wbCanvas"></canvas>
                <div id="wbObjects"></div>
                <div class="wb-bottom-bar">
                    <div class="wb-clr sel" style="background:#a855f7" onclick="wbClr(this,'#a855f7')"></div>
                    <div class="wb-clr" style="background:#fff" onclick="wbClr(this,'#fff')"></div>
                    <div class="wb-clr" style="background:#22c55e" onclick="wbClr(this,'#22c55e')"></div>
                    <div class="wb-vsep"></div><button class="wb-sz-btn" onclick="wbSzDown()">-</button><span class="wb-sz-lbl" id="wbSzLbl">2px</span><button class="wb-sz-btn" onclick="wbSzUp()">+</button>
                </div>
            </div>
            <div class="wb-rsidebar" id="wbRightSidebar">
                <div class="wb-tabs">
                    <div class="wb-tab wb-tab-active" onclick="wbTab(this,'wbPanMembers')">Members</div>
                    <div class="wb-tab" onclick="wbTab(this,'wbPanActivity')">Activity</div>
                </div>
                <div class="wb-panel wb-panel-active" id="wbPanMembers">
                    <div class="wb-section-lbl" id="wbMembersLabel">WHITEBOARD MEMBERS - 0</div>
                    <div id="wbMemberList"></div>
                </div>
                <div class="wb-panel" id="wbPanActivity">
                    <div class="wb-section-lbl">ACTIVITY</div>
                    <div class="wb-activity" id="wbActivityLog"></div>
                </div>
            </div>
        </div>
        <div class="wb-status">
            <div class="wb-status-item">&#9679; <span id="wbSaveLabel">Unsaved session</span></div>
            <div class="wb-status-item" id="wbSessionStatus">Connecting...</div><button class="wb-share-btn" onclick="wbSaveVersion()">Save snapshot</button>
        </div>
        <div id="wbVersionPanel" class="wb-version-panel">
            <h3>Saved versions</h3>
            <div id="wbVersionList">Loading...</div>
        </div>
    </div>
    <script>
        window.ECOLLAB = <?= json_encode(['baseUrl' => BASE_URL, 'wsUrl' => WS_URL, 'csrfToken' => $csrf, 'userId' => (int)$user['id'], 'username' => $user['username'], 'currentChannelId' => $channelId, 'whiteboardStandalone' => true], JSON_UNESCAPED_SLASHES) ?>;
        window.__USER__ = {
            id: <?= (int)$user['id'] ?>,
            username: <?= json_encode($user['username']) ?>,
            role: <?= json_encode($user['role']) ?>
        };
        window.__currentChannelId = <?= $channelId ?>;

        function showToast(message) {
            var el = document.getElementById('wbSessionStatus');
            if (el) el.textContent = message;
        }
    </script>
    <script src="<?= BASE_URL ?>/assets/js/chat/socket-core.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/chat/whiteboard.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            connectWebSocket();
            openWhiteboard('Whiteboard Session', null, <?= $channelId ?>);
            wbLoadVersions();
        });
    </script>
</body>

</html>