<?php

/**
 * Dashboard → Customers (permission customers.view): totals, new customers per day (a chart with a table view),
 * the top customers by money spent (spend shown only with reports.view) and the newest customers.
 * Uses $dashboard, $tab, $chart_days and $can_see_money from index.php. Deleted accounts are left out.
 */

$customer_stats = $dashboard->getCustomerStats($chart_days);
$counts         = $customer_stats['counts'];
$new_per_day    = $customer_stats['new_per_day'];

$count_cards = [
    ['Customers',                    number_format($counts['customers']),           'bi-people',       url('admin/customers.php')],
    ["New, last {$chart_days} days", number_format($counts['new_in_period']),       'bi-person-plus',  null],
    ['Verified businesses',          number_format($counts['verified_businesses']), 'bi-patch-check',  null],
    ['Suspended',                    number_format($counts['suspended']),           'bi-slash-circle', null],
];

$new_customers_chart = [
    'labels'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['join_date']))->format('j M'), $new_per_day),
    'titles'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['join_date']))->format('D j M Y'), $new_per_day),
    'values'  => array_column($new_per_day, 'customer_count'),
    'format'  => 'count',
    'tooltip' => array_map(fn (array $day): string => $day['customer_count'] . ($day['customer_count'] === 1 ? ' new customer' : ' new customers'), $new_per_day),
];
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="text-muted mb-0">New customers and top buyers over the last <?= e($chart_days) ?> days.</p>
    <?php require __DIR__ . '/day_switch.php'; ?>
</div>

<div class="mb-3"><?= adminStatCards($count_cards) ?></div>

<div class="admin-panel mb-3">
    <div class="admin-panel-header"><h3 class="admin-panel-title">New customers per day</h3></div>
    <div class="admin-panel-body">
        <?= adminChart('new-customers-data', 'daily', $new_customers_chart, "New customers per day, last {$chart_days} days. The numbers are in the table below.") ?>
        <details class="mt-3">
            <summary class="small">Show as a table</summary>
            <table class="table table-sm admin-table mt-2 mb-0">
                <thead><tr><th>Day</th><th class="text-end">New customers</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($new_per_day) as $day): ?>
                        <tr><td><?= e((new DateTimeImmutable($day['join_date']))->format('D j M Y')) ?></td><td class="text-end"><?= e($day['customer_count']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Top 5 customers<?php if ($can_see_money): ?> <span class="fw-normal text-muted small">· by money spent</span><?php endif; ?></h3></div>
            <?php if ($customer_stats['top_customers'] === []): ?>
                <div class="admin-panel-body text-muted">No orders in this period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Customer</th><th class="text-end">Orders</th><?php if ($can_see_money): ?><th class="text-end">Spent</th><?php endif; ?></tr></thead>
                        <tbody>
                            <?php foreach ($customer_stats['top_customers'] as $customer): ?>
                                <?php $customer_link = url('admin/customer_details.php?id=' . $customer['user_id']); ?>
                                <tr class="row-link" data-href="<?= e($customer_link) ?>">
                                    <td>
                                        <a class="fw-semibold" href="<?= e($customer_link) ?>"><?= e($customer['user_full_name'] ?? 'No name yet') ?></a>
                                        <div class="small text-muted"><?= e(trim(($customer['business_name'] ?? '') . ' · ' . Phone::format($customer['user_phone']), ' ·')) ?></div>
                                    </td>
                                    <td class="text-end"><?= e(number_format((int) $customer['order_count'])) ?></td>
                                    <?php if ($can_see_money): ?><td class="text-end text-nowrap"><?= e(adminMoney((int) $customer['total_spent'])) ?></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Newest customers</h3>
                <a class="small" href="<?= e(url('admin/customers.php')) ?>">All customers</a>
            </div>
            <?php if ($customer_stats['newest_customers'] === []): ?>
                <div class="admin-panel-body text-muted">No customers yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Customer</th><th>Region</th><th>Joined</th></tr></thead>
                        <tbody>
                            <?php foreach ($customer_stats['newest_customers'] as $customer): ?>
                                <?php $customer_link = url('admin/customer_details.php?id=' . $customer['user_id']); ?>
                                <tr class="row-link" data-href="<?= e($customer_link) ?>">
                                    <td>
                                        <a class="fw-semibold" href="<?= e($customer_link) ?>"><?= e($customer['user_full_name'] ?? 'Registration not finished') ?></a>
                                        <div class="small text-muted"><?= e(trim(($customer['business_name'] ?? '') . ' · ' . Phone::format($customer['user_phone']), ' ·')) ?></div>
                                    </td>
                                    <td><?= e($customer['region_name'] ?? '—') ?></td>
                                    <td class="text-nowrap"><?= e(adminDateTime($customer['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
