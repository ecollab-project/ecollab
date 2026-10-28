<?php
declare(strict_types=1);

namespace Tests\Support;

/** Only outbound delivery is replaced. Generation, hashes and verification stay real. */
final class LocalOtpTransport extends \OtpService
{
    /** @var array<string, string> Ephemeral synthetic codes; never written to evidence. */
    private array $codes = [];

    public function deliver(string $toEmail, string $toName, string $otp, string $action = 'reset_password'): array
    {
        $this->codes[$action . ':' . strtolower($toEmail)] = $otp;
        return ['success' => true];
    }

    public function codeFor(string $email, string $action): string
    {
        return $this->codes[$action . ':' . strtolower($email)]
            ?? throw new \LogicException('No local delivery for this synthetic identity/action');
    }
}
