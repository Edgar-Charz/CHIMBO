<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Payments are checked by hand until a provider is connected: customers send the number they paid from and the
// confirmation code, staff compare it with the M-Pesa / bank statement. The classes write the audit log and notify
// the customer.
$current_admin        = AdminSession::requireLogin('payments.manage');
$admin_id             = (int) $current_admin['admin_id'];
$database             = Database::instance();
$payment_model        = new Payment($database);
$payment_method_model = new PaymentMethod($database);
$can_manage_methods   = Admin::can($current_admin, 'payment_methods.manage');   // super admin only

$tabs = [
    'review'  => ['Waiting for review', 'bi-hourglass-split'],
    'all'     => ['All payments', 'bi-list-ul'],
    'refunds' => ['Refunds due', 'bi-arrow-counterclockwise'],
];
if ($can_manage_methods) {
    $tabs['methods'] = ['Payment methods', 'bi-wallet2'];
}
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'review';

$form_error = adminHandleForm(function (string $form_action) use ($payment_model, $payment_method_model, $admin_id, $can_manage_methods, $tab): void {
    $record_id = adminActionRecordId();
    $this_tab  = url("admin/payments.php?tab={$tab}");

    try {
        if ($form_action === 'confirm_payment') {
            $payment_model->confirmPayment($record_id, $admin_id);
            $message = 'Payment confirmed — the order is paid and the customer has been told.';
        } elseif ($form_action === 'reject_payment') {
            $payment_model->rejectPayment($record_id, $_POST, $admin_id);
            $message = 'Payment rejected — the customer sees your reason and can send the right details.';
        } elseif ($form_action === 'mark_refunded') {
            $payment_model->markRefunded($record_id, $_POST, $admin_id);
            $message = 'Marked as refunded — the customer has been told.';
        } elseif ($form_action === 'toggle_payment_method' && $can_manage_methods) {
            $method    = $payment_method_model->getMethodById($record_id);
            $is_active = !$method['payment_method_is_active'];
            $payment_method_model->setPaymentMethodActive($record_id, $is_active, $admin_id);
            $message = $method['payment_method_name'] . ($is_active ? ' is offered at checkout.' : ' is no longer offered at checkout.');
        } else {
            throw ApiException::forbidden('Your role cannot do this.');
        }
    } catch (ApiException $e) {
        // The reason dialogs close when they are sent, so their messages come back as an alert at the top
        Session::flash('error', adminErrorText($e));
        redirect($this_tab);
    }

    Session::flash('success', $message);
    redirect($this_tab);
});

$refunds_due     = $tab === 'refunds' ? $payment_model->getRefundsDue() : [];
$payment_methods = $tab === 'methods' ? $payment_method_model->listForAdmin() : [];
$waiting_count   = $payment_model->countWaitingForReview();
$refund_count    = count($tab === 'refunds' ? $refunds_due : $payment_model->getRefundsDue());

