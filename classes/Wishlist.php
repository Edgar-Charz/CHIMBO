<?php

/**
 * Saved ("liked") products — the heart button. Each customer's list is private.
 *
 * How to use it:
 *   $wishlist = new Wishlist(Database::instance());
 *   $wishlist->saveProduct($user_id, ['product_id' => 7]);
 *   $wishlist->getSavedProducts($user_id);   // product cards, most recently saved first
 */
class Wishlist
{
    private const MAX_SAVED_PRODUCTS = 200;

    public function __construct(private Database $db)
    {
    }

    /** Just the ids — the app uses them to fill in the hearts on every product card. */
    public function getSavedProductIds(int $user_id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT product_id FROM wishlist_items WHERE user_id = :user_id ORDER BY created_at DESC, product_id DESC',
            ['user_id' => $user_id]
        );
        return array_map('intval', array_column($rows, 'product_id'));
    }

    /** The saved products as product cards, most recently saved first (hidden products are left out). */
    public function getSavedProducts(int $user_id): array
    {
        return (new Product($this->db))->getProductCardsByIds($this->getSavedProductIds($user_id));
    }

    /** Saves a product. Saving it again changes nothing. */
    public function saveProduct(int $user_id, array $input): void
    {
        $data = Validator::validate($input, ['product_id' => 'required|int|min:1']);

        $is_in_shop = $this->db->fetchValue(
            'SELECT 1 FROM products WHERE product_id = :product_id AND product_is_active = 1 AND deleted_at IS NULL',
            ['product_id' => $data['product_id']]
        );
        if (!$is_in_shop) {
            throw ApiException::notFound('Bidhaa haikupatikana.');
        }

        $saved_count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM wishlist_items WHERE user_id = :user_id', ['user_id' => $user_id]);
        if ($saved_count >= self::MAX_SAVED_PRODUCTS) {
            throw ApiException::validation(['product_id' => 'Umehifadhi bidhaa nyingi mno. Ondoa baadhi kwanza.']);
        }

        $this->db->execute(
            'INSERT IGNORE INTO wishlist_items (user_id, product_id) VALUES (:user_id, :product_id)',
            ['user_id' => $user_id, 'product_id' => $data['product_id']]
        );
    }

    /** Removes a product from the list. Removing one that isn't saved changes nothing. */
    public function removeProduct(int $user_id, int $product_id): void
    {
        $this->db->execute(
            'DELETE FROM wishlist_items WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
    }
}
