<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin  = AdminSession::requireLogin('products.manage');
$admin_id       = (int) $current_admin['admin_id'];
$database       = Database::instance();
$product_editor = new ProductEditor($database);
$product_images = new ProductImage($database);
$offer_model    = new ProductOffer($database);

$product_id = (int) ($_GET['id'] ?? 0);
$is_new     = $product_id === 0;
$product    = $is_new ? null : adminLoadOrRedirect(fn () => $product_editor->getProductForAdmin($product_id), 'products.php');

$form_error = adminHandleForm(function (string $form_action) use ($product_editor, $product_images, $offer_model, $product_id, $is_new, $admin_id): void {
    $edit_page        = url("admin/product_edit.php?id={$product_id}");
    $product_image_id = (int) ($_POST['product_image_id'] ?? 0);

    switch ($form_action) {
        case 'upload_image':
            $uploads = $_FILES['product_images'] ?? [];
            $names = $uploads['name'] ?? null;
            if (!is_array($names) || $names === []) {
                throw ApiException::validation(['product_image' => 'Choose at least one photo to upload.']);
            }

            $files = [];
            foreach (array_keys($names) as $index) {
                $file = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
                    if (!isset($uploads[$field]) || !is_array($uploads[$field]) || !array_key_exists($index, $uploads[$field])) {
                        throw ApiException::validation(['product_image' => 'Choose the photos again and retry the upload.']);
                    }
                    $file[$field] = $uploads[$field][$index];
                }
                $files[] = $file;
            }

            $available_slots = ProductImage::MAX_IMAGES_PER_PRODUCT - count($product_images->getImages($product_id));
            if (count($files) > $available_slots) {
                throw ApiException::validation([
                    'product_image' => 'Choose no more than ' . $available_slots . ' photo' . ($available_slots === 1 ? '' : 's') . '.',
                ]);
            }

            foreach ($files as $file) {
                $product_images->addImage($product_id, $file, $admin_id);
            }
            Session::flash('success', count($files) . (count($files) === 1 ? ' photo added.' : ' photos added.'));
            redirect($edit_page . '#photos');

        case 'set_main_image':
            $product_images->setMainImage($product_id, $product_image_id, $admin_id);
            Session::flash('success', 'Main photo changed.');
            redirect($edit_page . '#photos');

        case 'move_image_up':
        case 'move_image_down':
            $image_ids = array_column($product_images->getImages($product_id), 'product_image_id');
            $step      = $form_action === 'move_image_up' ? -1 : 1;
            $product_images->reorderImages($product_id, adminMoveItem($image_ids, $product_image_id, $step), $admin_id);
            redirect($edit_page . '#photos');

        case 'delete_image':
            $product_images->deleteImage($product_id, $product_image_id, $admin_id);
            Session::flash('success', 'Photo deleted.');
            redirect($edit_page . '#photos');

        case 'end_offer':
            $offer = $offer_model->getOfferById(adminActionRecordId());
            if ((int) $offer['product_id'] !== $product_id) {
                throw ApiException::notFound('Offer not found.');
            }
            $offer_model->endOffer((int) $offer['product_offer_id'], $admin_id);
            Session::flash('success', $offer['product_offer_status'] === 'running' ? 'The offer has ended.' : 'The offer was cancelled.');
            redirect($edit_page . '#offers');

        case 'delete':
            $product_editor->deleteProduct($product_id, $admin_id);
            Session::flash('success', 'Product deleted.');
            redirect(url('admin/products.php'));

        default:
            if ($is_new) {
                $new_product_id = $product_editor->createProduct($_POST, $admin_id);
                Session::flash('success', 'Product created. Now add its photos.');
                redirect(url("admin/product_edit.php?id={$new_product_id}#photos"));
            }
            $product_editor->updateProduct($product_id, $_POST, $admin_id);
            Session::flash('success', 'Product saved.');
            redirect($edit_page);
    }
});

