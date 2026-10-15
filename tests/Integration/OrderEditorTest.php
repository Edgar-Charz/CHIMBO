<?php

use PHPUnit\Framework\TestCase;

/** Staff edit orders, step 1 (blueprint R-29, §12.11): items, delivery, payment method, discount, address, notes. */
final class OrderEditorTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const VASELINE = 1;   // 6+ level: 5,000 each
    private const NECKLACE = 7;   // MOQ 6, 6+ level: 8,500 each

    private Database $db;
    private OrderEditor $editor;
    private Order $order_model;
    private int $user_id;
    private int $address_id;
    private array $original_products;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        PaymentMethod::clearCache();
        $this->db->execute(
            "INSERT IGNORE INTO admins (admin_id, admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (1, 'Test Admin', 'test-admin@chimbo.test', 'x', 'super_admin')"
        );
        $this->db->execute(
            "UPDATE payment_methods SET payment_method_is_active = 1, payment_method_account_name = 'CHIMBO LTD',
                    payment_method_account_number = '123456' WHERE payment_method_code = 'mpesa'"
        );
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');

        $this->editor      = new OrderEditor($this->db);
        $this->order_model = new Order($this->db);
        $this->user_id     = (new User($this->db))->findOrCreateUserIdByPhone('+255712000444');
        $this->address_id  = (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Rehema Juma', 'address_phone' => '0712000444',
            'region_id' => $this->regionId('Dar es Salaam'), 'address_street' => 'Kariakoo',
        ])['address_id'];
    }

    protected function tearDown(): void
    {
        foreach ($this->original_products as $product) {
            $this->db->execute(
                'UPDATE products SET product_stock_quantity = :product_stock_quantity, product_sold_count = :product_sold_count WHERE product_id = :product_id',
                $product
            );
        }
        $this->db->execute("UPDATE payment_methods SET payment_method_is_active = (payment_method_code = 'cod'),
                                   payment_method_account_name = NULL, payment_method_account_number = NULL");
        PaymentMethod::clearCache();
    }

    public function testItemsChangeWithStockFollowingAndTheCustomerTold(): void
    {
        $order = $this->placeOrder();   // 8 Vaseline = 40,000
        $vaseline_stock = $this->stock(self::VASELINE);
        $necklace_stock = $this->stock(self::NECKLACE);

        $this->editor->updateItems($order['order_id'], ['items' => [
            ['product_id' => self::VASELINE, 'quantity' => 10],
            ['product_id' => self::NECKLACE, 'quantity' => 6],
        ]], self::ADMIN_ID);

        $edited = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame(10 * 5000 + 6 * 8500, $edited['order_total']);
        $this->assertSame([$vaseline_stock - 2, $necklace_stock - 6], [$this->stock(self::VASELINE), $this->stock(self::NECKLACE)]);
        $this->assertStringContainsString('Jumla mpya: TZS 101,000', $this->latestNotification());

        // Removing a line gives its stock back
        $this->editor->updateItems($order['order_id'], ['items' => [['product_id' => self::NECKLACE, 'quantity' => 6]]], self::ADMIN_ID);
        $this->assertSame($vaseline_stock + 8, $this->stock(self::VASELINE));
        $this->assertSame(51000, $this->order_model->getOrder($this->user_id, $order['order_id'])['order_total']);

        $this->assertFieldError('items', fn () => $this->editor->updateItems($order['order_id'], ['items' => []], self::ADMIN_ID));
    }

    public function testDiscountNeedsAReasonAndCannotPassTheProductsTotal(): void
    {
        $order = $this->placeOrder();

        $this->assertFieldError('order_discount_note', fn () => $this->editor->setDiscount($order['order_id'], ['order_discount_total' => 2000], self::ADMIN_ID));
        $this->assertFieldError('order_discount_total', fn () => $this->editor->setDiscount($order['order_id'], ['order_discount_total' => 50000, 'order_discount_note' => 'Too much'], self::ADMIN_ID));

        $this->editor->setDiscount($order['order_id'], ['order_discount_total' => 2000, 'order_discount_note' => 'Mteja wa kudumu'], self::ADMIN_ID);
        $edited = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame([2000, 'Mteja wa kudumu', 38000], [$edited['order_discount_total'], $edited['order_discount_note'], $edited['order_total']]);
        $this->assertSame(38000, $edited['payment']['payment_amount']);   // the customer pays the discounted total
    }

    public function testDeliveryChangeFollowsItsPriceRule(): void
    {
        $order = $this->placeOrder();
        $pickup = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'pickup'");
        $upcountry = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'upcountry'");

        $this->editor->changeDelivery($order['order_id'], ['delivery_method_id' => $pickup], self::ADMIN_ID);
        $this->assertTrue($this->order_model->getOrder($this->user_id, $order['order_id'])['delivery_method']['delivery_method_is_pickup']);

        // Upcountry doesn't go to Dar es Salaam
        $this->assertFieldError('delivery_method_id', fn () => $this->editor->changeDelivery($order['order_id'], ['delivery_method_id' => $upcountry], self::ADMIN_ID));
    }

    public function testAddressCanChangeUntilSentButUnpaidEditsCloseOnceAPaymentIsBeingChecked(): void
    {
        $order = $this->placeOrder();
        (new Payment($this->db))->submitPayment($this->user_id, $order['order_id'], ['payment_payer_account' => '0712000444', 'payment_reference' => 'QJK3X7EDIT']);

        $rules = $this->editor->getEditRules($order['order_id']);
        $this->assertFalse($rules['can_edit_unpaid']);
        $this->assertTrue($rules['can_edit_address']);
        try {
            $this->editor->setDiscount($order['order_id'], ['order_discount_total' => 0], self::ADMIN_ID);
            $this->fail('Expected ORDER_NOT_EDITABLE');
        } catch (ApiException $e) {
            $this->assertSame('ORDER_NOT_EDITABLE', $e->errorCode());
        }

        $this->editor->updateAddress($order['order_id'], [
            'address_recipient_name' => 'Rehema Juma', 'address_phone' => '0754111222', 'region_id' => $this->regionId('Dar es Salaam'),
            'address_street' => 'Sinza, Mori',
        ], self::ADMIN_ID);
        $address = $this->order_model->getOrder($this->user_id, $order['order_id'])['address'];
        $this->assertSame(['Sinza, Mori', '+255754111222'], [$address['address_street'], $address['address_phone']]);

        // Bolt doesn't go to Mwanza: change the delivery option first
        $this->assertFieldError('region_id', fn () => $this->editor->updateAddress($order['order_id'], [
            'address_recipient_name' => 'Rehema Juma', 'address_phone' => '0754111222', 'region_id' => $this->regionId('Mwanza'),
            'address_street' => 'Kirumba',
        ], self::ADMIN_ID));
    }

    public function testStaffNotesArePrivate(): void
    {
        $order = $this->placeOrder();
        $this->editor->addStaffNote($order['order_id'], ['order_staff_note_text' => 'Atakuja kuchukua saa 10 jioni'], self::ADMIN_ID);

        $notes = $this->editor->getStaffNotes($order['order_id']);
        $this->assertSame(['Atakuja kuchukua saa 10 jioni', 'Test Admin'], [$notes[0]['order_staff_note_text'], $notes[0]['admin_full_name']]);
        $this->assertStringNotContainsString('saa 10 jioni', json_encode($this->order_model->getOrder($this->user_id, $order['order_id'])));
    }

    // ------------------------------------------------------------------ step 2: a paid order

    public function testAddingToAPaidOrderLeavesABalanceToPayAndPackingWaitsForIt(): void
    {
        $order    = $this->placeOrder();   // 40,000
        $order_id = $order['order_id'];
        $this->pay($order_id, 'QJK3X7PAID');
        $this->assertTrue($this->editor->getEditRules($order_id)['can_add_to_paid']);

        $this->editor->updateItems($order_id, ['items' => [['product_id' => self::VASELINE, 'quantity' => 12]]], self::ADMIN_ID);   // 60,000

        $order = $this->order_model->getOrder($this->user_id, $order_id);
        $this->assertSame(['confirmed', 'balance_due', 60000], [$order['order_status'], $order['order_payment_status'], $order['order_total']]);
        $this->assertSame([true, true, 20000, 40000], [$order['payment']['can_submit_payment'], $order['payment']['is_balance_payment'],
            $order['payment']['payment_amount'], $order['payment']['amount_paid']]);
        $this->assertStringContainsString('Salio la kulipa: TZS 20,000', $this->latestNotification());
        $this->assertNotContains('packed', (new OrderManager($this->db))->getOrderForAdmin($order_id)['allowed_next_statuses']);

        // A wrong code is rejected: still a balance to pay; the right one confirmed: paid, packing can go on
        $payments = new Payment($this->db);
        $rejected = $payments->submitPayment($this->user_id, $order_id, ['payment_payer_account' => '0712000444', 'payment_reference' => 'QJK3X7BAD1']);
        $payments->rejectPayment($rejected['payment']['latest_payment']['payment_id'], ['payment_review_note' => 'Namba si sahihi.'], self::ADMIN_ID);
        $this->assertSame('balance_due', $this->order_model->getOrder($this->user_id, $order_id)['order_payment_status']);

        $sent = $payments->submitPayment($this->user_id, $order_id, ['payment_payer_account' => '0712000444', 'payment_reference' => 'QJK3X7BAL1']);
        $this->assertSame(20000, (int) $this->db->fetchValue('SELECT payment_amount FROM payments WHERE payment_id = ' . $sent['payment']['latest_payment']['payment_id']));
        $payments->confirmPayment($sent['payment']['latest_payment']['payment_id'], self::ADMIN_ID);

        $paid = $this->order_model->getOrder($this->user_id, $order_id);
        $this->assertSame(['confirmed', 'paid', 60000], [$paid['order_status'], $paid['order_payment_status'], $paid['payment']['amount_paid']]);
        (new OrderManager($this->db))->changeStatus($order_id, ['order_status' => 'packed'], self::ADMIN_ID);
    }

    public function testALowerTotalOnAPaidOrderIsARefundDue(): void
    {
        $order_id = $this->placeOrder()['order_id'];   // 40,000
        $this->pay($order_id, 'QJK3X7PAI2');

        $this->editor->updateItems($order_id, ['items' => [['product_id' => self::VASELINE, 'quantity' => 6]]], self::ADMIN_ID);   // 30,000

        $order = $this->order_model->getOrder($this->user_id, $order_id);
        $this->assertSame(['paid', 10000], [$order['order_payment_status'], $order['payment']['refund_due']]);
        $this->assertStringContainsString('Tutakurudishia TZS 10,000', $this->latestNotification());
        $due = array_column((new Payment($this->db))->getRefundsDue(), 'refund_amount', 'order_id');
        $this->assertSame(10000, (int) $due[$order_id]);

        (new Payment($this->db))->markRefunded($order_id, ['refund_note' => 'M-Pesa QJK… 10,000 to 0712000444'], self::ADMIN_ID);
        $refunded = $this->order_model->getOrder($this->user_id, $order_id);
        $this->assertSame([0, 30000, 'paid'], [$refunded['payment']['refund_due'], $refunded['payment']['amount_paid'], $refunded['order_payment_status']]);

        // Adding again now counts what's really been kept (30,000), not what was first paid
        $this->editor->updateItems($order_id, ['items' => [['product_id' => self::VASELINE, 'quantity' => 8]]], self::ADMIN_ID);   // 40,000
        $this->assertSame(10000, $this->order_model->getOrder($this->user_id, $order_id)['payment']['payment_amount']);
    }

    // ------------------------------------------------------------------ helpers

    private function pay(int $order_id, string $reference): void
    {
        $payments = new Payment($this->db);
        $sent     = $payments->submitPayment($this->user_id, $order_id, ['payment_payer_account' => '0712000444', 'payment_reference' => $reference]);
        $payments->confirmPayment($sent['payment']['latest_payment']['payment_id'], self::ADMIN_ID);
    }

    private function placeOrder(): array
    {
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $method = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'standard'");
        $total  = (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => $method, 'address_id' => $this->address_id])['grand_total'];
        return $this->order_model->placeOrder($this->user_id, [
            'address_id' => $this->address_id, 'delivery_method_id' => $method, 'payment_method' => 'mpesa', 'expected_total' => $total,
        ], 'app', null);
    }

    private function stock(int $product_id): int
    {
        return (int) $this->db->fetchValue('SELECT product_stock_quantity FROM products WHERE product_id = ' . $product_id);
    }

    private function latestNotification(): string
    {
        return (string) $this->db->fetchValue("SELECT notification_body FROM notifications WHERE user_id = {$this->user_id} ORDER BY notification_id DESC LIMIT 1");
    }

    private function regionId(string $region_name): int
    {
        return (int) $this->db->fetchValue('SELECT region_id FROM regions WHERE region_name = :name', ['name' => $region_name]);
    }

    private function assertFieldError(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected a validation error on {$field}");
        } catch (ApiException $e) {
            $this->assertArrayHasKey($field, $e->fields(), $e->getMessage());
        }
    }
}
