<?php

/**
 * Staff side of products (admin pages): list with filters, create, edit, tier prices, stock, delete.
 * (The shop side — what customers see — is in Product; photos are in ProductImage.)
 *
 * Tier price rules ("Nunua zaidi, lipa kidogo"):
 * - 1 to 10 levels; the first level starts exactly at the product's MOQ
 * - each next level has a bigger quantity AND a lower price per piece
 *
 * How to use it:
 *   $product_editor = new ProductEditor(Database::instance());
 *   $product_id = $product_editor->createProduct($_POST, $admin_id);   // $_POST['tiers'][0]['tier_min_quantity'] …
 *   $product_editor->adjustStock($product_id, ['movement_quantity_change' => 50, 'movement_reason' => 'restock'], $admin_id);
 */
class ProductEditor
{
    public const UNIT_LABELS         = ['pc', 'pack', 'set', 'box', 'dozen', 'carton'];
    public const LOW_STOCK_THRESHOLD = 20;   // "low stock" filter and dashboard warning
    private const MAX_TIERS          = 10;
    public const PER_PAGE_OPTIONS    = [10, 25, 50, 100];
    private const DEFAULT_PER_PAGE   = 25;

    // Columns the admin list can be sorted by. Only these fixed strings ever reach ORDER BY.
    private const SORT_COLUMNS = [
        'product_name'           => 'p.product_name',
        'product_price'          => 'p.product_price',            // the normal price (highest tier), stored by saveTiers()
        'product_stock_quantity' => 'p.product_stock_quantity',
        'created_at'             => 'p.created_at',
    ];

