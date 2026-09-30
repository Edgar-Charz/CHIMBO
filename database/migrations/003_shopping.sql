-- =====================================================================
-- 003_shopping.sql — wishlist, cart, delivery addresses, delivery options
-- =====================================================================

-- Saved ("liked") products — the heart button
CREATE TABLE wishlist_items (
    user_id     BIGINT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED    NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- newest first in the list
    PRIMARY KEY (user_id, product_id),                               -- a product is saved at most once
    KEY idx_wishlist_user_created (user_id, created_at),
    CONSTRAINT fk_wishlist_user    FOREIGN KEY (user_id)    REFERENCES users (user_id),
    CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The cart ("Kikapu"): one row per product per customer. Prices are NOT stored here —
-- they are always recalculated from the tiers, so a price change is never missed.
CREATE TABLE cart_items (
    cart_item_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED    NOT NULL,
    cart_item_quantity  INT UNSIGNED    NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cart_item_id),
    UNIQUE KEY uq_cart_user_product (user_id, product_id),
    CONSTRAINT fk_cart_user    FOREIGN KEY (user_id)    REFERENCES users (user_id),
    CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery addresses ("Anwani za Usafirishaji")
CREATE TABLE addresses (
    address_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                 BIGINT UNSIGNED NOT NULL,
    address_recipient_name  VARCHAR(100)  NOT NULL,
    address_phone           VARCHAR(16)   NOT NULL,               -- +255XXXXXXXXX
    region_id               INT UNSIGNED  NOT NULL,
    district_id             INT UNSIGNED  NULL,
    address_street          VARCHAR(160)  NOT NULL,               -- e.g. "Kariakoo, Lumumba Street"
    address_landmark        VARCHAR(160)  NULL,                   -- e.g. "near the mosque"
    address_is_default      TINYINT(1)    NOT NULL DEFAULT 0,
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (address_id),
    KEY idx_address_user (user_id),
    CONSTRAINT fk_address_user     FOREIGN KEY (user_id)     REFERENCES users (user_id),
    CONSTRAINT fk_address_region   FOREIGN KEY (region_id)   REFERENCES regions (region_id),
    CONSTRAINT fk_address_district FOREIGN KEY (district_id) REFERENCES districts (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery options at checkout ("Standard (siku 2–3)", "Haraka (kesho)")
CREATE TABLE delivery_methods (
    delivery_method_id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    delivery_method_code          VARCHAR(30)   NOT NULL,          -- "standard", "express"
    delivery_method_name          VARCHAR(80)   NOT NULL,          -- shown to the customer
    delivery_method_fee           INT UNSIGNED  NOT NULL,          -- TZS
    delivery_method_eta_min_days  TINYINT UNSIGNED NOT NULL,
    delivery_method_eta_max_days  TINYINT UNSIGNED NOT NULL,
    region_id                     INT UNSIGNED  NULL,              -- NULL = every region; otherwise only this one
    delivery_method_is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    delivery_method_sort_order    SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (delivery_method_id),
    UNIQUE KEY uq_delivery_method_code (delivery_method_code),
    CONSTRAINT fk_delivery_method_region FOREIGN KEY (region_id) REFERENCES regions (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
