<?php

use PHPUnit\Framework\TestCase;

/**
 * Mobile money and bank payments checked by staff: payment methods, "Nimelipa", confirm / reject,
 * payments recorded by staff, and refunds.
 */
final class PaymentTest extends TestCase
{
    private const ADMIN_ID  = 1;
    private const VASELINE  = 1;            // 8 pieces → 8 × 5,000 + 5,000 delivery = 45,000
    private const PAYER     = '0712000111';
    private const REFERENCE = 'QJK3X7ABC1';

    private Database $db;
    private Payment $payment_model;
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
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');

        $this->switchOnMpesa();
        $this->payment_model = new Payment($this->db);
        $this->order_model   = new Order($this->db);
        $this->user_id       = (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');
        $this->address_id    = (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'"),
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
        $this->db->execute(
            "UPDATE payment_methods SET payment_method_is_active = (payment_method_code = 'cod'),
                    payment_method_account_name = NULL, payment_method_account_number = NULL"
        );
        PaymentMethod::clearCache();
    }

    // ------------------------------------------------------------------ payment methods

    public function testAMethodCanOnlyBeOfferedWithAnAccountToPayTo(): void
    {
        $model = new PaymentMethod($this->db);
        $bank  = $this->methodRow('bank');

        $e = $this->catchApiError(fn () => $model->setPaymentMethodActive((int) $bank['payment_method_id'], true, self::ADMIN_ID));
        $this->assertArrayHasKey('payment_method_account_number', $e->fields());
        $this->assertArrayHasKey('payment_method_bank_name', $e->fields());
    }

    public function testCheckoutOffersTheSwitchedOnMethodsWithTheirPayToDetails(): void
    {
        $options = (new Checkout($this->db))->getOptions($this->user_id, []);

        $this->assertSame(['mpesa', 'cod'], $options['payment_methods']);
        $this->assertSame('123456', $options['payment_method_details'][0]['payment_method_account_number']);
        $this->assertSame('CHIMBO LTD', $options['payment_method_details'][0]['payment_method_account_name']);
    }

    // ------------------------------------------------------------------ customer

    public function testNewMobileMoneyOrderShowsWhereToPay(): void
    {
        $order = $this->placeOrder('mpesa');

        $this->assertSame('pending_payment', $order['order_status']);
        $this->assertSame('unpaid', $order['order_payment_status']);
        $this->assertTrue($order['payment']['can_submit_payment']);
        $this->assertSame(45000, $order['payment']['payment_amount']);
        $this->assertSame('123456', $order['payment']['payment_method']['payment_method_account_number']);
        $this->assertSame($order['order_number'], $order['payment']['payment_note_hint']);
        $this->assertNull($order['payment']['latest_payment']);
    }

    public function testCustomerSendsThePaymentAndWaitsForReview(): void
    {
        $order = $this->placeOrder('mpesa');

        $order = $this->payment_model->submitPayment($this->user_id, $order['order_id'], ['payment_payer_account' => self::PAYER, 'payment_reference' => 'qjk3x7 abc1']);

        $this->assertSame('pending', $order['order_payment_status']);
        $this->assertSame('pending_payment', $order['order_status']);   // not confirmed until staff check it
        $this->assertFalse($order['payment']['can_submit_payment']);
        $this->assertFalse($order['can_cancel']);
        $this->assertSame(['+255712000111', self::REFERENCE, 'submitted'], [
            $order['payment']['latest_payment']['payment_payer_account'],
            $order['payment']['latest_payment']['payment_reference'],
            $order['payment']['latest_payment']['payment_status'],
        ]);

        $this->assertApiError('PAYMENT_UNDER_REVIEW', fn () => $this->submit($order['order_id'], 'ZZZ999XYZ'));
        $this->assertApiError('PAYMENT_UNDER_REVIEW', fn () => $this->order_model->cancelOrder($this->user_id, $order['order_id'], []));
    }

    public function testBadPaymentDetailsAreRefused(): void
    {
        $order = $this->placeOrder('mpesa');

        $this->assertFieldError('payment_payer_account', fn () => $this->payment_model->submitPayment($this->user_id, $order['order_id'], ['payment_payer_account' => '123', 'payment_reference' => self::REFERENCE]));
        $this->assertFieldError('payment_reference', fn () => $this->submit($order['order_id'], 'AB-1'));
    }

    public function testOneConfirmationCodeCannotPayTwoOrders(): void
    {
        $first  = $this->placeOrder('mpesa');
        $second = $this->placeOrder('mpesa');
        $this->submit($first['order_id'], self::REFERENCE);

        $this->assertFieldError('payment_reference', fn () => $this->submit($second['order_id'], self::REFERENCE));
    }

    public function testNoPaymentIsExpectedForCashOrdersOrAfterTheTimeToPay(): void
    {
        $cash_order = $this->placeOrder('cod');
        $this->assertApiError('PAYMENT_NOT_EXPECTED', fn () => $this->submit($cash_order['order_id'], self::REFERENCE));

        $late_order = $this->placeOrder('mpesa');
        $this->db->execute('UPDATE orders SET order_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE order_id = ' . $late_order['order_id']);
        $this->assertApiError('PAYMENT_TIME_OVER', fn () => $this->submit($late_order['order_id'], self::REFERENCE));
        $this->assertFalse($this->order_model->getOrder($this->user_id, $late_order['order_id'])['payment']['can_submit_payment']);
    }

    public function testCustomerSwitchesToAnotherMethodWithoutRestartingTheTimeToPay(): void
    {
        $this->switchOnAirtel();
        $order = $this->placeOrder('mpesa');
        $this->assertTrue($order['payment']['can_change_payment_method']);

        $changed = $this->payment_model->changePaymentMethod($this->user_id, $order['order_id'], ['payment_method' => 'airtel_money']);

        $this->assertSame('airtel_money', $changed['order_payment_method']);
        $this->assertSame('654321', $changed['payment']['payment_method']['payment_method_account_number']);
        $this->assertSame('pending_payment', $changed['order_status']);
        $this->assertSame($order['order_expires_at'], $changed['order_expires_at']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'order.payment_method_changed' AND audit_log_entity_id = {$order['order_id']}"));
    }

    public function testSwitchingToCashConfirmsTheOrderButRespectsTheCashLimit(): void
    {
        $order = $this->placeOrder('mpesa');

        $this->db->execute("UPDATE settings SET setting_value = '10000' WHERE setting_key = 'cod_max_order_total'");
        Settings::clearCache();
        $this->assertFieldError('payment_method', fn () => $this->payment_model->changePaymentMethod($this->user_id, $order['order_id'], ['payment_method' => 'cod']));

        $this->db->execute("UPDATE settings SET setting_value = '300000' WHERE setting_key = 'cod_max_order_total'");
        Settings::clearCache();
        $changed = $this->payment_model->changePaymentMethod($this->user_id, $order['order_id'], ['payment_method' => 'cod']);

        $this->assertSame(['confirmed', 'cod', 'cod_pending', null], [$changed['order_status'], $changed['order_payment_method'], $changed['order_payment_status'], $changed['order_expires_at']]);
        $this->assertFalse($changed['payment']['can_change_payment_method']);
    }

    public function testMethodCannotChangeWhileUnderReviewOrToASwitchedOffMethod(): void
    {
        $order = $this->placeOrder('mpesa');
        $this->assertFieldError('payment_method', fn () => $this->payment_model->changePaymentMethod($this->user_id, $order['order_id'], ['payment_method' => 'bank']));

        $this->submit($order['order_id'], self::REFERENCE);
        $this->assertApiError('PAYMENT_UNDER_REVIEW', fn () => $this->payment_model->changePaymentMethod($this->user_id, $order['order_id'], ['payment_method' => 'cod']));

        $cash_order = $this->placeOrder('cod');
        $this->assertApiError('PAYMENT_METHOD_LOCKED', fn () => $this->payment_model->changePaymentMethod($this->user_id, $cash_order['order_id'], ['payment_method' => 'mpesa']));
    }

    // ------------------------------------------------------------------ staff

    public function testConfirmingMarksTheOrderPaidAndConfirmed(): void
    {
        $order      = $this->placeOrder('mpesa');
        $payment_id = $this->submitAndGetPaymentId($order['order_id']);

        $this->payment_model->confirmPayment($payment_id, self::ADMIN_ID);

        $order = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame(['confirmed', 'paid', null], [$order['order_status'], $order['order_payment_status'], $order['order_expires_at']]);
        $this->assertSame('confirmed', $order['payment']['latest_payment']['payment_status']);
        $this->assertSame('Malipo yamethibitishwa', end($order['events'])['status_note']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'payment.confirmed' AND audit_log_entity_id = {$payment_id}"));

        $this->assertApiError('PAYMENT_ALREADY_REVIEWED', fn () => $this->payment_model->confirmPayment($payment_id, self::ADMIN_ID));
    }

    public function testRejectingTellsTheCustomerWhyAndAllowsSendingAgain(): void
    {
        $order      = $this->placeOrder('mpesa');
        $payment_id = $this->submitAndGetPaymentId($order['order_id']);

        $this->assertFieldError('payment_review_note', fn () => $this->payment_model->rejectPayment($payment_id, [], self::ADMIN_ID));
        $this->payment_model->rejectPayment($payment_id, ['payment_review_note' => 'Hatukupata malipo haya.'], self::ADMIN_ID);

        $order = $this->order_model->getOrder($this->user_id, $order['order_id']);
        $this->assertSame('unpaid', $order['order_payment_status']);
        $this->assertTrue($order['payment']['can_submit_payment']);
        $this->assertSame('Hatukupata malipo haya.', $order['payment']['latest_payment']['payment_review_note']);
        $this->assertStringContainsString('Hatukupata malipo haya.', (string) $this->db->fetchValue(
            "SELECT notification_body FROM notifications WHERE notification_type = 'payment' ORDER BY notification_id DESC LIMIT 1"
        ));

        $this->submit($order['order_id'], self::REFERENCE);   // the same code may be sent again after a rejection
    }

    public function testStaffCannotCancelAnOrderWhosePaymentIsWaitingForReview(): void
    {
        $order = $this->placeOrder('mpesa');
        $this->submit($order['order_id'], self::REFERENCE);

        $this->assertApiError('PAYMENT_UNDER_REVIEW', fn () => (new OrderManager($this->db))->changeStatus($order['order_id'], ['order_status' => 'cancelled'], self::ADMIN_ID));
    }

    public function testStaffRecordAPhoneOrderPaymentInOneStep(): void
    {
        $order = $this->placeOrder('mpesa');

        $this->payment_model->recordPaymentByStaff($order['order_id'], ['payment_payer_account' => self::PAYER, 'payment_reference' => self::REFERENCE], self::ADMIN_ID);

        $admin_view = (new OrderManager($this->db))->getOrderForAdmin($order['order_id']);
        $this->assertSame(['confirmed', 'paid'], [$admin_view['order_status'], $admin_view['order_payment_status']]);
        $this->assertSame(self::ADMIN_ID, (int) $admin_view['payments'][0]['submitted_by_admin_id']);
        $this->assertFalse($admin_view['can_record_payment']);
    }

    public function testPaidOrderCancelledLaterIsListedUntilRefunded(): void
    {
        $order = $this->placeOrder('mpesa');
        $this->payment_model->confirmPayment($this->submitAndGetPaymentId($order['order_id']), self::ADMIN_ID);
        $this->order_model->cancelOrder($this->user_id, $order['order_id'], ['order_cancel_reason' => 'Nimebadili mawazo']);

        $refunds = $this->payment_model->getRefundsDue();
        $this->assertSame([$order['order_number']], array_column($refunds, 'order_number'));
        $this->assertSame('+255712000111', $refunds[0]['payment_payer_account']);

        $this->payment_model->markRefunded($order['order_id'], ['refund_note' => 'M-Pesa QXX111 45,000'], self::ADMIN_ID);

        $this->assertSame([], $this->payment_model->getRefundsDue());
        $this->assertSame('refunded', $this->order_model->getOrder($this->user_id, $order['order_id'])['order_payment_status']);
        $this->assertApiError('REFUND_NOT_DUE', fn () => $this->payment_model->markRefunded($order['order_id'], ['refund_note' => 'again please'], self::ADMIN_ID));
    }

    public function testPaymentsListFiltersAndSearches(): void
    {
        $order = $this->placeOrder('mpesa');
        $this->submit($order['order_id'], self::REFERENCE);

        $this->assertSame(1, $this->payment_model->countWaitingForReview());
        $this->assertSame(1, $this->payment_model->searchPayments(['payment_status' => 'submitted', 'search' => 'qjk3x7abc1'])['total']);
        $this->assertSame(1, $this->payment_model->searchPayments(['search' => $order['order_number']])['total']);
        $this->assertSame(1, $this->payment_model->searchPayments(['search' => '0712000111'])['total']);
        $this->assertSame(0, $this->payment_model->searchPayments(['payment_status' => 'confirmed'])['total']);
    }

    // ------------------------------------------------------------------ helpers

    private function switchOnMpesa(): void
    {
        $mpesa = $this->methodRow('mpesa');
        (new PaymentMethod($this->db))->updateMethod((int) $mpesa['payment_method_id'], [
            'payment_method_name'           => 'M-Pesa',
            'payment_method_account_name'   => 'CHIMBO LTD',
            'payment_method_account_number' => '123456',
            'payment_method_instructions'   => 'Lipa kwa Lipa Namba, kisha bonyeza "Nimelipa".',
            'payment_method_is_active'      => '1',
            'payment_method_sort_order'     => 1,
        ], self::ADMIN_ID);
    }

    private function switchOnAirtel(): void
    {
        $airtel = $this->methodRow('airtel_money');
        (new PaymentMethod($this->db))->updateMethod((int) $airtel['payment_method_id'], [
            'payment_method_name'           => 'Airtel Money',
            'payment_method_account_name'   => 'CHIMBO LTD',
            'payment_method_account_number' => '654321',
            'payment_method_is_active'      => '1',
            'payment_method_sort_order'     => 2,
        ], self::ADMIN_ID);
    }

    private function methodRow(string $code): array
    {
        return $this->db->fetchOne('SELECT * FROM payment_methods WHERE payment_method_code = :code', ['code' => $code]);
    }

    /** 8 Vaseline with standard delivery (45,000). */
    private function placeOrder(string $payment_method): array
    {
        $delivery_method_id = (int) $this->db->fetchValue("SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = 'standard'");
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $total = (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => $delivery_method_id, 'address_id' => $this->address_id])['grand_total'];

        return $this->order_model->placeOrder($this->user_id, [
            'address_id' => $this->address_id, 'delivery_method_id' => $delivery_method_id,
            'payment_method' => $payment_method, 'expected_total' => $total,
        ], 'app', null);
    }

    private function submit(int $order_id, string $reference): array
    {
        return $this->payment_model->submitPayment($this->user_id, $order_id, ['payment_payer_account' => self::PAYER, 'payment_reference' => $reference]);
    }

    private function submitAndGetPaymentId(int $order_id): int
    {
        return $this->submit($order_id, self::REFERENCE)['payment']['latest_payment']['payment_id'];
    }

    private function catchApiError(callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $e) {
            return $e;
        }
        $this->fail('Expected an ApiException');
    }

    private function assertApiError(string $expected_code, callable $action): void
    {
        $this->assertSame($expected_code, $this->catchApiError($action)->errorCode());
    }

    private function assertFieldError(string $field, callable $action): void
    {
        $this->assertArrayHasKey($field, $this->catchApiError($action)->fields());
    }
}
