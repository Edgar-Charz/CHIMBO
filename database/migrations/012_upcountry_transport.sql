-- =====================================================================
-- 012_upcountry_transport.sql — transport priced per region and confirmed by staff (blueprint R-25, §12.7)
--
-- Every region gets an estimated transport cost. A delivery option can be priced by that estimate; the order then
-- waits until staff measure the load and confirm (or adjust) the fee, and only then can the customer pay.
-- Delivery options also get "where": every region, only one region, or every region except one.
-- =====================================================================

ALTER TABLE regions
    ADD COLUMN region_transport_estimate INT UNSIGNED NULL AFTER region_name;   -- TZS; NULL = "tutakujulisha bei"

ALTER TABLE delivery_methods
    ADD COLUMN delivery_method_area    ENUM('all', 'only', 'except') NOT NULL DEFAULT 'all' AFTER region_id,   -- with region_id
    ADD COLUMN delivery_method_pricing ENUM('fixed', 'region_estimate') NOT NULL DEFAULT 'fixed' AFTER delivery_method_fee;

-- Options that already had one region were "only that region"
UPDATE delivery_methods SET delivery_method_area = 'only' WHERE region_id IS NOT NULL;

ALTER TABLE orders
    ADD COLUMN order_delivery_fee_estimate              INT UNSIGNED NULL AFTER order_delivery_fee,   -- NULL = a fixed fee, nothing to confirm
    ADD COLUMN order_delivery_fee_confirmed_at          DATETIME     NULL AFTER order_delivery_fee_estimate,
    ADD COLUMN order_delivery_fee_confirmed_by_admin_id INT UNSIGNED NULL AFTER order_delivery_fee_confirmed_at,
    ADD COLUMN order_delivery_fee_note                  VARCHAR(255) NULL AFTER order_delivery_fee_confirmed_by_admin_id,   -- why the price changed (customer sees it)
    ADD KEY idx_order_delivery_fee_waiting (order_delivery_fee_confirmed_at, order_status);

-- Dar keeps its fixed-fee options: Standard (until now "every region") becomes Dar-only …
UPDATE delivery_methods
SET region_id = (SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'), delivery_method_area = 'only'
WHERE delivery_method_code = 'standard' AND region_id IS NULL;

-- … and every other region gets "Usafiri wa mikoani", priced by the region's estimate
INSERT INTO delivery_methods (delivery_method_code, delivery_method_name, delivery_method_fee, delivery_method_pricing,
                              delivery_method_eta_min_days, delivery_method_eta_max_days, region_id, delivery_method_area,
                              delivery_method_is_active, delivery_method_sort_order)
SELECT 'upcountry', 'Usafiri wa mikoani', 0, 'region_estimate', 2, 5, region_id, 'except', 1, 3
FROM regions WHERE region_name = 'Dar es Salaam';
