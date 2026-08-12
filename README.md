# Ecollab — Auth & Landing Platform

Production-ready PHP authentication system built from `index.html`, `login.html`, and `signup.html`.
Preserves pixel-level UI/UX, dark theme, gradients, animations, and layout from all three source files.

---

## Stack

| Layer      | Technology                                        |
|------------|---------------------------------------------------|
| Frontend   | Vanilla JS (modular), extracted CSS with tokens   |
| Backend    | PHP 8.1+ with PDO prepared statements             |
| Auth       | Session-based + CSRF + Rate Limiting              |
| Database   | MySQL 8 / MariaDB 10.6+ (shared schema)          |
| Security   | bcrypt passwords, CSRF tokens, rate limiting      |

---

## Directory Structure

```
ecollab-auth/
├── index.php                         # Landing page (converted from index.html)
├── config.php                        # Loads .env, defines all constants
├── .env.example                      # Environment config template
├── .htaccess                         # Routing, security headers, PHP hardening
├── composer.json
│
├── database/config/
│   └── db.php                        # PDO singleton
│
├── security/
│   ├── csrf/csrf.php                 # CSRF token generation & verification
│   ├── middleware/
│   │   ├── AuthMiddleware.php        # Session auth gate & redirect helpers
│   │   └── RoleMiddleware.php        # Role-based access control
│   └── rate-limit/
│       └── RateLimiter.php           # DB-backed sliding window rate limiter
│
├── services/
│   └── AuthService.php               # All auth business logic (login, register, OTP…)
│
├── modules/auth/
│   ├── login.php                     # Login page (converted from login.html)
│   ├── signup.php                    # 3-step signup page (from signup.html)
│   ├── forgot-password.php           # Forgot password / OTP / reset page
│   └── logout.php                    # Session destroy + redirect
│
├── API/auth/
│   ├── login.php                     # POST /API/auth/login.php
│   ├── signup.php                    # POST /API/auth/signup.php
│   ├── logout.php                    # POST /API/auth/logout.php
│   ├── forgot-password.php           # POST /API/auth/forgot-password.php
│   ├── verify-otp.php                # POST /API/auth/verify-otp.php
│   ├── reset-password.php            # POST /API/auth/reset-password.php
│   ├── refresh-session.php           # GET  /API/auth/refresh-session.php
│   ├── validate-session.php          # GET  /API/auth/validate-session.php
│   └── csrf-token.php                # GET  /API/auth/csrf-token.php
│
├── includes/layout/
│   ├── head.php                      # Reusable <head> include
│   └── footer.php                    # Reusable footer/script include
│
└── assets/
    ├── css/
    │   ├── desktop/
    │   │   ├── variables.css         # Design tokens (shared across all pages)
    │   │   ├── auth.css              # Login + Signup + Forgot password styles
    │   │   └── landing.css           # Index/landing page styles (extracted)
    │   └── mobile/
    │       ├── auth-mobile.css       # Auth mobile breakpoints
    │       └── landing-mobile.css    # Landing mobile breakpoints
    └── js/
        ├── landing.js                # Landing page interactions (extracted)
        └── auth/
            ├── login.js              # Login form, particles, CSRF
            ├── signup.js             # 3-step wizard, validation, API call
            └── forgot-password.js    # Email → OTP → reset flow
```

---

## Installation

### 1 — Prerequisites

- PHP 8.1+ with extensions: `pdo_mysql`, `session`, `openssl`
- MySQL 8+ or MariaDB 10.6+
- Apache with `mod_rewrite` (or Nginx equivalent)
- Composer

### 2 — Setup

```bash
# Copy the project to your web root
cp -r ecollab-auth/ /var/www/ecollab/

# Create .env from template
cp /var/www/ecollab/.env.example /var/www/ecollab/.env
nano /var/www/ecollab/.env          # Fill in your values
```

### 3 — Database

The schema is shared with your teammates. Run in order:

```bash
mysql -u root -p ecollab < schema.txt
mysql -u root -p ecollab < seeds.txt
```

The `rate_limit_log` and `otp_codes` tables are auto-created by the application on first use.

### 4 — Configure .env

```env
DB_HOST=127.0.0.1
DB_NAME=ecollab
DB_USER=your_user
DB_PASS=your_password
APP_DEBUG=false         # Set true only in development
SESSION_SECURE=true     # Set false for local HTTP dev
```

### 5 — Apache VirtualHost

