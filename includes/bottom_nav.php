<?php

/**
 * App-like bottom navigation for phones and small tablets (hidden on desktop).
 * The active tab comes from $page['nav'].
 */

$bottom_nav_items = [
    ['key' => 'home',    'label' => 'Nyumbani', 'icon' => 'house',     'href' => url('')],
    ['key' => 'explore', 'label' => 'Gundua',   'icon' => 'grid',      'href' => url('explore.php')],
    ['key' => 'cart',    'label' => 'Kikapu',   'icon' => 'bag',       'href' => url('cart.php')],
    ['key' => 'orders',  'label' => 'Oda',      'icon' => 'box-seam',  'href' => url('orders.php')],
    ['key' => 'account', 'label' => 'Wasifu',   'icon' => 'person',    'href' => url('account.php')],
];
?>
<nav class="bottom-nav d-lg-none" aria-label="Menyu kuu">
    <?php foreach ($bottom_nav_items as $item) : ?>
        <?php $is_active = $item['key'] === $page['nav']; ?>
        <a class="bottom-nav__item<?= $is_active ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>" <?= $is_active ? 'aria-current="page"' : '' ?>>
            <span class="bottom-nav__icon">
                <i class="bi bi-<?= e($item['icon']) ?><?= $is_active ? '-fill' : '' ?>" aria-hidden="true"></i>
                <?php if ($item['key'] === 'cart') : ?>
                    <span class="count-badge" hidden data-cart-badge></span>
                <?php endif; ?>
            </span>
            <span class="bottom-nav__label"><?= e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
