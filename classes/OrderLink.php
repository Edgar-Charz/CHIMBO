<?php

/** Signed, revocable QR links for packing slips. Public pages receive only the order summary fields they need. */
class OrderLink
{
    private const ORDER_NUMBER_PATTERN = '/\A(?:CHS|CHM|CHB)(?:[0-9]{6,9}|[0-9]{4}-[0-9]{4})\z/';
    private const SIGNATURE_BYTES = 16;
    private const SIGNATURE_PATTERN = '[A-Za-z0-9_-]{22}';
    private const APP_KEY_MIN_LENGTH = 32;
    public const PUBLIC_REQUESTS_PER_HOUR = 120;
    public const PUBLIC_RATE_WINDOW_SECONDS = 3600;

    public function __construct(private Database $db)
    {
    }

    /** Builds the stable signed URL printed in the packing-slip QR, or null when staff revoked it. */
    public function createUrl(string $order_number): ?string
    {
        if (!preg_match(self::ORDER_NUMBER_PATTERN, $order_number)) {
            throw new InvalidArgumentException('Cannot create a QR link for an invalid order number.');
        }

        $link = $this->linkStateForOrderNumber($order_number);
        if ($link === null) {
            throw new InvalidArgumentException('Cannot create a QR link for an unknown order.');
        }
        if ($link['order_link_revoked_at'] !== null) {
            return null;
        }

        $base_url = rtrim(Env::required('APP_URL'), '/');
        $parts = parse_url($base_url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL.');
        }

        $signature = $this->signature($order_number, (int) $link['order_id'], (int) $link['order_link_version']);
        return $base_url . '/order_link.php?' . http_build_query(['code' => $order_number . '-' . $signature]);
    }

    /** Internal reference plus the safe fields used by the public order page; null means invalid, revoked or unknown. */
    public function findOrderForCode(string $code): ?array
    {
        $link = $this->verifiedLink($code);
        if ($link === null) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT o.order_id, o.user_id, o.order_number, o.order_status
             FROM orders o
             WHERE o.order_id = :order_id',
            ['order_id' => $link['order_id']]
        );
    }

    /** Product names and quantities from the order snapshot, with no prices or customer information. */
    public function getPublicItems(string $code): ?array
    {
        $link = $this->verifiedLink($code);
        if ($link === null) {
            return null;
        }

        return $this->db->fetchAll(
            'SELECT oi.order_item_product_name, oi.order_item_quantity, oi.order_item_unit_label
             FROM order_items oi
             WHERE oi.order_id = :order_id
             ORDER BY oi.order_item_id',
            ['order_id' => $link['order_id']]
        );
    }

    /** Current QR state for the order-details page. */
    public function getAdminStatus(int $order_id): array
    {
        $this->ensureLinkState($order_id);
        $state = $this->db->fetchOne(
            'SELECT order_link_version, order_link_revoked_at
             FROM order_public_links WHERE order_id = :order_id',
            ['order_id' => $order_id]
        );
        if ($state === null) {
            throw new RuntimeException('Could not read the order QR-link state.');
        }

        return [
            'is_active' => $state['order_link_revoked_at'] === null,
            'version' => (int) $state['order_link_version'],
            'revoked_at' => $state['order_link_revoked_at'],
        ];
    }

    /** Revokes every copy of the current QR link. */
    public function revoke(int $order_id): void
    {
        $this->ensureLinkState($order_id);
        $this->db->execute(
            'UPDATE order_public_links
             SET order_link_revoked_at = UTC_TIMESTAMP()
             WHERE order_id = :order_id AND order_link_revoked_at IS NULL',
            ['order_id' => $order_id]
        );
    }

    /** Creates a new signed token version; every previously printed link remains revoked. */
    public function issueReplacement(int $order_id): void
    {
        $this->ensureLinkState($order_id);
        $this->db->execute(
            'UPDATE order_public_links
             SET order_link_version = order_link_version + 1, order_link_revoked_at = NULL
             WHERE order_id = :order_id',
            ['order_id' => $order_id]
        );
    }

    /** Look up the active version before returning any order data. */
    private function verifiedLink(string $code): ?array
    {
        if (!preg_match('/\A((?:CHS|CHM|CHB)(?:[0-9]{6,9}|[0-9]{4}-[0-9]{4}))-(' . self::SIGNATURE_PATTERN . ')\z/', $code, $matches)) {
            return null;
        }

        $link = $this->linkStateForOrderNumber($matches[1]);
        if ($link === null || $link['order_link_revoked_at'] !== null) {
            return null;
        }

        $expected_signature = $this->signature($matches[1], (int) $link['order_id'], (int) $link['order_link_version']);
        return hash_equals($expected_signature, $matches[2]) ? $link : null;
    }

    private function linkStateForOrderNumber(string $order_number): ?array
    {
        $order_id = $this->db->fetchValue(
            'SELECT order_id FROM orders WHERE order_number = :order_number',
            ['order_number' => $order_number]
        );
        if ($order_id === null) {
            return null;
        }

        $this->ensureLinkState((int) $order_id);
        return $this->db->fetchOne(
            'SELECT o.order_id, l.order_link_version, l.order_link_revoked_at
             FROM orders o
             INNER JOIN order_public_links l ON l.order_id = o.order_id
             WHERE o.order_id = :order_id',
            ['order_id' => (int) $order_id]
        );
    }

    private function ensureLinkState(int $order_id): void
    {
        if ($this->db->fetchValue('SELECT 1 FROM orders WHERE order_id = :order_id', ['order_id' => $order_id]) === null) {
            throw new InvalidArgumentException('Order does not exist.');
        }
        $this->db->execute(
            'INSERT IGNORE INTO order_public_links (order_id, order_link_version)
             VALUES (:order_id, 0)',
            ['order_id' => $order_id]
        );
    }

    private function signature(string $order_number, int $order_id, int $version): string
    {
        $app_key = Env::required('APP_KEY');
        if (strlen($app_key) < self::APP_KEY_MIN_LENGTH) {
            throw new RuntimeException('APP_KEY must contain at least 32 characters to sign order links.');
        }

        // Version 0 matches existing printed QR codes; later versions make replacement links distinct and revocable.
        $payload = $version === 0
            ? $order_number
            : 'order-qr:v1:' . $order_id . ':' . $order_number . ':' . $version;
        $signature_bytes = substr(hash_hmac('sha256', $payload, $app_key, true), 0, self::SIGNATURE_BYTES);
        return rtrim(strtr(base64_encode($signature_bytes), '+/', '-_'), '=');
    }
}