$new_product_defaults = [
    'product_unit_label'        => 'pc',
    'product_moq'               => 1,
    'product_delivery_days_min' => 2,
    'product_delivery_days_max' => 3,
    'product_is_active'         => 1,
    'tiers'                     => [],
];

$errors           = $form_error?->fields() ?? [];
$form             = adminFormValues($form_error, $product ?? $new_product_defaults);
$category_groups  = adminCategoryGroups((new Category($database))->getAllCategoriesForAdmin());
$sellers          = (new Seller($database))->getActiveSellers();
$can_adjust_stock = Admin::can($current_admin, 'inventory.manage');
$product_offers   = $is_new ? [] : $offer_model->getOffersForAdmin(['product_id' => $product_id, 'per_page' => 25])['items'];

$page_title  = $is_new ? 'Add product' : $product['product_name'];
$active_menu = 'products';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/products.php')) ?>"><i class="bi bi-arrow-left"></i> Products</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="admin-panel mb-3">
                <div class="admin-panel-header"><h2 class="admin-panel-title">Details</h2></div>
                <div class="admin-panel-body">
                    <div class="mb-3">
                        <label class="form-label" for="product_name">Name</label>
                        <input class="form-control<?= adminInvalidClass($errors, 'product_name') ?>" id="product_name" name="product_name"
                               value="<?= e($form['product_name'] ?? '') ?>" maxlength="150" placeholder="e.g. Vaseline Petroleum Jelly 400ml" required>
                        <?= adminFieldError($errors, 'product_name') ?>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="product_brand">Brand <span class="text-muted">(optional)</span></label>
                            <input class="form-control<?= adminInvalidClass($errors, 'product_brand') ?>" id="product_brand" name="product_brand"
                                   value="<?= e($form['product_brand'] ?? '') ?>" maxlength="80">
                            <?= adminFieldError($errors, 'product_brand') ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="product_sku">SKU <span class="text-muted">(stock code)</span></label>
                            <input class="form-control<?= adminInvalidClass($errors, 'product_sku') ?>" id="product_sku" name="product_sku"
                                   value="<?= e($form['product_sku'] ?? '') ?>" maxlength="60" placeholder="Leave empty to create one">
                            <?= adminFieldError($errors, 'product_sku') ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="product_description">Description <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control<?= adminInvalidClass($errors, 'product_description') ?>" id="product_description"
                                  name="product_description" rows="5" maxlength="5000"><?= e($form['product_description'] ?? '') ?></textarea>
                        <?= adminFieldError($errors, 'product_description') ?>
                    </div>
                </div>
            </div>

            <?php require __DIR__ . '/includes/product_pricing.php'; ?>
        </div>

        <div class="col-lg-4">
            <div class="admin-panel mb-3">
                <div class="admin-panel-header"><h2 class="admin-panel-title">Status</h2></div>
                <div class="admin-panel-body">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="product_is_active" name="product_is_active"
                               value="1" <?= adminChecked($form, 'product_is_active') ?>>
                        <label class="form-check-label" for="product_is_active">Active — visible in the shop</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="product_is_bestseller" name="product_is_bestseller"
                               value="1" <?= adminChecked($form, 'product_is_bestseller') ?>>
                        <label class="form-check-label" for="product_is_bestseller">Bestseller badge</label>
                    </div>
                    <label class="form-label" for="product_new_until">"Bidhaa Mpya" until</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'product_new_until') ?>" type="date" id="product_new_until"
                           name="product_new_until" value="<?= e($form['product_new_until'] ?? '') ?>">
                    <div class="form-text">Leave empty when the product is not new.</div>
                    <?= adminFieldError($errors, 'product_new_until') ?>
                </div>
            </div>

            <div class="admin-panel mb-3">
                <div class="admin-panel-header"><h2 class="admin-panel-title">Organisation</h2></div>
                <div class="admin-panel-body">
                    <div class="mb-3">
                        <label class="form-label" for="category_id">Sub-category</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'category_id') ?>" id="category_id" name="category_id" required>
                            <option value="">Choose…</option>
                            <?php foreach ($category_groups as $group): ?>
                                <optgroup label="<?= e($group['category_name']) ?>">
                                    <?php foreach ($group['chips'] as $chip): ?>
                                        <option value="<?= e($chip['category_id']) ?>" <?= adminSelected($form, 'category_id', $chip['category_id']) ?>><?= e($chip['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <?= adminFieldError($errors, 'category_id') ?>
                    </div>
                    <div>
                        <label class="form-label" for="seller_id">Seller</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'seller_id') ?>" id="seller_id" name="seller_id" required>
                            <option value="">Choose…</option>
                            <?php foreach ($sellers as $seller): ?>
                                <option value="<?= e($seller['seller_id']) ?>" <?= adminSelected($form, 'seller_id', $seller['seller_id']) ?>><?= e($seller['seller_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= adminFieldError($errors, 'seller_id') ?>
                    </div>
                </div>
            </div>

            <div class="admin-panel mb-3">
                <div class="admin-panel-header"><h2 class="admin-panel-title">Stock and delivery</h2></div>
                <div class="admin-panel-body">
                    <?php if ($is_new): ?>
                        <div class="mb-3">
                            <label class="form-label" for="product_stock_quantity">Opening stock</label>
                            <input class="form-control<?= adminInvalidClass($errors, 'product_stock_quantity') ?>" type="number" min="0"
                                   id="product_stock_quantity" name="product_stock_quantity" value="<?= e($form['product_stock_quantity'] ?? 0) ?>">
                            <?= adminFieldError($errors, 'product_stock_quantity') ?>
                        </div>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>In stock: <strong><?= e(number_format((int) $product['product_stock_quantity'])) ?></strong> <?= e($product['product_unit_label']) ?></span>
                            <?php if ($can_adjust_stock): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/product_stock.php?id=' . $product_id)) ?>">Adjust stock</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <label class="form-label">Delivery time (days)</label>
                    <div class="input-group">
                        <input class="form-control<?= adminInvalidClass($errors, 'product_delivery_days_min') ?>" type="number" min="0" max="60"
                               name="product_delivery_days_min" value="<?= e($form['product_delivery_days_min'] ?? '') ?>" aria-label="Fastest delivery in days">
                        <span class="input-group-text">to</span>
                        <input class="form-control<?= adminInvalidClass($errors, 'product_delivery_days_max') ?>" type="number" min="0" max="60"
                               name="product_delivery_days_max" value="<?= e($form['product_delivery_days_max'] ?? '') ?>" aria-label="Slowest delivery in days">
                    </div>
                    <?= adminFieldError($errors, 'product_delivery_days_min') ?>
                    <?= adminFieldError($errors, 'product_delivery_days_max') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-save-bar">
        <a class="btn btn-light" href="<?= e(url('admin/products.php')) ?>">Cancel</a>
        <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Create product' : 'Save changes' ?></button>
    </div>
</form>

<div class="row g-3 mt-0">
    <div class="col-lg-8">
        <?php require __DIR__ . '/includes/product_photos.php'; ?>
    </div>

    <?php if (!$is_new): ?>
        <div class="col-lg-4">
            <?php require __DIR__ . '/includes/product_offers.php'; ?>
            <form class="admin-panel admin-danger-zone" method="post" data-confirm="Delete this product? It disappears from the shop and from this list.">
                <?= Csrf::field() ?>
                <div class="admin-panel-body">
                    <h2 class="admin-panel-title mb-1">Delete product</h2>
                    <p class="small text-muted">To stop selling it for a while, switch off "Active" instead.</p>
                    <button class="btn btn-outline-danger w-100" type="submit" name="form_action" value="delete">Delete product</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
