<?php

/**
 * Products for the shop: listing with filters (Gundua, Home rails) and product cards.
 *
 * Prices come from product_price_tiers: the highest tier price is the normal price
 * ("product_price"), the lowest is the best wholesale price ("kuanzia", product_price_from).
 *
 * How to use it:
 *   $product_model = new Product(Database::instance());
 *   $page = $product_model->getProducts(['category_id' => 1, 'sort' => 'price_asc', 'page' => 2]);
 *   // → ['items' => [...product cards...], 'total' => 34, 'page' => 2, 'per_page' => 20]
 */
class Product
{
    private const DEFAULT_PER_PAGE = 20;

    // How each "sort" choice orders the list. Only these fixed strings ever reach the SQL.
    private const SORT_ORDERS = [
        'popular'    => 'p.product_is_bestseller DESC, p.product_sold_count DESC, p.product_id DESC',
        'newest'     => 'p.created_at DESC, p.product_id DESC',
        'price_asc'  => 'prices.product_price ASC, p.product_id DESC',
        'price_desc' => 'prices.product_price DESC, p.product_id DESC',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * One page of product cards.
     * Filters (all optional): category_id (a top category includes its chips), q (search text),
     * collection (deals | new | best_sellers), min_price, max_price, max_moq,
     * sort (popular | newest | price_asc | price_desc), page, per_page (max 50).
     */
    public function getProducts(array $input): array
    {
        $filters = Validator::validate($input, [
            'category_id' => 'nullable|int|min:1',
            'q'           => 'nullable|string|max:100',   // search text
            'collection'  => 'nullable|in:deals,new,best_sellers',
            'min_price'   => 'nullable|int|min:0',
            'max_price'   => 'nullable|int|min:0',
            'max_moq'     => 'nullable|int|min:1',
            'sort'        => 'nullable|in:popular,newest,price_asc,price_desc',
            'page'        => 'nullable|int|min:1',
            'per_page'    => 'nullable|int|min:1|max:50',
        ]);

        $page     = $filters['page'] ?? 1;
        $per_page = $filters['per_page'] ?? self::DEFAULT_PER_PAGE;
        $offset   = ($page - 1) * $per_page;

        [$where_sql, $params] = $this->buildFilters($filters);
        $order_sql = self::SORT_ORDERS[$this->chooseSort($filters)];

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) ' . $this->productFromSql() . " WHERE {$where_sql}", $params);

        // $per_page and $offset are validated whole numbers, so they can be written into the SQL
        $rows = $this->db->fetchAll(
            $this->productSelectSql() . ' ' . $this->productFromSql()
            . " WHERE {$where_sql} ORDER BY {$order_sql} LIMIT {$per_page} OFFSET {$offset}",
            $params
        );

        return [
            'items'    => array_map(fn (array $row) => $this->formatProductCard($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /** A short list for a Home rail, e.g. getCollection('deals', 10). */
    public function getCollection(string $collection, int $limit): array
    {
        return $this->getProducts(['collection' => $collection, 'per_page' => $limit])['items'];
    }

    /**
     * Product cards for these ids, in the same order as the ids (used by the wishlist, and later
     * "recently ordered"). Products no longer in the shop are left out.
     */
    public function getProductCardsByIds(array $product_ids): array
    {
        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        if ($product_ids === []) {
            return [];
        }

        // One named placeholder per id: :id_0, :id_1 …
        $placeholders = [];
        $params       = [];
        foreach ($product_ids as $index => $product_id) {
            $placeholders[]        = ":id_{$index}";
            $params["id_{$index}"] = $product_id;
        }

        $rows = $this->db->fetchAll(
            $this->productSelectSql() . ' ' . $this->productFromSql()
            . ' WHERE p.product_is_active = 1 AND p.deleted_at IS NULL AND p.product_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $cards_by_id = [];
        foreach ($rows as $row) {
            $cards_by_id[(int) $row['product_id']] = $this->formatProductCard($row);
        }

        // Keep the requested order
        return array_values(array_filter(array_map(fn (int $product_id) => $cards_by_id[$product_id] ?? null, $product_ids)));
    }

    /**
     * The product page: everything on the card, plus description, stock, delivery days,
     * the tier price table and every photo in order. Throws 404 if the product is not in the shop.
     */
    public function getProductById(int $product_id): array
    {
        $row = $this->db->fetchOne(
            $this->productSelectSql() . ',
                    p.product_brand, p.product_description, p.product_delivery_days_min, p.product_delivery_days_max,
                    c.category_name, parent.category_name AS parent_category_name
             ' . $this->productFromSql() . '
             LEFT JOIN categories parent ON parent.category_id = c.parent_category_id
             WHERE p.product_id = :product_id AND p.product_is_active = 1 AND p.deleted_at IS NULL',
            ['product_id' => $product_id]
        );

        if ($row === null) {
            throw ApiException::notFound('Bidhaa haikupatikana.');
        }

        $gallery = (new ProductImage($this->db))->getImages($product_id);

        return $this->formatProductCard($row) + [
            'product_brand'             => $row['product_brand'],
            'product_description'       => $row['product_description'],
            'category_name'             => $row['category_name'],          // the chip, e.g. "Skin Care"
            'parent_category_name'      => $row['parent_category_name'],   // e.g. "Cosmetics"
            'product_stock_quantity'    => (int) $row['product_stock_quantity'],
            'product_delivery_days_min' => (int) $row['product_delivery_days_min'],
            'product_delivery_days_max' => (int) $row['product_delivery_days_max'],
            'tiers'                     => $this->getPriceTiers($product_id),
            'images'                    => array_column($gallery, 'product_image_medium_url'), // big photo + thumbnails, in order
            'gallery'                   => $gallery,                                           // every size (zoom, website)
        ];
    }

    /** Other products from the same chip ("You may also like"), most popular first. */
    public function getRelatedProducts(int $product_id, int $limit = 10): array
    {
        $category_id = $this->db->fetchValue('SELECT category_id FROM products WHERE product_id = :product_id', ['product_id' => $product_id]);
        if ($category_id === null) {
            throw ApiException::notFound('Bidhaa haikupatikana.');
        }

        $same_category = $this->getProducts(['category_id' => (int) $category_id, 'per_page' => $limit + 1])['items'];
        $related       = array_filter($same_category, fn (array $card) => $card['product_id'] !== $product_id);

        return array_slice(array_values($related), 0, $limit);
    }

    /** The tiers of several products in one query: [product_id => tiers] (used by the cart). */
    public function getPriceTiersForProducts(array $product_ids): array
    {
        $tiers_by_product = [];
        foreach (array_unique(array_map('intval', $product_ids)) as $product_id) {
            $tiers_by_product[$product_id] = [];
        }
        if ($tiers_by_product === []) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT product_id, tier_min_quantity, tier_unit_price FROM product_price_tiers
             WHERE product_id IN (' . implode(', ', array_keys($tiers_by_product)) . ')
             ORDER BY product_id, tier_min_quantity'
        ); // the ids were converted to whole numbers above, so they are safe in the SQL

        foreach ($rows as $row) {
            $tiers_by_product[(int) $row['product_id']][] = [
                'tier_min_quantity' => (int) $row['tier_min_quantity'],
                'tier_unit_price'   => (int) $row['tier_unit_price'],
            ];
        }
        return $tiers_by_product;
    }

    /** "Nunua zaidi, lipa kidogo": [{tier_min_quantity: 1, tier_unit_price: 5500}, {6, 5000} …], smallest quantity first. */
    public function getPriceTiers(int $product_id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT tier_min_quantity, tier_unit_price FROM product_price_tiers
             WHERE product_id = :product_id ORDER BY tier_min_quantity',
            ['product_id' => $product_id]
        );

        return array_map(fn (array $row) => [
            'tier_min_quantity' => (int) $row['tier_min_quantity'],
            'tier_unit_price'   => (int) $row['tier_unit_price'],
        ], $rows);
    }

    /** Turns the WHERE conditions into SQL + values. Each value is a named placeholder, never pasted in. */
    private function buildFilters(array $filters): array
    {
        $conditions = ['p.product_is_active = 1', 'p.deleted_at IS NULL'];
        $params     = [];

        if (isset($filters['category_id'])) {
            // A top category (Cosmetics) shows the products of all its chips (Skin Care, Hair Care …)
            $conditions[] = '(p.category_id = :category_id OR c.parent_category_id = :parent_category_id)';
            $params['category_id']        = $filters['category_id'];
            $params['parent_category_id'] = $filters['category_id'];
        }

        if (isset($filters['q'])) {
            // Escape % and _ so they are searched as normal characters
            $search_text = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $conditions[] = '(p.product_name LIKE :search_name OR p.product_brand LIKE :search_brand)';
            $params['search_name']  = $search_text;
            $params['search_brand'] = $search_text;
        }

        $conditions[] = match ($filters['collection'] ?? null) {
            'deals'        => 'p.product_compare_at_price > prices.product_price',
            'new'          => 'p.product_new_until >= UTC_DATE()',
            'best_sellers' => 'p.product_sold_count > 0',
            default        => '1 = 1',
        };

        if (isset($filters['min_price'])) {
            $conditions[] = 'prices.product_price >= :min_price';
            $params['min_price'] = $filters['min_price'];
        }
        if (isset($filters['max_price'])) {
            $conditions[] = 'prices.product_price <= :max_price';
            $params['max_price'] = $filters['max_price'];
        }
        if (isset($filters['max_moq'])) {
            $conditions[] = 'p.product_moq <= :max_moq';
            $params['max_moq'] = $filters['max_moq'];
        }

        return [implode(' AND ', $conditions), $params];
    }

    /** "New" lists show the newest first and "best sellers" the most sold, unless the customer chose a sort. */
    private function chooseSort(array $filters): string
    {
        return $filters['sort'] ?? match ($filters['collection'] ?? null) {
            'new'   => 'newest',
            default => 'popular',
        };
    }

    /** The columns a product card needs. */
    private function productSelectSql(): string
    {
        return 'SELECT p.product_id, p.product_name, p.product_slug, p.category_id, p.product_moq, p.product_unit_label,
                       p.product_stock_quantity, p.product_compare_at_price, p.product_is_bestseller, p.product_new_until,
                       prices.product_price, prices.product_price_from,
                       s.seller_name, s.seller_is_verified,
                       image.product_image_thumb_path';
    }

    /**
     * The tables a product card is built from. Only products with at least one price tier,
     * an active seller and an active category appear in the shop.
     */
    private function productFromSql(): string
    {
        return "FROM products p
                JOIN sellers s    ON s.seller_id = p.seller_id AND s.seller_status = 'active'
                JOIN categories c ON c.category_id = p.category_id AND c.category_is_active = 1
                JOIN (
                    SELECT product_id,
                           MAX(tier_unit_price) AS product_price,
                           MIN(tier_unit_price) AS product_price_from
                    FROM product_price_tiers
                    GROUP BY product_id
                ) prices ON prices.product_id = p.product_id
                LEFT JOIN product_images image
                       ON image.product_id = p.product_id AND image.product_image_is_primary = 1";
    }

    /** A database row → the product card JSON the apps use (keys follow the column names). */
    private function formatProductCard(array $row): array
    {
        $price = (int) $row['product_price'];

        return [
            'product_id'               => (int) $row['product_id'],
            'product_name'             => $row['product_name'],
            'product_slug'             => $row['product_slug'],
            'category_id'              => (int) $row['category_id'],
            'product_price'            => $price,
            'product_price_from'       => (int) $row['product_price_from'],
            'product_compare_at_price' => $row['product_compare_at_price'] > $price ? (int) $row['product_compare_at_price'] : null,
            'product_moq'              => (int) $row['product_moq'],
            'product_unit_label'       => $row['product_unit_label'],
            'product_in_stock'         => $row['product_stock_quantity'] >= $row['product_moq'],
            'product_image_url'        => $row['product_image_thumb_path'] ? url($row['product_image_thumb_path']) : null,
            'product_badge'            => $this->chooseBadge($row, $price),
            'seller_name'              => $row['seller_name'],
            'seller_is_verified'       => (bool) $row['seller_is_verified'],
        ];
    }

    /** One badge per card at most: a deal first, then bestseller, then new. */
    private function chooseBadge(array $row, int $price): ?string
    {
        return match (true) {
            $row['product_compare_at_price'] > $price                                     => 'deal',
            (bool) $row['product_is_bestseller']                                          => 'bestseller',
            $row['product_new_until'] !== null && $row['product_new_until'] >= gmdate('Y-m-d') => 'new',
            default                                                                        => null,
        };
    }
}
