<?php

use PHPUnit\Framework\TestCase;

/** Time-limited offers ("Ofa"): admin rules, prices everywhere, and the price check when an offer ends. */
final class ProductOfferTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const VASELINE = 1;   // tiers 1:5,500 · 6:5,000 · 24:4,700 · 60:4,400 → with 15%: 4,675 · 4,250 · 3,995 · 3,740

    private Database $db;
    private ProductOffer $offer_model;
    private int $user_id;
    private array $original_products;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        $this->db->execute('DELETE FROM product_offers');
        $this->original_products = $this->db->fetchAll('SELECT product_id, product_stock_quantity, product_sold_count FROM products');
        $this->offer_model = new ProductOffer($this->db);
        $this->user_id     = (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM product_offers');
        foreach ($this->original_products as $product) {
            $this->db->execute(
                'UPDATE products SET product_stock_quantity = :product_stock_quantity, product_sold_count = :product_sold_count WHERE product_id = :product_id',
                $product
            );
        }
    }

    // ------------------------------------------------------------------ prices shown

    public function testRunningOfferChangesTheCardAndTheProductPage(): void
    {
        $this->startOffer(15);

        $card = (new Product($this->db))->getProductCardsByIds([self::VASELINE])[0];
        $this->assertSame([4675, 3740, 5500, 'offer'], [$card['product_price'], $card['product_price_from'], $card['product_compare_at_price'], $card['product_badge']]);
        $this->assertSame(15, $card['product_offer']['product_offer_percent']);
        $this->assertNotNull($card['product_offer']['product_offer_ends_at']);

        $page = (new Product($this->db))->getProductById(self::VASELINE);
        $this->assertSame([4675, 4250, 3995, 3740], array_column($page['tiers'], 'tier_unit_price'));
        $this->assertSame(5500, $page['tiers'][0]['tier_price_before_offer']);
    }

    public function testScheduledAndEndedOffersChangeNothing(): void
    {
        $this->offer_model->createOffer($this->form(['product_offer_starts_at' => $this->localTime('+1 day'), 'product_offer_ends_at' => $this->localTime('+2 days')]), self::ADMIN_ID);

        $card = (new Product($this->db))->getProductCardsByIds([self::VASELINE])[0];
        $this->assertSame(5500, $card['product_price']);
        $this->assertNull($card['product_offer']);

        $offer_id = $this->startOffer(15, '+3 days', '+4 days');   // a second offer that doesn't overlap
        $this->offer_model->endOffer($offer_id, self::ADMIN_ID);
        $this->assertSame([], (new ProductOffer($this->db))->getRunningPercents([self::VASELINE]));
    }

    public function testOffersCollectionAndHomeRail(): void
    {
        $this->startOffer(10);

        $offers = (new Product($this->db))->getProducts(['collection' => 'offers']);
        $this->assertSame([self::VASELINE], array_column($offers['items'], 'product_id'));
        $this->assertSame([self::VASELINE], array_column((new Home($this->db))->getHome()['offers'], 'product_id'));
    }

    // ------------------------------------------------------------------ cart, checkout, order

    public function testCartAndOrderUseTheOfferPriceAndKeepIt(): void
    {
        $this->startOffer(15);
        $cart = (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $line = $cart['groups'][0]['items'][0];
        $this->assertSame([4250, 15, (5500 - 4250) * 8], [$line['unit_price'], $line['offer_percent'], $line['line_savings']]);

        $order = $this->placeOrder();
        $this->assertSame(4250, $order['items'][0]['order_item_unit_price']);
        $this->assertSame(15, $order['items'][0]['order_item_offer_percent']);

        $this->db->execute('DELETE FROM product_offers');   // the offer ends after ordering
        $this->assertSame(4250, (new Order($this->db))->getOrder($this->user_id, $order['order_id'])['items'][0]['order_item_unit_price']);
    }

    public function testOfferEndingDuringCheckoutIsCaughtByThePriceCheck(): void
    {
        $offer_id = $this->startOffer(15);
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => self::VASELINE, 'quantity' => 8]);
        $address_id = $this->addressId();
        $seen_total = (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => 1, 'address_id' => $address_id])['grand_total'];

        $this->offer_model->endOffer($offer_id, self::ADMIN_ID);

        try {
            (new Order($this->db))->placeOrder($this->user_id, ['address_id' => $address_id, 'delivery_method_id' => 1, 'payment_method' => 'cod', 'expected_total' => $seen_total], 'app', null);
            $this->fail('Expected PRICE_CHANGED');
        } catch (ApiException $e) {
            $this->assertSame('PRICE_CHANGED', $e->errorCode());
        }
    }

    // ------------------------------------------------------------------ admin rules

    public function testOfferFormRules(): void
    {
        $this->assertFieldError('product_offer_percent', fn () => $this->offer_model->createOffer($this->form(['product_offer_percent' => 95]), self::ADMIN_ID));
        $this->assertFieldError('product_offer_ends_at', fn () => $this->offer_model->createOffer($this->form(['product_offer_ends_at' => $this->localTime('-1 hour')]), self::ADMIN_ID));
        $this->assertFieldError('product_offer_ends_at', fn () => $this->offer_model->createOffer($this->form(['product_offer_ends_at' => $this->localTime('+100 days')]), self::ADMIN_ID));
        $this->assertFieldError('product_id', fn () => $this->offer_model->createOffer($this->form(['product_id' => 999999]), self::ADMIN_ID));

        $this->startOffer(10);
        $this->assertFieldError('product_offer_starts_at', fn () => $this->offer_model->createOffer($this->form(['product_offer_ends_at' => $this->localTime('+2 days')]), self::ADMIN_ID));
    }

    public function testTimesAreTypedInEastAfricaTimeAndSavedInUtc(): void
    {
        $offer_id = $this->offer_model->createOffer($this->form([
            'product_offer_starts_at' => '2030-01-10T08:00',
            'product_offer_ends_at'   => '2030-01-12T20:00',
        ]), self::ADMIN_ID);

        $offer = $this->offer_model->getOfferById($offer_id);
        $this->assertSame(['2030-01-10 05:00:00', '2030-01-12 17:00:00', 'scheduled'], [$offer['product_offer_starts_at'], $offer['product_offer_ends_at'], $offer['product_offer_status']]);
    }

    public function testEndedOfferCannotBeEditedAndTheListShowsStatuses(): void
    {
        $offer_id = $this->startOffer(20);
        $this->assertSame('running', $this->offer_model->getOffersForAdmin(['status' => 'running'])['items'][0]['product_offer_status']);

        $this->offer_model->endOffer($offer_id, self::ADMIN_ID);

        $this->assertSame(1, $this->offer_model->getOffersForAdmin(['status' => 'ended'])['total']);
        try {
            $this->offer_model->updateOffer($offer_id, $this->form(), self::ADMIN_ID);
            $this->fail('Expected OFFER_ENDED');
        } catch (ApiException $e) {
            $this->assertSame('OFFER_ENDED', $e->errorCode());
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Starts an offer now (or at $starts) for Vaseline. Returns its id. */
    private function startOffer(int $percent, ?string $starts = null, string $ends = '+2 days'): int
    {
        return $this->offer_model->createOffer($this->form([
            'product_offer_percent'   => $percent,
            'product_offer_starts_at' => $starts === null ? null : $this->localTime($starts),
            'product_offer_ends_at'   => $this->localTime($ends),
        ]), self::ADMIN_ID);
    }

    private function form(array $changes = []): array
    {
        return $changes + [
            'product_id'            => self::VASELINE,
            'product_offer_percent' => 15,
            'product_offer_ends_at' => $this->localTime('+2 days'),
        ];
    }

    /** A time in East Africa as the admin form sends it (datetime-local). */
    private function localTime(string $change): string
    {
        return (new DateTimeImmutable($change, new DateTimeZone('Africa/Dar_es_Salaam')))->format('Y-m-d\TH:i');
    }

    private function addressId(): int
    {
        return (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'"),
            'address_street'         => 'Kariakoo',
        ])['address_id'];
    }

    private function placeOrder(): array
    {
        $address_id = $this->addressId();
        $total = (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => 1, 'address_id' => $address_id])['grand_total'];
        return (new Order($this->db))->placeOrder($this->user_id, ['address_id' => $address_id, 'delivery_method_id' => 1, 'payment_method' => 'cod', 'expected_total' => $total], 'app', null);
    }

    private function assertFieldError(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected a validation error on {$field}");
        } catch (ApiException $e) {
            $this->assertArrayHasKey($field, $e->fields(), $e->getMessage() . ' ' . json_encode($e->fields()));
        }
    }
}
