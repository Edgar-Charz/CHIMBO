<?php

/**
 * "Oda Imepokelewa!" — shown right after checkout (?id={order_id}). Only the customer's own orders.
 */

require __DIR__ . '/includes/init.php';

const SUCCESS_ITEMS_SHOWN = 4;

$customer = requireCustomer();

try {
    $order = (new Order(Database::instance()))->getOrder($customer['user_id'], (int) requestInt('id'));
} catch (ApiException) {
    $order = null; // someone else's order or a wrong id: a plain "not found"
}

if ($order === null) {
    http_response_code(404);
} else {
    $address = $order['address'];
    $hidden_item_count = max(0, count($order['items']) - SUCCESS_ITEMS_SHOWN);
}

$page = [
    'title'   => $order === null ? 'Oda haipatikani' : 'Oda Imepokelewa',
    'nav'     => 'orders',
    'scripts' => $order === null ? [] : ['payment.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl success-page">
    <?php if ($order === null) : ?>
        <div class="state-block">
            <span class="state-block__icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
            <h1 class="state-block__title">Oda hii haipatikani</h1>
            <p class="state-block__text">Angalia oda zako zote kwenye "Oda Zangu".</p>
            <a class="btn btn-primary" href="<?= e(url('orders.php')) ?>">Oda Zangu</a>
        </div>
    <?php else : ?>
        <div class="success-card">
            <div class="success-check" aria-hidden="true">
                <i class="bi bi-check-lg"></i>
            </div>
            <h1 class="success-card__title">Oda Imepokelewa!</h1>
            <p class="success-card__text">
                Asante <?= e(customerFirstName() ?? '') ?>, tumepokea oda yako.
                <?= $order['payment']['can_submit_payment'] ? 'Ilipie sasa — tutaanza kuiandaa mara tukithibitisha malipo.' : 'Tunaanza kuiandaa na tutakujulisha kila hatua.' ?>
            </p>

            <dl class="success-facts">
                <div>
                    <dt>Namba ya oda</dt>
                    <dd class="success-facts__number"><?= e($order['order_number']) ?></dd>
                </div>
                <div>
                    <dt>Jumla kuu</dt>
                    <dd><?= e(formatTzs($order['order_total'])) ?></dd>
                </div>
                <div>
                    <dt>Malipo</dt>
                    <dd><?= e($order['payment']['payment_method']['payment_method_name']) ?></dd>
                </div>
                <?php if ($order['order_estimated_delivery_date'] !== null) : ?>
                    <div>
                        <dt>Inatarajiwa kufika</dt>
                        <dd><?= e(swahiliDate($order['order_estimated_delivery_date'])) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($order['order_payment_status'] === 'cod_pending') : ?>
                <p class="success-card__pay-note"><i class="bi bi-cash-coin" aria-hidden="true"></i> Andaa <strong><?= e(formatTzs($order['order_total'])) ?></strong> taslimu kwa ajili ya mpeleka mzigo.</p>
            <?php endif; ?>

            <?php require __DIR__ . '/includes/payment_box.php'; ?>

            <div class="success-card__details">
                <h2 class="success-card__subtitle">Itapelekwa kwa</h2>
                <p>
                    <?= e($address['address_recipient_name']) ?> · <?= e($address['address_phone']) ?><br>
                    <?= e(implode(', ', array_filter([$address['address_street'], $address['district_name'], $address['region_name']]))) ?>
                </p>

                <h2 class="success-card__subtitle">Bidhaa (<?= e(formatPieces($order['piece_count'])) ?>)</h2>
                <ul class="success-items">
                    <?php foreach (array_slice($order['items'], 0, SUCCESS_ITEMS_SHOWN) as $item) : ?>
                        <li>
                            <span><?= e($item['product_name']) ?> <small>× <?= e($item['order_item_quantity']) ?></small></span>
                            <span><?= e(formatTzs($item['order_item_line_total'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($hidden_item_count > 0) : ?>
                        <li class="success-items__more">na bidhaa nyingine <?= e($hidden_item_count) ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="success-card__actions">
                <a class="btn btn-primary btn-lg" href="<?= e(url('order.php?id=' . $order['order_id'])) ?>"><i class="bi bi-geo-alt" aria-hidden="true"></i> Fuatilia Oda</a>
                <a class="btn btn-outline-primary btn-lg" href="<?= e(url('')) ?>">Endelea kununua</a>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
