-- Mobile device registrations and queued Firebase push deliveries.

CREATE TABLE device_tokens (
    device_token_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id               BIGINT UNSIGNED NOT NULL,
    auth_token_id         BIGINT UNSIGNED NOT NULL,
    device_token_hash     CHAR(64)        NOT NULL,
    device_token          TEXT            NOT NULL,
    device_platform       ENUM('android', 'ios') NOT NULL,
    last_seen_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (device_token_id),
    UNIQUE KEY uq_device_token_hash (device_token_hash),
    KEY idx_device_token_user (user_id),
    CONSTRAINT fk_device_token_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_device_token_auth FOREIGN KEY (auth_token_id) REFERENCES auth_tokens (auth_token_id) ON DELETE CASCADE
);

CREATE TABLE notification_push_queue (
    notification_push_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id       BIGINT UNSIGNED NOT NULL,
    user_id               BIGINT UNSIGNED NOT NULL,
    push_status           ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    push_attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    push_next_attempt_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    push_locked_until     DATETIME        NULL,
    push_last_error       VARCHAR(120)    NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_push_id),
    UNIQUE KEY uq_notification_push_notification (notification_id),
    KEY idx_notification_push_pending (push_status, push_next_attempt_at, push_locked_until),
    CONSTRAINT fk_notification_push_notification FOREIGN KEY (notification_id) REFERENCES notifications (notification_id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_push_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);