```apache
<VirtualHost *:80>
    ServerName ecollab.local
    DocumentRoot /var/www/ecollab
    DirectoryIndex index.php

    <Directory /var/www/ecollab>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 6 — Nginx equivalent

```nginx
server {
    listen 80;
    root /var/www/ecollab;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block sensitive directories
    location ~ ^/(database|security|services|includes)/ { deny all; }
    location ~ /\.env { deny all; }
}
```

---

## API Reference

All endpoints accept and return JSON. Mutating endpoints require `X-CSRF-Token` header.

**This section covers the 9 auth endpoints in detail. For every other endpoint (chat, peer-matching, collaboration tools, DMs, friendships, servers, notifications, profiles, and the admin/facilitator/student dashboards — 56 files in total), see [`docs/API_REFERENCE.md`](docs/API_REFERENCE.md).**

| Method | Endpoint                          | Description                     | Auth |
|--------|-----------------------------------|---------------------------------|------|
| POST   | `/API/auth/login.php`             | Login with email + password     | No   |
| POST   | `/API/auth/signup.php`            | Register new account            | No   |
| POST   | `/API/auth/logout.php`            | Destroy session                 | Yes  |
| POST   | `/API/auth/forgot-password.php`   | Send OTP to email               | No   |
| POST   | `/API/auth/verify-otp.php`        | Verify OTP, receive reset token | No   |
| POST   | `/API/auth/reset-password.php`    | Set new password                | No   |
| GET    | `/API/auth/validate-session.php`  | Check session status            | No   |
| GET    | `/API/auth/refresh-session.php`   | Slide session expiry            | Yes  |
| GET    | `/API/auth/csrf-token.php`        | Get current CSRF token          | No   |

### Request / Response examples

**POST /API/auth/login.php**
```json
// Request
{ "identifier": "john@fatima.edu.ph", "password": "Password123!", "remember": true }

// Success
{ "success": true, "redirect": "/modules/student/dashboard.php", "role": "student" }

// Failure
{ "success": false, "error": "Incorrect password. Please try again." }
```

**POST /API/auth/signup.php**
```json
// Request
{
  "full_name": "John Doe", "email": "john@fatima.edu.ph",
  "password": "Password123!", "course": "BS Computer Science",
  "year_level": 2, "study_style": "Group", "primary_goal": "Build projects",
  "interests": ["AI", "Web Dev"], "terms_agreed": true
}

// Success
{ "success": true, "redirect": "/modules/student/dashboard.php", "username": "john" }
```

---

## Session Fields Written on Login/Register

```php
$_SESSION['user_id']         // int
$_SESSION['username']        // string
$_SESSION['email']           // string
$_SESSION['full_name']       // string
$_SESSION['role']            // admin|super_admin|moderator|facilitator|student
$_SESSION['avatar_gradient'] // e.g. "#FF2D75,#9F3BFF"
$_SESSION['plan_id']         // int
$_SESSION['logged_in_at']    // Unix timestamp
```

---

## Protecting Pages with Middleware

```php
// Require any logged-in user
$user = AuthMiddleware::requireAuth();

// Require specific roles
RoleMiddleware::requireRole(['admin', 'super_admin']);

// Require minimum role level
RoleMiddleware::requireMinRole('facilitator');

// API mode (returns JSON instead of redirect)
$user = AuthMiddleware::requireAuth(true);
```

---

## Security Features

- **PDO prepared statements** — zero raw SQL interpolation
- **CSRF protection** — synchronizer token on every mutating request
- **bcrypt** — configurable cost (default 12) with auto-rehash
- **Rate limiting** — sliding window, per-action, per-IP, stored in DB
- **Session hardening** — `HttpOnly`, `SameSite=Strict`, `Secure`, regenerate on login
- **Remember Me** — SHA-256 hashed token rotation
- **OTP expiry** — configurable TTL (default 10 min), hashed with bcrypt
- **XSS prevention** — all output escaped with `htmlspecialchars()`
- **Directory traversal** — `.htaccess` blocks non-public directories
- **Security headers** — CSP, X-Frame-Options, HSTS (via .htaccess)

---

## Credential Handling

- Copy `.env.example` to `.env` and fill in your own values. **Never commit `.env`.**
- **Never include a populated `.env` in any exported, shared, or archived copy of this project** — treat it the same as a password.
- If you suspect a credential has been exposed (e.g., shared in an archive, committed by mistake), see `SECURITY_CREDENTIAL_AUDIT.md` for how to assess exposure and `docs/CREDENTIAL_ROTATION.md` for step-by-step rotation instructions (Google OAuth, database, Anthropic API key).

---

## Test Credentials (after running seeds.txt)

| Email / Student ID       | Password       | Role        | Dashboard                     |
|--------------------------|----------------|-------------|-------------------------------|
| `admin@fatima.edu.ph`    | `Password123!` | super_admin | /modules/admin/dashboard.php  |
| `facilitator@fatima.edu.ph` | `Password123!` | facilitator | /modules/facilitator/         |
| `student@fatima.edu.ph`  | `Password123!` | student     | /modules/student/dashboard.php|
