<?php

/**
 * Kikapu — the full cart. Lines and totals are drawn by assets/js/cart_page.js from the priced cart
 * that cart.js loads (guests: priced from the browser's list; customers: their account cart).
 */

require __DIR__ . '/includes/init.php';

$is_guest = currentCustomer() === null;

$page = [
    'title'       => 'Kikapu',
    'description' => 'Kikapu chako cha CHIMBO: bei za jumla zinabadilika kulingana na idadi.',
    'nav'         => 'cart',
    'scripts'     => ['cart_page.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl cart-page">
    <h1 class="page-title">Kikapu Chako</h1>

    <div class="cart-page-layout">
        <div class="cart-page-lines" aria-live="polite" data-cart-page-lines></div>

        <aside class="order-summary" aria-labelledby="cart-summary-title" hidden data-cart-summary>
            <h2 class="order-summary-title" id="cart-summary-title">Muhtasari</h2>
            <dl class="cart-summary">
                <div class="cart-summary-row">
                    <dt>Idadi ya vipande</dt>
                    <dd data-cart-pieces></dd>
                </div>
                <div class="cart-summary-row cart-summary-row--savings" hidden data-cart-savings-row>
                    <dt><i class="bi bi-tags" aria-hidden="true"></i> Unaokoa</dt>
                    <dd data-cart-savings></dd>
                </div>
                <div class="cart-summary-row cart-summary-row--total">
                    <dt>Jumla ndogo</dt>
                    <dd data-cart-subtotal></dd>
                </div>
            </dl>
            <p class="order-summary-note" data-cart-note></p>
            <a class="btn btn-buy btn-lg w-100" href="<?= e(url('checkout.php')) ?>" data-cart-checkout>Endelea kwenye Malipo</a>
            <?php if ($is_guest) : ?>
                <p class="order-summary-hint"><i class="bi bi-person-check" aria-hidden="true"></i> Utaingia kwa namba ya simu kabla ya kulipa — kikapu chako kitabaki.</p>
            <?php endif; ?>
            <ul class="order-summary-promises">
                <li><i class="bi bi-shield-check" aria-hidden="true"></i> <?= e(paymentOptions()['short']) ?></li>
                <li><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Agiza tena kwa kubofya mara moja</li>
            </ul>
            <button class="btn btn-link order-summary-clear" type="button" data-cart-clear>
                <i class="bi bi-trash3" aria-hidden="true"></i> Futa kikapu chote
            </button>
        </aside>
    </div>
</section>

<div class="buy-bar is-visible cart-page-bar" hidden data-cart-summary>
    <div class="buy-bar-summary">
        <span>Jumla ndogo</span>
        <strong data-cart-subtotal></strong>
    </div>
    <a class="btn btn-buy" href="<?= e(url('checkout.php')) ?>" data-cart-checkout>Endelea kwenye Malipo</a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
