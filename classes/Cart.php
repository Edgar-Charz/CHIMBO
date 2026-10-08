<?php

/**
 * The customer's cart ("Kikapu").
 *
 * - Prices are never stored in the cart: every time it is read, each line is priced again from the
 *   product's tiers (Pricing), so a price change is never missed and the app can't send its own prices.
 * - MOQ is a hard minimum, and a quantity can't be more than the stock.
 * - Lines are grouped by top category (Cosmetics, Jewelry), like the design.
 * - Every change returns the whole priced cart, so the app always shows the server's numbers.
 *
 * How to use it:
 *   $cart = new Cart(Database::instance());
 *   $cart->addItem($user_id, ['product_id' => 1, 'quantity' => 8]);   // → the priced cart
 */
class Cart
{
    private const MAX_LINES    = 100;       // different products in one cart
    private const MAX_QUANTITY = 100000;    // pieces of one product

    public function __construct(private Database $db)
    {
    }

    /**
     * The priced cart:
     * {groups: [{category_name, items: [line …]}], summary: {line_count, piece_count, subtotal, savings, can_checkout}, warnings: […]}
     * Products that left the shop are removed from the cart and mentioned in "warnings".
     */
    public function getCart(int $user_id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT product_id, cart_item_quantity FROM cart_items WHERE user_id = :user_id ORDER BY created_at, cart_item_id',
            ['user_id' => $user_id]
        );
        $quantities = array_column($rows, 'cart_item_quantity', 'product_id');

        $priced = $this->priceItems($quantities);

        // Products that left the shop are taken out of the saved cart
        foreach ($priced['unavailable_product_ids'] as $product_id) {
            $this->deleteLine($user_id, $product_id);
        }

