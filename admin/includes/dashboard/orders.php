<?php

/**
 * Dashboard → Orders: order totals, the pipeline right now (a bar chart, one bar per stage), the orders waiting to
 * be packed (longest-waiting first) and the orders out for delivery by agent.
 * Uses $dashboard from index.php. Chart: assets/dashboard.js.
 */

$orders_summary   = $dashboard->getOrderPipeline();
$waiting_to_pack  = $dashboard->getOrdersWaitingToPack();
$deliveries       = $dashboard->getDeliveriesByAgent();

$totals = $orders_summary['totals'];
$total_cards = [
    ['All orders', number_format($totals['all']),       'bi-receipt',          url('admin/orders.php')],
    ['Active',     number_format($totals['active']),    'bi-arrow-repeat',     null],
    ['Delivered',  number_format($totals['delivered']), 'bi-check2-circle',    null],
    ['Cancelled',  number_format($totals['cancelled']), 'bi-x-circle',         null],
    ['Expired',    number_format($totals['expired']),   'bi-hourglass-bottom', null],
];

$pipeline_data = [
    'labels'  => array_map(fn (string $status): string => adminStatusName($status), array_keys($orders_summary['pipeline'])),
    'values'  => array_values($orders_summary['pipeline']),
    'format'  => 'count',
    'tooltip' => array_map(fn (int $count): string => number_format($count) . ($count === 1 ? ' order' : ' orders'), array_values($orders_summary['pipeline'])),
];
?>

<div class="mb-3"><?= adminStatCards($total_cards) ?></div>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <div class="admin-panel h-100">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Orders in progress <span class="fw-normal text-muted small">· right now</span></h3>
            </div>
            <div class="admin-panel-body">
                <?= adminChart('pipeline-data', 'bars', $pipeline_data, 'Orders in progress by stage. The numbers are in the table below.') ?>
                <details class="mt-3">
                    <summary class="small">Show as a table</summary>
                    <table class="table table-sm admin-table mt-2 mb-0">
                        <thead><tr><th>Stage</th><th class="text-end">Orders</th></tr></thead>
                        <tbody>
                            <?php foreach ($orders_summary['pipeline'] as $status => $count): ?>
                                <tr><td><?= e(adminStatusName($status)) ?></td><td class="text-end"><?= e(number_format($count)) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="admin-panel h-100">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Waiting to pack <span class="fw-normal text-muted small">· longest waiting first</span></h3>
                <a class="small" href="<?= e(url('admin/orders.php?order_status=confirmed')) ?>"><?= e(number_format($orders_summary['pipeline']['confirmed'])) ?> in total · open the list</a>
            </div>
            <?php if ($waiting_to_pack === []): ?>
                <div class="admin-panel-body text-muted">Nothing to pack. Every confirmed order is packed.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Order</th><th>Customer</th><th>Region</th><th class="text-end">Items</th><th>Waiting</th></tr></thead>
                        <tbody>
                            <?php foreach ($waiting_to_pack as $order): ?>
                                <?php $order_link = url('admin/order_details.php?id=' . $order['order_id']); ?>
                                <tr class="row-link" data-href="<?= e($order_link) ?>">
                                    <td>
                                        <a class="fw-semibold" href="<?= e($order_link) ?>"><?= e($order['order_number']) ?></a>
                                        <div class="small text-muted"><?= e(adminPaymentMethodName($order['order_payment_method'])) ?></div>
                                    </td>
                                    <td><?= e($order['user_full_name'] ?? 'No name yet') ?><div class="small text-muted"><?= e(Phone::format($order['user_phone'])) ?></div></td>
                                    <td><?= e($order['order_ship_region_name']) ?></td>
                                    <td class="text-end"><?= e($order['item_count']) ?></td>
                                    <td class="text-nowrap"><?= adminWaitingFor($order['confirmed_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="admin-panel">
    <div class="admin-panel-header">
        <h3 class="admin-panel-title">Out for delivery <span class="fw-normal text-muted small">· dispatched or in transit, by agent</span></h3>
    </div>
    <?php if ($deliveries === []): ?>
        <div class="admin-panel-body text-muted">No orders are out for delivery.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table mb-0">
                <thead><tr><th>Agent</th><th class="text-end">Orders</th><th>Order numbers</th><th class="text-end">Cash to collect</th></tr></thead>
                <tbody>
                    <?php foreach ($deliveries as $agent): ?>
                        <?php $agent_link = url('admin/delivery_agent_edit.php?id=' . $agent['delivery_agent_id']); ?>
                        <tr class="row-link" data-href="<?= e($agent_link) ?>">
                            <td>
                                <a class="fw-semibold" href="<?= e($agent_link) ?>"><?= e($agent['delivery_agent_full_name']) ?></a>
                                <div class="small text-muted"><?= e(Phone::format($agent['delivery_agent_phone'])) ?></div>
                            </td>
                            <td class="text-end"><?= e($agent['order_count']) ?></td>
                            <td class="small"><?= e($agent['order_numbers']) ?></td>
                            <td class="text-end text-nowrap"><?= $agent['cash_to_collect'] > 0 ? e(adminMoney($agent['cash_to_collect'])) : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
