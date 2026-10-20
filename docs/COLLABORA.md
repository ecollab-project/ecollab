# eCollab Collabora integration

This opt-in integration replaces the embedded ONLYOFFICE editor with a self-hosted Collabora WOPI client for DOCX, XLSX and PPTX. The default remains `DOCUMENT_EDITOR=onlyoffice` until deployment is configured. The integration branch is based on `lightfm-recommendation-prototype` so it preserves the book-event collection currently deployed. Do not merge or deploy the stacked branch without reviewing both changes.

## Implemented

- eCollab-authenticated launch, private/workspace sharing, explicit viewer/editor grants and workspace host/owner control. Every WOPI read and save rechecks active server membership, account status, workspace access and document permission.
- Random eight-hour bearer tokens stored as hashes and bound to user/document with a write-permission ceiling. Launch tokens travel in a POST form. WOPI requests use protocol-required query tokens; disable request-query logging on the WOPI route and avoid logging request bodies.
- Collabora timestamp conflict detection; database row serialization; immutable file publication; earlier revisions available to the owner/host. No WOPI lock support is advertised. Historical content is deliberately not exposed to ordinary viewers/editors.
- Download, private import (25 MB, DOCX/XLSX/PPTX, no VBA), owner rename, workspace sharing panel. Existing resource-sharing controls handle individual grants. Comments-only grants open read-only; comment-only editing is not implemented.
- Optional Jarred suggestions for explicitly submitted text via the existing configured Ollama service. No automatic content extraction or writes; users review and apply suggestions manually.
- Optional related-document suggestions using the existing local semantic model on authorized titles in the same workspace (most recent 100 candidates). This is pretrained semantic similarity, not LightFM training or a new collaborative-filtering model. It never grants access.

## Prerequisites and backup

Use a staging workspace first. Back up both the database and `uploads/collab-docs` together; version history depends on both. Close existing ONLYOFFICE sessions and confirm they saved before changing providers. Old ONLYOFFICE file/callback endpoints are disabled while Collabora is selected so the two editors cannot compete for saves.

Provide an HTTPS office hostname (examples use `office.ecollab.tech`) with DNS pointing to the server and a valid certificate. Collabora must be able to reach the public eCollab HTTPS hostname and eCollab must reach Collabora. Do not expose 9980 publicly. Add the nginx snippets to the appropriate existing TLS server blocks; these are snippets, not complete virtual hosts.

Install Docker Engine with Compose and PHP 8.3 extensions curl, zip, simplexml and pdo_mysql. Configure nginx/PHP upload limits described in the snippets. The container example limits memory to 2 GB; measure actual concurrent use alongside Ollama, peer ML and LiveKit before increasing capacity. CODE is a development distribution; assess your production support/capacity needs against Collabora's current offerings.

## Pull and prepare

Copy command blocks only, not terminal prompts or their output.

```bash
cd ~/ecollab-inspect
git status --short
git fetch origin
git switch collabora-document-integration
git pull --ff-only origin collabora-document-integration
php -l services/CollaboraService.php
php -l API/collaboration/wopi.php
php tests/peer-ml-regression.php
```

Apply migration 050 using the same database migration process used for your existing installation. If applying explicitly from this repository, this CLI command loads the configured database without printing credentials:

```bash
php -r 'require "config.php"; require "database/config/db.php"; Database::getInstance()->exec(file_get_contents("database/migrations/050_collabora_wopi.sql")); echo "Collabora tables ready\n";'
```

Create `deploy/collabora/.env` from `env.example`. Select a CODE release and pin its actual digest. One way to obtain a digest for an initial staging evaluation is:

```bash
docker pull collabora/code:latest
docker image inspect collabora/code:latest --format '{{index .RepoDigests 0}}'
```

Paste that exact digest into `COLLABORA_IMAGE`; set the actual eCollab origin and office hostname. This resolves `latest` once; subsequent deployments use the saved digest. Validate the selected release with the smoke test below before production use.

```bash
docker compose --env-file deploy/collabora/.env -f deploy/collabora/compose.yaml config --quiet
docker compose --env-file deploy/collabora/.env -f deploy/collabora/compose.yaml up -d
sudo nginx -t
sudo systemctl reload nginx
curl --fail --max-time 10 https://office.ecollab.tech/hosting/discovery
```

After installing both nginx snippets and configuring the hostnames, add to the application's root `.env`:

```dotenv
DOCUMENT_EDITOR=collabora
COLLABORA_URL=https://office.ecollab.tech
COLLABORA_WOPI_URL=https://ecollab.tech
DOCUMENT_AI_ENABLED=false
DOCUMENT_ML_ENABLED=false
```

Keep AI/ML off for the initial editor test, then enable each independently once its existing local service is ready. Document AI sends only explicitly submitted text to `OLLAMA_URL`; confirm that configured endpoint is the service you intend to use.

```bash
sudo systemctl reload php8.3-fpm
curl -i --max-time 5 https://ecollab.tech/API/collaboration/wopi/files/1
```

The unauthenticated WOPI request must return **401**, never file content. A raw `/uploads/collab-docs/<known-file>` request must return **404** even when signed in.

## Required live acceptance test

1. Create one DOCX, XLSX and PPTX in a test workspace and open each. Import one real file of each format as well. Confirm formatting and save/reopen behavior.
2. Open the same file with two separate authorized editor accounts. Type from both, verify live changes, close and reopen; verify both edits persisted. Download and inspect it in a desktop office suite.
3. Open as an explicitly granted viewer while workspace public permission is edit. The viewer must remain read-only. An uninvited account must not open a private document. Revoking workspace/server membership must stop subsequent WOPI reads/saves. Already-rendered content cannot be recalled from a user's browser.
4. Save twice and download prior history as owner. Check the expected previous content. Nonowners must not be able to download historical revisions by changing URL parameters.
5. Enable AI; submit a small excerpt and verify only a suggestion appears. Stop Ollama and verify editing still works. Enable document ML and verify private inaccessible titles never appear in related results.
6. Check browser/network and container logs for save failures, but do not share access tokens. Refreshing the outer editor issues a new token after an eight-hour session expires; save and reopen before expiration during long sessions.

CI tests authorization, token expiry/binding, downgrade/upgrade ceilings, timestamp conflicts and version retention using an isolated MariaDB database. These tests are **not** a substitute for the two-user Collabora browser test. Automated tests do not run an actual Collabora container.

## Operations and limitations

- Keep all previous version files for now. There is no automatic retention policy, restore-in-place button or history purge; deleted document histories are inaccessible through the API but may remain on disk/database until an administrator applies a reviewed retention process. Monitor disk usage.
- Permissions govern access and writes; download permission is not separately configurable. A viewer can download the current file.
- Cross-workspace moves are disabled pending an explicit sharing review workflow.
- Close and save all Collabora sessions before rollback, then set `DOCUMENT_EDITOR=onlyoffice` and reload PHP-FPM. Keep the migration and stored revisions; the current `storage_path` still points to the latest file. Do not run both providers on the same document at once.

Protocol reference: https://sdk.collaboraonline.com/CO-SDK-manual.pdf (discovery, form launch, CheckFileInfo/GetFile/PutFile and timestamp conflict handling).
