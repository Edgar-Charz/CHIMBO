-- =====================================================================
-- 011_admin_notifications.sql — the staff bell in the admin top bar
--
-- Things that happened (new order, payment to check, low stock …), each for the staff whose role handles it
-- (admin_notification_permission). Read / unread is kept per staff member in admin_notification_reads.
-- Rules live in AdminNotification.
-- =====================================================================

CREATE TABLE admin_notifications (
    admin_notification_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_notification_type        VARCHAR(40)   NOT NULL,          -- e.g. "order.placed" (AdminNotification::TYPES)
    admin_notification_title       VARCHAR(150)  NOT NULL,
    admin_notification_body        VARCHAR(500)  NOT NULL,
    admin_notification_link        VARCHAR(255)  NULL,              -- admin page it opens, e.g. "order_details.php?id=12"
    admin_notification_permission  VARCHAR(50)   NOT NULL,          -- only staff with this permission see it
    created_at                     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_notification_id),
    KEY idx_admin_notification_permission (admin_notification_permission, admin_notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_notification_reads (
    admin_id               INT UNSIGNED    NOT NULL,
    admin_notification_id  BIGINT UNSIGNED NOT NULL,
    read_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id, admin_notification_id),
    CONSTRAINT fk_admin_notification_read_admin        FOREIGN KEY (admin_id) REFERENCES admins (admin_id),
    CONSTRAINT fk_admin_notification_read_notification FOREIGN KEY (admin_notification_id)
        REFERENCES admin_notifications (admin_notification_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
