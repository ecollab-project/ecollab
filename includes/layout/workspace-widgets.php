<?php require_once ROOT_PATH . '/services/WorkspaceCustomization.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/workspace-widgets.css?v=dock-1">
<script type="application/json" id="ecWorkspaceWidgetCatalog"><?= json_encode(WorkspaceCustomization::WIDGETS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script defer src="<?= BASE_URL ?>/assets/js/workspace-widgets.js?v=channel-sidebar-1"></script>