    private const RULES = [
        'product_name'              => 'required|string|min:2|max:150',
        'seller_id'                 => 'required|int|min:1',
        'category_id'               => 'required|int|min:1',          // a chip, e.g. "Skin Care"
        'product_brand'             => 'nullable|string|max:80',
        'product_description'       => 'nullable|string|max:5000',
        'product_sku'               => 'nullable|string|max:60',      // empty → created automatically
        'product_unit_label'        => 'required|in:pc,pack,set,box,dozen,carton',
        'product_moq'               => 'required|int|min:1|max:100000',
        'product_compare_at_price'  => 'nullable|int|min:1',          // old price → shown as an offer
        'product_is_bestseller'     => 'nullable|bool',
        'product_new_until'         => 'nullable|date',
        'product_delivery_days_min' => 'required|int|min:0|max:60',
        'product_delivery_days_max' => 'required|int|min:0|max:60',
        'product_is_active'         => 'nullable|bool',               // unticked = hidden from the shop
        'tiers'                     => 'required|array|max:10',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * One page of the admin product list. Filters (all optional): q (name, brand or SKU),
     * category_id (a top category includes its chips), seller_id, status (active | hidden),
     * stock (low | out), page, per_page (10/25/50/100), sort (a SORT_COLUMNS key) + direction (asc/desc).
     * No sort chosen = newest product first.
     */
    public function getProductsForAdmin(array $input): array
    {
        $filters = Validator::validate($input, [
            'q'           => 'nullable|string|max:100',
            'category_id' => 'nullable|int|min:1',
            'seller_id'   => 'nullable|int|min:1',
            'status'      => 'nullable|in:active,hidden',
            'stock'       => 'nullable|in:low,out',
            'page'        => 'nullable|int|min:1',
            'per_page'    => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
            'sort'        => 'nullable|in:' . implode(',', array_keys(self::SORT_COLUMNS)),
            'direction'   => 'nullable|in:asc,desc',
        ]);
        $page     = $filters['page'] ?? 1;
        $per_page = $filters['per_page'] ?? self::DEFAULT_PER_PAGE;
        $offset   = ($page - 1) * $per_page;

        // The chosen column, then the product id as a tie-breaker so the order is always the same
        $order_by = isset($filters['sort'])
            ? self::SORT_COLUMNS[$filters['sort']] . ' ' . strtoupper($filters['direction'] ?? 'desc') . ', p.product_id DESC'
            : 'p.product_id DESC';

        [$where_sql, $params] = $this->buildAdminFilters($filters);
        $from_sql = 'FROM products p
                     JOIN sellers s    ON s.seller_id = p.seller_id
                     JOIN categories c ON c.category_id = p.category_id
                     LEFT JOIN categories parent ON parent.category_id = c.parent_category_id
                     LEFT JOIN product_images image ON image.product_id = p.product_id AND image.product_image_is_primary = 1';

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from_sql} WHERE {$where_sql}", $params);
        $items = $this->db->fetchAll(
            "SELECT p.product_id, p.product_name, p.product_sku, p.product_brand, p.product_moq, p.product_unit_label,
                    p.product_stock_quantity, p.product_is_active, p.product_is_bestseller, p.product_compare_at_price,
                    p.updated_at, p.created_at,
                    s.seller_name, c.category_name, parent.category_name AS parent_category_name,
                    p.product_price, p.product_price_from,
                    image.product_image_thumb_path
             {$from_sql}
             WHERE {$where_sql}
             ORDER BY {$order_by}
             LIMIT {$per_page} OFFSET {$offset}",
            $params
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** Everything for the edit form: the product row, its tiers and its photos. 404 if missing or deleted. */
    public function getProductForAdmin(int $product_id): array
    {
        $product = $this->db->fetchOne(
            'SELECT * FROM products WHERE product_id = :product_id AND deleted_at IS NULL',
            ['product_id' => $product_id]
        );
        if ($product === null) {
            throw ApiException::notFound('Product not found.');
        }

        $product['tiers']  = (new Product($this->db))->getPriceTiers($product_id);
        $product['images'] = (new ProductImage($this->db))->getImages($product_id);
        return $product;
    }

    /** Creates a product with its tier prices and opening stock. Returns the new product_id. */
    public function createProduct(array $input, int $admin_id): int
    {
        $data = Validator::validate($input, self::RULES + ['product_stock_quantity' => 'nullable|int|min:0|max:10000000']);
        $tiers = $this->checkProduct($data, null);

        return $this->db->transaction(function () use ($data, $tiers, $admin_id) {
            $product_id = $this->db->insert(
                'INSERT INTO products (
                    seller_id, category_id, product_name, product_slug, product_brand, product_description, product_sku,
                    product_unit_label, product_moq, product_compare_at_price, product_is_bestseller, product_new_until,
                    product_delivery_days_min, product_delivery_days_max, product_is_active
                 ) VALUES (
                    :seller_id, :category_id, :product_name, :product_slug, :product_brand, :product_description, :product_sku,
                    :product_unit_label, :product_moq, :product_compare_at_price, :product_is_bestseller, :product_new_until,
                    :product_delivery_days_min, :product_delivery_days_max, :product_is_active
                 )',
                $this->columnValues($data, null)
            );

            $this->saveTiers($product_id, $tiers);

            $opening_stock = $data['product_stock_quantity'] ?? 0;
            if ($opening_stock > 0) {
                $this->changeStock($product_id, $opening_stock, 'restock', 'Opening stock', $admin_id);
            }

            (new AuditLog($this->db))->record('admin', $admin_id, 'product.created', 'product', $product_id, null, $data);
            return $product_id;
        });
    }

    /** Saves the edit form: details and tier prices. Stock is changed only with adjustStock(). */
    public function updateProduct(int $product_id, array $input, int $admin_id): void
    {
        $old_product = $this->getProductForAdmin($product_id);
        $data  = Validator::validate($input, self::RULES);
        $tiers = $this->checkProduct($data, $product_id);

        $this->db->transaction(function () use ($product_id, $data, $tiers, $old_product, $admin_id) {
            $this->db->execute(
                'UPDATE products
                 SET seller_id = :seller_id, category_id = :category_id, product_name = :product_name, product_slug = :product_slug,
                     product_brand = :product_brand, product_description = :product_description, product_sku = :product_sku,
                     product_unit_label = :product_unit_label, product_moq = :product_moq,
                     product_compare_at_price = :product_compare_at_price, product_is_bestseller = :product_is_bestseller,
                     product_new_until = :product_new_until, product_delivery_days_min = :product_delivery_days_min,
                     product_delivery_days_max = :product_delivery_days_max, product_is_active = :product_is_active
                 WHERE product_id = :product_id',
                $this->columnValues($data, $product_id) + ['product_id' => $product_id]
            );

            $this->saveTiers($product_id, $tiers);

            unset($old_product['images']);
            (new AuditLog($this->db))->record('admin', $admin_id, 'product.updated', 'product', $product_id, $old_product, $data);
        });
    }

    /**
     * Adds or removes stock with a reason, e.g. +50 "restock" or −3 "adjustment" (damaged).
     * Every change is saved in inventory_movements. Stock can never go below zero.
     */
    public function adjustStock(int $product_id, array $input, int $admin_id): int
    {
        $this->getProductForAdmin($product_id); // 404 if missing
        $data = Validator::validate($input, [
            'movement_quantity_change' => 'required|int|min:-10000000|max:10000000',
            'movement_reason'          => 'required|in:restock,adjustment,return',
            'movement_note'            => 'nullable|string|max:255',
        ]);
        if ($data['movement_quantity_change'] === 0) {
            throw ApiException::validation(['movement_quantity_change' => 'Enter a number other than 0.']);
        }

        return $this->db->transaction(function () use ($product_id, $data, $admin_id) {
            $new_stock = $this->changeStock(
                $product_id, $data['movement_quantity_change'], $data['movement_reason'], $data['movement_note'] ?? null, $admin_id
            );
            (new AuditLog($this->db))->record('admin', $admin_id, 'product.stock_adjusted', 'product', $product_id, null, $data + ['new_stock' => $new_stock]);
            return $new_stock;
        });
    }

    /** The latest stock changes of a product (newest first), with the staff member's name. */
    public function getStockMovements(int $product_id, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT m.movement_id, m.movement_quantity_change, m.movement_reason, m.movement_note,
                    m.movement_reference_type, m.movement_reference_id, m.created_at, a.admin_full_name
             FROM inventory_movements m
             LEFT JOIN admins a ON a.admin_id = m.admin_id
             WHERE m.product_id = :product_id
             ORDER BY m.movement_id DESC
             LIMIT ' . max(1, min($limit, 500)),
            ['product_id' => $product_id]
        );
    }

