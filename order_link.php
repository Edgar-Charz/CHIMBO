<?php

/** Public landing page for the signed order link printed on packing slips. */
require __DIR__ . '/includes/init.php';

$code = (string) ($_GET['code'] ?? '');
$order_link = new OrderLink(Database::instance());
$order_reference = null;
$rate_limited = false;

try {
    (new RateLimiter(Database::instance()))->hit(
        'order-qr:ip:' . hash('sha256', clientIp()),
        OrderLink::PUBLIC_REQUESTS_PER_HOUR,
        OrderLink::PUBLIC_RATE_WINDOW_SECONDS
    );
    $order_reference = $order_link->findOrderForCode($code);
} catch (ApiException $exception) {
    if ($exception->status() !== 429) {
        throw $exception;
    }
    http_response_code(429);
    $rate_limited = true;
}

if (!$rate_limited && $order_reference === null) {
    http_response_code(404);
}

if ($order_reference !== null) {
    $customer = currentCustomer();
    if (isCustomerReady($customer) && (int) $customer['user_id'] === (int) $order_reference['user_id']) {
        redirect(url('order.php?id=' . (int) $order_reference['order_id']));
    }

    $public_order = [
        'order_number' => $order_reference['order_number'],
        'order_status' => ORDER_STATUS_LABELS[$order_reference['order_status']] ?? 'Inasasishwa',
    ];
    $public_items = $order_link->getPublicItems($code) ?? [];
    $support_phone = (new Settings(Database::instance()))->getSupportContacts()['support_phone'];
}

header('Cache-Control: private, no-store');
$page = [
    'title' => $rate_limited ? 'Subiri kidogo' : ($order_reference === null ? 'QR code si halali' : 'Taarifa za oda'),
    'nav' => '',
    'scripts' => $order_reference === null || $rate_limited ? [] : ['order-link.js'],
];
require __DIR__ . '/includes/header.php';
?>

<section class="container-xl py-5">
    <?php if ($rate_limited) : ?>
        <div class="state-block">
            <h1 class="state-block-title">Subiri kidogo</h1>
            <p class="state-block-text">Kumekuwa na maombi mengi. Tafadhali jaribu tena baada ya muda.</p>
        </div>
    <?php elseif ($order_reference === null) : ?>
        <div class="state-block">
            <h1 class="state-block-title">QR code si halali</h1>
            <p class="state-block-text">Msimbo huu wa QR si sahihi au umesitishwa. Tafadhali omba kiungo kipya kutoka CHIMBO au wasiliana nasi kwa msaada.</p>
            <a class="btn btn-outline-primary" href="<?= e(url('help.php')) ?>">Wasiliana na CHIMBO</a>
        </div>
    <?php else : ?>
        <div class="row">
            <div class="col-12 col-md-8 col-lg-6 col-xl-5 mx-auto">
                <article class="card" data-order-link-page data-code="<?= e($code) ?>"
                         data-admin-check-url="<?= e(url('admin/ajax/order_link.php')) ?>">
                    <div class="card-body p-4">
                        <p class="text-uppercase text-secondary small mb-2">Taarifa za oda</p>
                        <h1 class="h3 mb-4">#<?= e($public_order['order_number']) ?></h1>
                        <dl class="mb-4">
                            <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                                <dt class="fw-normal">Hali ya oda</dt>
                                <dd class="fw-semibold text-end mb-0"><?= e($public_order['order_status']) ?></dd>
                            </div>
                        </dl>
                        <h2 class="h6">Bidhaa zilizomo</h2>
                        <ul class="list-group list-group-flush mb-4">
                            <?php foreach ($public_items as $item) : ?>
                                <li class="list-group-item d-flex justify-content-between gap-3 px-0">
                                    <span><?= e($item['order_item_product_name']) ?></span>
                                    <strong class="text-nowrap">
                                        <?= e(number_format((int) $item['order_item_quantity'])) ?>
                                        <?= e($item['order_item_unit_label']) ?>
                                    </strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mb-0">
                            Msaada:
                            <?php if ($support_phone) : ?>
                                <a href="tel:<?= e($support_phone) ?>"><?= e(Phone::format($support_phone)) ?></a>
                            <?php else : ?>
                                Wasiliana na CHIMBO kupitia ukurasa wa <a href="<?= e(url('help.php')) ?>">Msaada</a>.
                            <?php endif; ?>
                        </p>
                    </div>
                </article>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
