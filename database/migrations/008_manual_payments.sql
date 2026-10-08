-- =====================================================================
-- 008_manual_payments.sql — payment methods and payments (checked by staff for now)
--
-- Until a payment provider is connected:
--   the customer orders → pays with Lipa Namba / bank transfer → sends the payer number + confirmation code
--   ("Nimelipa") → staff check the statement and confirm or reject it in the admin.
-- Later the provider's callback fills the same `payments` rows and confirms them automatically.
-- Rules live in PaymentMethod and Payment.
-- =====================================================================

-- What customers may choose at checkout, and where to send the money ("pay to" details)
CREATE TABLE payment_methods (
    payment_method_id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    payment_method_code            ENUM('mpesa', 'airtel_money', 'mixx', 'bank', 'cod') NOT NULL,   -- what orders store
    payment_method_name            VARCHAR(60)   NOT NULL,          -- shown to the customer, e.g. "M-Pesa"
    payment_method_type            ENUM('mobile_money', 'bank', 'cash') NOT NULL,
    payment_method_account_name    VARCHAR(100)  NULL,              -- e.g. "CHIMBO LTD"
    payment_method_account_number  VARCHAR(40)   NULL,              -- Lipa Namba, phone number or bank account
    payment_method_bank_name       VARCHAR(80)   NULL,              -- banks only, e.g. "CRDB"
    payment_method_instructions    VARCHAR(500)  NULL,              -- extra steps shown to the customer (Kiswahili)
    payment_method_is_active       TINYINT(1)    NOT NULL DEFAULT 0,
    payment_method_sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at                     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                     DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_method_id),
    UNIQUE KEY uq_payment_method_code (payment_method_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO payment_methods (payment_method_code, payment_method_name, payment_method_type, payment_method_sort_order) VALUES
    ('mpesa',        'M-Pesa',          'mobile_money', 1),
    ('airtel_money', 'Airtel Money',    'mobile_money', 2),
    ('mixx',         'Mixx by Yas',     'mobile_money', 3),
    ('bank',         'Benki',           'bank',         4),
    ('cod',          'Lipa ukipokea',   'cash',         5);

-- Keep what was switched on in the old "enabled_payment_methods" setting, then remove the setting
UPDATE payment_methods
SET payment_method_is_active = 1
WHERE FIND_IN_SET(payment_method_code,
        REPLACE(COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'enabled_payment_methods'), 'cod'), ' ', '')) > 0;

DELETE FROM settings WHERE setting_key = 'enabled_payment_methods';

-- One row per payment attempt. Now: what the customer sent ("Nimelipa") and the staff decision.
-- Later: also the provider's automatic payments (payment_provider = the provider's name).
CREATE TABLE payments (
    payment_id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                  BIGINT UNSIGNED NOT NULL,
    user_id                   BIGINT UNSIGNED NOT NULL,
    payment_method_code       ENUM('mpesa', 'airtel_money', 'mixx', 'bank', 'cod') NOT NULL,
    payment_amount            INT UNSIGNED  NOT NULL,              -- TZS: the order total when it was sent
    payment_payer_account     VARCHAR(40)   NOT NULL,              -- the phone / bank account the money came from
    payment_reference         VARCHAR(40)   NOT NULL,              -- the confirmation code from the SMS / bank slip
    payment_status            ENUM('submitted', 'confirmed', 'rejected') NOT NULL DEFAULT 'submitted',
    payment_provider          VARCHAR(30)   NOT NULL DEFAULT 'manual',   -- 'manual' = sent by the customer or staff
    payment_review_note       VARCHAR(255)  NULL,                  -- why it was rejected (shown to the customer)
    submitted_by_admin_id     INT UNSIGNED  NULL,                  -- staff recorded it for the customer (phone order)
    reviewed_by_admin_id      INT UNSIGNED  NULL,
    payment_reviewed_at       DATETIME      NULL,
    created_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    KEY idx_payment_order (order_id),
    KEY idx_payment_status (payment_status, created_at),
    KEY idx_payment_reference (payment_reference),
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders (order_id),
    CONSTRAINT fk_payment_user  FOREIGN KEY (user_id)  REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checking payments by hand takes longer than a provider: unpaid orders now wait 24 hours (staff can change it)
UPDATE settings SET setting_value = '1440' WHERE setting_key = 'unpaid_order_expiry_minutes' AND setting_value = '30';

-- The draft terms said cash only; change that sentence if staff have not rewritten it
UPDATE settings
SET setting_value = REPLACE(setting_value,
    'Malipo: kwa sasa malipo ni wakati wa kupokea mzigo. Kiasi cha juu cha oda ya aina hii kinaweza kuwekewa kikomo.',
    'Malipo: unaweza kulipa kwa simu (M-Pesa, Airtel Money, Mixx), kwa benki au wakati wa kupokea mzigo. Malipo kwa simu na benki huthibitishwa na CHIMBO kabla oda haijaandaliwa. Oda ya kulipa ukipokea ina kiasi cha juu.')
WHERE setting_key = 'legal_terms';
