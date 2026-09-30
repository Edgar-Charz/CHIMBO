<?php

use PHPUnit\Framework\TestCase;

/** Kikapu: cart pricing and limits, addresses, checkout totals. Uses the demo catalog. */
final class ShoppingTest extends TestCase
{
    private const VASELINE = 1;   // MOQ 1, tiers 1:5,500 · 6:5,000 · 24:4,700 · 60:4,400, stock 500
    private const NECKLACE = 7;   // MOQ 6, tiers 6:8,500 · 24:8,000 · 60:7,500, stock 150

    private Database $db;
    private Cart $cart;
    private Address $address_model;
    private Checkout $checkout;
    private int $user_id;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->cart          = new Cart($this->db);
        $this->address_model = new Address($this->db);
        $this->checkout      = new Checkout($this->db);
        $this->user_id       = (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');
        Settings::clearCache();
    }

    protected function tearDown(): void
    {
        $this->db->execute('UPDATE products SET product_stock_quantity = 500, product_is_active = 1 WHERE product_id = ' . self::VASELINE);
        $this->db->execute('UPDATE products SET product_stock_quantity = 150 WHERE product_id = ' . self::NECKLACE);
    }

    // ------------------------------------------------------------------ cart

    public function testCartIsPricedFromTheTiersAndGroupedByCategory(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $cart = $this->cart->addItem($this->user_id, ['product_id' => self::NECKLACE, 'quantity' => 6]);

        $this->assertSame(['Cosmetics', 'Jewelry'], array_column($cart['groups'], 'category_name'));
        $vaseline = $cart['groups'][0]['items'][0];
        $this->assertSame(5000, $vaseline['unit_price']);                  // 6+ tier
        $this->assertSame(['extra_quantity' => 16, 'unit_price' => 4700], $vaseline['next_tier_hint']);
        $this->assertSame(8 * 5000 + 6 * 8500, $cart['summary']['subtotal']);
        $this->assertSame(2, $cart['summary']['line_count']);
        $this->assertTrue($cart['summary']['can_checkout']);
    }

