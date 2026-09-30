-- =====================================================================
-- 002_catalog.sql — sellers, categories, products, tier prices, images, stock history, banners
--
-- Naming as in 001_core.sql. Two tables use a shorter column prefix to stay readable
-- (and to match the mobile app's JSON): product_price_tiers → tier_*, inventory_movements → movement_*.
-- Money = whole TZS (INT UNSIGNED).
-- =====================================================================

-- Suppliers shown on product pages ("Muuzaji Aliyethibitishwa")
CREATE TABLE sellers (
    seller_id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    seller_name         VARCHAR(120)  NOT NULL,
    seller_slug         VARCHAR(140)  NOT NULL,                   -- for web addresses, e.g. "shamba-la-vipodozi"
    seller_description  TEXT          NULL,
    seller_phone        VARCHAR(16)   NULL,
    seller_logo_path    VARCHAR(255)  NULL,
    seller_is_verified  TINYINT(1)    NOT NULL DEFAULT 0,
    seller_status       ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (seller_id),
    UNIQUE KEY uq_seller_slug (seller_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two levels: top categories (Cosmetics, Jewelry) and their chips (Skin Care, Earrings …)
CREATE TABLE categories (
    category_id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    parent_category_id   INT UNSIGNED  NULL,                      -- NULL = top category
    category_name        VARCHAR(80)   NOT NULL,
    category_slug        VARCHAR(100)  NOT NULL,
    category_tagline     VARCHAR(120)  NULL,                      -- e.g. "Shamba la Vipodozi"
    category_image_path  VARCHAR(255)  NULL,
    category_sort_order  SMALLINT      NOT NULL DEFAULT 0,        -- lower comes first
    category_is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_category_slug (category_slug),
    KEY idx_category_parent (parent_category_id, category_sort_order),
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_category_id) REFERENCES categories (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    product_id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    seller_id                  INT UNSIGNED  NOT NULL,
    category_id                INT UNSIGNED  NOT NULL,             -- a chip (sub-category), e.g. "Skin Care"
    product_name               VARCHAR(150)  NOT NULL,
    product_slug               VARCHAR(170)  NOT NULL,
    product_description        TEXT          NULL,
    product_brand              VARCHAR(80)   NULL,
    product_sku                VARCHAR(60)   NOT NULL,             -- stock code
    product_unit_label         VARCHAR(20)   NOT NULL DEFAULT 'pc',-- pc, pack, set …
    product_moq                INT UNSIGNED  NOT NULL DEFAULT 1,   -- minimum order quantity
    product_stock_quantity     INT UNSIGNED  NOT NULL DEFAULT 0,
    product_compare_at_price   INT UNSIGNED  NULL,                 -- old price → "Ofa" (deal) when higher than the price
    product_is_bestseller      TINYINT(1)    NOT NULL DEFAULT 0,   -- "Bestseller" badge, set by staff
    product_new_until          DATE          NULL,                 -- "Bidhaa Mpya" until this date
    product_sold_count         INT UNSIGNED  NOT NULL DEFAULT 0,   -- pieces sold, for "popular" sorting
    product_delivery_days_min  TINYINT UNSIGNED NOT NULL DEFAULT 2,
    product_delivery_days_max  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    product_is_active          TINYINT(1)    NOT NULL DEFAULT 1,   -- hidden from the shop when 0
    created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                 DATETIME      NULL,
    PRIMARY KEY (product_id),
    UNIQUE KEY uq_product_slug (product_slug),
    UNIQUE KEY uq_product_sku (product_sku),
    KEY idx_product_category (category_id, product_is_active),
    KEY idx_product_popular (product_is_bestseller, product_sold_count),
    CONSTRAINT fk_product_seller   FOREIGN KEY (seller_id)   REFERENCES sellers (seller_id),
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Nunua zaidi, lipa kidogo": the unit price for each quantity level.
-- The row with the smallest tier_min_quantity is the normal price; bigger quantities are cheaper.
CREATE TABLE product_price_tiers (
    tier_id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    product_id         INT UNSIGNED  NOT NULL,
    tier_min_quantity  INT UNSIGNED  NOT NULL,                     -- e.g. 6 → "6+ pcs"
    tier_unit_price    INT UNSIGNED  NOT NULL,                     -- TZS per piece at this level
    PRIMARY KEY (tier_id),
    UNIQUE KEY uq_tier_quantity (product_id, tier_min_quantity),
    CONSTRAINT fk_tier_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each image is saved in three sizes (WebP) so phones download only what they need
CREATE TABLE product_images (
    product_image_id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    product_id                 INT UNSIGNED  NOT NULL,
    product_image_thumb_path   VARCHAR(255)  NOT NULL,            -- ~300 px, product cards
    product_image_medium_path  VARCHAR(255)  NOT NULL,            -- ~800 px, product page
    product_image_large_path   VARCHAR(255)  NOT NULL,            -- ~1600 px, zoom
    product_image_sort_order   SMALLINT      NOT NULL DEFAULT 0,
    product_image_is_primary   TINYINT(1)    NOT NULL DEFAULT 0,  -- the one shown on cards
    created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (product_image_id),
    KEY idx_product_image (product_id, product_image_sort_order),
    CONSTRAINT fk_image_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every stock change, with the reason (restock, order, correction …) — never edited or deleted
CREATE TABLE inventory_movements (
    movement_id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id                INT UNSIGNED  NOT NULL,
    movement_quantity_change  INT           NOT NULL,             -- +50 restock, -8 order
    movement_reason           ENUM('restock', 'order_reserve', 'order_release', 'adjustment', 'return') NOT NULL,
    movement_note             VARCHAR(255)  NULL,
    movement_reference_type   VARCHAR(30)   NULL,                 -- e.g. "order"
    movement_reference_id     BIGINT UNSIGNED NULL,               -- e.g. the order id
    admin_id                  INT UNSIGNED  NULL,                 -- staff member who made the change
    created_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (movement_id),
    KEY idx_movement_product (product_id, created_at),
    CONSTRAINT fk_movement_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_movement_admin   FOREIGN KEY (admin_id)   REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promotional banners on Home ("BEI ZA JUMLA — Nunua Sasa")
CREATE TABLE banners (
    banner_id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    banner_title         VARCHAR(100)  NOT NULL,
    banner_subtitle      VARCHAR(160)  NULL,
    banner_image_path    VARCHAR(255)  NULL,
    banner_button_label  VARCHAR(40)   NULL,                      -- e.g. "Nunua Sasa"
    banner_target_type   ENUM('category', 'product', 'collection', 'url') NULL,
    banner_target_value  VARCHAR(255)  NULL,                      -- e.g. category id, or "deals"
    banner_sort_order    SMALLINT      NOT NULL DEFAULT 0,
    banner_is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    banner_starts_at     DATETIME      NULL,
    banner_ends_at       DATETIME      NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (banner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
