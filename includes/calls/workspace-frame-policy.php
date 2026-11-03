<?php
declare(strict_types=1);
// Call only after the page's existing authentication/role checks, before output.
// Permit the same-origin call shell; cross-origin framing remains blocked.
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
