-- =====================================================================
-- 007_customer_pin.sql — login with a 4–6 digit PIN
--
-- Registration: phone → SMS code → create PIN → business details.
-- Next logins:  phone → PIN. "Umesahau PIN?" → SMS code → new PIN.
-- Rules live in CustomerPin and CustomerAuth.
-- =====================================================================

ALTER TABLE users
    ADD COLUMN user_pin_hash            VARCHAR(255)     NULL AFTER user_status,                 -- NULL = no PIN yet (use the SMS code)
    ADD COLUMN user_pin_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_pin_hash, -- wrong PINs in a row
    ADD COLUMN user_pin_locked_at       DATETIME         NULL AFTER user_pin_failed_attempts,    -- set after 5 wrong PINs; only a new PIN (via SMS) unlocks
    ADD COLUMN user_pin_changed_at      DATETIME         NULL AFTER user_pin_locked_at,
    ADD COLUMN user_sessions_revoked_at DATETIME         NULL AFTER user_last_login_at;          -- website logins older than this are logged out

-- A device that just proved the phone with an SMS code may set a new PIN without the old one, for a short time
ALTER TABLE auth_tokens
    ADD COLUMN auth_token_pin_reset_until DATETIME NULL AFTER auth_token_revoked_at;
