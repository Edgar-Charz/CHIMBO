<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('sellers.manage');
$seller_model  = new Seller(Database::instance());

// Row actions: the "Verified" button and activate/deactivate (one-click switches)
$form_error = adminHandleForm(function (string $form_action) use ($seller_model, $current_admin): void {
    $admin_id  = (int) $current_admin['admin_id'];
    $seller_id = adminActionRecordId();
    $seller    = $seller_model->getSellerForAdmin($seller_id);   // for the name in the message

    if ($form_action === 'toggle_verified') {
        $is_verified = !$seller['seller_is_verified'];
        $seller_model->setSellerVerified($seller_id, $is_verified, $admin_id);
        $message = $is_verified ? ' is now verified.' : ' is no longer verified.';
    } elseif ($form_action === 'toggle_status') {
        $new_status = $seller['seller_status'] === 'active' ? 'inactive' : 'active';
        $seller_model->setSellerStatus($seller_id, $new_status, $admin_id);
        $message = $new_status === 'active' ? ' is active again.' : ' is now inactive — their products are hidden.';
    } else {
        return;
    }
    Session::flash('success', $seller['seller_name'] . $message);
    redirect(url('admin/sellers.php'));
});

$sellers = $seller_model->getAllSellersForAdmin();

$page_title  = 'Sellers';
$active_menu = 'sellers';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Suppliers of the products. Verified sellers show "Muuzaji Aliyethibitishwa" in the app.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/seller_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add seller</a>
</div>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="admin-panel admin-table-panel">
    <?php if (!$sellers): ?>
        <div class="admin-empty-state">
            <i class="bi bi-shop"></i>
            <p class="mt-2 mb-0">No sellers yet. Add one before adding products.</p>
        </div>
    <?php else: ?>
            <table class="table admin-table w-100" data-datatable data-title="All sellers" data-icon="bi-shop"
                   data-order='[[0,"asc"]]' data-search-placeholder="Search sellers">
                <thead>
                    <tr>
                        <th>Seller</th>
                        <th>Phone</th>
                        <th class="text-end">Products</th>
                        <th>Status</th>
                        <th>Verified</th>
                        <th class="text-end" data-orderable="false">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sellers as $seller): ?>
                        <tr class="row-link">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="initials-avatar"><?= e(adminInitials($seller['seller_name'])) ?></span>
                                    <a class="stretched-link fw-semibold" href="<?= e(url('admin/seller_edit.php?id=' . $seller['seller_id'])) ?>">
                                        <?= e($seller['seller_name']) ?>
                                    </a>
                                </div>
                            </td>
                            <td class="text-nowrap"><?= e($seller['seller_phone'] ? Phone::format($seller['seller_phone']) : '—') ?></td>
                            <td class="text-end"><?= e(number_format((int) $seller['product_count'])) ?></td>
                            <td><?= adminStatusBadge($seller['seller_status']) ?></td>
                            <td>
                                <form class="row-action" method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="record_id" value="<?= e($seller['seller_id']) ?>">
                                    <button class="btn btn-sm <?= $seller['seller_is_verified'] ? 'btn-verified' : 'btn-outline-secondary' ?>"
                                            type="submit" name="form_action" value="toggle_verified"
                                            title="<?= $seller['seller_is_verified'] ? 'Click to remove the verified mark' : 'Click to mark as verified' ?>">
                                        <i class="bi <?= $seller['seller_is_verified'] ? 'bi-patch-check-fill' : 'bi-patch-check' ?>"></i>
                                        <?= $seller['seller_is_verified'] ? 'Verified' : 'Not verified' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end">
                                <?= adminRowActions(
                                    adminActionLink('bi-pencil', 'Edit', url('admin/seller_edit.php?id=' . $seller['seller_id'])),
                                    $seller['seller_status'] === 'active'
                                        ? adminActionButton('bi-pause-circle', 'Deactivate (hides their products)', 'toggle_status', (int) $seller['seller_id'],
                                            'Deactivate ' . $seller['seller_name'] . '? All their products are hidden from the shop.')
                                        : adminActionButton('bi-play-circle', 'Activate', 'toggle_status', (int) $seller['seller_id']),
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
