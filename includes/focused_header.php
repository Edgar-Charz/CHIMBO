<?php

/**
 * Calm header for login and checkout (like a store's checkout): the logo and a safety note,
 * no search, menus or bottom navigation, so nothing pulls the customer away. Included by header.php.
 */
?>
<header class="focused-header">
    <div class="container-xl focused-header__inner">
        <a class="site-logo" href="<?= e(url('')) ?>" aria-label="<?= e(SHOP_NAME) ?> — Nyumbani">
            <img class="site-logo__mark" src="<?= e(asset('img/logo-mark.webp')) ?>" width="40" height="40" alt="">
            <span class="site-logo__word"><?= e(SHOP_NAME) ?></span>
        </a>
        <p class="focused-header__note"><i class="bi bi-shield-lock" aria-hidden="true"></i> Salama na siri</p>
    </div>
</header>

<main id="main" class="site-main site-main--focused">
