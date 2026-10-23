-- Switch shop counters to a monthly sequence and add revocable order QR-link versions.
ALTER TABLE order_number_sequences
    ADD COLUMN order_number_period CHAR(4) NOT NULL DEFAULT '0000' AFTER order_number_prefix,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (order_number_prefix, order_number_period);

CREATE TABLE order_public_links (
    order_id                 BIGINT UNSIGNED NOT NULL,
    order_link_version       INT UNSIGNED NOT NULL DEFAULT 0,
    order_link_revoked_at    DATETIME NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    CONSTRAINT fk_order_public_link_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Version zero keeps already printed signed QR codes working until staff choose to revoke them.
INSERT INTO order_public_links (order_id, order_link_version)
SELECT order_id, 0 FROM orders;