    /** Removes the product from the shop and the admin list. The row stays for history (future orders refer to it). */
    public function deleteProduct(int $product_id, int $admin_id): void
    {
        $old_product = $this->getProductForAdmin($product_id);

        $this->db->execute(
            'UPDATE products SET deleted_at = UTC_TIMESTAMP(), product_is_active = 0 WHERE product_id = :product_id',
            ['product_id' => $product_id]
        );

        unset($old_product['images']);
        (new AuditLog($this->db))->record('admin', $admin_id, 'product.deleted', 'product', $product_id, $old_product);
    }

    /**
     * The one place stock changes: locks the product row, applies the change, refuses to go below zero,
     * and records the movement. Must run inside a transaction. Returns the new stock.
     * (Orders will use this too, with reason "order_reserve" / "order_release".)
     */
    public function changeStock(int $product_id, int $quantity_change, string $reason, ?string $note, ?int $admin_id,
                                ?string $reference_type = null, ?int $reference_id = null): int
    {
        // FOR UPDATE: two changes at the same moment wait for each other, so none is lost
        $current_stock = (int) $this->db->fetchValue(
            'SELECT product_stock_quantity FROM products WHERE product_id = :product_id FOR UPDATE',
            ['product_id' => $product_id]
        );

        $new_stock = $current_stock + $quantity_change;
        if ($new_stock < 0) {
            throw ApiException::validation(['movement_quantity_change' => "Stock cannot go below zero (current stock: {$current_stock})."]);
        }

        $this->db->execute(
            'UPDATE products SET product_stock_quantity = :new_stock WHERE product_id = :product_id',
            ['new_stock' => $new_stock, 'product_id' => $product_id]
        );
        $this->db->insert(
            'INSERT INTO inventory_movements (
                product_id, movement_quantity_change, movement_reason, movement_note, movement_reference_type, movement_reference_id, admin_id
             ) VALUES (:product_id, :quantity_change, :reason, :note, :reference_type, :reference_id, :admin_id)',
            [
                'product_id'      => $product_id,
                'quantity_change' => $quantity_change,
                'reason'          => $reason,
                'note'            => $note,
                'reference_type'  => $reference_type,
                'reference_id'    => $reference_id,
                'admin_id'        => $admin_id,
            ]
        );

