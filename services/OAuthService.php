<?php

declare(strict_types=1);

/**
 * services/OAuthService.php
 *
 * Handles the full OAuth 2.0 Authorization Code flow for Google and Microsoft.
 * No external library needed — uses PHP's native curl / file_get_contents.
 *
 * Usage:
 *   $svc = new OAuthService();
 *
 *   // Step 1 — redirect user to provider
 *   header('Location: ' . $svc->getAuthUrl('google'));
 *
 *   // Step 2 — in the callback handler
 *   $result = $svc->handleCallback('google', $_GET['code'], $_GET['state']);
 *   // $result['user'] = ['id','email','full_name','avatar_url','email_verified']
 */
class OAuthService
{
    // ── Google ────────────────────────────────────────────────────────────────
    private const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_USER_URL  = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const GOOGLE_SCOPES    = 'openid email profile';

    // ── Microsoft ─────────────────────────────────────────────────────────────
    private const MS_AUTH_URL_TPL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize';
    private const MS_TOKEN_URL_TPL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const MS_USER_URL      = 'https://graph.microsoft.com/v1.0/me';
    private const MS_SCOPES        = 'openid email profile User.Read offline_access';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Build the provider authorization URL and store a PKCE state in the session.
     *
     * @param  string $provider  'google' | 'microsoft'
     * @return string            URL to redirect the browser to
     */
    public function getAuthUrl(string $provider): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        // Generate a random state and store it to prevent CSRF
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state']    = $state;
        $_SESSION['oauth_provider'] = $provider;

