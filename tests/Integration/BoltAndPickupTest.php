<?php

use PHPUnit\Framework\TestCase;

/**
 * Dar by Bolt, paid by the customer to the rider, and pickup at the shop (blueprint R-26, §12.8).
 */
final class BoltAndPickupTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const VASELINE = 1;           // 8 pieces = 40,000

    private Database $db;
    private Checkout $checkout;
    private Order $order_model;
    private OrderManager $order_manager;
    private int $user_id;
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
        $this->db->execute("UPDATE settings SET setting_value = 'Kariakoo, Mtaa wa Congo' WHERE setting_key = 'pickup_address'");
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');

        $this->checkout      = new Checkout($this->db);
        $this->order_model   = new Order($this->db);
        $this->order_manager = new OrderManager($this->db);
        $this->user_id       = (new User($this->db))->findOrCreateUserIdByPhone('+255712000222');
    }

    protected function tearDown(): void
    {
        foreach ($this->original_products as $product) {
            $this->db->execute(
                'UPDATE products SET product_stock_quantity = :product_stock_quantity, product_sold_count = :product_sold_count WHERE product_id = :product_id',
                $product
            );
        }
        $this->db->execute("UPDATE settings SET setting_value = 'Kariakoo, Dar es Salaam' WHERE setting_key = 'pickup_address'");
        $this->db->execute("UPDATE payment_methods SET payment_method_is_active = (payment_method_code = 'cod'),
                                   payment_method_account_name = NULL, payment_method_account_number = NULL");
        Settings::clearCache();
        PaymentMethod::clearCache();
    }

    public function testBoltIsPaidToTheRiderSoCHIMBOsTotalIsTheProductsOnly(): void
    {
        $address_id = $this->addressIn('Dar es Salaam');
        $order = $this->placeOrder('standard', $address_id);

        $this->assertSame([0, 40000], [$order['order_delivery_fee'], $order['order_total']]);
        $this->assertTrue($order['delivery_method']['delivery_fee_paid_to_rider']);
        $this->assertFalse($order['delivery_method']['delivery_method_is_pickup']);
        $this->assertSame(40000, $order['payment']['payment_amount']);
        $this->assertTrue($order['payment']['can_submit_payment']);       // pay CHIMBO for the products now
        $this->assertNull($order['pickup_point']);
    }

    public function testPickupIsFreeEverywhereAndShowsWhereToCollect(): void
    {
        $address_id = $this->addressIn('Mwanza');
        $options = $this->checkout->getOptions($this->user_id, ['address_id' => $address_id]);
        $pickup  = array_column($options['delivery_methods'], null, 'delivery_method_code')['pickup'];

        $this->assertTrue($pickup['delivery_method_is_pickup']);
        $this->assertSame(0, $pickup['delivery_method_fee']);
        $this->assertSame('Kariakoo, Mtaa wa Congo', $options['pickup_point']['pickup_address']);

        $order = $this->placeOrder('pickup', $address_id);
        $this->assertSame(40000, $order['order_total']);
        $this->assertTrue($order['delivery_method']['delivery_method_is_pickup']);
        $this->assertSame('Kariakoo, Mtaa wa Congo', $order['pickup_point']['pickup_address']);
    }

    public function testAPickupOrderGoesFromReadyToCollectedAndTheCustomerIsTold(): void
    {
        $order    = $this->placeOrder('pickup', $this->addressIn('Dar es Salaam'));
        $order_id = $order['order_id'];
        $this->payAndConfirm($order_id);

        $this->order_manager->changeStatus($order_id, ['order_status' => 'packed'], self::ADMIN_ID);
        $this->assertSame(['delivered', 'cancelled'], $this->order_manager->getOrderForAdmin($order_id)['allowed_next_statuses']);
        $this->assertStringContainsString('Njoo uichukue: Kariakoo, Mtaa wa Congo', $this->latestNotificationBody());

        try {
            $this->order_manager->changeStatus($order_id, ['order_status' => 'dispatched'], self::ADMIN_ID);
            $this->fail('A pickup order is never dispatched');
        } catch (ApiException $e) {
            $this->assertSame('STATUS_CHANGE_NOT_ALLOWED', $e->errorCode());
        }

        $this->order_manager->changeStatus($order_id, ['order_status' => 'delivered'], self::ADMIN_ID);
        $this->assertSame('delivered', $this->order_model->getOrder($this->user_id, $order_id)['order_status']);
        $this->assertStringContainsString('Umechukua oda', $this->latestNotificationBody());
    }

    public function testADeliveryOrderStillGoesThroughDispatch(): void
    {
        $order = $this->placeOrder('standard', $this->addressIn('Dar es Salaam'));
        $this->payAndConfirm($order['order_id']);
        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'packed'], self::ADMIN_ID);

        $this->assertSame(['dispatched', 'cancelled'], $this->order_manager->getOrderForAdmin($order['order_id'])['allowed_next_statuses']);
        $this->assertStringContainsString('itatumwa hivi karibuni', $this->latestNotificationBody());
    }

    // ------------------------------------------------------------------ helpers

    private function addressIn(string $region_name): int
    {
        $region_id = (int) $this->db->fetchValue('SELECT region_id FROM regions WHERE region_name = :name', ['name' => $region_name]);
        return (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Asha Musa',
            'address_phone'          => '0712000222',
            'region_id'              => $region_id,
            'address_street'         => 'Mtaa wa Uhuru',
        ])['address_id'];
    }

    private function placeOrder(string $delivery_code, int $address_id): array
    {
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $method_id = (int) $this->db->fetchValue('SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = :code', ['code' => $delivery_code]);
        $total     = $this->checkout->preview($this->user_id, ['delivery_method_id' => $method_id, 'address_id' => $address_id])['grand_total'];

        return $this->order_model->placeOrder($this->user_id, [
            'address_id' => $address_id, 'delivery_method_id' => $method_id, 'payment_method' => 'mpesa', 'expected_total' => $total,
        ], 'app', null);
    }

    private function payAndConfirm(int $order_id): void
    {
        $order = (new Payment($this->db))->submitPayment($this->user_id, $order_id, ['payment_payer_account' => '0712000222', 'payment_reference' => 'QJK3X7ABC2']);
        (new Payment($this->db))->confirmPayment($order['payment']['latest_payment']['payment_id'], self::ADMIN_ID);
    }

    private function latestNotificationBody(): string
    {
        return (string) $this->db->fetchValue(
            "SELECT notification_body FROM notifications WHERE user_id = {$this->user_id} ORDER BY notification_id DESC LIMIT 1"
        );
    }
}
