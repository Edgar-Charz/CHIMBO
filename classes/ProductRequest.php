<?php

/** Customer requests for products absent from the catalog and staff matching. */
class ProductRequest
{
    private const MAX_PENDING_PER_USER = 20;
    private const MAX_DAILY_REQUESTS = 10;
    private const STATUS_PENDING = 'pending';
    private const STATUS_MATCHED = 'matched';
    private const STATUS_NOTIFIED = 'notified';
    private const STATUS_CANCELLED = 'cancelled';

    public function __construct(private Database $db)
    {
    }

    /** Saves a request and, when supplied, a private copy of its reference photo. */
    public function createRequest(int $user_id, array $input, ?array $image): int
    {
        $data = Validator::validate($input, [
            'product_request_description' => 'required|string|min:2|max:255',
        ]);
        $pending = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM product_requests WHERE user_id = :user_id AND product_request_status IN (:pending, :matched)',
            ['user_id' => $user_id, 'pending' => self::STATUS_PENDING, 'matched' => self::STATUS_MATCHED]
        );
        if ($pending >= self::MAX_PENDING_PER_USER) {
            throw ApiException::validation(['product_request_description' => 'Una maombi mengi yanayosubiri. Tafadhali subiri yakaguliwe.']);
        }
        $recent = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM product_requests WHERE user_id = :user_id AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY',
            ['user_id' => $user_id]
        );
        if ($recent >= self::MAX_DAILY_REQUESTS) {
            throw ApiException::validation(['product_request_description' => 'Umetuma maombi mengi leo. Tafadhali jaribu tena baadaye.']);
        }

        $image_path = null;
        if ($image !== null && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $image_uploader = new ImageUploader();
            $paths = $image_uploader->saveUploadedFile($image, 'product-requests');
            $image_path = $paths['large'];
            $image_uploader->deleteImageFiles([$paths['thumb'], $paths['medium']]);
        }

        return $this->db->insert(
            'INSERT INTO product_requests (user_id, product_request_description, product_request_image_path)
             VALUES (:user_id, :description, :image_path)',
            ['user_id' => $user_id, 'description' => trim($data['product_request_description']), 'image_path' => $image_path]
        );
    }

    /** Staff queue with customer contact details and optional private image path. */
    public function getRequestsForAdmin(array $input = []): array
    {
        $data = Validator::validate($input, [
            'status' => 'nullable|in:' . implode(',', [self::STATUS_PENDING, self::STATUS_MATCHED, self::STATUS_NOTIFIED, self::STATUS_CANCELLED]),
        ]);
        $where = isset($data['status'])
            ? 'WHERE pr.product_request_status = :status'
            : 'WHERE pr.product_request_status IN (:pending, :matched)';
        $params = isset($data['status'])
            ? ['status' => $data['status']]
            : ['pending' => self::STATUS_PENDING, 'matched' => self::STATUS_MATCHED];
        return $this->db->fetchAll(
            'SELECT pr.product_request_id, pr.user_id, pr.product_request_description, pr.product_request_image_path,
                    pr.product_request_status, pr.matched_product_id, pr.created_at,
                    u.user_full_name, u.user_phone, p.product_name AS matched_product_name
             FROM product_requests pr
             JOIN users u ON u.user_id = pr.user_id
             LEFT JOIN products p ON p.product_id = pr.matched_product_id
             ' . $where . '
             ORDER BY pr.created_at ASC
             LIMIT 200',
            $params
        );
    }

    /** A customer's own requests, so they can see whether staff matched them. */
    public function getRequestsForUser(int $user_id): array
    {
        $requests = $this->db->fetchAll(
            'SELECT pr.product_request_id, pr.product_request_description, pr.product_request_status,
                    pr.product_request_image_path, pr.matched_product_id, p.product_name AS matched_product_name,
                    image.product_image_thumb_path AS matched_product_image_path, pr.created_at
             FROM product_requests pr
             LEFT JOIN products p ON p.product_id = pr.matched_product_id
             LEFT JOIN product_images image
                    ON image.product_id = p.product_id AND image.product_image_is_primary = 1
             WHERE pr.user_id = :user_id AND pr.product_request_status <> :cancelled
             ORDER BY pr.product_request_id DESC
             LIMIT 100',
            ['user_id' => $user_id, 'cancelled' => self::STATUS_CANCELLED]
        );

        foreach ($requests as &$request) {
            $request['product_request_image_url'] = $request['product_request_image_path'] === null
                ? null
                : url('api/v1/product-requests/' . $request['product_request_id'] . '/image');
            $request['matched_product_image_url'] = $request['matched_product_image_path'] === null
                ? null
                : url((string) $request['matched_product_image_path']);
            unset($request['product_request_image_path'], $request['matched_product_image_path']);
        }
        unset($request);

        return $requests;
    }

    /** Resolves a private reference photo only after confirming the request belongs to the customer. */
    public function getRequestImagePathForUser(int $user_id, int $product_request_id): string
    {
        $image_path = $this->db->fetchValue(
            'SELECT product_request_image_path FROM product_requests
             WHERE product_request_id = :request_id AND user_id = :user_id
               AND product_request_status <> :cancelled',
            [
                'request_id' => $product_request_id,
                'user_id' => $user_id,
                'cancelled' => self::STATUS_CANCELLED,
            ]
        );
        $private_directory = realpath(BASE_PATH . '/media/product-requests');
        $full_path = is_string($image_path) && str_starts_with($image_path, 'media/product-requests/')
            ? realpath(BASE_PATH . '/' . $image_path)
            : false;

        if ($private_directory === false || $full_path === false
            || !str_starts_with($full_path, $private_directory . DIRECTORY_SEPARATOR)
            || !is_file($full_path)) {
            throw ApiException::notFound('Picha hii haipatikani.');
        }

        return $full_path;
    }

    /** Matches a customer request to a product and sends an alert immediately if it is in stock. */
    public function matchRequest(int $product_request_id, int $product_id, int $admin_id): void
    {
        $request = $this->db->fetchOne(
            'SELECT product_request_id, product_request_status FROM product_requests
             WHERE product_request_id = :id AND product_request_status IN (:pending, :matched)',
            ['id' => $product_request_id, 'pending' => self::STATUS_PENDING, 'matched' => self::STATUS_MATCHED]
        );
        if ($request === null) {
            throw ApiException::notFound('Ombi halipo au limeshakamilika.');
        }
        $product = $this->db->fetchOne(
            'SELECT product_id, product_stock_quantity, product_is_active, deleted_at
             FROM products WHERE product_id = :product_id',
            ['product_id' => $product_id]
        );
        if ($product === null || !(bool) $product['product_is_active'] || $product['deleted_at'] !== null) {
            throw ApiException::validation(['matched_product_id' => 'Chagua bidhaa inayopatikana dukani.']);
        }

        $this->db->transaction(function () use ($product_request_id, $product_id, $product, $admin_id): void {
            $this->db->execute(
                'UPDATE product_requests SET matched_product_id = :product_id, product_request_status = :matched
                 WHERE product_request_id = :request_id AND product_request_status IN (:pending, :matched_filter)',
                [
                    'product_id' => $product_id,
                    'matched' => self::STATUS_MATCHED,
                    'request_id' => $product_request_id,
                    'pending' => self::STATUS_PENDING,
                    'matched_filter' => self::STATUS_MATCHED,
                ]
            );
            (new AuditLog($this->db))->record('admin', $admin_id, 'product_request.matched', 'product_request', $product_request_id,
                null, ['matched_product_id' => $product_id]);
            if ((int) $product['product_stock_quantity'] > 0) {
                $this->notifyMatchingRequests($product_id);
            }
        });
    }

    /** Notifies matched requests once the mapped product is actually available. */
    public function notifyMatchingRequests(int $product_id): int
    {
        $product = $this->db->fetchOne(
            'SELECT product_name FROM products
             WHERE product_id = :product_id AND product_stock_quantity > 0 AND product_is_active = 1 AND deleted_at IS NULL',
            ['product_id' => $product_id]
        );
        if ($product === null) {
            return 0;
        }
        $product_name = (string) $product['product_name'];
        $rows = $this->db->fetchAll(
            'SELECT product_request_id, user_id FROM product_requests
             WHERE matched_product_id = :product_id AND product_request_status = :matched AND notified_at IS NULL
             ORDER BY product_request_id ASC',
            ['product_id' => $product_id, 'matched' => self::STATUS_MATCHED]
        );
        $notifications = new Notification($this->db);
        foreach ($rows as $row) {
            $request_id = (int) $row['product_request_id'];
            $notifications->notifyUser(
                (int) $row['user_id'],
                'product_request',
                'Ombi lako limepatikana',
                $product_name . ' sasa inapatikana dukani.',
                ['product_id' => $product_id, 'product_request_id' => $request_id],
                ['en' => [
                    'title' => 'Your requested product is available',
                    'body' => $product_name . ' is now available in the shop.',
                ]]
            );
            $this->db->execute(
                'UPDATE product_requests SET product_request_status = :notified, notified_at = UTC_TIMESTAMP()
                 WHERE product_request_id = :id AND notified_at IS NULL',
                ['id' => $request_id, 'notified' => self::STATUS_NOTIFIED]
            );
        }
        return count($rows);
    }

    /** Lets a customer withdraw a request that has not already been notified. */
    public function cancelRequest(int $user_id, int $product_request_id): void
    {
        $image_path = $this->db->fetchValue(
            'SELECT product_request_image_path FROM product_requests
             WHERE product_request_id = :request_id AND user_id = :user_id
               AND product_request_status IN (:pending, :matched)',
            [
                'request_id' => $product_request_id,
                'user_id' => $user_id,
                'pending' => self::STATUS_PENDING,
                'matched' => self::STATUS_MATCHED,
            ]
        );
        $this->db->execute(
            'UPDATE product_requests SET product_request_status = :cancelled
             WHERE product_request_id = :request_id AND user_id = :user_id
               AND product_request_status IN (:pending, :matched)',
            [
                'cancelled' => self::STATUS_CANCELLED,
                'request_id' => $product_request_id,
                'user_id' => $user_id,
                'pending' => self::STATUS_PENDING,
                'matched' => self::STATUS_MATCHED,
            ]
        );
        if (is_string($image_path) && str_starts_with($image_path, 'media/product-requests/')) {
            $base_path = preg_replace('/-large\.webp$/', '', $image_path);
            if (is_string($base_path)) {
                (new ImageUploader())->deleteImageFiles([
                    $base_path . '-thumb.webp',
                    $base_path . '-medium.webp',
                    $base_path . '-large.webp',
                ]);
            }
        }
    }
}
