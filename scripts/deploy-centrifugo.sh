#!/usr/bin/env bash
# Run as ecollabadmin after pulling ecollab-collabs; optional previous deployed SHA.
set -euo pipefail
umask 077
cd "$(dirname "${BASH_SOURCE[0]}")/.."
[ "$(git branch --show-current)" = ecollab-collabs ] || { echo 'Switch to ecollab-collabs first'; exit 1; }
git diff --quiet && git diff --cached --quiet
ECOLLAB_PREVIOUS="$(git rev-parse --verify "${1:-f792858}^{commit}")"
ECOLLAB_DELIVERY_BACKUP="$HOME/ecollab-backups/centrifugo-$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$ECOLLAB_DELIVERY_BACKUP"
printf '%s\n' "$ECOLLAB_PREVIOUS" > "$ECOLLAB_DELIVERY_BACKUP/deployed-commit.txt"
sudo mariadb-dump --all-databases --single-transaction --routines --events --triggers |
  gzip > "$ECOLLAB_DELIVERY_BACKUP/database.sql.gz"
gzip -t "$ECOLLAB_DELIVERY_BACKUP/database.sql.gz"
sudo tar -czf "$ECOLLAB_DELIVERY_BACKUP/configuration.tar.gz" -C / etc/nginx etc/systemd/system

git diff --name-only --diff-filter=AM -z "$ECOLLAB_PREVIOUS" HEAD | sudo xargs -0 -r chgrp www-data
git diff --name-only --diff-filter=AM -z "$ECOLLAB_PREVIOUS" HEAD | sudo xargs -0 -r chmod g+r
sudo chgrp www-data scripts services API/auth API/dm assets/js/chat
sudo chmod g+rx scripts services API/auth API/dm assets/js/chat
sudo chmod 755 deploy deploy/centrifugo
sudo chmod 644 deploy/centrifugo/config.json

php tests/centrifugo-regression.php
php database/migrate.php
php scripts/configure-centrifugo.php prepare
docker compose -f deploy/centrifugo/compose.yaml up -d
ECOLLAB_BROKER_READY=0
for attempt in {1..30}; do
  if php scripts/check-centrifugo.php >/dev/null 2>&1; then ECOLLAB_BROKER_READY=1; break; fi
  sleep 1
done
if [ "$ECOLLAB_BROKER_READY" -ne 1 ]; then
  docker logs --tail 30 ecollab-centrifugo
  echo 'Broker not ready; delivery remains disabled.'
  exit 1
fi
sudo python3 scripts/install-centrifugo-nginx.py
sudo systemctl reload nginx

ECOLLAB_DELIVERY_ENABLED=0
on_delivery_failure() {
  if [ "$ECOLLAB_DELIVERY_ENABLED" -eq 1 ]; then
    php scripts/configure-centrifugo.php disable || true
    sudo systemctl stop ecollab-realtime || true
    sudo systemctl reload php8.3-fpm || true
    echo 'Deployment check failed; optional delivery disabled. Review the error above.'
  fi
}
trap on_delivery_failure ERR
php scripts/configure-centrifugo.php enable
ECOLLAB_DELIVERY_ENABLED=1
sudo install -m 644 deploy/centrifugo/ecollab-realtime.service /etc/systemd/system/ecollab-realtime.service
sudo systemctl daemon-reload
sudo systemctl enable ecollab-realtime
sudo systemctl restart ecollab-realtime
sudo systemctl reload php8.3-fpm
sudo systemctl is-active ecollab-realtime
sudo -u www-data php /home/ecollabadmin/ecollab-inspect/scripts/check-centrifugo.php
printf 'Centrifugo enabled. Backup: %s\nReload both chat tabs and test text/image delivery and reconnects.\n' "$ECOLLAB_DELIVERY_BACKUP"
