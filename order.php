<?php

/**
 * One order (?id=): payment box (mobile money / bank, assets/js/payment.js), "Fuatilia Oda" timeline, delivery agent,
 * items, totals, delivery details and the actions Agiza Tena, Pakua Risiti and Ghairi oda (assets/js/orders.js).
 * Only the customer's own orders.
 */

require __DIR__ . '/includes/init.php';

$customer = requireCustomer();

try {
    $order = (new Order(Database::instance()))->getOrder($customer['user_id'], (int) requestInt('id'));
} catch (ApiException) {
    $order = null;
}

if ($order === null) {
    http_response_code(404);
} else {
    $timeline = orderTimeline($order);
    $address = $order['address'];
    $agent = $order['delivery_agent'];
    $status_message = ORDER_STATUS_MESSAGES[$order['order_status']] ?? '';
}

$page = [
    'title'   => $order === null ? 'Oda haipatikani' : 'Oda ' . $order['order_number'],
    'nav'     => 'orders',
    'scripts' => $order === null ? [] : ['orders.js', 'payment.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl order-page">
    <?php if ($order === null) : ?>
        <div class="state-block">
            <span class="state-block__icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
            <h1 class="state-block__title">Oda hii haipatikani</h1>
            <p class="state-block__text">Huenda kiungo si sahihi. Angalia oda zako zote hapa.</p>
            <a class="btn btn-primary" href="<?= e(url('orders.php')) ?>">Oda Zangu</a>
        </div>
    <?php else : ?>
        <a class="checkout-page__back" href="<?= e(url('orders.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Oda Zangu</a>

        <header class="order-page__header">
            <div>
                <h1 class="page-title order-page__title"><?= e($order['order_number']) ?></h1>
                <p class="order-page__placed">Iliagizwa <?= e(localDateTime($order['order_placed_at'], 'd/m/Y, H:i')) ?></p>
            </div>
            <span class="status-pill status-pill--<?= e(ORDER_STATUS_TONES[$order['order_status']] ?? 'info') ?>"><?= e(ORDER_STATUS_LABELS[$order['order_status']] ?? $order['order_status']) ?></span>
        </header>

        <div class="order-layout">
            <div class="order-layout__main">
                <?php require __DIR__ . '/includes/payment_box.php'; ?>

                <section class="order-panel" aria-labelledby="tracking-title">
                    <h2 class="order-panel__title" id="tracking-title">Fuatilia Oda</h2>
                    <p class="order-panel__lead"><?= e($status_message) ?></p>

                    <ol class="order-timeline">
                        <?php foreach ($timeline as $step) : ?>
                            <li class="order-timeline__step is-<?= e($step['state']) ?>" <?= $step['state'] === 'current' ? 'aria-current="step"' : '' ?>>
                                <span class="order-timeline__dot" aria-hidden="true">
                                    <i class="bi bi-<?= $step['state'] === 'stopped' ? 'x-lg' : 'check-lg' ?>"></i>
                                </span>
                                <span class="order-timeline__label"><?= e($step['label']) ?></span>
                                <?php if ($step['time'] !== null) : ?>
                                    <span class="order-timeline__time"><?= e($step['time']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ($order['order_status'] === 'delivered' && $order['order_delivered_at'] !== null) : ?>
                        <p class="order-panel__note"><i class="bi bi-calendar-check" aria-hidden="true"></i> Imefika <?= e(localDateTime($order['order_delivered_at'], 'd/m/Y, H:i')) ?></p>
                    <?php elseif (!in_array($order['order_status'], ORDER_STOPPED_STATUSES, true) && $order['order_estimated_delivery_date'] !== null) : ?>
                        <p class="order-panel__note"><i class="bi bi-calendar-event" aria-hidden="true"></i> Inatarajiwa kufika: <strong><?= e(swahiliDate($order['order_estimated_delivery_date'])) ?></strong></p>
                    <?php endif; ?>
                    <?php if ($order['order_cancel_reason']) : ?>
                        <p class="order-panel__note">Sababu ya kughairi: <?= e($order['order_cancel_reason']) ?></p>
                    <?php endif; ?>

                    <?php if ($agent !== null) : ?>
                        <div class="agent-card">
                            <?php if ($agent['delivery_agent_photo_url']) : ?>
                                <img class="agent-card__photo" src="<?= e($agent['delivery_agent_photo_url']) ?>" alt="" loading="lazy">
                            <?php else : ?>
                                <span class="agent-card__photo agent-card__photo--initial" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($agent['delivery_agent_full_name'], 0, 1))) ?></span>
                            <?php endif; ?>
                            <div class="agent-card__body">
                                <span class="agent-card__role">Wakala wa Uwasilishaji</span>
                                <strong><?= e($agent['delivery_agent_full_name']) ?></strong>
                            </div>
                            <a class="btn btn-primary btn-sm" href="tel:<?= e($agent['delivery_agent_phone']) ?>"><i class="bi bi-telephone" aria-hidden="true"></i> Piga simu</a>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="order-panel" aria-labelledby="items-title">
                    <h2 class="order-panel__title" id="items-title">Bidhaa (<?= e(formatPieces($order['piece_count'])) ?>)</h2>
                    <ul class="checkout-items order-items">
                        <?php foreach ($order['items'] as $item) : ?>
                            <li class="checkout-item">
                                <?php if ($item['product_image_url'] !== null) : ?>
                                    <img class="checkout-item__image" src="<?= e($item['product_image_url']) ?>" alt="" loading="lazy">
                                <?php else : ?>
                                    <span class="checkout-item__image image-placeholder" aria-hidden="true"><i class="bi bi-image"></i></span>
                                <?php endif; ?>
                                <span class="checkout-item__name">
                                    <a href="<?= e(productUrl($item['product_id'])) ?>"><?= e($item['product_name']) ?></a>
                                    <small><?= e(formatPieces($item['order_item_quantity'])) ?> × <?= e(formatTzs($item['order_item_unit_price'])) ?> <?= offerTag($item['order_item_offer_percent']) ?></small>
                                </span>
                                <span class="checkout-item__total"><?= e(formatTzs($item['order_item_line_total'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>

            <aside class="order-layout__side">
                <section class="order-summary" aria-labelledby="payment-title">
                    <h2 class="order-summary__title" id="payment-title">Malipo</h2>
                    <dl class="cart-summary checkout-totals">
                        <div class="cart-summary__row"><dt>Bidhaa</dt><dd><?= e(formatTzs($order['order_subtotal'])) ?></dd></div>
                        <div class="cart-summary__row"><dt>Usafirishaji</dt><dd><?= $order['order_delivery_fee'] === 0 ? 'Bure' : e(formatTzs($order['order_delivery_fee'])) ?></dd></div>
                        <?php if ($order['order_discount_total'] > 0) : ?>
                            <div class="cart-summary__row cart-summary__row--savings"><dt>Punguzo</dt><dd>−<?= e(formatTzs($order['order_discount_total'])) ?></dd></div>
                        <?php endif; ?>
                        <div class="cart-summary__row cart-summary__row--total"><dt>Jumla kuu</dt><dd><?= e(formatTzs($order['order_total'])) ?></dd></div>
                    </dl>
                    <p class="order-summary__note">
                        <?= e($order['payment']['payment_method']['payment_method_name']) ?> ·
                        <?php if ($order['order_payment_status'] === 'cod_pending') : ?>
                            andaa <strong><?= e(formatTzs($order['order_total'])) ?></strong> taslimu mzigo ukifika
                        <?php else : ?>
                            <?= e(PAYMENT_STATUS_LABELS[$order['order_payment_status']] ?? $order['order_payment_status']) ?>
                        <?php endif; ?>
                    </p>

                    <div class="order-actions">
                        <?php if (in_array($order['order_status'], ORDER_REORDER_STATUSES, true)) : ?>
                            <button class="btn btn-buy w-100" type="button" data-reorder="<?= e($order['order_id']) ?>"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Agiza Tena</button>
                        <?php endif; ?>
                        <a class="btn btn-outline-primary w-100" href="<?= e(receiptUrl($order['order_id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Pakua Risiti</a>
                        <?php if ($order['can_cancel']) : ?>
                            <button class="btn btn-link order-actions__cancel" type="button" data-bs-toggle="modal" data-bs-target="#cancel-order-dialog">Ghairi oda</button>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="order-summary" aria-labelledby="delivery-title">
                    <h2 class="order-summary__title" id="delivery-title">Usafirishaji</h2>
                    <p class="order-detail-text">
                        <strong><?= e($address['address_recipient_name']) ?></strong> · <?= e($address['address_phone']) ?><br>
                        <?= e(implode(', ', array_filter([$address['address_street'], $address['address_landmark']]))) ?><br>
                        <?= e(implode(', ', array_filter([$address['district_name'], $address['region_name']]))) ?>
                    </p>
                    <p class="order-detail-text"><i class="bi bi-truck" aria-hidden="true"></i> <?= e($order['delivery_method']['delivery_method_name']) ?></p>
                    <?php if ($order['order_customer_note']) : ?>
                        <p class="order-detail-text"><i class="bi bi-chat-left-text" aria-hidden="true"></i> <?= e($order['order_customer_note']) ?></p>
                    <?php endif; ?>
                </section>
            </aside>
        </div>

        <?php if ($order['can_cancel']) : ?>
            <div class="modal fade" id="cancel-order-dialog" tabindex="-1" aria-labelledby="cancel-order-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" id="cancel-order-form" novalidate data-cancel-order="<?= e($order['order_id']) ?>">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="cancel-order-title">Ghairi oda hii?</h2>
                            <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Funga"></button>
                        </div>
                        <div class="modal-body">
                            <p>Oda itaghairiwa na bidhaa kurudishwa stoku. Huwezi kutendua hili.</p>
                            <div data-field>
                                <label class="form-label" for="order_cancel_reason">Sababu <span class="text-secondary">(hiari)</span></label>
                                <textarea class="form-control" id="order_cancel_reason" name="order_cancel_reason" rows="2" maxlength="300"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-link" type="button" data-bs-dismiss="modal">Hapana, iache</button>
                            <button class="btn btn-danger" type="submit">Ndiyo, ghairi oda</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
