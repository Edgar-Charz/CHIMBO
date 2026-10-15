<?php

use PHPUnit\Framework\TestCase;

/**
 * Upcountry transport (blueprint R-25, §12.7): an estimate per region at checkout, staff confirm or adjust the price
 * after measuring the load, and only then the customer pays.
 */
final class UpcountryTransportTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const VASELINE = 1;           // 8 pieces = 40,000
    private const MWANZA_ESTIMATE = 15000;

    private Database $db;
    private Checkout $checkout;
    private Order $order_model;
    private OrderManager $order_manager;
    private int $user_id;
    private int $mwanza_address_id;
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
        $this->db->execute("UPDATE regions SET region_transport_estimate = " . self::MWANZA_ESTIMATE . " WHERE region_name = 'Mwanza'");
        $this->db->execute(
            "UPDATE payment_methods SET payment_method_is_active = 1, payment_method_account_name = 'CHIMBO LTD',
                    payment_method_account_number = '123456' WHERE payment_method_code = 'mpesa'"
        );
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');

        $this->checkout      = new Checkout($this->db);
        $this->order_model   = new Order($this->db);
        $this->order_manager = new OrderManager($this->db);
        $this->user_id       = (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');
        $this->mwanza_address_id = (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => $this->regionId('Mwanza'),
            'address_street'         => 'Kirumba',
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
        $this->db->execute('UPDATE regions SET region_transport_estimate = NULL');
        $this->db->execute("UPDATE payment_methods SET payment_method_is_active = (payment_method_code = 'cod'),
                                   payment_method_account_name = NULL, payment_method_account_number = NULL");
        PaymentMethod::clearCache();
    }

    public function testCheckoutShowsTheRegionsEstimateToBeConfirmed(): void
    {
        $this->fillCart();

        $preview = $this->checkout->preview($this->user_id, ['delivery_method_id' => $this->upcountryId(), 'address_id' => $this->mwanza_address_id]);

        $this->assertSame(self::MWANZA_ESTIMATE, $preview['delivery_fee']);
        $this->assertTrue($preview['delivery_fee_needs_confirmation']);
        $this->assertSame(40000 + self::MWANZA_ESTIMATE, $preview['grand_total']);
    }

    public function testTheOrderWaitsForStaffAndCannotBePaidYet(): void
    {
        $order = $this->placeUpcountryOrder();

        $this->assertSame('pending_payment', $order['order_status']);
        $this->assertSame('awaiting_confirmation', $order['delivery_method']['delivery_fee_status']);
        $this->assertSame(self::MWANZA_ESTIMATE, $order['delivery_method']['delivery_fee_estimate']);
        $this->assertNull($order['order_expires_at']);                    // no time limit while staff measure
        $this->assertFalse($order['payment']['can_submit_payment']);
        $this->assertTrue($order['can_cancel']);                           // the customer can still walk away

        try {
            (new Payment($this->db))->submitPayment($this->user_id, $order['order_id'], ['payment_payer_account' => '0712000111', 'payment_reference' => 'QJK3X7ABC1']);
            $this->fail('Expected DELIVERY_FEE_NOT_CONFIRMED');
        } catch (ApiException $e) {
            $this->assertSame('DELIVERY_FEE_NOT_CONFIRMED', $e->errorCode());
        }

        $staff_titles = array_column((new AdminNotification($this->db))->getLatest(['admin_id' => self::ADMIN_ID, 'admin_role' => 'super_admin']), 'title');
        $this->assertContains("Transport price to confirm: {$order['order_number']}", $staff_titles);
        $this->assertSame(1, (new Dashboard($this->db))->getAttentionCounts()['transport_to_confirm']);
    }

    public function testStaffConfirmTheEstimateAndTheCustomerCanPay(): void
    {
        $order = $this->placeUpcountryOrder();

        $this->order_manager->confirmDeliveryFee($order['order_id'], ['order_delivery_fee' => self::MWANZA_ESTIMATE], self::ADMIN_ID);

        $order = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame('confirmed', $order['delivery_method']['delivery_fee_status']);
        $this->assertSame(40000 + self::MWANZA_ESTIMATE, $order['order_total']);
        $this->assertNotNull($order['order_expires_at']);                  // the time to pay starts now
        $this->assertTrue($order['payment']['can_submit_payment']);
        $this->assertSame(0, (new Dashboard($this->db))->getAttentionCounts()['transport_to_confirm']);
    }

    public function testABiggerLoadCostsMoreWithAReasonTheCustomerSees(): void
    {
        $order = $this->placeUpcountryOrder();

        try {
            $this->order_manager->confirmDeliveryFee($order['order_id'], ['order_delivery_fee' => 20000], self::ADMIN_ID);
            $this->fail('Expected a validation error: a changed price needs a reason');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('order_delivery_fee_note', $e->fields());
        }

        $this->order_manager->confirmDeliveryFee($order['order_id'], ['order_delivery_fee' => 20000, 'order_delivery_fee_note' => 'Mzigo mkubwa, katoni 6'], self::ADMIN_ID);

        $order = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame([20000, 60000, 'Mzigo mkubwa, katoni 6'], [
            $order['order_delivery_fee'], $order['order_total'], $order['delivery_method']['delivery_fee_note'],
        ]);
        $this->assertStringContainsString('Jumla: 60,000 TZS', (string) $this->db->fetchValue(
            "SELECT notification_body FROM notifications WHERE user_id = {$this->user_id} ORDER BY notification_id DESC LIMIT 1"
        ));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'order.delivery_fee_confirmed' AND audit_log_entity_id = {$order['order_id']}"));

        try {
            $this->order_manager->confirmDeliveryFee($order['order_id'], ['order_delivery_fee' => 1000, 'order_delivery_fee_note' => 'again'], self::ADMIN_ID);
            $this->fail('Expected DELIVERY_FEE_NOT_WAITING');
        } catch (ApiException $e) {
            $this->assertSame('DELIVERY_FEE_NOT_WAITING', $e->errorCode());
        }
    }

    public function testNoCashOnDeliveryWhileThePriceIsToBeConfirmed(): void
    {
        $this->fillCart();
        $total = $this->checkout->preview($this->user_id, ['delivery_method_id' => $this->upcountryId(), 'address_id' => $this->mwanza_address_id])['grand_total'];

        try {
            $this->order_model->placeOrder($this->user_id, [
                'address_id' => $this->mwanza_address_id, 'delivery_method_id' => $this->upcountryId(),
                'payment_method' => 'cod', 'expected_total' => $total,
            ], 'app', null);
            $this->fail('Expected a validation error');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('payment_method', $e->fields());
        }
    }

    public function testARegionWithoutAnEstimateIsPricedByStaff(): void
    {
        $this->db->execute('UPDATE regions SET region_transport_estimate = NULL');
        $this->fillCart();

        $options = $this->checkout->getOptions($this->user_id, ['address_id' => $this->mwanza_address_id]);
        $this->assertNull($options['delivery_methods'][0]['delivery_method_fee']);   // "tutakujulisha bei"

        $order = $this->placeUpcountryOrder(fill_cart: false);
        $this->assertSame([0, 40000], [$order['order_delivery_fee'], $order['order_total']]);
        $this->order_manager->confirmDeliveryFee($order['order_id'], ['order_delivery_fee' => 18000, 'order_delivery_fee_note' => 'Bei ya usafiri hadi Mwanza'], self::ADMIN_ID);
        $this->assertSame(58000, $this->order_model->getOrder($this->user_id, $order['order_id'])['order_total']);
    }

    // ------------------------------------------------------------------ helpers

    private function fillCart(): void
    {
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
    }

    private function placeUpcountryOrder(bool $fill_cart = true): array
    {
        if ($fill_cart) {
            $this->fillCart();
        }
        $total = $this->checkout->preview($this->user_id, ['delivery_method_id' => $this->upcountryId(), 'address_id' => $this->mwanza_address_id])['grand_total'];
        return $this->order_model->placeOrder($this->user_id, [
            'address_id' => $this->mwanza_address_id, 'delivery_method_id' => $this->upcountryId(),
            'payment_method' => 'mpesa', 'expected_total' => $total,
        ], 'app', null);
    }

    private function upcountryId(): int
    {
        return (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'upcountry'");
    }

    private function regionId(string $region_name): int
    {
        return (int) $this->db->fetchValue('SELECT region_id FROM regions WHERE region_name = :name', ['name' => $region_name]);
    }
}
