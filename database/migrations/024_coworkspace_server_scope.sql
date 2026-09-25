-- ============================================================
-- Ecollab Coworkspaces — server-wide scope
-- A Coworkspace belongs to a server, not to an individual channel.
-- channel_id is retained as the originating/context channel for
-- backwards compatibility with existing collaboration tools.
-- ============================================================

ALTER TABLE collab_workspaces
    ADD COLUMN server_id INT UNSIGNED NULL AFTER channel_id,
    ADD KEY idx_server_visibility (server_id, visibility, archived);

UPDATE collab_workspaces w
INNER JOIN channels c ON c.id = w.channel_id
SET w.server_id = c.server_id
WHERE w.server_id IS NULL;

ALTER TABLE collab_workspaces
    MODIFY COLUMN server_id INT UNSIGNED NOT NULL;

ALTER TABLE collab_workspaces
    ADD CONSTRAINT fk_cw_server
    FOREIGN KEY (server_id) REFERENCES servers(id)
    ON DELETE CASCADE ON UPDATE CASCADE;
