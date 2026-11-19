<?php

/**
 * "Nijulishe ikipatikana" — customers subscribe to be pushed when an out-of-stock
 * product comes back in stock, and admin triggers the push from the product form.
 *
 * Usage:
 *   $sn = new StockNotification(Database::instance());
 *
 *   // Customer taps the button:
 *   $sn->subscribe($user_id, $product_id);       // idempotent
 *   $sn->unsubscribe($user_id, $product_id);
 *   $sn->isSubscribed($user_id, $product_id);    // bool
 *
 *   // Admin restocks a product — call from ProductEditor or admin AJAX:
 *   $sn->notifySubscribers($product_id, $product_name);
 */
class StockNotification
{
    private const MAX_SUBSCRIPTIONS_PER_USER = 100;

    public function __construct(private Database $db)
    {
    }

    // ── Customer-facing ──────────────────────────────────────────────────────

    /**
     * Subscribes the customer. Subscribing twice is a no-op (INSERT IGNORE).
     * Throws ApiException::notFound when the product is not in the shop.
     */
    public function subscribe(int $user_id, int $product_id): void
    {
        // Must be a real, active, out-of-stock product.
        $product = $this->db->fetchOne(
            'SELECT product_stock_quantity FROM products
              WHERE product_id = :product_id AND product_is_active = 1 AND deleted_at IS NULL',
            ['product_id' => $product_id]
        );
        if ($product === null) {
            throw ApiException::notFound('Bidhaa haikupatikana.');
        }
        if ((int) $product['product_stock_quantity'] > 0) {
            throw ApiException::validation(['product_id' => 'Bidhaa hii bado iko dukani.']);
        }

        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM stock_notifications WHERE user_id = :user_id AND notified_at IS NULL',
            ['user_id' => $user_id]
        );
        if ($count >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            throw ApiException::validation(['product_id' => 'Umejisajili kwa bidhaa nyingi mno.']);
        }

        $this->db->execute(
            'INSERT IGNORE INTO stock_notifications (user_id, product_id) VALUES (:user_id, :product_id)',
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
    }

    /** Removes a subscription silently (no error if not subscribed). */
    public function unsubscribe(int $user_id, int $product_id): void
    {
        $this->db->execute(
            'DELETE FROM stock_notifications WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
    }

    /** True when the customer is waiting for this product. */
    public function isSubscribed(int $user_id, int $product_id): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM stock_notifications WHERE user_id = :user_id AND product_id = :product_id AND notified_at IS NULL',
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
    }

    // ── Admin-facing ─────────────────────────────────────────────────────────

    /**
     * Fires FCM pushes to every waiting subscriber, then marks them as notified.
     * Safe to call multiple times — already-notified rows are skipped.
     * Returns the number of pushes sent.
     */
    public function notifySubscribers(int $product_id, string $product_name): int
    {
        $rows = $this->db->fetchAll(
            'SELECT sn.stock_notification_id, sn.user_id
               FROM stock_notifications sn
              WHERE sn.product_id = :product_id AND sn.notified_at IS NULL',
            ['product_id' => $product_id]
        );

        if (empty($rows)) {
            return 0;
        }

        $fcm    = new FcmPushSender();
        $tokens = new DeviceToken($this->db);
        $sent   = 0;

        foreach ($rows as $row) {
            $user_id = (int) $row['user_id'];
            $sn_id   = (int) $row['stock_notification_id'];

            $devices = $tokens->forUser($user_id);
            foreach ($devices as $device) {
                $language = $device['device_language'] ?? 'sw';
                [$title, $body] = $language === 'en'
                    ? ['Back in stock!', "$product_name is now available in the CHIMBO app."]
                    : ['Ipo dukani!', "$product_name inapatikana tena kwenye CHIMBO."];

                try {
                    $delivered = $fcm->send(
                        $device['device_token'],
                        $title,
                        $body,
                        ['type' => 'stock_back', 'product_id' => (string) $product_id]
                    );
                    if (!$delivered) {
                        $tokens->removeById((int) $device['device_token_id']);
                    } else {
                        $sent++;
                    }
                } catch (Throwable) {
                    // One bad token or network hiccup should not stop the rest.
                }
            }

            // Mark notified regardless of push success (avoid re-spamming on retry).
            $this->db->execute(
                'UPDATE stock_notifications SET notified_at = UTC_TIMESTAMP() WHERE stock_notification_id = :id',
                ['id' => $sn_id]
            );
        }

        return $sent;
    }

    /** Pending subscriber count for the admin product form badge. */
    public function pendingCount(int $product_id): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM stock_notifications WHERE product_id = :product_id AND notified_at IS NULL',
            ['product_id' => $product_id]
        );
    }
}
