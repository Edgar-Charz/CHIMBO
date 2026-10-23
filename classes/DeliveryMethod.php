<?php

/**
 * Delivery options and their fees as staff manage them (admin Settings → Delivery), and each region's upcountry
 * transport estimate. The customer's checkout reads the active options through Checkout::getDeliveryMethods().
 *
 * An option has a "where" (every region · only one region · every region except one) and a "pricing": a fixed fee,
 * the region's transport estimate that staff confirm after measuring the load (blueprint R-25, §12.7), or paid by the
 * customer to the rider — Bolt in Dar (R-26, §12.8). A pickup option is collected at the shop.
 *
 * How to use it:
 *   $delivery_method_model = new DeliveryMethod(Database::instance());
 *   $methods = $delivery_method_model->listForAdmin();
 *   $delivery_method_model->updateMethod($delivery_method_id, $_POST, $admin_id);
 *   $delivery_method_model->saveRegionEstimates($_POST['region_transport_estimate'], $admin_id);
 */
class DeliveryMethod
{
    public const AREAS   = ['all' => 'Every region', 'only' => 'Only this region', 'except' => 'Every region except'];
    public const PRICING = [
        'fixed'           => 'Fixed fee',
        'region_estimate' => "Region's estimate, confirmed by staff",
        'paid_to_rider'   => 'Customer pays the rider (not CHIMBO)',
    ];

    private const RULES = [
        'delivery_method_name'         => 'required|string|min:2|max:80',
        'delivery_method_fee'          => 'nullable|int|min:0|max:1000000',   // required for a fixed fee
        'delivery_method_pricing'      => 'nullable|in:fixed,region_estimate,paid_to_rider', // empty = fixed
        'delivery_method_is_pickup'    => 'nullable|bool',                     // checkbox: collected at the shop
        'delivery_method_eta_min_days' => 'required|int|min:0|max:60',
        'delivery_method_eta_max_days' => 'required|int|min:0|max:60',
        'delivery_method_area'         => 'nullable|in:all,only,except',       // empty = "only" with a region, "all" without
        'region_id'                    => 'nullable|int|min:1',
        'delivery_method_is_active'    => 'nullable|bool',                     // checkbox
        'delivery_method_sort_order'   => 'nullable|int|min:0|max:1000',
    ];

    public function __construct(private Database $db)
    {
    }

    /** Every delivery option (active and hidden) with its region's name (null = every region). */
    public function listForAdmin(): array
    {
        return $this->db->fetchAll(
            'SELECT m.delivery_method_id, m.delivery_method_code, m.delivery_method_name, m.delivery_method_fee,
                    m.delivery_method_pricing, m.delivery_method_is_pickup, m.delivery_method_eta_min_days, m.delivery_method_eta_max_days,
                    m.region_id, m.delivery_method_area, r.region_name, m.delivery_method_is_active, m.delivery_method_sort_order
             FROM delivery_methods m
             LEFT JOIN regions r ON r.region_id = m.region_id
             ORDER BY m.delivery_method_sort_order, m.delivery_method_id'
        );
    }

    /** One option for the edit form, or 404. */
    public function getMethodById(int $delivery_method_id): array
    {
        $method = $this->db->fetchOne(
            'SELECT * FROM delivery_methods WHERE delivery_method_id = :delivery_method_id',
            ['delivery_method_id' => $delivery_method_id]
        );
        if ($method === null) {
            throw ApiException::notFound('Delivery option not found.');
        }
        return $method;
    }

