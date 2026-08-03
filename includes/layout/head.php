<?php

declare(strict_types=1);
/**
 * includes/layout/head.php
 * Usage: <?php include ROOT_PATH . '/includes/layout/head.php'; ?>
 * Expects: $pageTitle (string), $extraCss (array of paths, optional)
 */
$pageTitle   = $pageTitle   ?? 'Ecollab';
$extraCss    = $extraCss    ?? [];
$bodyClass   = $bodyClass   ?? '';
if (!defined('BASE_URL')) {
    require_once dirname(__DIR__, 2) . '/config.php';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – Ecollab</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Base variables -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/variables.css">

    <?php foreach ($extraCss as $css): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($css, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

    <!-- CSRF meta tag for JS -->
    <meta name="csrf-token" content="<?= htmlspecialchars(\CSRF::token(), ENT_QUOTES, 'UTF-8') ?>">
</head>

<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">