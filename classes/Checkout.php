<?php

/**
 * Checkout: the delivery and payment choices, and the totals before the order is placed ("Hakiki").
 * calculateTotals() is the ONE place the order total is worked out — order creation will use it too,
 * so the total the customer sees is exactly the total they are charged.
 */
class Checkout
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Delivery methods (optionally only those available for one of the customer's addresses)
     * and the payment methods that are switched on: "payment_methods" = their codes,
     * "payment_method_details" = name, type and "pay to" details for each.
     */
    public function getOptions(int $user_id, array $input): array
    {
        $data = Validator::validate($input, ['address_id' => 'nullable|int|min:1']);
        $region_id = isset($data['address_id'])
            ? (new Address($this->db))->getAddress($user_id, $data['address_id'])['region_id']
            : null;

        return [
            'delivery_methods' => $this->getDeliveryMethods($region_id),
            'payment_methods'        => $this->getEnabledPaymentMethods(),
            'payment_method_details' => (new PaymentMethod($this->db))->getActiveMethods(),
            'pickup_point'           => (new Settings($this->db))->getPickupPoint(),   // for "Nitachukua mwenyewe dukani"
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
        $delivery_fee   = $delivery_method['delivery_method_fee'] ?? 0;   // a region without an estimate: 0 until staff confirm
        $discount_total = 0; // no cart-level discounts ("Punguzo") in the MVP (decision D-7)

        return [
            'line_count'         => $cart['summary']['line_count'],
            'piece_count'        => $cart['summary']['piece_count'],
            'subtotal'           => $subtotal,
            'savings'            => $cart['summary']['savings'],
            'delivery_method_id' => $delivery_method['delivery_method_id'],
            'delivery_fee'       => $delivery_fee,
            // true: the fee is the region's estimate; staff confirm the final price after measuring the load (R-25)
            'delivery_fee_needs_confirmation' => $delivery_method['delivery_fee_needs_confirmation'],
            // true: the customer pays the transport to the rider (Bolt in Dar), not to CHIMBO — delivery_fee is 0 (R-26)
            'delivery_fee_paid_to_rider' => $delivery_method['delivery_fee_paid_to_rider'],
            'delivery_method_is_pickup'  => $delivery_method['delivery_method_is_pickup'],
            'discount_total'     => $discount_total,
            'grand_total'        => $subtotal + $delivery_fee - $discount_total,
            'delivery_days_min'  => $delivery_method['delivery_days_min'],
            'delivery_days_max'  => $delivery_method['delivery_days_max'],
            'cart'               => $cart, // for order creation; preview() removes it
        ];
    }

    /**
     * Active delivery methods. With a region (the customer's address), only those that reach it — an option is for
     * every region, only one region, or every region except one — and an estimate-priced option ("Usafiri wa
     * mikoani") carries that region's estimate as its fee (null when the region has none: "tutakujulisha bei").
     * Without a region every active option is listed and estimate-priced ones have no fee yet.
     * An option paid to the rider (Bolt in Dar) has fee 0 for CHIMBO: the customer pays the rider directly (R-26).
     */
    public function getDeliveryMethods(?int $region_id = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.delivery_method_id, m.delivery_method_code, m.delivery_method_name, m.delivery_method_fee,
                    m.delivery_method_pricing, m.delivery_method_is_pickup, m.delivery_method_eta_min_days,
                    m.delivery_method_eta_max_days, m.region_id, m.delivery_method_area, r.region_transport_estimate
             FROM delivery_methods m
             LEFT JOIN regions r ON r.region_id = :estimate_region_id
             WHERE m.delivery_method_is_active = 1
               AND (:region_id IS NULL
                    OR m.delivery_method_area = 'all'
                    OR (m.delivery_method_area = 'only'   AND m.region_id = :only_region_id)
                    OR (m.delivery_method_area = 'except' AND m.region_id <> :except_region_id))
             ORDER BY m.delivery_method_sort_order, m.delivery_method_id",
            ['estimate_region_id' => $region_id, 'region_id' => $region_id, 'only_region_id' => $region_id, 'except_region_id' => $region_id]
        );

        return array_map(function (array $row): array {
            $fee = match ($row['delivery_method_pricing']) {
                'paid_to_rider'   => 0,
                'region_estimate' => $row['region_transport_estimate'] === null ? null : (int) $row['region_transport_estimate'],
                default           => (int) $row['delivery_method_fee'],
            };
            return [
                'delivery_method_id'   => (int) $row['delivery_method_id'],
                'delivery_method_code' => $row['delivery_method_code'],
                'delivery_method_name' => $row['delivery_method_name'],
                'delivery_method_fee'  => $fee,   // null: an estimate-priced option without a known estimate yet
                'delivery_fee_needs_confirmation' => $row['delivery_method_pricing'] === 'region_estimate',
                'delivery_fee_paid_to_rider'      => $row['delivery_method_pricing'] === 'paid_to_rider',   // "Unamlipa dereva ukipokea"
                'delivery_method_is_pickup'       => (bool) $row['delivery_method_is_pickup'],             // collect at the shop
                'delivery_days_min'    => (int) $row['delivery_method_eta_min_days'],
                'delivery_days_max'    => (int) $row['delivery_method_eta_max_days'],
                'delivery_method_area' => $row['delivery_method_area'],   // all | only | except (with region_id)
                'region_id'            => $row['region_id'] === null ? null : (int) $row['region_id'],
            ];
        }, $rows);
    }

    /** The codes of the payment methods staff switched on (admin → Payments → Payment methods). */
    public function getEnabledPaymentMethods(): array
    {
        return (new PaymentMethod($this->db))->getActiveCodes();
    }

    /** The chosen delivery method for this region (its fee filled in), or a clear validation error. */
    private function requireDeliveryMethod(int $delivery_method_id, ?int $region_id): array
    {
        $for_region = array_column($this->getDeliveryMethods($region_id), null, 'delivery_method_id');
        if (isset($for_region[$delivery_method_id])) {
            $delivery_method = $for_region[$delivery_method_id];
            // An estimate-priced option needs the address: the estimate depends on the region
            if ($delivery_method['delivery_fee_needs_confirmation'] && $region_id === null) {
                throw ApiException::validation(['address_id' => 'Chagua anwani ya kupokelea mzigo kwanza.']);
            }
            return $delivery_method;
        }

        $any_region = array_column($this->getDeliveryMethods(), null, 'delivery_method_id');
        throw ApiException::validation(['delivery_method_id' => isset($any_region[$delivery_method_id])
            ? "{$any_region[$delivery_method_id]['delivery_method_name']} haipatikani kwa anwani hii."
            : 'Chagua njia ya usafirishaji.']);
    }
}
