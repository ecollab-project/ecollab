# eCollab ML Repository Assessment

Date: 2026-09-01
Base branch: `main`
ML branch: `ml/peer-channel-recommendation`
Repository: `ecollab-project/ecollab`

## 1. Scope

This assessment covers only the proposed ML work:

- ML-1: peer matching
- ML-2: public channel recommendation
- minimum integration required to expose those capabilities

Unrelated security, UI, chat refactoring, and roadmap work are out of scope.

## 2. Repository Architecture Observed

The repository default branch is `main`. The application is primarily a PHP/MySQL system with vanilla JavaScript, REST-style PHP APIs, service classes, database migrations, and PHPUnit tests.

Relevant existing areas include:

- `API/chat/`
- `API/onboarding/`
- `services/`
- `database/migrations/`
- `assets/js/chat/`
- `assets/js/peer-matching.js`
- `tests/Unit/`

A dedicated ML branch was created from `main`:

`ml/peer-channel-recommendation`

No ML implementation files were present in the inspected `main` tree before this assessment.

## 3. Existing Peer-Matching Functionality

The repository already has a substantial deterministic peer-matching implementation.

### `services/PeerMatchingService.php`

The service is an explainable multi-factor compatibility engine. Its documented components are:

- Subjects: 35%
- Study style: 25%
- Interests: 25%
- Hobbies: 15%

It accepts already-loaded profiles containing study preferences, subjects, interests, and hobbies, then returns a total score, component scores, shared counts, and explanation tags.

The subject scorer considers overlap, study/tutoring roles, and proficiency compatibility. This is an existing rule-based compatibility engine, not a trained statistical ML model.

### `API/chat/get-matches.php`

This authenticated endpoint loads candidate users and the peer-matching profile dimensions, calls `PeerMatchingService`, caches compatibility results in `pm_compatibility`, and returns the top matches.

It excludes users without configured matcher dimensions and requires the requesting user's profile to have at least one matcher dimension configured.

### `API/chat/peer-match.php`

This is a larger authenticated peer-matching API supporting tag/profile retrieval, profile saving, and match retrieval. It uses the same `PeerMatchingService` and has CSRF protection for POST requests plus rate limiting on match retrieval.

Conclusion: peer matching already exists and is functional as a deterministic compatibility engine. The ML task must therefore add a genuinely ML/recommendation-based capability without pretending the current weighted rules are trained ML.

## 4. Existing Peer-Matching Data

Migration `database/migrations/013_peer_matching.sql` establishes these ML-relevant structures:

- `pm_user_study_prefs`
  - study style
  - session length
  - time preference
  - learning mode
  - pace
  - communication style
  - primary goal
  - availability days
- `pm_subjects`
- `pm_user_subjects`
  - subject
  - role (`studying`, `tutoring`, `both`)
  - proficiency
- `pm_hobby_tags`
- `pm_user_hobbies`
- `pm_interest_tags`
- `pm_user_interests`
- `pm_compatibility`
- `pm_match_requests`
- `pm_match_feedback`

The feedback table contains a 1–5 rating, optional comment, tags, reviewer, and match ID. This is potentially useful as a future source of labeled outcomes, but its actual production row count and quality have not been established by repository inspection alone.

Important limitation: source code defines the schema, but this repository inspection cannot establish how much real production data exists in a deployed database. The ML implementation must not claim sufficient training labels until that is measured against an actual database snapshot/environment.

## 5. Existing User Data

The core users schema contains at least:

- user ID
- institution ID
- username
- email
- student ID
- password hash
- full name
- avatar fields
- role
- account status
- online/activity fields
- bio
- SSO-related fields

Only legitimate collaboration/profile signals should be sent to ML components. Credentials and unnecessary identity/security fields must remain outside ML inputs.

## 6. Existing Channel / Server Model

`services/ChannelService.php` confirms the current channel model includes:

- `channels.id`
- `channels.server_id`
- `channels.name`
- `channels.slug`
- `channels.type`
- `channels.description`
- `channels.position`
- `channels.is_private`
- `channels.is_locked`
- `channels.member_count`
- `channels.created_at`

Access is tied to server membership and, for private channels, channel membership.

For ordinary users, `ChannelService::getChannelsForUser()` requires membership in the server and permits a channel when either it is non-private or the user is a channel member.

Therefore, for this project, a “public channel” cannot safely mean simply “channel where `is_private = 0`.” The candidate set must also be restricted to servers/channels the current user is legitimately allowed to access.

## 7. Existing Public-Content Recommendation Logic

`API/onboarding/get-server-suggestions.php` already implements deterministic server suggestions. It:

1. reads user interest tags and hobbies;
2. considers active public/institution servers not already joined;
3. loads server tags;
4. scores interest overlap, hobby keyword matches, and popularity;
5. sorts by score and returns the top results.

