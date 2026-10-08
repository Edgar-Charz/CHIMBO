<?php

/**
 * Nyumbani — the home page.
 * PHP draws the page from Home::getHome() (the same data as GET /home). The product grids are drawn by
 * assets/js/home.js with the shared product card, from that same data printed as JSON (no second request).
 */

require __DIR__ . '/includes/init.php';

const HOME_TIER_EXAMPLE_MIN_TIERS = 3; // the wholesale example needs a product with a real price ladder

$customer = currentCustomer();
$home = (new Home(Database::instance()))->getHome($customer['user_id'] ?? null);

$product_tabs = array_values(array_filter([
    ['key' => 'best_sellers', 'label' => 'Zinazouzwa Zaidi', 'products' => $home['best_sellers']],
    ['key' => 'new',          'label' => 'Mpya',             'products' => $home['new_arrivals']],
    ['key' => 'deals',        'label' => 'Ofa',              'products' => $home['deals']],
], fn(array $tab): bool => $tab['products'] !== []));

$has_subcategories = array_merge(...array_column($home['top_categories'], 'children')) !== [];

// Wholesale example: the best seller with the longest price ladder
$tiers_by_product = (new Product(Database::instance()))->getPriceTiersForProducts(array_column($home['best_sellers'], 'product_id'));
uasort($tiers_by_product, fn(array $first, array $second): int => count($second) <=> count($first));
$example_product_id = array_key_first($tiers_by_product);
$has_tier_example = $example_product_id !== null && count($tiers_by_product[$example_product_id]) >= HOME_TIER_EXAMPLE_MIN_TIERS;

if ($has_tier_example) {
    $example_product = array_column($home['best_sellers'], null, 'product_id')[$example_product_id];
    $example_tiers = tierRows($tiers_by_product[$example_product_id]);
    $example_savings_percent = $example_tiers[array_key_last($example_tiers)]['savings_percent'];
}

$home_products = [
    'tabs'             => array_column($product_tabs, 'products', 'key'),
    'offers'           => $home['offers'],
    'recently_ordered' => $home['recently_ordered'],
];

$page = [
    'nav'     => 'home',
    'scripts' => ['product_card.js', 'home.js'],
];

require __DIR__ . '/includes/header.php';
?>

<h1 class="visually-hidden"><?= e(SHOP_NAME) ?> — soko la jumla la vipodozi na urembo kwa wenye maduka</h1>

<section class="container-xl home-hero" aria-label="Matangazo">
    <?php if ($home['banners'] === []) : ?>
        <a class="hero-banner" href="<?= e(collectionUrl('best_sellers')) ?>">
            <div class="hero-banner__copy">
                <p class="hero-banner__eyebrow">Soko la jumla kwa wenye maduka</p>
                <h2 class="hero-banner__title">Bei za jumla kwa duka lako</h2>
                <p class="hero-banner__text">Nunua zaidi, lipa kidogo — kisha lipa ukipokea mzigo.</p>
                <span class="btn btn-buy btn-lg">Anza kununua</span>
            </div>
        </a>
    <?php else : ?>
        <div class="carousel slide home-hero__carousel" id="home-hero-carousel" data-bs-ride="carousel" data-bs-interval="6000">
            <div class="carousel-inner">
                <?php foreach ($home['banners'] as $index => $banner) : ?>
                    <div class="carousel-item<?= $index === 0 ? ' active' : '' ?>">
                        <a class="hero-banner" href="<?= e(bannerUrl($banner)) ?>" <?= $banner['banner_target_type'] === 'url' ? ' rel="noopener"' : '' ?>>
                            <div class="hero-banner__copy">
                                <p class="hero-banner__eyebrow">CHIMBO Jumla</p>
                                <h2 class="hero-banner__title"><?= e($banner['banner_title']) ?></h2>
                                <?php if ($banner['banner_subtitle']) : ?>
                                    <p class="hero-banner__text"><?= e($banner['banner_subtitle']) ?></p>
                                <?php endif; ?>
                                <span class="btn btn-buy btn-lg"><?= e($banner['banner_button_label'] ?: 'Nunua sasa') ?></span>
                            </div>
                            <?php if ($banner['banner_image_url']) : ?>
                                <img class="hero-banner__image" src="<?= e($banner['banner_image_url']) ?>" alt=""
                                    <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
                            <?php endif; ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($home['banners']) > 1) : ?>
                <div class="carousel-indicators">
                    <?php foreach ($home['banners'] as $index => $banner) : ?>
                        <button type="button" data-bs-target="#home-hero-carousel" data-bs-slide-to="<?= $index ?>"
                            class="<?= $index === 0 ? 'active' : '' ?>" <?= $index === 0 ? 'aria-current="true"' : '' ?>
                            aria-label="Tangazo <?= $index + 1 ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="home-hero__tiles">
        <a class="promo-tile promo-tile--deal" href="<?= e(collectionUrl('deals')) ?>">
            <span class="promo-tile__icon"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>
            <span class="promo-tile__title">Ofa za wiki</span>
            <span class="promo-tile__text">Bei maalum kwa muda mfupi</span>
            <i class="bi bi-arrow-right promo-tile__arrow" aria-hidden="true"></i>
        </a>
        <a class="promo-tile promo-tile--new" href="<?= e(collectionUrl('new')) ?>">
            <span class="promo-tile__icon"><i class="bi bi-stars" aria-hidden="true"></i></span>
            <span class="promo-tile__title">Bidhaa mpya</span>
            <span class="promo-tile__text">Zimewasili hivi karibuni</span>
            <i class="bi bi-arrow-right promo-tile__arrow" aria-hidden="true"></i>
        </a>
    </div>