    /** A new option. The code (used by the apps) is made from the name, e.g. "Pikipiki Mwanza" → "pikipiki-mwanza". */
    public function createMethod(array $input, int $admin_id): int
    {
        $values = $this->checkForm($input);
        $values['delivery_method_code'] = Slug::unique($this->db, $values['delivery_method_name'], 'delivery_methods', 'delivery_method_code', 'delivery_method_id', null);

        $delivery_method_id = $this->db->insert(
            'INSERT INTO delivery_methods (delivery_method_code, delivery_method_name, delivery_method_fee, delivery_method_pricing,
                                           delivery_method_is_pickup, delivery_method_eta_min_days, delivery_method_eta_max_days, region_id, delivery_method_area,
                                           delivery_method_is_active, delivery_method_sort_order)
             VALUES (:delivery_method_code, :delivery_method_name, :delivery_method_fee, :delivery_method_pricing,
                     :delivery_method_is_pickup, :delivery_method_eta_min_days, :delivery_method_eta_max_days, :region_id, :delivery_method_area,
                     :delivery_method_is_active, :delivery_method_sort_order)',
            $values
        );
        (new AuditLog($this->db))->record('admin', $admin_id, 'delivery_method.created', 'delivery_method', $delivery_method_id, null, $values);
        return $delivery_method_id;
    }

    /** Changes take effect for new checkouts; orders already placed keep the fee they were charged. */
    public function updateMethod(int $delivery_method_id, array $input, int $admin_id): void
    {
        $old    = $this->getMethodById($delivery_method_id);
        $values = $this->checkForm($input);

        $this->db->execute(
            'UPDATE delivery_methods
             SET delivery_method_name = :delivery_method_name, delivery_method_fee = :delivery_method_fee,
                 delivery_method_pricing = :delivery_method_pricing, delivery_method_is_pickup = :delivery_method_is_pickup,
                 delivery_method_eta_min_days = :delivery_method_eta_min_days, delivery_method_eta_max_days = :delivery_method_eta_max_days,
                 region_id = :region_id, delivery_method_area = :delivery_method_area,
                 delivery_method_is_active = :delivery_method_is_active, delivery_method_sort_order = :delivery_method_sort_order
             WHERE delivery_method_id = :delivery_method_id',
            $values + ['delivery_method_id' => $delivery_method_id]
        );
        (new AuditLog($this->db))->record('admin', $admin_id, 'delivery_method.updated', 'delivery_method', $delivery_method_id,
            array_intersect_key($old, $values), $values);
    }

    /** "Offer at checkout / Stop offering": changes only delivery_method_is_active. Returns false when nothing changed. */
    public function setDeliveryMethodActive(int $delivery_method_id, bool $is_active, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('delivery_methods', 'delivery_method_id', $delivery_method_id, 'delivery_method_is_active', (int) $is_active, $admin_id, 'delivery_method');
    }

    /** Every region with its upcountry transport estimate (TZS, null = "tutakujulisha bei"), by name. */
    public function getRegionEstimates(): array
    {
        return $this->db->fetchAll('SELECT region_id, region_name, region_transport_estimate FROM regions ORDER BY region_name');
    }

    /**
     * Saves the estimates form: [region_id => amount or "" (no estimate)]. Only changed regions are written; the
     * audit log keeps old → new. Orders already placed keep the price they were given.
     */
    public function saveRegionEstimates(array $estimates, int $admin_id): void
    {
        $errors     = [];
        $new_values = [];
        foreach ($estimates as $region_id => $amount) {
            $amount = trim((string) $amount);
            $is_grouped_integer = preg_match('/^\d{1,3}(?:,\d{3})+$/D', $amount) === 1;
            if ($amount !== '' && !ctype_digit($amount) && !$is_grouped_integer) {
                $errors["region_transport_estimate[{$region_id}]"] = 'Enter a whole number of shillings, or leave it empty.';
                continue;
            }
            $amount = str_replace(',', '', $amount);
            if ($amount !== '' && (int) $amount > 10_000_000) {
                $errors["region_transport_estimate[{$region_id}]"] = 'Enter a whole number of shillings, or leave it empty.';
                continue;
            }
            $new_values[(int) $region_id] = $amount === '' ? null : (int) $amount;
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $current = array_column($this->getRegionEstimates(), 'region_transport_estimate', 'region_id');
        $changed_old = [];
        $changed_new = [];
        foreach ($new_values as $region_id => $amount) {
            if (!array_key_exists($region_id, $current)) {
                continue;   // not a region
            }
            $old = $current[$region_id] === null ? null : (int) $current[$region_id];
            if ($old !== $amount) {
                $changed_old[$region_id] = $old;
                $changed_new[$region_id] = $amount;
            }
        }
        if ($changed_new === []) {
            return;
        }

        $this->db->transaction(function (Database $db) use ($changed_old, $changed_new, $admin_id) {
            foreach ($changed_new as $region_id => $amount) {
                $db->execute('UPDATE regions SET region_transport_estimate = :amount WHERE region_id = :region_id',
                    ['amount' => $amount, 'region_id' => $region_id]);
            }
            (new AuditLog($db))->record('admin', $admin_id, 'transport_estimates.updated', 'settings', null, $changed_old, $changed_new);
        });
    }

    /** Validates the form and returns the column values. */
    private function checkForm(array $input): array
    {
        $data = Validator::validate($input, self::RULES);

        if ($data['delivery_method_eta_min_days'] > $data['delivery_method_eta_max_days']) {
            throw ApiException::validation(['delivery_method_eta_max_days' => 'The latest day must be on or after the earliest day.']);
        }
        $pricing = $data['delivery_method_pricing'] ?? 'fixed';
        if ($pricing === 'fixed' && ($data['delivery_method_fee'] ?? null) === null) {
            throw ApiException::validation(['delivery_method_fee' => 'Enter the fee.']);
        }
        $region_id = $data['region_id'] ?? null;
        $area      = $data['delivery_method_area'] ?? ($region_id === null ? 'all' : 'only');
        if ($area !== 'all' && $region_id === null) {
            throw ApiException::validation(['region_id' => 'Choose the region.']);
        }
        if ($region_id !== null) {
            (new Region($this->db))->checkLocation($region_id, null);
        }

        return [
            'delivery_method_name'         => $data['delivery_method_name'],
            'delivery_method_fee'          => $pricing === 'fixed' ? $data['delivery_method_fee'] : 0,
            'delivery_method_pricing'      => $pricing,
            'delivery_method_is_pickup'    => (int) ($data['delivery_method_is_pickup'] ?? false),
            'delivery_method_eta_min_days' => $data['delivery_method_eta_min_days'],
            'delivery_method_eta_max_days' => $data['delivery_method_eta_max_days'],
            'region_id'                    => $area === 'all' ? null : $region_id,
            'delivery_method_area'         => $area,
            'delivery_method_is_active'    => (int) ($data['delivery_method_is_active'] ?? false),
            'delivery_method_sort_order'   => $data['delivery_method_sort_order'] ?? 0,
        ];
    }
}
