<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('categories.manage');
$categories    = (new Category(Database::instance()))->getAllCategoriesForAdmin();

$page_title  = 'Categories';
$active_menu = 'categories';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Top categories (Cosmetics, Jewelry) and their sub-categories. Products go in sub-categories.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/category_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add category</a>
</div>

<div class="admin-panel admin-table-panel">
    <?php if (!$categories): ?>
        <div class="admin-empty-state">
            <i class="bi bi-diagram-3"></i>
            <p class="mt-2 mb-0">No categories yet. Start with a top category such as "Cosmetics".</p>
        </div>
    <?php else: ?>
            <!-- Starts in tree order (each top category followed by its sub-categories); clicking a column sorts by it -->
            <table class="table admin-table w-100" data-datatable data-title="All categories" data-icon="bi-diagram-3" data-order='[]' data-page-length="50"
                   data-search-placeholder="Search categories">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Tagline</th>
                        <th class="text-end">Products</th>
                        <th class="text-end">Order</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <?php $is_top_category = $category['parent_category_id'] === null; ?>
                        <tr class="row-link <?= $is_top_category ? 'category-top-row' : '' ?>">
                            <td>
                                <div class="d-flex align-items-center gap-2 <?= $is_top_category ? '' : 'category-chip-cell' ?>">
                                    <?php if ($is_top_category): ?>
                                        <?= adminThumbnail($category['category_image_path'], 'bi-diagram-3') ?>
                                    <?php else: ?>
                                        <i class="bi bi-arrow-return-right text-muted"></i>
                                    <?php endif; ?>
                                    <a class="stretched-link" href="<?= e(url('admin/category_edit.php?id=' . $category['category_id'])) ?>">
                                        <?= e($category['category_name']) ?>
                                    </a>
                                </div>
                            </td>
                            <td class="text-muted"><?= e($category['category_tagline'] ?? '') ?></td>
                            <td class="text-end"><?= e(number_format((int) $category['product_count'])) ?></td>
                            <td class="text-end"><?= e($category['category_sort_order']) ?></td>
                            <td><?= adminStatusBadge($category['category_is_active'] ? 'active' : 'hidden') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
