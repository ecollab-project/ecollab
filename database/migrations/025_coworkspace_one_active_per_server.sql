-- ============================================================
-- Ecollab Coworkspaces — one active server-wide Coworkspace
-- Enforce the server-level invariant at the database boundary.
-- Archived Coworkspaces are allowed to coexist with a new active one.
-- ============================================================

ALTER TABLE collab_workspaces
    ADD COLUMN active_server_id INT UNSIGNED
        GENERATED ALWAYS AS (IF(archived = 0, server_id, NULL)) STORED,
    ADD UNIQUE KEY uk_coworkspace_active_server (active_server_id);
