-- Sequential order numbers per shop. The allocator skips numbers already held by legacy orders.
CREATE TABLE order_number_sequences (
    order_number_prefix         CHAR(3)       NOT NULL,
    order_number_sequence_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (order_number_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO order_number_sequences (order_number_prefix, order_number_sequence_value)
VALUES ('CHS', 0), ('CHM', 0), ('CHB', 0);
