# Ecollab VPS Publishing

This deployment branch is intended for the Hostinger VPS Docker environment.

## 1. Clone the publishing branch

```bash
git clone -b publish/ecollab-2026-09-17 https://github.com/ecollab-project/ecollab.git /opt/ecollab
cd /opt/ecollab
```

## 2. Create production environment

```bash
cp deploy/.env.production.example .env
nano .env
```

Set the real VPS IP/domain, strong database passwords, `APP_KEY`, mail credentials, OAuth credentials, and `WS_URL`.

Generate an application key with:

```bash
openssl rand -hex 32
```

For an HTTP-only first deployment use:

```text
APP_URL=http://YOUR_VPS_IP
WS_URL=ws://YOUR_VPS_IP:8080
SESSION_SECURE=false
```

After HTTPS is configured, change these to `https://` and `wss://` and set `SESSION_SECURE=true`.

## 3. Build and start the stack

```bash
docker compose build
docker compose up -d
```

Check:

```bash
docker compose ps
docker compose logs --tail=100 web
docker compose logs --tail=100 websocket
docker compose logs --tail=100 db
```

## 4. Apply the database migrations

The database is persistent in the `ecollab_db` Docker volume.

Run:

```bash
docker compose exec web php database/migrate.php --status
docker compose exec web php database/migrate.php
```

Then verify:

```bash
docker compose exec web php database/migrate.php --status
```

## 5. Import an existing local database instead of starting empty

Do not run a local XAMPP database import blindly over a production database.
Create a dump first, copy it to the VPS, and import it into the `ecollab-db` container after the database is initialized.

## 6. Verify services

Web application:

```text
http://YOUR_VPS_IP/
```

WebSocket:

```text
ws://YOUR_VPS_IP:8080
```

The WebSocket container runs `php websocket/bin/server.php` on port 8080.

## 7. Production updates

The publishing branch is intentionally separate from `ecollab-collabs`.

```bash
cd /opt/ecollab
git fetch origin
git checkout publish/ecollab-2026-09-17
git pull --ff-only origin publish/ecollab-2026-09-17
docker compose build
docker compose up -d
docker compose exec web php database/migrate.php
```

Never commit `.env` or production credentials to GitHub.
