-- Customer requests for products that are not currently listed. Staff may match
-- a request to a catalog product; notification is sent when it is in stock.
CREATE TABLE IF NOT EXISTS product_requests (
    product_request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_request_description VARCHAR(255) NOT NULL,
    product_request_image_path VARCHAR(255) NULL,
    product_request_status ENUM('pending', 'matched', 'notified', 'cancelled') NOT NULL DEFAULT 'pending',
    matched_product_id INT UNSIGNED NULL,
    notified_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_product_requests_status_created (product_request_status, created_at),
    KEY idx_product_requests_matched_product (matched_product_id, product_request_status),
    CONSTRAINT fk_product_requests_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_product_requests_product FOREIGN KEY (matched_product_id) REFERENCES products (product_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
