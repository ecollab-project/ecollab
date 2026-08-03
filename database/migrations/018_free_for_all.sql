-- ============================================================
-- 018_free_for_all.sql
-- ============================================================
-- Removes paid-tier differentiation entirely. This deployment
-- runs as a single FREE plan with every feature unlocked for
-- every account — the only access boundaries left are the
-- ROLE-based ones (student / facilitator / admin / super_admin),
-- enforced by security/middleware/RoleMiddleware.php.
--
-- WHAT THIS CHANGES:
--   - subscription_plans: collapses 'student_plus' and
--     'institution' rows into the single 'free' plan, with
--     token_grant raised to a high ceiling (effectively
--     unlimited for normal usage — see note below on why we
--     don't use NULL/0-means-infinite, for compatibility with
--     any future reporting that sums token_grant).
--   - users.plan_id: every account is reassigned to plan 1
--     ('free'), regardless of what they had before.
--   - system_settings: the 'tokens' category settings
--     (study_streak_reward, ai_query_cost) are left AS-IS —
--     these are REWARD/COST bookkeeping values for the
--     ai_sessions.token_cost ledger, not access restrictions.
--     No code path in the application ever checks
--     tokens_balance to BLOCK a feature (verified — there is
--     no `tokens_balance <` / `plan_id ==` gate anywhere in
--     services/ or API/). Token bookkeeping can remain for
--     analytics/gamification without limiting anyone.
--
-- WHAT THIS DOES NOT CHANGE:
--   - Role-based permissions (RoleMiddleware) are untouched.
--     Students, facilitators, and admins still see only the
--     dashboards and admin actions appropriate to their role —
--     that boundary is intentional and remains in place.
--   - The subscription_plans table and users.plan_id column
--     are NOT dropped. Removing them would require reverting
--     migration 017 and re-touching AuthService's
--     SchemaVersion::selectColumns() adaptive query for no
--     functional benefit (plan_id already isn't used to gate
--     anything). Keeping the column costs nothing and avoids
--     a destructive ALTER on production data.
--
-- IDEMPOTENT: safe to re-run. UPDATE/DELETE statements use
-- conditions that become no-ops once applied.
-- ============================================================

-- ── Remove the paid-tier rows entirely ──────────────────────
-- Any user previously on plan 2 or 3 is moved to plan 1 BEFORE
-- the rows are deleted, so the FK (fk_user_plan) never points
-- at a missing row.
UPDATE users SET plan_id = 1 WHERE plan_id IN (2, 3) OR plan_id IS NULL;

DELETE FROM subscription_plans WHERE id IN (2, 3);

-- ── Rewrite the single remaining plan as the unlimited free plan ──
UPDATE subscription_plans
SET
    code        = 'free',
    name        = 'Free',
    description = 'Every feature unlocked for every account. The only limits are role-based (student / facilitator / admin).',
    token_grant = 1000000,   -- effectively unlimited; not enforced by any code path
    price_cents = 0,
    is_active   = 1
WHERE id = 1;

-- If id=1 doesn't exist for some reason (e.g. it was manually
-- deleted), recreate it so users.plan_id always has a valid FK target.
INSERT IGNORE INTO subscription_plans (id, code, name, description, token_grant, price_cents, is_active)
VALUES (1, 'free', 'Free',
        'Every feature unlocked for every account. The only limits are role-based (student / facilitator / admin).',
        1000000, 0, 1);

-- ── Reassign every user to the free plan (defensive, idempotent) ──
UPDATE users SET plan_id = 1 WHERE plan_id != 1 OR plan_id IS NULL;
