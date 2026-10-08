<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin       = AdminSession::requireLogin('orders.view');
$admin_id            = (int) $current_admin['admin_id'];
$database            = Database::instance();
$order_manager       = new OrderManager($database);
$can_change_status   = Admin::can($current_admin, 'orders.manage');
$can_confirm_cash    = Admin::can($current_admin, 'payments.confirm_cash');
$can_manage_payments = Admin::can($current_admin, 'payments.manage');   // review, record, refunds

$order_id = (int) ($_GET['id'] ?? 0);
$order    = adminLoadOrRedirect(fn () => $order_manager->getOrderForAdmin($order_id), 'orders.php');

// Each action checks its own permission: Finance may view orders but not move them
$form_error = adminHandleForm(function (string $form_action) use ($order_manager, $order_id, $admin_id, $can_change_status, $can_confirm_cash, $can_manage_payments, $database): void {
    $order_page = url("admin/order_details.php?id={$order_id}");

    // Payment actions (the classes notify the customer). Reject and refund come from dialogs, so their errors are flashed.
    if ($can_manage_payments && in_array($form_action, ['confirm_payment', 'reject_payment', 'mark_refunded'], true)) {
        $payment_model = new Payment($database);
        try {
            if ($form_action === 'confirm_payment') {
                $payment_model->confirmPayment(adminActionRecordId(), $admin_id);
                $message = 'Payment confirmed — the order is paid and the customer has been told.';
            } elseif ($form_action === 'reject_payment') {
                $payment_model->rejectPayment(adminActionRecordId(), $_POST, $admin_id);
                $message = 'Payment rejected — the customer sees your reason.';
            } else {
                $payment_model->markRefunded($order_id, $_POST, $admin_id);
                $message = 'Marked as refunded — the customer has been told.';
            }
        } catch (ApiException $e) {
            Session::flash('error', adminErrorText($e));
            redirect($order_page);
        }
        Session::flash('success', $message);
        redirect($order_page);
    }

    if ($form_action === 'record_payment' && $can_manage_payments) {
        (new Payment($database))->recordPaymentByStaff($order_id, $_POST, $admin_id);
        Session::flash('success', 'Payment recorded and confirmed — the order is paid.');
    } elseif ($form_action === 'change_status' && $can_change_status) {
        $order_manager->changeStatus($order_id, $_POST, $admin_id);
        Session::flash('success', 'The order is now "' . adminStatusName((string) $_POST['order_status']) . '". The customer has been notified.');
    } elseif ($form_action === 'confirm_cash' && $can_confirm_cash) {
        $order_manager->confirmCashCollected($order_id, $_POST, $admin_id);
        Session::flash('success', 'Cash received — the order is paid.');
    } else {
        throw ApiException::forbidden('Your role cannot do this.');
    }
    redirect($order_page);
});

$errors = $form_error?->fields() ?? [];
$posted = $form_error ? $_POST : [];   // what the admin chose, kept when saving failed

$next_statuses   = $can_change_status ? $order['allowed_next_statuses'] : [];
$delivery_agents = in_array('dispatched', $next_statuses, true) ? (new DeliveryAgent($database))->getActiveAgents() : [];
$address         = $order['address'];
$customer        = $order['customer'];
$refund_is_due   = $can_manage_payments && in_array($order['order_status'], ['cancelled', 'expired'], true)
    && $order['order_payment_status'] === 'paid';

