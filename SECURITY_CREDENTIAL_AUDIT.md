# ECOLLAB — Credential Exposure Audit

**Source:** `.env` as included in `ecollab_complete_merged.zip`
**Purpose:** Inventory every secret-bearing key found in the exposed `.env`, its actual population/exposure status, and the required remediation action. No secret values appear in this document.

**Corrections to prior reporting:**
1. The initial codebase analysis flagged `ANTHROPIC_API_KEY` as "populated" based on it being non-empty. On direct inspection, its value is the literal string `your_anthropic_api_key_here` — the application's own documented placeholder (see `API/chat/ai-assist.php`, which explicitly checks for and rejects this exact string). **This key was never actually live.** This audit corrects that finding below.
2. The prior roadmap document stated `.env.example` was "not present in this archive." That was incorrect — `.env.example` **does exist** in the archive (its filesystem timestamp matches the rest of the original file set, confirming it shipped with the project rather than being added later). It is a well-formed template with no real secret values. The actual gap is narrower than originally stated: a **populated `.env` was shipped alongside the already-correct `.env.example`**, and the existing template is **missing two keys** (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`) that the application code actually reads. Remediation below amends the existing template rather than replacing it.

---

## Environment context

This `.env` is a **local development configuration**, not a production one:
- `APP_ENV=local`, `APP_DEBUG=true`
- `APP_URL` points to `http://localhost/ecollab_sample5/ecollab`
- `DB_HOST=127.0.0.1`

This changes risk framing but does not eliminate it: the Google OAuth client is a cloud-hosted, internet-reachable credential regardless of where the `.env` referencing it lives. A leaked client secret is exploitable by anyone, independent of whether the app itself was running locally.

---

## Per-key audit

| Key | Status Found | Real Secret? | Risk Level | Action Required |
|---|---|---|---|---|
| `APP_NAME` | Populated (`Ecollab`) | No — not a secret | None | None |
| `APP_ENV` | Populated (`local`) | No | None | Set to `production` in any live deployment |
| `APP_DEBUG` | Populated (`true`) | No — but a config risk | Low (config hygiene) | Must be `false` in production — `true` enables verbose error output |
| `APP_URL` | Populated (localhost path) | No | None | Environment-specific, no action |
| `DB_HOST` | Populated (`127.0.0.1`) | No | None | None (local-only) |
| `DB_PORT` | Populated (`3306`) | No | None | None |
| `DB_NAME` | Populated (`ecollab_v2`) | No | None | None |
| `DB_USER` | Populated (`root`) | Partial — a default local dev account, not a secret by itself | Low locally / High if reused in production | **Never use `root` for the application DB user in production.** Create a dedicated least-privilege DB user. |
| `DB_PASS` | **Empty** | N/A | High if this pattern is reused in production | Ensure any production DB has a strong password — an empty password must never exist outside local dev |
| `SESSION_LIFETIME` / `SESSION_SECURE` / `SESSION_SAMESITE` | Populated, dev-appropriate (`SESSION_SECURE=false`) | No | Config only | `SESSION_SECURE` must be `true` in any production/HTTPS deployment |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_FROM` / `MAIL_FROM_NAME` | Populated, local dev values (`localhost`, `noreply@ecollab.local`) | No | None | Environment-specific, no action |
| `MAIL_USER` / `MAIL_PASS` | **Empty** | N/A | None currently | Populate with real SMTP credentials only in a properly secured production `.env`, never committed |
| `GOOGLE_CLIENT_ID` | **Populated with a real, live-format Google OAuth Client ID** | **Yes** | **High** | Treat as exposed — see rotation steps below |
| `GOOGLE_CLIENT_SECRET` | **Populated with a real, live-format Google OAuth Client Secret** (`GOCSPX-` prefix — Google's standard secret format) | **Yes** | **Critical** | **Rotate immediately** — this is the primary live exposure in this archive |
| `GOOGLE_REDIRECT_URI` | Populated (localhost callback URL) | No — but confirms the OAuth client's configured redirect | Informational | None directly, but update if redirect URIs change after rotation |
| `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` | **Empty** | N/A | None | Microsoft SSO is not currently configured — no exposure |
| `MICROSOFT_TENANT_ID` | Populated (`common`) | No | None | None |
| `MICROSOFT_REDIRECT_URI` | Populated (localhost callback URL, references a differently-named `ecollab-fixed` path — inconsistent with the app's actual directory name) | No | None | Worth double-checking this URI matches the real deployed path before Microsoft SSO is ever enabled |
| `ANTHROPIC_API_KEY` | **Populated with the literal placeholder string `your_anthropic_api_key_here`** | **No — not a real key** | **None** | No rotation needed. A real key must still be added through the Anthropic Console when the AI-assist feature is enabled, and that `.env` must never be shipped in an archive once it is. |
| `ANTHROPIC_MODEL` | Populated (`claude-haiku-4-5-20251001`) | No — not a secret | None | None |

---

## Summary of required action

**One credential requires actual rotation:** the Google OAuth Client Secret (and, as a pair, reviewing whether the associated Client ID should also be replaced — Google allows secret rotation without changing the Client ID, which is the simpler and recommended path).

**No action needed** for the Anthropic API key — it was never populated with a real value in this archive.

**Process fixes needed regardless of rotation:**
- Never ship a populated `.env` in a distributed archive again — see `.env.example` (created alongside this audit) and the process note added to `README.md`.
- If this local `.env`'s `DB_USER=root` / empty-password pattern has ever been mirrored in a real production database, that database's root account needs a password and the application should be moved to a dedicated least-privilege DB user regardless of whether this specific `.env` was ever used in production.

**Template fix needed:** `.env.example` already exists and is otherwise well-formed, but is missing `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` — the two keys `API/chat/ai-assist.php` actually reads. Amended in this pass.

**Full step-by-step rotation instructions:** see `docs/CREDENTIAL_ROTATION.md`.

---

*This audit describes exposure status only. It does not perform rotation — see `docs/CREDENTIAL_ROTATION.md` for the manual steps required in Google Cloud Console and (if applicable) your database.*
