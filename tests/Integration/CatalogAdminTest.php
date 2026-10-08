<?php

use PHPUnit\Framework\TestCase;

/** Staff side of the catalog: categories, sellers, products with tier prices, stock and banners. */
final class CatalogAdminTest extends TestCase
{
    private const ADMIN_ID = 1;

    private Database $db;
    private ProductEditor $product_editor;
    private int $chip_id;
    private int $seller_id;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->ensureTestAdminExists();
        $this->product_editor = new ProductEditor($this->db);

        $category_model = new Category($this->db);
        $top_id = $category_model->createCategory(['category_name' => 'Test Top', 'category_is_active' => '1'], self::ADMIN_ID);
        $this->chip_id = $category_model->createCategory(
            ['category_name' => 'Test Chip', 'parent_category_id' => $top_id, 'category_is_active' => '1'],
            self::ADMIN_ID
        );
        $this->seller_id = (new Seller($this->db))->createSeller(
            ['seller_name' => 'Test Seller', 'seller_status' => 'active', 'seller_is_verified' => '1'],
            self::ADMIN_ID
        );
    }

    protected function tearDown(): void
    {
        $test_products = "SELECT product_id FROM products WHERE product_name LIKE 'Test %'";
        $this->db->execute("DELETE FROM inventory_movements WHERE product_id IN ({$test_products})");
        $this->db->execute("DELETE FROM products WHERE product_name LIKE 'Test %'");
        $this->db->execute("DELETE FROM categories WHERE category_name LIKE 'Test %' AND parent_category_id IS NOT NULL");
        $this->db->execute("DELETE FROM categories WHERE category_name LIKE 'Test %'");
        $this->db->execute("DELETE FROM sellers WHERE seller_name LIKE 'Test %'");
        $this->db->execute("DELETE FROM banners WHERE banner_title LIKE 'Test %'");
    }

    public function testCreateProductWithTiersAndOpeningStock(): void
    {
        $product_id = $this->product_editor->createProduct($this->productForm(), self::ADMIN_ID);

        $product = (new Product($this->db))->getProductById($product_id);
        $this->assertSame(1000, $product['product_price']);
        $this->assertSame(800, $product['product_price_from']);
        $this->assertSame(50, $product['product_stock_quantity']);
        $this->assertStringStartsWith('CHB-', $this->product_editor->getProductForAdmin($product_id)['product_sku']);
        $this->assertSame('restock', $this->product_editor->getStockMovements($product_id)[0]['movement_reason']);
    }

    public function testFirstTierMustStartAtTheMoq(): void
    {
        $this->assertValidationError('tiers', fn () => $this->product_editor->createProduct(
            $this->productForm(['product_moq' => 6]),  // tiers start at 1
            self::ADMIN_ID
        ));
    }

    public function testBiggerQuantityMustHaveALowerPrice(): void
    {
        $form = $this->productForm(['tiers' => [
            ['tier_min_quantity' => 1, 'tier_unit_price' => 1000],
            ['tier_min_quantity' => 6, 'tier_unit_price' => 1200],
        ]]);

        $this->assertValidationError('tiers', fn () => $this->product_editor->createProduct($form, self::ADMIN_ID));
    }

    public function testEmptyTierRowsFromTheFormAreIgnored(): void
    {
        $form = $this->productForm();
        $form['tiers'][] = ['tier_min_quantity' => '', 'tier_unit_price' => ''];

        $product_id = $this->product_editor->createProduct($form, self::ADMIN_ID);

        $this->assertCount(2, (new Product($this->db))->getPriceTiers($product_id));
    }

    public function testOldPriceMustBeHigherThanTheNormalPrice(): void
    {
        $this->assertValidationError('product_compare_at_price', fn () => $this->product_editor->createProduct(
            $this->productForm(['product_compare_at_price' => 900]),
            self::ADMIN_ID
        ));
    }

    public function testProductMustBeInAChipNotATopCategory(): void
    {
        $top_id = (int) $this->db->fetchValue("SELECT category_id FROM categories WHERE category_name = 'Test Top'");

        $this->assertValidationError('category_id', fn () => $this->product_editor->createProduct(
            $this->productForm(['category_id' => $top_id]),
            self::ADMIN_ID
        ));
    }

    public function testUpdateReplacesTheTiers(): void
    {
        $product_id = $this->product_editor->createProduct($this->productForm(), self::ADMIN_ID);

        $this->product_editor->updateProduct($product_id, $this->productForm(['tiers' => [
            ['tier_min_quantity' => 1, 'tier_unit_price' => 1500],
        ]]), self::ADMIN_ID);

        $this->assertSame([['tier_min_quantity' => 1, 'tier_unit_price' => 1500]], (new Product($this->db))->getPriceTiers($product_id));

        // The stored list prices follow the new tiers
        $stored = $this->db->fetchOne('SELECT product_price, product_price_from FROM products WHERE product_id = :id', ['id' => $product_id]);
        $this->assertSame(['product_price' => 1500, 'product_price_from' => 1500], array_map('intval', $stored));
    }

    public function testStockCannotGoBelowZeroAndEveryChangeIsRecorded(): void
    {
        $product_id = $this->product_editor->createProduct($this->productForm(), self::ADMIN_ID);

        $new_stock = $this->product_editor->adjustStock($product_id, ['movement_quantity_change' => -20, 'movement_reason' => 'adjustment', 'movement_note' => 'Damaged'], self::ADMIN_ID);
        $this->assertSame(30, $new_stock);

        $this->assertValidationError('movement_quantity_change', fn () => $this->product_editor->adjustStock(
            $product_id,
            ['movement_quantity_change' => -31, 'movement_reason' => 'adjustment'],
            self::ADMIN_ID
        ));
        $this->assertCount(2, $this->product_editor->getStockMovements($product_id)); // opening stock + the −20
    }

    public function testDeletedProductDisappearsFromTheShop(): void
    {
        $product_id = $this->product_editor->createProduct($this->productForm(), self::ADMIN_ID);

        $this->product_editor->deleteProduct($product_id, self::ADMIN_ID);

        $this->expectException(ApiException::class);
        (new Product($this->db))->getProductById($product_id);
    }

    public function testCategoriesHaveOnlyTwoLevels(): void
    {
        $this->assertValidationError('parent_category_id', fn () => (new Category($this->db))->createCategory(
            ['category_name' => 'Test Grandchild', 'parent_category_id' => $this->chip_id],
            self::ADMIN_ID
        ));
    }

    public function testCategoryInUseCannotBeDeleted(): void
    {
        $this->product_editor->createProduct($this->productForm(), self::ADMIN_ID);

        $this->expectException(ApiException::class);
        (new Category($this->db))->deleteCategory($this->chip_id, self::ADMIN_ID);
    }

    public function testSellerNamesGetUniqueSlugs(): void
    {
        $second_id = (new Seller($this->db))->createSeller(['seller_name' => 'Test Seller', 'seller_status' => 'active'], self::ADMIN_ID);

        $this->assertSame('test-seller-2', (new Seller($this->db))->getSellerForAdmin($second_id)['seller_slug']);
    }

    public function testBannerTimesAreSavedInUtc(): void
    {
        $banner_id = (new Banner($this->db))->createBanner([
            'banner_title'     => 'Test Banner',
            'banner_is_active' => '1',
            'banner_starts_at' => '2026-10-01T08:00',   // Tanzania time (UTC+3)
        ], self::ADMIN_ID);

        $this->assertSame('2026-10-01 05:00:00', (new Banner($this->db))->getBannerForAdmin($banner_id)['banner_starts_at']);
    }

    public function testBannerCollectionMustBeKnown(): void
    {
        $this->assertValidationError('banner_target_value', fn () => (new Banner($this->db))->createBanner(
            ['banner_title' => 'Test Banner', 'banner_target_type' => 'collection', 'banner_target_value' => 'cheap'],
            self::ADMIN_ID
        ));
    }

    public function testAdminListSortsByTheChosenColumn(): void
    {
        $result = $this->product_editor->getProductsForAdmin(['sort' => 'product_price', 'direction' => 'asc', 'per_page' => 100]);
        $prices = array_map('intval', array_column($result['items'], 'product_price'));

        $sorted_prices = $prices;
        sort($sorted_prices);
        $this->assertSame($sorted_prices, $prices);
    }

    public function testAdminListRejectsAnUnknownSortColumn(): void
    {
        $this->assertValidationError('sort', fn () => $this->product_editor->getProductsForAdmin(['sort' => 'product_id; DROP TABLE products']));
    }

    public function testAdminListPageSize(): void
    {
        $result = $this->product_editor->getProductsForAdmin(['per_page' => 10]);

        $this->assertSame(10, $result['per_page']);
        $this->assertCount(10, $result['items']);
        $this->assertValidationError('per_page', fn () => $this->product_editor->getProductsForAdmin(['per_page' => 7]));
    }

    /** A valid product form; $changes replaces fields. */
    private function productForm(array $changes = []): array
    {
        return $changes + [
            'product_name'              => 'Test Lotion 200ml',
            'seller_id'                 => $this->seller_id,
            'category_id'               => $this->chip_id,
            'product_unit_label'        => 'pc',
            'product_moq'               => 1,
            'product_delivery_days_min' => 2,
            'product_delivery_days_max' => 3,
            'product_is_active'         => '1',
            'product_stock_quantity'    => 50,
            'tiers'                     => [
                ['tier_min_quantity' => 1, 'tier_unit_price' => 1000],
                ['tier_min_quantity' => 12, 'tier_unit_price' => 800],
            ],
        ];
    }

    private function assertValidationError(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected a validation error for {$field}");
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertArrayHasKey($field, $e->fields());
        }
    }

    /** The test database has no admins (the first-admin seed is skipped in tests); the audit log needs one. */
    private function ensureTestAdminExists(): void
    {
        $this->db->execute(
            "INSERT IGNORE INTO admins (admin_id, admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (1, 'Test Admin', 'test-admin@chimbo.test', 'x', 'super_admin')"
        );
    }
}
