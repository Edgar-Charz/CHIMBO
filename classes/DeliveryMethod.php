<?php

/**
 * Delivery options and their fees as staff manage them (admin "Settings" → Delivery).
 * The customer's checkout reads the active ones through Checkout::getDeliveryMethods().
 *
 * How to use it:
 *   $delivery_method_model = new DeliveryMethod(Database::instance());
 *   $methods = $delivery_method_model->listForAdmin();
 *   $delivery_method_model->updateMethod($delivery_method_id, $_POST, $admin_id);
 */
class DeliveryMethod
{
    private const RULES = [
        'delivery_method_name'         => 'required|string|min:2|max:80',
        'delivery_method_fee'          => 'required|int|min:0|max:1000000',
        'delivery_method_eta_min_days' => 'required|int|min:0|max:60',
        'delivery_method_eta_max_days' => 'required|int|min:0|max:60',
        'region_id'                    => 'nullable|int|min:1',   // empty = every region
        'delivery_method_is_active'    => 'nullable|bool',        // checkbox
        'delivery_method_sort_order'   => 'nullable|int|min:0|max:1000',
    ];

    public function __construct(private Database $db)
    {
    }

    /** Every delivery option (active and hidden) with the region name ("All regions" when empty). */
    public function listForAdmin(): array
    {
        return $this->db->fetchAll(
            'SELECT m.delivery_method_id, m.delivery_method_code, m.delivery_method_name, m.delivery_method_fee,
                    m.delivery_method_eta_min_days, m.delivery_method_eta_max_days, m.region_id, r.region_name,
                    m.delivery_method_is_active, m.delivery_method_sort_order
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
            'INSERT INTO delivery_methods (delivery_method_code, delivery_method_name, delivery_method_fee, delivery_method_eta_min_days,
                                           delivery_method_eta_max_days, region_id, delivery_method_is_active, delivery_method_sort_order)
             VALUES (:delivery_method_code, :delivery_method_name, :delivery_method_fee, :delivery_method_eta_min_days,
                     :delivery_method_eta_max_days, :region_id, :delivery_method_is_active, :delivery_method_sort_order)',
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
                 delivery_method_eta_min_days = :delivery_method_eta_min_days, delivery_method_eta_max_days = :delivery_method_eta_max_days,
                 region_id = :region_id, delivery_method_is_active = :delivery_method_is_active,
                 delivery_method_sort_order = :delivery_method_sort_order
             WHERE delivery_method_id = :delivery_method_id',
            $values + ['delivery_method_id' => $delivery_method_id]
        );
        (new AuditLog($this->db))->record('admin', $admin_id, 'delivery_method.updated', 'delivery_method', $delivery_method_id,
            array_intersect_key($old, $values), $values);
    }

    /** Validates the form and returns the column values. */
    private function checkForm(array $input): array
    {
        $data = Validator::validate($input, self::RULES);

        if ($data['delivery_method_eta_min_days'] > $data['delivery_method_eta_max_days']) {
            throw ApiException::validation(['delivery_method_eta_max_days' => 'The latest day must be on or after the earliest day.']);
        }
        if (isset($data['region_id'])) {
            (new Region($this->db))->checkLocation($data['region_id'], null);
        }

        return [
            'delivery_method_name'         => $data['delivery_method_name'],
            'delivery_method_fee'          => $data['delivery_method_fee'],
            'delivery_method_eta_min_days' => $data['delivery_method_eta_min_days'],
            'delivery_method_eta_max_days' => $data['delivery_method_eta_max_days'],
            'region_id'                    => $data['region_id'] ?? null,
            'delivery_method_is_active'    => (int) ($data['delivery_method_is_active'] ?? false),
            'delivery_method_sort_order'   => $data['delivery_method_sort_order'] ?? 0,
        ];
    }


    /** "Offer at checkout / Stop offering": changes only delivery_method_is_active. Returns false when nothing changed. */
    public function setDeliveryMethodActive(int $delivery_method_id, bool $is_active, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('delivery_methods', 'delivery_method_id', $delivery_method_id, 'delivery_method_is_active', (int) $is_active, $admin_id, 'delivery_method');
    }
}
