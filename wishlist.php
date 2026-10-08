<?php

/**
 * Vipendwa — products the customer saved with ♡ (shared with the app), newest first.
 * Drawn with the shared product card by assets/js/wishlist_page.js; un-hearting removes the card.
 */

require __DIR__ . '/includes/init.php';

$customer = requireCustomer();
$saved_products = (new Wishlist(Database::instance()))->getSavedProducts($customer['user_id']);

$page = [
    'title'   => 'Vipendwa',
    'nav'     => 'account',
    'scripts' => ['product_card.js', 'wishlist_page.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl wishlist-page">
    <h1 class="page-title">Vipendwa <span class="page-title__count" data-wishlist-count><?= $saved_products === [] ? '' : e('(' . count($saved_products) . ')') ?></span></h1>
    <div class="product-grid" data-wishlist-grid></div>
</section>

<script type="application/json" id="wishlist-data"><?= json_encode($saved_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
