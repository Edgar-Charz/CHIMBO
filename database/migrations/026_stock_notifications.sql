-- Stock notification subscriptions: customers opt-in to be pushed when an out-of-stock product
-- comes back. One row per customer+product pair. The unique key prevents duplicates.
CREATE TABLE IF NOT EXISTS stock_notifications (
    stock_notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id               BIGINT UNSIGNED NOT NULL,
    product_id            INT UNSIGNED    NOT NULL,
    notified_at           DATETIME        NULL     DEFAULT NULL COMMENT 'Set when the push fires; NULL = still waiting.',
    created_at            DATETIME        NOT NULL DEFAULT UTC_TIMESTAMP(),
    UNIQUE KEY uq_user_product (user_id, product_id),
    KEY idx_product_pending (product_id, notified_at),
    CONSTRAINT fk_sn_user    FOREIGN KEY (user_id)    REFERENCES users    (user_id)    ON DELETE CASCADE,
    CONSTRAINT fk_sn_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