        return $new_stock;
    }

    /** Checks the rules that need the database or several fields together. Returns the clean, sorted tiers. */
    private function checkProduct(array $data, ?int $product_id): array
    {
        $errors = [];

        if (!$this->db->fetchValue('SELECT 1 FROM sellers WHERE seller_id = :seller_id', ['seller_id' => $data['seller_id']])) {
            $errors['seller_id'] = 'Choose a seller.';
        }
        $is_chip = $this->db->fetchValue(
            'SELECT 1 FROM categories WHERE category_id = :category_id AND parent_category_id IS NOT NULL',
            ['category_id' => $data['category_id']]
        );
        if (!$is_chip) {
            $errors['category_id'] = 'Choose a sub-category (e.g. Skin Care), not a top category.';
        }
        if (!empty($data['product_sku']) && $this->db->fetchValue(
            'SELECT 1 FROM products WHERE product_sku = :product_sku AND product_id <> :product_id',
            ['product_sku' => $data['product_sku'], 'product_id' => $product_id ?? 0]
        )) {
            $errors['product_sku'] = 'Another product already uses this SKU.';
        }
        if ($data['product_delivery_days_min'] > $data['product_delivery_days_max']) {
            $errors['product_delivery_days_max'] = 'Must be the same as or more than the minimum days.';
        }

        $tiers = $this->cleanTiers($data['tiers'], $data['product_moq'], $errors);

        $normal_price = $tiers[0]['tier_unit_price'] ?? null;
        if (isset($data['product_compare_at_price'], $normal_price) && $data['product_compare_at_price'] <= $normal_price) {
            $errors['product_compare_at_price'] = 'The old price must be higher than the normal price (' . number_format($normal_price) . ').';
        }

        if ($errors) {
            throw ApiException::validation($errors);
        }
        return $tiers;
    }

    /**
     * Tier rows from the form → sorted, checked tiers. Empty form rows are ignored.
     * Problems are added to $errors under "tiers".
     */
    private function cleanTiers(array $tier_rows, int $moq, array &$errors): array
    {
        $tiers = [];
        foreach ($tier_rows as $tier_row) {
            $is_empty_row = trim((string) ($tier_row['tier_min_quantity'] ?? '')) === '' && trim((string) ($tier_row['tier_unit_price'] ?? '')) === '';
            if ($is_empty_row) {
                continue;
            }
            try {
                $tiers[] = Validator::validate((array) $tier_row, [
                    'tier_min_quantity' => 'required|int|min:1|max:1000000',
                    'tier_unit_price'   => 'required|int|min:1|max:100000000',
                ]);
            } catch (ApiException $e) {
                $errors['tiers'] = 'Every price level needs a quantity and a price (whole numbers).';
                return [];
            }
        }

        usort($tiers, fn (array $first, array $second) => $first['tier_min_quantity'] <=> $second['tier_min_quantity']);

        $errors['tiers'] = match (true) {
            $tiers === []                               => 'Add at least one price level.',
            count($tiers) > self::MAX_TIERS             => 'At most ' . self::MAX_TIERS . ' price levels.',
            $tiers[0]['tier_min_quantity'] !== $moq     => "The first price level must start at the MOQ ({$moq}).",
            !$this->tiersGetCheaper($tiers)             => 'Each level needs a bigger quantity and a lower price than the one before.',
            default                                     => null,
        };
        if ($errors['tiers'] === null) {
            unset($errors['tiers']);
        }

        return $tiers;
    }

    /** True when every next level has a bigger quantity and a lower price. */
    private function tiersGetCheaper(array $tiers): bool
    {
        for ($i = 1; $i < count($tiers); $i++) {
            if ($tiers[$i]['tier_min_quantity'] <= $tiers[$i - 1]['tier_min_quantity']
                || $tiers[$i]['tier_unit_price'] >= $tiers[$i - 1]['tier_unit_price']) {
                return false;
            }
        }
        return true;
    }

    /** Replaces the product's tier prices. Runs inside the create/update transaction. */
    private function saveTiers(int $product_id, array $tiers): void
    {
        $this->db->execute('DELETE FROM product_price_tiers WHERE product_id = :product_id', ['product_id' => $product_id]);
        foreach ($tiers as $tier) {
            $this->db->insert(
                'INSERT INTO product_price_tiers (product_id, tier_min_quantity, tier_unit_price)
                 VALUES (:product_id, :tier_min_quantity, :tier_unit_price)',
                ['product_id' => $product_id] + $tier
            );
        }
        $this->refreshStoredPrices($product_id);
    }

    /**
     * Copies the normal price (highest tier) and the "kuanzia" price (lowest tier) onto the product,
     * so product lists can filter and sort by price without reading every tier.
     * Call it after any change to a product's tiers.
     */
    public function refreshStoredPrices(int $product_id): void
    {
        $this->db->execute(
            'UPDATE products
             SET product_price      = (SELECT MAX(tier_unit_price) FROM product_price_tiers WHERE product_id = :tier_product_id),
                 product_price_from = (SELECT MIN(tier_unit_price) FROM product_price_tiers WHERE product_id = :min_product_id)
             WHERE product_id = :product_id',
            ['tier_product_id' => $product_id, 'min_product_id' => $product_id, 'product_id' => $product_id]
        );
    }

    /** Validated data → values for the INSERT/UPDATE (slug and SKU filled in, checkboxes to 0/1). */
    private function columnValues(array $data, ?int $product_id): array
    {
        return [
            'seller_id'                 => $data['seller_id'],
            'category_id'               => $data['category_id'],
            'product_name'              => $data['product_name'],
            'product_slug'              => Slug::unique($this->db, $data['product_name'], 'products', 'product_slug', 'product_id', $product_id),
            'product_brand'             => $data['product_brand'] ?? null,
            'product_description'       => $data['product_description'] ?? null,
            'product_sku'               => $data['product_sku'] ?? $this->newSku(),
            'product_unit_label'        => $data['product_unit_label'],
            'product_moq'               => $data['product_moq'],
            'product_compare_at_price'  => $data['product_compare_at_price'] ?? null,
            'product_is_bestseller'     => (int) ($data['product_is_bestseller'] ?? false),
            'product_new_until'         => $data['product_new_until'] ?? null,
            'product_delivery_days_min' => $data['product_delivery_days_min'],
            'product_delivery_days_max' => $data['product_delivery_days_max'],
            'product_is_active'         => (int) ($data['product_is_active'] ?? false),
        ];
    }

    /** A free stock code like "CHB-7F3A9C". */
    private function newSku(): string
    {
        do {
            $sku = 'CHB-' . strtoupper(bin2hex(random_bytes(3)));
        } while ($this->db->fetchValue('SELECT 1 FROM products WHERE product_sku = :sku', ['sku' => $sku]));
        return $sku;
    }

    private function buildAdminFilters(array $filters): array
    {
        $conditions = ['p.deleted_at IS NULL'];
        $params     = [];

        if (isset($filters['q'])) {
            $search_text  = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $conditions[] = '(p.product_name LIKE :search_name OR p.product_brand LIKE :search_brand OR p.product_sku LIKE :search_sku)';
            $params += ['search_name' => $search_text, 'search_brand' => $search_text, 'search_sku' => $search_text];
        }
        if (isset($filters['category_id'])) {
            $conditions[] = '(p.category_id = :category_id OR c.parent_category_id = :parent_category_id)';
            $params += ['category_id' => $filters['category_id'], 'parent_category_id' => $filters['category_id']];
        }
        if (isset($filters['seller_id'])) {
            $conditions[] = 'p.seller_id = :seller_id';
            $params['seller_id'] = $filters['seller_id'];
        }
        $conditions[] = match ($filters['status'] ?? null) {
            'active' => 'p.product_is_active = 1',
            'hidden' => 'p.product_is_active = 0',
            default  => '1 = 1',
        };
        $conditions[] = match ($filters['stock'] ?? null) {
            'low'   => 'p.product_stock_quantity BETWEEN 1 AND ' . self::LOW_STOCK_THRESHOLD,
            'out'   => 'p.product_stock_quantity = 0',
            default => '1 = 1',
        };

        return [implode(' AND ', $conditions), $params];
    }


    /** "Hide / Show" in the product list: changes only product_is_active. Returns false when nothing changed. */
    public function setProductActive(int $product_id, bool $is_active, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('products', 'product_id', $product_id, 'product_is_active', (int) $is_active, $admin_id, 'product', 'deleted_at IS NULL');
    }
}
