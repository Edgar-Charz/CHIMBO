<?php

/**
 * Dashboard: Customers (permission customers.view): totals, new customers by day or month,
 * the top customers by money spent (spend shown only with reports.view) and the newest customers.
 * Uses $dashboard, $dashboard_range and $can_see_money from index.php. Deleted accounts are left out.
 */

$customer_stats = $dashboard->getCustomerStats($dashboard_range);
$counts         = $customer_stats['counts'];
$new_per_day    = $customer_stats['new_per_day'];
$buyer_mix      = $customer_stats['buyer_mix'];

$count_cards = [
    ['Customers',                    number_format($counts['customers']),           'bi-people',       url('admin/customers.php')],
    [$dashboard_range['period'] === 'all_time' ? 'New customers' : 'New in period', number_format($counts['new_in_period']), 'bi-person-plus', null],
    ['Verified businesses',          number_format($counts['verified_businesses']), 'bi-patch-check',  null],
    ['Suspended',                    number_format($counts['suspended']),           'bi-slash-circle', null],
];

$new_customers_chart = [
    'labels'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['join_date']))->format($dashboard_range['bucket'] === 'month' ? 'M y' : 'j M'), $new_per_day),
    'titles'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['join_date']))->format($dashboard_range['bucket'] === 'month' ? 'F Y' : 'D j M Y'), $new_per_day),
    'values'  => array_column($new_per_day, 'customer_count'),
    'format'  => 'count',
    'tooltip' => array_map(fn (array $day): string => $day['customer_count'] . ($day['customer_count'] === 1 ? ' new customer' : ' new customers'), $new_per_day),
];
$buyer_mix_labels = $dashboard_range['period'] === 'all_time'
    ? ['One-time buyers', 'Repeat buyers']
    : ['First-time buyers', 'Returning buyers'];
$buyer_mix_colors = ['--chimbo-green', '--chimbo-orange'];
$buyer_mix_chart = [
    'labels' => $buyer_mix_labels,
    'values' => [$buyer_mix['first_time_buyers'], $buyer_mix['returning_buyers']],
    'colors' => $buyer_mix_colors,
    'format' => 'count',
    'tooltip' => array_map(fn (string $label, int $count): string => number_format($count) . ' ' . strtolower($label), $buyer_mix_labels, [$buyer_mix['first_time_buyers'], $buyer_mix['returning_buyers']]),
];
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="text-muted mb-0">New customers and top buyers: <?= e($dashboard_range['label']) ?>.</p>
</div>

<div class="mb-3"><?= adminStatCards($count_cards, 4) ?></div>

<div class="row g-3 mb-3 align-items-stretch">
    <div class="col-12 col-xl-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">New customers <?= $dashboard_range['bucket'] === 'month' ? 'per month' : 'per day' ?></h3></div>
            <div class="admin-panel-body">
                <?= adminChart('new-customers-data', 'daily', $new_customers_chart, 'New customers over ' . $dashboard_range['label'] . '.') ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Buyer mix <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3></div>
            <div class="admin-panel-body customer-buyer-mix-body">
                <div class="orders-overview-donut">
                    <?= adminChart('customer-buyer-mix-data', 'doughnut', $buyer_mix_chart, 'Buyer mix for ' . $dashboard_range['label'] . '.') ?>
                    <div class="orders-overview-center" aria-hidden="true">
                        <strong><?= e(number_format($buyer_mix['total_buyers'])) ?></strong>
                        <span>Buyers</span>
                    </div>
                </div>
                <ul class="orders-overview-legend list-unstyled mb-0">
                    <?php foreach ($buyer_mix_labels as $buyer_index => $buyer_label): ?>
                        <?php $buyer_count = $buyer_mix_chart['values'][$buyer_index]; ?>
                        <?php $buyer_percentage = $buyer_mix['total_buyers'] > 0 ? $buyer_count / $buyer_mix['total_buyers'] * 100 : 0; ?>
                        <li>
                            <span class="orders-overview-legend-dot" style="--legend-color: var(<?= e($buyer_mix_colors[$buyer_index]) ?>)"></span>
                            <span class="orders-overview-legend-label"><?= e($buyer_label) ?><small><?= e(number_format($buyer_percentage, 1)) ?>%</small></span>
                            <strong><?= e(number_format($buyer_count)) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Top 5 customers<?php if ($can_see_money): ?> <span class="fw-normal text-muted small">&middot; by money spent</span><?php endif; ?></h3></div>
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
                                        <div class="small text-muted"><?= e(implode(' / ', array_filter([$customer['business_name'] ?? '', Phone::format($customer['user_phone'])]))) ?></div>
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
                                        <div class="small text-muted"><?= e(implode(' / ', array_filter([$customer['business_name'] ?? '', Phone::format($customer['user_phone'])]))) ?></div>
                                    </td>
                                    <td><?= isset($customer['region_name']) ? e($customer['region_name']) : '&mdash;' ?></td>
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
