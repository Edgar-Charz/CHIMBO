-- =====================================================================
-- 014_payment_methods_open_list.sql — staff can add payment methods (e.g. HaloPesa, NMB Bank)
--
-- The method codes were a fixed list (ENUM); they become text so a method added in the admin (code made from its
-- name, e.g. "halopesa") can be stored on orders and payments. Behaviour still comes from the method's type:
-- mobile_money / bank (pay first, staff check it) or cash (the one built-in "Lipa ukipokea").
-- =====================================================================

ALTER TABLE payment_methods MODIFY COLUMN payment_method_code VARCHAR(40) NOT NULL;
ALTER TABLE orders          MODIFY COLUMN order_payment_method VARCHAR(40) NOT NULL;
ALTER TABLE payments        MODIFY COLUMN payment_method_code VARCHAR(40) NOT NULL;
