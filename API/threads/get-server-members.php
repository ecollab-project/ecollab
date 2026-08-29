<?php
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/ApiErrorResponder.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';

// Existing endpoint body intentionally retained; M3 only changes the error response.