        return $priced['cart'];
    }

    /**
     * Website guests keep their cart in the browser. This prices that list exactly like a saved cart
     * (same shape as getCart()), but saves nothing. Lines below the MOQ or above the stock come back
     * with "line_problem"; unknown or hidden products are left out and listed in "warnings".
     * Body: {"items": [{"product_id": 1, "quantity": 8}, …]} — the same product twice is added together.
     */
    public function previewGuestCart(array $input): array
    {
        $data = Validator::validate($input, ['items' => 'required|array|max:' . self::MAX_LINES]);

        $quantities = [];
        $errors     = [];
        foreach (array_values($data['items']) as $index => $item) {
            try {
                $item = Validator::validate(is_array($item) ? $item : [], [
                    'product_id' => 'required|int|min:1',
                    'quantity'   => 'required|int|min:1|max:' . self::MAX_QUANTITY,
                ]);
                $quantities[$item['product_id']] = min(self::MAX_QUANTITY, ($quantities[$item['product_id']] ?? 0) + $item['quantity']);
            } catch (ApiException $e) {
                foreach ($e->fields() as $field => $message) {
                    $errors["items.{$index}.{$field}"] = $message;
                }
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        return $this->priceItems($quantities)['cart'];
    }

    /**
     * The ONE place a cart is priced — used by the saved cart (getCart) and the guest preview.
     * $quantities: [product_id => quantity], in the order the products were added.
     * Returns the priced cart, and the ids of products that are no longer in the shop.
     */
    private function priceItems(array $quantities): array
    {
        $product_ids = array_map('intval', array_keys($quantities));
        if ($product_ids === []) {
            return ['cart' => $this->formatCart([], []), 'unavailable_product_ids' => []];
        }

        // Name, MOQ, stock and the top category (for grouping) of each product; the ids are whole numbers
        $products = array_column($this->db->fetchAll(
            'SELECT p.product_id, p.product_name, p.product_moq, p.product_stock_quantity,
                    COALESCE(parent.category_name, c.category_name) AS group_name,
                    COALESCE(parent.category_sort_order, c.category_sort_order) AS group_sort_order
             FROM products p
             JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN categories parent ON parent.category_id = c.parent_category_id
             WHERE p.product_id IN (' . implode(', ', $product_ids) . ')'
        ), null, 'product_id');

        $product_model = new Product($this->db);
        $cards_by_id   = array_column($product_model->getProductCardsByIds($product_ids), null, 'product_id');
        $tiers_by_id   = $product_model->getPriceTiersForProducts($product_ids);
        $offers_by_id  = (new ProductOffer($this->db))->getRunningPercents($product_ids);

        // Group order follows the categories (Cosmetics before Jewelry); inside a group, the order added
        $sorted_ids = $product_ids;
        usort($sorted_ids, fn (int $first, int $second) =>
            [$products[$first]['group_sort_order'] ?? PHP_INT_MAX, array_search($first, $product_ids, true)]
            <=> [$products[$second]['group_sort_order'] ?? PHP_INT_MAX, array_search($second, $product_ids, true)]);

        $groups                  = [];
        $warnings                = [];
        $unavailable_product_ids = [];
        foreach ($sorted_ids as $product_id) {
            $product = $products[$product_id] ?? null;

            // Unknown, hidden, deleted, or its seller/category was hidden
            if ($product === null || !isset($cards_by_id[$product_id]) || ($tiers_by_id[$product_id] ?? []) === []) {
                $product_name              = $product['product_name'] ?? 'Bidhaa hii';
                $warnings[]                = ['product_id' => $product_id, 'message' => "{$product_name} haipatikani tena na imeondolewa kwenye kikapu."];
                $unavailable_product_ids[] = $product_id;
                continue;
            }

            $quantity = (int) $quantities[$product_id];
            $groups[$product['group_name']][] = [
                'product'       => $cards_by_id[$product_id],
                'cart_quantity' => $quantity,
            ] + Pricing::priceLine($tiers_by_id[$product_id], $quantity, $offers_by_id[$product_id] ?? 0) + [
                'line_problem'       => $this->lineProblem($quantity, (int) $product['product_moq'], (int) $product['product_stock_quantity']),
                'available_quantity' => (int) $product['product_stock_quantity'],
            ];
        }

        return ['cart' => $this->formatCart($groups, $warnings), 'unavailable_product_ids' => $unavailable_product_ids];
    }

    /** Adds pieces of a product (on top of what is already in the cart). Returns the priced cart. */
    public function addItem(int $user_id, array $input): array
    {
        $data    = Validator::validate($input, [
            'product_id' => 'required|int|min:1',
            'quantity'   => 'required|int|min:1|max:' . self::MAX_QUANTITY,
        ]);
        $product = $this->requireShopProduct($data['product_id']);

        $current_quantity = (int) $this->db->fetchValue(
            'SELECT cart_item_quantity FROM cart_items WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $user_id, 'product_id' => $data['product_id']]
        );
        if ($current_quantity === 0) {
            $this->checkCartSize($user_id);
        }

        $this->saveQuantity($user_id, $product, $current_quantity + $data['quantity']);
        return $this->getCart($user_id);
    }

    /** Sets the quantity of a product already in the cart (the + / − buttons). Returns the priced cart. */
    public function setQuantity(int $user_id, int $product_id, array $input): array
    {
        $data = Validator::validate($input, ['quantity' => 'required|int|min:1|max:' . self::MAX_QUANTITY]);

        $is_in_cart = $this->db->fetchValue(
            'SELECT 1 FROM cart_items WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
        if (!$is_in_cart) {
            throw ApiException::notFound('Bidhaa hii haipo kwenye kikapu.');
        }

        $this->saveQuantity($user_id, $this->requireShopProduct($product_id), $data['quantity']);
        return $this->getCart($user_id);
    }

    public function removeItem(int $user_id, int $product_id): array
    {
        $this->deleteLine($user_id, $product_id);
        return $this->getCart($user_id);
    }

    /** Empties the cart ("Hariri" → remove all; also done when an order is placed). */
    public function clearCart(int $user_id): array
    {
        $this->db->execute('DELETE FROM cart_items WHERE user_id = :user_id', ['user_id' => $user_id]);
        return $this->formatCart([], []);
    }

    /**
     * Website: after login, the guest cart kept in the browser is moved into the account.
     * Body: {"items": [{"product_id": 1, "quantity": 8}, …]}
     */
    public function mergeGuestCart(int $user_id, array $input): array
    {
        $data = Validator::validate($input, ['items' => 'required|array|max:' . self::MAX_LINES]);
        return $this->addItemsFromList($user_id, $data['items']);
    }

    /**
     * Puts a list of items in the cart — used by the guest-cart merge and by "Agiza Tena" (reorder).
     * For a product already in the cart, the bigger quantity is kept (so nothing doubles by accident).
     * Items that can't be added (hidden, below MOQ, not enough stock …) are returned in "skipped_items".
     */
    public function addItemsFromList(int $user_id, array $items): array
    {
        $skipped_items = [];
        foreach ($items as $item) {
            try {
                $item    = Validator::validate((array) $item, [
                    'product_id' => 'required|int|min:1',
                    'quantity'   => 'required|int|min:1|max:' . self::MAX_QUANTITY,
                ]);
                $product = $this->requireShopProduct($item['product_id']);

                $cart_quantity = (int) $this->db->fetchValue(
                    'SELECT cart_item_quantity FROM cart_items WHERE user_id = :user_id AND product_id = :product_id',
                    ['user_id' => $user_id, 'product_id' => $item['product_id']]
                );
                if ($cart_quantity === 0) {
                    $this->checkCartSize($user_id);
                }
                $this->saveQuantity($user_id, $product, max($cart_quantity, $item['quantity']));
            } catch (ApiException $e) {
                $reasons = $e->fields() ?: [$e->getMessage()];
                $skipped_items[] = ['product_id' => (int) ($item['product_id'] ?? 0), 'message' => array_values($reasons)[0]];
            }
        }

        return $this->getCart($user_id) + ['skipped_items' => $skipped_items];
    }

    /** Shared by add, set and merge: checks MOQ and stock, then saves the quantity. */
    private function saveQuantity(int $user_id, array $product, int $quantity): void
    {
        if ($quantity < $product['product_moq']) {
            throw ApiException::validation(['quantity' => "Kiwango cha chini cha kuagiza ni {$product['product_moq']} (MOQ)."]);
        }
        if ($quantity > self::MAX_QUANTITY) {
            throw ApiException::validation(['quantity' => 'Kiasi ni kikubwa mno.']);
        }
        if ($quantity > $product['product_stock_quantity']) {
            throw ApiException::conflict('OUT_OF_STOCK', "Samahani, zimebaki {$product['product_stock_quantity']} tu za {$product['product_name']}.");
        }

        $this->db->execute(
            'INSERT INTO cart_items (user_id, product_id, cart_item_quantity) VALUES (:user_id, :product_id, :quantity)
             ON DUPLICATE KEY UPDATE cart_item_quantity = VALUES(cart_item_quantity)',
            ['user_id' => $user_id, 'product_id' => $product['product_id'], 'quantity' => $quantity]
        );
    }

    /** The product's MOQ and stock, or 404 if it can't be bought (hidden, deleted, no price). */
    private function requireShopProduct(int $product_id): array
    {
        $product = $this->db->fetchOne(
            "SELECT p.product_id, p.product_name, p.product_moq, p.product_stock_quantity
             FROM products p
             JOIN sellers s    ON s.seller_id = p.seller_id AND s.seller_status = 'active'
             JOIN categories c ON c.category_id = p.category_id AND c.category_is_active = 1
             WHERE p.product_id = :product_id AND p.product_is_active = 1 AND p.deleted_at IS NULL
               AND p.product_price IS NOT NULL",
            ['product_id' => $product_id]
        );
        if ($product === null) {
            throw ApiException::notFound('Bidhaa haikupatikana.');
        }
        return $product;
    }

    private function checkCartSize(int $user_id): void
    {
        $line_count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM cart_items WHERE user_id = :user_id', ['user_id' => $user_id]);
        if ($line_count >= self::MAX_LINES) {
            throw ApiException::validation(['product_id' => 'Kikapu kimejaa. Agiza kwanza au ondoa baadhi ya bidhaa.']);
        }
    }

    /** Why a line can't be ordered right now (stock or MOQ changed after it was added), or null. */
    private function lineProblem(int $quantity, int $moq, int $stock): ?string
    {
        return match (true) {
            $stock < $moq     => 'out_of_stock',        // can't be bought at all now
            $quantity > $stock => 'not_enough_stock',   // lower the quantity to the available stock
            $quantity < $moq  => 'below_moq',           // the MOQ went up; raise the quantity
            default           => null,
        };
    }

    /** Groups + totals in the shape the apps use. */
    private function formatCart(array $groups, array $warnings): array
    {
        $lines = array_merge([], ...array_values($groups));

        return [
            'groups'   => array_map(
                fn (string $category_name, array $items) => ['category_name' => $category_name, 'items' => $items],
                array_keys($groups),
                array_values($groups)
            ),
            'summary'  => [
                'line_count'   => count($lines),                                        // "Bidhaa (4)" and the tab badge
                'piece_count'  => array_sum(array_column($lines, 'cart_quantity')),
                'subtotal'     => array_sum(array_column($lines, 'line_total')),
                'savings'      => array_sum(array_column($lines, 'line_savings')),       // total "Unaokoa"
                'can_checkout' => $lines !== [] && !in_array(true, array_map(fn (array $line) => $line['line_problem'] !== null, $lines), true),
            ],
            'warnings' => $warnings,
        ];
    }

    private function deleteLine(int $user_id, int $product_id): void
    {
        $this->db->execute(
            'DELETE FROM cart_items WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
    }
}
