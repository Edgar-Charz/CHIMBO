<?php

/**
 * Suppliers whose products CHIMBO sells ("Muuzaji Aliyethibitishwa" when verified).
 * In v1 staff manage sellers; sellers do not log in themselves.
 *
 * Staff: getAllSellersForAdmin(), getSellerForAdmin(), getActiveSellers(), createSeller(), updateSeller()
 */
class Seller
{
    private const RULES = [
        'seller_name'        => 'required|string|min:2|max:120',
        'seller_description' => 'nullable|string|max:2000',
        'seller_phone'       => 'nullable|phone_tz',
        'seller_is_verified' => 'nullable|bool',           // unticked checkbox = not verified
        'seller_status'      => 'required|in:active,inactive',
    ];

    public function __construct(private Database $db)
    {
    }

    /** Every seller with how many products they have. */
    public function getAllSellersForAdmin(): array
    {
        return $this->db->fetchAll(
            'SELECT s.seller_id, s.seller_name, s.seller_slug, s.seller_phone, s.seller_is_verified, s.seller_status, s.created_at,
                    (SELECT COUNT(*) FROM products p WHERE p.seller_id = s.seller_id AND p.deleted_at IS NULL) AS product_count
             FROM sellers s
             ORDER BY s.seller_name'
        );
    }

    /** Active sellers — for the "seller" dropdown on the product form. */
    public function getActiveSellers(): array
    {
        return $this->db->fetchAll("SELECT seller_id, seller_name FROM sellers WHERE seller_status = 'active' ORDER BY seller_name");
    }

    public function getSellerForAdmin(int $seller_id): array
    {
        $seller = $this->db->fetchOne('SELECT * FROM sellers WHERE seller_id = :seller_id', ['seller_id' => $seller_id]);
        if ($seller === null) {
            throw ApiException::notFound('Seller not found.');
        }
        return $seller;
    }

    /** Returns the new seller_id. */
    public function createSeller(array $input, int $admin_id): int
    {
        $data = Validator::validate($input, self::RULES);

        $seller_id = $this->db->insert(
            'INSERT INTO sellers (seller_name, seller_slug, seller_description, seller_phone, seller_is_verified, seller_status)
             VALUES (:seller_name, :seller_slug, :seller_description, :seller_phone, :seller_is_verified, :seller_status)',
            $this->columnValues($data, null)
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'seller.created', 'seller', $seller_id, null, $data);
        return $seller_id;
    }

    /** Making a seller inactive hides all their products from the shop. */
    public function updateSeller(int $seller_id, array $input, int $admin_id): void
    {
        $old_seller = $this->getSellerForAdmin($seller_id);
        $data = Validator::validate($input, self::RULES);

        $this->db->execute(
            'UPDATE sellers
             SET seller_name = :seller_name, seller_slug = :seller_slug, seller_description = :seller_description,
                 seller_phone = :seller_phone, seller_is_verified = :seller_is_verified, seller_status = :seller_status
             WHERE seller_id = :seller_id',
            $this->columnValues($data, $seller_id) + ['seller_id' => $seller_id]
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'seller.updated', 'seller', $seller_id, $old_seller, $data);
    }

    private function columnValues(array $data, ?int $seller_id): array
    {
        return [
            'seller_name'        => $data['seller_name'],
            'seller_slug'        => Slug::unique($this->db, $data['seller_name'], 'sellers', 'seller_slug', 'seller_id', $seller_id),
            'seller_description' => $data['seller_description'] ?? null,
            'seller_phone'       => $data['seller_phone'] ?? null,
            'seller_is_verified' => (int) ($data['seller_is_verified'] ?? false),
            'seller_status'      => $data['seller_status'],
        ];
    }


    /** The "Verified" button: changes only seller_is_verified. Returns false when nothing changed. */
    public function setSellerVerified(int $seller_id, bool $is_verified, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('sellers', 'seller_id', $seller_id, 'seller_is_verified', (int) $is_verified, $admin_id, 'seller');
    }

    /** "Activate / Deactivate" ($seller_status 'active' or 'inactive' — inactive hides their products). */
    public function setSellerStatus(int $seller_id, string $seller_status, int $admin_id): bool
    {
        if (!in_array($seller_status, ['active', 'inactive'], true)) {
            throw ApiException::validation(['seller_status' => 'Choose active or inactive.']);
        }
        return (new RecordSwitch($this->db))->set('sellers', 'seller_id', $seller_id, 'seller_status', $seller_status, $admin_id, 'seller');
    }
}
