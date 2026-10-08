<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('dashboard.view');
$summary       = (new Dashboard(Database::instance()))->getSummaryCounts();

// The cards shown on the dashboard: [label, value, icon, page it opens (or null)]
$can_manage_payments = Admin::can($current_admin, 'payments.manage');
$stat_cards = [
    ['Customers',             $summary['total_customers'],                  'bi-people',          null],
    ['New customers today',   $summary['new_customers_today'],              'bi-person-plus',     null],
    ['Verified businesses',   $summary['verified_businesses'],              'bi-patch-check',     null],
    ['Awaiting verification', $summary['businesses_awaiting_verification'], 'bi-hourglass-split', null],
    ['All orders',            $summary['total_orders'],                     'bi-receipt',         null],
    ['Active orders',         $summary['active_orders'],                    'bi-truck',           null],
    ['Awaiting payment',      $summary['orders_awaiting_payment'],          'bi-hourglass',       null],
    ['Delivered orders',      $summary['delivered_orders'],                 'bi-check2-circle',   null],
    ['Payments to check',     $summary['payments_awaiting_review'],         'bi-cash-coin',       $can_manage_payments ? 'payments.php?tab=review' : null],
    ['Refunds due',           $summary['refunds_due'],                      'bi-arrow-counterclockwise', $can_manage_payments ? 'payments.php?tab=refunds' : null],
];

$page_title  = 'Dashboard';
$active_menu = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<p class="text-muted mb-4">Karibu, <?= e($current_admin['admin_full_name']) ?>.</p>

<div class="row g-3">
    <?php foreach ($stat_cards as [$label, $value, $icon, $page]): ?>
        <div class="col-6 col-xl-3">
            <div class="card stat-card h-100 <?= $page && $value > 0 ? 'stat-card-attention' : '' ?>">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="stat-icon"><i class="bi <?= e($icon) ?>"></i></div>
                    <div>
                        <div class="stat-value"><?= e(number_format((int) $value)) ?></div>
                        <div class="small text-muted"><?= e($label) ?></div>
                    </div>
                    <?php if ($page): ?>
                        <a class="stretched-link" href="<?= e(url('admin/' . $page)) ?>" aria-label="Open <?= e($label) ?>"></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-light border mt-4 mb-0">
    <i class="bi bi-info-circle"></i> <a href="<?= e(url('admin/orders.php')) ?>">Review orders</a> and manage fulfilment from the Orders page.
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
