#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "${BASH_SOURCE[0]}")/.."
[ "$(git branch --show-current)" = ecollab-collabs ] || { echo 'Switch to ecollab-collabs first'; exit 1; }
git diff --quiet && git diff --cached --quiet
ECOLLAB_COMPOSER_BACKUP="$HOME/ecollab-backups/composer-$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$ECOLLAB_COMPOSER_BACKUP"
git rev-parse --verify "${1:-87cd08b}^{commit}" > "$ECOLLAB_COMPOSER_BACKUP/deployed-commit.txt"
sudo tar -czf "$ECOLLAB_COMPOSER_BACKUP/chat-assets-and-environment.tar.gz" \
  assets/js/chat assets/css modules/chat/chat.php .env
ECOLLAB_COMPOSER_FILES=(
  assets/js/chat/rich-composer.js assets/js/chat/rich-composer.NOTICES.txt
  assets/css/chat-composer.css assets/js/chat/chat.js assets/js/chat/chat-features.js
  assets/js/chat/dm-notifications.js assets/js/chat/socket.js assets/js/chat/socket-core.js
  modules/chat/chat.php
)
sudo chgrp www-data "${ECOLLAB_COMPOSER_FILES[@]}" assets/css assets/js/chat modules/chat
sudo chmod g+r "${ECOLLAB_COMPOSER_FILES[@]}"
sudo chmod g+rx assets/css assets/js/chat modules/chat
php -l modules/chat/chat.php
php scripts/configure-chat-composer.php enable
sudo systemctl reload php8.3-fpm
printf 'Chat composer enabled. Backup: %s\nHard-refresh both chat pages, then test formatting, drafts, mentions and attachments.\n' "$ECOLLAB_COMPOSER_BACKUP"
