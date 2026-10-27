<?php

/**
 * Slide-out cart ("Kikapu Chako"). The lines and totals are drawn by assets/js/cart.js
 * from the priced cart the API returns.
 */
?>
<aside class="offcanvas offcanvas-end cart-drawer" tabindex="-1" id="cart-drawer" aria-labelledby="cart-drawer-title" data-cart-drawer>
    <div class="offcanvas-header cart-drawer-header">
        <h2 class="offcanvas-title cart-drawer-title" id="cart-drawer-title">
            Kikapu Chako <span class="cart-drawer-count" data-cart-count-label></span>
        </h2>
        <button class="btn-close" type="button" data-bs-dismiss="offcanvas" aria-label="Funga kikapu"></button>
    </div>

    <div class="offcanvas-body cart-drawer-body" data-cart-lines></div>

    <div class="cart-drawer-footer" hidden data-cart-summary>
        <dl class="cart-summary">
            <div class="cart-summary-row cart-summary-row--savings" hidden data-cart-savings-row>
                <dt><i class="bi bi-tags" aria-hidden="true"></i> Unaokoa</dt>
                <dd data-cart-savings></dd>
            </div>
            <div class="cart-summary-row cart-summary-row--total">
                <dt>Jumla ndogo</dt>
                <dd data-cart-subtotal></dd>
            </div>
        </dl>
        <p class="cart-drawer-note" data-cart-note>Gharama ya usafirishaji itaonyeshwa kwenye malipo.</p>
        <a class="btn btn-buy btn-lg w-100" href="<?= e(url('checkout.php')) ?>" data-cart-checkout>Endelea kwenye Malipo</a>
        <a class="btn btn-link w-100 cart-drawer-view" href="<?= e(url('cart.php')) ?>">Tazama kikapu kizima</a>
    </div>
</aside>
