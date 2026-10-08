-- =====================================================================
-- 010_product_offers.sql — time-limited offers ("Ofa", blueprint §12.4)
--
-- A percentage off EVERY price tier of one product, between a start and an end time (UTC).
-- It starts and ends by itself: the server compares the time on every request (no scheduled job).
-- At most one offer per product at a time (checked in ProductOffer). Rules live in ProductOffer and Pricing.
-- =====================================================================

CREATE TABLE product_offers (
    product_offer_id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    product_id                INT UNSIGNED     NOT NULL,
    product_offer_percent     TINYINT UNSIGNED NOT NULL,          -- 1–90, taken off every tier
    product_offer_starts_at   DATETIME         NOT NULL,          -- UTC
    product_offer_ends_at     DATETIME         NOT NULL,          -- UTC; "end now" moves it to the current time
    created_by_admin_id       INT UNSIGNED     NULL,
    created_at                DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME         NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_offer_id),
    KEY idx_product_offer_running (product_id, product_offer_ends_at, product_offer_starts_at),
    KEY idx_product_offer_ends (product_offer_ends_at),
    CONSTRAINT fk_product_offer_product FOREIGN KEY (product_id) REFERENCES products (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What each order line was charged under (0 = no offer), for receipts and reports
ALTER TABLE order_items
    ADD COLUMN order_item_offer_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER order_item_tier_min_quantity;
