<?php

/**
 * Anwani zangu — the customer's delivery addresses (shared with the app): add, edit, make main, delete.
 * assets/js/addresses.js draws the list; the form is the shared includes/address_form.php.
 */

require __DIR__ . '/includes/init.php';

$customer = requireCustomer();

$addresses_data = [
    'addresses' => (new Address(Database::instance()))->getAddresses($customer['user_id']),
    'suggested' => [
        'address_recipient_name' => $customer['user_full_name'],
        'address_phone'          => $customer['user_phone'],
        'region_id'              => $customer['business']['region_id'] ?? null,
        'district_id'            => $customer['business']['district_id'] ?? null,
    ],
];

$page = [
    'title'   => 'Anwani zangu',
    'nav'     => 'account',
    'scripts' => ['location_select.js', 'address_form.js', 'addresses.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl addresses-page">
    <a class="checkout-page__back" href="<?= e(url('account.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wasifu</a>
    <div class="notifications-page__heading">
        <h1 class="page-title">Anwani zangu</h1>
        <button class="btn btn-primary" type="button" data-address-add><i class="bi bi-plus-lg" aria-hidden="true"></i> Ongeza anwani</button>
    </div>

    <?php require __DIR__ . '/includes/address_form.php'; ?>

    <div class="address-list" aria-live="polite" data-address-list></div>
</section>

<script type="application/json" id="addresses-data"><?= json_encode($addresses_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