        return match ($provider) {
            'google'    => $this->buildGoogleUrl($state),
            'microsoft' => $this->buildMicrosoftUrl($state),
            default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    /**
     * Handle the callback from the provider.
     * Verifies state, exchanges code for tokens, fetches user info,
     * then upserts the user in the database and writes the session.
     *
     * @return array  ['success' => bool, 'redirect' => string, 'error' => string]
     */
    public function handleCallback(string $provider, string $code, string $state): array
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        // ── CSRF state check ─────────────────────────────────────────────────
        $expectedState    = $_SESSION['oauth_state']    ?? '';
        $expectedProvider = $_SESSION['oauth_provider'] ?? '';

        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

        if ($state === '' || !hash_equals($expectedState, $state)) {
            return ['success' => false, 'error' => 'Invalid OAuth state. Please try again.'];
        }
        if ($expectedProvider !== $provider) {
            return ['success' => false, 'error' => 'Provider mismatch. Please try again.'];
        }
        if ($code === '') {
            return ['success' => false, 'error' => 'No authorization code received.'];
        }

        // ── Exchange code for tokens ─────────────────────────────────────────
        try {
            $tokens = match ($provider) {
                'google'    => $this->exchangeGoogleCode($code),
                'microsoft' => $this->exchangeMicrosoftCode($code),
                default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
            };
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Token exchange failed: ' . $e->getMessage()];
        }

        // ── Fetch user info from provider ────────────────────────────────────
        try {
            $providerUser = match ($provider) {
                'google'    => $this->fetchGoogleUser($tokens['access_token']),
                'microsoft' => $this->fetchMicrosoftUser($tokens['access_token']),
                default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Failed to fetch user info: ' . $e->getMessage()];
        }

        // ── Upsert user in DB and write session ──────────────────────────────
        try {
            $user     = $this->upsertOAuthUser($provider, $providerUser);
            $redirect = $this->buildRedirect($user['role']);
            $this->writeSession($user);
            return ['success' => true, 'redirect' => $redirect];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Account setup failed: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // GOOGLE
    // =========================================================================

    private function buildGoogleUrl(string $state): string
    {
        return self::GOOGLE_AUTH_URL . '?' . http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::GOOGLE_SCOPES,
            'state'         => $state,
            'access_type'   => 'offline',
            'prompt'        => 'select_account',
        ]);
    }

    private function exchangeGoogleCode(string $code): array
    {
        return $this->postJson(self::GOOGLE_TOKEN_URL, [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
    }

    private function fetchGoogleUser(string $accessToken): array
    {
        $data = $this->getJson(self::GOOGLE_USER_URL, $accessToken);

        if (empty($data['email'])) {
            throw new \RuntimeException('Google did not return an email address.');
        }

        return [
            'provider_id'    => $data['sub'],
            'email'          => strtolower(trim($data['email'])),
            'full_name'      => trim($data['name'] ?? ''),
            'avatar_url'     => $data['picture'] ?? null,
            'email_verified' => !empty($data['email_verified']),
        ];
    }

    // =========================================================================
    // MICROSOFT
    // =========================================================================

    private function buildMicrosoftUrl(string $state): string
    {
        $tenant = MICROSOFT_TENANT_ID ?: 'common';
        return sprintf(self::MS_AUTH_URL_TPL, $tenant) . '?' . http_build_query([
            'client_id'     => MICROSOFT_CLIENT_ID,
            'redirect_uri'  => MICROSOFT_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::MS_SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    private function exchangeMicrosoftCode(string $code): array
    {
        $tenant = MICROSOFT_TENANT_ID ?: 'common';
        return $this->postJson(sprintf(self::MS_TOKEN_URL_TPL, $tenant), [
            'code'          => $code,
            'client_id'     => MICROSOFT_CLIENT_ID,
            'client_secret' => MICROSOFT_CLIENT_SECRET,
            'redirect_uri'  => MICROSOFT_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
            'scope'         => self::MS_SCOPES,
        ]);
    }

    private function fetchMicrosoftUser(string $accessToken): array
    {
        $data = $this->getJson(self::MS_USER_URL, $accessToken);

        // Microsoft Graph: mail > userPrincipalName
        $email = strtolower(trim($data['mail'] ?? $data['userPrincipalName'] ?? ''));
        if ($email === '') {
            throw new \RuntimeException('Microsoft did not return an email address.');
        }

        $fullName = trim(($data['givenName'] ?? '') . ' ' . ($data['surname'] ?? ''));
        if ($fullName === '') $fullName = $data['displayName'] ?? '';

        return [
            'provider_id'    => $data['id'],
            'email'          => $email,
            'full_name'      => trim($fullName),
            'avatar_url'     => null, // MS Graph photo requires a separate call; skip for simplicity
            'email_verified' => true, // Microsoft accounts are always verified
        ];
    }

    // =========================================================================
    // DB UPSERT
    // =========================================================================

    /**
     * Find or create the user for this OAuth identity.
     * Priority order:
     *   1. Match on (oauth_provider + oauth_id)   — returning user, same device
     *   2. Match on email                          — link existing account
     *   3. Create new account                      — first-time SSO user
     */
    private function upsertOAuthUser(string $provider, array $p): array
    {
        // 1. Look up by provider + provider ID
        $stmt = $this->db->prepare("
            SELECT id, username, email, full_name, role,
                   avatar_color_gradient, plan_id
            FROM users
            WHERE sso_provider = :prov AND sso_uid = :oid
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':prov' => $provider, ':oid' => $p['provider_id']]);
        $user = $stmt->fetch();

        if ($user) {
            // Refresh avatar URL and last seen
            $this->db->prepare("
                UPDATE users
                SET status = 'active', is_online = 1, last_seen_at = NOW()
                WHERE id = :id
            ")->execute([':id' => $user['id']]);
            return $user;
        }

        // 2. Look up by email — link existing account to this provider
        //    Only safe when the provider has verified the email address.
        if (!$p['email_verified']) {
            // Treat unverified emails as new accounts to prevent account takeover
            return $this->createOAuthUser($provider, $p);
        }

        $stmt = $this->db->prepare("
            SELECT id, username, email, full_name, role,
                   avatar_color_gradient, plan_id
            FROM users
            WHERE email = :email AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':email' => $p['email']]);
        $user = $stmt->fetch();

        if ($user) {
            // Attach OAuth identity to existing account
            $this->db->prepare("
                UPDATE users
                SET sso_provider     = :prov,
                    sso_uid          = :oid,
                    email_verified   = 1,
                    status           = 'active',
                    is_online        = 1,
                    last_seen_at     = NOW()
                WHERE id = :id
            ")->execute([
                ':prov' => $provider,
                ':oid'  => $p['provider_id'],
                ':id'   => $user['id'],
            ]);
            return $user;
        }

        // 3. Brand new user — create account
        return $this->createOAuthUser($provider, $p);
    }

    private function createOAuthUser(string $provider, array $p): array
    {
        // Derive username from email prefix
        $base     = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $p['email'])[0]));
        $base     = substr($base ?: 'user', 0, 20);
        $check    = $this->db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $username = $base;
        $i        = 1;
        while (true) {
            $check->execute([':u' => $username]);
            if (!$check->fetchColumn()) break;
            $username = $base . $i++;
        }

        // Pick a random gradient
        $gradients = [
            '#FF2D75,#9F3BFF', '#FF4D4D,#FF2D75', '#3B82FF,#9F3BFF',
            '#10B981,#3B82FF', '#F59E0B,#EF4444', '#8B5CF6,#EC4899',
        ];
        $gradient = $gradients[array_rand($gradients)];

        // Lookup institution by email domain
        $domain  = ltrim(strstr($p['email'], '@') ?: ('@' . DEFAULT_INSTITUTION_DOMAIN), '@');
        $instStmt = $this->db->prepare("SELECT id FROM institutions WHERE domain = :d LIMIT 1");
        $instStmt->execute([':d' => $domain]);
        $instId = $instStmt->fetchColumn() ?: null;

        $ins = $this->db->prepare("
            INSERT INTO users
                (institution_id, username, email, password_hash,
                 full_name, avatar_color_gradient, sso_provider, sso_uid, avatar_url,
                 email_verified, role, status, created_at, updated_at)
            VALUES
                (:inst, :uname, :email, NULL,
                 :name, :grad, :prov, :oid, :av,
                 :ev, 'student', 'active', NOW(), NOW())
        ");
        $ins->execute([
            ':inst'  => $instId,
            ':uname' => $username,
            ':email' => $p['email'],
            ':name'  => $p['full_name'] ?: $username,
            ':grad'  => $gradient,
            ':prov'  => $provider,
            ':oid'   => $p['provider_id'],
            ':av'    => $p['avatar_url'],
            ':ev'    => $p['email_verified'] ? 1 : 0,
        ]);
        $userId = (int)$this->db->lastInsertId();

        // Minimal profile row
        $this->db->prepare("
            INSERT IGNORE INTO user_profiles (user_id) VALUES (:uid)
        ")->execute([':uid' => $userId]);

        return [
            'id'                    => $userId,
            'username'              => $username,
            'email'                 => $p['email'],
            'full_name'             => $p['full_name'] ?: $username,
            'role'                  => 'student',
            'avatar_color_gradient' => $gradient,
        ];
    }

    // =========================================================================
    // SESSION + REDIRECT
    // =========================================================================

    private function writeSession(array $user): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_regenerate_id(true);
        $_SESSION['user_id']               = (int)$user['id'];
        $_SESSION['username']              = $user['username'];
        $_SESSION['email']                 = $user['email'];
        $_SESSION['full_name']             = $user['full_name'];
        $_SESSION['role']                  = $user['role'];
        $_SESSION['plan_id']               = $user['plan_id'] ?? null;
        $_SESSION['avatar_gradient']       = $user['avatar_color_gradient'] ?? '#a855f7,#ec4899';
        $_SESSION['avatar_color_gradient'] = $user['avatar_color_gradient'] ?? '#a855f7,#ec4899';
        $_SESSION['logged_in_at']          = time();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    private function buildRedirect(string $role): string
    {
        return match (true) {
            in_array($role, ['admin', 'super_admin', 'moderator'], true)
                => BASE_URL . '/modules/admin/dashboard.php',
            $role === 'facilitator'
                => BASE_URL . '/modules/facilitator/dashboard.php',
            default
                => BASE_URL . '/modules/chat/chat.php',
        };
    }

    // =========================================================================
    // HTTP HELPERS
    // =========================================================================

    /** POST form-encoded, return decoded JSON. */
    private function postJson(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("cURL error: $err");

        $data = json_decode($body, true);
        if (!is_array($data)) throw new \RuntimeException("Invalid JSON response from token endpoint.");
        if (!empty($data['error'])) {
            throw new \RuntimeException($data['error_description'] ?? $data['error']);
        }
        return $data;
    }

    /** GET with Bearer token, return decoded JSON. */
    private function getJson(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("cURL error: $err");

        $data = json_decode($body, true);
        if (!is_array($data)) throw new \RuntimeException("Invalid JSON response from userinfo endpoint.");
        if (!empty($data['error'])) {
            throw new \RuntimeException($data['error']['message'] ?? $data['error']);
        }
        return $data;
    }
}
