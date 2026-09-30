# LightFM recommendation experiment

Status: offline prototype plus optional collection for personal book recommendations.
No production ranking uses LightFM. Peer matching and its environment are unchanged.

## Source audit (2026-09-30, base 94d11af)

- `API/library/books.php` derives keywords from course/year/interests or authorized
  server context and queries Open Library. It did not persist book identities,
  impressions, clicks, or usefulness feedback.
- `modules/library/library.php` rendered external links without tracking.
- `JarredGroupRecommendationService.php` uses deterministic peer compatibility;
  it explicitly has no assessment telemetry. Group membership is not a book label.
- Existing message bookmarks and peer feedback have different targets; treating
  these as book preferences would invent training evidence.
- No live database was accessible during this audit. Run the count-only audit
  below; repository schemas alone cannot establish training-data sufficiency.

## Included collection

`BOOK_RECOMMENDATION_EVENTS_ENABLED` defaults to false. When enabled, only the
unsearched **Recommended for Me** library view offers trackable book cards.
Server recommendations and explicit search results are excluded. A notice explains
collection and the existing AI-matching opt-out. The Useful button records explicit
feedback; a click is only a weak signal, not proof of reading or satisfaction.

The authenticated POST endpoint checks CSRF, eligibility/opt-out, a session-bound
30-minute offered-book allowlist, payload limits, and rate limits. The server uses
the logged-in user ID; clients cannot choose another user or supply metadata.
Events are deduplicated by user, work, type and UTC day. Only work IDs, subject
labels and event timestamps are stored; no search text, messages, names or emails.
Foreign keys remove events when an account is physically deleted. Soft-deleted,
ineligible and currently opted-out accounts are excluded at export time. Opt-out
stops new collection; it does not automatically delete historical events.

## Database and collection deployment (after reviewing this branch)

Back up the database before applying the additive migration. Use your normal
database credentials; the following prompts for a password rather than storing it:

```bash
cd ~/ecollab-inspect
php scripts/audit-recommendations.php
mariadb -u YOUR_DB_USER -p ecollab_v2 < database/migrations/049_book_recommendation_events.sql
```

Only after the migration succeeds, add to application `.env`:

```dotenv
BOOK_RECOMMENDATION_EVENTS_ENABLED=true
```

Reload PHP-FPM if necessary. Open Recommended for Me, click a book and mark one
Useful, then rerun the audit. Repeated same-day clicks should not inflate the count.
Turn AI matching off and confirm collection stops. A stopped/failed collector
must not prevent normal book navigation. Turning the feature flag off immediately
stops recording without affecting the existing library or peer matcher.

For a limited pilot, periodically remove events beyond the chosen retention period.
Example 90-day policy (choose the period for your study before collecting):

```sql
DELETE FROM recommendation_book_events WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY;
```

## Count and export

Run the audit first. There is no universal user-count threshold; assess distinct
user/book pairs, overlap between users, time coverage and evaluation eligibility.
The trainer refuses empty or chronologically unevaluable data.

Export to a private directory **outside the web root**. The salt pseudonymizes
user IDs; this remains user data and is not an anonymous public dataset.

```bash
cd ~/ecollab-inspect
umask 077
mkdir -p ~/ecollab-ml-private
export RECOMMENDATION_EXPORT_SALT="$(openssl rand -hex 32)"
php scripts/export-book-recommendations.php > ~/ecollab-ml-private/books.json
unset RECOMMENDATION_EXPORT_SALT
```

Only training-relevant fields are exported. Current eligibility is checked on every
export. User features are identity-only for this first experiment; item subjects
provide the hybrid metadata component. This avoids presenting current course/profile
features as historical facts. Do not upload the export to GitHub or paste it in chat.
Exports and any later models must be regenerated when opt-outs/deletions change.

## Isolated Python 3.11 experiment

Use a separate Python 3.11 environment on a development machine/CI (or a separately
installed 3.11 runtime on the VPS). Do not change the operating-system Python or
install these dependencies into `ml/peer_matching/.venv`. Upstream LightFM 1.17 has
reported Python 3.12/build-tool compatibility issues; this experiment pins a
conservative build stack and verifies an actual WARP fit in CI.

```bash
python3.11 -m venv ml/lightfm/.venv
ml/lightfm/.venv/bin/pip install 'pip==24.0' 'setuptools==69.5.1' 'wheel==0.43.0' 'numpy==1.26.4'
ml/lightfm/.venv/bin/pip install --no-build-isolation -r ml/lightfm/requirements.txt
cd ml/lightfm
.venv/bin/python -m unittest -v
```

With a real export, select a UTC time separating training from later interactions:

```bash
.venv/bin/python evaluate.py ~/ecollab-ml-private/books.json --cutoff UNIX_TIMESTAMP --k 5 --epochs 20
```

The output includes precision@k, recall@k and NDCG@k for LightFM, training-only
popularity and topic-overlap baselines, plus cohort sizes and excluded-pair counts.
All three rank the same catalog with previously seen items excluded. Features and
catalog availability are restricted to before the global cutoff. Repeated pairs
are deduplicated before splitting. Training uses binary first-interaction positives;
Useful feedback is stored separately but not given an unvalidated higher weight.

## Limits and decision gate

- This evaluates warm-start users and pre-cutoff books; new users/books are excluded
  and reported. It does not establish cold-start quality.
- The catalog includes interacted-with books only; unclicked impressions are not
  collected. Exposure/selection bias remains. The content baseline is not the actual
  Open Library ordering, whose historical results were not stored.
- Missing interactions are not explicit dislikes. Synthetic fixtures only verify
  code, not recommendation quality. No score here is a probability.
- Caps: 5,000 users/items and 100,000 events; one CPU training thread, 16 factors.
  These are prototype limits, not measured VPS capacity promises.
- No model is saved or served, no pickle is loaded, and no automatic rollout occurs.
- Before production, collect/evaluate exposure-aware data, compare against the live
  baseline, assess per-user coverage and uncertainty, and test current authorization
  at serving time. Do not recommend private resources based on training membership.

## Validation

CI builds LightFM on Python 3.11 and runs a real fit/predict test; tests also cover
chronological separation, duplicate pairs, future catalog exclusion and metric
arithmetic. A disposable MariaDB 10.11 database tests the migration, opt-out,
allowlist/expiry and event deduplication. PHP and inline JavaScript are syntax checked.
Live database counts, real-data quality and browser interaction still require the
deployment checks above. Do not run the disposable database test on production.

References:
- https://making.lyst.com/lightfm/docs/lightfm.html
- https://github.com/lyst/lightfm/issues/709
