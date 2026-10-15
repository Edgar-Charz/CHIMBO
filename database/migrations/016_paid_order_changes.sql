-- =====================================================================
-- 016_paid_order_changes.sql — adding to a paid order (blueprint R-29, §12.11 step 2)
--
-- Staff may change the items of a paid order before packing. A higher total leaves a balance to pay
-- (order_payment_status "balance_due": packing waits for it); a lower total leaves money to give back
-- (order_refund_due, cleared when staff mark it refunded; order_refunded_amount keeps what went back, so the
-- amount paid = confirmed payments − refunded).
-- =====================================================================

ALTER TABLE orders
    MODIFY COLUMN order_payment_status ENUM('unpaid', 'pending', 'paid', 'cod_pending', 'refunded', 'failed', 'cancelled', 'balance_due') NOT NULL,
    ADD COLUMN order_refund_due      INT UNSIGNED NOT NULL DEFAULT 0 AFTER order_total,        -- to give back after a change
    ADD COLUMN order_refunded_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER order_refund_due;   -- already given back (changes only)
