<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// "Add order": staff take an order by phone or in person. Three steps in one form (the steps are shown
// one at a time by assets/order_create.js): 1 customer + address, 2 products, 3 review + delivery + payment.
$current_admin = AdminSession::requireLogin('orders.manage');
$admin_id      = (int) $current_admin['admin_id'];
$order_manager = new OrderManager(Database::instance());
$options       = $order_manager->getManualOrderOptions();

$form_error = adminHandleForm(function () use ($order_manager, $admin_id): void {
    $order_id = $order_manager->createManualOrder($_POST, $admin_id);
    Session::flash('success', 'Order created and its stock reserved.');
    redirect(url("admin/order_details.php?id={$order_id}"));
});

$errors         = $form_error?->fields() ?? [];
$form           = adminFormValues($form_error, []);
$order_products = $options['products'];   // used by includes/order_item_row.php

$empty_item = ['product_id' => '', 'quantity' => ''];
$form_items = array_values(array_filter((array) ($form['items'] ?? []), 'is_array')) ?: [$empty_item];

// After a failed save, reopen the form where the problem is
$has_item_errors    = array_filter(array_keys($errors), fn (string $field): bool => str_starts_with($field, 'items')) !== [];
$customer_form_open = !empty($form['user_id']) || !empty($form['user_full_name']) || !empty($form['user_phone']);
$start_step = match (true) {
    $form_error === null                                              => 1,
    isset($errors['user_full_name']) || isset($errors['user_phone'])  => 1,
    $has_item_errors || !$errors                                      => 2,
    default                                                           => 3,
};

