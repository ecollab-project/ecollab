<?php
declare(strict_types=1);
/**
 * includes/layout/footer.php
 * Expects: $extraJs (array of script paths, optional)
 */
$extraJs = $extraJs ?? [];
?>
<?php foreach ($extraJs as $js): ?>
<script src="<?= htmlspecialchars($js, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