</section>

<section class="container-xl" aria-label="Faida za CHIMBO">
    <ul class="trust-strip">
        <li class="trust-strip__item"><i class="bi bi-tags" aria-hidden="true"></i><span><strong>Bei za jumla</strong> Nunua zaidi, lipa kidogo</span></li>
        <li class="trust-strip__item"><i class="bi bi-cash-coin" aria-hidden="true"></i><span><strong>Lipa ukipokea</strong> Hakuna malipo ya mapema</span></li>
        <li class="trust-strip__item"><i class="bi bi-patch-check" aria-hidden="true"></i><span><strong>Wauzaji waliothibitishwa</strong> Bidhaa halisi</span></li>
        <li class="trust-strip__item"><i class="bi bi-truck" aria-hidden="true"></i><span><strong>Tunakuletea dukani</strong> Fuatilia oda yako</span></li>
    </ul>
</section>

<?php if ($home['offers'] !== []) : ?>
    <section class="container-xl home-section home-offers" aria-labelledby="home-offers-title">
        <div class="section-heading">
            <div>
                <h2 class="section-heading__title" id="home-offers-title"><i class="bi bi-alarm" aria-hidden="true"></i> Ofa za muda</h2>
                <p class="section-heading__text">Bei maalum kwa muda mfupi tu — wahi kabla hazijaisha.</p>
            </div>
            <a class="section-heading__link" href="<?= e(collectionUrl('offers')) ?>">Ona zote <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="product-grid product-grid--rail" data-home-offers></div>
    </section>
<?php endif; ?>

<?php if ($home['recently_ordered'] !== []) : ?>
    <section class="container-xl home-section" aria-labelledby="home-reorder-title">
        <div class="section-heading">
            <div>
                <h2 class="section-heading__title" id="home-reorder-title">Karibu tena, <?= e(customerFirstName() ?? 'mteja') ?></h2>
                <p class="section-heading__text">Agiza tena bidhaa ulizonunua hivi karibuni.</p>
            </div>
            <a class="section-heading__link" href="<?= e(url('orders.php')) ?>">Oda zangu <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="product-grid product-grid--rail" data-home-recent></div>
    </section>
<?php endif; ?>

<section class="container-xl home-section" aria-labelledby="home-categories-title">
    <div class="section-heading">
        <h2 class="section-heading__title" id="home-categories-title">Nunua kwa aina</h2>
        <a class="section-heading__link" href="<?= e(url('explore.php')) ?>">Aina zote <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>

    <div class="category-tiles">
        <?php foreach ($home['top_categories'] as $category) : ?>
            <a class="category-tile" href="<?= e(categoryUrl($category['category_id'], $category['category_slug'])) ?>">
                <?php if ($category['category_image_url'] !== null) : ?>
                    <img class="category-tile__image" src="<?= e($category['category_image_url']) ?>" alt="" loading="lazy" decoding="async">
                <?php endif; ?>
                <span class="category-tile__copy">
                    <span class="category-tile__name"><?= e($category['category_name']) ?></span>
                    <?php if ($category['category_tagline']) : ?>
                        <span class="category-tile__tagline"><?= e($category['category_tagline']) ?></span>
                    <?php endif; ?>
                    <span class="category-tile__cta">Nunua sasa <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($has_subcategories) : ?>
        <div class="scroll-rail" data-scroll-rail>
            <button class="scroll-rail__button scroll-rail__button--prev" type="button" aria-label="Rudi nyuma" hidden data-rail-prev>
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
            </button>
            <ul class="subcategory-row" data-rail-track>
                <?php foreach ($home['top_categories'] as $category) : ?>
                    <?php foreach ($category['children'] as $chip) : ?>
                        <li><?php require __DIR__ . '/includes/subcategory_link.php'; ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
            <button class="scroll-rail__button scroll-rail__button--next" type="button" aria-label="Endelea mbele" hidden data-rail-next>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
    <?php endif; ?>