This is server recommendation, not channel recommendation, and it is a rule-based scorer rather than a trained ML model.

It provides useful evidence that recommendation behavior already exists in the application, but ML channel recommendation should be a separate capability and must not simply relabel this scoring formula as machine learning.

## 8. Existing ML Infrastructure

No Python ML service, FastAPI service, scikit-learn model artifact, Python requirements file, or dedicated ML directory was found in the inspected `main` repository tree.

The existing project is therefore not currently structured as a Python ML service. The proposed ML architecture will need to introduce this infrastructure while keeping it isolated under `ml/`.

## 9. Existing APIs / Integration Points

Primary integration points identified:

### Peer matching

- `API/chat/get-matches.php`
- `API/chat/peer-match.php`
- `assets/js/peer-matching.js`
- `assets/js/chat/peer-matching.js`
- `services/PeerMatchingService.php`

### Channel/server discovery

- `API/chat/get-channels.php`
- `API/chat/get-channel.php`
- `services/ChannelService.php`
- `API/onboarding/get-server-suggestions.php`

The existing PHP application should remain authoritative for user authentication and channel authorization. Python ML should provide recommendation/ranking functionality, not authorization decisions.

## 10. Data and Modeling Assessment

### Peer matching

A defensible initial ML approach is likely to be a content-based or hybrid recommender using the existing profile dimensions. This is preferable to inventing supervised labels if the production feedback dataset is insufficient.

Potential features supported by the observed schema include:

- subject overlap and subject text/category representations
- study preference representations
- interest tags
- hobby tags
- availability compatibility
- goal compatibility
- historical feedback only if sufficient real labeled data is verified

### Channel recommendation

A content-based recommender is immediately plausible because channels have names/descriptions and the application already has user interest/hobby information. A hybrid approach can later incorporate legitimate interaction signals if the repository/database proves that such data exists and is appropriate.

The candidate filter must happen before or independently of ML ranking so the model cannot recommend inaccessible/private channels.

## 11. Security Constraints Identified

The ML service must not become an authorization bypass.

Required design boundary:

`authenticated PHP request -> server-side authorization/candidate filtering -> ML ranking -> PHP response`

For channel recommendations, private or otherwise inaccessible channels must be removed from the candidate set before results are returned.

The ML service must not receive or persist:

- password hashes
- authentication tokens
- private messages
- unnecessary PII
- unrelated security fields

## 12. Risks

### R1 — Existing peer matching may be mistaken for ML

The existing `PeerMatchingService` is a deterministic compatibility engine. The new work must provide measurable ML/recommendation behavior and document exactly what is learned or represented by the model.

### R2 — Insufficient labeled data

The repository defines `pm_match_feedback`, but source code alone cannot prove there are enough real labels for supervised learning. No synthetic labels may be presented as production outcomes.

### R3 — Channel authorization leakage

A recommender operating over all channels could expose private/inaccessible channels. Authorization must constrain the candidate set outside the ranking model.

### R4 — Cold start

New users and new channels may have no interaction history. Content-based signals should therefore be available as a fallback.

### R5 — Integration coupling

Existing PHP APIs should not be rewritten wholesale. The ML branch should contain the minimum integration required and preserve current application behavior.

## 13. Proposed Initial ML Architecture

```text
eCollab PHP/MySQL
       |
       | authorized feature/candidate data
       v
Python ML Service
   |             |
   v             v
Peer Model   Channel Model
   |             |
   +-------> ranked results
                 |
                 v
          eCollab PHP/API
```

Initial implementation should prefer lightweight, explainable techniques such as TF-IDF/cosine similarity and/or a hybrid content-based recommender unless verified data supports a stronger supervised ranking model.

## 14. Required Next Steps

1. Measure actual availability of real peer feedback/history in the target database environment.
2. Inspect the complete relevant schema for channel tags/interactions and confirm exact fields.
3. Define a minimal ML feature contract.
4. Implement the isolated Python service under `ml/`.
5. Build peer matching as a genuine recommender rather than a renamed weighted formula.
6. Build public-channel recommendation with authorization-safe candidate filtering.
7. Add evaluation scripts and appropriate ranking metrics.
8. Add Python unit/API/security tests.
9. Add only minimal PHP integration.
10. Perform a fresh independent audit after implementation.

## 15. Assessment Verdict

**Repository inspection: PASS — ready for ML implementation, with important data-availability constraints.**

The repository already contains strong deterministic peer-matching infrastructure and server recommendation logic. It does not currently contain a dedicated Python ML service. The cleanest path is to introduce an isolated ML subsystem that uses existing legitimate collaboration signals, preserves PHP authorization as the source of truth, and does not fabricate supervised training results.
