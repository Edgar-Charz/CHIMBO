<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin       = AdminSession::requireLogin('orders.view');
$admin_id            = (int) $current_admin['admin_id'];
$database            = Database::instance();
$order_manager       = new OrderManager($database);
$order_link_manager  = new OrderLink($database);
$can_change_status   = Admin::can($current_admin, 'orders.manage');
$can_confirm_cash    = Admin::can($current_admin, 'payments.confirm_cash');
$can_manage_payments = Admin::can($current_admin, 'payments.manage');   // review, record, refunds

$order_id = (int) ($_GET['id'] ?? 0);
$order    = adminLoadOrRedirect(fn () => $order_manager->getOrderForAdmin($order_id), 'orders.php');
$return_to = 'orders.php';
$requested_return_to = (string) ($_GET['return_to'] ?? '');
if (parse_url($requested_return_to, PHP_URL_PATH) === 'orders.php'
    && parse_url($requested_return_to, PHP_URL_SCHEME) === null
    && parse_url($requested_return_to, PHP_URL_HOST) === null) {
    parse_str((string) parse_url($requested_return_to, PHP_URL_QUERY), $requested_filters);
    $return_filters = [];
    $allowed_filter_values = [
        'order_status' => ADMIN_ORDER_STATUSES,
        'order_payment_status' => ADMIN_PAYMENT_STATUSES,
    ];
    foreach ($allowed_filter_values as $filter_name => $allowed_values) {
        $filter_value = $requested_filters[$filter_name] ?? null;
        if (is_string($filter_value) && in_array($filter_value, $allowed_values, true)) {
            $return_filters[$filter_name] = $filter_value;
        }
    }
    foreach (['overdue', 'transport_to_confirm'] as $filter_name) {
        if (($requested_filters[$filter_name] ?? null) === '1') {
            $return_filters[$filter_name] = '1';
        }
    }
    if ($return_filters) {
        $return_to .= '?' . http_build_query($return_filters);
    }
}

// Each action checks its own permission: Finance may view orders but not move them
$is_pickup = $order['delivery_method']['delivery_method_is_pickup'];   // collected at the shop (R-26)

