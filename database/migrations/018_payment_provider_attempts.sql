-- =====================================================================
-- 018_payment_provider_attempts.sql — provider-backed payment attempt storage
--
-- Keep manual payment review working while adding fields for server-initiated provider payments.
-- =====================================================================

ALTER TABLE payments
    MODIFY COLUMN payment_status ENUM(
        'submitted', 'confirmed', 'rejected',
        'initiated', 'pending', 'successful', 'failed', 'cancelled', 'expired', 'refunded'
    ) NOT NULL DEFAULT 'submitted',
    ADD COLUMN payment_provider_reference VARCHAR(120) NULL AFTER payment_provider,
    ADD COLUMN payment_provider_redirect_url VARCHAR(500) NULL AFTER payment_provider_reference,
    ADD COLUMN payment_idempotency_key VARCHAR(100) NULL AFTER payment_provider_redirect_url,
    ADD UNIQUE KEY uq_payment_provider_reference (payment_provider, payment_provider_reference),
    ADD UNIQUE KEY uq_payment_idempotency_key (payment_idempotency_key);

CREATE TABLE payment_events (
    payment_event_id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id                      BIGINT UNSIGNED NULL,
    payment_provider                VARCHAR(30) NOT NULL,
    payment_provider_event_id        VARCHAR(120) NOT NULL,
    payment_event_type              VARCHAR(60) NOT NULL,
    payment_event_payload           LONGTEXT NOT NULL,
    payment_event_signature_valid   TINYINT(1) NULL,
    payment_event_processing_status ENUM('received', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    payment_event_processed_at      DATETIME NULL,
    created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_event_id),
    UNIQUE KEY uq_payment_provider_event (payment_provider, payment_provider_event_id),
    KEY idx_payment_event_payment (payment_id, created_at),
    KEY idx_payment_event_processing (payment_event_processing_status, created_at),
    CONSTRAINT fk_payment_event_payment FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
