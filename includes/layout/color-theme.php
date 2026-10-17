<?php
require_once ROOT_PATH.'/services/ColorPaletteService.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/color-palettes.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/color-theme.css?v=1">
<script type="application/json" id="ecColorPalettes"><?= json_encode(ColorPaletteService::all(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>/assets/js/color-theme.js?v=1" defer></script>
