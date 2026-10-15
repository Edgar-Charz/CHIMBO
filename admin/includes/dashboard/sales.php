<?php

/**
 * Dashboard → Sales (permission reports.view): the period's totals, sales by category and by payment method
 * (bar charts with table views), top products and top regions. The same numbers as the Reports page.
 * Uses $dashboard, $tab and $chart_days from index.php.
 */

$sales_breakdown = $dashboard->getSalesBreakdown($chart_days);
$summary         = $sales_breakdown['summary'];

$summary_cards = [
    ['Orders',        number_format($summary['order_count']),     'bi-receipt',    null],
    ['Revenue',       adminCardMoney($summary['revenue']),            'bi-cash-stack', null],
    ['Average order', adminCardMoney($summary['average_order']),      'bi-calculator', null],
    ['Pieces sold',   number_format($summary['pieces_sold']),     'bi-box-seam',   null],
    ['Delivery fees', adminCardMoney($summary['delivery_fees']),      'bi-truck',      null],
    ['Cancelled',     number_format($summary['cancelled_count']), 'bi-x-circle',   null],
];

$categories = $sales_breakdown['by_category'];
$category_chart = [
    'labels'  => array_column($categories, 'category_name'),
    'values'  => array_column($categories, 'revenue'),
    'format'  => 'money',
    'tooltip' => array_map(fn (array $row): array => [adminMoney($row['revenue']), number_format($row['quantity']) . ' pieces'], $categories),
];

$payment_methods = $sales_breakdown['by_payment_method'];
$payment_chart = [
    'labels'  => array_map(fn (array $row): string => adminPaymentMethodName($row['order_payment_method']), $payment_methods),
    'values'  => array_column($payment_methods, 'revenue'),
    'format'  => 'money',
    'tooltip' => array_map(fn (array $row): array => [
        adminMoney($row['revenue']) . ' · ' . number_format($row['order_count']) . ($row['order_count'] === 1 ? ' order' : ' orders'),
        'Paid so far: ' . adminMoney($row['paid_total']),
    ], $payment_methods),
];

$reports_link = url('admin/reports.php?' . http_build_query(['date_from' => $sales_breakdown['date_from'], 'date_to' => $sales_breakdown['date_to']]));
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="text-muted mb-0">Last <?= e($chart_days) ?> days, Tanzania time, without cancelled or expired orders.</p>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <?php require __DIR__ . '/day_switch.php'; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($reports_link) ?>"><i class="bi bi-file-earmark-bar-graph"></i> Full report</a>
    </div>
</div>

<div class="mb-3"><?= adminStatCards($summary_cards) ?></div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Revenue by category</h3></div>
            <div class="admin-panel-body">
                <?php if ($categories === []): ?>
                    <p class="text-muted mb-0">No sales in this period.</p>
                <?php else: ?>
                    <?= adminChart('category-sales-data', 'bars', $category_chart, 'Revenue by category. The numbers are in the table below.') ?>
                    <details class="mt-3">
                        <summary class="small">Show as a table</summary>
                        <table class="table table-sm admin-table mt-2 mb-0">
                            <thead><tr><th>Category</th><th class="text-end">Pieces</th><th class="text-end">Revenue</th></tr></thead>
                            <tbody>
                                <?php foreach ($categories as $row): ?>
                                    <tr><td><?= e($row['category_name']) ?></td><td class="text-end"><?= e(number_format($row['quantity'])) ?></td><td class="text-end"><?= e(adminMoney($row['revenue'])) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Revenue by payment method</h3></div>
            <div class="admin-panel-body">
                <?php if ($payment_methods === []): ?>
                    <p class="text-muted mb-0">No sales in this period.</p>
                <?php else: ?>
                    <?= adminChart('payment-sales-data', 'bars', $payment_chart, 'Revenue by payment method. The numbers are in the table below.') ?>
                    <details class="mt-3">
                        <summary class="small">Show as a table</summary>
                        <table class="table table-sm admin-table mt-2 mb-0">
                            <thead><tr><th>Method</th><th class="text-end">Orders</th><th class="text-end">Revenue</th><th class="text-end">Paid</th></tr></thead>
                            <tbody>
                                <?php foreach ($payment_methods as $row): ?>
                                    <tr>
                                        <td><?= e(adminPaymentMethodName($row['order_payment_method'])) ?></td>
                                        <td class="text-end"><?= e(number_format($row['order_count'])) ?></td>
                                        <td class="text-end"><?= e(adminMoney($row['revenue'])) ?></td>
                                        <td class="text-end"><?= e(adminMoney($row['paid_total'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Top 5 products</h3></div>
            <?php if ($sales_breakdown['top_products'] === []): ?>
                <div class="admin-panel-body text-muted">No sales in this period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Pieces</th><th class="text-end">Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach ($sales_breakdown['top_products'] as $product): ?>
                                <?php $product_link = url('admin/product_edit.php?id=' . $product['product_id']); ?>
                                <tr class="row-link" data-href="<?= e($product_link) ?>">
                                    <td><a class="fw-semibold" href="<?= e($product_link) ?>"><?= e($product['product_name']) ?></a></td>
                                    <td class="text-muted"><?= e($product['product_sku']) ?></td>
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
    <div class="col-lg-5">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Top 5 regions</h3></div>
            <?php if ($sales_breakdown['top_regions'] === []): ?>
                <div class="admin-panel-body text-muted">No sales in this period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Region</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach ($sales_breakdown['top_regions'] as $region): ?>
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
