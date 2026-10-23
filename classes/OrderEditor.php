<?php

/**
 * Staff edit an order (blueprint R-29, §12.11) — the "Edit order" page. While the order is unpaid: its items,
 * delivery option, payment method and discount. When it is paid but not packed yet: its items ("add to this order") —
 * a higher total leaves a balance to pay, a lower one a refund due; an upcountry order's transport can be confirmed
 * again for the new load. Until it is sent: the delivery address and phone. Any time: private staff notes. The total
 * is worked out again after every change, the customer is told about changes that touch them, and everything is
 * audit-logged.
 *
 * How to use it:
 *   $editor = new OrderEditor(Database::instance());
 *   $editor->getEditRules($order_id);                       // what can be edited now (the page shows only that)
 *   $editor->updateItems($order_id, ['items' => [['product_id' => 1, 'quantity' => 8]]], $admin_id);
 *   $editor->addStaffNote($order_id, ['order_staff_note_text' => 'Atakuja saa 10'], $admin_id);
 */
class OrderEditor
{
    // Not sent yet: the address and phone can still change
    private const NOT_SENT_STATUSES = ['pending_payment', 'confirmed', 'packed'];

    public function __construct(private Database $db)
    {
    }

    /**
     * What staff may edit on this order now: {can_edit_unpaid, can_add_to_paid, can_edit_address, reason, amount_paid}
     * — reason explains why the unpaid edits are closed ("being paid", "already paid" …), or null.
     */
    public function getEditRules(int $order_id): array
    {
        $order = $this->db->fetchOne('SELECT * FROM orders WHERE order_id = :id', ['id' => $order_id]);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }
        return [
            'can_edit_unpaid'  => self::isUnpaidAndOpen($order),
            'can_add_to_paid'  => self::isPaidAndOpen($order),
            'can_edit_address' => in_array($order['order_status'], self::NOT_SENT_STATUSES, true),
            'reason'           => self::closedReason($order),
            'amount_paid'      => $this->amountPaid($order_id),
        ];
    }

    /**
     * Sets the order's products: {"items": [{"product_id", "quantity"}, …]} (at least one). A line whose quantity is
     * unchanged keeps its price; a changed or new line gets today's price for its quantity. Stock follows the difference.
     * On a paid order (not packed yet) also {"order_delivery_fee", "order_delivery_fee_note"} for an upcountry order's
     * transport with the new load (a reason when it changes); then the money is settled: a balance to pay or a refund due.
     */
    public function updateItems(int $order_id, array $input, int $admin_id): void
    {
        $items     = $this->checkItems($input);
        $transport = Validator::validate($input, [
            'order_delivery_fee'      => 'nullable|int|min:0|max:10000000',
            'order_delivery_fee_note' => 'nullable|string|max:255',
        ]);

        $this->db->transaction(function (Database $db) use ($order_id, $items, $transport, $admin_id) {
            $order   = $this->lockOrderForItems($order_id);
            $is_paid = self::isPaidAndOpen($order);
            $current = array_column($db->fetchAll('SELECT * FROM order_items WHERE order_id = :id', ['id' => $order_id]), null, 'product_id');

            // Changed and new lines are priced today; this order's own reservation counts as available for it
            $to_price = [];
            foreach ($items as $product_id => $quantity) {
                if (($current[$product_id]['order_item_quantity'] ?? null) !== null && (int) $current[$product_id]['order_item_quantity'] === $quantity) {
                    continue;   // unchanged: keeps its price
                }
                $to_price[] = ['product_id' => $product_id, 'quantity' => $quantity,
                               'name' => $current[$product_id]['order_item_product_name'] ?? null,
                               'reserved' => (int) ($current[$product_id]['order_item_quantity'] ?? 0)];
            }
            $priced = (new OrderManager($db))->priceLinesToday($to_price, true);
            if ($priced['problems'] !== []) {
                throw ApiException::validation(['items' => implode(' ', array_column($priced['problems'], 'message'))]);
            }

            $stock = new ProductEditor($db);
            foreach ($priced['lines'] as $line) {
                $pricing  = $line['pricing'];
                $old      = $current[$line['product_id']] ?? null;
                $old_qty  = (int) ($old['order_item_quantity'] ?? 0);
                $values   = ['quantity' => $line['quantity'], 'price' => $pricing['unit_price'], 'tier' => $pricing['tier_min_quantity'],
                             'offer' => $pricing['offer_percent'], 'line_total' => $pricing['line_total']];
                if ($old === null) {
                    $product = $line['product'];
                    $db->insert(
                        'INSERT INTO order_items (order_id, product_id, seller_id, order_item_product_name, order_item_sku, order_item_image_path,
                                                  order_item_unit_label, order_item_quantity, order_item_unit_price, order_item_tier_min_quantity,
                                                  order_item_offer_percent, order_item_line_total)
                         VALUES (:order_id, :product_id, :seller, :name, :sku, :image, :unit, :quantity, :price, :tier, :offer, :line_total)',
                        $values + ['order_id' => $order_id, 'product_id' => $line['product_id'], 'seller' => $product['seller_id'],
                                   'name' => $product['product_name'], 'sku' => $product['product_sku'],
                                   'image' => $product['product_image_thumb_path'], 'unit' => $product['product_unit_label']]
                    );
                } else {
                    $db->execute(
                        'UPDATE order_items SET order_item_quantity = :quantity, order_item_unit_price = :price, order_item_tier_min_quantity = :tier,
                                order_item_offer_percent = :offer, order_item_line_total = :line_total
                         WHERE order_item_id = :order_item_id',
                        $values + ['order_item_id' => $old['order_item_id']]
                    );
                }
                $this->moveStock($stock, $line['product_id'], $line['quantity'] - $old_qty, $admin_id, $order_id);
            }

            // Removed lines give their stock back
            foreach (array_diff_key($current, $items) as $product_id => $old) {
                $db->execute('DELETE FROM order_items WHERE order_item_id = :id', ['id' => $old['order_item_id']]);
                $this->moveStock($stock, (int) $product_id, -(int) $old['order_item_quantity'], $admin_id, $order_id);
            }

            if ($is_paid) {
                $this->updatePaidTransport($order, $transport);
            }
            $total = $this->saveTotals($order);
            if ($is_paid) {
                $this->settlePaidOrder($order, $total);
            } else {
                $this->tellCustomer($order, 'bidhaa zimebadilishwa', $total);
            }
            (new AuditLog($db))->record('admin', $admin_id, 'order.items_changed', 'order', $order_id,
                ['items' => array_map(fn (array $row): int => (int) $row['order_item_quantity'], $current), 'order_total' => (int) $order['order_total']],
                ['items' => $items, 'order_total' => $total]);
        });
    }

    /**
     * Another delivery option for the order's region: {"delivery_method_id"}. Its price rule applies — a fixed fee,
     * 0 for Bolt (paid to the rider) and pickup, or the region's estimate that staff confirm again (no time to pay
     * until then).
     */
    public function changeDelivery(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, ['delivery_method_id' => 'required|int|min:1']);

        $this->db->transaction(function (Database $db) use ($order_id, $data, $admin_id) {
            $order  = $this->lockUnpaidOrder($order_id);
            $method = $this->deliveryMethodFor((int) $order['region_id'], $data['delivery_method_id']);
            if ($method === null) {
                throw ApiException::validation(['delivery_method_id' => "This delivery option doesn't go to {$order['order_ship_region_name']}."]);
            }
            if ($method['delivery_fee_needs_confirmation'] && $order['order_payment_method'] === 'cod') {
                throw ApiException::validation(['delivery_method_id' => 'Upcountry transport is paid by phone or bank — change the payment method first.']);
            }

            $fee        = (int) ($method['delivery_method_fee'] ?? 0);
            $to_confirm = $method['delivery_fee_needs_confirmation'];
            $was_estimate = $order['order_delivery_fee_estimate'] !== null;
            $expiry       = (new Settings($db))->unpaidOrderExpiryMinutes();
            $db->execute(
                "UPDATE orders
                 SET delivery_method_id = :method_id, order_delivery_method_name = :name, order_delivery_fee = :fee,
                     order_delivery_days_min = :days_min, order_delivery_days_max = :days_max,
                     order_estimated_delivery_date = UTC_DATE() + INTERVAL :days_until DAY,
                     order_delivery_fee_estimate = :estimate, order_delivery_fee_confirmed_at = NULL,
                     order_delivery_fee_confirmed_by_admin_id = NULL, order_delivery_fee_note = NULL,
                     order_delivery_paid_to_rider = :paid_to_rider, order_is_pickup = :is_pickup,
                     order_expires_at = CASE
                         WHEN :to_confirm = 1 THEN NULL                                              -- staff confirm first
                         WHEN :was_estimate = 1 AND order_status = 'pending_payment'
                              THEN UTC_TIMESTAMP() + INTERVAL {$expiry} MINUTE                       -- the time to pay starts now
                         ELSE order_expires_at END
                 WHERE order_id = :order_id",
                ['method_id' => $method['delivery_method_id'], 'name' => $method['delivery_method_name'], 'fee' => $fee,
                 'days_min' => $method['delivery_days_min'], 'days_max' => $method['delivery_days_max'], 'days_until' => $method['delivery_days_max'],
                 'estimate' => $to_confirm ? $fee : null, 'paid_to_rider' => (int) $method['delivery_fee_paid_to_rider'],
                 'is_pickup' => (int) $method['delivery_method_is_pickup'], 'to_confirm' => (int) $to_confirm,
                 'was_estimate' => (int) $was_estimate, 'order_id' => $order_id]
            );

            $order['order_delivery_fee'] = $fee;
            $total = $this->saveTotals($order);
            $this->tellCustomer($order, "usafirishaji sasa ni {$method['delivery_method_name']}", $total);
            (new AuditLog($db))->record('admin', $admin_id, 'order.delivery_changed', 'order', $order_id,
                ['delivery_method_id' => (int) $order['delivery_method_id'], 'order_delivery_fee' => (int) $order['order_delivery_fee']],
                ['delivery_method_id' => $method['delivery_method_id'], 'order_delivery_fee' => $fee]);
        });
    }

    /**
     * Another payment method, switched on: {"payment_method"}. To cash on delivery: within its limit, and the order is
     * confirmed (as if chosen at checkout). From cash to phone / bank: back to awaiting payment with a new time to pay.
     */
    public function changePaymentMethod(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, ['payment_method' => 'required|string|max:40']);

        $this->db->transaction(function (Database $db) use ($order_id, $data, $admin_id) {
            $order      = $this->lockUnpaidOrder($order_id);
            $new_method = $data['payment_method'];
            if ($new_method === $order['order_payment_method']) {
                return;
            }
            if (!in_array($new_method, (new PaymentMethod($db))->getActiveCodes(), true)) {
                throw ApiException::validation(['payment_method' => 'This payment method is not switched on.']);
            }

            $order_model = new Order($db);
            if ($new_method === 'cod') {
                if ($order['order_delivery_fee_estimate'] !== null && $order['order_delivery_fee_confirmed_at'] === null) {
                    throw ApiException::validation(['payment_method' => 'Not while the upcountry transport price is still to be confirmed.']);
                }
                $order_model->checkCashOnDeliveryLimit('cod', (int) $order['order_total']);
                $db->execute("UPDATE orders SET order_payment_method = 'cod', order_payment_status = 'cod_pending', order_expires_at = NULL
                              WHERE order_id = :id", ['id' => $order_id]);
                $order['order_payment_status'] = 'cod_pending';
                $order_model->moveToStatus($order, 'confirmed', 'admin', $admin_id, 'Atalipa akipokea mzigo');
            } elseif ($order['order_payment_method'] === 'cod') {
                $expiry = (new Settings($db))->unpaidOrderExpiryMinutes();
                $db->execute(
                    "UPDATE orders SET order_payment_method = :method, order_payment_status = 'unpaid', order_status = 'pending_payment',
                            order_expires_at = UTC_TIMESTAMP() + INTERVAL {$expiry} MINUTE
                     WHERE order_id = :id",
                    ['method' => $new_method, 'id' => $order_id]
                );
                $order_model->recordStatus($order_id, 'pending_payment', 'admin', $admin_id, 'Njia ya malipo imebadilishwa');
            } else {
                $db->execute('UPDATE orders SET order_payment_method = :method WHERE order_id = :id', ['method' => $new_method, 'id' => $order_id]);
            }

            $this->tellCustomer($order, 'njia ya malipo sasa ni ' . (new PaymentMethod($db))->nameOf($new_method), null);
            (new AuditLog($db))->record('admin', $admin_id, 'order.payment_method_changed', 'order', $order_id,
                ['order_payment_method' => $order['order_payment_method']], ['order_payment_method' => $new_method]);
        });
    }

    /** A discount on the products: {"order_discount_total": 5000, "order_discount_note": "Mteja wa kudumu"} (0 removes it). */
    public function setDiscount(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, [
            'order_discount_total' => 'required|int|min:0|max:100000000',
            'order_discount_note'  => 'nullable|string|max:255',
        ]);
        $amount = $data['order_discount_total'];
        $note   = $amount > 0 ? trim((string) ($data['order_discount_note'] ?? '')) : null;
        if ($amount > 0 && mb_strlen((string) $note) < 3) {
            throw ApiException::validation(['order_discount_note' => 'Say why (the customer sees it on the receipt).']);
        }

        $this->db->transaction(function (Database $db) use ($order_id, $amount, $note, $admin_id) {
            $order = $this->lockUnpaidOrder($order_id);
            if ($amount > (int) $order['order_subtotal']) {
                throw ApiException::validation(['order_discount_total' => 'The discount can be at most the products total (' . number_format((int) $order['order_subtotal']) . ').']);
            }
            $db->execute('UPDATE orders SET order_discount_total = :amount, order_discount_note = :note WHERE order_id = :id',
                ['amount' => $amount, 'note' => $note, 'id' => $order_id]);

            $order['order_discount_total'] = $amount;
            $total = $this->saveTotals($order);
            $this->tellCustomer($order, $amount > 0 ? 'punguzo la TZS ' . number_format($amount) . " ({$note})" : 'punguzo limeondolewa', $total);
            (new AuditLog($db))->record('admin', $admin_id, 'order.discount_changed', 'order', $order_id,
                ['order_discount_total' => (int) $order['order_discount_total'], 'order_discount_note' => $order['order_discount_note']],
                ['order_discount_total' => $amount, 'order_discount_note' => $note]);
        });
    }

    /**
     * The delivery address and phone (until the order is sent): {address_recipient_name, address_phone, region_id,
     * district_id?, address_street, address_landmark?}. The order's delivery option must go to the new region.
     */
    public function updateAddress(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, [
            'address_recipient_name' => 'required|string|min:2|max:100',
            'address_phone'          => 'required|phone_tz',
            'region_id'              => 'required|int|min:1',
            'district_id'            => 'nullable|int|min:1',
            'address_street'         => 'required|string|min:2|max:150',
            'address_landmark'       => 'nullable|string|max:150',
        ]);
        (new Region($this->db))->checkLocation($data['region_id'], $data['district_id'] ?? null);

        $this->db->transaction(function (Database $db) use ($order_id, $data, $admin_id) {
            $order = $db->fetchOne('SELECT * FROM orders WHERE order_id = :id FOR UPDATE', ['id' => $order_id]);
            if ($order === null) {
                throw ApiException::notFound('Order not found.');
            }
            if (!in_array($order['order_status'], self::NOT_SENT_STATUSES, true)) {
                throw ApiException::conflict('ORDER_ALREADY_SENT', 'The order has been sent; its address can no longer change.');
            }
            if ($this->deliveryMethodFor($data['region_id'], (int) $order['delivery_method_id']) === null) {
                throw ApiException::validation(['region_id' => "\"{$order['order_delivery_method_name']}\" doesn't go to this region — change the delivery option first."]);
            }

            $values = [
                'recipient' => $data['address_recipient_name'], 'phone' => $data['address_phone'], 'region_id' => $data['region_id'],
                'region_name' => (string) $db->fetchValue('SELECT region_name FROM regions WHERE region_id = :id', ['id' => $data['region_id']]),
                'district_name' => empty($data['district_id']) ? null : $db->fetchValue('SELECT district_name FROM districts WHERE district_id = :id', ['id' => $data['district_id']]),
                'street' => $data['address_street'], 'landmark' => $data['address_landmark'] ?? null,
            ];
            $db->execute(
                'UPDATE orders SET order_ship_recipient_name = :recipient, order_ship_phone = :phone, region_id = :region_id,
                        order_ship_region_name = :region_name, order_ship_district_name = :district_name,
                        order_ship_street = :street, order_ship_landmark = :landmark
                 WHERE order_id = :order_id',
                $values + ['order_id' => $order_id]
            );

            $this->tellCustomer($order, 'anwani ya kupokelea imebadilishwa', null);
            (new AuditLog($db))->record('admin', $admin_id, 'order.address_changed', 'order', $order_id,
                ['recipient' => $order['order_ship_recipient_name'], 'phone' => $order['order_ship_phone'], 'region' => $order['order_ship_region_name'],
                 'street' => $order['order_ship_street']],
                ['recipient' => $values['recipient'], 'phone' => $values['phone'], 'region' => $values['region_name'], 'street' => $values['street']]);
        });
    }

    /** A private note for staff (the customer never sees it): {"order_staff_note_text"}. Any time. */
    public function addStaffNote(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, ['order_staff_note_text' => 'required|string|min:2|max:1000']);
        if (!$this->db->fetchValue('SELECT 1 FROM orders WHERE order_id = :id', ['id' => $order_id])) {
            throw ApiException::notFound('Order not found.');
        }
        $this->db->insert(
            'INSERT INTO order_staff_notes (order_id, admin_id, order_staff_note_text) VALUES (:order_id, :admin_id, :text)',
            ['order_id' => $order_id, 'admin_id' => $admin_id, 'text' => $data['order_staff_note_text']]
        );
    }

    /** The order's staff notes, newest first: [{order_staff_note_text, admin_full_name, created_at}]. */
    public function getStaffNotes(int $order_id): array
    {
        return $this->db->fetchAll(
            'SELECT n.order_staff_note_text, a.admin_full_name, n.created_at
             FROM order_staff_notes n JOIN admins a ON a.admin_id = n.admin_id
             WHERE n.order_id = :id ORDER BY n.order_staff_note_id DESC',
            ['id' => $order_id]
        );
    }

    // ------------------------------------------------------------------ private

    /** Paid by phone / bank (or with a balance still to pay), not packed yet: products can be added (step 2). */
    private static function isPaidAndOpen(array $order): bool
    {
        return $order['order_status'] === 'confirmed'
            && $order['order_payment_method'] !== 'cod'
            && in_array($order['order_payment_status'], ['paid', 'balance_due'], true);
    }

    /** Awaiting payment with nothing being checked, or cash on delivery before packing. */
    private static function isUnpaidAndOpen(array $order): bool
    {
        return ($order['order_status'] === 'pending_payment' && $order['order_payment_status'] === 'unpaid')
            || ($order['order_status'] === 'confirmed' && $order['order_payment_status'] === 'cod_pending');
    }

    /** Why the unpaid edits are closed, or null. */
    private static function closedReason(array $order): ?string
    {
        return match (true) {
            self::isUnpaidAndOpen($order)                            => null,
            $order['order_payment_status'] === 'pending'             => 'A payment is being checked — confirm or reject it first.',
            self::isPaidAndOpen($order)                              => 'The order is paid: only its products can change (the customer pays a balance or gets money back).',
            in_array($order['order_status'], ['cancelled', 'expired', 'delivered'], true) => 'The order is ' . $order['order_status'] . '.',
            default                                                  => 'The order is already being prepared.',
        };
    }

    /** Locks the order for a change of products: unpaid, or paid and not packed yet. */
    private function lockOrderForItems(int $order_id): array
    {
        $order = $this->db->fetchOne('SELECT * FROM orders WHERE order_id = :id FOR UPDATE', ['id' => $order_id]);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }
        if (!self::isUnpaidAndOpen($order) && !self::isPaidAndOpen($order)) {
            throw ApiException::conflict('ORDER_NOT_EDITABLE', (string) self::closedReason($order));
        }
        return $order;
    }

    /** An upcountry order's transport for the new load (paid order): the same, or another price with a reason. */
    private function updatePaidTransport(array &$order, array $transport): void
    {
        $new_fee = $transport['order_delivery_fee'] ?? null;
        if ($new_fee === null || $order['order_delivery_fee_estimate'] === null || $new_fee === (int) $order['order_delivery_fee']) {
            return;   // not upcountry, or unchanged
        }
        $note = trim((string) ($transport['order_delivery_fee_note'] ?? ''));
        if (mb_strlen($note) < 3) {
            throw ApiException::validation(['order_delivery_fee_note' => 'Say why the transport price changes (the customer sees it).']);
        }
        $this->db->execute('UPDATE orders SET order_delivery_fee = :fee, order_delivery_fee_note = :note WHERE order_id = :id',
            ['fee' => $new_fee, 'note' => $note, 'id' => $order['order_id']]);
        $order['order_delivery_fee'] = $new_fee;
    }

    /**
     * After a paid order's products changed: more than was paid → a balance to pay (packing waits for it); less →
     * a refund due (Payments → Refunds due); the same → paid. The customer is told what happens to their money.
     */
    private function settlePaidOrder(array $order, int $total): void
    {
        $paid = $this->amountPaid((int) $order['order_id']);
        [$status, $refund_due, $what] = match (true) {
            $total > $paid => ['balance_due', 0, 'Salio la kulipa: TZS ' . number_format($total - $paid) . '. Lipa ili tuendelee kuiandaa'],
            $total < $paid => ['paid', $paid - $total, 'Tutakurudishia TZS ' . number_format($paid - $total)],
            default        => ['paid', 0, 'hakuna cha kulipa zaidi'],
        };
        $this->db->execute('UPDATE orders SET order_payment_status = :status, order_refund_due = :refund_due WHERE order_id = :id',
            ['status' => $status, 'refund_due' => $refund_due, 'id' => $order['order_id']]);

        $this->tellCustomer($order, "bidhaa zimebadilishwa. {$what}", $total);
        if ($refund_due > 0) {
            (new AdminNotification($this->db))->notify('refund.due', "Refund due: {$order['order_number']}",
                'Its total went down after a change: send TZS ' . number_format($refund_due) . ' back.', "order_details.php?id={$order['order_id']}");
        }
    }

    /** What the customer has paid (confirmed payments minus money given back after changes). */
    private function amountPaid(int $order_id): int
    {
        return (int) $this->db->fetchValue('SELECT ' . Order::AMOUNT_PAID_SQL . ' FROM orders o WHERE o.order_id = :id', ['id' => $order_id]);
    }

    /** Locks the order for an unpaid edit, or explains why it can't be edited. */
    private function lockUnpaidOrder(int $order_id): array
    {
        $order = $this->db->fetchOne('SELECT * FROM orders WHERE order_id = :id FOR UPDATE', ['id' => $order_id]);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }
        if (!self::isUnpaidAndOpen($order)) {
            throw ApiException::conflict('ORDER_NOT_EDITABLE', (string) self::closedReason($order));
        }
        return $order;
    }

    /** The checked items form → [product_id => quantity]. */
    private function checkItems(array $input): array
    {
        $errors = [];
        $items  = [];
        foreach (array_values((array) ($input['items'] ?? [])) as $index => $line) {
            $product_id = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity   = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($product_id === false) {
                $errors["items.{$index}.product_id"] = 'Choose a product.';
            } elseif ($quantity === false) {
                $errors["items.{$index}.quantity"] = 'Enter a quantity.';
            } elseif (isset($items[$product_id])) {
                $errors["items.{$index}.product_id"] = 'Each product can appear once; combine its quantity on one line.';
            } else {
                $items[$product_id] = $quantity;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if ($items === []) {
            throw ApiException::validation(['items' => 'Keep at least one product (to drop everything, cancel the order).']);
        }
        return $items;
    }

    /** The delivery option if it goes to the region (with its price for that region), else null. */
    private function deliveryMethodFor(int $region_id, int $delivery_method_id): ?array
    {
        foreach ((new Checkout($this->db))->getDeliveryMethods($region_id) as $method) {
            if ($method['delivery_method_id'] === $delivery_method_id) {
                return $method;
            }
        }
        return null;
    }

    /** Reserves (+) or gives back (−) stock for this order's line, and keeps the sold count in step. */
    private function moveStock(ProductEditor $stock, int $product_id, int $more_ordered, int $admin_id, int $order_id): void
    {
        if ($more_ordered === 0) {
            return;
        }
        $stock->changeStock($product_id, -$more_ordered, $more_ordered > 0 ? 'order_reserve' : 'order_release', 'Order edited by staff', $admin_id, 'order', $order_id);
        $this->db->execute('UPDATE products SET product_sold_count = GREATEST(0, CAST(product_sold_count AS SIGNED) + :more) WHERE product_id = :id',
            ['more' => $more_ordered, 'id' => $product_id]);
    }

    /** Works the subtotal and total out again from the lines; returns the new total. Cash stays within its limit. */
    private function saveTotals(array $order): int
    {
        $subtotal = (int) $this->db->fetchValue('SELECT COALESCE(SUM(order_item_line_total), 0) FROM order_items WHERE order_id = :id', ['id' => $order['order_id']]);
        $discount = min((int) $order['order_discount_total'], $subtotal);   // fewer products can't leave a bigger discount
        $total    = $subtotal + (int) $order['order_delivery_fee'] - $discount;
        if ($order['order_payment_method'] === 'cod') {
            (new Order($this->db))->checkCashOnDeliveryLimit('cod', $total);
        }
        $this->db->execute('UPDATE orders SET order_subtotal = :subtotal, order_discount_total = :discount, order_total = :total WHERE order_id = :id',
            ['subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'id' => $order['order_id']]);
        return $total;
    }

    /** "Oda #… imebadilishwa na CHIMBO: {what}. Jumla mpya: TZS …" (the total only when it may have changed). */
    private function tellCustomer(array $order, string $what, ?int $new_total): void
    {
        (new Notification($this->db))->notifyUser((int) $order['user_id'], 'order_status', 'Oda imebadilishwa',
            "Oda #{$order['order_number']} imebadilishwa na CHIMBO: {$what}."
                . ($new_total !== null ? ' Jumla mpya: TZS ' . number_format($new_total) . '.' : ''),
            ['order_id' => (int) $order['order_id']], [
                'sw' => [
                    'title' => 'Oda imebadilishwa',
                    'body' => "Oda #{$order['order_number']} imebadilishwa na CHIMBO: {$what}."
                        . ($new_total !== null ? ' Jumla mpya: TZS ' . number_format($new_total) . '.' : ''),
                ],
                'en' => [
                    'title' => 'Order updated',
                    'body' => "CHIMBO updated order #{$order['order_number']}. Review the order details in the app."
                        . ($new_total !== null ? ' New total: TZS ' . number_format($new_total) . '.' : ''),
                ],
            ]);
    }
}
