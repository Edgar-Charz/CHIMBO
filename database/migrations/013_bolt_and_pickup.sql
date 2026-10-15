-- =====================================================================
-- 013_bolt_and_pickup.sql — Dar by Bolt paid to the rider, and pickup at the shop (blueprint R-26, §12.8)
--
-- A delivery option can now be "paid to the rider": the customer pays the Bolt rider directly, so the order's
-- transport is 0 in CHIMBO's total and the order remembers who is paid. A delivery option can also be "pickup at
-- the shop" (free; the order goes packed → delivered = "Tayari kuchukuliwa" → "Imechukuliwa").
-- The pickup point's address and hours are settings (seeded in database/seeds/02_settings.php).
-- =====================================================================

ALTER TABLE delivery_methods
    MODIFY COLUMN delivery_method_pricing ENUM('fixed', 'region_estimate', 'paid_to_rider') NOT NULL DEFAULT 'fixed',
    ADD COLUMN delivery_method_is_pickup TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_method_pricing;

ALTER TABLE orders
    ADD COLUMN order_delivery_paid_to_rider TINYINT(1) NOT NULL DEFAULT 0 AFTER order_delivery_fee_note,   -- transport paid to the rider, not CHIMBO
    ADD COLUMN order_is_pickup              TINYINT(1) NOT NULL DEFAULT 0 AFTER order_delivery_paid_to_rider;

-- Dar: Standard becomes the one Bolt option; Haraka is switched off (old orders keep their fees)
UPDATE delivery_methods
SET delivery_method_name = 'Bolt (Dar es Salaam)', delivery_method_fee = 0, delivery_method_pricing = 'paid_to_rider',
    delivery_method_eta_min_days = 0, delivery_method_eta_max_days = 1
WHERE delivery_method_code = 'standard';

UPDATE delivery_methods SET delivery_method_is_active = 0 WHERE delivery_method_code = 'express';

-- Every region: collect at the shop, free. Only in a database that already has its regions — a new database gets
-- every option from database/seeds/05_delivery_methods.php (in the order the apps and tests expect)
INSERT IGNORE INTO delivery_methods (delivery_method_code, delivery_method_name, delivery_method_fee, delivery_method_pricing,
                                     delivery_method_is_pickup, delivery_method_eta_min_days, delivery_method_eta_max_days,
                                     region_id, delivery_method_area, delivery_method_is_active, delivery_method_sort_order)
SELECT 'pickup', 'Nitachukua mwenyewe dukani', 0, 'fixed', 1, 0, 1, NULL, 'all', 1, 4
FROM regions WHERE region_name = 'Dar es Salaam';
