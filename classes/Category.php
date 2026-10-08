<?php

/**
 * Product categories: top categories (Cosmetics, Jewelry) and their chips (Skin Care, Earrings …).
 * Only two levels: a chip's parent must be a top category. Products always belong to a chip.
 *
 * Shop:  getCategoryTree(), getCategoryById()
 * Staff: getAllCategoriesForAdmin(), getCategoryForAdmin(), createCategory(), updateCategory(),
 *        setCategoryImage(), deleteCategory()
 */
class Category
{
    private const RULES = [
        'category_name'       => 'required|string|min:2|max:80',
        'parent_category_id'  => 'nullable|int|min:1',          // empty = a top category
        'category_tagline'    => 'nullable|string|max:120',
        'category_sort_order' => 'nullable|int|min:0|max:1000',
        'category_is_active'  => 'nullable|bool',                // unticked checkbox = hidden
    ];

    public function __construct(private Database $db)
    {
    }

    // ---------------------------------------------------------------- Staff (admin pages)

    /** Every category (hidden ones too), top categories first, each followed by its chips, with product counts. */
    public function getAllCategoriesForAdmin(): array
    {
        return $this->db->fetchAll(
            'SELECT c.category_id, c.parent_category_id, parent.category_name AS parent_category_name,
                    c.category_name, c.category_slug, c.category_tagline, c.category_image_path,
                    c.category_sort_order, c.category_is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id AND p.deleted_at IS NULL) AS product_count
             FROM categories c
             LEFT JOIN categories parent ON parent.category_id = c.parent_category_id
             ORDER BY COALESCE(parent.category_sort_order, c.category_sort_order),
                      COALESCE(c.parent_category_id, c.category_id),
                      c.parent_category_id IS NOT NULL, c.category_sort_order, c.category_name'
        );
    }

    /** One category row for the edit form. 404 if missing. */
    public function getCategoryForAdmin(int $category_id): array
    {
        $category = $this->db->fetchOne('SELECT * FROM categories WHERE category_id = :category_id', ['category_id' => $category_id]);
        if ($category === null) {
            throw ApiException::notFound('Category not found.');
        }
        return $category;
    }

    /** Only top categories — for the "parent" dropdown and for choosing a product's chip. */
    public function getTopCategories(): array
    {
        return $this->db->fetchAll(
            'SELECT category_id, category_name FROM categories WHERE parent_category_id IS NULL ORDER BY category_sort_order, category_name'
        );
    }

    /** Creates a top category or a chip. Returns the new category_id. */
    public function createCategory(array $input, int $admin_id): int
    {
        $data = Validator::validate($input, self::RULES);
        $this->checkParent($data['parent_category_id'] ?? null, null);

        $category_id = $this->db->insert(
            'INSERT INTO categories (parent_category_id, category_name, category_slug, category_tagline, category_sort_order, category_is_active)
             VALUES (:parent_category_id, :category_name, :category_slug, :category_tagline, :category_sort_order, :category_is_active)',
            $this->columnValues($data, null)
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'category.created', 'category', $category_id, null, $data);
        return $category_id;
    }

    public function updateCategory(int $category_id, array $input, int $admin_id): void
    {
        $old_category = $this->getCategoryForAdmin($category_id);
        $data = Validator::validate($input, self::RULES);
        $this->checkParent($data['parent_category_id'] ?? null, $category_id);

        $this->db->execute(
            'UPDATE categories
             SET parent_category_id = :parent_category_id, category_name = :category_name, category_slug = :category_slug,
                 category_tagline = :category_tagline, category_sort_order = :category_sort_order,
                 category_is_active = :category_is_active
             WHERE category_id = :category_id',
            $this->columnValues($data, $category_id) + ['category_id' => $category_id]
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'category.updated', 'category', $category_id, $old_category, $data);
    }

    /** Uploads the picture shown on the Home category card (replaces the old one). */
    public function setCategoryImage(int $category_id, array $uploaded_file, int $admin_id): void
    {
        $old_category = $this->getCategoryForAdmin($category_id);
        $uploader     = new ImageUploader();
        $paths        = $uploader->saveUploadedFile($uploaded_file, "categories/{$category_id}");

        // A category card needs one size only (medium); the other sizes are removed straight away
        $uploader->deleteImageFiles([$paths['thumb'], $paths['large']]);
        $this->db->execute(
            'UPDATE categories SET category_image_path = :category_image_path WHERE category_id = :category_id',
            ['category_image_path' => $paths['medium'], 'category_id' => $category_id]
        );
        if ($old_category['category_image_path']) {
            $uploader->deleteImageFiles([$old_category['category_image_path']]);
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'category.image_changed', 'category', $category_id);
    }

    /** Deletes an empty category. One with chips or products can only be hidden (untick "active"). */
    public function deleteCategory(int $category_id, int $admin_id): void
    {
        $old_category = $this->getCategoryForAdmin($category_id);

        $is_in_use = $this->db->fetchValue(
            'SELECT (SELECT COUNT(*) FROM categories WHERE parent_category_id = :parent_id)
                  + (SELECT COUNT(*) FROM products WHERE category_id = :category_id)',
            ['parent_id' => $category_id, 'category_id' => $category_id]
        );
        if ($is_in_use > 0) {
            throw ApiException::conflict('CATEGORY_IN_USE', 'This category has chips or products. Hide it instead of deleting it.');
        }

        $this->db->execute('DELETE FROM categories WHERE category_id = :category_id', ['category_id' => $category_id]);
        if ($old_category['category_image_path']) {
            (new ImageUploader())->deleteImageFiles([$old_category['category_image_path']]);
        }
        (new AuditLog($this->db))->record('admin', $admin_id, 'category.deleted', 'category', $category_id, $old_category);
    }

    /**
     * Two levels only: a parent must be a top category, a category can't be its own parent,
     * and a top category that already has chips can't become a chip.
     */
    private function checkParent(?int $parent_category_id, ?int $category_id): void
    {
        if ($parent_category_id === null) {
            return;
        }

        $parent_is_top_category = $this->db->fetchValue(
            'SELECT 1 FROM categories WHERE category_id = :category_id AND parent_category_id IS NULL',
            ['category_id' => $parent_category_id]
        );
        $has_chips = $category_id !== null && $this->db->fetchValue(
            'SELECT 1 FROM categories WHERE parent_category_id = :category_id LIMIT 1',
            ['category_id' => $category_id]
        );

        if (!$parent_is_top_category || $parent_category_id === $category_id || $has_chips) {
            throw ApiException::validation(['parent_category_id' => 'Choose a top category as the parent (only two levels are allowed).']);
        }
    }

    /** Validated data → the values for the INSERT/UPDATE (slug made unique, checkbox unticked = hidden). */
    private function columnValues(array $data, ?int $category_id): array
    {
        $parent_name = isset($data['parent_category_id'])
            ? $this->db->fetchValue('SELECT category_name FROM categories WHERE category_id = :id', ['id' => $data['parent_category_id']])
            : '';

        return [
            'parent_category_id'  => $data['parent_category_id'] ?? null,
            'category_name'       => $data['category_name'],
            // chips include the parent in the slug: "cosmetics-skin-care"
            'category_slug'       => Slug::unique($this->db, trim("{$parent_name} {$data['category_name']}"), 'categories', 'category_slug', 'category_id', $category_id),
            'category_tagline'    => $data['category_tagline'] ?? null,
            'category_sort_order' => $data['category_sort_order'] ?? 0,
            'category_is_active'  => (int) ($data['category_is_active'] ?? false),
        ];
    }

    // ---------------------------------------------------------------- Shop (API)

    /** All active top categories, each with its chips in "children". */
    public function getCategoryTree(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT category_id, parent_category_id, category_name, category_slug, category_tagline, category_image_path
             FROM categories
             WHERE category_is_active = 1
             ORDER BY category_sort_order, category_name'
        );

        // First the top categories, then put each chip under its parent
        $tree = [];
        foreach ($rows as $row) {
            if ($row['parent_category_id'] === null) {
                $tree[$row['category_id']] = $this->formatCategory($row) + ['children' => []];
            }
        }
        foreach ($rows as $row) {
            if ($row['parent_category_id'] !== null && isset($tree[$row['parent_category_id']])) {
                $tree[$row['parent_category_id']]['children'][] = $this->formatCategory($row);
            }
        }

        return array_values($tree);
    }

    /** One top category with its chips. Throws 404 if it does not exist. */
    public function getCategoryById(int $category_id): array
    {
        foreach ($this->getCategoryTree() as $category) {
            if ($category['category_id'] === $category_id) {
                return $category;
            }
        }
        throw ApiException::notFound('Aina hii ya bidhaa haipo.');
    }

    private function formatCategory(array $row): array
    {
        return [
            'category_id'        => (int) $row['category_id'],
            'category_name'      => $row['category_name'],
            'category_slug'      => $row['category_slug'],
            'category_tagline'   => $row['category_tagline'],
            'category_image_url' => $row['category_image_path'] ? url($row['category_image_path']) : null,
        ];
    }


    /** "Hide / Show in the shop": changes only category_is_active. Returns false when nothing changed. */
    public function setCategoryActive(int $category_id, bool $is_active, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('categories', 'category_id', $category_id, 'category_is_active', (int) $is_active, $admin_id, 'category');
    }
}
