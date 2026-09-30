<?php

/**
 * Customer accounts as CHIMBO staff see them: the admin list (search, filters, pages) and the detail page.
 * The customer's own profile actions (register, edit, delete) live in the User class.
 *
 * How to use it:
 *   $customer_model = new Customer(Database::instance());
 *   $result   = $customer_model->searchCustomers($_GET);        // ['items', 'total', 'page', 'per_page']
 *   $customer = $customer_model->getCustomerById($user_id);    // null when it does not exist
 */
class Customer
{
    public const STATUSES = ['active', 'suspended', 'deleted'];

    public const VERIFICATION_STATUSES = ['unverified', 'pending', 'verified', 'rejected'];

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    private const DEFAULT_PER_PAGE = 25;

    private const ORDER_PICKER_LIMIT = 10;   // customers shown in the "find a customer" list

    // Columns the list may be sorted by: sort key => SQL column
    private const SORT_COLUMNS = [
        'user_full_name'               => 'users.user_full_name',
        'business_name'                => 'business_profiles.business_name',
        'region_name'                  => 'regions.region_name',
        'business_verification_status' => 'business_profiles.business_verification_status',
        'user_status'                  => 'users.user_status',
        'created_at'                   => 'users.created_at',
        'user_last_login_at'           => 'users.user_last_login_at',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * One page of customers (newest first unless sorted otherwise).
     * Input (all optional): search (name, phone or shop), user_status, business_verification_status, region_id,
     * page, per_page (10/25/50/100), sort (a SORT_COLUMNS key) + direction (asc/desc).
     * Throws ApiException (422) when a value is not allowed.
     */
    public function searchCustomers(array $filters): array
    {
        $data = Validator::validate($filters, [
            'search'                       => 'nullable|string|max:100',
            'user_status'                  => 'nullable|in:' . implode(',', self::STATUSES),
            'business_verification_status' => 'nullable|in:' . implode(',', self::VERIFICATION_STATUSES),
            'region_id'                    => 'nullable|int|min:1',
            'page'                         => 'nullable|int|min:1',
            'per_page'                     => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
            'sort'                         => 'nullable|in:' . implode(',', array_keys(self::SORT_COLUMNS)),
            'direction'                    => 'nullable|in:asc,desc',
        ]);
        $per_page = $data['per_page'] ?? self::DEFAULT_PER_PAGE;
        // Safe to put in the SQL: both parts come from the fixed lists checked above, never from raw input
        $order_by = self::SORT_COLUMNS[$data['sort'] ?? 'created_at'] . ' ' . strtoupper($data['direction'] ?? 'desc');

        [$where_sql, $params] = $this->buildFilterConditions($data);

        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*)
             FROM users
             LEFT JOIN business_profiles ON business_profiles.user_id = users.user_id
             WHERE {$where_sql}",
            $params
        );

        // A page number past the end (old link, fewer results after filtering) shows the last page
        $last_page = max(1, (int) ceil($total / $per_page));
        $page      = min($data['page'] ?? 1, $last_page);

