-- Per-user high-water marks; separate scopes prevent channel/DM/group collisions.
CREATE TABLE IF NOT EXISTS chat_read_cursors (
 user_id BIGINT UNSIGNED NOT NULL,
 scope VARCHAR(8) NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(user_id,scope,conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
