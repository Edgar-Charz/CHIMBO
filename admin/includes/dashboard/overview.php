<?php

/**
 * Dashboard â†’ Overview: period activity, daily or monthly sales, latest orders, order status and sales insights.
 * Revenue only for staff who may see reports; others see orders per day.
 * Uses $dashboard, $dashboard_range, $can_see_money and $current_admin from index.php. Chart: assets/dashboard.js.
 */

$all_time = $dashboard_range['period'] === 'all_time' ? $dashboard->getAllTimeTotals() : [];
$period_totals = $dashboard_range['period'] === 'all_time' ? $all_time : $dashboard->getOverviewPeriodTotals($dashboard_range);
$daily_sales = $dashboard->getDailySales($dashboard_range);
$can_view_orders = Admin::can($current_admin, 'orders.view');
$orders_summary = $can_view_orders && $dashboard_range['period'] === 'all_time' ? $dashboard->getOrderPipeline() : null;

// "All time": everything since CHIMBO started (money only for staff who may see reports). A card links to its page
// only when this staff member may open it.
$link_if = fn (string $permission, string $page): ?string => Admin::can($current_admin, $permission) ? url('admin/' . $page) : null;
$all_time_cards = array_values(array_filter([
    [$dashboard_range['period'] === 'all_time' ? 'Customers' : 'New customers', number_format($period_totals['customers']), 'bi-people', $link_if('customers.view', 'customers.php')],
    ['Orders', number_format($period_totals['orders']), 'bi-receipt', $link_if('orders.view', 'orders.php')],
    $can_see_money ? ['Revenue', adminCardMoney($period_totals['revenue']), 'bi-cash-stack', $link_if('reports.view', 'reports.php')] : null,
    ['Products in shop now', number_format($period_totals['products_in_shop']), 'bi-shop', $link_if('products.manage', 'products.php')],
]));
$more_all_time_cards = array_values(array_filter([
    ['Delivered orders', number_format($period_totals['delivered_orders'] ?? $period_totals['orders_delivered'] ?? 0), 'bi-check2-circle', null],
    $can_view_orders ? ['Active orders', number_format($dashboard_range['period'] === 'all_time' ? $orders_summary['totals']['active'] : $period_totals['active_orders']), 'bi-arrow-repeat', null] : null,
    $can_see_money ? ['Average order', adminCardMoney($period_totals['average_order']), 'bi-calculator', null] : null,
    ['Pieces sold', number_format($period_totals['pieces_sold']), 'bi-box-seam', null],
]));
$chart_unit = $dashboard_range['bucket'] === 'month' ? 'month' : 'day';
$chart_title = $can_see_money ? "Revenue per {$chart_unit}" : "Orders per {$chart_unit}";

// What the chart draws (one series, one axis); the tooltip also shows the number of orders
$order_count_text = fn (int $count): string => number_format($count) . ($count === 1 ? ' order' : ' orders');
$chart_data = [
    'labels'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['sales_date']))->format($dashboard_range['bucket'] === 'month' ? 'M y' : 'j M'), $daily_sales),
    'titles'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['sales_date']))->format($dashboard_range['bucket'] === 'month' ? 'F Y' : 'D j M Y'), $daily_sales),
    'values'  => array_column($daily_sales, $can_see_money ? 'revenue' : 'order_count'),
    'format'  => $can_see_money ? 'money' : 'count',
    'tooltip' => array_map(fn (array $day): array => $can_see_money
        ? [adminMoney($day['revenue']), $order_count_text($day['order_count'])]
        : [$order_count_text($day['order_count'])], $daily_sales),
];

$latest_orders = $can_view_orders ? $dashboard->getRecentOrders($dashboard_range, DASHBOARD_PREVIEW_LIMIT) : [];
?>

<h2 class="dashboard-section-title"><?= e(ucfirst($dashboard_range['label'])) ?> <span>&middot; orders, sales, and customer activity for the selected period</span></h2>
<div class="dashboard-overview-page">
<div class="mb-4"><?= adminStatCards($all_time_cards, 4) ?></div>
<?php if ($more_all_time_cards !== []): ?>
    <div class="mb-4"><?= adminStatCards($more_all_time_cards, 4) ?></div>
