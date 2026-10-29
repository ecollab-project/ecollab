mkdir -p phase3ad-raw
python3 -B tests/phase3ad-scope.py phase3ad-raw/scope.json
for file in tests/phase3ac-*.php tests/phase3ad-*.php services/MessageService.php API/chat/send-message.php API/chat/vote-poll.php security/forgot-password/OtpService.php; do php8.3 -l "$file"; done
node --check tests/phase3ac-browser.cjs
for file in tests/phase3aa-*.php tests/experiments/phase3aa/*.php; do php8.3 -l "$file"; done
php8.3 -l tests/phase3z-clock.php
php8.3 -l tests/phase3y-bootstrap.php
php8.3 -l tests/phase3y-control.php
php8.3 -l tests/Integration/OtpExpiryTest.php
node --check tests/phase3y-browser.cjs
node --check assets/js/auth/signup.js
node --check assets/js/auth/signup-network-fix.js
node --check assets/js/auth/login.js
mkdir -p phase3ad-raw
python3 -B tests/phase3ad-inventory.py phase3ad-raw/inventory.json
node --check tests/phase3ad-browser.cjs
php8.3 -l tests/phase3ad-fixture.php
python3 -B tests/phase3ac-inventory.py phase3ad-raw/writers.json
php8.3 tests/client-ip-regression.php
node tests/request-security-regression.cjs
