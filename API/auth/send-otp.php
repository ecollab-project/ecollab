<?php
declare(strict_types=1);

// This obsolete route stored pending_otp, which no verification route consumes.
// Signup uses send-signup-email-otp.php; login and password-reset codes are
// issued by AuthService/OtpService. Do not create an unverifiable legacy code.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'success' => false,
    'error' => 'This verification route has been retired. Please use the signup, login, or password-reset form.',
]);