<?php endif; ?>
<div class="row g-3 mb-4 align-items-stretch">
    <div class="<?= $can_view_orders ? 'col-12 col-lg-6' : 'col-12' ?>">
        <div class="admin-panel h-100 dashboard-overview-panel">
            <div class="admin-panel-header flex-wrap gap-2">
        <h3 class="admin-panel-title"><?= e($chart_title) ?> <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3>

    </div>
            <div class="admin-panel-body dashboard-overview-chart-body">
                <?= adminChart('daily-sales-data', 'daily', $chart_data, "{$chart_title}, {$dashboard_range['label']}.") ?>
            </div>
        </div>
    </div>

<?php if ($can_view_orders): ?>
    <div class="col-12 col-lg-6">
        <div class="admin-panel h-100 dashboard-overview-panel">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Latest orders</h3>
                <a class="small" href="<?= e(url('admin/orders.php')) ?>">All orders</a>
            </div>
            <?php if ($latest_orders === []): ?>
                <div class="admin-panel-body text-muted">No orders in this period.</div>
            <?php else: ?>
                <div class="table-responsive dashboard-overview-table dashboard-overview-orders-table">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>#</th><th>Order</th><th>Customer</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($latest_orders as $order_index => $order): ?>
                                <?php $order_link = url('admin/order_details.php?id=' . $order['order_id']); ?>
                                <tr class="row-link" data-href="<?= e($order_link) ?>">
                                    <td class="text-muted"><?= e($order_index + 1) ?></td>
                                    <td><a class="fw-semibold" href="<?= e($order_link) ?>"><?= e($order['order_number']) ?></a></td>
                                    <td><?= e($order['user_full_name'] ?? 'No name yet') ?></td>
                                    <td class="text-nowrap"><?= e(adminDate($order['order_placed_at'])) ?></td>
                                    <td><?= adminStatusBadge($order['order_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<?php

/**
 * Overview's order and sales breakdowns. Each section is included only for admins with its matching permission.
 * Uses the sales report methods so the figures match the dedicated Reports page.
 */

if ($can_view_orders) {
    $order_status_counts = $dashboard->getOrderStatusCounts($dashboard_range);
    foreach (array_merge(Dashboard::PIPELINE_STATUSES, ['delivered', 'cancelled', 'expired']) as $status) {
        $order_status_counts[$status] ??= 0;
    }
    arsort($order_status_counts, SORT_NUMERIC);
    $order_status_colors = [
        'pending_payment' => '--status-warning',
        'confirmed' => '--chimbo-green-light',
        'packed' => '--chart-status-packed',
        'dispatched' => '--status-info',
        'in_transit' => '--chart-status-in-transit',
        'delivered' => '--status-success',
        'cancelled' => '--status-danger',
        'expired' => '--status-neutral',
    ];
    $orders_status_groups = [];
    foreach ($order_status_counts as $order_status => $order_count) {
        $orders_status_groups[] = [
            'label' => adminStatusName($order_status),
            'count' => $order_count,
            'color' => $order_status_colors[$order_status],
        ];
    }
    $orders_chart_total = array_sum($order_status_counts);
}

if ($can_see_money) {
    $sales_breakdown = $dashboard->getSalesBreakdown($dashboard_range);
    $categories = array_slice($sales_breakdown['by_category'], 0, DASHBOARD_PREVIEW_LIMIT);
    $payment_methods = array_slice($sales_breakdown['by_payment_method'], 0, DASHBOARD_PREVIEW_LIMIT);
    $payment_revenue_total = array_sum(array_column($payment_methods, 'revenue'));
    $top_products = array_slice($sales_breakdown['top_products'], 0, DASHBOARD_PREVIEW_LIMIT);
    $top_regions = array_slice($sales_breakdown['top_regions'], 0, DASHBOARD_PREVIEW_LIMIT);

    $category_color_tokens = ['--chimbo-green', '--chimbo-orange', '--chart-status-packed', '--chart-status-in-transit', '--status-info'];
    $category_chart = [
        'labels' => array_column($categories, 'category_name'),
        'values' => array_column($categories, 'revenue'),
        'colors' => array_map(fn (int $index): string => $category_color_tokens[$index % count($category_color_tokens)], array_keys($categories)),
        'format' => 'money',
        'tooltip' => array_map(fn (array $row): array => [
            adminMoney($row['revenue']),
            number_format($row['quantity']) . ' pieces',
        ], $categories),
    ];
}

$insight_column_class = $can_view_orders && $can_see_money ? 'col-12 col-lg-6' : 'col-12';
?>

<div class="row g-3 mb-4 align-items-stretch">
    <?php if ($can_view_orders): ?>
        <div class="<?= e($insight_column_class) ?>">
            <div class="admin-panel h-100 dashboard-overview-panel">
                <div class="admin-panel-header">
    <h3 class="admin-panel-title">Orders Overview <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3>
                </div>
                <div class="admin-panel-body orders-overview-chart-body">
                    <div class="orders-overview-donut">
                        <?= adminChart('orders-overview-data', 'doughnut', [
                            'labels' => array_column($orders_status_groups, 'label'),
                            'values' => array_column($orders_status_groups, 'count'),
                            'colors' => array_column($orders_status_groups, 'color'),
                            'format' => 'count',
                        ], 'Orders by status, ' . $dashboard_range['label'] . '.') ?>
                        <div class="orders-overview-center" aria-hidden="true">
                            <strong><?= e(number_format($orders_chart_total)) ?></strong>
                            <span>Orders</span>
                        </div>
                    </div>
                    <ul class="orders-overview-legend list-unstyled mb-0">
                        <?php foreach ($orders_status_groups as $status_group): ?>
                            <?php $status_percentage = (int) round($status_group['count'] / max($orders_chart_total, 1) * 100); ?>
                            <li>
                                <span class="orders-overview-legend-dot" style="--legend-color: var(<?= e($status_group['color']) ?>)"></span>
                                <span class="orders-overview-legend-label"><?= e($status_group['label']) ?><small><?= e($status_percentage) ?>%</small></span>
                                <strong><?= e(number_format($status_group['count'])) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($can_see_money): ?>
        <div class="<?= e($insight_column_class) ?>">
            <div class="admin-panel h-100 dashboard-overview-panel">
                <div class="admin-panel-header">
                    <h3 class="admin-panel-title">Revenue by category <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3>
                    <a class="small" href="<?= e(url('admin/reports.php')) ?>">Full report</a>
                </div>
                <?php if ($categories === []): ?>
                    <div class="admin-panel-body text-muted">No sales in this period.</div>
                <?php else: ?>
                    <?php $category_revenue_total = array_sum(array_column($categories, 'revenue')); ?>
                    <div class="admin-panel-body orders-overview-chart-body">
                        <div class="orders-overview-donut">
                            <?= adminChart('overview-category-sales-data', 'doughnut', $category_chart, 'Revenue share by product category, ' . $dashboard_range['label'] . '.') ?>
                            <div class="orders-overview-center" aria-hidden="true">
                                <strong><?= e(adminShortAmount($category_revenue_total)) ?></strong>
                                <span>Product revenue</span>
                            </div>
                        </div>
                        <ul class="orders-overview-legend list-unstyled mb-0">
                            <?php foreach ($categories as $category_index => $category): ?>
                                <?php $category_percentage = $category_revenue_total > 0 ? $category['revenue'] / $category_revenue_total * 100 : 0; ?>
                                <li>
                                    <span class="orders-overview-legend-dot" style="--legend-color: var(<?= e($category_color_tokens[$category_index % count($category_color_tokens)]) ?>)"></span>
                                    <span class="orders-overview-legend-label"><?= e($category['category_name']) ?><small><?= e(number_format($category_percentage, 1)) ?>%</small></span>
                                    <strong><?= e(adminShortAmount($category['revenue'])) ?> TZS</strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($can_see_money): ?>
    <div class="row g-3 mb-4 align-items-stretch">
        <div class="col-12 col-xl-4">
            <div class="admin-panel h-100 dashboard-overview-panel">
                <div class="admin-panel-header">
                    <h3 class="admin-panel-title">Revenue by payment method <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3>
                    <a class="small" href="<?= e(url('admin/reports.php')) ?>">Full report</a>
                </div>
                <?php if ($payment_methods === []): ?>
                    <div class="admin-panel-body text-muted">No sales in this period.</div>
                <?php else: ?>
                    <div class="admin-panel-body payment-method-funnel" role="group" aria-label="Revenue by payment method for <?= e($dashboard_range['label']) ?>">
                        <div class="payment-method-funnel__segments" aria-hidden="true">
                            <?php foreach ($payment_methods as $payment_method): ?>
                                <?php $payment_share = $payment_revenue_total > 0 ? $payment_method['revenue'] / $payment_revenue_total * 100 : 0; ?>
                                <div class="payment-method-funnel__segment"><strong><?= e(number_format($payment_share, 1)) ?>%</strong></div>
                            <?php endforeach; ?>
                        </div>
                        <ul class="payment-method-funnel__legend">
                            <?php foreach ($payment_methods as $payment_method): ?>
                                <?php $payment_name = adminPaymentMethodName($payment_method['order_payment_method']); ?>
                                <li>
                                    <span class="payment-method-funnel__dot" aria-hidden="true"></span>
                                    <div class="payment-method-funnel__details" title="<?= e($payment_name) ?>">
                                        <strong><?= e($payment_name) ?></strong>
                                        <span><?= e(adminShortAmount($payment_method['revenue'])) ?> TZS</span>
                                        <small><?= e(number_format($payment_method['order_count'])) ?> <?= $payment_method['order_count'] === 1 ? 'order' : 'orders' ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="admin-panel h-100 dashboard-overview-panel">
                <div class="admin-panel-header">
                    <h3 class="admin-panel-title">Top products <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3>
                    <a class="small" href="<?= e(url('admin/reports.php')) ?>">Full report</a>
                </div>
                <?php if ($top_products === []): ?>
                    <div class="admin-panel-body text-muted">No sales in this period.</div>
                <?php else: ?>
                    <div class="table-responsive dashboard-overview-table">
                        <table class="table admin-table mb-0">
                            <thead><tr><th>Product</th><th class="text-end">Pieces</th><th class="text-end">Revenue</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_products as $product): ?>
                                    <?php $product_link = url('admin/product_edit.php?id=' . $product['product_id']); ?>
                                    <tr class="row-link" data-href="<?= e($product_link) ?>">
                                        <td><a class="fw-semibold" href="<?= e($product_link) ?>"><?= e($product['product_name']) ?></a></td>
                                        <td class="text-end"><?= e(number_format($product['quantity'])) ?></td>
                                        <td class="text-end text-nowrap"><?= e(adminMoney($product['revenue'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="admin-panel h-100 dashboard-overview-panel">
                <div class="admin-panel-header">
                    <h3 class="admin-panel-title">Top regions <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3>
                    <a class="small" href="<?= e(url('admin/reports.php')) ?>">Full report</a>
                </div>
                <?php if ($top_regions === []): ?>
                    <div class="admin-panel-body text-muted">No sales in this period.</div>
                <?php else: ?>
                    <div class="table-responsive dashboard-overview-table">
                        <table class="table admin-table mb-0">
                            <thead><tr><th>Region</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_regions as $region): ?>
                                    <tr>
                                        <td><?= e($region['region_name']) ?></td>
                                        <td class="text-end"><?= e(number_format($region['order_count'])) ?></td>
                                        <td class="text-end text-nowrap"><?= e(adminMoney($region['revenue'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
