<?php

/**
 * Slide-out cart ("Kikapu Chako"). The lines and totals are drawn by assets/js/cart.js
 * from the priced cart the API returns.
 */
?>
<aside class="offcanvas offcanvas-end cart-drawer" tabindex="-1" id="cart-drawer" aria-labelledby="cart-drawer-title" data-cart-drawer>
    <div class="offcanvas-header cart-drawer__header">
        <h2 class="offcanvas-title cart-drawer__title" id="cart-drawer-title">
            Kikapu Chako <span class="cart-drawer__count" data-cart-count-label></span>
        </h2>
        <button class="btn-close" type="button" data-bs-dismiss="offcanvas" aria-label="Funga kikapu"></button>
    </div>

    <div class="offcanvas-body cart-drawer__body" data-cart-lines></div>

    <div class="cart-drawer__footer" hidden data-cart-summary>
        <dl class="cart-summary">
            <div class="cart-summary__row cart-summary__row--savings" hidden data-cart-savings-row>
                <dt><i class="bi bi-tags" aria-hidden="true"></i> Unaokoa</dt>
                <dd data-cart-savings></dd>
            </div>
            <div class="cart-summary__row cart-summary__row--total">
                <dt>Jumla ndogo</dt>
                <dd data-cart-subtotal></dd>
            </div>
        </dl>
        <p class="cart-drawer__note" data-cart-note>Gharama ya usafirishaji itaonyeshwa kwenye malipo.</p>
        <a class="btn btn-buy btn-lg w-100" href="<?= e(url('checkout.php')) ?>" data-cart-checkout>Endelea kwenye Malipo</a>
        <a class="btn btn-link w-100 cart-drawer__view" href="<?= e(url('cart.php')) ?>">Tazama kikapu kizima</a>
    </div>
</aside>
