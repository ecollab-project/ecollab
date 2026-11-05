-- Additive send identity: no message content is changed or copied.
CREATE TABLE IF NOT EXISTS dm_client_identity (
 sender_id BIGINT UNSIGNED NOT NULL,
 client_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind VARCHAR(8) NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (sender_id,client_id),
 UNIQUE KEY dm_identity_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
