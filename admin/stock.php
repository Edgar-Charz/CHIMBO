<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The rows are loaded by the table itself from ajax/stock.php
$current_admin = AdminSession::requireLogin('inventory.manage');

$stock_options = [
    ''    => 'All products',
    'low' => 'Low stock (≤ ' . ProductEditor::LOW_STOCK_THRESHOLD . ')',
    'out' => 'Sold out',
];

$page_title  = 'Stock';
$active_menu = 'stock';
require __DIR__ . '/includes/header.php';
?>

<p class="text-muted">Click a product to add or remove stock and see its history.</p>

<form class="admin-panel filter-card" id="stock-filters">
    <div class="filter-grid">
        <div>
            <label class="form-label" for="filter-stock">Stock</label>
            <select class="form-select" id="filter-stock" name="stock">
                <?php foreach ($stock_options as $stock_value => $stock_label): ?>
                    <option value="<?= e($stock_value) ?>"><?= e($stock_label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-x-lg"></i> Clear</button>
        </div>
    </div>
</form>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable-source="<?= e(url('admin/ajax/stock.php')) ?>"
           data-filter-form="#stock-filters" data-title="Stock levels" data-icon="bi-boxes" data-order='[[3,"asc"]]'
           data-search-placeholder="Search name, brand or SKU" data-empty-message="Nothing here — stock looks good.">
        <thead>
            <tr>
                <th data-column="product" data-sort="product_name">Product</th>
                <th data-column="sku">SKU</th>
                <th data-column="moq" data-cell-class="text-end" class="text-end">MOQ</th>
                <th data-column="in_stock" data-sort="product_stock_quantity" data-cell-class="text-end text-nowrap" class="text-end">In stock</th>
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
