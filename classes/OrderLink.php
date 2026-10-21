<?php

/** Signed QR links for packing slips. Public pages receive only the order summary fields they need. */
class OrderLink
{
    private const ORDER_NUMBER_PATTERN = '/\ACHB[0-9]{6}\z/';
    private const SIGNATURE_BYTES = 16;
    private const SIGNATURE_PATTERN = '[A-Za-z0-9_-]{22}';
    private const APP_KEY_MIN_LENGTH = 32;
    public const PUBLIC_REQUESTS_PER_HOUR = 120;
    public const PUBLIC_RATE_WINDOW_SECONDS = 3600;

    public function __construct(private Database $db)
    {
    }

    /** Builds the permanent, signed URL printed in the packing-slip QR. */
    public function createUrl(string $order_number): string
    {
        if (!preg_match(self::ORDER_NUMBER_PATTERN, $order_number)) {
            throw new InvalidArgumentException('Cannot create a QR link for an invalid order number.');
        }

        $base_url = rtrim(Env::required('APP_URL'), '/');
        $parts = parse_url($base_url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL.');
        }

        return $base_url . '/o/' . $order_number . '-' . $this->signature($order_number);
    }

    /** Internal reference plus the safe fields used by the public order page; null means invalid or unknown. */
    public function findOrderForCode(string $code): ?array
    {
        $order_number = $this->verifiedOrderNumber($code);
        if ($order_number === null) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT o.order_id, o.user_id, o.order_number, o.order_status
             FROM orders o
             WHERE o.order_number = :order_number',
            ['order_number' => $order_number]
        );
    }

    /** Product names and quantities from the order snapshot, with no prices or customer information. */
    public function getPublicItems(string $code): ?array
    {
        $order_number = $this->verifiedOrderNumber($code);
        if ($order_number === null) {
            return null;
        }

        return $this->db->fetchAll(
            'SELECT oi.order_item_product_name, oi.order_item_quantity, oi.order_item_unit_label
             FROM order_items oi
             INNER JOIN orders o ON o.order_id = oi.order_id
             WHERE o.order_number = :order_number
             ORDER BY oi.order_item_id',
            ['order_number' => $order_number]
        );
    }

    /** The signed portion is checked before querying, so invalid links never reveal whether an order exists. */
    private function verifiedOrderNumber(string $code): ?string
    {
        if (!preg_match('/\A(CHB[0-9]{6})-(' . self::SIGNATURE_PATTERN . ')\z/', $code, $matches)) {
            return null;
        }

        $expected_signature = $this->signature($matches[1]);
        return hash_equals($expected_signature, $matches[2]) ? $matches[1] : null;
    }

    private function signature(string $order_number): string
    {
        $app_key = Env::required('APP_KEY');
        if (strlen($app_key) < self::APP_KEY_MIN_LENGTH) {
            throw new RuntimeException('APP_KEY must contain at least 32 characters to sign order links.');
        }

        $signature_bytes = substr(hash_hmac('sha256', $order_number, $app_key, true), 0, self::SIGNATURE_BYTES);
        return rtrim(strtr(base64_encode($signature_bytes), '+/', '-_'), '=');
    }
}
