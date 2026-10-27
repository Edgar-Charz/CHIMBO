<?php

/**
 * The full shop footer (links, payment note, copyright). Included by footer.php on normal pages.
 */
?>
    <footer class="site-footer">
        <div class="container-xl">
            <div class="row g-4">
                <div class="col-12 col-lg-4">
                    <a class="site-logo site-logo--light" href="<?= e(url('')) ?>">
                        <img class="site-logo-mark" src="<?= e(logoUrl()) ?>" width="40" height="40" alt="">
                        <span class="site-logo-word"><?= e(SHOP_NAME) ?></span>
                    </a>
                    <p class="site-footer-tagline"><?= e(SHOP_TAGLINE) ?></p>
                    <p class="site-footer-text">Soko la jumla la vipodozi na urembo kwa wenye maduka. Nunua zaidi, lipa kidogo — tunakuletea dukani.</p>
                </div>

                <div class="col-6 col-lg-2">
                    <h2 class="site-footer-heading">Nunua</h2>
                    <ul class="site-footer-links">
                        <?php foreach (shopCategories() as $footer_category) : ?>
                            <li><a href="<?= e(categoryUrl($footer_category['category_id'], $footer_category['category_slug'])) ?>"><?= e($footer_category['category_name']) ?></a></li>
                        <?php endforeach; ?>
                        <li><a href="<?= e(collectionUrl('offers')) ?>">Ofa</a></li>
                        <li><a href="<?= e(url('search.php?collection=new')) ?>">Bidhaa Mpya</a></li>
                    </ul>
                </div>

                <div class="col-6 col-lg-2">
                    <h2 class="site-footer-heading">Akaunti</h2>
                    <ul class="site-footer-links">
                        <li><a href="<?= e(url('account.php')) ?>">Wasifu</a></li>
                        <li><a href="<?= e(url('orders.php')) ?>">Oda Zangu</a></li>
                        <li><a href="<?= e(url('wishlist.php')) ?>">Vipendwa</a></li>
                    </ul>
                </div>

                <div class="col-6 col-lg-2">
                    <h2 class="site-footer-heading">Msaada</h2>
                    <ul class="site-footer-links">
                        <li><a href="<?= e(url('help.php')) ?>">Maswali na Msaada</a></li>
                        <li><a href="<?= e(url('terms.php')) ?>">Vigezo na Masharti</a></li>
                        <li><a href="<?= e(url('privacy.php')) ?>">Faragha</a></li>
                    </ul>
                </div>

                <div class="col-6 col-lg-2">
                    <h2 class="site-footer-heading">Malipo</h2>
                    <ul class="site-footer-links site-footer-links--plain">
                        <?php foreach (paymentOptions()['methods'] as $payment_method) : // the methods switched on in the admin ?>
                            <li><?php $method = $payment_method; require __DIR__ . '/payment_method_icon.php'; ?> <?= e($payment_method['payment_method_name']) ?></li>
                        <?php endforeach; ?>
                        <li><i class="bi bi-shield-check" aria-hidden="true"></i> Malipo salama</li>
                    </ul>
                </div>
            </div>

            <p class="site-footer-legal">&copy; <?= e(date('Y')) ?> <?= e(SHOP_NAME) ?>. Haki zote zimehifadhiwa.</p>
        </div>
    </footer>
