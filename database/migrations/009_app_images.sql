-- =====================================================================
-- 009_app_images.sql — pictures of the app's fixed screens, replaceable from the admin
--
-- Onboarding slides, login / registration screens and "Oda Imepokelewa!". Each screen has a fixed
-- slot (AppImage::SLOTS); the words stay in the app (they are translated). A slot without a row
-- uses the picture built into the app.
-- =====================================================================

CREATE TABLE app_images (
    app_image_slot              VARCHAR(40)   NOT NULL,          -- e.g. "onboarding_products" (AppImage::SLOTS)
    app_image_path              VARCHAR(255)  NOT NULL,          -- media/app/… WebP
    updated_by_admin_id         INT UNSIGNED  NULL,
    updated_at                  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (app_image_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