$page_title   = 'Add order';
$active_menu  = 'orders';
$page_scripts = ['order_create.js'];
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/orders.php')) ?>"><i class="bi bi-arrow-left"></i> Orders</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel" method="post" novalidate
      data-order-create
      data-customers-url="<?= e(url('admin/ajax/order_customers.php')) ?>"
      data-save-customer-url="<?= e(url('admin/ajax/order_customer_save.php')) ?>"
      data-start-step="<?= e($start_step) ?>"
      data-customer-form-open="<?= $customer_form_open ? 'true' : 'false' ?>">
    <?= Csrf::field() ?>

    <div class="order-stepper" aria-label="Steps">
        <div class="order-step-indicator" data-step-indicator="1"><span>1</span><div><strong>Customer</strong><small>Choose or add</small></div></div>
        <div class="order-step-line"></div>
        <div class="order-step-indicator" data-step-indicator="2"><span>2</span><div><strong>Products</strong><small>Items and quantities</small></div></div>
        <div class="order-step-line"></div>
        <div class="order-step-indicator" data-step-indicator="3"><span>3</span><div><strong>Review</strong><small>Delivery and payment</small></div></div>
    </div>

    <!-- Step 1: customer and delivery address -->
    <div data-order-step="1">
        <div class="admin-panel-header"><h2 class="admin-panel-title">Customer</h2></div>
        <div class="admin-panel-body">
            <div class="alert alert-danger" data-customer-save-error hidden></div>
            <input type="hidden" name="user_id" value="<?= e($form['user_id'] ?? '') ?>" data-user-id>

            <div class="mb-3" data-customer-picker>
                <label class="form-label" for="customer-search">Find a customer by name, shop or phone</label>
                <input class="form-control" id="customer-search" type="search" autocomplete="off" placeholder="Type at least 2 characters" data-customer-search>
                <div class="list-group mt-1" data-customer-results hidden></div>
            </div>

            <div class="d-flex align-items-center justify-content-between gap-2 mb-3" data-selected-customer hidden>
                <div>
                    <strong data-selected-customer-name></strong>
                    <div class="small text-muted" data-selected-customer-phone></div>
                    <div class="small text-muted" data-selected-customer-business></div>
                </div>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-new-customer>Use a new customer</button>
            </div>

            <button class="btn btn-outline-secondary" type="button" data-toggle-customer-mode>
                <i class="bi bi-person-plus"></i> Create a new customer
            </button>

            <div class="row g-3 mt-1" data-new-customer-fields hidden>
                <div class="col-md-6">
                    <label class="form-label" for="user_full_name">Customer name</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'user_full_name') ?>" id="user_full_name" name="user_full_name"
                           value="<?= e($form['user_full_name'] ?? '') ?>" maxlength="100" required disabled>
                    <?= adminFieldError($errors, 'user_full_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="user_phone">Customer phone</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'user_phone') ?>" type="tel" id="user_phone" name="user_phone"
                           value="<?= e($form['user_phone'] ?? '') ?>" placeholder="0712 345 678" required disabled>
                    <?= adminFieldError($errors, 'user_phone') ?>
                </div>
            </div>
        </div>

        <div class="admin-panel-header border-top"><h2 class="admin-panel-title">Delivery address</h2></div>
        <div class="admin-panel-body">
            <div class="mb-3" data-saved-address-picker hidden>
                <label class="form-label" for="saved-address">Saved address</label>
                <select class="form-select" id="saved-address" data-saved-address>
                    <option value="">Enter a new delivery address</option>
                </select>
            </div>
            <input type="hidden" name="address_id" value="<?= e($form['address_id'] ?? '') ?>" data-address-id>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="address_recipient_name">Recipient name</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'address_recipient_name') ?>" id="address_recipient_name" name="address_recipient_name"
                           value="<?= e($form['address_recipient_name'] ?? '') ?>" maxlength="100" required data-address-field>
                    <?= adminFieldError($errors, 'address_recipient_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="address_phone">Recipient phone</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'address_phone') ?>" type="tel" id="address_phone" name="address_phone"
                           value="<?= e($form['address_phone'] ?? '') ?>" placeholder="0712 345 678" required data-address-field>
                    <?= adminFieldError($errors, 'address_phone') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="region_id">Region</label>
                    <select class="form-select<?= adminInvalidClass($errors, 'region_id') ?>" id="region_id" name="region_id" required data-address-field>
                        <option value="">Choose a region</option>
                        <?php foreach ($options['regions'] as $region): ?>
                            <option value="<?= e($region['region_id']) ?>" <?= adminSelected($form, 'region_id', $region['region_id']) ?>><?= e($region['region_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($errors, 'region_id') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="district_id">District <span class="text-muted">(optional)</span></label>
                    <select class="form-select<?= adminInvalidClass($errors, 'district_id') ?>" id="district_id" name="district_id" data-address-field>
                        <option value="">Choose a district</option>
                        <?php foreach ($options['districts'] as $district): ?>
                            <option value="<?= e($district['district_id']) ?>" data-region="<?= e($district['region_id']) ?>" <?= adminSelected($form, 'district_id', $district['district_id']) ?>>
                                <?= e($district['district_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($errors, 'district_id') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="address_street">Street / area</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'address_street') ?>" id="address_street" name="address_street"
                           value="<?= e($form['address_street'] ?? '') ?>" maxlength="160" required data-address-field>
                    <?= adminFieldError($errors, 'address_street') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="address_landmark">Landmark <span class="text-muted">(optional)</span></label>
                    <input class="form-control<?= adminInvalidClass($errors, 'address_landmark') ?>" id="address_landmark" name="address_landmark"
                           value="<?= e($form['address_landmark'] ?? '') ?>" maxlength="160" data-address-field>
                    <?= adminFieldError($errors, 'address_landmark') ?>
                </div>
            </div>
        </div>
        <div class="admin-panel-footer">
            <button class="btn btn-chimbo" type="button" data-step-next="1">Continue to products <i class="bi bi-arrow-right"></i></button>
        </div>
    </div>

    <!-- Step 2: products -->
    <div data-order-step="2" hidden>
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
                <?php $item_errors = $errors; ?>
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

            <div class="text-danger small mt-2" data-order-items-error hidden>Add at least one product to continue.</div>
            <?= adminFieldError($errors, 'items') ?>
            <div class="form-text mt-2">Each line uses the tier price reached by its quantity. Stock is reserved when the order is created.</div>
        </div>
        <div class="admin-panel-footer justify-content-between">
            <button class="btn btn-light" type="button" data-step-back="2"><i class="bi bi-arrow-left"></i> Back</button>
            <button class="btn btn-chimbo" type="button" data-step-next="2">Continue to review <i class="bi bi-arrow-right"></i></button>
        </div>
    </div>

    <!-- Step 3: review, delivery and payment -->
    <div data-order-step="3" hidden>
        <div class="admin-panel-header"><h2 class="admin-panel-title">Delivery and payment</h2></div>
        <div class="admin-panel-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="delivery_method_id">Delivery method</label>
                    <select class="form-select<?= adminInvalidClass($errors, 'delivery_method_id') ?>" id="delivery_method_id" name="delivery_method_id" required>
                        <option value="">Choose a region first</option>
                        <?php foreach ($options['delivery_methods'] as $method): ?>
                            <option value="<?= e($method['delivery_method_id']) ?>" data-region="<?= e($method['region_id'] ?? '') ?>"
                                    data-fee="<?= e($method['delivery_method_fee']) ?>" <?= adminSelected($form, 'delivery_method_id', $method['delivery_method_id']) ?>>
                                <?= e($method['delivery_method_name']) ?> · <?= e(adminMoney((int) $method['delivery_method_fee'])) ?> · <?= e($method['delivery_days_min']) ?>–<?= e($method['delivery_days_max']) ?> days
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= adminFieldError($errors, 'delivery_method_id') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="payment_method">Payment method</label>
                    <select class="form-select<?= adminInvalidClass($errors, 'payment_method') ?>" id="payment_method" name="payment_method" required>
                        <option value="">Choose payment</option>
                        <?php foreach ($options['payment_methods'] as $payment_method): ?>
                            <option value="<?= e($payment_method) ?>" <?= adminSelected($form, 'payment_method', $payment_method) ?>><?= e(adminPaymentMethodName($payment_method)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Cash on delivery confirms the order at once; other methods wait for the payment.</div>
                    <?= adminFieldError($errors, 'payment_method') ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="order_customer_note">Order note <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control<?= adminInvalidClass($errors, 'order_customer_note') ?>" id="order_customer_note" name="order_customer_note"
                              rows="2" maxlength="500"><?= e($form['order_customer_note'] ?? '') ?></textarea>
                    <?= adminFieldError($errors, 'order_customer_note') ?>
                </div>
            </div>
        </div>
        <div class="admin-panel-header border-top"><h2 class="admin-panel-title">Summary</h2></div>
        <div class="admin-panel-body" data-order-summary></div>
        <div class="admin-panel-footer justify-content-between">
            <button class="btn btn-light" type="button" data-step-back="3"><i class="bi bi-arrow-left"></i> Back to products</button>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="<?= e(url('admin/orders.php')) ?>">Cancel</a>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><i class="bi bi-check2"></i> Create order</button>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
