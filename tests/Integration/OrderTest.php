<?php

use PHPUnit\Framework\TestCase;

/** Oda: placing orders, stock, cancel, reorder, staff status changes, cash on delivery, notifications. */
final class OrderTest extends TestCase
{
    private const VASELINE = 1;   // tiers 1:5,500 · 6:5,000 …, MOQ 1
    private const NECKLACE = 7;   // tiers 6:8,500 …, MOQ 6
    private const ADMIN_ID = 1;

    private Database $db;
    private Order $order_model;
    private OrderManager $order_manager;
    private int $user_id;
    private int $address_id;
    private array $original_products;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        $this->db->execute(
            "INSERT IGNORE INTO admins (admin_id, admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (1, 'Test Admin', 'test-admin@chimbo.test', 'x', 'super_admin')"
        );
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');

        $this->order_model   = new Order($this->db);
        $this->order_manager = new OrderManager($this->db);
        $this->user_id       = (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');
        $this->address_id    = (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'"),
            'address_street'         => 'Kariakoo, Lumumba Street',
        ])['address_id'];
    }

    protected function tearDown(): void
    {
        foreach ($this->original_products as $product) {
            $this->db->execute(
                'UPDATE products SET product_stock_quantity = :stock, product_sold_count = :sold WHERE product_id = :id',
                ['stock' => $product['product_stock_quantity'], 'sold' => $product['product_sold_count'], 'id' => $product['product_id']]
            );
        }
        $this->db->execute("UPDATE settings SET setting_value = '300000' WHERE setting_key = 'cod_max_order_total'");
    }

    public function testPlacingAnOrderCopiesPricesReservesStockAndEmptiesTheCart(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8, self::NECKLACE => 6]);

        $this->assertMatchesRegularExpression('/^CHB\d{6}$/', $order['order_number']);
        $this->assertSame('confirmed', $order['order_status']);          // cash on delivery starts confirmed
        $this->assertSame('cod_pending', $order['order_payment_status']);
        $this->assertSame(8 * 5000 + 6 * 8500 + 5000, $order['order_total']);
        $this->assertSame([5000, 8500], array_column($order['items'], 'order_item_unit_price'));
        $this->assertSame('Kariakoo, Lumumba Street', $order['address']['address_street']);
        $this->assertSame(['confirmed'], array_column($order['events'], 'order_status'));
        $this->assertSame($this->originalStock(self::VASELINE) - 8, $this->currentStock(self::VASELINE));
        $this->assertSame(0, (new Cart($this->db))->getCart($this->user_id)['summary']['line_count']);
    }

    public function testLaterPriceChangesDontChangeAPlacedOrder(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8]);
        $this->db->execute('UPDATE product_price_tiers SET tier_unit_price = tier_unit_price + 1000 WHERE product_id = ' . self::VASELINE);

        try {
            $this->assertSame(5000, $this->order_model->getOrder($this->user_id, $order['order_id'])['items'][0]['order_item_unit_price']);
        } finally {
            $this->db->execute('UPDATE product_price_tiers SET tier_unit_price = tier_unit_price - 1000 WHERE product_id = ' . self::VASELINE);
        }
    }

    public function testDifferentTotalFromWhatTheCustomerSawIsRefused(): void
    {
        $this->fillCart([self::VASELINE => 8]);

        $this->assertApiError('PRICE_CHANGED', fn () => $this->order_model->placeOrder($this->user_id, $this->orderForm(12345), 'app', null));
        $this->assertSame($this->originalStock(self::VASELINE), $this->currentStock(self::VASELINE)); // nothing was taken
    }

    public function testSameIdempotencyKeyReturnsTheSameOrder(): void
    {
        $first = $this->placeOrder([self::VASELINE => 2], 'checkout-key-1234');
        $this->fillCart([self::VASELINE => 2]);

        $second = $this->order_model->placeOrder($this->user_id, $this->orderForm($first['order_total']), 'app', 'checkout-key-1234');

        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders'));
    }

    public function testDisabledPaymentMethodIsRefused(): void
    {
        $this->fillCart([self::VASELINE => 2]);
        $form = ['payment_method' => 'mpesa'] + $this->orderForm($this->previewTotal());

        $this->assertApiError('VALIDATION_ERROR', fn () => $this->order_model->placeOrder($this->user_id, $form, 'app', null));
    }

    public function testCashOnDeliveryHasALimit(): void
    {
        $this->db->execute("UPDATE settings SET setting_value = '10000' WHERE setting_key = 'cod_max_order_total'");
        Settings::clearCache();
        $this->fillCart([self::VASELINE => 8]);

        $this->assertApiError('VALIDATION_ERROR', fn () => $this->order_model->placeOrder($this->user_id, $this->orderForm($this->previewTotal()), 'app', null));
    }

    public function testCustomerCancelsBeforePackingAndStockComesBack(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8]);

        $order = $this->order_model->cancelOrder($this->user_id, $order['order_id'], ['order_cancel_reason' => 'Nimebadili mawazo']);

        $this->assertSame('cancelled', $order['order_status']);
        $this->assertSame($this->originalStock(self::VASELINE), $this->currentStock(self::VASELINE));
    }

    public function testCustomerCannotCancelAfterPacking(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8]);
        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'packed'], self::ADMIN_ID);

        $this->assertApiError('ORDER_NOT_CANCELLABLE', fn () => $this->order_model->cancelOrder($this->user_id, $order['order_id'], []));
    }

    public function testStaffMoveTheOrderThroughTheTimelineAndConfirmCash(): void
    {
        $order    = $this->placeOrder([self::VASELINE => 8]);
        $agent_id = (new DeliveryAgent($this->db))->createAgent(
            ['delivery_agent_full_name' => 'Juma Mbeva', 'delivery_agent_phone' => '0712987654', 'delivery_agent_is_active' => '1'],
            self::ADMIN_ID
        );

        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'packed'], self::ADMIN_ID);
        $this->assertApiError('VALIDATION_ERROR', fn () => $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'dispatched'], self::ADMIN_ID));
        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'dispatched', 'delivery_agent_id' => $agent_id], self::ADMIN_ID);
        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'in_transit'], self::ADMIN_ID);
        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'delivered'], self::ADMIN_ID);

        $tracked = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame(['confirmed', 'packed', 'dispatched', 'in_transit', 'delivered'], array_column($tracked['events'], 'order_status'));
        $this->assertSame('Juma Mbeva', $tracked['delivery_agent']['delivery_agent_full_name']);

        $this->assertApiError('VALIDATION_ERROR', fn () => $this->order_manager->confirmCashCollected($order['order_id'], ['delivery_cash_collected' => 1000], self::ADMIN_ID));
        $this->order_manager->confirmCashCollected($order['order_id'], ['delivery_cash_collected' => $order['order_total']], self::ADMIN_ID);
        $this->assertSame('paid', $this->order_model->getOrder($this->user_id, $order['order_id'])['order_payment_status']);
    }

    public function testSkippingStepsIsRefused(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8]);

        $this->assertApiError('STATUS_CHANGE_NOT_ALLOWED', fn () => $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'delivered'], self::ADMIN_ID));
    }

    public function testEachStatusChangeNotifiesTheCustomer(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8]);
        $this->order_manager->changeStatus($order['order_id'], ['order_status' => 'packed'], self::ADMIN_ID);

        $notifications = new Notification($this->db);
        $this->assertSame(2, $notifications->getUnreadCount($this->user_id)); // confirmed + packed
        $notifications->markAllAsRead($this->user_id);
        $this->assertSame(0, $notifications->getUnreadCount($this->user_id));
    }

    public function testReorderAndRecentlyOrdered(): void
    {
        $order = $this->placeOrder([self::VASELINE => 8, self::NECKLACE => 6]);

        $cart = $this->order_model->reorder($this->user_id, $order['order_id']);
        $recent = $this->order_model->getRecentlyOrderedProducts($this->user_id, 10);

        $this->assertSame(2, $cart['summary']['line_count']);
        $this->assertEqualsCanonicalizing([self::VASELINE, self::NECKLACE], array_column($recent, 'product_id'));
    }

    public function testAnotherCustomersOrderIsNotFound(): void
    {
        $order = $this->placeOrder([self::VASELINE => 2]);
        $other_user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255754000222');

        $this->assertApiError('NOT_FOUND', fn () => $this->order_model->getOrder($other_user_id, $order['order_id']));
    }

    public function testCancelledCashOrderNoLongerOwesMoney(): void
    {
        $order = $this->placeOrder([self::VASELINE => 2]);

        $order = $this->order_model->cancelOrder($this->user_id, $order['order_id'], []);

        $this->assertSame('cancelled', $order['order_payment_status']);
    }

    public function testStaffCanCreateAnOrderForAPhoneCustomer(): void
    {
        $order_id = $this->order_manager->createManualOrder([
            'user_full_name'         => 'Mama Asha',
            'user_phone'             => '0754 999 888',
            'address_recipient_name' => 'Mama Asha',
            'address_phone'          => '0754 999 888',
            'region_id'              => (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'"),
            'address_street'         => 'Mbagala, Rangi Tatu',
            'delivery_method_id'     => 1,
            'payment_method'         => 'cod',
            'items'                  => [['product_id' => self::VASELINE, 'quantity' => 8]],
        ], self::ADMIN_ID);

        $order = $this->order_manager->getOrderForAdmin($order_id);
        $this->assertMatchesRegularExpression('/^CHB\d{6}$/', $order['order_number']);
        $this->assertSame('confirmed', $order['order_status']);
        $this->assertSame(8 * 5000 + 5000, $order['order_total']);
        $this->assertSame(['confirmed'], array_column($order['events'], 'order_status'));
        $this->assertSame($this->originalStock(self::VASELINE) - 8, $this->currentStock(self::VASELINE));
        $this->assertSame(1, (new Notification($this->db))->getUnreadCount((int) $order['customer']['user_id']));
    }

    public function testAdminOrderListSortsAndRejectsUnknownColumns(): void
    {
        $this->placeOrder([self::VASELINE => 2]);
        $this->placeOrder([self::VASELINE => 30]);

        $totals = array_column($this->order_manager->getOrdersForAdmin(['sort' => 'order_total', 'direction' => 'asc'])['items'], 'order_total');
        $sorted_totals = $totals;
        sort($sorted_totals);
        $this->assertSame($sorted_totals, $totals);

        $this->assertApiError('VALIDATION_ERROR', fn () => $this->order_manager->getOrdersForAdmin(['sort' => 'user_id']));
    }

    public function testOrdersListByTab(): void
    {
        $this->placeOrder([self::VASELINE => 2]);

        $this->assertSame(1, $this->order_model->getOrders($this->user_id, ['group' => 'active'])['total']);
        $this->assertSame(0, $this->order_model->getOrders($this->user_id, ['group' => 'delivered'])['total']);
    }

    // ------------------------------------------------------------------ helpers

    /** Fills the cart and places a cash-on-delivery order with the correct total. */
    private function placeOrder(array $quantities, ?string $idempotency_key = null): array
    {
        $this->fillCart($quantities);
        return $this->order_model->placeOrder($this->user_id, $this->orderForm($this->previewTotal()), 'app', $idempotency_key);
    }

    private function fillCart(array $quantities): void
    {
        foreach ($quantities as $product_id => $quantity) {
            (new Cart($this->db))->addItem($this->user_id, ['product_id' => $product_id, 'quantity' => $quantity]);
        }
    }

    private function previewTotal(): int
    {
        return (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => 1, 'address_id' => $this->address_id])['grand_total'];
    }

    private function orderForm(int $expected_total): array
    {
        return ['address_id' => $this->address_id, 'delivery_method_id' => 1, 'payment_method' => 'cod', 'expected_total' => $expected_total];
    }

    private function originalStock(int $product_id): int
    {
        foreach ($this->original_products as $product) {
            if ((int) $product['product_id'] === $product_id) {
                return (int) $product['product_stock_quantity'];
            }
        }
        return 0;
    }

    private function currentStock(int $product_id): int
    {
        return (int) $this->db->fetchValue('SELECT product_stock_quantity FROM products WHERE product_id = :id', ['id' => $product_id]);
    }

    private function assertApiError(string $expected_code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the error {$expected_code}");
        } catch (ApiException $e) {
            $this->assertSame($expected_code, $e->errorCode());
        }
    }
}
