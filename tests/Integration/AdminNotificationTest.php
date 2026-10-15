<?php

use PHPUnit\Framework\TestCase;

/** The staff bell: who sees which notification, read / unread per staff member, and the events that create them. */
final class AdminNotificationTest extends TestCase
{
    private const VASELINE = 1;   // stock 500, MOQ 1

    private Database $db;
    private AdminNotification $notifications;
    private array $super_admin;
    private array $operations;
    private array $catalog;
    private array $original_products;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        $this->db->execute('DELETE FROM admins WHERE admin_id > 1');
        $this->db->execute(
            "INSERT IGNORE INTO admins (admin_id, admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (1, 'Test Admin', 'test-admin@chimbo.test', 'x', 'super_admin')"
        );
        $this->super_admin = ['admin_id' => 1, 'admin_role' => 'super_admin'];
        $this->operations  = ['admin_id' => $this->createAdmin('operations'), 'admin_role' => 'operations'];
        $this->catalog     = ['admin_id' => $this->createAdmin('catalog'), 'admin_role' => 'catalog'];
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');
        $this->notifications = new AdminNotification($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->original_products as $product) {
            $this->db->execute(
                'UPDATE products SET product_stock_quantity = :product_stock_quantity, product_sold_count = :product_sold_count WHERE product_id = :product_id',
                $product
            );
        }
        $this->db->execute('DELETE FROM admin_notification_reads');
        $this->db->execute('DELETE FROM admins WHERE admin_id > 1');
    }

    public function testANewOrderIsForOrderStaffOnly(): void
    {
        $this->placeCashOrder(8);

        $latest = $this->notifications->getLatest($this->operations);
        $this->assertSame('order.placed', $latest[0]['type']);
        $this->assertStringStartsWith('New order CHB', $latest[0]['title']);
        $this->assertStringContainsString('40,000 TZS', $latest[0]['body']);   // Bolt is paid to the rider
        $this->assertSame([], $this->notifications->getLatest($this->catalog));   // catalog staff don't handle orders
        $this->assertCount(1, $this->notifications->getLatest($this->super_admin));
    }

    public function testReadIsPerStaffMember(): void
    {
        $this->placeCashOrder(8);
        $notification_id = $this->notifications->getLatest($this->operations)[0]['admin_notification_id'];

        $page = $this->notifications->open($this->operations, $notification_id);

        $this->assertMatchesRegularExpression('/^order_details\.php\?id=\d+$/', $page);
        $this->assertSame(0, $this->notifications->countUnread($this->operations));
        $this->assertSame(1, $this->notifications->countUnread($this->super_admin));   // still unread for the others
        $this->assertTrue($this->notifications->getLatest($this->operations)[0]['is_read']);
    }

    public function testStaffCannotOpenANotificationThatIsNotForThem(): void
    {
        $this->placeCashOrder(8);
        $notification_id = $this->notifications->getLatest($this->operations)[0]['admin_notification_id'];

        $this->expectExceptionObject(ApiException::notFound('Notification not found.'));
        $this->notifications->open($this->catalog, $notification_id);
    }

    public function testMarkAllAsReadAndTheUnreadFilter(): void
    {
        $this->placeCashOrder(8);
        $this->placeCashOrder(2);
        $this->assertSame(2, $this->notifications->search($this->operations, ['only_unread' => '1'])['total']);

        $this->notifications->markAllRead($this->operations);

        $this->assertSame(0, $this->notifications->countUnread($this->operations));
        $this->assertSame(0, $this->notifications->search($this->operations, ['only_unread' => '1'])['total']);
        $this->assertSame(2, $this->notifications->search($this->operations, [])['total']);
    }

    public function testStockRunningLowTellsStockStaffOnceAndSoldOutAgain(): void
    {
        $editor = new ProductEditor($this->db);
        $editor->changeStock(self::VASELINE, -485, 'adjustment', 'test', 1);   // 500 → 15: low
        $editor->changeStock(self::VASELINE, -5, 'adjustment', 'test', 1);     // 15 → 10: still low, no new notification
        $editor->changeStock(self::VASELINE, -10, 'adjustment', 'test', 1);    // 10 → 0: sold out

        $titles = array_column($this->notifications->getLatest($this->catalog), 'title');
        $this->assertSame(['Sold out: Vaseline Petroleum Jelly 400ml', 'Low stock: Vaseline Petroleum Jelly 400ml'], $titles);
        $this->assertSame([], $this->notifications->getLatest($this->operations));   // operations staff don't manage stock
    }

    public function testPaymentToCheckAndNewCustomerGoToTheRightStaff(): void
    {
        $user_model = new User($this->db);
        $user_id    = $user_model->findOrCreateUserIdByPhone('+255712000777');
        $profile    = ['user_full_name' => 'Asha Musa', 'business_name' => 'Duka la Asha', 'region_id' => $this->darEsSalaamId()];
        $user_model->completeProfile($user_id, $profile);
        $user_model->completeProfile($user_id, ['user_full_name' => 'Asha M. Musa'] + $profile);   // an edit: no second notification

        $customer_titles = array_column($this->notifications->getLatest($this->operations), 'title');
        $this->assertSame(['New customer: Asha Musa'], $customer_titles);

        $this->notifications->notify('payment.submitted', 'Payment to check: CHB000001', 'M-Pesa · 45,000 TZS', 'payments.php?tab=review');
        $finance = ['admin_id' => 99, 'admin_role' => 'finance'];
        $this->assertContains('Payment to check: CHB000001', array_column($this->notifications->getLatest($finance), 'title'));
        $this->assertNotContains('payment.submitted', array_column($this->notifications->getLatest($this->operations), 'type'));
    }

    public function testOnlyAdminPagesCanBeOpened(): void
    {
        $this->assertSame('order_details.php?id=12', AdminNotification::safeLink('order_details.php?id=12'));
        $this->assertSame('payments.php?tab=review', AdminNotification::safeLink('payments.php?tab=review'));
        foreach (['https://evil.example/x', '//evil.example', '../index.php', '/admin/x.php', 'javascript:alert(1)', null] as $bad_link) {
            $this->assertSame('index.php', AdminNotification::safeLink($bad_link), (string) $bad_link);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function createAdmin(string $role): int
    {
        return $this->db->insert(
            "INSERT INTO admins (admin_full_name, admin_email, admin_password_hash, admin_role) VALUES (:name, :email, 'x', :role)",
            ['name' => ucfirst($role) . ' Staff', 'email' => "{$role}@chimbo.test", 'role' => $role]
        );
    }

    private function darEsSalaamId(): int
    {
        return (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'");
    }

    /** A cash order of Vaseline for a new customer (standard delivery). */
    private function placeCashOrder(int $quantity): array
    {
        static $customer_number = 0;
        $user_id    = (new User($this->db))->findOrCreateUserIdByPhone(sprintf('+25571300%04d', ++$customer_number));
        $address_id = (new Address($this->db))->createAddress($user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => $this->darEsSalaamId(),
            'address_street'         => 'Kariakoo',
        ])['address_id'];
        (new Cart($this->db))->addItem($user_id, ['product_id' => self::VASELINE, 'quantity' => $quantity]);
        $total = (new Checkout($this->db))->preview($user_id, ['delivery_method_id' => 1, 'address_id' => $address_id])['grand_total'];

        return (new Order($this->db))->placeOrder($user_id, [
            'address_id' => $address_id, 'delivery_method_id' => 1, 'payment_method' => 'cod', 'expected_total' => $total,
        ], 'app', null);
    }
}
