<?php

/**
 * Time-limited offers ("Ofa"): a percentage off every price tier of a product, from a start to an end time.
 *
 * - The offer starts and ends by itself; "running" = starts_at ≤ now < ends_at (compared by the database in UTC).
 * - One offer per product at a time: a new one may not overlap an offer that is running or still to come.
 * - The prices themselves are worked out by Pricing (the only place a price is chosen); this class only says
 *   which percentage applies. If an offer ends during checkout, the PRICE_CHANGED check stops the order.
 *
 * How to use it:
 *   $offer_model = new ProductOffer(Database::instance());
 *   $offer_model->getRunningPercents([1, 7]);                 // [1 => 15]  (products without an offer are left out)
 *   $offer_model->createOffer($_POST, $admin_id);             // admin; times typed in East Africa time
 */
class ProductOffer
{
    public const MAX_PERCENT   = 90;
    public const MAX_DAYS      = 90;
    public const STATUSES      = ['running', 'scheduled', 'ended'];
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    private const DEFAULT_PER_PAGE = 25;

    // SQL for "this offer row is running now" (offer table alias: offer)
    public const RUNNING_SQL = 'offer.product_offer_starts_at <= UTC_TIMESTAMP() AND offer.product_offer_ends_at > UTC_TIMESTAMP()';

    public function __construct(private Database $db)
    {
    }

    /** The running offer percentage of each product that has one: [product_id => percent]. */
    public function getRunningPercents(array $product_ids): array
    {
        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        if ($product_ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT offer.product_id, offer.product_offer_percent FROM product_offers offer
             WHERE offer.product_id IN (' . implode(', ', $product_ids) . ') AND ' . self::RUNNING_SQL
        ); // the ids were converted to whole numbers above, so they are safe in the SQL

        return array_map('intval', array_column($rows, 'product_offer_percent', 'product_id'));
    }

    // ------------------------------------------------------------------ admin

