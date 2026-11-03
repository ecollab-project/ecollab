<?php
declare(strict_types=1);
namespace Tests\Support;
/** In-memory delivery transport; never sends mail or logs plaintext codes. */
final class RecordingOtpService extends \OtpService
{
    public array $messages = [];
    public bool $failDelivery = false;
    public function deliver(string $toEmail, string $toName, string $otp, string $action = 'reset_password'): array
    {
        if ($this->failDelivery) return ['success' => false, 'error' => 'Fixture mail unavailable.'];
        $this->messages[] = ['email' => $toEmail, 'otp' => $otp, 'action' => $action];
        return ['success' => true];
    }
    public function latestCode(): string { return $this->messages[array_key_last($this->messages)]['otp']; }
}