</section>

<?php if ($product_tabs !== []) : ?>
    <section class="container-xl home-section" aria-labelledby="home-products-title">
        <div class="section-heading section-heading--tabs">
            <h2 class="section-heading__title" id="home-products-title">Bidhaa za duka lako</h2>
            <div class="home-tabs" role="tablist" aria-label="Chagua kundi la bidhaa">
                <?php foreach ($product_tabs as $index => $tab) : ?>
                    <button class="chip<?= $index === 0 ? ' is-active' : '' ?>" type="button" role="tab"
                        id="home-tab-<?= e($tab['key']) ?>" aria-controls="home-products-panel"
                        aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                        data-home-tab="<?= e($tab['key']) ?>" data-see-all="<?= e(collectionUrl($tab['key'])) ?>">
                        <?= e($tab['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <a class="section-heading__link" href="<?= e(collectionUrl($product_tabs[0]['key'])) ?>" data-home-see-all>Tazama zote <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="product-grid product-grid--rail" id="home-products-panel" role="tabpanel"
            aria-labelledby="home-tab-<?= e($product_tabs[0]['key']) ?>" data-home-products></div>
    </section>
<?php endif; ?>

<section class="wholesale-band" aria-labelledby="wholesale-title">
    <div class="container-xl wholesale-band__inner">
        <div class="wholesale-band__copy">
            <p class="wholesale-band__eyebrow">Jinsi bei za jumla zinavyofanya kazi</p>
            <h2 class="wholesale-band__title" id="wholesale-title">Nunua zaidi, lipa kidogo</h2>
            <p class="wholesale-band__text">Kila bidhaa ina ngazi za bei: kadri unavyoagiza pcs nyingi, ndivyo bei ya kila moja inavyoshuka. Kikapu chako kinabadilisha bei chenyewe.</p>
            <ul class="wholesale-band__points">
                <li><i class="bi bi-check2-circle" aria-hidden="true"></i> Kiwango cha chini cha kuagiza (MOQ) kiko wazi kwenye kila bidhaa</li>
                <li><i class="bi bi-check2-circle" aria-hidden="true"></i> Lipa pesa taslimu mzigo ukifika dukani</li>
                <li><i class="bi bi-check2-circle" aria-hidden="true"></i> Agiza tena oda yako ya zamani kwa kubofya mara moja</li>
            </ul>
            <a class="btn btn-buy btn-lg" href="<?= e(collectionUrl('best_sellers')) ?>">Anza kununua</a>
        </div>

        <?php if ($has_tier_example) : ?>
            <div class="tier-card">
                <p class="tier-card__label">Mfano halisi</p>
                <a class="tier-card__product" href="<?= e(productUrl($example_product['product_id'], $example_product['product_slug'])) ?>"><?= e($example_product['product_name']) ?></a>
                <table class="tier-table">
                    <caption class="visually-hidden">Bei kwa kila pc kulingana na idadi</caption>
                    <thead>
                        <tr>
                            <th scope="col">Idadi</th>
                            <th scope="col">Bei kwa pc</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($example_tiers as $tier) : ?>
                            <tr class="<?= $tier['is_best'] ? 'is-best' : '' ?>">
                                <td><?= e($tier['label']) ?></td>
                                <td><?= e(formatTzs($tier['unit_price'])) ?><?php if ($tier['is_best']) : ?> <span class="tier-table__tag">Bei bora</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="tier-card__savings"><i class="bi bi-graph-down-arrow" aria-hidden="true"></i> Unaokoa hadi <strong><?= e($example_savings_percent) ?>%</strong> kwa kila pc</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<script type="application/json" id="home-products-data">
    <?= json_encode($home_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>