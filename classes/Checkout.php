<?php

/**
 * Checkout: the delivery and payment choices, and the totals before the order is placed ("Hakiki").
 * calculateTotals() is the ONE place the order total is worked out — order creation will use it too,
 * so the total the customer sees is exactly the total they are charged.
 */
class Checkout
{
    // Every payment method the apps know. Which ones are switched on is the "enabled_payment_methods" setting.
    private const ALL_PAYMENT_METHODS = ['mpesa', 'airtel_money', 'mixx', 'bank', 'cod'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Delivery methods (optionally only those available for one of the customer's addresses)
     * and the payment methods that are switched on.
     */
    public function getOptions(int $user_id, array $input): array
    {
        $data = Validator::validate($input, ['address_id' => 'nullable|int|min:1']);
        $region_id = isset($data['address_id'])
            ? (new Address($this->db))->getAddress($user_id, $data['address_id'])['region_id']
            : null;

        return [
            'delivery_methods' => $this->getDeliveryMethods($region_id),
            'payment_methods'  => $this->getEnabledPaymentMethods(),
        ];
    }

    /**
     * "Hakiki": totals for the current cart with the chosen delivery method.
     * Body: {"delivery_method_id": 1, "address_id": 3 (optional, checks the method reaches that region)}
     */
    public function preview(int $user_id, array $input): array
    {
        $data = Validator::validate($input, [
            'delivery_method_id' => 'required|int|min:1',
            'address_id'         => 'nullable|int|min:1',
        ]);

        $totals = $this->calculateTotals($user_id, $data['delivery_method_id'], $data['address_id'] ?? null);
        unset($totals['cart']);
        return $totals;
    }

    /**
     * The order total, from the server's own data only: the cart priced from the tiers + the delivery fee.
     * Throws 409 when the cart is empty or has a line that can't be ordered (stock/MOQ changed),
     * and 422 when the delivery method isn't available.
     */
    public function calculateTotals(int $user_id, int $delivery_method_id, ?int $address_id): array
    {
        $cart = (new Cart($this->db))->getCart($user_id);

        if ($cart['summary']['line_count'] === 0) {
            throw ApiException::conflict('CART_EMPTY', 'Kikapu chako hakina bidhaa.');
        }
        if (!$cart['summary']['can_checkout']) {
            throw ApiException::conflict('CART_HAS_PROBLEMS', 'Baadhi ya bidhaa kwenye kikapu zina tatizo la idadi. Tafadhali rekebisha kikapu kwanza.');
        }

        $region_id = $address_id !== null ? (new Address($this->db))->getAddress($user_id, $address_id)['region_id'] : null;
        $delivery_method = $this->requireDeliveryMethod($delivery_method_id, $region_id);

        $subtotal       = $cart['summary']['subtotal'];
        $delivery_fee   = $delivery_method['delivery_method_fee'];
        $discount_total = 0; // no cart-level discounts ("Punguzo") in the MVP (decision D-7)

        return [
            'line_count'         => $cart['summary']['line_count'],
            'piece_count'        => $cart['summary']['piece_count'],
            'subtotal'           => $subtotal,
            'savings'            => $cart['summary']['savings'],
            'delivery_method_id' => $delivery_method['delivery_method_id'],
            'delivery_fee'       => $delivery_fee,
            'discount_total'     => $discount_total,
            'grand_total'        => $subtotal + $delivery_fee - $discount_total,
            'delivery_days_min'  => $delivery_method['delivery_days_min'],
            'delivery_days_max'  => $delivery_method['delivery_days_max'],
            'cart'               => $cart, // for order creation; preview() removes it
        ];
    }

    /** Active delivery methods; with a region, only those that reach it (NULL region = everywhere). */
    public function getDeliveryMethods(?int $region_id = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT delivery_method_id, delivery_method_code, delivery_method_name, delivery_method_fee,
                    delivery_method_eta_min_days, delivery_method_eta_max_days, region_id
             FROM delivery_methods
             WHERE delivery_method_is_active = 1
               AND (:region_id IS NULL OR region_id IS NULL OR region_id = :same_region_id)
             ORDER BY delivery_method_sort_order, delivery_method_id',
            ['region_id' => $region_id, 'same_region_id' => $region_id]
        );

        return array_map(fn (array $row) => [
            'delivery_method_id'   => (int) $row['delivery_method_id'],
            'delivery_method_code' => $row['delivery_method_code'],
            'delivery_method_name' => $row['delivery_method_name'],
            'delivery_method_fee'  => (int) $row['delivery_method_fee'],
            'delivery_days_min'    => (int) $row['delivery_method_eta_min_days'],
            'delivery_days_max'    => (int) $row['delivery_method_eta_max_days'],
            'region_id'            => $row['region_id'] === null ? null : (int) $row['region_id'], // null = every region
        ], $rows);
    }

    /** Payment methods switched on in the "enabled_payment_methods" setting (comma-separated). */
    public function getEnabledPaymentMethods(): array
    {
        $enabled = array_map('trim', explode(',', (string) (new Settings($this->db))->get('enabled_payment_methods', 'cod')));
        return array_values(array_intersect(self::ALL_PAYMENT_METHODS, $enabled));
    }

    private function requireDeliveryMethod(int $delivery_method_id, ?int $region_id): array
    {
        foreach ($this->getDeliveryMethods() as $delivery_method) {
            if ($delivery_method['delivery_method_id'] !== $delivery_method_id) {
                continue;
            }
            $reaches_region = $region_id === null || $delivery_method['region_id'] === null || $delivery_method['region_id'] === $region_id;
            if (!$reaches_region) {
                throw ApiException::validation(['delivery_method_id' => "{$delivery_method['delivery_method_name']} haipatikani kwa anwani hii."]);
            }
            return $delivery_method;
        }
        throw ApiException::validation(['delivery_method_id' => 'Chagua njia ya usafirishaji.']);
    }
}
