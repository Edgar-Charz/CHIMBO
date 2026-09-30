-- =====================================================================
-- 004_orders.sql — orders, their items and status history, delivery agents, notifications
--
-- An order COPIES everything it needs at the moment it is placed (product names, prices, the address,
-- the delivery method), so editing a product or an address later never changes a past order.
-- Short prefixes: order_status_history → status_*, order_deliveries → delivery_*.
-- =====================================================================

-- Staff (or partners) who deliver orders; shown to the customer on "Fuatilia Oda"
CREATE TABLE delivery_agents (
    delivery_agent_id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    delivery_agent_full_name   VARCHAR(100)  NOT NULL,
    delivery_agent_phone       VARCHAR(16)   NOT NULL,
    delivery_agent_photo_path  VARCHAR(255)  NULL,
    delivery_agent_is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    order_id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number                  VARCHAR(12)   NOT NULL,          -- "CHB123456", shown to the customer
    user_id                       BIGINT UNSIGNED NOT NULL,
    order_channel                 ENUM('app', 'web') NOT NULL,
    order_status                  ENUM('pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit',
                                       'delivered', 'cancelled', 'expired') NOT NULL,
    order_payment_method          ENUM('mpesa', 'airtel_money', 'mixx', 'bank', 'cod') NOT NULL,
    order_payment_status          ENUM('unpaid', 'pending', 'paid', 'cod_pending', 'refunded', 'failed') NOT NULL,
    order_subtotal                INT UNSIGNED  NOT NULL,          -- TZS, sum of the items
    order_delivery_fee            INT UNSIGNED  NOT NULL,
    order_discount_total          INT UNSIGNED  NOT NULL DEFAULT 0,
    order_total                   INT UNSIGNED  NOT NULL,          -- what the customer pays
    -- Delivery method, as it was when ordering
    delivery_method_id            INT UNSIGNED  NOT NULL,
    order_delivery_method_name    VARCHAR(80)   NOT NULL,
    order_delivery_days_min       TINYINT UNSIGNED NOT NULL,
    order_delivery_days_max       TINYINT UNSIGNED NOT NULL,
    order_estimated_delivery_date DATE          NOT NULL,          -- "Makadirio ya Uwasilishaji"
    -- Delivery address, as it was when ordering
    order_ship_recipient_name     VARCHAR(100)  NOT NULL,
    order_ship_phone              VARCHAR(16)   NOT NULL,
    region_id                     INT UNSIGNED  NOT NULL,          -- for reports and delivery planning
    order_ship_region_name        VARCHAR(60)   NOT NULL,
    order_ship_district_name      VARCHAR(80)   NULL,
    order_ship_street             VARCHAR(160)  NOT NULL,
    order_ship_landmark           VARCHAR(160)  NULL,
    order_customer_note           VARCHAR(500)  NULL,
    order_idempotency_key         VARCHAR(64)   NULL,              -- same key sent twice = the same order, never two
    order_expires_at              DATETIME      NULL,              -- unpaid mobile-money orders are cancelled after this
    order_placed_at               DATETIME      NOT NULL,
    order_delivered_at            DATETIME      NULL,
    order_cancelled_at            DATETIME      NULL,
    order_cancel_reason           VARCHAR(255)  NULL,
    created_at                    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_order_number (order_number),
    UNIQUE KEY uq_order_idempotency (user_id, order_idempotency_key),
    KEY idx_order_user_status (user_id, order_status, order_placed_at),
    KEY idx_order_status_placed (order_status, order_placed_at),
    CONSTRAINT fk_order_user            FOREIGN KEY (user_id)            REFERENCES users (user_id),
    CONSTRAINT fk_order_delivery_method FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods (delivery_method_id),
    CONSTRAINT fk_order_region          FOREIGN KEY (region_id)          REFERENCES regions (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The products of an order, with the price paid (a copy — never changes later)
CREATE TABLE order_items (
    order_item_id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                      BIGINT UNSIGNED NOT NULL,
    product_id                    INT UNSIGNED  NOT NULL,
    seller_id                     INT UNSIGNED  NOT NULL,
    order_item_product_name       VARCHAR(150)  NOT NULL,
    order_item_sku                VARCHAR(60)   NOT NULL,
    order_item_image_path         VARCHAR(255)  NULL,              -- the main photo's thumbnail at ordering time
    order_item_unit_label         VARCHAR(20)   NOT NULL,
    order_item_quantity           INT UNSIGNED  NOT NULL,
    order_item_unit_price         INT UNSIGNED  NOT NULL,          -- tier price reached
    order_item_tier_min_quantity  INT UNSIGNED  NOT NULL,          -- e.g. 6 → "6+ pcs @ TZS 5,000"
    order_item_line_total         INT UNSIGNED  NOT NULL,
    PRIMARY KEY (order_item_id),
    KEY idx_order_item_order (order_id),
    KEY idx_order_item_product (product_id),
    CONSTRAINT fk_order_item_order   FOREIGN KEY (order_id)   REFERENCES orders (order_id),
    CONSTRAINT fk_order_item_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_order_item_seller  FOREIGN KEY (seller_id)  REFERENCES sellers (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every status change with its time — the "Fuatilia Oda" timeline. Never edited or deleted.
CREATE TABLE order_status_history (
    status_history_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id           BIGINT UNSIGNED NOT NULL,
    order_status       ENUM('pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit',
                            'delivered', 'cancelled', 'expired') NOT NULL,
    status_note        VARCHAR(255)  NULL,
    status_actor_type  ENUM('customer', 'admin', 'system') NOT NULL,
    status_actor_id    BIGINT UNSIGNED NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (status_history_id),
    KEY idx_status_order (order_id, status_history_id),
    CONSTRAINT fk_status_order FOREIGN KEY (order_id) REFERENCES orders (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who delivers the order, and cash collected for "Lipa ukipokea"
CREATE TABLE order_deliveries (
    order_delivery_id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                           BIGINT UNSIGNED NOT NULL,
    delivery_agent_id                  INT UNSIGNED  NULL,
    delivery_dispatched_at             DATETIME      NULL,
    delivery_delivered_at              DATETIME      NULL,
    delivery_cash_collected            INT UNSIGNED  NULL,          -- TZS, cash on delivery
    delivery_cash_confirmed_by_admin_id INT UNSIGNED NULL,
    delivery_cash_confirmed_at         DATETIME      NULL,
    PRIMARY KEY (order_delivery_id),
    UNIQUE KEY uq_delivery_order (order_id),
    CONSTRAINT fk_delivery_order FOREIGN KEY (order_id)          REFERENCES orders (order_id),
    CONSTRAINT fk_delivery_agent FOREIGN KEY (delivery_agent_id) REFERENCES delivery_agents (delivery_agent_id),
    CONSTRAINT fk_delivery_cash_admin FOREIGN KEY (delivery_cash_confirmed_by_admin_id) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notifications (the bell): order updates, payments, promotions
CREATE TABLE notifications (
    notification_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id               BIGINT UNSIGNED NOT NULL,
    notification_type     ENUM('order_status', 'payment', 'promo', 'system') NOT NULL,
    notification_title    VARCHAR(120)  NOT NULL,
    notification_body     VARCHAR(500)  NOT NULL,
    notification_data     JSON          NULL,                       -- e.g. {"order_id": 12} to open the order
    notification_read_at  DATETIME      NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY idx_notification_user (user_id, notification_read_at, notification_id),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
