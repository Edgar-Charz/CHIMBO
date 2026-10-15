<?php

/**
 * One order in "Oda Zangu": number, date, status, the first product photos, total, "Agiza Tena".
 * Expects $order (the API order shape).
 */

$order_link = url('order.php?id=' . $order['order_id']);
$extra_item_count = max(0, count($order['items']) - ORDER_CARD_PHOTOS);
?>
<article class="order-card">
    <header class="order-card__header">
        <div>
            <a class="order-card__number" href="<?= e($order_link) ?>"><?= e($order['order_number']) ?></a>
            <p class="order-card__date"><?= e(localDateTime($order['order_placed_at'], 'd/m/Y, H:i')) ?></p>
        </div>
        <span class="status-pill status-pill--<?= e(orderStatusTone($order)) ?>"><?= e(orderStatusLabel($order)) ?></span>
    </header>

    <div class="order-card__photos" aria-hidden="true">
        <?php foreach (array_slice($order['items'], 0, ORDER_CARD_PHOTOS) as $item) : ?>
            <?php if ($item['product_image_url'] !== null) : ?>
                <img class="order-card__photo" src="<?= e($item['product_image_url']) ?>" alt="" loading="lazy" decoding="async">
            <?php else : ?>
                <span class="order-card__photo image-placeholder"><i class="bi bi-image"></i></span>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($extra_item_count > 0) : ?>
            <span class="order-card__photo order-card__more">+<?= e($extra_item_count) ?></span>
        <?php endif; ?>
    </div>

    <footer class="order-card__footer">
        <p class="order-card__summary">
            <?= e($order['item_count']) ?> bidhaa · <?= e(formatPieces($order['piece_count'])) ?>
            <strong><?= e(formatTzs($order['order_total'])) ?></strong>
            <?php if ($order['payment']['is_balance_payment']) : ?>
                <span class="order-card__balance">Salio la kulipa: <?= e(formatTzs($order['payment']['payment_amount'])) ?></span>
            <?php endif; ?>
        </p>
        <div class="order-card__actions">
            <?php if (in_array($order['order_status'], ORDER_REORDER_STATUSES, true)) : ?>
                <button class="btn btn-outline-primary btn-sm" type="button" data-reorder="<?= e($order['order_id']) ?>">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Agiza Tena
                </button>
            <?php endif; ?>
            <?php if ($order['payment']['can_submit_payment']) : ?>
                <a class="btn btn-buy btn-sm" href="<?= e($order_link) ?>#payment-box"><i class="bi bi-wallet2" aria-hidden="true"></i> Lipa sasa</a>
            <?php else : ?>
                <a class="btn btn-primary btn-sm" href="<?= e($order_link) ?>">Fuatilia</a>
            <?php endif; ?>
        </div>
    </footer>
</article>
