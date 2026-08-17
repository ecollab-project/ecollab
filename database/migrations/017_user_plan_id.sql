-- Ecollab plan compatibility.
-- All capstone accounts currently use the same full-capacity experience;
-- plan_id is retained only because AuthService expects the column.
CREATE TABLE IF NOT EXISTS subscription_plans (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(60) NOT NULL,
    description VARCHAR(255),
    token_grant INT UNSIGNED NOT NULL DEFAULT 0,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO subscription_plans (id,code,name,description,token_grant,price_cents) VALUES
(1,'capstone','Ecollab Full Access','Full-capacity capstone access for students and facilitators',100000,0);

ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_id SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER tokens_balance;
UPDATE users SET plan_id=1 WHERE plan_id IS NULL OR plan_id=0;
