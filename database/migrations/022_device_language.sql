-- Store each app installation preference for language-specific system push alerts.
ALTER TABLE device_tokens
    ADD COLUMN device_language ENUM('sw', 'en') NOT NULL DEFAULT 'sw' AFTER device_platform;
