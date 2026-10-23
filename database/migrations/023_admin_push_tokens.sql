-- Browser registrations for staff notification delivery.
CREATE TABLE admin_push_tokens (
    admin_push_token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NOT NULL,
    admin_push_token_hash CHAR(64) NOT NULL,
    admin_push_token TEXT NOT NULL,
    last_seen_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_push_token_id),
    UNIQUE KEY uq_admin_push_token_hash (admin_push_token_hash),
    KEY idx_admin_push_token_admin (admin_id),
    CONSTRAINT fk_admin_push_token_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;