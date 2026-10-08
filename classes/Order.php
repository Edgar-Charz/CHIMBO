<?php

/**
 * Customer orders ("Oda"): place, list, detail with the tracking timeline, cancel, reorder.
 * The staff side (moving orders through the statuses) is in OrderManager; both use moveToStatus().
 *
 * Statuses: pending_payment → confirmed → packed → dispatched → in_transit → delivered
 *           (+ cancelled, expired). Cash on delivery starts at "confirmed".
 */
class Order
{
    public const ACTIVE_STATUSES      = ['pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit'];
    public const CANCELLABLE_STATUSES = ['pending_payment', 'confirmed'];   // customers can cancel until packing starts
    private const PER_PAGE            = 20;

    // What the customer is told when the order reaches each status: [title, body with {number}]
    private const STATUS_MESSAGES = [
        'pending_payment' => ['Oda imepokelewa', 'Oda #{number} inasubiri malipo.'],
        'confirmed'       => ['Oda imethibitishwa', 'Asante! Oda #{number} imepokelewa na tunaiandaa.'],
        'packed'          => ['Oda imepakiwa', 'Oda #{number} imepakiwa na itatumwa hivi karibuni.'],
        'dispatched'      => ['Oda imetumwa', 'Oda #{number} imekabidhiwa kwa msambazaji.'],
        'in_transit'      => ['Oda iko njiani', 'Oda #{number} inakuja kwako.'],
        'delivered'       => ['Oda imewasili', 'Oda #{number} imefika. Asante kwa kununua na CHIMBO!'],
        'cancelled'       => ['Oda imesitishwa', 'Oda #{number} imesitishwa.'],
        'expired'         => ['Oda imeisha muda', 'Oda #{number} imesitishwa kwa sababu malipo hayakukamilika.'],
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * "Thibitisha Oda": turns the cart into an order, in ONE transaction:
     * lock the products → recalculate the total → refuse if it differs from what the customer saw →
     * copy prices and address into the order → reserve the stock → empty the cart → notify.
     * If anything fails, nothing is saved.
     *
     * Body: {"address_id", "delivery_method_id", "payment_method", "expected_total", "order_customer_note"}
     * $idempotency_key (the "Idempotency-Key" header): sending the same order twice (a double tap, a retried
     * request) returns the first order instead of creating a second one.
     */
    public function placeOrder(int $user_id, array $input, string $channel, ?string $idempotency_key): array
    {
        $data = Validator::validate($input, [
            'address_id'          => 'required|int|min:1',
            'delivery_method_id'  => 'required|int|min:1',
            'payment_method'      => 'required|in:mpesa,airtel_money,mixx,bank,cod',
            'expected_total'      => 'required|int|min:0',
            'order_customer_note' => 'nullable|string|max:500',
        ]);
        $checkout = new Checkout($this->db);
        if (!in_array($data['payment_method'], $checkout->getEnabledPaymentMethods(), true)) {
            throw ApiException::validation(['payment_method' => 'Njia hii ya malipo haipatikani kwa sasa.']);
        }
        if ($idempotency_key !== null && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idempotency_key)) {
            throw ApiException::badRequest('INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key si sahihi.');
        }

        $existing_order_id = $this->findOrderIdByIdempotencyKey($user_id, $idempotency_key);
        if ($existing_order_id !== null) {
            return $this->getOrder($user_id, $existing_order_id);
        }

        try {
            $order_id = $this->db->transaction(fn () => $this->createOrderFromCart($user_id, $data, $channel, $idempotency_key));
        } catch (PDOException $e) {
            // The same key arrived twice at the same moment: the other request created the order
            $existing_order_id = $this->findOrderIdByIdempotencyKey($user_id, $idempotency_key);
            if ($existing_order_id === null) {
                throw $e;
            }
            $order_id = $existing_order_id;
        }

        return $this->getOrder($user_id, $order_id);
    }

