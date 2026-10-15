<?php

/**
 * Dashboard → Overview: sales per day (a bar chart with a 7 / 14 / 30 / 90-day switch and a table view) and the
 * latest orders. Revenue only for staff who may see reports; others see orders per day.
 * Uses $dashboard, $can_see_money, $current_admin, $tab and $chart_days from index.php. Chart: assets/dashboard.js.
 */

$all_time    = $dashboard->getAllTimeTotals();
$daily_sales = $dashboard->getDailySales($chart_days);

// "All time": everything since CHIMBO started (money only for staff who may see reports). A card links to its page
// only when this staff member may open it.
$link_if = fn (string $permission, string $page): ?string => Admin::can($current_admin, $permission) ? url('admin/' . $page) : null;
$all_time_cards = array_values(array_filter([
    ['Customers',            number_format($all_time['customers']),           'bi-people',        $link_if('customers.view', 'customers.php')],
    ['Verified businesses',  number_format($all_time['businesses_verified']), 'bi-patch-check',   null],
    ['Orders',               number_format($all_time['orders']),              'bi-receipt',       $link_if('orders.view', 'orders.php')],
    ['Delivered orders',     number_format($all_time['orders_delivered']),    'bi-check2-circle', null],
    $can_see_money ? ['Revenue',       adminCardMoney($all_time['revenue']),       'bi-cash-stack',  $link_if('reports.view', 'reports.php')] : null,
    $can_see_money ? ['Average order', adminCardMoney($all_time['average_order']), 'bi-calculator',  null] : null,
    ['Pieces sold',          number_format($all_time['pieces_sold']),         'bi-box-seam',      null],
    ['Products in the shop', number_format($all_time['products_in_shop']),    'bi-shop',          $link_if('products.manage', 'products.php')],
    ['Sellers',              number_format($all_time['sellers']),             'bi-building',      $link_if('sellers.manage', 'sellers.php')],
]));
$chart_title = $can_see_money ? 'Revenue per day' : 'Orders per day';

// What the chart draws (one series, one axis); the tooltip also shows the number of orders
$order_count_text = fn (int $count): string => number_format($count) . ($count === 1 ? ' order' : ' orders');
$chart_data = [
    'labels'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['sales_date']))->format('j M'), $daily_sales),
    'titles'  => array_map(fn (array $day): string => (new DateTimeImmutable($day['sales_date']))->format('D j M Y'), $daily_sales),
    'values'  => array_column($daily_sales, $can_see_money ? 'revenue' : 'order_count'),
    'format'  => $can_see_money ? 'money' : 'count',
    'tooltip' => array_map(fn (array $day): array => $can_see_money
        ? [adminMoney($day['revenue']), $order_count_text($day['order_count'])]
        : [$order_count_text($day['order_count'])], $daily_sales),
];

$can_view_orders = Admin::can($current_admin, 'orders.view');
$latest_orders   = $can_view_orders ? (new OrderManager(Database::instance()))->getOrdersForAdmin(['per_page' => 10])['items'] : [];
?>

<h2 class="dashboard-section-title">All time <span>· since CHIMBO started, without cancelled or expired orders</span></h2>
<div class="mb-4"><?= adminStatCards($all_time_cards) ?></div>

<div class="admin-panel mb-4">
    <div class="admin-panel-header flex-wrap gap-2">
        <h3 class="admin-panel-title"><?= e($chart_title) ?> <span class="fw-normal text-muted small">· last <?= e($chart_days) ?> days</span></h3>
        <?php require __DIR__ . '/day_switch.php'; ?>
    </div>
    <div class="admin-panel-body">
        <?= adminChart('daily-sales-data', 'daily', $chart_data, "{$chart_title}, last {$chart_days} days. The numbers are in the table below.") ?>

        <details class="mt-3">
            <summary class="small">Show as a table</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm admin-table mb-0">
                    <thead><tr><th>Day</th><th class="text-end">Orders</th><?php if ($can_see_money): ?><th class="text-end">Revenue</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($daily_sales) as $day): ?>
                            <tr>
                                <td><?= e((new DateTimeImmutable($day['sales_date']))->format('D j M Y')) ?></td>
                                <td class="text-end"><?= e(number_format($day['order_count'])) ?></td>
                                <?php if ($can_see_money): ?><td class="text-end"><?= e(adminMoney($day['revenue'])) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</div>

<?php if ($can_view_orders): ?>
    <div class="admin-panel">
        <div class="admin-panel-header">
            <h3 class="admin-panel-title">Latest orders</h3>
            <a class="small" href="<?= e(url('admin/orders.php')) ?>">All orders</a>
        </div>
        <?php if ($latest_orders === []): ?>
            <div class="admin-panel-body text-muted">No orders yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Payment</th><th class="text-end">Total</th><th>Placed</th></tr></thead>
                    <tbody>
                        <?php foreach ($latest_orders as $order): ?>
                            <?php $order_link = url('admin/order_details.php?id=' . $order['order_id']); ?>
                            <tr class="row-link" data-href="<?= e($order_link) ?>">
                                <td><a class="fw-semibold" href="<?= e($order_link) ?>"><?= e($order['order_number']) ?></a></td>
                                <td><?= e($order['user_full_name'] ?? 'No name yet') ?><div class="small text-muted"><?= e(Phone::format($order['user_phone'])) ?></div></td>
                                <td><?= adminStatusBadge($order['order_status']) ?></td>
                                <td><?= adminStatusBadge($order['order_payment_status']) ?></td>
                                <td class="text-end text-nowrap"><?= e(adminMoney((int) $order['order_total'])) ?></td>
                                <td class="text-nowrap"><?= e(adminDateTime($order['order_placed_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
