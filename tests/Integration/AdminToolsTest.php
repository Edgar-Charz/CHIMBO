<?php

use PHPUnit\Framework\TestCase;

/** The admin tools behind Admin users, Audit log, Reports and Settings. */
final class AdminToolsTest extends TestCase
{
    private const ADMIN_ID = 1;       // the super admin doing the changes
    private const VASELINE = 1;       // tiers 1:5,500 · 6:5,000 · 24:4,700

    private Database $db;
    private AdminUser $admin_user_model;
    private array $original_settings;
    private array $original_products;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        $this->db->execute('DELETE FROM admins WHERE admin_id <> ' . self::ADMIN_ID);
        $this->db->execute(
            "INSERT IGNORE INTO admins (admin_id, admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (1, 'Test Admin', 'test-admin@chimbo.test', 'x', 'super_admin')"
        );
        $this->db->execute("UPDATE admins SET admin_role = 'super_admin', admin_status = 'active' WHERE admin_id = " . self::ADMIN_ID);

        $this->original_settings = $this->db->fetchAll('SELECT setting_key, setting_value FROM settings');
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');
        $this->admin_user_model  = new AdminUser($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->original_settings as $setting) {
            $this->db->execute('UPDATE settings SET setting_value = :setting_value WHERE setting_key = :setting_key', $setting);
        }
        foreach ($this->original_products as $product) {
            $this->db->execute(
                'UPDATE products SET product_stock_quantity = :product_stock_quantity, product_sold_count = :product_sold_count WHERE product_id = :product_id',
                $product
            );
        }
        $this->db->execute("DELETE FROM delivery_methods WHERE delivery_method_code NOT IN ('standard', 'upcountry', 'pickup')");
        $this->db->execute("UPDATE delivery_methods SET delivery_method_fee = 0, delivery_method_pricing = 'paid_to_rider' WHERE delivery_method_code = 'standard'");
        Settings::clearCache();
    }

    // ------------------------------------------------------------------ admin users

    public function testSuperAdminCreatesAStaffAccountThatCanLogIn(): void
    {
        $admin_id = $this->admin_user_model->createAdmin($this->staffForm(), self::ADMIN_ID);

        $admin = (new Admin($this->db))->login(['admin_email' => 'Neema@Chimbo.test', 'admin_password' => 'a-long-password']);

        $this->assertSame($admin_id, (int) $admin['admin_id']);
        $this->assertSame('operations', $admin['admin_role']);
        $this->assertArrayNotHasKey('admin_password_hash', $this->admin_user_model->getAdminById($admin_id));
    }

    public function testShortPasswordsMismatchesAndUsedEmailsAreRefused(): void
    {
        $this->assertFieldError('admin_password', fn () => $this->admin_user_model->createAdmin($this->staffForm(['admin_password' => 'short', 'admin_password_confirmation' => 'short']), self::ADMIN_ID));
        $this->assertFieldError('admin_password_confirmation', fn () => $this->admin_user_model->createAdmin($this->staffForm(['admin_password_confirmation' => 'something-else']), self::ADMIN_ID));

        $this->admin_user_model->createAdmin($this->staffForm(), self::ADMIN_ID);
        $this->assertFieldError('admin_email', fn () => $this->admin_user_model->createAdmin($this->staffForm(), self::ADMIN_ID));
    }

    public function testNobodyCanDisableOrDemoteThemselvesAndASuperAdminAlwaysRemains(): void
    {
        $self_form = ['admin_full_name' => 'Test Admin', 'admin_email' => 'test-admin@chimbo.test', 'admin_role' => 'super_admin'];

        $this->assertFieldError('admin_status', fn () => $this->admin_user_model->updateAdmin(self::ADMIN_ID, $self_form + ['admin_status' => 'disabled'], self::ADMIN_ID));
        $this->assertFieldError('admin_role', fn () => $this->admin_user_model->updateAdmin(self::ADMIN_ID, ['admin_role' => 'finance'] + $self_form + ['admin_status' => 'active'], self::ADMIN_ID));

        // Another super admin may not remove the last one either
        $other_id = $this->admin_user_model->createAdmin($this->staffForm(['admin_role' => 'catalog']), self::ADMIN_ID);
        $this->assertFieldError('admin_role', fn () => $this->admin_user_model->updateAdmin(self::ADMIN_ID, ['admin_role' => 'finance'] + $self_form + ['admin_status' => 'active'], $other_id));
        $this->assertSame('super_admin', $this->admin_user_model->getAdminById(self::ADMIN_ID)['admin_role']);
    }

    public function testPasswordResetUnlocksTheAccountAndIsAudited(): void
    {
        $admin_id = $this->admin_user_model->createAdmin($this->staffForm(), self::ADMIN_ID);
        $this->db->execute('UPDATE admins SET admin_locked_until = UTC_TIMESTAMP() + INTERVAL 10 MINUTE WHERE admin_id = ' . $admin_id);

        $this->admin_user_model->resetPassword($admin_id, ['admin_password' => 'another-long-one', 'admin_password_confirmation' => 'another-long-one'], self::ADMIN_ID);

        (new Admin($this->db))->login(['admin_email' => 'neema@chimbo.test', 'admin_password' => 'another-long-one']);
        $log = (new AuditLog($this->db))->search(['action' => 'admin.password_reset', 'entity_id' => $admin_id]);
        $this->assertSame(1, $log['total']);
        $this->assertSame('Test Admin', $log['items'][0]['admin_full_name']);
    }

    // ------------------------------------------------------------------ audit log

    public function testAuditLogFiltersByStaffMemberAndDecodesValues(): void
    {
        $admin_id = $this->admin_user_model->createAdmin($this->staffForm(), self::ADMIN_ID);
        $audit_log = new AuditLog($this->db);

        $page = $audit_log->search(['admin_id' => self::ADMIN_ID, 'entity_type' => 'admin', 'entity_id' => $admin_id, 'date_from' => date('Y-m-d', time() - 86400)]);

        $this->assertSame('admin.created', $page['items'][0]['audit_log_action']);
        $this->assertSame('operations', $page['items'][0]['audit_log_new_values']['admin_role']);
        $this->assertContains('admin.created', $audit_log->getActions());
        $this->assertSame(0, $audit_log->search(['entity_id' => $admin_id, 'date_to' => '2020-01-01'])['total']);
    }

    // ------------------------------------------------------------------ reports

    public function testSalesReportCountsPlacedOrdersButNotCancelledOnes(): void
    {
        $this->placeCashOrder(8);    // 8 × 5,000 + 5,000 delivery = 45,000
        $cancelled = $this->placeCashOrder(2);
        $this->db->execute("UPDATE orders SET order_status = 'cancelled' WHERE order_id = " . $cancelled['order_id']);

        $report = (new Report($this->db))->getSalesReport([]);

        // Dar by Bolt: the customer pays the rider, so CHIMBO's money is the products only
        $this->assertSame(['order_count' => 1, 'revenue' => 40000, 'delivery_fees' => 0, 'pieces_sold' => 8, 'cancelled_count' => 1, 'average_order' => 40000], $report['summary']);
        $this->assertSame(40000, $report['top_products'][0]['revenue']);
        $this->assertSame('Cosmetics', $report['by_category'][0]['category_name']);
        $this->assertSame([['order_payment_method' => 'cod', 'order_count' => 1, 'revenue' => 40000, 'paid_total' => 0]], $report['by_payment_method']);
        $this->assertSame(1, $report['by_day'][0]['order_count']);
    }

    public function testReportCsvAndDateLimits(): void
    {
        $this->placeCashOrder(8);

        $file = (new Report($this->db))->exportCsv('by_region', []);
        $this->assertStringContainsString("Region,Orders,\"Revenue (TZS)\"", $file['content']);
        $this->assertStringContainsString('"Dar es Salaam",1,40000', $file['content']);

        $this->assertFieldError('date_from', fn () => (new Report($this->db))->getSalesReport(['date_from' => '2026-10-02', 'date_to' => '2026-10-01']));
        $this->assertFieldError('date_from', fn () => (new Report($this->db))->getSalesReport(['date_from' => '2024-01-01', 'date_to' => '2026-01-01']));
        $this->assertFieldError('section', fn () => (new Report($this->db))->exportCsv('passwords', []));
    }

    // ------------------------------------------------------------------ dashboard

    public function testDashboardSalesMatchTheReportsSummary(): void
    {
        $this->placeCashOrder(8);   // 45,000 today

        $sales = (new Dashboard($this->db))->getSales();

        $this->assertSame([1, 40000], [$sales['today']['order_count'], $sales['today']['revenue']]);
        $this->assertSame([1, 40000], [$sales['last_7_days']['order_count'], $sales['last_7_days']['revenue']]);
        $this->assertSame((new Report($this->db))->getSalesReport([])['summary']['revenue'], $sales['last_7_days']['revenue']);
    }

    public function testAllTimeTotalsLeaveCancelledOrdersOutOfTheMoney(): void
    {
        $this->placeCashOrder(8);                    // 45,000
        $cancelled = $this->placeCashOrder(2);       // 16,000, then cancelled
        $this->db->execute("UPDATE orders SET order_status = 'cancelled' WHERE order_id = " . $cancelled['order_id']);

        $totals = (new Dashboard($this->db))->getAllTimeTotals();

        $this->assertSame(2, $totals['orders']);              // every order ever placed
        $this->assertSame(40000, $totals['revenue']);         // the cancelled one isn't money
        $this->assertSame(40000, $totals['average_order']);
        $this->assertSame(8, $totals['pieces_sold']);
        $this->assertSame(2, $totals['customers']);
    }

    public function testDailySalesListEveryDayWithZerosForQuietDays(): void
    {
        $this->placeCashOrder(8);   // 45,000 today

        $days = (new Dashboard($this->db))->getDailySales(7);

        $this->assertCount(7, $days);
        $this->assertSame(['order_count' => 0, 'revenue' => 0], array_intersect_key($days[0], ['order_count' => 0, 'revenue' => 0]));
        $this->assertSame([1, 40000], [end($days)['order_count'], end($days)['revenue']]);   // today is last
        $this->assertSame(40000, array_sum(array_column($days, 'revenue')));

        try {
            (new Dashboard($this->db))->getDailySales(5);
            $this->fail('Expected a validation error');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('days', $e->fields());
        }
    }

    public function testAttentionCountsWhatNeedsSomeoneNow(): void
    {
        $this->placeCashOrder(8);   // a cash order starts "confirmed" → one order to pack

        $counts = (new Dashboard($this->db))->getAttentionCounts();

        $this->assertSame(1, $counts['orders_to_pack']);
        $this->assertSame(0, $counts['payments_to_check']);
        $this->assertSame(0, $counts['overdue_unpaid']);
        $this->assertSame(['payments_to_check', 'refunds_due', 'orders_to_pack', 'transport_to_confirm', 'businesses_to_verify', 'low_stock', 'overdue_unpaid'], array_keys($counts));
    }

    public function testOrdersTabShowsThePipelineWhatToPackAndWhoIsDelivering(): void
    {
        $to_pack     = $this->placeCashOrder(8);
        $dispatched  = $this->placeCashOrder(2);
        $agent_id    = $this->db->insert(
            "INSERT INTO delivery_agents (delivery_agent_full_name, delivery_agent_phone) VALUES ('Juma Ali', '+255754999888')"
        );
        $manager = new OrderManager($this->db);
        $manager->changeStatus($dispatched['order_id'], ['order_status' => 'packed'], self::ADMIN_ID);
        $manager->changeStatus($dispatched['order_id'], ['order_status' => 'dispatched', 'delivery_agent_id' => $agent_id], self::ADMIN_ID);
        $dashboard = new Dashboard($this->db);

        $summary = $dashboard->getOrderPipeline();
        $this->assertSame(['pending_payment' => 0, 'confirmed' => 1, 'packed' => 0, 'dispatched' => 1, 'in_transit' => 0], $summary['pipeline']);
        $this->assertSame(['all' => 2, 'active' => 2, 'delivered' => 0, 'cancelled' => 0, 'expired' => 0], $summary['totals']);

        $waiting = $dashboard->getOrdersWaitingToPack();
        $this->assertSame([$to_pack['order_number']], array_column($waiting, 'order_number'));
        $this->assertNotNull($waiting[0]['confirmed_at']);

        $deliveries = $dashboard->getDeliveriesByAgent();
        $this->assertSame(['Juma Ali', 1, $dispatched['order_total'], $dispatched['order_number']], [
            $deliveries[0]['delivery_agent_full_name'], $deliveries[0]['order_count'],
            $deliveries[0]['cash_to_collect'], $deliveries[0]['order_numbers'],
        ]);

        $this->db->execute('DELETE FROM order_deliveries');
        $this->db->execute('DELETE FROM delivery_agents WHERE delivery_agent_id = ' . (int) $agent_id);
    }

    public function testSalesTabMatchesTheReportsPage(): void
    {
        $this->placeCashOrder(8);   // 8 Vaseline (Cosmetics), cash, Dar es Salaam: 45,000

        $sales = (new Dashboard($this->db))->getSalesBreakdown(30);

        $this->assertSame(40000, $sales['summary']['revenue']);
        $this->assertSame([['category_name' => 'Cosmetics', 'quantity' => 8, 'revenue' => 40000]], $sales['by_category']);
        $this->assertSame('cod', $sales['by_payment_method'][0]['order_payment_method']);
        $this->assertSame(['Vaseline Petroleum Jelly 400ml'], array_column($sales['top_products'], 'product_name'));
        $this->assertSame((new Report($this->db))->getSalesReport(['date_from' => $sales['date_from'], 'date_to' => $sales['date_to']])['summary'], $sales['summary']);
    }

    public function testCustomersTabCountsNewCustomersAndTopBuyers(): void
    {
        $this->placeCashOrder(8);   // a new customer who spends 45,000
        $this->placeCashOrder(2);   // another new customer: 2 × 5,500 + 5,000 delivery = 16,000
        $deleted_id = (new User($this->db))->findOrCreateUserIdByPhone('+255754000999');
        $this->db->execute("UPDATE users SET user_status = 'deleted' WHERE user_id = {$deleted_id}");

        $stats = (new Dashboard($this->db))->getCustomerStats(7);

        $this->assertSame(2, $stats['counts']['new_in_period']);   // the deleted account isn't counted
        $this->assertCount(7, $stats['new_per_day']);
        $this->assertSame(2, end($stats['new_per_day'])['customer_count']);
        $this->assertSame([40000, 11000], array_map('intval', array_column($stats['top_customers'], 'total_spent')));
        $this->assertNotContains($deleted_id, array_map('intval', array_column($stats['newest_customers'], 'user_id')));
    }

    public function testStockTabShowsBestSellersAndSlowMovers(): void
    {
        $this->placeCashOrder(8);

        $stock = (new Dashboard($this->db))->getStockStats();

        $this->assertSame(['product_id' => self::VASELINE, 'pieces_sold' => 8], [
            'product_id'  => (int) $stock['best_sellers'][0]['product_id'],
            'pieces_sold' => $stock['best_sellers'][0]['pieces_sold'],
        ]);
        $this->assertNotContains(self::VASELINE, array_map('intval', array_column($stock['slow_movers'], 'product_id')));
        $this->assertGreaterThan(0, $stock['counts']['in_shop']);
    }

    public function testLowStockFollowsTheStockPageRule(): void
    {
        $this->db->execute('UPDATE products SET product_stock_quantity = 0 WHERE product_id = ' . self::VASELINE);
        $this->db->execute('UPDATE products SET product_stock_quantity = ' . ProductEditor::LOW_STOCK_THRESHOLD . ' WHERE product_id = 2');

        $low_stock = (new Dashboard($this->db))->getLowStock();

        $this->assertSame([1, 1], [$low_stock['out_count'], $low_stock['low_count']]);
        $this->assertSame([self::VASELINE, 2], array_map('intval', array_column($low_stock['items'], 'product_id')));
    }

    // ------------------------------------------------------------------ settings

    public function testSettingsSaveOnlyChangesAndAreAudited(): void
    {
        $settings = new Settings($this->db);
        $form     = [];
        foreach ($settings->getEditableSettings() as $fields) {
            foreach ($fields as $field) {
                $form[$field['setting_key']] = $field['setting_value'];
            }
        }

        $settings->updateSettings(['support_phone' => '0754 111 222'] + $form, self::ADMIN_ID);

        $this->assertSame('+255754111222', $settings->getSupportContacts()['support_phone']);
        $log = (new AuditLog($this->db))->search(['action' => 'settings.updated']);
        $this->assertSame(['support_phone' => '+255754111222'], $log['items'][0]['audit_log_new_values']);

        $this->assertFieldError('otp_max_attempts', fn () => $settings->updateSettings(['otp_max_attempts' => '1'] + $form, self::ADMIN_ID));
    }

    public function testLegalPagesAndUnknownPages(): void
    {
        $page = (new Settings($this->db))->getLegalPage('terms');

        $this->assertSame('Vigezo na Masharti', $page['page_title']);
        $this->assertNotSame('', $page['page_body']);
        $this->expectExceptionObject(ApiException::notFound('Ukurasa haukupatikana.'));
        (new Settings($this->db))->getLegalPage('../.env');
    }

    // ------------------------------------------------------------------ delivery methods

    public function testDeliveryFeeChangeIsUsedByCheckoutAndNewOptionsCanBeHidden(): void
    {
        $model       = new DeliveryMethod($this->db);
        $standard_id = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'standard'");
        $standard    = $model->getMethodById($standard_id);

        $model->updateMethod($standard_id, ['delivery_method_fee' => 6000, 'delivery_method_pricing' => 'fixed', 'delivery_method_is_active' => '1'] + $standard, self::ADMIN_ID);
        $model->createMethod(['delivery_method_name' => 'Pikipiki Mwanza', 'delivery_method_fee' => 3000, 'delivery_method_eta_min_days' => 0, 'delivery_method_eta_max_days' => 1], self::ADMIN_ID);

        $offered = (new Checkout($this->db))->getDeliveryMethods();
        $this->assertSame(6000, array_column($offered, 'delivery_method_fee', 'delivery_method_code')['standard']);
        $this->assertNotContains('pikipiki-mwanza', array_column($offered, 'delivery_method_code'));   // unchecked = hidden

        $this->assertFieldError('delivery_method_eta_max_days', fn () => $model->updateMethod($standard_id, ['delivery_method_eta_min_days' => 5, 'delivery_method_eta_max_days' => 2] + $standard, self::ADMIN_ID));
    }

    // ------------------------------------------------------------------ helpers

    private function staffForm(array $changes = []): array
    {
        return $changes + [
            'admin_full_name'             => 'Neema Said',
            'admin_email'                 => 'Neema@Chimbo.test',
            'admin_role'                  => 'operations',
            'admin_password'              => 'a-long-password',
            'admin_password_confirmation' => 'a-long-password',
        ];
    }

    /** A cash-on-delivery order of Vaseline for a new customer in Dar es Salaam (standard delivery, 5,000). */
    private function placeCashOrder(int $quantity): array
    {
        static $customer_number = 0;
        $user_id    = (new User($this->db))->findOrCreateUserIdByPhone(sprintf('+25571200%04d', ++$customer_number));
        $address_id = (new Address($this->db))->createAddress($user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'"),
            'address_street'         => 'Kariakoo',
        ])['address_id'];
        $delivery_method_id = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'standard'");

        (new Cart($this->db))->addItem($user_id, ['product_id' => self::VASELINE, 'quantity' => $quantity]);
        $total = (new Checkout($this->db))->preview($user_id, ['delivery_method_id' => $delivery_method_id, 'address_id' => $address_id])['grand_total'];

        return (new Order($this->db))->placeOrder($user_id, [
            'address_id' => $address_id, 'delivery_method_id' => $delivery_method_id, 'payment_method' => 'cod', 'expected_total' => $total,
        ], 'app', null);
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
