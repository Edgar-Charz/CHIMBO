<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin  = AdminSession::requireLogin('inventory.manage');
$product_editor = new ProductEditor(Database::instance());

$product_id = (int) ($_GET['id'] ?? 0);
$product    = adminLoadOrRedirect(fn () => $product_editor->getProductForAdmin($product_id), 'stock.php');

$form_error = adminHandleForm(function () use ($product_editor, $product_id, $current_admin): void {
    $new_stock = $product_editor->adjustStock($product_id, $_POST, (int) $current_admin['admin_id']);
    Session::flash('success', 'Stock updated. Now in stock: ' . number_format($new_stock) . '.');
    redirect(url("admin/product_stock.php?id={$product_id}"));
});

$reason_names = [
    'restock'       => 'Restock',
    'adjustment'    => 'Correction',
    'return'        => 'Return',
    'order_reserve' => 'Order',
    'order_release' => 'Order cancelled',
];
// The reasons staff may choose, with a hint (orders reserve and release stock by themselves)
$staff_reason_hints = [
    'restock'    => 'new goods arrived',
    'adjustment' => 'count, damage or loss',
    'return'     => 'goods came back',
];

$errors      = $form_error?->fields() ?? [];
$form        = adminFormValues($form_error, ['movement_reason' => 'restock']);
$movements   = $product_editor->getStockMovements($product_id);
$main_photo  = array_values(array_filter($product['images'], fn (array $image): bool => $image['product_image_is_primary']))[0] ?? null;

$page_title  = 'Stock · ' . $product['product_name'];
$active_menu = 'stock';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/stock.php')) ?>"><i class="bi bi-arrow-left"></i> Stock</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="admin-panel mb-3">
            <div class="admin-panel-body d-flex align-items-center gap-3">
                <?php if ($main_photo): ?>
                    <img class="list-thumb list-thumb-large" src="<?= e($main_photo['product_image_thumb_url']) ?>" alt="">
                <?php else: ?>
                    <?= adminThumbnail(null, 'bi-box-seam') ?>
                <?php endif; ?>
                <div>
                    <div class="fw-semibold"><?= e($product['product_name']) ?></div>
                    <div class="small text-muted"><?= e($product['product_sku']) ?> · MOQ <?= e($product['product_moq']) ?></div>
                    <?php if (Admin::can($current_admin, 'products.manage')): ?>
                        <a class="small" href="<?= e(url('admin/product_edit.php?id=' . $product_id)) ?>">Edit product</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stock-now">
                <span class="text-muted small">In stock</span>
                <strong><?= e(number_format((int) $product['product_stock_quantity'])) ?></strong>
                <span class="text-muted"><?= e($product['product_unit_label']) ?></span>
            </div>
        </div>

        <form class="admin-panel" method="post" novalidate>
            <?= Csrf::field() ?>
            <div class="admin-panel-header"><h2 class="admin-panel-title">Adjust stock</h2></div>
            <div class="admin-panel-body">
                <div class="mb-3">
                    <label class="form-label" for="movement_quantity_change">Change</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'movement_quantity_change') ?>" type="number" step="1"
                           id="movement_quantity_change" name="movement_quantity_change" value="<?= e($form['movement_quantity_change'] ?? '') ?>"
                           placeholder="e.g. 50 or -3" required>
                    <div class="form-text">A positive number adds stock; a minus sign removes it.</div>
                    <?= adminFieldError($errors, 'movement_quantity_change') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="movement_reason">Reason</label>
                    <select class="form-select<?= adminInvalidClass($errors, 'movement_reason') ?>" id="movement_reason" name="movement_reason">
                        <?php foreach ($staff_reason_hints as $reason => $reason_hint): ?>
                            <option value="<?= e($reason) ?>" <?= adminSelected($form, 'movement_reason', $reason) ?>><?= e($reason_names[$reason] . ' — ' . $reason_hint) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($errors, 'movement_reason') ?>
                </div>
                <div>
                    <label class="form-label" for="movement_note">Note <span class="text-muted">(optional)</span></label>
                    <input class="form-control<?= adminInvalidClass($errors, 'movement_note') ?>" id="movement_note" name="movement_note"
                           value="<?= e($form['movement_note'] ?? '') ?>" maxlength="255" placeholder="e.g. 3 bottles broken in delivery">
                    <?= adminFieldError($errors, 'movement_note') ?>
                </div>
            </div>
            <div class="admin-panel-footer">
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save">Save change</button>
            </div>
        </form>
    </div>

    <div class="col-lg-8">
        <div class="admin-panel admin-table-panel">
            <div class="admin-panel-header"><h2 class="admin-panel-title">History</h2><span class="small text-muted">Latest 50 changes</span></div>
            <?php if (!$movements): ?>
                <div class="admin-empty-state">
                    <i class="bi bi-clock-history"></i>
                    <p class="mt-2 mb-0">No stock changes yet.</p>
                </div>
            <?php else: ?>
                    <table class="table admin-table w-100" data-datatable data-order='[[0,"desc"]]' data-page-length="10"
                           data-search-placeholder="Search history">
                        <thead>
                            <tr><th>Date</th><th class="text-end">Change</th><th>Reason</th><th>Note</th><th>By</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <?php $change = (int) $movement['movement_quantity_change']; ?>
                                <tr>
                                    <td class="text-nowrap" data-order="<?= e($movement['created_at']) ?>"><?= e(adminDateTime($movement['created_at'])) ?></td>
                                    <td class="text-end fw-semibold <?= $change > 0 ? 'text-success' : 'text-danger' ?>" data-order="<?= e($change) ?>">
                                        <?= e(($change > 0 ? '+' : '') . number_format($change)) ?>
                                    </td>
                                    <td><?= e($reason_names[$movement['movement_reason']] ?? $movement['movement_reason']) ?></td>
                                    <td class="text-muted"><?= e($movement['movement_note'] ?? '') ?></td>
                                    <td><?= e($movement['admin_full_name'] ?? 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
