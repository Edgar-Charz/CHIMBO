<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('dashboard.view');
$summary       = (new Dashboard(Database::instance()))->getSummaryCounts();

// The cards shown on the dashboard: [label, value, icon]
$stat_cards = [
    ['Customers',             $summary['total_customers'],                  'bi-people'],
    ['New customers today',   $summary['new_customers_today'],              'bi-person-plus'],
    ['Verified businesses',   $summary['verified_businesses'],              'bi-patch-check'],
    ['Awaiting verification', $summary['businesses_awaiting_verification'], 'bi-hourglass-split'],
    ['All orders',            $summary['total_orders'],                     'bi-receipt'],
    ['Active orders',         $summary['active_orders'],                    'bi-truck'],
    ['Awaiting payment',      $summary['orders_awaiting_payment'],          'bi-hourglass'],
    ['Delivered orders',      $summary['delivered_orders'],                 'bi-check2-circle'],
];

$page_title  = 'Dashboard';
$active_menu = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<p class="text-muted mb-4">Karibu, <?= e($current_admin['admin_full_name']) ?>.</p>

<div class="row g-3">
    <?php foreach ($stat_cards as [$label, $value, $icon]): ?>
        <div class="col-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="stat-icon"><i class="bi <?= e($icon) ?>"></i></div>
                    <div>
                        <div class="stat-value"><?= e(number_format((int) $value)) ?></div>
                        <div class="small text-muted"><?= e($label) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-light border mt-4 mb-0">
    <i class="bi bi-info-circle"></i> <a href="<?= e(url('admin/orders.php')) ?>">Review orders</a> and manage fulfilment from the Orders page.
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