    public function testAddingAgainAddsToTheQuantity(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 3]);
        $cart = $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 3]);

        $this->assertSame(6, $cart['groups'][0]['items'][0]['cart_quantity']);
        $this->assertSame(5000, $cart['groups'][0]['items'][0]['unit_price']); // reached the 6+ tier
    }

    public function testQuantityBelowMoqIsRefused(): void
    {
        $this->expectExceptionObject(ApiException::validation(['quantity' => 'Kiwango cha chini cha kuagiza ni 6 (MOQ).']));
        $this->cart->addItem($this->user_id, ['product_id' => self::NECKLACE, 'quantity' => 4]);
    }

    public function testQuantityAboveStockIsRefused(): void
    {
        $this->assertApiError('OUT_OF_STOCK', fn () => $this->cart->addItem($this->user_id, ['product_id' => self::NECKLACE, 'quantity' => 151]));
    }

    public function testLineIsFlaggedWhenStockDropsAfterAdding(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 30]);
        $this->db->execute('UPDATE products SET product_stock_quantity = 10 WHERE product_id = ' . self::VASELINE);

        $cart = $this->cart->getCart($this->user_id);

        $this->assertSame('not_enough_stock', $cart['groups'][0]['items'][0]['line_problem']);
        $this->assertFalse($cart['summary']['can_checkout']);
        $this->assertApiError('CART_HAS_PROBLEMS', fn () => $this->checkout->preview($this->user_id, ['delivery_method_id' => 1]));
    }

    public function testHiddenProductIsRemovedWithAWarning(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 2]);
        $this->db->execute('UPDATE products SET product_is_active = 0 WHERE product_id = ' . self::VASELINE);

        $cart = $this->cart->getCart($this->user_id);

        $this->assertSame([], $cart['groups']);
        $this->assertSame(self::VASELINE, $cart['warnings'][0]['product_id']);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cart_items'));
    }

    public function testEachCustomerHasTheirOwnCart(): void
    {
        $other_user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255754000222');
        $this->cart->addItem($other_user_id, ['product_id' => self::VASELINE, 'quantity' => 2]);

        $this->assertSame(0, $this->cart->getCart($this->user_id)['summary']['line_count']);
    }

    public function testGuestCartMergeKeepsTheBiggerQuantityAndReportsBadItems(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 10]);

        $cart = $this->cart->mergeGuestCart($this->user_id, ['items' => [
            ['product_id' => self::VASELINE, 'quantity' => 4],    // account already has 10 → stays 10
            ['product_id' => self::NECKLACE, 'quantity' => 2],    // below MOQ 6 → skipped
            ['product_id' => 9999, 'quantity' => 1],              // not in the shop → skipped
        ]]);

        $this->assertSame(10, $cart['groups'][0]['items'][0]['cart_quantity']);
        $this->assertSame([self::NECKLACE, 9999], array_column($cart['skipped_items'], 'product_id'));
    }

    // ------------------------------------------------------------------ addresses

    public function testFirstAddressBecomesTheDefault(): void
    {
        $address = $this->address_model->createAddress($this->user_id, $this->addressForm());

        $this->assertTrue($address['address_is_default']);
        $this->assertSame('+255712345678', $address['address_phone']);
    }

    public function testChoosingAnotherDefaultAndDeletingTheDefault(): void
    {
        $first  = $this->address_model->createAddress($this->user_id, $this->addressForm());
        $second = $this->address_model->createAddress($this->user_id, $this->addressForm(['address_street' => 'Kirumba']));

        $addresses = $this->address_model->setDefaultAddress($this->user_id, $second['address_id']);
        $this->assertSame([$second['address_id'], $first['address_id']], array_column($addresses, 'address_id'));

        $addresses = $this->address_model->deleteAddress($this->user_id, $second['address_id']);
        $this->assertTrue($addresses[0]['address_is_default']); // the remaining one became default
    }

    public function testAnotherCustomersAddressIsNotFound(): void
    {
        $address = $this->address_model->createAddress($this->user_id, $this->addressForm());
        $other_user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255754000222');

        $this->assertApiError('NOT_FOUND', fn () => $this->address_model->deleteAddress($other_user_id, $address['address_id']));
    }

    // ------------------------------------------------------------------ checkout

    public function testTotalsUseServerPricesAndTheDeliveryFee(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 24]);

        $totals = $this->checkout->preview($this->user_id, ['delivery_method_id' => $this->deliveryMethodId('standard')]);

        $this->assertSame(24 * 4700, $totals['subtotal']);
        $this->assertSame(5000, $totals['delivery_fee']);
        $this->assertSame(24 * 4700 + 5000, $totals['grand_total']);
        $this->assertSame(24 * 800, $totals['savings']);
    }

    public function testExpressIsOnlyForDarEsSalaam(): void
    {
        $this->cart->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 2]);
        $mwanza_address = $this->address_model->createAddress($this->user_id, $this->addressForm(['region_id' => $this->regionId('Mwanza')]));

        $options = $this->checkout->getOptions($this->user_id, ['address_id' => $mwanza_address['address_id']]);
        $this->assertSame(['standard'], array_column($options['delivery_methods'], 'delivery_method_code'));

        $this->assertApiError('VALIDATION_ERROR', fn () => $this->checkout->preview($this->user_id, [
            'delivery_method_id' => $this->deliveryMethodId('express'),
            'address_id'         => $mwanza_address['address_id'],
        ]));
    }

    public function testEmptyCartCannotBeCheckedOut(): void
    {
        $this->assertApiError('CART_EMPTY', fn () => $this->checkout->preview($this->user_id, ['delivery_method_id' => 1]));
    }

    public function testOnlyEnabledPaymentMethodsAreOffered(): void
    {
        $this->assertSame(['cod'], $this->checkout->getOptions($this->user_id, [])['payment_methods']);
    }

    // ------------------------------------------------------------------ helpers

    private function addressForm(array $changes = []): array
    {
        return $changes + [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712 345 678',
            'region_id'              => $this->regionId('Dar es Salaam'),
            'address_street'         => 'Kariakoo, Lumumba Street',
        ];
    }

    private function regionId(string $region_name): int
    {
        return (int) $this->db->fetchValue('SELECT region_id FROM regions WHERE region_name = :name', ['name' => $region_name]);
    }

    private function deliveryMethodId(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT delivery_method_id FROM delivery_methods WHERE delivery_method_code = :code', ['code' => $code]);
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
