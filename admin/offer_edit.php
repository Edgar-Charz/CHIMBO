<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Create or change a time-limited offer. Times are typed in Tanzania time; the class saves them in UTC,
// checks the percent (1–90), the length (at most 90 days) and that it doesn't overlap another offer of the product.
$current_admin = AdminSession::requireLogin('products.manage');
$admin_id      = (int) $current_admin['admin_id'];
$database      = Database::instance();
$offer_model   = new ProductOffer($database);

$offer_id = (int) ($_GET['id'] ?? 0);
$is_new   = $offer_id === 0;
$offer    = $is_new ? null : adminLoadOrRedirect(fn () => $offer_model->getOfferById($offer_id), 'offers.php');

// getOfferById() has no prices, so the product's normal price (shown next to the offer) comes from the product
$normal_price = $offer === null ? 0 : (int) (new ProductEditor($database))->getProductForAdmin((int) $offer['product_id'])['product_price'];

if ($offer !== null && $offer['product_offer_status'] === 'ended') {
    Session::flash('error', 'This offer has ended and can no longer be changed. Create a new one instead.');
    redirect(url('admin/offers.php?tab=ended'));
}

$form_error = adminHandleForm(function () use ($offer_model, $offer_id, $is_new, $admin_id): void {
    if ($is_new) {
        $saved_offer_id = $offer_model->createOffer($_POST, $admin_id);
        Session::flash('success', 'Offer saved. Customers see the lower price while it runs.');
    } else {
        $offer_model->updateOffer($offer_id, $_POST, $admin_id);
        $saved_offer_id = $offer_id;
        Session::flash('success', 'Offer updated.');
    }
    // Back to the tab the offer is now in (running or scheduled)
    redirect(url('admin/offers.php?tab=' . $offer_model->getOfferById($saved_offer_id)['product_offer_status']));
});

// Saved times are UTC; the datetime inputs show and take Tanzania time
$saved_values = $offer ?? ['product_id' => (int) ($_GET['product_id'] ?? 0)];
foreach (['product_offer_starts_at', 'product_offer_ends_at'] as $time_field) {
    $saved_values[$time_field] = empty($saved_values[$time_field]) ? '' : adminDateTime($saved_values[$time_field], 'Y-m-d\TH:i');
}
$errors = $form_error?->fields() ?? [];
$form   = adminFormValues($form_error, $saved_values);

// The products to choose from (a new offer only): every product, A–Z, with its normal price
$products = [];
if ($is_new) {
    $product_editor = new ProductEditor($database);
    $page = 1;
    do {
        $result   = $product_editor->getProductsForAdmin(['page' => $page, 'per_page' => 100, 'sort' => 'product_name', 'direction' => 'asc']);
        $products = array_merge($products, $result['items']);
        $page++;
    } while (count($products) < $result['total'] && $result['items'] !== []);
}

$page_title  = $is_new ? 'New offer' : 'Edit offer';
$active_menu = 'offers';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/offers.php')) ?>"><i class="bi bi-arrow-left"></i> Offers</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel admin-form-narrow" method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="admin-panel-header">
        <h2 class="admin-panel-title"><?= $is_new ? 'New offer' : 'Offer on ' . e($offer['product_name']) ?></h2>
        <?php if (!$is_new): ?>
            <?= adminStatusBadge($offer['product_offer_status']) ?>
        <?php endif; ?>
    </div>
    <div class="admin-panel-body">
        <div class="mb-3">
            <label class="form-label" for="product_id">Product</label>
            <?php if ($is_new): ?>
                <select class="form-select<?= adminInvalidClass($errors, 'product_id') ?>" id="product_id" name="product_id" required>
                    <option value="">Choose a product…</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= e($product['product_id']) ?>" <?= adminSelected($form, 'product_id', $product['product_id']) ?>>
                            <?= e($product['product_name']) ?> · <?= e(adminMoney((int) $product['product_price'])) ?><?= $product['product_is_active'] ? '' : ' (hidden)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= adminFieldError($errors, 'product_id') ?>
            <?php else: ?>
                <div class="form-control-plaintext fw-semibold">
                    <a href="<?= e(url('admin/product_edit.php?id=' . $offer['product_id'])) ?>"><?= e($offer['product_name']) ?></a>
                    <span class="text-muted fw-normal">· normal price <?= e(adminMoney($normal_price)) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="product_offer_percent">Discount</label>
            <div class="input-group admin-input-medium">
                <input class="form-control<?= adminInvalidClass($errors, 'product_offer_percent') ?>" type="number" min="1" max="<?= e(ProductOffer::MAX_PERCENT) ?>"
                       id="product_offer_percent" name="product_offer_percent" value="<?= e($form['product_offer_percent'] ?? '') ?>" required>
                <span class="input-group-text">% off</span>
            </div>
            <div class="form-text">1–<?= e(ProductOffer::MAX_PERCENT) ?>%, taken off every price level of the product.</div>
            <?php if (!$is_new): ?>
                <div class="form-text">Now: <?= e(adminOfferPrice($normal_price, (int) $offer['product_offer_percent'])) ?></div>
            <?php endif; ?>
            <?= adminFieldError($errors, 'product_offer_percent') ?>
        </div>

        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="product_offer_starts_at">Starts</label>
                <input class="form-control<?= adminInvalidClass($errors, 'product_offer_starts_at') ?>" type="datetime-local" id="product_offer_starts_at"
                       name="product_offer_starts_at" value="<?= e($form['product_offer_starts_at'] ?? '') ?>">
                <div class="form-text">Leave empty to start now.</div>
                <?= adminFieldError($errors, 'product_offer_starts_at') ?>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="product_offer_ends_at">Ends</label>
                <input class="form-control<?= adminInvalidClass($errors, 'product_offer_ends_at') ?>" type="datetime-local" id="product_offer_ends_at"
                       name="product_offer_ends_at" value="<?= e($form['product_offer_ends_at'] ?? '') ?>" required>
                <div class="form-text">At most <?= e(ProductOffer::MAX_DAYS) ?> days after the start.</div>
                <?= adminFieldError($errors, 'product_offer_ends_at') ?>
            </div>
            <div class="col-12 form-text mt-1">Tanzania time.</div>
        </div>
    </div>
    <div class="admin-panel-footer">
        <a class="btn btn-light" href="<?= e(url('admin/offers.php')) ?>">Cancel</a>
        <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Create offer' : 'Save changes' ?></button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