$page_title  = 'Payments';
$active_menu = 'payments';
require __DIR__ . '/includes/header.php';
?>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<nav class="page-tabs mb-3" aria-label="Payment sections">
    <?php foreach ($tabs as $tab_key => [$tab_name, $tab_icon]): ?>
        <a class="page-tab <?= $tab_key === $tab ? 'active' : '' ?>" href="<?= e(url("admin/payments.php?tab={$tab_key}")) ?>">
            <i class="bi <?= e($tab_icon) ?>"></i> <?= e($tab_name) ?>
            <?php if ($tab_key === 'review' && $waiting_count > 0): ?>
                <span class="page-tab-count"><?= e($waiting_count) ?></span>
            <?php elseif ($tab_key === 'refunds' && $refund_count > 0): ?>
                <span class="page-tab-count"><?= e($refund_count) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'review' || $tab === 'all'): ?>
    <?php if ($tab === 'review'): ?>
        <p class="text-muted">
            Compare each payment with the M-Pesa / bank statement: the amount, the number it came from and the confirmation code.
            Confirming marks the order paid and sends it to packing.
        </p>
        <!-- This tab always shows the payments waiting for review -->
        <form id="payment-filters" hidden><input type="hidden" name="payment_status" value="submitted"></form>
    <?php else: ?>
        <form class="admin-panel filter-card" id="payment-filters">
            <div class="filter-grid">
                <div>
                    <label class="form-label" for="filter-payment-status">Status</label>
                    <select class="form-select" id="filter-payment-status" name="payment_status">
                        <option value="">All statuses</option>
                        <?php foreach (ADMIN_PAYMENT_REVIEW_STATUSES as $status => [, $status_name]): ?>
                            <option value="<?= e($status) ?>"><?= e($status_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="filter-payment-method">Method</label>
                    <select class="form-select" id="filter-payment-method" name="payment_method_code">
                        <option value="">All methods</option>
                        <?php foreach (ADMIN_PAYMENT_METHOD_NAMES as $method_code => $method_name): ?>
                            <option value="<?= e($method_code) ?>"><?= e($method_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-x-lg"></i> Clear</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <div class="admin-panel admin-table-panel">
        <table class="table admin-table w-100" data-datatable-source="<?= e(url("admin/ajax/payments.php?tab={$tab}")) ?>"
               data-filter-form="#payment-filters" data-title="<?= e($tabs[$tab][0]) ?>" data-icon="<?= e($tabs[$tab][1]) ?>"
               data-ordering="false" data-page-length="<?= e(Payment::PER_PAGE_OPTIONS[0]) ?>"
               data-length-menu="<?= e(json_encode(Payment::PER_PAGE_OPTIONS)) ?>"
               data-search-placeholder="Order number, code or payer number"
               data-empty-message="<?= $tab === 'review' ? 'Nothing to check — no payments are waiting.' : 'No payments yet.' ?>">
            <thead>
                <tr>
                    <th data-column="order">Order</th>
                    <th data-column="customer">Customer</th>
                    <th data-column="method" data-cell-class="text-nowrap">Method</th>
                    <th data-column="amount" data-cell-class="text-end text-nowrap" class="text-end">Amount</th>
                    <th data-column="payer" data-cell-class="text-nowrap">Paid from</th>
                    <th data-column="code">Code</th>
                    <th data-column="sent" data-cell-class="text-nowrap">Sent</th>
                    <th data-column="status"><?= $tab === 'review' ? 'Time left to pay' : 'Status' ?></th>
                    <th data-column="actions" data-cell-class="text-end" class="text-end">Actions</th>
                </tr>
            </thead>
        </table>
    </div>
    <?php require __DIR__ . '/includes/payment_reject_modal.php'; ?>

<?php elseif ($tab === 'refunds'): ?>
    <p class="text-muted">Paid orders that were cancelled or expired: send the money back, then mark them refunded.</p>
    <div class="admin-panel admin-table-panel">
        <?php if (!$refunds_due): ?>
            <div class="admin-empty-state">
                <i class="bi bi-check2-circle"></i>
                <p class="mt-2 mb-0">No refunds due.</p>
            </div>
        <?php else: ?>
            <table class="table admin-table w-100" data-datatable data-title="Refunds due" data-icon="bi-arrow-counterclockwise"
                   data-order='[]' data-search-placeholder="Search order or customer">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th class="text-end">Amount</th>
                        <th>Paid with</th>
                        <th>Send back to</th>
                        <th>Cancelled</th>
                        <th class="text-end" data-orderable="false">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refunds_due as $refund): ?>
                        <tr>
                            <td><a class="fw-semibold" href="<?= e(url('admin/order_details.php?id=' . $refund['order_id'])) ?>"><?= e($refund['order_number']) ?></a></td>
                            <td><?= e($refund['user_full_name'] ?? 'No name yet') ?><div class="small text-muted"><?= e(Phone::format($refund['user_phone'])) ?></div></td>
                            <td class="text-end text-nowrap fw-semibold" data-order="<?= e($refund['order_total']) ?>"><?= e(adminMoney((int) $refund['order_total'])) ?></td>
                            <td><?= e(adminPaymentMethodName($refund['order_payment_method'])) ?></td>
                            <td class="text-nowrap"><?= e($refund['payment_payer_account'] ? adminPayerAccount($refund['payment_payer_account']) : '—') ?></td>
                            <td data-order="<?= e($refund['order_cancelled_at'] ?? '') ?>">
                                <?= e(adminDateTime($refund['order_cancelled_at'])) ?>
                                <?php if ($refund['order_cancel_reason']): ?>
                                    <div class="small text-muted"><?= e($refund['order_cancel_reason']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?= adminRowActions(
                                    '<button class="btn btn-sm btn-light" type="button" title="Mark refunded" aria-label="Mark refunded"'
                                        . ' data-open-modal="#refund-modal" data-record-id="' . e($refund['order_id']) . '"'
                                        . ' data-record-label="' . e($refund['order_number'] . ' · ' . adminMoney((int) $refund['order_total'])) . '">'
                                        . '<i class="bi bi-arrow-counterclockwise"></i></button>',
                                    adminActionLink('bi-eye', 'View order', url('admin/order_details.php?id=' . $refund['order_id'])),
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/includes/refund_modal.php'; ?>

<?php else: ?>
    <p class="text-muted">
        Where customers send their money. A mobile money or bank method can only be offered once its account number and name
        are filled in. Cash on delivery needs no account.
    </p>
    <div class="admin-panel admin-table-panel">
        <table class="table admin-table w-100" data-datatable data-title="Payment methods" data-icon="bi-wallet2"
               data-order='[]' data-searching="false">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Type</th>
                    <th>Pay to</th>
                    <th>Account name</th>
                    <th>Status</th>
                    <th class="text-end" data-orderable="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_methods as $method): ?>
                    <?php $method_id = (int) $method['payment_method_id']; ?>
                    <tr class="row-link">
                        <td>
                            <a class="stretched-link fw-semibold" href="<?= e(url("admin/payment_method_edit.php?id={$method_id}")) ?>"><?= e($method['payment_method_name']) ?></a>
                            <div class="small text-muted"><?= e($method['payment_method_code']) ?></div>
                        </td>
                        <td><?= e(ADMIN_PAYMENT_METHOD_TYPES[$method['payment_method_type']] ?? $method['payment_method_type']) ?></td>
                        <td class="text-nowrap">
                            <?= e($method['payment_method_account_number'] ?? '—') ?>
                            <?php if ($method['payment_method_bank_name']): ?>
                                <div class="small text-muted"><?= e($method['payment_method_bank_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($method['payment_method_account_name'] ?? '—') ?></td>
                        <td><?= adminStatusBadge($method['payment_method_is_active'] ? 'active' : 'hidden') ?></td>
                        <td class="text-end">
                            <?= adminRowActions(
                                adminActionLink('bi-pencil', 'Edit', url("admin/payment_method_edit.php?id={$method_id}")),
                                $method['payment_method_is_active']
                                    ? adminActionButton('bi-pause-circle', 'Stop offering at checkout', 'toggle_payment_method', $method_id,
                                        'Stop offering ' . $method['payment_method_name'] . '? Customers can no longer choose it.')
                                    : adminActionButton('bi-play-circle', 'Offer at checkout', 'toggle_payment_method', $method_id),
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
