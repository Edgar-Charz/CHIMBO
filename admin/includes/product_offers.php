<?php

/**
 * Product page — "Offers" box: this product's offers (running, scheduled, ended) with Edit and End now / Cancel,
 * and "New offer" for this product. Included by product_edit.php (uses $product, $product_offers).
 */
?>
<section class="admin-panel mb-3" id="offers">
    <div class="admin-panel-header">
        <h2 class="admin-panel-title">Offers</h2>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/offer_edit.php?product_id=' . $product['product_id'])) ?>">
            <i class="bi bi-plus-lg"></i> New offer
        </a>
    </div>
    <?php if (!$product_offers): ?>
        <div class="admin-panel-body">
            <p class="small text-muted mb-0">No offers yet. An offer takes a percentage off every price level for a limited time.</p>
        </div>
    <?php else: ?>
        <ul class="order-payments">
            <?php foreach ($product_offers as $offer): ?>
                <?php
                $offer_id = (int) $offer['product_offer_id'];
                $percent  = (int) $offer['product_offer_percent'];
                $status   = $offer['product_offer_status'];
                ?>
                <li>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">−<?= e($percent) ?>% · <?= e(adminOfferPrice((int) $product['product_price'], $percent)) ?></div>
                            <div class="small text-muted"><?= e(adminDateTime($offer['product_offer_starts_at'])) ?> → <?= e(adminDateTime($offer['product_offer_ends_at'])) ?></div>
                            <?php if ($offer['created_by_admin_name']): ?>
                                <div class="small text-muted">by <?= e($offer['created_by_admin_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?= adminStatusBadge($status) ?>
                    </div>
                    <?php if ($status !== 'ended'): ?>
                        <div class="mt-2">
                            <?= adminRowActions(
                                adminActionLink('bi-pencil', 'Edit', url("admin/offer_edit.php?id={$offer_id}")),
                                $status === 'running'
                                    ? adminActionButton('bi-stop-circle', 'End now', 'end_offer', $offer_id, "End the {$percent}% offer now? Customers pay the normal price at once.", is_danger: true)
                                    : adminActionButton('bi-x-circle', 'Cancel', 'end_offer', $offer_id, "Cancel the {$percent}% offer? It will not start.", is_danger: true),
                            ) ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
