<?php

/**
 * Staff side of orders (admin pages): list, detail, move through the statuses, assign the delivery agent,
 * confirm cash collected on delivery. The customer side is in Order.
 *
 * Allowed status changes (anything else is refused):
 *   pending_payment → cancelled            (it becomes "confirmed" only when the payment arrives)
 *   confirmed       → packed | cancelled
 *   packed          → dispatched | cancelled   (dispatching needs a delivery agent)
 *   dispatched      → in_transit | delivered
 *   in_transit      → delivered
 */
class OrderManager
{
    public const ALLOWED_TRANSITIONS = [
        'pending_payment' => ['cancelled'],
        'confirmed'       => ['packed', 'cancelled'],
        'packed'          => ['dispatched', 'cancelled'],
        'dispatched'      => ['in_transit', 'delivered'],
        'in_transit'      => ['delivered'],
    ];
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];
    private const DEFAULT_PER_PAGE = 25;

    // Columns the admin order list can be sorted by. Only these fixed strings ever reach ORDER BY.
    private const SORT_COLUMNS = [
        'order_placed_at' => 'o.order_placed_at',
        'order_total'     => 'o.order_total',
        'order_number'    => 'o.order_number',
    ];

    private Order $order_model;

    public function __construct(private Database $db)
    {
        $this->order_model = new Order($db);
    }

    /**
     * One page of orders, newest first. Filters (optional): q (order number, customer name or phone),
     * order_status, order_payment_status, page, per_page (10/25/50/100),
     * sort (order_placed_at | order_total | order_number) + direction (asc | desc, default desc). No sort = newest first.
     */
    public function getOrdersForAdmin(array $input): array
    {
        $filters = Validator::validate($input, [
            'q'                    => 'nullable|string|max:100',
            'order_status'         => 'nullable|in:pending_payment,confirmed,packed,dispatched,in_transit,delivered,cancelled,expired',
            'order_payment_status' => 'nullable|in:unpaid,pending,paid,cod_pending,refunded,failed,cancelled',
            'page'                 => 'nullable|int|min:1',
            'per_page'             => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
            'sort'                 => 'nullable|in:' . implode(',', array_keys(self::SORT_COLUMNS)),
            'direction'            => 'nullable|in:asc,desc',
        ]);
        $page     = $filters['page'] ?? 1;
        $per_page = $filters['per_page'] ?? self::DEFAULT_PER_PAGE;

        $conditions = ['1 = 1'];
        $params     = [];
        if (isset($filters['q'])) {
            $search_text  = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $conditions[] = '(o.order_number LIKE :search_number OR u.user_full_name LIKE :search_name OR u.user_phone LIKE :search_phone)';
            $params += ['search_number' => $search_text, 'search_name' => $search_text, 'search_phone' => $search_text];
        }
        foreach (['order_status', 'order_payment_status'] as $column) {
            if (isset($filters[$column])) {
                $conditions[] = "o.{$column} = :{$column}";  // column names come from the fixed list above
                $params[$column] = $filters[$column];
            }
        }
        $where_sql = implode(' AND ', $conditions);

        // The chosen column, then the order id as a tie-breaker so the order is always the same
        $order_by = isset($filters['sort'])
            ? self::SORT_COLUMNS[$filters['sort']] . ' ' . strtoupper($filters['direction'] ?? 'desc') . ', o.order_id DESC'
            : 'o.order_placed_at DESC, o.order_id DESC';

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM orders o JOIN users u ON u.user_id = o.user_id WHERE {$where_sql}", $params);
        $items = $this->db->fetchAll(
            "SELECT o.order_id, o.order_number, o.order_status, o.order_payment_method, o.order_payment_status,
                    o.order_total, o.order_placed_at, o.order_ship_region_name, o.order_channel,
                    u.user_id, u.user_full_name, u.user_phone,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
             FROM orders o
             JOIN users u ON u.user_id = o.user_id
             WHERE {$where_sql}
             ORDER BY {$order_by}
             LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page),
            $params
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** Data needed by the staff form for an order placed on a customer's behalf. */
    public function getManualOrderOptions(): array
    {
        $products = $this->db->fetchAll(
            "SELECT p.product_id, p.product_name, p.product_sku, p.product_moq, p.product_unit_label,
                    p.product_stock_quantity,
                    (SELECT MAX(t.tier_unit_price) FROM product_price_tiers t WHERE t.product_id = p.product_id) AS product_price
             FROM products p
             JOIN sellers s ON s.seller_id = p.seller_id AND s.seller_status = 'active'
             JOIN categories c ON c.category_id = p.category_id AND c.category_is_active = 1
             WHERE p.product_is_active = 1 AND p.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM product_price_tiers t WHERE t.product_id = p.product_id)
             ORDER BY p.product_name"
        );
        $tiers = $this->db->fetchAll(
            'SELECT t.product_id, t.tier_min_quantity, t.tier_unit_price
             FROM product_price_tiers t ORDER BY t.product_id, t.tier_min_quantity'
        );
        $tiers_by_product = [];
        foreach ($tiers as $tier) {
            $tiers_by_product[$tier['product_id']][] = [
                'tier_min_quantity' => (int) $tier['tier_min_quantity'],
                'tier_unit_price' => (int) $tier['tier_unit_price'],
            ];
        }
        foreach ($products as &$product) {
            $product['price_tiers'] = $tiers_by_product[$product['product_id']] ?? [];
        }
        unset($product);

        return [
            'regions' => (new Region($this->db))->getAllRegions(),
            'districts' => $this->db->fetchAll('SELECT district_id, district_name, region_id FROM districts ORDER BY district_name'),
            'delivery_methods' => (new Checkout($this->db))->getDeliveryMethods(),
            'payment_methods' => (new Checkout($this->db))->getEnabledPaymentMethods(),
            'products' => $products,
        ];
    }

    /** Saves a walk-in customer and their delivery address before staff continue to order items. */
    public function createManualCustomer(array $input, int $admin_id): array
    {
        $data = Validator::validate($input, [
            'user_full_name' => 'required|string|min:2|max:100',
            'user_phone' => 'required|phone_tz',
            'address_recipient_name' => 'required|string|min:2|max:100',
            'address_phone' => 'required|phone_tz',
            'region_id' => 'required|int|min:1',
            'district_id' => 'nullable|int|min:1',
            'address_street' => 'required|string|min:3|max:160',
            'address_landmark' => 'nullable|string|max:160',
        ]);
        $data['user_phone'] = Phone::normalizeTz($data['user_phone']);
        $data['address_phone'] = Phone::normalizeTz($data['address_phone']);
        (new Region($this->db))->checkLocation($data['region_id'], $data['district_id'] ?? null);

        return $this->db->transaction(function () use ($data, $admin_id): array {
            $created = $this->db->execute(
                'INSERT IGNORE INTO users (user_phone, user_full_name) VALUES (:phone, :name)',
                ['phone' => $data['user_phone'], 'name' => $data['user_full_name']]
            ) === 1;
            $user = $this->db->fetchOne('SELECT user_id, user_phone, user_full_name, user_status FROM users WHERE user_phone = :phone FOR UPDATE', ['phone' => $data['user_phone']]);
            if ($user === null || $user['user_status'] !== 'active') {
                throw ApiException::forbidden('This customer account is not active.');
            }
            $user_id = (int) $user['user_id'];
            if ($user['user_full_name'] === null) {
                $this->db->execute('UPDATE users SET user_full_name = :name WHERE user_id = :id', ['name' => $data['user_full_name'], 'id' => $user_id]);
            }

            $address_id = $this->db->fetchValue(
                'SELECT address_id FROM addresses
                 WHERE user_id = :user_id AND address_recipient_name = :recipient AND address_phone = :phone
                   AND region_id = :region_id AND district_id <=> :district_id
                   AND address_street = :street AND address_landmark <=> :landmark
                 ORDER BY address_is_default DESC, address_id DESC LIMIT 1',
                [
                    'user_id' => $user_id, 'recipient' => $data['address_recipient_name'], 'phone' => $data['address_phone'],
                    'region_id' => $data['region_id'], 'district_id' => $data['district_id'] ?? null,
                    'street' => $data['address_street'], 'landmark' => $data['address_landmark'] ?? null,
                ]
            );
            if ($address_id === null) {
                $has_address = (bool) $this->db->fetchValue('SELECT 1 FROM addresses WHERE user_id = :id LIMIT 1', ['id' => $user_id]);
                $address_id = $this->db->insert(
                    'INSERT INTO addresses (user_id, address_recipient_name, address_phone, region_id, district_id,
                        address_street, address_landmark, address_is_default)
                     VALUES (:user_id, :recipient, :phone, :region, :district, :street, :landmark, :is_default)',
                    [
                        'user_id' => $user_id, 'recipient' => $data['address_recipient_name'], 'phone' => $data['address_phone'],
                        'region' => $data['region_id'], 'district' => $data['district_id'] ?? null,
                        'street' => $data['address_street'], 'landmark' => $data['address_landmark'] ?? null,
                        'is_default' => (int) !$has_address,
                    ]
                );
                (new AuditLog($this->db))->record('admin', $admin_id, 'customer.address_added_by_admin', 'user', $user_id, null, ['address_id' => $address_id]);
            }
            if ($created) {
                (new AuditLog($this->db))->record('admin', $admin_id, 'customer.created_by_admin', 'user', $user_id, null, ['user_phone' => $data['user_phone']]);
            }

            $user['user_full_name'] = $user['user_full_name'] ?? $data['user_full_name'];
            return [
                'customer' => [
                    'user_id' => $user_id,
                    'user_full_name' => $user['user_full_name'],
                    'user_phone' => $user['user_phone'],
                    'business_name' => null,
                    'addresses' => (new Address($this->db))->getAddresses($user_id),
                ],
                'address_id' => (int) $address_id,
            ];
        });
    }

    /** Creates an order on behalf of a customer who ordered by phone or in person. */
    public function createManualOrder(array $input, int $admin_id): int
    {
        $data = Validator::validate($input, [
            'user_id'                => 'nullable|int|min:1',
            'address_id'             => 'nullable|int|min:1',
            'user_full_name'       => 'required|string|min:2|max:100',
            'user_phone'           => 'required|phone_tz',
            'address_recipient_name' => 'required|string|min:2|max:100',
            'address_phone'        => 'required|phone_tz',
            'region_id'            => 'required|int|min:1',
            'district_id'          => 'nullable|int|min:1',
            'address_street'       => 'required|string|min:3|max:160',
            'address_landmark'     => 'nullable|string|max:160',
            'delivery_method_id'   => 'required|int|min:1',
            'payment_method'       => 'required|in:mpesa,airtel_money,mixx,bank,cod',
            'order_customer_note'  => 'nullable|string|max:500',
            'items'                => 'required|array',
        ]);
        $data['user_phone'] = Phone::normalizeTz($data['user_phone']);
        $data['address_phone'] = Phone::normalizeTz($data['address_phone']);
        if ($data['user_phone'] === null || $data['address_phone'] === null) {
            throw ApiException::validation(['user_phone' => 'Enter a valid Tanzanian mobile number.']);
        }
        if (count($data['items']) < 1 || count($data['items']) > 30) {
            throw ApiException::validation(['items' => 'Add between 1 and 30 products.']);
        }

        $lines = [];
        $seen_product_ids = [];
        $item_errors = [];
        foreach (array_values($data['items']) as $index => $item) {
            if (!is_array($item)) {
                $item_errors["items.{$index}"] = 'Choose a product and quantity.';
                continue;
            }
            try {
                $line = Validator::validate($item, [
                    'product_id' => 'required|int|min:1',
                    'quantity'   => 'required|int|min:1|max:100000',
                ]);
            } catch (ApiException $e) {
                foreach ($e->fields() as $field => $message) {
                    $item_errors["items.{$index}.{$field}"] = $message;
                }
                continue;
            }
            if (isset($seen_product_ids[$line['product_id']])) {
                $item_errors["items.{$index}.product_id"] = 'Each product can appear once; combine its quantity on one line.';
                continue;
            }
            $seen_product_ids[$line['product_id']] = true;
            $lines[] = $line;
        }
        if ($item_errors) {
            throw ApiException::validation($item_errors);
        }
        if (count($lines) !== count($data['items'])) {
            throw ApiException::validation(['items' => 'Check each product and quantity.']);
        }

        (new Region($this->db))->checkLocation($data['region_id'], $data['district_id'] ?? null);
        $delivery_method = null;
        foreach ((new Checkout($this->db))->getDeliveryMethods($data['region_id']) as $method) {
            if ($method['delivery_method_id'] === $data['delivery_method_id']) {
                $delivery_method = $method;
                break;
            }
        }
        if ($delivery_method === null) {
            throw ApiException::validation(['delivery_method_id' => 'Choose a delivery method available for this region.']);
        }
        if (!in_array($data['payment_method'], (new Checkout($this->db))->getEnabledPaymentMethods(), true)) {
            throw ApiException::validation(['payment_method' => 'This payment method is not available right now.']);
        }

        return $this->db->transaction(function () use ($data, $lines, $delivery_method, $admin_id): int {
            // Staff either select an existing account or create one from the customer's contact details.
            if (isset($data['user_id'])) {
                $user_id = (int) $data['user_id'];
            } else {
                $this->db->execute(
                    'INSERT INTO users (user_phone, user_full_name) VALUES (:phone, :full_name)
                     ON DUPLICATE KEY UPDATE user_id = LAST_INSERT_ID(user_id)',
                    ['phone' => $data['user_phone'], 'full_name' => $data['user_full_name']]
                );
                $user_id = (int) $this->db->fetchValue('SELECT user_id FROM users WHERE user_phone = :phone FOR UPDATE', ['phone' => $data['user_phone']]);
            }
            $user = $this->db->fetchOne('SELECT user_status, user_full_name, user_phone FROM users WHERE user_id = :id FOR UPDATE', ['id' => $user_id]);
            if ($user === null || $user['user_phone'] !== $data['user_phone']) {
                throw ApiException::validation(['user_id' => 'Select the customer again; their details have changed.']);
            }
            if ($user['user_status'] !== 'active') {
                throw ApiException::forbidden('This customer account is not active.');
            }
            if ($user['user_full_name'] === null) {
                $this->db->execute('UPDATE users SET user_full_name = :name WHERE user_id = :id', ['name' => $data['user_full_name'], 'id' => $user_id]);
            }

            $product_ids = array_map(fn (array $line): int => (int) $line['product_id'], $lines);
            sort($product_ids);
            $locked_products = $this->db->fetchAll(
                "SELECT p.product_id, p.product_name, p.product_sku, p.seller_id, p.product_unit_label,
                        p.product_moq, p.product_stock_quantity, image.product_image_thumb_path
                 FROM products p
                 JOIN sellers s ON s.seller_id = p.seller_id AND s.seller_status = 'active'
                 JOIN categories c ON c.category_id = p.category_id AND c.category_is_active = 1
                 LEFT JOIN product_images image ON image.product_id = p.product_id AND image.product_image_is_primary = 1
                 WHERE p.product_id IN (" . implode(',', $product_ids) . ")
                   AND p.product_is_active = 1 AND p.deleted_at IS NULL
                 ORDER BY p.product_id FOR UPDATE"
            );
            $products = array_column($locked_products, null, 'product_id');
            if (count($products) !== count($lines)) {
                throw ApiException::validation(['items' => 'One or more selected products are no longer available.']);
            }

            $priced_lines = [];
            $subtotal = 0;
            foreach ($lines as $line) {
                $product_id = (int) $line['product_id'];
                $product = $products[$product_id];
                $quantity = (int) $line['quantity'];
                if ($quantity < (int) $product['product_moq']) {
                    throw ApiException::validation(['items' => "{$product['product_name']}: minimum quantity is {$product['product_moq']}."]);
                }
                if ($quantity > (int) $product['product_stock_quantity']) {
                    throw ApiException::conflict('OUT_OF_STOCK', "{$product['product_name']}: only {$product['product_stock_quantity']} available.");
                }
                $tiers = $this->db->fetchAll(
                    'SELECT tier_min_quantity, tier_unit_price FROM product_price_tiers WHERE product_id = :id ORDER BY tier_min_quantity',
                    ['id' => $product_id]
                );
                if (!$tiers) {
                    throw ApiException::validation(['items' => "{$product['product_name']} has no price tiers and cannot be ordered."]);
                }
                $pricing = Pricing::priceLine($tiers, $quantity);
                $priced_lines[] = ['product' => $product, 'quantity' => $quantity, 'pricing' => $pricing];
                $subtotal += $pricing['line_total'];
            }

            $delivery_fee = (int) $delivery_method['delivery_method_fee'];
            $total = $subtotal + $delivery_fee;
            $this->order_model->checkCashOnDeliveryLimit($data['payment_method'], $total);

            if (isset($data['address_id'])) {
                $address = (new Address($this->db))->getAddress($user_id, (int) $data['address_id']);
                if ($address['region_id'] !== $data['region_id']
                    || ($delivery_method['region_id'] !== null && $delivery_method['region_id'] !== $address['region_id'])) {
                    throw ApiException::validation(['delivery_method_id' => 'Choose a delivery method available for the selected address.']);
                }
                $data['address_recipient_name'] = $address['address_recipient_name'];
                $data['address_phone'] = $address['address_phone'];
                $data['region_id'] = $address['region_id'];
                $data['district_id'] = $address['district_id'];
                $data['address_street'] = $address['address_street'];
                $data['address_landmark'] = $address['address_landmark'];
                $region_name = $address['region_name'];
                $district_name = $address['district_name'];
            } else {
                $has_address = (bool) $this->db->fetchValue('SELECT 1 FROM addresses WHERE user_id = :id LIMIT 1', ['id' => $user_id]);
                $this->db->insert(
                    'INSERT INTO addresses (user_id, address_recipient_name, address_phone, region_id, district_id,
                        address_street, address_landmark, address_is_default)
                     VALUES (:user_id, :recipient, :phone, :region, :district, :street, :landmark, :is_default)',
                    [
                        'user_id' => $user_id, 'recipient' => $data['address_recipient_name'], 'phone' => $data['address_phone'],
                        'region' => $data['region_id'], 'district' => $data['district_id'] ?? null,
                        'street' => $data['address_street'], 'landmark' => $data['address_landmark'] ?? null,
                        'is_default' => (int) !$has_address,
                    ]
                );
                $region_name = (string) $this->db->fetchValue('SELECT region_name FROM regions WHERE region_id = :id', ['id' => $data['region_id']]);
                $district_name = empty($data['district_id']) ? null : $this->db->fetchValue('SELECT district_name FROM districts WHERE district_id = :id', ['id' => $data['district_id']]);
            }
            $is_cash = $data['payment_method'] === 'cod';
            $initial_status = $is_cash ? 'confirmed' : 'pending_payment';
            $order_number = $this->order_model->newOrderNumber();
            $order_id = $this->db->insert(
                'INSERT INTO orders (
                    order_number, user_id, order_channel, order_status, order_payment_method, order_payment_status,
                    order_subtotal, order_delivery_fee, order_discount_total, order_total,
                    delivery_method_id, order_delivery_method_name, order_delivery_days_min, order_delivery_days_max,
                    order_estimated_delivery_date, order_ship_recipient_name, order_ship_phone, region_id,
                    order_ship_region_name, order_ship_district_name, order_ship_street, order_ship_landmark,
                    order_customer_note, order_placed_at
                 ) VALUES (
                    :number, :user, \'web\', :status, :payment_method, :payment_status,
                    :subtotal, :delivery_fee, 0, :total,
                    :delivery_method, :delivery_name, :days_min, :days_max,
                    UTC_DATE() + INTERVAL :days_until DAY, :recipient, :ship_phone, :region,
                    :region_name, :district_name, :street, :landmark, :note, UTC_TIMESTAMP()
                 )',
                [
                    'number' => $order_number, 'user' => $user_id, 'status' => $initial_status,
                    'payment_method' => $data['payment_method'], 'payment_status' => $is_cash ? 'cod_pending' : 'unpaid',
                    'subtotal' => $subtotal, 'delivery_fee' => $delivery_fee, 'total' => $total,
                    'delivery_method' => $delivery_method['delivery_method_id'], 'delivery_name' => $delivery_method['delivery_method_name'],
                    'days_min' => $delivery_method['delivery_days_min'], 'days_max' => $delivery_method['delivery_days_max'],
                    'days_until' => $delivery_method['delivery_days_max'], 'recipient' => $data['address_recipient_name'],
                    'ship_phone' => $data['address_phone'], 'region' => $data['region_id'], 'region_name' => $region_name,
                    'district_name' => $district_name, 'street' => $data['address_street'], 'landmark' => $data['address_landmark'] ?? null,
                    'note' => $data['order_customer_note'] ?? null,
                ]
            );

            $stock = new ProductEditor($this->db);
            foreach ($priced_lines as $line) {
                $product = $line['product'];
                $pricing = $line['pricing'];
                $quantity = $line['quantity'];
                $this->db->insert(
                    'INSERT INTO order_items (
                        order_id, product_id, seller_id, order_item_product_name, order_item_sku, order_item_image_path,
                        order_item_unit_label, order_item_quantity, order_item_unit_price, order_item_tier_min_quantity, order_item_line_total
                     ) VALUES (:order, :product, :seller, :name, :sku, :image, :unit, :quantity, :price, :tier, :line_total)',
                    [
                        'order' => $order_id, 'product' => $product['product_id'], 'seller' => $product['seller_id'],
                        'name' => $product['product_name'], 'sku' => $product['product_sku'], 'image' => $product['product_image_thumb_path'],
                        'unit' => $product['product_unit_label'], 'quantity' => $quantity, 'price' => $pricing['unit_price'],
                        'tier' => $pricing['tier_min_quantity'], 'line_total' => $pricing['line_total'],
                    ]
                );
                $stock->changeStock((int) $product['product_id'], -$quantity, 'order_reserve', 'Manually created order', $admin_id, 'order', $order_id);
                $this->db->execute('UPDATE products SET product_sold_count = product_sold_count + :quantity WHERE product_id = :id', ['quantity' => $quantity, 'id' => $product['product_id']]);
            }

            // The same timeline row and customer notification as an order placed in the app
            $this->order_model->recordStatus($order_id, $initial_status, 'admin', $admin_id, 'Created by staff for a phone or walk-in order');
            $this->order_model->notifyCustomer(['order_id' => $order_id, 'user_id' => $user_id, 'order_number' => $order_number], $initial_status);
            (new AuditLog($this->db))->record('admin', $admin_id, 'order.created_manually', 'order', $order_id, null, ['order_number' => $order_number, 'user_id' => $user_id, 'order_total' => $total]);

            return $order_id;
        });
    }

    /** The full order (as the customer sees it) + customer details + the statuses it can move to next. */
    public function getOrderForAdmin(int $order_id): array
    {
        $row = $this->db->fetchOne($this->order_model->orderSelectSql() . ' WHERE o.order_id = :order_id', ['order_id' => $order_id]);
        if ($row === null) {
            throw ApiException::notFound('Order not found.');
        }

        $order = $this->order_model->formatOrders([$row])[0];
        $order['customer'] = $this->db->fetchOne(
            'SELECT u.user_id, u.user_full_name, u.user_phone, b.business_name
             FROM users u LEFT JOIN business_profiles b ON b.user_id = u.user_id
             WHERE u.user_id = :user_id',
            ['user_id' => $row['user_id']]
        );
        $order['delivery'] = $this->db->fetchOne('SELECT * FROM order_deliveries WHERE order_id = :order_id', ['order_id' => $order_id]);
        $order['allowed_next_statuses'] = self::ALLOWED_TRANSITIONS[$order['order_status']] ?? [];
        $order['can_confirm_cash'] = $order['order_payment_method'] === 'cod'
            && $order['order_payment_status'] === 'cod_pending'
            && $order['order_status'] === 'delivered';

        return $order;
    }

    /**
     * Moves the order to the next status. Body: {"order_status", "status_note", "delivery_agent_id" (needed for dispatched)}.
     * Cancelling returns the stock. The customer gets a notification for every change.
     */
    public function changeStatus(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, [
            'order_status'      => 'required|in:packed,dispatched,in_transit,delivered,cancelled',
            'status_note'       => 'nullable|string|max:255',
            'delivery_agent_id' => 'nullable|int|min:1',
        ]);

        $this->db->transaction(function () use ($order_id, $data, $admin_id) {
            $order = $this->order_model->lockOrder($order_id);
            $current_status = $order['order_status'];

            if (!in_array($data['order_status'], self::ALLOWED_TRANSITIONS[$current_status] ?? [], true)) {
                throw ApiException::conflict('STATUS_CHANGE_NOT_ALLOWED', "An order that is \"{$current_status}\" can't become \"{$data['order_status']}\".");
            }

            if ($data['order_status'] === 'dispatched') {
                $this->assignDeliveryAgent($order_id, $data['delivery_agent_id'] ?? null);
            }
            if ($data['order_status'] === 'delivered') {
                $this->db->execute(
                    'INSERT INTO order_deliveries (order_id, delivery_delivered_at) VALUES (:order_id, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE delivery_delivered_at = UTC_TIMESTAMP()',
                    ['order_id' => $order_id]
                );
            }

            $this->order_model->moveToStatus($order, $data['order_status'], 'admin', $admin_id, $data['status_note'] ?? null);

            (new AuditLog($this->db))->record('admin', $admin_id, 'order.status_changed', 'order', $order_id,
                ['order_status' => $current_status], ['order_status' => $data['order_status'], 'status_note' => $data['status_note'] ?? null]);
        });
    }

    /**
     * Cash on delivery: records the cash the agent brought back and marks the order paid.
     * Only for delivered cash orders; the amount must equal the order total.
     * Body: {"delivery_cash_collected": 168800}
     */
    public function confirmCashCollected(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, ['delivery_cash_collected' => 'required|int|min:0']);

        $this->db->transaction(function () use ($order_id, $data, $admin_id) {
            $order = $this->order_model->lockOrder($order_id);

            $is_waiting_for_cash = $order['order_payment_method'] === 'cod'
                && $order['order_payment_status'] === 'cod_pending'
                && $order['order_status'] === 'delivered';
            if (!$is_waiting_for_cash) {
                throw ApiException::conflict('CASH_NOT_EXPECTED', 'Cash can only be confirmed for a delivered cash-on-delivery order that is not yet paid.');
            }
            if ($data['delivery_cash_collected'] !== (int) $order['order_total']) {
                throw ApiException::validation(['delivery_cash_collected' => 'The amount must equal the order total: TZS ' . number_format((int) $order['order_total']) . '.']);
            }

            $this->db->execute(
                'UPDATE order_deliveries
                 SET delivery_cash_collected = :cash, delivery_cash_confirmed_by_admin_id = :admin_id, delivery_cash_confirmed_at = UTC_TIMESTAMP()
                 WHERE order_id = :order_id',
                ['cash' => $data['delivery_cash_collected'], 'admin_id' => $admin_id, 'order_id' => $order_id]
            );
            $this->db->execute("UPDATE orders SET order_payment_status = 'paid' WHERE order_id = :order_id", ['order_id' => $order_id]);

            (new AuditLog($this->db))->record('admin', $admin_id, 'order.cash_confirmed', 'order', $order_id, null, $data);
        });
    }

    /** Dispatching needs an active delivery agent; the customer then sees their name and phone. */
    private function assignDeliveryAgent(int $order_id, ?int $delivery_agent_id): void
    {
        $is_active_agent = $delivery_agent_id !== null && $this->db->fetchValue(
            'SELECT 1 FROM delivery_agents WHERE delivery_agent_id = :id AND delivery_agent_is_active = 1',
            ['id' => $delivery_agent_id]
        );
        if (!$is_active_agent) {
            throw ApiException::validation(['delivery_agent_id' => 'Choose the delivery agent who takes this order.']);
        }

        $this->db->execute(
            'INSERT INTO order_deliveries (order_id, delivery_agent_id, delivery_dispatched_at)
             VALUES (:order_id, :delivery_agent_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE delivery_agent_id = VALUES(delivery_agent_id), delivery_dispatched_at = UTC_TIMESTAMP()',
            ['order_id' => $order_id, 'delivery_agent_id' => $delivery_agent_id]
        );
    }
}