$page_title  = 'Order ' . $order['order_number'];
$active_menu = 'orders';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/orders.php')) ?>"><i class="bi bi-arrow-left"></i> Orders</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="admin-panel mb-3">
    <div class="admin-panel-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <h2 class="h4 mb-1"><?= e($order['order_number']) ?></h2>
            <div class="text-muted">
                Placed <?= e(adminDateTime($order['order_placed_at'])) ?>
                · <?= e($order['order_channel'] === 'app' ? 'in the app' : 'on the web / by staff') ?>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?= adminStatusBadge($order['order_status']) ?>
            <?= adminStatusBadge($order['order_payment_status']) ?>
            <strong class="fs-5 ms-2"><?= e(adminMoney($order['order_total'])) ?></strong>
            <a class="btn btn-outline-secondary ms-2" href="<?= e(url('admin/order_receipt.php?id=' . $order['order_id'])) ?>"
               target="_blank" rel="noopener"><i class="bi bi-printer"></i> Print receipt</a>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="admin-panel mb-3">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title">Items</h2>
                <span class="small text-muted"><?= e($order['item_count']) ?> product(s) · <?= e(number_format($order['piece_count'])) ?> piece(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Unit price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?= adminThumbnailFromUrl($item['product_image_url'], 'bi-box-seam') ?>
                                        <div>
                                            <div class="fw-semibold"><?= e($item['product_name']) ?></div>
                                            <div class="small text-muted">
                                                Price level <?= e($item['order_item_tier_min_quantity']) ?>+ <?= e($item['order_item_unit_label']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end text-nowrap"><?= e(number_format($item['order_item_quantity'])) ?> <?= e($item['order_item_unit_label']) ?></td>
                                <td class="text-end text-nowrap"><?= e(adminMoney($item['order_item_unit_price'])) ?></td>
                                <td class="text-end text-nowrap fw-semibold"><?= e(adminMoney($item['order_item_line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <dl class="order-totals">
                <dt>Subtotal</dt>
                <dd><?= e(adminMoney($order['order_subtotal'])) ?></dd>
                <dt>Delivery · <?= e($order['delivery_method']['delivery_method_name']) ?></dt>
                <dd><?= e(adminMoney($order['order_delivery_fee'])) ?></dd>
                <?php if ($order['order_discount_total'] > 0): ?>
                    <dt>Discount</dt>
                    <dd>− <?= e(adminMoney($order['order_discount_total'])) ?></dd>
                <?php endif; ?>
                <dt class="order-grand-total">Total</dt>
                <dd class="order-grand-total"><?= e(adminMoney($order['order_total'])) ?></dd>
            </dl>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-header"><h2 class="admin-panel-title">Timeline</h2></div>
            <div class="admin-panel-body">
                <ol class="order-timeline">
                    <?php foreach (array_reverse($order['events']) as $event): ?>
                        <li>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <?= adminStatusBadge($event['order_status']) ?>
                                <span class="small text-muted"><?= e(adminDateTime($event['created_at'])) ?></span>
                            </div>
                            <?php if ($event['status_note']): ?>
                                <div class="small mt-1"><?= e($event['status_note']) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>
    </div>

    <div class="col-lg-4">
        <?php if ($next_statuses): ?>
            <form class="admin-panel mb-3" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Move the order</h2></div>
                <div class="admin-panel-body">
                    <div class="mb-3">
                        <label class="form-label" for="order_status">Next status</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'order_status') ?>" id="order_status" name="order_status">
                            <?php foreach ($next_statuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= adminSelected($posted, 'order_status', $status) ?>><?= e(adminStatusName($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= adminFieldError($errors, 'order_status') ?>
                    </div>
                    <div class="mb-3" data-show-when="order_status=dispatched">
                        <label class="form-label" for="delivery_agent_id">Delivery agent</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'delivery_agent_id') ?>" id="delivery_agent_id" name="delivery_agent_id">
                            <option value="">Choose who takes the order…</option>
                            <?php foreach ($delivery_agents as $agent): ?>
                                <option value="<?= e($agent['delivery_agent_id']) ?>" <?= adminSelected($posted, 'delivery_agent_id', $agent['delivery_agent_id']) ?>>
                                    <?= e($agent['delivery_agent_full_name']) ?> · <?= e(Phone::format($agent['delivery_agent_phone'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$delivery_agents): ?>
                            <div class="form-text">No active delivery agents. <a href="<?= e(url('admin/delivery_agent_edit.php')) ?>">Add one</a> first.</div>
                        <?php endif; ?>
                        <?= adminFieldError($errors, 'delivery_agent_id') ?>
                    </div>
                    <div>
                        <label class="form-label" for="status_note">Note <span class="text-muted">(optional, the customer sees it)</span></label>
                        <textarea class="form-control<?= adminInvalidClass($errors, 'status_note') ?>" id="status_note" name="status_note"
                                  rows="2" maxlength="255"><?= e($posted['status_note'] ?? '') ?></textarea>
                        <?= adminFieldError($errors, 'status_note') ?>
                    </div>
                </div>
                <div class="admin-panel-footer">
                    <button class="btn btn-chimbo" type="submit" name="form_action" value="change_status"
                            data-confirm="Move this order? The customer will be notified.">Save status</button>
                </div>
            </form>
        <?php endif; ?>

        <section class="admin-panel mb-3">
            <div class="admin-panel-header"><h2 class="admin-panel-title">Customer</h2></div>
            <div class="admin-panel-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="initials-avatar"><?= e(adminInitials($customer['user_full_name'])) ?></span>
                    <div>
                        <?php if (Admin::can($current_admin, 'customers.view')): ?>
                            <a class="fw-semibold" href="<?= e(url('admin/customer_details.php?id=' . $customer['user_id'])) ?>"><?= e($customer['user_full_name'] ?? 'No name yet') ?></a>
                        <?php else: ?>
                            <span class="fw-semibold"><?= e($customer['user_full_name'] ?? 'No name yet') ?></span>
                        <?php endif; ?>
                        <div class="small text-muted"><?= e(Phone::format($customer['user_phone'])) ?></div>
                    </div>
                </div>
                <dl class="admin-details">
                    <dt>Shop</dt>
                    <dd><?= e($customer['business_name'] ?? '—') ?></dd>
                    <?php if ($order['order_customer_note']): ?>
                        <dt>Note</dt>
                        <dd><?= e($order['order_customer_note']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="admin-panel mb-3">
            <div class="admin-panel-header"><h2 class="admin-panel-title">Delivery</h2></div>
            <div class="admin-panel-body">
                <dl class="admin-details">
                    <dt>Recipient</dt>
                    <dd><?= e($address['address_recipient_name']) ?><br><span class="text-muted"><?= e(Phone::format($address['address_phone'])) ?></span></dd>
                    <dt>Address</dt>
                    <dd><?= e(implode(', ', array_filter([$address['address_street'], $address['address_landmark'], $address['district_name'], $address['region_name']]))) ?></dd>
                    <dt>Method</dt>
                    <dd><?= e($order['delivery_method']['delivery_method_name']) ?></dd>
                    <dt>Expected by</dt>
                    <dd><?= e(adminDate($order['order_estimated_delivery_date'])) ?></dd>
                    <dt>Agent</dt>
                    <dd>
                        <?php if ($order['delivery_agent']): ?>
                            <?= e($order['delivery_agent']['delivery_agent_full_name']) ?><br>
                            <span class="text-muted"><?= e(Phone::format($order['delivery_agent']['delivery_agent_phone'])) ?></span>
                        <?php else: ?>
                            <span class="text-muted">Not assigned yet</span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($order['order_delivered_at']): ?>
                        <dt>Delivered</dt>
                        <dd><?= e(adminDateTime($order['order_delivered_at'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($order['order_cancelled_at']): ?>
                        <dt>Cancelled</dt>
                        <dd><?= e(adminDateTime($order['order_cancelled_at'])) ?><?= $order['order_cancel_reason'] ? ' — ' . e($order['order_cancel_reason']) : '' ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-header"><h2 class="admin-panel-title">Payment</h2></div>
            <div class="admin-panel-body">
                <dl class="admin-details">
                    <dt>Method</dt>
                    <dd><?= e(adminPaymentMethodName($order['order_payment_method'])) ?></dd>
                    <dt>Status</dt>
                    <dd><?= adminStatusBadge($order['order_payment_status']) ?></dd>
                    <?php if ($order['delivery'] && $order['delivery']['delivery_cash_confirmed_at']): ?>
                        <dt>Cash received</dt>
                        <dd><?= e(adminMoney((int) $order['delivery']['delivery_cash_collected'])) ?> · <?= e(adminDateTime($order['delivery']['delivery_cash_confirmed_at'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>

            <?php if ($order['payments']): ?>
                <ul class="order-payments">
                    <?php foreach ($order['payments'] as $payment): ?>
                        <li>
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold"><?= e(adminMoney((int) $payment['payment_amount'])) ?> · <?= e(adminPaymentMethodName($payment['payment_method_code'])) ?></div>
                                    <div class="small">
                                        From <?= e(adminPayerAccount($payment['payment_payer_account'])) ?>
                                        · code <code class="payment-code"><?= e($payment['payment_reference']) ?></code>
                                    </div>
                                    <div class="small text-muted">
                                        Sent <?= e(adminDateTime($payment['created_at'])) ?>
                                        <?php if ($payment['reviewed_by_admin_name']): ?>
                                            · checked by <?= e($payment['reviewed_by_admin_name']) ?>, <?= e(adminDateTime($payment['payment_reviewed_at'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($payment['payment_review_note']): ?>
                                        <div class="small text-muted">"<?= e($payment['payment_review_note']) ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <?= adminPaymentReviewBadge($payment['payment_status']) ?>
                            </div>
                            <?php if ($payment['payment_status'] === 'submitted' && $can_manage_payments): ?>
                                <div class="mt-2"><?= adminRowActions(adminPaymentReviewButtons($payment, $order['order_number'])) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($order['can_record_payment'] && $can_manage_payments): ?>
                <form class="admin-panel-body border-top" method="post" novalidate>
                    <?= Csrf::field() ?>
                    <h3 class="h6 mb-1">Record payment</h3>
                    <p class="small text-muted">For phone orders: once you see the money on the statement, record it here. It is confirmed at once.</p>
                    <div class="mb-2">
                        <label class="form-label" for="payment_payer_account">Paid from (phone or account)</label>
                        <input class="form-control<?= adminInvalidClass($errors, 'payment_payer_account') ?>" id="payment_payer_account" name="payment_payer_account"
                               value="<?= e($posted['payment_payer_account'] ?? '') ?>" maxlength="40" placeholder="0712 345 678">
                        <?= adminFieldError($errors, 'payment_payer_account') ?>
                    </div>
                    <div>
                        <label class="form-label" for="payment_reference">Confirmation code</label>
                        <input class="form-control payment-code<?= adminInvalidClass($errors, 'payment_reference') ?>" id="payment_reference" name="payment_reference"
                               value="<?= e($posted['payment_reference'] ?? '') ?>" maxlength="40" placeholder="e.g. QJK7XY12AB" autocomplete="off">
                        <?= adminFieldError($errors, 'payment_reference') ?>
                    </div>
                    <button class="btn btn-outline-success w-100 mt-3" type="submit" name="form_action" value="record_payment"
                            data-confirm="Record <?= e(adminMoney($order['order_total'])) ?> as paid? Only do this when you see it on the statement.">
                        <i class="bi bi-check2-circle"></i> Record payment
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($refund_is_due): ?>
                <div class="admin-panel-body border-top">
                    <p class="small text-muted mb-2">This paid order was cancelled: send <?= e(adminMoney($order['order_total'])) ?> back, then mark it refunded.</p>
                    <button class="btn btn-outline-secondary w-100" type="button" data-open-modal="#refund-modal"
                            data-record-id="<?= e($order['order_id']) ?>" data-record-label="<?= e($order['order_number'] . ' · ' . adminMoney($order['order_total'])) ?>">
                        <i class="bi bi-arrow-counterclockwise"></i> Mark refunded
                    </button>
                </div>
            <?php endif; ?>
            <?php if ($order['can_confirm_cash'] && $can_confirm_cash): ?>
                <form class="admin-panel-body border-top" method="post" novalidate>
                    <?= Csrf::field() ?>
                    <label class="form-label" for="delivery_cash_collected">Cash the agent brought back (TZS)</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'delivery_cash_collected') ?>" type="number" min="0" step="1"
                           id="delivery_cash_collected" name="delivery_cash_collected" value="<?= e($posted['delivery_cash_collected'] ?? '') ?>"
                           placeholder="<?= e($order['order_total']) ?>">
                    <div class="form-text">Must be the full order total: <?= e(adminMoney($order['order_total'])) ?>.</div>
                    <?= adminFieldError($errors, 'delivery_cash_collected') ?>
                    <button class="btn btn-outline-success w-100 mt-3" type="submit" name="form_action" value="confirm_cash">
                        <i class="bi bi-cash-coin"></i> Confirm cash received
                    </button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($can_manage_payments): ?>
    <?php require __DIR__ . '/includes/payment_reject_modal.php'; ?>
    <?php require __DIR__ . '/includes/refund_modal.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
