-- =====================================================================
-- 005_order_payment_cancelled.sql
-- A cancelled or expired order that was never paid no longer owes anything:
-- its payment status becomes "cancelled" (before, cash-on-delivery orders kept "cod_pending").
-- =====================================================================

ALTER TABLE orders
    MODIFY order_payment_status ENUM('unpaid', 'pending', 'paid', 'cod_pending', 'refunded', 'failed', 'cancelled') NOT NULL;

-- Fix orders that were already cancelled or expired before this change
UPDATE orders
SET order_payment_status = 'cancelled'
WHERE order_status IN ('cancelled', 'expired')
  AND order_payment_status IN ('unpaid', 'pending', 'cod_pending');
