<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// "Edit order" (blueprint R-29, §12.11): while the order is unpaid — products, delivery option, payment method,
// discount; until it is sent — address and phone. Each part is its own form; OrderEditor checks every rule, works the
// total out again, tells the customer and writes the audit log.
$current_admin = AdminSession::requireLogin('orders.manage');
$admin_id      = (int) $current_admin['admin_id'];
$database      = Database::instance();
$order_manager = new OrderManager($database);
$editor        = new OrderEditor($database);

$order_id   = (int) ($_GET['id'] ?? 0);
$order      = adminLoadOrRedirect(fn () => $order_manager->getOrderForAdmin($order_id), 'orders.php');
$order_page = url("admin/order_details.php?id={$order_id}");
$rules      = $editor->getEditRules($order_id);
$can_edit_items = $rules['can_edit_unpaid'] || $rules['can_add_to_paid'];
$is_upcountry   = $order['delivery_method']['delivery_fee_estimate'] !== null;
if (!$can_edit_items && !$rules['can_edit_address']) {
    Session::flash('error', 'This order can no longer be edited. ' . $rules['reason']);
    redirect($order_page);
}

$form_error = adminHandleForm(function (string $form_action) use ($editor, $order_id, $admin_id, $order_page): void {
    [$save, $message] = match ($form_action) {
        'save_items'    => [fn () => $editor->updateItems($order_id, $_POST, $admin_id), 'Products saved — the customer has been told the new total.'],
        'save_delivery' => [fn () => $editor->changeDelivery($order_id, $_POST, $admin_id), 'Delivery option changed.'],
        'save_payment'  => [fn () => $editor->changePaymentMethod($order_id, $_POST, $admin_id), 'Payment method changed.'],
        'save_discount' => [fn () => $editor->setDiscount($order_id, $_POST, $admin_id), 'Discount saved.'],
        'save_address'  => [fn () => $editor->updateAddress($order_id, $_POST, $admin_id), 'Address saved.'],
        default         => throw ApiException::validation(['form_action' => 'Unknown action.']),
    };
    $save();
    Session::flash('success', $message);
    redirect($order_page);
});

// After a failed save, the form that failed shows what was typed; the others show the order
$failed  = $form_error !== null ? (string) ($_POST['form_action'] ?? '') : '';
$errors  = $form_error?->fields() ?? [];
$posted  = fn (string $form_action): array => $failed === $form_action ? $_POST : [];
$address = $order['address'];

$options        = $order_manager->getManualOrderOptions();
$order_products = $options['products'];   // used by includes/order_item_row.php
$form_items     = $failed === 'save_items'
    ? array_values(array_filter((array) ($_POST['items'] ?? []), 'is_array'))
    : array_map(fn (array $item): array => [
        'product_id' => $item['product_id'],
        'quantity'   => $item['order_item_quantity'],
        'reserved'   => $item['order_item_quantity'],   // this order already holds that stock
    ], $order['items']);
// The order keeps the district's name only: find its id in the region to pre-select it
$order_district_id = null;
foreach ($options['districts'] as $district) {
    if ((int) $district['region_id'] === (int) $address['region_id'] && $district['district_name'] === $address['district_name']) {
        $order_district_id = $district['district_id'];
    }
}
$delivery_methods = (new Checkout($database))->getDeliveryMethods((int) $address['region_id']);
$payment_methods  = (new PaymentMethod($database))->getActiveMethods();
$address_form     = $posted('save_address') ?: [
    'address_recipient_name' => $address['address_recipient_name'], 'address_phone' => $address['address_phone'],
    'region_id' => $address['region_id'], 'district_id' => $order_district_id,
    'address_street' => $address['address_street'], 'address_landmark' => $address['address_landmark'],
];
$discount_form = $posted('save_discount') ?: ['order_discount_total' => $order['order_discount_total'], 'order_discount_note' => $order['order_discount_note']];

