<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('banners.manage');
$banners       = (new Banner(Database::instance()))->getAllBannersForAdmin();
$now_utc       = gmdate('Y-m-d H:i:s');

/** What the customer sees right now: hidden, scheduled (starts later), ended or active. */
$banner_state = fn (array $banner): string => match (true) {
    !$banner['banner_is_active']                                                   => 'hidden',
    $banner['banner_starts_at'] !== null && $banner['banner_starts_at'] > $now_utc => 'scheduled',
    $banner['banner_ends_at'] !== null && $banner['banner_ends_at'] <= $now_utc    => 'ended',
    default                                                                        => 'active',
};

$target_names = ['category' => 'Category', 'product' => 'Product', 'collection' => 'Collection', 'url' => 'Web address'];

$page_title  = 'Banners';
$active_menu = 'banners';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Promotions on the app's Home screen, shown in this order.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/banner_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add banner</a>
</div>

<div class="admin-panel admin-table-panel">
    <?php if (!$banners): ?>
        <div class="admin-empty-state">
            <i class="bi bi-images"></i>
            <p class="mt-2 mb-0">No banners yet.</p>
        </div>
    <?php else: ?>
            <table class="table admin-table w-100" data-datatable data-title="All banners" data-icon="bi-images"
                   data-order='[[3,"asc"]]' data-search-placeholder="Search banners">
                <thead>
                    <tr>
                        <th>Banner</th>
                        <th>Opens</th>
                        <th>Shows</th>
                        <th class="text-end">Order</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($banners as $banner): ?>
                        <tr class="row-link">
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($banner['banner_image_path']): ?>
                                        <img class="banner-thumb" src="<?= e(url($banner['banner_image_path'])) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <span class="banner-thumb list-thumb-empty"><i class="bi bi-image"></i></span>
                                    <?php endif; ?>
                                    <div>
                                        <a class="stretched-link fw-semibold" href="<?= e(url('admin/banner_edit.php?id=' . $banner['banner_id'])) ?>">
                                            <?= e($banner['banner_title']) ?>
                                        </a>
                                        <div class="small text-muted"><?= e($banner['banner_subtitle'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($banner['banner_target_type']): ?>
                                    <?= e($target_names[$banner['banner_target_type']]) ?>: <span class="text-muted"><?= e($banner['banner_target_value']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Nothing</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-nowrap" data-order="<?= e($banner['banner_starts_at'] ?? '') ?>">
                                <?php if ($banner['banner_starts_at'] === null && $banner['banner_ends_at'] === null): ?>
                                    <span class="text-muted">Always</span>
                                <?php else: ?>
                                    <?= e(adminDateTime($banner['banner_starts_at'])) ?> → <?= e(adminDateTime($banner['banner_ends_at'])) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= e($banner['banner_sort_order']) ?></td>
                            <td><?= adminStatusBadge($banner_state($banner)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
