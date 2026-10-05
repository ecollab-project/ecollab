<?php
// Shared by authenticated pages; do not expose call controls inside embedded editors.
if (!empty($_SESSION['user_id']) && !defined('ECOLLAB_CALLS_LOADED')):
    define('ECOLLAB_CALLS_LOADED', true);
    $callNonce = $nonce ?? (class_exists('SecurityHeaders') ? SecurityHeaders::nonce() : '');
    $callConfig = [
        'scriptNonce' => $callNonce,
        'baseUrl' => BASE_URL,
        'wsUrl' => defined('WS_URL') ? WS_URL : '',
        'userId' => (int)$_SESSION['user_id'],
        'csrfToken' => AuthMiddleware::csrfToken(),
    ];
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>/assets/css/calls/calls.css?v=2">
<script nonce="<?=htmlspecialchars($callNonce, ENT_QUOTES)?>">window.ECOLLAB_CALLS_CONFIG=<?=json_encode($callConfig, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script>
<script nonce="<?=htmlspecialchars($callNonce, ENT_QUOTES)?>" src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>/assets/js/calls/media-settings.js?v=2" defer></script>
<script nonce="<?=htmlspecialchars($callNonce, ENT_QUOTES)?>" src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>/assets/js/chat/dm-call.js?v=calls-2" defer></script>
<?php endif; ?>