$page_title   = 'Edit ' . $order['order_number'];
$active_menu  = 'orders';
$page_scripts = ['order_items.js', 'order_edit.js'];
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e($order_page) ?>"><i class="bi bi-arrow-left"></i> Order <?= e($order['order_number']) ?></a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="admin-panel mb-3">
    <div class="admin-panel-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold"><?= e($order['order_number']) ?> · <?= e($order['customer']['user_full_name'] ?? '') ?></div>
            <div class="small text-muted">
                Products <?= e(adminMoney($order['order_subtotal'])) ?> · Delivery <?= e(adminDeliveryFee($order)) ?>
                <?= $order['order_discount_total'] > 0 ? ' · Discount −' . e(adminMoney($order['order_discount_total'])) : '' ?>
            </div>
        </div>
        <div class="text-end">
            <div class="small text-muted">Total</div>
            <strong class="fs-5"><?= e(adminMoney($order['order_total'])) ?></strong>
            <?php if ($rules['can_add_to_paid']): ?>
                <div class="small text-muted">Paid <?= e(adminMoney($rules['amount_paid'])) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($rules['can_add_to_paid']): ?>
        <div class="admin-panel-footer small">
            <i class="bi bi-info-circle"></i> This order is paid. If the products' total goes <strong>up</strong>, the customer pays only the
            balance ("Salio la kulipa") and packing waits for it; if it goes <strong>down</strong>, the difference becomes a refund due.
        </div>
    <?php elseif (!$rules['can_edit_unpaid']): ?>
        <div class="admin-panel-footer small text-muted"><i class="bi bi-info-circle"></i> Only the address and phone can change now: <?= e($rules['reason']) ?></div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <?php if ($can_edit_items): ?>
        <div class="col-xl-8">
            <form class="admin-panel h-100" method="post" novalidate data-order-item-editor>
                <?= Csrf::field() ?>
                <div class="admin-panel-header">
                    <h2 class="admin-panel-title">Products</h2>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-add-order-item><i class="bi bi-plus-lg"></i> Add product</button>
                </div>
                <div class="admin-panel-body">
                    <div class="row small text-muted mb-2 d-none d-md-flex">
                        <div class="col-md-5">Product</div>
                        <div class="col-md-1">Stock</div>
                        <div class="col-md-1">MOQ</div>
                        <div class="col-md-2">Unit price</div>
                        <div class="col-md-2">Quantity</div>
                        <div class="col-md-1"></div>
                    </div>
                    <div data-order-items data-next-index="<?= e(count($form_items)) ?>">
                        <?php $item_errors = $failed === 'save_items' ? $errors : []; ?>
                        <?php foreach ($form_items as $item_index => $item): ?>
                            <?php require __DIR__ . '/includes/order_item_row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-order-item-template>
                        <?php
                        $item_index  = '__INDEX__';
                        $item        = [];
                        $item_errors = [];
                        require __DIR__ . '/includes/order_item_row.php';
                        ?>
                    </template>
                    <?= adminFieldError($failed === 'save_items' ? $errors : [], 'items') ?>
                    <div class="form-text mt-2">
                        A line you don't change keeps its price; a changed or new line gets today's price for its quantity.
                        Stock follows the difference. To drop everything, cancel the order instead.
                    </div>

                    <?php if ($rules['can_add_to_paid'] && $is_upcountry): ?>
                        <?php $item_form = $posted('save_items') ?: ['order_delivery_fee' => $order['order_delivery_fee']]; ?>
                        <div class="row g-2 mt-3 pt-3 border-top">
                            <div class="col-sm-4">
                                <label class="form-label" for="order_delivery_fee">Transport for the new load</label>
                                <div class="input-group">
                                    <span class="input-group-text">TZS</span>
                                    <input class="form-control<?= adminInvalidClass($item_errors, 'order_delivery_fee') ?>" type="number" min="0" step="500"
                                           id="order_delivery_fee" name="order_delivery_fee" value="<?= e($item_form['order_delivery_fee'] ?? '') ?>">
                                </div>
                                <?= adminFieldError($item_errors, 'order_delivery_fee') ?>
                            </div>
                            <div class="col-sm-8">
                                <label class="form-label" for="order_delivery_fee_note">Reason <span class="text-muted">(when it changes — the customer sees it)</span></label>
                                <input class="form-control<?= adminInvalidClass($item_errors, 'order_delivery_fee_note') ?>" id="order_delivery_fee_note"
                                       name="order_delivery_fee_note" maxlength="255" value="<?= e($item_form['order_delivery_fee_note'] ?? '') ?>"
                                       placeholder="e.g. Mzigo umeongezeka, katoni 2 zaidi">
                                <?= adminFieldError($item_errors, 'order_delivery_fee_note') ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="admin-panel-footer">
                    <button class="btn btn-chimbo" type="submit" name="form_action" value="save_items"
                            data-confirm="<?= $rules['can_add_to_paid']
                                ? 'Save these products? The customer is told the new total and pays a balance, or gets money back.'
                                : 'Save these products? The customer is told the new total.' ?>">Save products</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($rules['can_edit_unpaid']): ?>
        <div class="col-xl-4 d-flex flex-column gap-3">
            <form class="admin-panel" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Delivery option</h2></div>
                <div class="admin-panel-body">
                    <label class="form-label" for="delivery_method_id">To <?= e($address['region_name']) ?></label>
                    <select class="form-select<?= adminInvalidClass($failed === 'save_delivery' ? $errors : [], 'delivery_method_id') ?>" id="delivery_method_id" name="delivery_method_id">
                        <?php foreach ($delivery_methods as $method): ?>
                            <option value="<?= e($method['delivery_method_id']) ?>" <?= $method['delivery_method_id'] === $order['delivery_method']['delivery_method_id'] ? 'selected' : '' ?>>
                                <?= e($method['delivery_method_name']) ?> · <?= e(adminDeliveryOptionPrice($method)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($failed === 'save_delivery' ? $errors : [], 'delivery_method_id') ?>
                    <div class="form-text">Upcountry: staff confirm the transport price again before the customer pays.</div>
                </div>
                <div class="admin-panel-footer">
                    <button class="btn btn-chimbo" type="submit" name="form_action" value="save_delivery">Change delivery</button>
                </div>
            </form>

            <form class="admin-panel" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Payment method</h2></div>
                <div class="admin-panel-body">
                    <select class="form-select<?= adminInvalidClass($failed === 'save_payment' ? $errors : [], 'payment_method') ?>" name="payment_method" aria-label="Payment method">
                        <?php foreach ($payment_methods as $method): ?>
                            <option value="<?= e($method['payment_method_code']) ?>" <?= $method['payment_method_code'] === $order['order_payment_method'] ? 'selected' : '' ?>>
                                <?= e($method['payment_method_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($failed === 'save_payment' ? $errors : [], 'payment_method') ?>
                </div>
                <div class="admin-panel-footer">
                    <button class="btn btn-chimbo" type="submit" name="form_action" value="save_payment">Change payment method</button>
                </div>
            </form>

            <form class="admin-panel" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Discount</h2></div>
                <div class="admin-panel-body">
                    <?php $discount_errors = $failed === 'save_discount' ? $errors : []; ?>
                    <div class="input-group mb-2">
                        <span class="input-group-text">TZS</span>
                        <input class="form-control<?= adminInvalidClass($discount_errors, 'order_discount_total') ?>" type="number" min="0" step="100"
                               name="order_discount_total" value="<?= e($discount_form['order_discount_total'] ?? 0) ?>" aria-label="Discount amount">
                    </div>
                    <?= adminFieldError($discount_errors, 'order_discount_total') ?>
                    <input class="form-control<?= adminInvalidClass($discount_errors, 'order_discount_note') ?>" name="order_discount_note" maxlength="255"
                           value="<?= e($discount_form['order_discount_note'] ?? '') ?>" placeholder="Reason, e.g. Mteja wa kudumu" aria-label="Reason">
                    <?= adminFieldError($discount_errors, 'order_discount_note') ?>
                    <div class="form-text">Off the products total. The customer sees the reason on the receipt. 0 removes it.</div>
                </div>
                <div class="admin-panel-footer">
                    <button class="btn btn-chimbo" type="submit" name="form_action" value="save_discount">Save discount</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="col-xl-8">
        <form class="admin-panel" method="post" novalidate data-address-form>
            <?= Csrf::field() ?>
            <?php $address_errors = $failed === 'save_address' ? $errors : []; ?>
            <div class="admin-panel-header"><h2 class="admin-panel-title">Delivery address and phone</h2></div>
            <div class="admin-panel-body row g-3">
                <div class="col-sm-6">
                    <label class="form-label" for="address_recipient_name">Recipient</label>
                    <input class="form-control<?= adminInvalidClass($address_errors, 'address_recipient_name') ?>" id="address_recipient_name" name="address_recipient_name"
                           value="<?= e($address_form['address_recipient_name'] ?? '') ?>" maxlength="100">
                    <?= adminFieldError($address_errors, 'address_recipient_name') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="address_phone">Phone</label>
                    <input class="form-control<?= adminInvalidClass($address_errors, 'address_phone') ?>" type="tel" id="address_phone" name="address_phone"
                           value="<?= e($address_form['address_phone'] ?? '') ?>">
                    <?= adminFieldError($address_errors, 'address_phone') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="region_id">Region</label>
                    <select class="form-select<?= adminInvalidClass($address_errors, 'region_id') ?>" id="region_id" name="region_id">
                        <?php foreach ($options['regions'] as $region): ?>
                            <option value="<?= e($region['region_id']) ?>" <?= adminSelected($address_form, 'region_id', $region['region_id']) ?>><?= e($region['region_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($address_errors, 'region_id') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="district_id">District <span class="text-muted">(optional)</span></label>
                    <select class="form-select<?= adminInvalidClass($address_errors, 'district_id') ?>" id="district_id" name="district_id">
                        <option value="">Choose a district</option>
                        <?php foreach ($options['districts'] as $district): ?>
                            <option value="<?= e($district['district_id']) ?>" data-region="<?= e($district['region_id']) ?>" <?= adminSelected($address_form, 'district_id', $district['district_id']) ?>>
                                <?= e($district['district_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($address_errors, 'district_id') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="address_street">Street</label>
                    <input class="form-control<?= adminInvalidClass($address_errors, 'address_street') ?>" id="address_street" name="address_street"
                           value="<?= e($address_form['address_street'] ?? '') ?>" maxlength="150">
                    <?= adminFieldError($address_errors, 'address_street') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="address_landmark">Landmark <span class="text-muted">(optional)</span></label>
                    <input class="form-control<?= adminInvalidClass($address_errors, 'address_landmark') ?>" id="address_landmark" name="address_landmark"
                           value="<?= e($address_form['address_landmark'] ?? '') ?>" maxlength="150">
                    <?= adminFieldError($address_errors, 'address_landmark') ?>
                </div>
            </div>
            <div class="admin-panel-footer">
                <span class="small text-muted me-auto">The delivery option must still go to the region. The customer is told.</span>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save_address">Save address</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
