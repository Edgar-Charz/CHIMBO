<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Time-limited offers ("Ofa"): a percentage off every price level of one product, between two times.
// The backend applies them everywhere (shop, cart, checkout, staff orders, receipts) and writes the audit log.
$current_admin = AdminSession::requireLogin('products.manage');
$offer_model   = new ProductOffer(Database::instance());

$tabs = [
    'running'   => ['Running', 'bi-lightning-charge'],
    'scheduled' => ['Scheduled', 'bi-calendar-event'],
    'ended'     => ['Ended', 'bi-clock-history'],
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'running';

// "End now" (running) / "Cancel" (scheduled)
$form_error = adminHandleForm(function (string $form_action) use ($offer_model, $current_admin, $tab): void {
    if ($form_action !== 'end_offer') {
        return;
    }
    $offer = $offer_model->getOfferById(adminActionRecordId());
    $offer_model->endOffer((int) $offer['product_offer_id'], (int) $current_admin['admin_id']);
    Session::flash('success', $offer['product_offer_status'] === 'running'
        ? 'The offer has ended — customers pay the normal price again.'
        : 'The offer was cancelled and will not start.');
    redirect(url("admin/offers.php?tab={$tab}"));
});

$page_title  = 'Offers';
$active_menu = 'offers';
require __DIR__ . '/includes/header.php';
?>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">
        A percentage off every price level of a product, for a limited time (at most <?= e(ProductOffer::MAX_DAYS) ?> days).
        Banners can open all running offers (collection "offers").
    </p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/offer_edit.php')) ?>"><i class="bi bi-plus-lg"></i> New offer</a>
</div>

<nav class="page-tabs mb-3" aria-label="Offer sections">
    <?php foreach ($tabs as $tab_key => [$tab_name, $tab_icon]): ?>
        <a class="page-tab <?= $tab_key === $tab ? 'active' : '' ?>" href="<?= e(url("admin/offers.php?tab={$tab_key}")) ?>">
            <i class="bi <?= e($tab_icon) ?>"></i> <?= e($tab_name) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable-source="<?= e(url("admin/ajax/offers.php?tab={$tab}")) ?>"
           data-title="<?= e($tabs[$tab][0]) ?> offers" data-icon="<?= e($tabs[$tab][1]) ?>"
           data-ordering="false" data-searching="false" data-page-length="<?= e(ProductOffer::PER_PAGE_OPTIONS[0]) ?>"
           data-length-menu="<?= e(json_encode(ProductOffer::PER_PAGE_OPTIONS)) ?>"
           data-empty-message="<?= e(['running' => 'No offer is running right now.', 'scheduled' => 'No offers are planned.', 'ended' => 'No offers have ended yet.'][$tab]) ?>">
        <thead>
            <tr>
                <th data-column="product">Product</th>
                <th data-column="percent">Off</th>
                <th data-column="price" data-cell-class="text-nowrap">Price</th>
                <th data-column="starts" data-cell-class="text-nowrap">Starts</th>
                <th data-column="ends" data-cell-class="text-nowrap">Ends</th>
                <th data-column="by">Created by</th>
                <th data-column="status">Status</th>
                <th data-column="actions" data-cell-class="text-end" class="text-end">Actions</th>
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
