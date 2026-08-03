<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';

header('Content-Type: application/json');

AuthMiddleware::startSession();
CSRF::verify();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim($body['email'] ?? ''));

if ($email === '') {
    echo json_encode(['success' => false, 'error' => 'Email is required.']);
    exit;
}

// Rate-limit: max 3 OTPs per email per 10 minutes (session-based for simplicity)
$otpKey  = 'otp_email_' . md5($email);
$otpData = $_SESSION[$otpKey] ?? null;
if ($otpData && $otpData['count'] >= 3 && (time() - $otpData['first_sent']) < 600) {
    echo json_encode(['success' => false, 'error' => 'Too many requests. Wait a few minutes.']);
    exit;
}

// Generate 6-digit OTP
$otpPlain  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otpHashed = password_hash($otpPlain, PASSWORD_BCRYPT, ['cost' => 10]);
$otpExpiry = time() + 600; // 10 min

// Store in session
$_SESSION['pending_otp']       = $otpHashed;
$_SESSION['pending_otp_email'] = $email;
$_SESSION['pending_otp_expiry']= $otpExpiry;
$_SESSION[$otpKey] = [
    'count'      => (($otpData['count'] ?? 0) + 1),
    'first_sent' => $otpData['first_sent'] ?? time(),
];

// Send email (if mail is configured)
$sent = false;
if (defined('MAIL_HOST') && MAIL_HOST !== '' && MAIL_HOST !== 'localhost') {
    // Use OtpService if available, otherwise basic mail()
    $subject = 'Your ' . APP_NAME . ' Verification Code';
    $body    = "Your verification code is: {$otpPlain}\n\nThis code expires in 10 minutes.\n\nIf you didn't request this, ignore this email.";
    $headers = "From: " . MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8";
    $sent    = @mail($email . '@fatima.edu.ph', $subject, $body, $headers);
}

$response = ['success' => true];

// In debug mode, return the OTP in the response for local testing
if (defined('APP_DEBUG') && APP_DEBUG) {
    $response['otp_debug'] = $otpPlain;
}

echo json_encode($response);
