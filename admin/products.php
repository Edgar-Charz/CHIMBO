<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The rows are loaded by the table itself from ajax/products.php
$current_admin   = AdminSession::requireLogin('products.manage');
$database        = Database::instance();
$category_groups = adminCategoryGroups((new Category($database))->getAllCategoriesForAdmin());
$sellers         = (new Seller($database))->getAllSellersForAdmin();

$page_title  = 'Products';
$active_menu = 'products';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Everything CHIMBO sells. Click a product to edit its prices, photos and details.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/product_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add product</a>
</div>

<form class="admin-panel filter-card" id="product-filters">
    <div class="filter-grid">
        <div>
            <label class="form-label" for="filter-category">Category</label>
            <select class="form-select" id="filter-category" name="category_id">
                <option value="">All categories</option>
                <?php foreach ($category_groups as $top_category_id => $group): ?>
                    <optgroup label="<?= e($group['category_name']) ?>">
                        <option value="<?= e($top_category_id) ?>">All <?= e($group['category_name']) ?></option>
                        <?php foreach ($group['chips'] as $chip): ?>
                            <option value="<?= e($chip['category_id']) ?>"><?= e($chip['category_name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-seller">Seller</label>
            <select class="form-select" id="filter-seller" name="seller_id">
                <option value="">All sellers</option>
                <?php foreach ($sellers as $seller): ?>
                    <option value="<?= e($seller['seller_id']) ?>"><?= e($seller['seller_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-status">Status</label>
            <select class="form-select" id="filter-status" name="status">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="hidden">Hidden</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-stock">Stock</label>
            <select class="form-select" id="filter-stock" name="stock">
                <option value="">Any stock</option>
                <option value="low">Low stock (≤ <?= e(ProductEditor::LOW_STOCK_THRESHOLD) ?>)</option>
                <option value="out">Sold out</option>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-x-lg"></i> Clear</button>
        </div>
    </div>
</form>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable-source="<?= e(url('admin/ajax/products.php')) ?>"
        data-filter-form="#product-filters" data-title="All products" data-icon="bi-box-seam" data-order='[[7,"desc"]]'
        data-search-placeholder="Search name, brand or SKU" data-empty-message="No products yet.">
        <thead>
            <tr>
                <th data-column="product" data-sort="product_name">Product</th>
                <th data-column="category" data-cell-class="text-nowrap">Category</th>
                <th data-column="seller">Seller</th>
                <th data-column="price" data-sort="product_price" data-cell-class="text-end text-nowrap" class="text-end">Price</th>
                <th data-column="moq" data-cell-class="text-end text-nowrap" class="text-end">MOQ</th>
                <th data-column="stock" data-sort="product_stock_quantity" data-cell-class="text-end" class="text-end">Stock</th>
                <th data-column="status" data-cell-class="text-nowrap">Status</th>
                <th data-column="added" data-sort="created_at" data-cell-class="text-nowrap">Added</th>
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>