-- Keep provider delivery receipts separate from the initial queue acceptance.
ALTER TABLE sms_outbox
    MODIFY COLUMN sms_status ENUM('queued', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'queued',
    ADD COLUMN sms_status_checked_at DATETIME NULL AFTER sms_sent_at;
