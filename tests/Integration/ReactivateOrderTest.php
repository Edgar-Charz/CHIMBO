<?php

use PHPUnit\Framework\TestCase;

/**
 * Reactivating an expired order (blueprint R-28, §12.10): staff bring it back at today's prices, with stock reserved
 * again and a new time to pay. Also: staff-created orders follow the delivery rules (Bolt, upcountry, pickup).
 */
final class ReactivateOrderTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const VASELINE = 1;           // 8 pieces = 40,000 (6+ level: 5,000 each)

    private Database $db;
    private Order $order_model;
    private OrderManager $order_manager;
    private int $user_id;
    private int $address_id;
    private array $original_products;
    private array $original_tiers;

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
        $this->original_tiers    = $this->db->fetchAll('SELECT product_id, tier_min_quantity, tier_unit_price FROM product_price_tiers WHERE product_id = ' . self::VASELINE);

        $this->order_model   = new Order($this->db);
        $this->order_manager = new OrderManager($this->db);
        $this->user_id       = (new User($this->db))->findOrCreateUserIdByPhone('+255712000333');
        $this->address_id    = (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Neema Ally',
            'address_phone'          => '0712000333',
            'region_id'              => $this->regionId('Dar es Salaam'),
            'address_street'         => 'Kariakoo',
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
        foreach ($this->original_tiers as $tier) {
            $this->db->execute(
                'UPDATE product_price_tiers SET tier_unit_price = :tier_unit_price WHERE product_id = :product_id AND tier_min_quantity = :tier_min_quantity',
                $tier
            );
        }
        $this->db->execute("UPDATE payment_methods SET payment_method_is_active = (payment_method_code = 'cod'),
                                   payment_method_account_name = NULL, payment_method_account_number = NULL");
        PaymentMethod::clearCache();
    }

    public function testAnExpiredOrderComesBackAtTodaysPricesWithANewTimeToPay(): void
    {
        $order_id = $this->expiredOrder();
        $stock_while_expired = $this->stock();
        $this->db->execute('UPDATE product_price_tiers SET tier_unit_price = 5500 WHERE product_id = ' . self::VASELINE . ' AND tier_min_quantity = 6');

        $preview = $this->order_manager->previewReactivation($order_id);
        $this->assertTrue($preview['can_reactivate']);
        $this->assertSame([5000, 5500], [$preview['lines'][0]['old_unit_price'], $preview['lines'][0]['new_unit_price']]);
        $this->assertSame([40000, 44000], [$preview['old_total'], $preview['new_total']]);

        $this->order_manager->reactivateOrder($order_id, ['status_note' => 'Mteja alipiga simu'], self::ADMIN_ID);

        $order = $this->order_model->getOrder($this->user_id, $order_id);
        $this->assertSame(['pending_payment', 'unpaid', 44000, 5500], [
            $order['order_status'], $order['order_payment_status'], $order['order_total'], $order['items'][0]['order_item_unit_price'],
        ]);
        $this->assertGreaterThan(time(), strtotime($order['order_expires_at']));
        $this->assertTrue($order['payment']['can_submit_payment']);                 // the pay box is back
        $this->assertSame($stock_while_expired - 8, $this->stock());                // reserved again
        $this->assertSame('Imerejeshwa na CHIMBO. Mteja alipiga simu', end($order['events'])['status_note']);
        $this->assertStringContainsString('Jumla: TZS 44,000', (string) $this->db->fetchValue(
            "SELECT notification_body FROM notifications WHERE user_id = {$this->user_id} ORDER BY notification_id DESC LIMIT 1"
        ));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'order.reactivated' AND audit_log_entity_id = {$order_id}"));
    }

    public function testShortStockStopsItWithTheReason(): void
    {
        $order_id = $this->expiredOrder();
        $this->db->execute('UPDATE products SET product_stock_quantity = 3 WHERE product_id = ' . self::VASELINE);

        $preview = $this->order_manager->previewReactivation($order_id);
        $this->assertFalse($preview['can_reactivate']);
        $this->assertStringContainsString('only 3 available', $preview['problems'][0]);

        try {
            $this->order_manager->reactivateOrder($order_id, [], self::ADMIN_ID);
            $this->fail('Expected CANNOT_REACTIVATE');
        } catch (ApiException $e) {
            $this->assertSame('CANNOT_REACTIVATE', $e->errorCode());
        }
        $this->assertSame('expired', $this->order_model->getOrder($this->user_id, $order_id)['order_status']);
    }

    public function testOnlyExpiredOrdersCanBeReactivated(): void
    {
        $order = $this->placeOrder();
        $this->order_model->cancelOrder($this->user_id, $order['order_id'], []);

        try {
            $this->order_manager->reactivateOrder($order['order_id'], [], self::ADMIN_ID);
            $this->fail('Expected ORDER_NOT_EXPIRED');
        } catch (ApiException $e) {
            $this->assertSame('ORDER_NOT_EXPIRED', $e->errorCode());
        }
    }

    public function testAStaffOrderFollowsTheDeliveryRules(): void
    {
        $this->db->execute("UPDATE regions SET region_transport_estimate = 15000 WHERE region_name = 'Mwanza'");
        $form = fn (string $region, string $method_code): array => [
            'user_full_name' => 'Mama Asha', 'user_phone' => '0754 999 888', 'address_recipient_name' => 'Mama Asha',
            'address_phone' => '0754 999 888', 'region_id' => $this->regionId($region), 'address_street' => 'Sokoni',
            'delivery_method_id' => (int) $this->db->fetchValue('SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = :c', ['c' => $method_code]),
            'payment_method' => 'mpesa', 'items' => [['product_id' => self::VASELINE, 'quantity' => 8]],
        ];

        $bolt = $this->order_manager->getOrderForAdmin($this->order_manager->createManualOrder($form('Dar es Salaam', 'standard'), self::ADMIN_ID));
        $this->assertTrue($bolt['delivery_method']['delivery_fee_paid_to_rider']);
        $this->assertSame(40000, $bolt['order_total']);

        $upcountry = $this->order_manager->getOrderForAdmin($this->order_manager->createManualOrder($form('Mwanza', 'upcountry'), self::ADMIN_ID));
        $this->assertSame('awaiting_confirmation', $upcountry['delivery_method']['delivery_fee_status']);
        $this->assertSame(55000, $upcountry['order_total']);

        $pickup = $this->order_manager->getOrderForAdmin($this->order_manager->createManualOrder($form('Mwanza', 'pickup'), self::ADMIN_ID));
        $this->assertTrue($pickup['delivery_method']['delivery_method_is_pickup']);
        $this->db->execute('UPDATE regions SET region_transport_estimate = NULL');
    }

    // ------------------------------------------------------------------ helpers

    private function placeOrder(): array
    {
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $method = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'standard'");
        $total  = (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => $method, 'address_id' => $this->address_id])['grand_total'];
        return $this->order_model->placeOrder($this->user_id, [
            'address_id' => $this->address_id, 'delivery_method_id' => $method, 'payment_method' => 'mpesa', 'expected_total' => $total,
        ], 'app', null);
    }

    /** An unpaid order whose time to pay ran out, expired by the scheduled job. */
    private function expiredOrder(): int
    {
        $order_id = $this->placeOrder()['order_id'];
        $this->db->execute("UPDATE orders SET order_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE order_id = {$order_id}");
        $this->order_manager->expireOverdueOrders(null);
        $this->assertSame('expired', $this->order_model->getOrder($this->user_id, $order_id)['order_status']);
        return $order_id;
    }

    private function stock(): int
    {
        return (int) $this->db->fetchValue('SELECT product_stock_quantity FROM products WHERE product_id = ' . self::VASELINE);
    }

    private function regionId(string $region_name): int
    {
        return (int) $this->db->fetchValue('SELECT region_id FROM regions WHERE region_name = :name', ['name' => $region_name]);
    }
}