$form_error = adminHandleForm(function (string $form_action) use ($order_manager, $order_link_manager, $order_id, $admin_id, $can_change_status, $can_confirm_cash, $can_manage_payments, $database, $is_pickup, $return_to): void {
    $order_page = url('admin/order_details.php?' . http_build_query(['id' => $order_id, 'return_to' => $return_to]));

    if (in_array($form_action, ['revoke_qr_link', 'issue_qr_link'], true)) {
        if (!$can_change_status) {
            throw ApiException::forbidden('Your role cannot manage order QR links.');
        }

        $previous_state = $order_link_manager->getAdminStatus($order_id);
        if ($form_action === 'revoke_qr_link') {
            $order_link_manager->revoke($order_id);
            (new AuditLog($database))->record('admin', $admin_id, 'order.qr_link_revoked', 'order', $order_id,
                ['is_active' => true, 'version' => $previous_state['version']], ['is_active' => false]);
            Session::flash('success', 'QR link revoked. All copies of its current code are now invalid.');
        } else {
            $order_link_manager->issueReplacement($order_id);
            (new AuditLog($database))->record('admin', $admin_id, 'order.qr_link_reissued', 'order', $order_id,
                ['is_active' => $previous_state['is_active'], 'version' => $previous_state['version']],
                ['is_active' => true, 'version' => $previous_state['version'] + 1]);
            Session::flash('success', 'A replacement QR link is ready. Print a new packing slip to get its QR code.');
        }
        redirect($order_page);
    }

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
        Session::flash('success', 'The order is now "' . adminStatusName((string) $_POST['order_status'], $is_pickup) . '". The customer has been notified.');
    } elseif ($form_action === 'reactivate_order' && $can_change_status) {
        $order_manager->reactivateOrder($order_id, $_POST, $admin_id);
        Session::flash('success', 'Order reactivated at today\'s prices — the customer has been told and can pay now.');
    } elseif ($form_action === 'confirm_delivery_fee' && $can_change_status) {
        $order_manager->confirmDeliveryFee($order_id, $_POST, $admin_id);
        Session::flash('success', 'Transport price confirmed — the customer has been told and can pay now.');
    } elseif ($form_action === 'confirm_cash' && $can_confirm_cash) {
        $order_manager->confirmCashCollected($order_id, $_POST, $admin_id);
        Session::flash('success', 'Cash received — the order is paid.');
    } elseif ($form_action === 'add_staff_note' && $can_change_status) {
        (new OrderEditor($database))->addStaffNote($order_id, $_POST, $admin_id);
        Session::flash('success', 'Note added. Only staff see it.');
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
$delivery_fee_status = $order['delivery_method']['delivery_fee_status'];   // fixed · awaiting_confirmation · confirmed (R-25)
// An expired order can be brought back at today's prices (R-28): what would change
$reactivation = $order['order_status'] === 'expired' && $can_change_status ? $order_manager->previewReactivation($order_id) : null;
// "Edit order" (R-29) when something can still change; private staff notes
$order_editor = new OrderEditor($database);
$edit_rules   = $order_editor->getEditRules($order_id);
$can_edit     = $can_change_status && ($edit_rules['can_edit_unpaid'] || $edit_rules['can_add_to_paid'] || $edit_rules['can_edit_address']);
$staff_notes  = $order_editor->getStaffNotes($order_id);
// Money to give back: a paid order that was stopped (all of it), or a lower total after a change (the difference)
$refund_amount   = $order['payment']['refund_due'] > 0 ? $order['payment']['refund_due']
    : (in_array($order['order_status'], ['cancelled', 'expired'], true) && $order['order_payment_status'] === 'paid' ? $order['payment']['amount_paid'] : 0);
$refund_is_due   = $can_manage_payments && $refund_amount > 0;
$order_qr_status = $order_link_manager->getAdminStatus($order_id);

$page_title  = 'Order ' . $order['order_number'];
$active_menu = 'orders';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/' . $return_to)) ?>"><i class="bi bi-arrow-left"></i> Orders</a>

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
            <?= adminOrderStatusBadge($order['order_status'], $order['order_payment_status'], $delivery_fee_status === 'awaiting_confirmation', $is_pickup) ?>
            <?= adminStatusBadge($order['order_payment_status']) ?>
            <strong class="fs-5 ms-2"><?= e(adminMoney($order['order_total'])) ?></strong>
            <a class="btn btn-outline-secondary ms-2" href="<?= e(url('admin/order_receipt.php?id=' . $order['order_id'])) ?>"
               target="_blank" rel="noopener"><i class="bi bi-printer"></i> Print receipt</a>
            <a class="btn btn-outline-secondary ms-2" href="<?= e(url('admin/order_packing_slip.php?id=' . $order['order_id'])) ?>"
               target="_blank" rel="noopener"><i class="bi bi-box-seam"></i> Packing slip</a>
            <?php if ($can_edit): ?>
                <a class="btn btn-chimbo ms-2" href="<?= e(url('admin/order_edit.php?id=' . $order['order_id'])) ?>"><i class="bi bi-pencil"></i> Edit order</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="admin-panel mb-3">
    <div class="admin-panel-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <strong>Order QR link</strong>
            <span class="badge <?= $order_qr_status['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?> ms-2">
                <?= $order_qr_status['is_active'] ? 'Active' : 'Revoked' ?>
            </span>
            <div class="small text-muted mt-1">
                <?= $order_qr_status['is_active']
                    ? 'Revoking disables every copy of the current QR code.'
                    : 'The old QR code is invalid. Issue a replacement, then print a new packing slip.' ?>
            </div>
        </div>
        <?php if ($can_change_status): ?>
            <form method="post" class="m-0">
                <?= Csrf::field() ?>
                <?php if ($order_qr_status['is_active']): ?>
                    <button class="btn btn-outline-danger" type="submit" name="form_action" value="revoke_qr_link">
                        <i class="bi bi-x-circle"></i> Revoke QR link
                    </button>
                <?php else: ?>
                    <button class="btn btn-chimbo" type="submit" name="form_action" value="issue_qr_link">
                        <i class="bi bi-arrow-repeat"></i> Issue replacement QR
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
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
                            <th class="serial-column" title="Row number">#</th>
                            <th>Product</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Unit price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item_number => $item): ?>
                            <tr>
                                <td class="serial-column"><?= e($item_number + 1) ?></td>
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
                <dd>
                    <?= e(adminDeliveryFee($order)) ?>
                    <?php if ($delivery_fee_status === 'awaiting_confirmation'): ?>
                        <div class="small order-fee-to-confirm">estimate · to confirm</div>
                    <?php elseif ($delivery_fee_status === 'confirmed' && $order['delivery_method']['delivery_fee_note']): ?>
                        <div class="small text-muted"><?= e($order['delivery_method']['delivery_fee_note']) ?></div>
                    <?php endif; ?>
                </dd>
                <?php if ($order['order_discount_total'] > 0): ?>
                    <dt>Discount</dt>
                    <dd>− <?= e(adminMoney($order['order_discount_total'])) ?>
                        <?php if ($order['order_discount_note']): ?><div class="small text-muted"><?= e($order['order_discount_note']) ?></div><?php endif; ?>
                    </dd>
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
                                <?= adminStatusBadge($event['order_status'], $is_pickup) ?>
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
        <?php if ($reactivation !== null): ?>
            <form class="admin-panel admin-panel-attention mb-3" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title"><i class="bi bi-arrow-counterclockwise"></i> Reactivate order</h2></div>
                <div class="admin-panel-body">
                    <p class="small text-muted">
                        The time to pay ran out. If the customer still wants it, bring it back at <strong>today's prices</strong>:
                        the stock is reserved again and they get a new time to pay.
                    </p>
                    <table class="table table-sm small mb-2">
                        <thead><tr><th>Product</th><th class="text-end">Was</th><th class="text-end">Today</th></tr></thead>
                        <tbody>
                            <?php foreach ($reactivation['lines'] as $line): ?>
                                <tr>
                                    <td><?= e($line['product_name']) ?> <span class="text-muted">× <?= e($line['quantity']) ?></span></td>
                                    <td class="text-end text-nowrap"><?= e(adminMoney($line['old_unit_price'])) ?></td>
                                    <td class="text-end text-nowrap<?= $line['new_unit_price'] !== null && $line['new_unit_price'] !== $line['old_unit_price'] ? ' fw-semibold' : '' ?>">
                                        <?= $line['new_unit_price'] === null ? '—' : e(adminMoney($line['new_unit_price'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="d-flex justify-content-between small"><span>Total was</span><span><?= e(adminMoney($reactivation['old_total'])) ?></span></div>
                    <div class="d-flex justify-content-between fw-semibold mb-3"><span>New total</span><span><?= e(adminMoney($reactivation['new_total'])) ?></span></div>
                    <?php if ($reactivation['estimated_delivery_date'] !== null): ?>
                        <div class="small mb-3"><span class="text-muted">New estimated delivery</span>
                            <strong><?= e(adminCalendarDate($reactivation['estimated_delivery_date'])) ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if ($reactivation['problems']): ?>
                        <div class="small text-danger mb-2"><i class="bi bi-exclamation-circle"></i> Can't reactivate yet:</div>
                        <ul class="small text-danger mb-0">
                            <?php foreach ($reactivation['problems'] as $problem): ?>
                                <li><?= e($problem) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <label class="form-label" for="reactivate_note">Note <span class="text-muted">(optional, the customer sees it)</span></label>
                        <input class="form-control mb-3" id="reactivate_note" name="status_note" maxlength="255" placeholder="e.g. Mteja alipiga simu">
                        <button class="btn btn-chimbo w-100" type="submit" name="form_action" value="reactivate_order"
                                data-confirm="Reactivate this order at today's prices (<?= e(adminMoney($reactivation['new_total'])) ?>)? The customer will be told to pay.">
                            Reactivate order
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($order['can_confirm_delivery_fee'] && $can_change_status): ?>
            <?php $estimate = $order['delivery_method']['delivery_fee_estimate']; ?>
            <form class="admin-panel admin-panel-attention mb-3" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title"><i class="bi bi-truck"></i> Transport price</h2></div>
                <div class="admin-panel-body">
                    <p class="small text-muted">
                        Measure the load, then confirm the transport price to <?= e($address['region_name']) ?>.
                        The customer can pay only after this.
                        <?= $estimate > 0 ? 'Estimate given at checkout: ' . e(adminMoney($estimate)) . '.' : 'This region has no estimate — set the price.' ?>
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="order_delivery_fee">Transport price</label>
                        <div class="input-group">
                            <span class="input-group-text">TZS</span>
                            <input class="form-control<?= adminInvalidClass($errors, 'order_delivery_fee') ?>" type="number" min="0" step="500"
                                   id="order_delivery_fee" name="order_delivery_fee" value="<?= e($posted['order_delivery_fee'] ?? ($estimate ?: '')) ?>" required>
                        </div>
                        <?= adminFieldError($errors, 'order_delivery_fee') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="order_delivery_fee_note">Reason <span class="text-muted">(when the price differs — the customer sees it)</span></label>
                        <input class="form-control<?= adminInvalidClass($errors, 'order_delivery_fee_note') ?>" id="order_delivery_fee_note" name="order_delivery_fee_note"
                               maxlength="255" value="<?= e($posted['order_delivery_fee_note'] ?? '') ?>" placeholder="e.g. Mzigo mkubwa, katoni 6">
                        <?= adminFieldError($errors, 'order_delivery_fee_note') ?>
                    </div>
                    <button class="btn btn-chimbo w-100" type="submit" name="form_action" value="confirm_delivery_fee">
                        Confirm price and let the customer pay
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($next_statuses): ?>
            <form class="admin-panel mb-3" method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Move the order</h2></div>
                <div class="admin-panel-body">
                    <div class="mb-3">
                        <label class="form-label" for="order_status">Next status</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'order_status') ?>" id="order_status" name="order_status">
                            <?php foreach ($next_statuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= adminSelected($posted, 'order_status', $status) ?>><?= e(adminStatusName($status, $is_pickup)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= adminFieldError($errors, 'order_status') ?>
                    </div>
                    <div class="mb-3" data-show-when="order_status=dispatched">
                        <label class="form-label" for="delivery_agent_id">
                            Delivery agent<?= $order['needs_delivery_agent'] ? '' : ' <span class="text-muted">(optional)</span>' ?>
                        </label>
                        <select class="form-select<?= adminInvalidClass($errors, 'delivery_agent_id') ?>" id="delivery_agent_id" name="delivery_agent_id">
                            <option value=""><?= $order['needs_delivery_agent']
                                ? 'Choose who takes the order…'
                                : ($order['delivery_method']['delivery_fee_paid_to_rider'] ? 'No agent — sent with Bolt' : 'No agent — handed to the transport company') ?></option>
                            <?php foreach ($delivery_agents as $agent): ?>
                                <option value="<?= e($agent['delivery_agent_id']) ?>" <?= adminSelected($posted, 'delivery_agent_id', $agent['delivery_agent_id']) ?>>
                                    <?= e($agent['delivery_agent_full_name']) ?> · <?= e(Phone::format($agent['delivery_agent_phone'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$order['needs_delivery_agent']): ?>
                            <div class="form-text">Choose an agent only if one of yours takes it.</div>
                        <?php elseif (!$delivery_agents): ?>
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
            <div class="admin-panel-header">
                <h2 class="admin-panel-title"><i class="bi bi-lock"></i> Staff notes</h2>
                <span class="small text-muted">Only staff see these</span>
            </div>
            <div class="admin-panel-body">
                <?php foreach ($staff_notes as $note): ?>
                    <div class="staff-note">
                        <div><?= nl2br(e($note['order_staff_note_text'])) ?></div>
                        <div class="small text-muted"><?= e($note['admin_full_name']) ?> · <?= e(adminDateTime($note['created_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$staff_notes): ?>
                    <p class="small text-muted mb-2">No notes yet.</p>
                <?php endif; ?>
                <?php if ($can_change_status): ?>
                    <form method="post" novalidate>
                        <?= Csrf::field() ?>
                        <textarea class="form-control form-control-sm<?= adminInvalidClass($errors, 'order_staff_note_text') ?>" name="order_staff_note_text"
                                  rows="2" maxlength="1000" placeholder="e.g. Atakuja kuchukua saa 10 jioni" aria-label="New staff note"></textarea>
                        <?= adminFieldError($errors, 'order_staff_note_text') ?>
                        <button class="btn btn-sm btn-light mt-2" type="submit" name="form_action" value="add_staff_note"><i class="bi bi-plus-lg"></i> Add note</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

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
            <div class="admin-panel-header"><h2 class="admin-panel-title"><?= $is_pickup ? 'Pickup at the shop' : 'Delivery' ?></h2></div>
            <div class="admin-panel-body">
                <?php if ($is_pickup): ?>
                    <p class="small mb-2">
                        <i class="bi bi-shop"></i> The customer collects it — don't send it. "Ready for pickup" tells them to come;
                        "Picked up" when they have it.
                    </p>
                <?php elseif ($order['delivery_method']['delivery_fee_paid_to_rider']): ?>
                    <p class="small mb-2"><i class="bi bi-scooter"></i> The customer pays the rider (Bolt) for transport — not CHIMBO.</p>
                <?php endif; ?>
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
                    <p class="small text-muted mb-2">
                        <?= $order['payment']['refund_due'] > 0 ? 'The total went down after a change' : 'This paid order was cancelled' ?>:
                        send <?= e(adminMoney($refund_amount)) ?> back, then mark it refunded.
                    </p>
                    <button class="btn btn-outline-secondary w-100" type="button" data-open-modal="#refund-modal"
                            data-record-id="<?= e($order['order_id']) ?>" data-record-label="<?= e($order['order_number'] . ' · ' . adminMoney($refund_amount)) ?>">
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
