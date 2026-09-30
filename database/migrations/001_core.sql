-- =====================================================================
-- 001_core.sql — identity, login, locations, admins and platform tables
--
-- Naming (docs/CODING_STANDARDS.md §3):
--   primary key  <entity>_id          e.g. users.user_id
--   columns      <entity>_<name>      e.g. user_phone, user_full_name
--   foreign keys named like the key they point to (users.user_id → business_profiles.user_id)
--   money = whole Tanzanian shillings (INT), dates saved in UTC
-- =====================================================================

-- Tanzania's regions (Mkoa) and districts (Wilaya) — filled by database/seeds
CREATE TABLE regions (
    region_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    region_name     VARCHAR(60)   NOT NULL,
    PRIMARY KEY (region_id),
    UNIQUE KEY uq_region_name (region_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE districts (
    district_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    region_id       INT UNSIGNED  NOT NULL,
    district_name   VARCHAR(80)   NOT NULL,
    PRIMARY KEY (district_id),
    UNIQUE KEY uq_district_in_region (region_id, district_name),
    CONSTRAINT fk_districts_region FOREIGN KEY (region_id) REFERENCES regions (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CHIMBO staff who use the admin dashboard (they log in with email + password)
CREATE TABLE admins (
    admin_id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    admin_full_name       VARCHAR(100)  NOT NULL,
    admin_email           VARCHAR(150)  NOT NULL,
    admin_password_hash   VARCHAR(255)  NOT NULL,                 -- password_hash(), never the real password
    admin_role            ENUM('super_admin', 'catalog', 'operations', 'finance') NOT NULL,
    admin_status          ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    admin_failed_logins   TINYINT UNSIGNED NOT NULL DEFAULT 0,     -- wrong passwords in a row
    admin_locked_until    DATETIME      NULL,                     -- temporary lock after too many failures
    admin_last_login_at   DATETIME      NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id),
    UNIQUE KEY uq_admin_email (admin_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers (business owners). They log in with phone + OTP, so there is no password column.
CREATE TABLE users (
    user_id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_phone              VARCHAR(16)   NOT NULL,               -- always +255XXXXXXXXX
    user_full_name          VARCHAR(100)  NULL,                   -- filled in registration step 3
    user_email              VARCHAR(150)  NULL,
    user_avatar_path        VARCHAR(255)  NULL,
    user_locale             ENUM('sw', 'en') NOT NULL DEFAULT 'sw',
    user_status             ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
    user_phone_verified_at  DATETIME      NULL,
    user_last_login_at      DATETIME      NULL,
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME      NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_phone (user_phone),
    UNIQUE KEY uq_user_email (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The customer's shop ("Tuambie Kuhusu Biashara Yako") — one per user
CREATE TABLE business_profiles (
    business_profile_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                         BIGINT UNSIGNED NOT NULL,
    business_name                   VARCHAR(120)  NULL,           -- optional ("Si lazima")
    region_id                       INT UNSIGNED  NOT NULL,
    district_id                     INT UNSIGNED  NULL,           -- optional ("Si lazima")
    business_verification_status    ENUM('unverified', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
    business_verified_at            DATETIME      NULL,
    business_verified_by_admin_id   INT UNSIGNED  NULL,           -- which admin verified it
    created_at                      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (business_profile_id),
    UNIQUE KEY uq_business_user (user_id),
    CONSTRAINT fk_business_user     FOREIGN KEY (user_id)     REFERENCES users (user_id),
    CONSTRAINT fk_business_region   FOREIGN KEY (region_id)   REFERENCES regions (region_id),
    CONSTRAINT fk_business_district FOREIGN KEY (district_id) REFERENCES districts (district_id),
    CONSTRAINT fk_business_verifier FOREIGN KEY (business_verified_by_admin_id) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time login codes sent by SMS (only a hash of the code is stored)
CREATE TABLE otp_codes (
    otp_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    otp_phone        VARCHAR(16)   NOT NULL,
    otp_code_hash    CHAR(64)      NOT NULL,                      -- HMAC-SHA256 of the code, keyed with APP_KEY
    otp_purpose      ENUM('login') NOT NULL DEFAULT 'login',
    otp_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,          -- wrong guesses so far
    otp_expires_at   DATETIME      NOT NULL,
    otp_consumed_at  DATETIME      NULL,                          -- set when used, so it works only once
    otp_ip_address   VARCHAR(45)   NOT NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (otp_id),
    KEY idx_otp_phone_created (otp_phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login tokens for the mobile app (only a hash is stored; the app keeps the real token)
CREATE TABLE auth_tokens (
    auth_token_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                  BIGINT UNSIGNED NOT NULL,
    auth_token_hash          CHAR(64)      NOT NULL,              -- SHA-256 of the token
    auth_token_device_name   VARCHAR(100)  NULL,
    auth_token_platform      ENUM('android', 'ios') NULL,
    auth_token_expires_at    DATETIME      NOT NULL,
    auth_token_last_used_at  DATETIME      NULL,
    auth_token_revoked_at    DATETIME      NULL,                  -- set on logout
    created_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (auth_token_id),
    UNIQUE KEY uq_auth_token_hash (auth_token_hash),
    KEY idx_auth_token_user (user_id),
    CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counters that stop abuse, e.g. too many OTP requests for one phone
CREATE TABLE rate_limits (
    rate_limit_key           VARCHAR(191)  NOT NULL,              -- e.g. "otp:phone:+255712345678"
    rate_limit_window_start  DATETIME      NOT NULL,
    rate_limit_hits          INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (rate_limit_key, rate_limit_window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who changed what (admin actions, payments, status changes)
CREATE TABLE audit_logs (
    audit_log_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    audit_log_actor_type   ENUM('admin', 'customer', 'system') NOT NULL,
    audit_log_actor_id     BIGINT UNSIGNED NULL,
    audit_log_action       VARCHAR(100)  NOT NULL,                -- e.g. "product.updated"
    audit_log_entity_type  VARCHAR(50)   NOT NULL,                -- e.g. "product"
    audit_log_entity_id    BIGINT UNSIGNED NULL,
    audit_log_old_values   JSON          NULL,
    audit_log_new_values   JSON          NULL,
    audit_log_ip_address   VARCHAR(45)   NULL,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_log_id),
    KEY idx_audit_entity (audit_log_entity_type, audit_log_entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Values staff can change without code: support phone, COD limit, OTP rules …
CREATE TABLE settings (
    setting_key    VARCHAR(100)  NOT NULL,
    setting_value  TEXT          NOT NULL,
    updated_at     DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS waiting to be sent (OTP codes, order updates); a cron job sends and retries them
CREATE TABLE sms_outbox (
    sms_id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sms_phone                VARCHAR(16)   NOT NULL,
    sms_message              VARCHAR(480)  NOT NULL,
    sms_purpose              VARCHAR(50)   NOT NULL,              -- e.g. "otp", "order_status"
    sms_status               ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    sms_attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sms_provider_message_id  VARCHAR(100)  NULL,
    created_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sms_sent_at              DATETIME      NULL,
    PRIMARY KEY (sms_id),
    KEY idx_sms_status (sms_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