    /** "Oda Zangu": one page of orders, newest first. group = active ("Zinazoendelea") | delivered ("Zimefika") | all. */
    public function getOrders(int $user_id, array $input): array
    {
        $data   = Validator::validate($input, ['group' => 'nullable|in:active,delivered,all', 'page' => 'nullable|int|min:1']);
        $page   = $data['page'] ?? 1;
        $offset = ($page - 1) * self::PER_PAGE;

        $group_condition = match ($data['group'] ?? 'all') {
            'active'    => "o.order_status IN ('" . implode("', '", self::ACTIVE_STATUSES) . "')",
            'delivered' => "o.order_status = 'delivered'",
            default     => '1 = 1',
        };

        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM orders o WHERE o.user_id = :user_id AND {$group_condition}",
            ['user_id' => $user_id]
        );
        $rows = $this->db->fetchAll(
            $this->orderSelectSql() . " WHERE o.user_id = :user_id AND {$group_condition}
             ORDER BY o.order_placed_at DESC, o.order_id DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            ['user_id' => $user_id]
        );

        return ['items' => $this->formatOrders($rows), 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE];
    }

    /** One order with items, the tracking timeline and the delivery agent. 404 if it isn't this customer's. */
    public function getOrder(int $user_id, int $order_id): array
    {
        $row = $this->db->fetchOne(
            $this->orderSelectSql() . ' WHERE o.order_id = :order_id AND o.user_id = :user_id',
            ['order_id' => $order_id, 'user_id' => $user_id]
        );
        if ($row === null) {
            throw ApiException::notFound('Oda haikupatikana.');
        }
        return $this->formatOrders([$row])[0];
    }

    /** Customer cancels (only before packing). The reserved stock goes back. */
    public function cancelOrder(int $user_id, int $order_id, array $input): array
    {
        $data = Validator::validate($input, ['order_cancel_reason' => 'nullable|string|max:255']);

        $this->db->transaction(function () use ($user_id, $order_id, $data) {
            $order = $this->lockOrder($order_id, $user_id);
            if (!in_array($order['order_status'], self::CANCELLABLE_STATUSES, true)) {
                throw ApiException::conflict('ORDER_NOT_CANCELLABLE', 'Oda hii haiwezi kusitishwa tena kwa sababu imeshaanza kuandaliwa.');
            }
            $this->moveToStatus($order, 'cancelled', 'customer', $user_id, $data['order_cancel_reason'] ?? null);
        });

        return $this->getOrder($user_id, $order_id);
    }

    /** "Agiza Tena": puts the order's products back in the cart (current prices; unavailable ones are listed as skipped). */
    public function reorder(int $user_id, int $order_id): array
    {
        $this->getOrder($user_id, $order_id); // 404 if not theirs

        $items = $this->db->fetchAll(
            'SELECT product_id, order_item_quantity AS quantity FROM order_items WHERE order_id = :order_id',
            ['order_id' => $order_id]
        );

        return (new Cart($this->db))->addItemsFromList($user_id, $items);
    }

    /** Products the customer ordered recently, newest first (Home: "Uliagiza Hivi Karibuni"). */
    public function getRecentlyOrderedProducts(int $user_id, int $limit): array
    {
        $rows = $this->db->fetchAll(
            "SELECT oi.product_id, MAX(o.order_placed_at) AS last_ordered_at
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             WHERE o.user_id = :user_id AND o.order_status NOT IN ('cancelled', 'expired')
             GROUP BY oi.product_id
             ORDER BY last_ordered_at DESC
             LIMIT " . max(1, $limit),
            ['user_id' => $user_id]
        );

        return (new Product($this->db))->getProductCardsByIds(array_column($rows, 'product_id'));
    }

    /**
     * Changes an order's status and does everything that goes with it: timestamps, stock back on
     * cancel/expire, the timeline row and the customer's notification.
     * The caller has checked that the change is allowed and runs this inside a transaction with the order locked.
     */
    public function moveToStatus(array $order, string $new_status, string $actor_type, ?int $actor_id, ?string $note): void
    {
        // A payment the customer sent must be confirmed or rejected first, or money could be lost track of
        if (in_array($new_status, ['cancelled', 'expired'], true) && $order['order_payment_status'] === 'pending') {
            throw ApiException::conflict('PAYMENT_UNDER_REVIEW', $actor_type === 'customer'
                ? 'Malipo yako yanakaguliwa. Wasiliana na msaada ili kusitisha oda hii.'
                : "Confirm or reject the customer's payment (Payments page) before stopping this order.");
        }

        $this->db->execute(
            'UPDATE orders
             SET order_status        = :order_status,
                 order_delivered_at  = IF(:is_delivered, UTC_TIMESTAMP(), order_delivered_at),
                 order_cancelled_at  = IF(:is_stopped, UTC_TIMESTAMP(), order_cancelled_at),
                 order_cancel_reason = IF(:is_stopped_again, :cancel_reason, order_cancel_reason)
             WHERE order_id = :order_id',
            [
                'order_status'     => $new_status,
                'is_delivered'     => (int) ($new_status === 'delivered'),
                'is_stopped'       => (int) in_array($new_status, ['cancelled', 'expired'], true),
                'is_stopped_again' => (int) in_array($new_status, ['cancelled', 'expired'], true),
                'cancel_reason'    => $note,
                'order_id'         => $order['order_id'],
            ]
        );

        if (in_array($new_status, ['cancelled', 'expired'], true)) {
            $this->releaseStock((int) $order['order_id']);

            // Nothing is owed any more for an order that was stopped before it was paid.
            // (A paid order keeps "paid" until the money is refunded and staff mark it "refunded".)
            $this->db->execute(
                "UPDATE orders SET order_payment_status = 'cancelled'
                 WHERE order_id = :order_id AND order_payment_status IN ('unpaid', 'pending', 'cod_pending')",
                ['order_id' => $order['order_id']]
            );
        }

        $this->recordStatus((int) $order['order_id'], $new_status, $actor_type, $actor_id, $note);
        $this->notifyCustomer($order, $new_status);
    }

    /** Locks the order row until the transaction ends (so two status changes can't overlap). */
    public function lockOrder(int $order_id, ?int $user_id = null): array
    {
        $order = $this->db->fetchOne(
            'SELECT order_id, order_number, user_id, order_status, order_payment_method, order_payment_status, order_total
             FROM orders WHERE order_id = :order_id FOR UPDATE',
            ['order_id' => $order_id]
        );
        if ($order === null || ($user_id !== null && (int) $order['user_id'] !== $user_id)) {
            throw ApiException::notFound('Oda haikupatikana.');
        }
        return $order;
    }

    /** The select used for every order read (customer and staff). */
    public function orderSelectSql(): string
    {
        return 'SELECT o.*, a.delivery_agent_full_name, a.delivery_agent_phone, a.delivery_agent_photo_path
                FROM orders o
                LEFT JOIN order_deliveries d ON d.order_id = o.order_id
                LEFT JOIN delivery_agents a  ON a.delivery_agent_id = d.delivery_agent_id';
    }

    /** Order rows → the JSON the apps use, loading items and timelines for all orders in two queries. */
    public function formatOrders(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $order_ids   = implode(', ', array_map(fn (array $row) => (int) $row['order_id'], $rows)); // whole numbers, safe in SQL
        $items_by_id = $this->groupByOrder($this->db->fetchAll("SELECT * FROM order_items WHERE order_id IN ({$order_ids}) ORDER BY order_item_id"));
        $events_by_id = $this->groupByOrder($this->db->fetchAll(
            "SELECT order_id, order_status, status_note, created_at FROM order_status_history
             WHERE order_id IN ({$order_ids}) ORDER BY status_history_id"
        ));
        $payments_by_id = (new Payment($this->db))->getLatestPaymentsForOrders(array_column($rows, 'order_id'));

        return array_map(fn (array $row) => $this->formatOrder(
            $row,
            $items_by_id[$row['order_id']] ?? [],
            $events_by_id[$row['order_id']] ?? [],
            $payments_by_id[$row['order_id']] ?? null
        ), $rows);
    }

    // ------------------------------------------------------------------ private

    /** The work inside placeOrder()'s transaction. Returns the new order_id. */
    private function createOrderFromCart(int $user_id, array $data, string $channel, ?string $idempotency_key): int
    {
        $this->lockCartProducts($user_id);

        // The same calculation as the "Hakiki" preview — so the customer pays exactly what they saw
        $totals = (new Checkout($this->db))->calculateTotals($user_id, $data['delivery_method_id'], $data['address_id']);
        if ($totals['grand_total'] !== $data['expected_total']) {
            throw ApiException::conflict('PRICE_CHANGED', 'Bei imebadilika. Jumla mpya ni TZS ' . number_format($totals['grand_total']) . '. Tafadhali hakiki tena.');
        }
        $this->checkCashOnDeliveryLimit($data['payment_method'], $totals['grand_total']);

        $address        = (new Address($this->db))->getAddress($user_id, $data['address_id']);
        $is_cash        = $data['payment_method'] === 'cod';
        $status         = $is_cash ? 'confirmed' : 'pending_payment';
        $expiry_minutes = (new Settings($this->db))->getInt('unpaid_order_expiry_minutes', 30);

        $order_id = $this->db->insert(
            'INSERT INTO orders (
                order_number, user_id, order_channel, order_status, order_payment_method, order_payment_status,
                order_subtotal, order_delivery_fee, order_discount_total, order_total,
                delivery_method_id, order_delivery_method_name, order_delivery_days_min, order_delivery_days_max,
                order_estimated_delivery_date,
                order_ship_recipient_name, order_ship_phone, region_id, order_ship_region_name, order_ship_district_name,
                order_ship_street, order_ship_landmark, order_customer_note, order_idempotency_key,
                order_expires_at, order_placed_at
             ) VALUES (
                :order_number, :user_id, :order_channel, :order_status, :order_payment_method, :order_payment_status,
                :order_subtotal, :order_delivery_fee, :order_discount_total, :order_total,
                :delivery_method_id, :delivery_method_name, :delivery_days_min, :delivery_days_max,
                UTC_DATE() + INTERVAL :days_until_delivery DAY,
                :recipient_name, :ship_phone, :region_id, :region_name, :district_name,
                :street, :landmark, :customer_note, :idempotency_key,
                IF(:is_cash, NULL, UTC_TIMESTAMP() + INTERVAL :expiry_minutes MINUTE), UTC_TIMESTAMP()
             )',
            [
                'order_number'         => $this->newOrderNumber(),
                'user_id'              => $user_id,
                'order_channel'        => $channel,
                'order_status'         => $status,
                'order_payment_method' => $data['payment_method'],
                'order_payment_status' => $is_cash ? 'cod_pending' : 'unpaid',
                'order_subtotal'       => $totals['subtotal'],
                'order_delivery_fee'   => $totals['delivery_fee'],
                'order_discount_total' => $totals['discount_total'],
                'order_total'          => $totals['grand_total'],
                'delivery_method_id'   => $totals['delivery_method_id'],
                'delivery_method_name' => $this->deliveryMethodName($totals['delivery_method_id']),
                'delivery_days_min'    => $totals['delivery_days_min'],
                'delivery_days_max'    => $totals['delivery_days_max'],
                'days_until_delivery'  => $totals['delivery_days_max'],
                'recipient_name'       => $address['address_recipient_name'],
                'ship_phone'           => $address['address_phone'],
                'region_id'            => $address['region_id'],
                'region_name'          => $address['region_name'],
                'district_name'        => $address['district_name'],
                'street'               => $address['address_street'],
                'landmark'             => $address['address_landmark'],
                'customer_note'        => $data['order_customer_note'] ?? null,
                'idempotency_key'      => $idempotency_key,
                'is_cash'              => (int) $is_cash,
                'expiry_minutes'       => $expiry_minutes,
            ]
        );

        $this->saveItemsAndReserveStock($order_id, $totals['cart']);
        $this->recordStatus($order_id, $status, 'customer', $user_id, null);
        (new Cart($this->db))->clearCart($user_id);
        $this->notifyCustomer(['order_id' => $order_id, 'user_id' => $user_id, 'order_number' => $this->orderNumberOf($order_id)], $status);

        return $order_id;
    }

    /**
     * Locks the cart's products (in id order, so two orders can't block each other) until the order is saved.
     * Meanwhile nobody else can buy the same last pieces.
     */
    private function lockCartProducts(int $user_id): void
    {
        $this->db->fetchAll(
            'SELECT product_id FROM products
             WHERE product_id IN (SELECT product_id FROM cart_items WHERE user_id = :user_id)
             ORDER BY product_id
             FOR UPDATE',
            ['user_id' => $user_id]
        );
    }

    /** Copies each cart line into order_items (name, price, photo as they are now) and takes the stock. */
    private function saveItemsAndReserveStock(int $order_id, array $cart): void
    {
        $lines       = array_merge([], ...array_column($cart['groups'], 'items'));
        $product_ids = implode(', ', array_map(fn (array $line) => (int) $line['product']['product_id'], $lines));
        $products    = array_column($this->db->fetchAll(
            "SELECT p.product_id, p.seller_id, p.product_sku, p.product_unit_label, image.product_image_thumb_path
             FROM products p
             LEFT JOIN product_images image ON image.product_id = p.product_id AND image.product_image_is_primary = 1
             WHERE p.product_id IN ({$product_ids})"
        ), null, 'product_id');

        $product_editor = new ProductEditor($this->db);
        foreach ($lines as $line) {
            $product_id = (int) $line['product']['product_id'];
            $product    = $products[$product_id];

            $this->db->insert(
                'INSERT INTO order_items (
                    order_id, product_id, seller_id, order_item_product_name, order_item_sku, order_item_image_path,
                    order_item_unit_label, order_item_quantity, order_item_unit_price, order_item_tier_min_quantity,
                    order_item_offer_percent, order_item_line_total
                 ) VALUES (
                    :order_id, :product_id, :seller_id, :product_name, :sku, :image_path,
                    :unit_label, :quantity, :unit_price, :tier_min_quantity, :offer_percent, :line_total
                 )',
                [
                    'order_id'          => $order_id,
                    'product_id'        => $product_id,
                    'seller_id'         => $product['seller_id'],
                    'product_name'      => $line['product']['product_name'],
                    'sku'               => $product['product_sku'],
                    'image_path'        => $product['product_image_thumb_path'],
                    'unit_label'        => $product['product_unit_label'],
                    'quantity'          => $line['cart_quantity'],
                    'unit_price'        => $line['unit_price'],
                    'tier_min_quantity' => $line['tier_min_quantity'],
                    'offer_percent'     => $line['offer_percent'],
                    'line_total'        => $line['line_total'],
                ]
            );

            $product_editor->changeStock($product_id, -$line['cart_quantity'], 'order_reserve', null, null, 'order', $order_id);
            $this->db->execute(
                'UPDATE products SET product_sold_count = product_sold_count + :quantity WHERE product_id = :product_id',
                ['quantity' => $line['cart_quantity'], 'product_id' => $product_id]
            );
        }
    }

    /** Cancelled or expired: the pieces go back to stock, and the "sold" counter goes down again. */
    private function releaseStock(int $order_id): void
    {
        $items = $this->db->fetchAll('SELECT product_id, order_item_quantity FROM order_items WHERE order_id = :order_id', ['order_id' => $order_id]);

        $product_editor = new ProductEditor($this->db);
        foreach ($items as $item) {
            $product_editor->changeStock((int) $item['product_id'], (int) $item['order_item_quantity'], 'order_release', null, null, 'order', $order_id);
            $this->db->execute(
                'UPDATE products SET product_sold_count = GREATEST(0, CAST(product_sold_count AS SIGNED) - :quantity) WHERE product_id = :product_id',
                ['quantity' => $item['order_item_quantity'], 'product_id' => $item['product_id']]
            );
        }
    }

    /** Adds a row to the order's timeline ("Fuatilia Oda"). Also used by OrderManager for staff-created orders. */
    public function recordStatus(int $order_id, string $status, string $actor_type, ?int $actor_id, ?string $note): void
    {
        $this->db->insert(
            'INSERT INTO order_status_history (order_id, order_status, status_note, status_actor_type, status_actor_id)
             VALUES (:order_id, :order_status, :status_note, :actor_type, :actor_id)',
            ['order_id' => $order_id, 'order_status' => $status, 'status_note' => $note, 'actor_type' => $actor_type, 'actor_id' => $actor_id]
        );
    }

    /** Tells the customer about the order's new status (the bell). */
    public function notifyCustomer(array $order, string $status): void
    {
        [$title, $body] = self::STATUS_MESSAGES[$status];
        (new Notification($this->db))->notifyUser(
            (int) $order['user_id'],
            'order_status',
            $title,
            str_replace('{number}', $order['order_number'], $body),
            ['order_id' => (int) $order['order_id']]
        );
    }

    /** Refuses cash on delivery above the "cod_max_order_total" setting. */
    public function checkCashOnDeliveryLimit(string $payment_method, int $grand_total): void
    {
        $cash_limit = (new Settings($this->db))->getInt('cod_max_order_total', 300000);
        if ($payment_method === 'cod' && $grand_total > $cash_limit) {
            throw ApiException::validation([
                'payment_method' => 'Lipa ukipokea inaruhusiwa kwa oda hadi TZS ' . number_format($cash_limit) . '. Chagua njia nyingine ya malipo.',
            ]);
        }
    }

    private function findOrderIdByIdempotencyKey(int $user_id, ?string $idempotency_key): ?int
    {
        if ($idempotency_key === null) {
            return null;
        }
        $order_id = $this->db->fetchValue(
            'SELECT order_id FROM orders WHERE user_id = :user_id AND order_idempotency_key = :idempotency_key',
            ['user_id' => $user_id, 'idempotency_key' => $idempotency_key]
        );
        return $order_id === null ? null : (int) $order_id;
    }

    /** A free order number: "CHB" + 6 random digits (not a counter, so it doesn't reveal how many orders exist). */
    public function newOrderNumber(): string
    {
        do {
            $order_number = 'CHB' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($this->db->fetchValue('SELECT 1 FROM orders WHERE order_number = :order_number', ['order_number' => $order_number]));
        return $order_number;
    }

    private function orderNumberOf(int $order_id): string
    {
        return (string) $this->db->fetchValue('SELECT order_number FROM orders WHERE order_id = :order_id', ['order_id' => $order_id]);
    }

    private function deliveryMethodName(int $delivery_method_id): string
    {
        return (string) $this->db->fetchValue(
            'SELECT delivery_method_name FROM delivery_methods WHERE delivery_method_id = :delivery_method_id',
            ['delivery_method_id' => $delivery_method_id]
        );
    }

    private function groupByOrder(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['order_id']][] = $row;
        }
        return $grouped;
    }

    /** One order row + its items + its timeline + its newest payment → JSON (keys follow the column names). */
    private function formatOrder(array $row, array $items, array $events, ?array $latest_payment): array
    {
        $has_agent = $row['delivery_agent_full_name'] !== null;

        return [
            'order_id'                      => (int) $row['order_id'],
            'order_number'                  => $row['order_number'],
            'order_status'                  => $row['order_status'],
            'order_channel'                 => $row['order_channel'],
            'order_payment_method'          => $row['order_payment_method'],
            'order_payment_status'          => $row['order_payment_status'],
            'order_subtotal'                => (int) $row['order_subtotal'],
            'order_delivery_fee'            => (int) $row['order_delivery_fee'],
            'order_discount_total'          => (int) $row['order_discount_total'],
            'order_total'                   => (int) $row['order_total'],
            'order_placed_at'               => isoDate($row['order_placed_at']),
            'order_estimated_delivery_date' => $row['order_estimated_delivery_date'],
            'order_delivered_at'            => isoDate($row['order_delivered_at']),
            'order_cancelled_at'            => isoDate($row['order_cancelled_at']),
            'order_cancel_reason'           => $row['order_cancel_reason'],
            'order_customer_note'           => $row['order_customer_note'],
            'order_expires_at'              => isoDate($row['order_expires_at']),
            'can_cancel'                    => in_array($row['order_status'], self::CANCELLABLE_STATUSES, true) && $row['order_payment_status'] !== 'pending',
            'item_count'                    => count($items),
            'piece_count'                   => array_sum(array_column($items, 'order_item_quantity')),
            'delivery_method'               => [
                'delivery_method_id'   => (int) $row['delivery_method_id'],
                'delivery_method_name' => $row['order_delivery_method_name'],
                'delivery_method_fee'  => (int) $row['order_delivery_fee'],
                'delivery_days_min'    => (int) $row['order_delivery_days_min'],
                'delivery_days_max'    => (int) $row['order_delivery_days_max'],
            ],
            'address'                       => [   // a copy of the address at ordering time
                'address_recipient_name' => $row['order_ship_recipient_name'],
                'address_phone'          => $row['order_ship_phone'],
                'region_id'              => (int) $row['region_id'],
                'region_name'            => $row['order_ship_region_name'],
                'district_name'          => $row['order_ship_district_name'],
                'address_street'         => $row['order_ship_street'],
                'address_landmark'       => $row['order_ship_landmark'],
            ],
            'items'                         => array_map(fn (array $item) => [
                'order_item_id'                => (int) $item['order_item_id'],
                'product_id'                   => (int) $item['product_id'],
                'product_name'                 => $item['order_item_product_name'],
                'product_image_url'            => $item['order_item_image_path'] ? url($item['order_item_image_path']) : null,
                'order_item_unit_label'        => $item['order_item_unit_label'],
                'order_item_quantity'          => (int) $item['order_item_quantity'],
                'order_item_unit_price'        => (int) $item['order_item_unit_price'],
                'order_item_tier_min_quantity' => (int) $item['order_item_tier_min_quantity'],
                'order_item_offer_percent'     => (int) $item['order_item_offer_percent'],   // 0 = no offer
                'order_item_line_total'        => (int) $item['order_item_line_total'],
            ], $items),
            'events'                        => array_map(fn (array $event) => [   // the "Fuatilia Oda" timeline
                'order_status' => $event['order_status'],
                'status_note'  => $event['status_note'],
                'created_at'   => isoDate($event['created_at']),
            ], $events),
            'delivery_agent'                => $has_agent ? [
                'delivery_agent_full_name' => $row['delivery_agent_full_name'],
                'delivery_agent_phone'     => $row['delivery_agent_phone'],
                'delivery_agent_photo_url' => $row['delivery_agent_photo_path'] ? url($row['delivery_agent_photo_path']) : null,
            ] : null,
            'payment'                       => $this->formatPayment($row, $latest_payment),
        ];
    }

    /**
     * The order's payment box: where to pay (the method's "pay to" details), the newest payment the customer
     * sent and its review, and whether the "Nimelipa" form should be shown.
     */
    private function formatPayment(array $row, ?array $latest_payment): array
    {
        $can_submit = $row['order_status'] === 'pending_payment'
            && $row['order_payment_status'] === 'unpaid'
            && $row['order_payment_method'] !== 'cod'
            && ($row['order_expires_at'] === null || $row['order_expires_at'] > gmdate('Y-m-d H:i:s'));

        return [
            'payment_method'     => (new PaymentMethod($this->db))->getDetailsByCode($row['order_payment_method']),
            'payment_amount'     => (int) $row['order_total'],
            'payment_note_hint'  => $row['order_number'],   // ask the customer to write this as the payment's reference/description
            'can_submit_payment' => $can_submit,
            // "Badilisha njia ya malipo": the same moment, when there is another method to switch to
            'can_change_payment_method' => $can_submit && count((new PaymentMethod($this->db))->getActiveCodes()) > 1,
            'latest_payment'     => $latest_payment === null ? null : [
                'payment_id'            => (int) $latest_payment['payment_id'],
                'payment_payer_account' => $latest_payment['payment_payer_account'],
                'payment_reference'     => $latest_payment['payment_reference'],
                'payment_status'        => $latest_payment['payment_status'],        // submitted | confirmed | rejected
                'payment_review_note'   => $latest_payment['payment_review_note'],   // why it was rejected
                'created_at'            => isoDate($latest_payment['created_at']),
            ],
        ];
    }
}