    /**
     * The admin "Offers" list. Filters (all optional): status (running / scheduled / ended), product_id, page,
     * per_page (25/50/100). Running first, then scheduled (soonest first), then ended (latest first).
     * Times are UTC (show with localDateTime()).
     */
    public function getOffersForAdmin(array $filters): array
    {
        $data = Validator::validate($filters, [
            'status'     => 'nullable|in:' . implode(',', self::STATUSES),
            'product_id' => 'nullable|int|min:1',
            'page'       => 'nullable|int|min:1',
            'per_page'   => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
        ]);
        $per_page = $data['per_page'] ?? self::DEFAULT_PER_PAGE;

        $status_sql = "CASE WHEN offer.product_offer_ends_at <= UTC_TIMESTAMP() THEN 'ended'
                            WHEN offer.product_offer_starts_at > UTC_TIMESTAMP() THEN 'scheduled'
                            ELSE 'running' END";
        $conditions = ['1 = 1'];
        $params     = [];
        if (isset($data['status'])) {
            $conditions[]     = "{$status_sql} = :status";
            $params['status'] = $data['status'];
        }
        if (isset($data['product_id'])) {
            $conditions[]         = 'offer.product_id = :product_id';
            $params['product_id'] = $data['product_id'];
        }
        $where_sql = implode(' AND ', $conditions);

        $total     = (int) $this->db->fetchValue("SELECT COUNT(*) FROM product_offers offer WHERE {$where_sql}", $params);
        $last_page = max(1, (int) ceil($total / $per_page));
        $page      = min($data['page'] ?? 1, $last_page);

        $items = $this->db->fetchAll(
            "SELECT offer.*, {$status_sql} AS product_offer_status, p.product_name, p.product_price, p.product_price_from,
                    admins.admin_full_name AS created_by_admin_name
             FROM product_offers offer
             JOIN products p ON p.product_id = offer.product_id
             LEFT JOIN admins ON admins.admin_id = offer.created_by_admin_id
             WHERE {$where_sql}
             ORDER BY FIELD({$status_sql}, 'running', 'scheduled', 'ended'),
                      IF({$status_sql} = 'ended', NULL, offer.product_offer_starts_at),
                      offer.product_offer_ends_at DESC
             LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page),
            $params
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** One offer for the edit form, with its status. 404 if missing. */
    public function getOfferById(int $product_offer_id): array
    {
        $offer = $this->db->fetchOne(
            "SELECT offer.*, p.product_name,
                    CASE WHEN offer.product_offer_ends_at <= UTC_TIMESTAMP() THEN 'ended'
                         WHEN offer.product_offer_starts_at > UTC_TIMESTAMP() THEN 'scheduled'
                         ELSE 'running' END AS product_offer_status
             FROM product_offers offer JOIN products p ON p.product_id = offer.product_id
             WHERE offer.product_offer_id = :product_offer_id",
            ['product_offer_id' => $product_offer_id]
        );
        if ($offer === null) {
            throw ApiException::notFound('Offer not found.');
        }
        return $offer;
    }

    /**
     * New offer. Fields: product_id, product_offer_percent (1–90), product_offer_starts_at, product_offer_ends_at
     * (East Africa time, as typed in <input type="datetime-local">; an empty start = now). Returns the new id.
     */
    public function createOffer(array $input, int $admin_id): int
    {
        $values = $this->checkForm($input, null);

        $product_offer_id = $this->db->insert(
            'INSERT INTO product_offers (product_id, product_offer_percent, product_offer_starts_at, product_offer_ends_at, created_by_admin_id)
             VALUES (:product_id, :product_offer_percent, :product_offer_starts_at, :product_offer_ends_at, :admin_id)',
            $values + ['admin_id' => $admin_id]
        );
        (new AuditLog($this->db))->record('admin', $admin_id, 'product_offer.created', 'product_offer', $product_offer_id, null, $values);
        return $product_offer_id;
    }

    /** Changes an offer that is running or scheduled (same fields; the product can't change). Ended offers stay as they were. */
    public function updateOffer(int $product_offer_id, array $input, int $admin_id): void
    {
        $old = $this->getOfferById($product_offer_id);
        if ($old['product_offer_status'] === 'ended') {
            throw ApiException::conflict('OFFER_ENDED', 'This offer has ended and can no longer be changed. Create a new one.');
        }
        $values = $this->checkForm(['product_id' => $old['product_id']] + $input, $product_offer_id);

        $this->db->execute(
            'UPDATE product_offers
             SET product_offer_percent = :product_offer_percent, product_offer_starts_at = :product_offer_starts_at,
                 product_offer_ends_at = :product_offer_ends_at
             WHERE product_offer_id = :product_offer_id',
            array_diff_key($values, ['product_id' => true]) + ['product_offer_id' => $product_offer_id]
        );
        (new AuditLog($this->db))->record('admin', $admin_id, 'product_offer.updated', 'product_offer', $product_offer_id,
            array_intersect_key($old, $values), $values);
    }

    /** "End now": a running offer stops at once; a scheduled one is cancelled (it never starts). */
    public function endOffer(int $product_offer_id, int $admin_id): void
    {
        $old = $this->getOfferById($product_offer_id);
        if ($old['product_offer_status'] === 'ended') {
            return;
        }

        $this->db->execute(
            'UPDATE product_offers
             SET product_offer_ends_at = UTC_TIMESTAMP(),
                 product_offer_starts_at = LEAST(product_offer_starts_at, UTC_TIMESTAMP())
             WHERE product_offer_id = :product_offer_id',
            ['product_offer_id' => $product_offer_id]
        );
        (new AuditLog($this->db))->record('admin', $admin_id, 'product_offer.ended', 'product_offer', $product_offer_id,
            ['product_offer_ends_at' => $old['product_offer_ends_at']], ['product_offer_ends_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** Validates the form; times converted to UTC. $own_offer_id = the offer being edited (left out of the overlap check). */
    private function checkForm(array $input, ?int $own_offer_id): array
    {
        $data = Validator::validate($input, [
            'product_id'              => 'required|int|min:1',
            'product_offer_percent'   => 'required|int|min:1|max:' . self::MAX_PERCENT,
            'product_offer_starts_at' => 'nullable|datetime',
            'product_offer_ends_at'   => 'required|datetime',
        ]);

        $is_in_shop = $this->db->fetchValue(
            'SELECT 1 FROM products WHERE product_id = :product_id AND deleted_at IS NULL AND product_price IS NOT NULL',
            ['product_id' => $data['product_id']]
        );
        if (!$is_in_shop) {
            throw ApiException::validation(['product_id' => 'Choose a product that has prices.']);
        }

        $now       = gmdate('Y-m-d H:i:s');
        $starts_at = isset($data['product_offer_starts_at']) ? localTimeToUtc($data['product_offer_starts_at']) : $now;
        $ends_at   = localTimeToUtc($data['product_offer_ends_at']);

        $errors = [];
        if ($ends_at <= $now) {
            $errors['product_offer_ends_at'] = 'The end must be in the future.';
        } elseif ($ends_at <= $starts_at) {
            $errors['product_offer_ends_at'] = 'The end must be after the start.';
        } elseif (strtotime($ends_at) - strtotime($starts_at) > self::MAX_DAYS * 86400) {
            $errors['product_offer_ends_at'] = 'An offer can last at most ' . self::MAX_DAYS . ' days.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        // Another offer of the same product that is running or to come, overlapping these times?
        $overlapping = $this->db->fetchValue(
            'SELECT 1 FROM product_offers
             WHERE product_id = :product_id AND product_offer_id <> :own_offer_id
               AND product_offer_starts_at < :ends_at AND product_offer_ends_at > :starts_at',
            ['product_id' => $data['product_id'], 'own_offer_id' => $own_offer_id ?? 0, 'ends_at' => $ends_at, 'starts_at' => $starts_at]
        );
        if ($overlapping) {
            throw ApiException::validation(['product_offer_starts_at' => 'This product already has an offer during these times.']);
        }

        return [
            'product_id'              => $data['product_id'],
            'product_offer_percent'   => $data['product_offer_percent'],
            'product_offer_starts_at' => $starts_at,
            'product_offer_ends_at'   => $ends_at,
        ];
    }
}
