-- =====================================================================
-- 006_stored_product_prices.sql — faster product lists
--
-- Every product list used to work out the normal price and the "kuanzia" price from the whole
-- product_price_tiers table on each request. They are now stored on the product and kept up to date
-- whenever the tiers are saved (ProductEditor::refreshStoredPrices()), so lists, price filters and price
-- sorting can use an index. Cart and checkout still price every line from the tiers themselves.
-- =====================================================================

ALTER TABLE products
    ADD COLUMN product_price      INT UNSIGNED NULL AFTER product_moq,          -- normal price (highest tier); NULL = no tiers = not for sale
    ADD COLUMN product_price_from INT UNSIGNED NULL AFTER product_price,        -- best wholesale price (lowest tier)
    ADD KEY idx_product_price (product_price);

-- Fill them in for the products that already exist
UPDATE products p
JOIN (
    SELECT product_id, MAX(tier_unit_price) AS normal_price, MIN(tier_unit_price) AS best_price
    FROM product_price_tiers
    GROUP BY product_id
) tiers ON tiers.product_id = p.product_id
SET p.product_price = tiers.normal_price,
    p.product_price_from = tiers.best_price;
