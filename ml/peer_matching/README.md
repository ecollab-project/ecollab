# eCollab semantic peer matching (Phase 1)

This branch adds local Sentence Transformers embeddings and scikit-learn cosine
similarity to the existing PHP matcher. It does not replace Jarred or train a model
on students. Default model: `sentence-transformers/all-MiniLM-L6-v2`.

## Behavior

- Disabled by default. PHP uses the original rules if disabled, unavailable, busy,
  timed out, invalid, or either profile has no named tags.
- One request per candidate list, at most 100 candidates and 1,500 characters per
  profile. PHP still chooses eligible candidates; ML cannot add users.
- Hybrid score = original unrounded rules score × 0.75 + semantic score × 0.25.
  Effective weights: subjects 26.25%, study preferences (including availability)
  18.75%, interests 18.75%, hobbies 11.25%, semantic topics 25%.
- These weights are initial engineering choices, not experimentally validated.
  Similarity is not a probability of a successful peer relationship.
- API metadata includes engine, semantic score, and effective weights. The
  compatibility dialog shows the semantic component when available.
- Both recommendation endpoints, matching search and compatibility detail use
  the same scorer. `computeScore()` remains the original rules-only API.
- The old `pm_compatibility` cache deliberately retains rules-only scores and tags:
  it has no model/version columns. The legacy leaderboard and saved request scores
  therefore remain rules-based. Hybrid results are recomputed, not read from this
  cache. No database migration is required.
- Candidate opt-outs are excluded and requester opt-outs are honored. No names,
  account IDs, emails, bios, DMs, or chat histories are sent to Python. Only selected
  subject, interest and hobby labels are encoded. Nothing is sent to an LLM provider.
- Python keeps a bounded in-memory embedding cache, with no profile logging or
  persistence. The local API requires a shared token; PHP only allows numeric
  loopback URLs and does not follow redirects.
- Existing candidate limits (50/100, chosen by activity) remain. This is semantic
  reranking of that pool, not a site-wide vector index.

## Install on the VPS after branch review

The backup branch is `backup-ecollab-collabs-2026-09-29` at
`a0dccec2831eb42aada0605fbf8469b2e612dc6a`. It is a **Git code backup**, not a backup
of MariaDB, uploaded files, environment variables, or the VPS.

Use a clean working tree. Do not force checkout over local production changes.
The service example assumes `/home/ecollabadmin/ecollab-inspect`; adjust both paths
and User in the unit if your actual checkout differs.

```bash
cd ~/ecollab-inspect
git fetch origin
git switch ml-peer-matching-integration
git pull --ff-only origin ml-peer-matching-integration
sudo apt-get install python3-venv php8.3-curl
python3 -m venv ml/peer_matching/.venv
ml/peer_matching/.venv/bin/pip install torch --index-url https://download.pytorch.org/whl/cpu
ml/peer_matching/.venv/bin/pip install -r ml/peer_matching/requirements.txt
ml/peer_matching/.venv/bin/python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('sentence-transformers/all-MiniLM-L6-v2', device='cpu', trust_remote_code=False).save('ml/peer_matching/model')"
ml/peer_matching/.venv/bin/pip freeze > ml/peer_matching/deployed-requirements.txt
openssl rand -hex 32
sudo install -m 600 /dev/null /etc/ecollab-peer-ml.env
sudo nano /etc/ecollab-peer-ml.env
```

Put these values in `/etc/ecollab-peer-ml.env`, replacing the token placeholder with
the generated value. Put the same token into the application `.env` below.

```dotenv
PEER_ML_TOKEN=REPLACE_WITH_GENERATED_TOKEN
PEER_ML_MODEL_PATH=/home/ecollabadmin/ecollab-inspect/ml/peer_matching/model
HF_HUB_OFFLINE=1
```

```bash
sudo cp ml/peer_matching/ecollab-peer-ml.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ecollab-peer-ml
curl --fail http://127.0.0.1:8091/health
php tests/peer-ml-regression.php
cd ml/peer_matching
.venv/bin/python -m unittest -v
cd ../..
```

The model must finish loading before `/health` returns `ready: true`. Startup uses
local model files only. Do not open port 8091 in Nginx or the firewall.

Application `.env` (enable only after the service is ready):

```dotenv
PEER_ML_ENABLED=true
PEER_ML_URL=http://127.0.0.1:8091
PEER_ML_TOKEN=REPLACE_WITH_GENERATED_TOKEN
PEER_ML_TIMEOUT_MS=1500
```

Reload PHP-FPM if environment values are cached. In an authenticated browser,
inspect `API/chat/get-matches.php` and
`API/chat/peer-match.php?action=get_compatibility&user_id=ELIGIBLE_PEER_ID`.
With labeled profiles and a working service, expect `engine: hybrid-v1` and numeric
semantic scores. Stop the Python service temporarily and verify the APIs still
return matches with `engine: rules-v1` and null semantic values. Check opt-outs,
profile edits, and latency with representative real profiles before wider rollout.

Immediate rollback: set `PEER_ML_ENABLED=false` and reload PHP-FPM as needed. To
return the code to the pre-integration branch, use `git switch ecollab-collabs`
after ensuring the working tree is clean. Never reset or overwrite the backup.

## Verification and limits

`python -m unittest -v` tests real scikit-learn cosine arithmetic, input bounds,
empty profiles, cache eviction, HTTP authentication and protocol behavior using a
fake encoder. It does **not** validate the pretrained model's semantic quality.
`php tests/peer-ml-regression.php` covers exact fallback, malformed responses,
payload minimization, order preservation and hybrid weights. CI checks PHP and JS
syntax and runs both suites. Production database integration and real model
latency/quality require the VPS smoke checks above.

Before describing effectiveness in a thesis, evaluate representative labeled peer
pairs, compare against rules-only ranking, and report ranking metrics and latency.
The model is predominantly English; assess mixed Filipino/English profiles rather
than assuming equal accuracy. Model downloads require outbound access during
installation only. The 1.5 GB service memory cap is a limit, not a measured usage
claim; verify alongside Ollama, LiveKit and PHP on the VPS.

References:
- https://www.sbert.net/docs/sentence_transformer/usage/semantic_textual_similarity.html
- https://scikit-learn.org/stable/modules/generated/sklearn.metrics.pairwise.cosine_similarity.html
