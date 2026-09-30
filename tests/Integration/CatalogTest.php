<?php

use PHPUnit\Framework\TestCase;

/** Home, categories and product lists, using the demo catalog seeded into the test database. */
final class CatalogTest extends TestCase
{
    private Database $db;
    private Product $product_model;

    protected function setUp(): void
    {
        $this->db            = Database::instance();
        $this->product_model = new Product($this->db);
    }

    public function testHomeHasEverySection(): void
    {
        $home = (new Home($this->db))->getHome();

        $this->assertSame(
            ['banners', 'top_categories', 'best_sellers', 'deals', 'new_arrivals', 'recently_ordered'],
            array_keys($home)
        );
        $this->assertSame('Shamba la Vipodozi', $home['top_categories'][0]['category_tagline']);
    }

    public function testCardShowsNormalPriceAndBestWholesalePrice(): void
    {
        $vaseline = $this->findCard('Vaseline Petroleum Jelly 400ml');

        $this->assertSame(5500, $vaseline['product_price']);       // 1–5 pcs
        $this->assertSame(4400, $vaseline['product_price_from']);  // 60+ pcs
        $this->assertSame('bestseller', $vaseline['product_badge']);
    }

    public function testTopCategoryIncludesItsChips(): void
    {
        $cosmetics_id = (int) $this->db->fetchValue("SELECT category_id FROM categories WHERE category_name = 'Cosmetics'");

        $result = $this->product_model->getProducts(['category_id' => $cosmetics_id]);

        $this->assertSame(6, $result['total']);
    }

    public function testDealsAreProductsBelowTheirOldPrice(): void
    {
        $deals = $this->product_model->getCollection('deals', 10);

        foreach ($deals as $card) {
            $this->assertGreaterThan($card['product_price'], $card['product_compare_at_price']);
            $this->assertSame('deal', $card['product_badge']);
        }
        $this->assertNotEmpty($deals);
    }

    public function testFiltersAndSortWorkTogether(): void
    {
        $result = $this->product_model->getProducts(['max_moq' => 3, 'sort' => 'price_asc']);
        $prices = array_column($result['items'], 'product_price');

        $sorted_prices = $prices;
        sort($sorted_prices);
        $this->assertSame($sorted_prices, $prices);
        $this->assertLessThanOrEqual(3, max(array_column($result['items'], 'product_moq')));
    }

    public function testSearchTreatsPercentSignAsNormalText(): void
    {
        $this->assertSame(0, $this->product_model->getProducts(['q' => '100%'])['total']);
        $this->assertSame(1, $this->product_model->getProducts(['q' => 'dove'])['total']);
    }

    public function testInactiveProductsAreHidden(): void
    {
        $this->db->execute("UPDATE products SET product_is_active = 0 WHERE product_name = 'Classic Watch'");

        $this->assertNull($this->findCard('Classic Watch'));

        $this->db->execute("UPDATE products SET product_is_active = 1 WHERE product_name = 'Classic Watch'");
    }

    public function testUnknownSortIsRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->product_model->getProducts(['sort' => 'cheapest']);
    }

    private function findCard(string $product_name): ?array
    {
        $cards = $this->product_model->getProducts(['per_page' => 50])['items'];
        foreach ($cards as $card) {
            if ($card['product_name'] === $product_name) {
                return $card;
            }
        }
        return null;
    }
}
