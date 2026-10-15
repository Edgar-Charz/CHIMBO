-- =====================================================================
-- 015_order_editing.sql — staff edit orders (blueprint R-29, §12.11)
--
-- A discount staff give carries a reason the customer sees (receipt: "Punguzo — {reason}"), and staff can keep
-- private notes on an order (never shown to the customer).
-- =====================================================================

ALTER TABLE orders
    ADD COLUMN order_discount_note VARCHAR(255) NULL AFTER order_discount_total;   -- why the discount was given

CREATE TABLE order_staff_notes (
    order_staff_note_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    order_id               BIGINT UNSIGNED NOT NULL,
    admin_id               INT UNSIGNED  NOT NULL,
    order_staff_note_text  VARCHAR(1000) NOT NULL,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_staff_note_id),
    KEY idx_order_staff_notes_order (order_id, created_at),
    CONSTRAINT fk_order_staff_note_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    CONSTRAINT fk_order_staff_note_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