        $rows = $this->db->fetchAll(
            "SELECT users.user_id, users.user_phone, users.user_full_name, users.user_status,
                    users.user_last_login_at, users.created_at,
                    business_profiles.business_name, business_profiles.business_verification_status,
                    regions.region_name
             FROM users
             LEFT JOIN business_profiles ON business_profiles.user_id = users.user_id
             LEFT JOIN regions           ON regions.region_id = business_profiles.region_id
             WHERE {$where_sql}
             ORDER BY {$order_by}, users.user_id DESC
             LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page),
            $params
        );

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** Everything the detail page shows about one customer, or null when the id does not exist. */
    public function getCustomerById(int $user_id): ?array
    {
        return $this->db->fetchOne(
            'SELECT users.user_id, users.user_phone, users.user_full_name, users.user_email, users.user_locale,
                    users.user_status, users.user_phone_verified_at, users.user_last_login_at,
                    users.created_at, users.updated_at, users.deleted_at,
                    business_profiles.business_name, business_profiles.business_verification_status,
                    business_profiles.business_verified_at,
                    regions.region_name, districts.district_name,
                    admins.admin_full_name AS verified_by_admin_name,
                    (SELECT COUNT(*) FROM auth_tokens
                      WHERE auth_tokens.user_id = users.user_id
                        AND auth_tokens.auth_token_revoked_at IS NULL
                        AND auth_tokens.auth_token_expires_at > UTC_TIMESTAMP()) AS active_app_sessions
             FROM users
             LEFT JOIN business_profiles ON business_profiles.user_id = users.user_id
             LEFT JOIN regions           ON regions.region_id = business_profiles.region_id
             LEFT JOIN districts         ON districts.district_id = business_profiles.district_id
             LEFT JOIN admins            ON admins.admin_id = business_profiles.business_verified_by_admin_id
             WHERE users.user_id = :user_id',
            ['user_id' => $user_id]
        );
    }

    /**
     * Up to 10 active customers matching a name, shop or phone (any format), each with their saved addresses
     * — for the customer picker when staff take an order by phone. Fewer than 2 characters finds nothing.
     */
    public function findCustomersForOrder(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }

        [$search_condition, $params] = $this->buildSearchCondition(mb_substr($search, 0, 100));
        $customers = $this->db->fetchAll(
            "SELECT users.user_id, users.user_full_name, users.user_phone, business_profiles.business_name
             FROM users
             LEFT JOIN business_profiles ON business_profiles.user_id = users.user_id
             WHERE users.user_status = 'active' AND {$search_condition}
             ORDER BY users.user_full_name, users.user_id DESC
             LIMIT " . self::ORDER_PICKER_LIMIT,
            $params
        );

        $address_model = new Address($this->db);
        return array_map(fn (array $customer): array => [
            'user_id'        => (int) $customer['user_id'],
            'user_full_name' => $customer['user_full_name'],
            'user_phone'     => $customer['user_phone'],
            'business_name'  => $customer['business_name'],
            'addresses'      => $address_model->getAddresses((int) $customer['user_id']),
        ], $customers);
    }

    /** Regions that have at least one customer, for the region filter. */
    public function getRegionsWithCustomers(): array
    {
        return $this->db->fetchAll(
            'SELECT regions.region_id, regions.region_name, COUNT(*) AS customer_count
             FROM business_profiles
             JOIN regions ON regions.region_id = business_profiles.region_id
             GROUP BY regions.region_id, regions.region_name
             ORDER BY regions.region_name'
        );
    }

    /** Turns the validated filters into a WHERE clause + its values. */
    private function buildFilterConditions(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (!empty($filters['search'])) {
            [$search_condition, $search_params] = $this->buildSearchCondition($filters['search']);
            $conditions[] = $search_condition;
            $params      += $search_params;
        }
        if (!empty($filters['user_status'])) {
            $conditions[]          = 'users.user_status = :user_status';
            $params['user_status'] = $filters['user_status'];
        }
        if (!empty($filters['business_verification_status'])) {
            $conditions[] = 'business_profiles.business_verification_status = :business_verification_status';
            $params['business_verification_status'] = $filters['business_verification_status'];
        }
        if (!empty($filters['region_id'])) {
            $conditions[]        = 'business_profiles.region_id = :region_id';
            $params['region_id'] = $filters['region_id'];
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Matches the name or shop name, and — when the text contains digits — the phone number
     * in any format the admin types (0712…, 712…, +255 712…).
     */
    private function buildSearchCondition(string $search): array
    {
        // % and _ are wildcards in LIKE; escape them so they are searched as normal characters
        $like_text = '%' . addcslashes($search, '%_\\') . '%';

        $condition = 'users.user_full_name LIKE :search_name OR business_profiles.business_name LIKE :search_business';
        $params    = ['search_name' => $like_text, 'search_business' => $like_text];

        $phone_digits = ltrim((string) preg_replace('/\D/', '', $search), '0');
        if (strlen($phone_digits) >= 3) {
            $condition .= ' OR users.user_phone LIKE :search_phone';
            $params['search_phone'] = '%' . $phone_digits . '%';
        }

        return ["({$condition})", $params];
    }
}
