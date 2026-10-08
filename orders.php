<?php

/**
 * Oda Zangu — the customer's orders (web and app), newest first, in tabs Zote / Zinazoendelea / Zimefika.
 * "Agiza Tena" puts an order's products back in the cart (assets/js/orders.js).
 */

require __DIR__ . '/includes/init.php';

const ORDER_TABS = ['all' => 'Zote', 'active' => 'Zinazoendelea', 'delivered' => 'Zimefika'];

$customer = requireCustomer();
$group = array_key_exists(requestText('group'), ORDER_TABS) ? requestText('group') : 'all';
$page_number = requestInt('page') ?? 1;

$orders = (new Order(Database::instance()))->getOrders($customer['user_id'], ['group' => $group, 'page' => $page_number]);
$last_page = max(1, (int) ceil($orders['total'] / $orders['per_page']));
$page_link = fn (int $number): string => url('orders.php?' . http_build_query(['group' => $group, 'page' => $number]));

$page = [
    'title'   => 'Oda Zangu',
    'nav'     => 'orders',
    'scripts' => ['orders.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl orders-page">
    <h1 class="page-title">Oda Zangu</h1>

    <nav class="catalog-chips" aria-label="Aina za oda">
        <?php foreach (ORDER_TABS as $tab_group => $tab_label) : ?>
            <a class="chip<?= $tab_group === $group ? ' is-active' : '' ?>" href="<?= e(url('orders.php?group=' . $tab_group)) ?>" <?= $tab_group === $group ? 'aria-current="page"' : '' ?>><?= e($tab_label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($orders['items'] === []) : ?>
        <div class="state-block">
            <span class="state-block__icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
            <h2 class="state-block__title"><?= $group === 'all' ? 'Bado hujaagiza' : 'Hakuna oda hapa' ?></h2>
            <p class="state-block__text">Oda zako zote — za tovuti na za programu ya simu — zitaonekana hapa.</p>
            <a class="btn btn-primary" href="<?= e(url('')) ?>">Anza kununua</a>
        </div>
    <?php else : ?>
        <div class="orders-list">
            <?php foreach ($orders['items'] as $order) : ?>
                <?php require __DIR__ . '/includes/order_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($last_page > 1) : ?>
            <nav class="orders-pager" aria-label="Kurasa za oda">
                <?php if ($page_number > 1) : ?>
                    <a class="btn btn-outline-primary" href="<?= e($page_link($page_number - 1)) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Mpya zaidi</a>
                <?php endif; ?>
                <span>Ukurasa <?= e($page_number) ?> kati ya <?= e($last_page) ?></span>
                <?php if ($page_number < $last_page) : ?>
                    <a class="btn btn-outline-primary" href="<?= e($page_link($page_number + 1)) ?>">Za zamani <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
