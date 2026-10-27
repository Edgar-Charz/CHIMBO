<?php

/**
 * Top of every storefront page: <head>, announcement bar, sticky header (logo, search, account, cart)
 * and the desktop category menu — or the calm focused header when $page['is_focused'] is true.
 * Set $page before including it:
 *
 *   $page = ['title' => 'Kikapu', 'nav' => 'cart', 'scripts' => ['cart_page.js']];
 *   require __DIR__ . '/includes/header.php';
 */

$page = pageSettings($page ?? []);
$customer = currentCustomer();
$current_script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$current_collection = requestText('collection');
$is_all_products = $current_script === 'search.php'
    && requestText('q') === ''
    && $current_collection === '';
$is_gundua_current = $page['nav'] === 'explore'
    && !$is_all_products
    && !in_array($current_collection, ['offers', 'new', 'best_sellers'], true);
$current_category_id = $current_script === 'category.php' ? requestInt('id') : 0;

require __DIR__ . '/head.php';
?>

<body class="<?= e(trim($page['body_class'] . ($page['is_focused'] ? ' is-focused' : ''))) ?>">
    <a class="skip-link" href="#main">Ruka hadi maudhui</a>

    <?php if ($page['is_focused']) {
        require __DIR__ . '/focused_header.php';
        return;
    } ?>

    <div class="announcement-bar">
        <div class="container-xl announcement-bar-inner">
            <span><i class="bi bi-tags" aria-hidden="true"></i> Nunua zaidi, lipa kidogo</span>
            <span class="d-none d-md-inline"><i class="bi bi-<?= paymentOptions()['cash_only'] ? 'cash-coin' : 'phone' ?>" aria-hidden="true"></i> <?= e(paymentOptions()['short']) ?></span>
            <span class="d-none d-lg-inline"><i class="bi bi-patch-check" aria-hidden="true"></i> Wauzaji waliothibitishwa</span>
        </div>
    </div>

    <header class="site-header" data-site-header>
        <div class="container-xl site-header-inner">
            <a class="site-logo" href="<?= e(url('')) ?>" aria-label="<?= e(SHOP_NAME) ?> — Nyumbani">
                <img class="site-logo-mark" src="<?= e(logoUrl()) ?>" width="40" height="40" alt="">
                <span class="site-logo-word"><?= e(SHOP_NAME) ?></span>
            </a>

            <form class="site-search" role="search" action="<?= e(url('search.php')) ?>" method="get" data-search-form>
                <i class="bi bi-search site-search-icon" aria-hidden="true"></i>
                <input class="site-search-input" type="search" name="q" value="<?= e($page['search_query']) ?>"
                    placeholder="Tafuta bidhaa au chapa…" aria-label="Tafuta bidhaa" autocomplete="off"
                    role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="search-suggestions"
                    data-search-input>
                <button class="site-search-submit" type="submit">Tafuta</button>
                <div class="search-suggestions" id="search-suggestions" role="listbox" aria-label="Mapendekezo" hidden data-search-suggestions></div>
            </form>

            <nav class="site-actions" aria-label="Akaunti na kikapu">
                <a class="icon-button d-none d-lg-inline-flex" href="<?= e(url('wishlist.php')) ?>" aria-label="Vipendwa">
                    <i class="bi bi-heart" aria-hidden="true"></i>
                </a>

                <?php if ($customer !== null) : ?>
                    <a class="icon-button" href="<?= e(url('notifications.php')) ?>" aria-label="Arifa">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span class="count-badge" hidden data-unread-badge></span>
                    </a>

                    <div class="dropdown d-none d-lg-block">
                        <button class="header-account dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                            <span>Habari, <?= e(customerFirstName() ?? 'Mteja') ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= e(url('account.php')) ?>"><i class="bi bi-person" aria-hidden="true"></i> Wasifu</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('orders.php')) ?>"><i class="bi bi-box-seam" aria-hidden="true"></i> Oda Zangu</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('payments.php')) ?>"><i class="bi bi-wallet2" aria-hidden="true"></i> Malipo yangu</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('wishlist.php')) ?>"><i class="bi bi-heart" aria-hidden="true"></i> Vipendwa</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><button class="dropdown-item text-danger" type="button" data-logout><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Toka</button></li>
                        </ul>
                    </div>
                <?php else : ?>
                    <a class="header-account d-none d-lg-inline-flex" href="<?= e(loginUrl()) ?>">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <span>Ingia</span>
                    </a>
                <?php endif; ?>

                <button class="icon-button" type="button" aria-label="Fungua kikapu" data-cart-open>
                    <i class="bi bi-bag" aria-hidden="true"></i>
                    <span class="count-badge" hidden data-cart-badge></span>
                </button>
            </nav>
        </div>

        <nav class="category-nav d-none d-lg-block" aria-label="Menyu ya duka">
            <div class="container-xl category-nav-inner">
                <ul class="category-nav-list">
                    <li>
                        <a class="category-nav-link" href="<?= e(url('')) ?>" <?= $page['nav'] === 'home' ? 'aria-current="page"' : '' ?>>Nyumbani</a>
                    </li>
                    <li>
                        <a class="category-nav-link" href="<?= e(url('explore.php')) ?>" <?= $is_gundua_current ? 'aria-current="page"' : '' ?>>Gundua</a>
                    </li>
                    <li>
                        <a class="category-nav-link" href="<?= e(url('search.php')) ?>" <?= $is_all_products ? 'aria-current="page"' : '' ?>>Bidhaa zote</a>
                    </li>
                    <?php foreach (shopCategories() as $menu_category) : ?>
                        <li class="dropdown">
                            <button class="category-nav-link dropdown-toggle<?= $current_category_id === (int) $menu_category['category_id'] ? ' is-current' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false" <?= $current_category_id === (int) $menu_category['category_id'] ? 'aria-current="page"' : '' ?>>
                                <?= e($menu_category['category_name']) ?>
                            </button>
                            <div class="dropdown-menu category-menu">
                                <a class="category-menu-all" href="<?= e(categoryUrl($menu_category['category_id'], $menu_category['category_slug'])) ?>">
                                    Tazama <?= e($menu_category['category_name']) ?> zote <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                                <div class="category-menu-chips">
                                    <?php foreach ($menu_category['children'] as $menu_chip) : ?>
                                        <a class="chip" href="<?= e(categoryUrl($menu_category['category_id'], $menu_category['category_slug'], $menu_chip['category_id'])) ?>"><?= e($menu_chip['category_name']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <li><a class="category-nav-link category-nav-link--deal<?= $current_collection === 'offers' ? ' is-current' : '' ?>" href="<?= e(collectionUrl('offers')) ?>" <?= $current_collection === 'offers' ? 'aria-current="page"' : '' ?>><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> Ofa</a></li>
                    <li><a class="category-nav-link<?= $current_collection === 'new' ? ' is-current' : '' ?>" href="<?= e(url('search.php?collection=new')) ?>" <?= $current_collection === 'new' ? 'aria-current="page"' : '' ?>>Mpya</a></li>
                    <li><a class="category-nav-link<?= $current_collection === 'best_sellers' ? ' is-current' : '' ?>" href="<?= e(url('search.php?collection=best_sellers')) ?>" <?= $current_collection === 'best_sellers' ? 'aria-current="page"' : '' ?>>Zinazouzwa Zaidi</a></li>
                </ul>
                <p class="category-nav-note"><i class="bi bi-shield-check" aria-hidden="true"></i> Malipo yako ni salama</p>
            </div>
        </nav>
    </header>

    <main id="main" class="site-main">
