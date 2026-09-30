<?php

use PHPUnit\Framework\TestCase;

/** Saved products (heart button). */
final class WishlistTest extends TestCase
{
    private Database $db;
    private Wishlist $wishlist;
    private int $user_id;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->wishlist = new Wishlist($this->db);
        $this->user_id  = (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');
    }

    public function testSavedProductsComeBackNewestFirst(): void
    {
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 7]);
        $this->db->execute('UPDATE wishlist_items SET created_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE product_id = 1');

        $this->assertSame([7, 1], $this->wishlist->getSavedProductIds($this->user_id));
        $this->assertSame([7, 1], array_column($this->wishlist->getSavedProducts($this->user_id), 'product_id'));
    }

    public function testSavingTwiceKeepsOneEntry(): void
    {
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);

        $this->assertSame([1], $this->wishlist->getSavedProductIds($this->user_id));
    }

    public function testEachCustomerSeesOnlyTheirOwnList(): void
    {
        $other_user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255754000222');
        $this->wishlist->saveProduct($other_user_id, ['product_id' => 1]);

        $this->assertSame([], $this->wishlist->getSavedProductIds($this->user_id));
    }

    public function testHiddenProductsCannotBeSavedAndDisappearFromTheList(): void
    {
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);
        $this->db->execute('UPDATE products SET product_is_active = 0 WHERE product_id = 1');

        try {
            $this->assertSame([], $this->wishlist->getSavedProducts($this->user_id));
            $this->expectException(ApiException::class);
            $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);
        } finally {
            $this->db->execute('UPDATE products SET product_is_active = 1 WHERE product_id = 1');
        }
    }

    public function testRemove(): void
    {
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);

        $this->wishlist->removeProduct($this->user_id, 1);

        $this->assertSame([], $this->wishlist->getSavedProductIds($this->user_id));
    }

    public function testDeletingTheAccountClearsTheWishlist(): void
    {
        $this->wishlist->saveProduct($this->user_id, ['product_id' => 1]);

        (new User($this->db))->deleteAccount($this->user_id, ['confirm' => true]);

        $this->assertSame([], $this->wishlist->getSavedProductIds($this->user_id));
    }
}
