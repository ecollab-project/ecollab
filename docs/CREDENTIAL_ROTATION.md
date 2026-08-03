# ECOLLAB — Credential Rotation Guide

Companion to `SECURITY_CREDENTIAL_AUDIT.md`. This document contains the manual steps required to complete rotation. **None of these steps have been executed as part of this task** — they require console/database access outside this codebase.

---

## 1. Google OAuth Client Secret (required — this is the one live exposure found)

**Why:** `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` were found populated with real, live-format values in the distributed `.env`. The working copy has been scrubbed (`ROTATED_PENDING_REPLACEMENT`), but the actual credential is still valid at Google until you revoke it there.

**Steps:**
1. Go to [Google Cloud Console → APIs & Services → Credentials](https://console.cloud.google.com/apis/credentials).
2. Select the project associated with Ecollab's OAuth client.
3. Find the OAuth 2.0 Client ID matching the one that was in `.env` (identifiable by its Client ID, which is not itself sensitive).
4. Click into it → **"Add Secret"** (Google allows generating a new secret without changing the Client ID, which avoids needing to update `GOOGLE_CLIENT_ID` or any redirect URI configuration).
5. Copy the new secret value immediately — Google only displays it once.
6. Update your **real, non-shared** `.env` file (not the scrubbed one in this archive) with the new value:
   ```
   GOOGLE_CLIENT_SECRET=<new secret value>
   ```
7. Return to the Credentials page and **delete the old secret** to fully revoke it.
8. Restart/redeploy the application so the new `.env` value is loaded (PHP reads `.env` at request time via `config.php`, so no build step is needed — just ensure the running environment's `.env` file is updated and any opcode cache is cleared if one is in use).
9. Verify: attempt a Google login flow end-to-end; confirm it succeeds with the new secret.

**Note on `GOOGLE_REDIRECT_URI`:** the value found in `.env` pointed to a `localhost` path. If this project has ever been deployed anywhere else, confirm the redirect URI registered in Google Cloud Console matches the actual deployment URL — a mismatch will cause OAuth login to fail with a `redirect_uri_mismatch` error independent of the secret rotation above.

---

## 2. Database credentials (recommended, not confirmed exposed)

**Why:** The exposed `.env` used `DB_USER=root` with an **empty** `DB_PASS`. This is a standard local-development default (consistent with `APP_ENV=local` and `APP_URL=http://localhost/...` also found in the same file) and was not confirmed to be a production credential. However, if this pattern has ever been mirrored on a real, network-reachable database, it needs to be corrected regardless of this specific `.env`'s exposure.

**If this is only ever used against a local development database:** no action is required beyond the standard practice of never exposing that database to the network.

**If a production (or any non-localhost) database uses `root` with no password, or if you want to rotate as a precaution:**

```sql
-- Set a strong password for the existing user (adjust host if not localhost-only):
ALTER USER 'root'@'localhost' IDENTIFIED BY '<new_strong_password>';
FLUSH PRIVILEGES;
```

**Better long-term fix — create a dedicated, least-privilege application user instead of using `root` at all:**

```sql
CREATE USER 'ecollab_app'@'localhost' IDENTIFIED BY '<new_strong_password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecollab_v2.* TO 'ecollab_app'@'localhost';
FLUSH PRIVILEGES;
```

Then update `.env`:
```
DB_USER=ecollab_app
DB_PASS=<new_strong_password>
```

**Verify:** restart the application, confirm login/signup and a few chat actions still work end-to-end against the database with the new credentials.

---

## 3. Anthropic API Key — no action required

**Status:** `ANTHROPIC_API_KEY` in the exposed `.env` was the literal placeholder string `your_anthropic_api_key_here`, which `API/chat/ai-assist.php` explicitly detects and treats as "not configured" (returns HTTP 503). **No real key was exposed. Nothing to rotate.**

**If/when you do enable the AI-assist feature:**
1. Go to the [Anthropic Console → API Keys](https://console.anthropic.com/settings/keys).
2. Generate a new key.
3. Add it to your real `.env` (never the version shipped in any shared archive):
   ```
   ANTHROPIC_API_KEY=<your new key>
   ```
4. From that point forward, treat this key with the same handling discipline described in `SECURITY_CREDENTIAL_AUDIT.md` — never commit or export a `.env` containing it.

---

## 4. Microsoft OAuth — no action required

`MICROSOFT_CLIENT_ID` and `MICROSOFT_CLIENT_SECRET` were both empty in the exposed `.env`. Microsoft SSO is not currently configured, so there is nothing to rotate. If it's configured in the future, apply the same handling discipline as the Google credentials above.

---

## 5. Post-rotation checklist

- [ ] New Google OAuth Client Secret generated and old one deleted in Google Cloud Console
- [ ] Real `.env` (not this archive's copy) updated with the new Google secret
- [ ] Google login flow tested end-to-end with the new secret
- [ ] Database credential status confirmed (local-only — no action, or rotated per §2)
- [ ] Confirmed no other deployment/hosting panel/CI secret store still holds the old Google secret
- [ ] Confirmed this archive (or any future export) will use `.env.example`, never a populated `.env` — see the "Credential Handling" note added to `README.md`
