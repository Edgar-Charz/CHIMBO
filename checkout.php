<?php

/**
 * Malipo na Usafirishaji — one page: 1 address, 2 delivery, 3 payment, 4 review + "Thibitisha Oda"
 * (assets/js/checkout.js). Only for logged-in customers; guests are sent to login and brought back.
 * Every total shown comes from POST /checkout/preview; the order is placed with an Idempotency-Key.
 */

require __DIR__ . '/includes/init.php';

$customer = requireCustomer();

$checkout_data = [
    'addresses'     => (new Address(Database::instance()))->getAddresses($customer['user_id']),
    'new_address'   => [ // suggested values for a first address
        'address_recipient_name' => $customer['user_full_name'],
        'address_phone'          => $customer['user_phone'],
        'region_id'              => $customer['business']['region_id'] ?? null,
        'district_id'            => $customer['business']['district_id'] ?? null,
    ],
    'success_url'   => url('order_success.php'),
];

$page = [
    'title'      => 'Malipo na Usafirishaji',
    'is_focused' => true,
    'scripts'    => ['location_select.js', 'address_form.js', 'checkout.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl checkout-page" data-checkout>
    <a class="checkout-page__back" href="<?= e(url('cart.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Rudi kwenye kikapu</a>
    <h1 class="page-title">Malipo na Usafirishaji</h1>

    <div class="checkout-layout" data-checkout-content>
        <div class="checkout-steps">
            <section class="checkout-step" aria-labelledby="address-title">
                <h2 class="checkout-step__title" id="address-title"><span class="checkout-step__number">1</span> Anwani ya kupokelea</h2>
                <div class="choice-list" role="radiogroup" aria-labelledby="address-title" data-address-list></div>
                <button class="btn btn-link checkout-step__add" type="button" aria-expanded="false" aria-controls="address-form" data-address-toggle>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Ongeza anwani mpya
                </button>

                <?php require __DIR__ . '/includes/address_form.php'; ?>
            </section>

            <section class="checkout-step" aria-labelledby="delivery-title">
                <h2 class="checkout-step__title" id="delivery-title"><span class="checkout-step__number">2</span> Njia ya usafirishaji</h2>
                <div class="choice-list" role="radiogroup" aria-labelledby="delivery-title" data-delivery-list></div>
            </section>

            <section class="checkout-step" aria-labelledby="payment-title">
                <h2 class="checkout-step__title" id="payment-title"><span class="checkout-step__number">3</span> Njia ya malipo</h2>
                <div class="choice-list" role="radiogroup" aria-labelledby="payment-title" data-payment-list></div>
            </section>
        </div>

        <aside class="order-summary checkout-summary" aria-labelledby="review-title">
            <h2 class="checkout-step__title" id="review-title"><span class="checkout-step__number">4</span> Hakiki oda yako</h2>
            <ul class="checkout-items" data-checkout-items></ul>
            <dl class="cart-summary checkout-totals" aria-live="polite" data-checkout-totals></dl>

            <div class="checkout-summary__note" data-field>
                <label class="form-label" for="order_customer_note">Ujumbe kwa CHIMBO <span class="text-secondary">(hiari)</span></label>
                <textarea class="form-control" id="order_customer_note" name="order_customer_note" rows="2" maxlength="500"
                    placeholder="Mf. Piga simu kabla ya kufika" data-order-note></textarea>
            </div>

            <button class="btn btn-buy btn-lg w-100" type="button" disabled data-place-order>Thibitisha Oda</button>
            <p class="order-summary__note" data-pay-note></p>
        </aside>
    </div>
</section>

<script type="application/json" id="checkout-data"><?= json_encode($checkout_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
