<?php

/**
 * Dashboard â†’ Stock (permission products.manage): current product totals, period best sellers, low stock, slow movers and offers ending soonest.
 * Uses $dashboard, $dashboard_range and $current_admin from index.php.
 */

$stock_stats = $dashboard->getStockStats($dashboard_range);
$low_stock   = $dashboard->getLowStock();
$counts      = $stock_stats['counts'];
$can_adjust  = Admin::can($current_admin, 'inventory.manage');

$count_cards = [
    ['In the shop',    number_format($counts['in_shop']),        'bi-shop',                 url('admin/products.php')],
    ['Sold out',       number_format($counts['sold_out']),       'bi-x-octagon',            null],
    ['Low stock',      number_format($counts['low']),            'bi-exclamation-triangle', null],
    ['Hidden',         number_format($counts['hidden']),         'bi-eye-slash',            null],
    ['Running offers', number_format($counts['running_offers']), 'bi-percent',              url('admin/offers.php')],
];

$best_sellers = $stock_stats['best_sellers'];
$best_sellers_chart = [
    'labels'  => array_column($best_sellers, 'product_name'),
    'values'  => array_column($best_sellers, 'pieces_sold'),
    'format'  => 'count',
    'tooltip' => array_map(fn (array $row): string => number_format($row['pieces_sold']) . ' pieces sold', $best_sellers),
];
?>

<div class="mb-3"><?= adminStatCards($count_cards, 5) ?></div>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Best sellers <span class="fw-normal text-muted small">&middot; <?= e($dashboard_range['label']) ?></span></h3></div>
            <div class="admin-panel-body">
                <?php if ($best_sellers === []): ?>
                    <p class="text-muted mb-0">Nothing sold during this period.</p>
                <?php else: ?>
                    <?= adminChart('best-sellers-data', 'bars', $best_sellers_chart, 'Best sellers during ' . $dashboard_range['label'] . ', by pieces sold.') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Low stock <span class="fw-normal text-muted small">&middot; <?= e(ProductEditor::LOW_STOCK_THRESHOLD) ?> pieces or fewer</span></h3>
                <?php if ($can_adjust): ?><a class="small" href="<?= e(url('admin/stock.php')) ?>">Stock page</a><?php endif; ?>
            </div>
            <?php if ($low_stock['items'] === []): ?>
                <div class="admin-panel-body text-muted">Every product has more than <?= e(ProductEditor::LOW_STOCK_THRESHOLD) ?> pieces.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Product</th><th class="text-end">In stock</th><th class="text-end">MOQ</th></tr></thead>
                        <tbody>
                            <?php foreach ($low_stock['items'] as $product): ?>
                                <?php $product_link = url(($can_adjust ? 'admin/product_stock.php?id=' : 'admin/product_edit.php?id=') . $product['product_id']); ?>
                                <tr class="row-link" data-href="<?= e($product_link) ?>">
                                    <td><a class="fw-semibold" href="<?= e($product_link) ?>"><?= e($product['product_name']) ?></a><div class="small text-muted"><?= e($product['product_sku']) ?></div></td>
                                    <td class="text-end">
                                        <?php if ((int) $product['product_stock_quantity'] === 0): ?>
                                            <span class="badge text-bg-danger">Sold out</span>
                                        <?php else: ?>
                                            <?= e($product['product_stock_quantity']) ?> <?= e($product['product_unit_label']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= e($product['product_moq']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Slow movers <span class="fw-normal text-muted small">&middot; in stock, not sold during <?= e($dashboard_range['label']) ?></span></h3></div>
            <?php if ($stock_stats['slow_movers'] === []): ?>
                <div class="admin-panel-body text-muted">Every product in stock sold during this period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Product</th><th class="text-end">In stock</th><th>Last sold</th></tr></thead>
                        <tbody>
                            <?php foreach ($stock_stats['slow_movers'] as $product): ?>
                                <?php $product_link = url('admin/product_edit.php?id=' . $product['product_id']); ?>
                                <tr class="row-link" data-href="<?= e($product_link) ?>">
                                    <td><a class="fw-semibold" href="<?= e($product_link) ?>"><?= e($product['product_name']) ?></a><div class="small text-muted"><?= e($product['product_sku']) ?></div></td>
                                    <td class="text-end text-nowrap"><?= e(number_format((int) $product['product_stock_quantity'])) ?> <?= e($product['product_unit_label']) ?></td>
                                    <td class="text-nowrap"><?= $product['last_sold_at'] ? e(adminDateTime($product['last_sold_at'], 'j M Y')) : '<span class="text-muted">Never</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Offers ending soonest</h3>
                <a class="small" href="<?= e(url('admin/offers.php')) ?>">All offers</a>
            </div>
            <?php if ($stock_stats['offers_ending'] === []): ?>
                <div class="admin-panel-body text-muted">No offers are running.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th>Product</th><th class="text-end">Off</th><th>Ends</th></tr></thead>
                        <tbody>
                            <?php foreach ($stock_stats['offers_ending'] as $offer): ?>
                                <?php $offer_link = url('admin/offer_edit.php?id=' . $offer['product_offer_id']); ?>
                                <tr class="row-link" data-href="<?= e($offer_link) ?>">
                                    <td><a class="fw-semibold" href="<?= e($offer_link) ?>"><?= e($offer['product_name']) ?></a></td>
                                    <td class="text-end">&minus;<?= e($offer['product_offer_percent']) ?>%</td>
                                    <td class="text-nowrap"><?= adminTimeLeft($offer['product_offer_ends_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
