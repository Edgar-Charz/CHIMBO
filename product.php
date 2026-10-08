<?php

/**
 * Product page (/p/{id}-{slug}): gallery, wholesale tier table, quantity with a live price preview,
 * add to cart (with a sticky bar on phones), wishlist, share and product suggestions.
 * Prices shown here are a preview from the tiers; the cart always gets its prices from the server.
 */

require __DIR__ . '/includes/init.php';

$product_id = requestInt('id');
$product = null;

try {
    $product_model = new Product(Database::instance());
    $product = $product_id === null ? null : $product_model->getProductById($product_id);
} catch (ApiException) {
    $product = null; // hidden or deleted products are a normal "not found"
}

if ($product === null) {
    http_response_code(404);
} else {
    $tier_rows = tierRows($product['tiers']);
    $moq_tier = Pricing::tierForQuantity($product['tiers'], $product['product_moq']);
    $moq_total = $moq_tier['tier_unit_price'] * $product['product_moq'];
    $badge_text = productBadgeText($product);
    $parent_category = null;
    foreach (shopCategories() as $top_category) {
        if (in_array($product['category_id'], array_column($top_category['children'], 'category_id'), true)) {
            $parent_category = $top_category;
        }
    }
    $suggested_products = productSuggestions($product, $product_model->getRelatedProducts($product_id), $parent_category);
}

$page = [
    'title'       => $product['product_name'] ?? 'Bidhaa haipatikani',
    'description' => ($product['product_description'] ?? null) ?: 'Bei za jumla, MOQ na upatikanaji wa bidhaa hii kwenye CHIMBO. Lipa ukipokea.',
    'image'       => ($product['product_image_url'] ?? null) ?: url('assets/img/logo-mark.webp'),
    'nav'         => 'explore',
    'scripts'     => $product === null ? [] : ['product_card.js', 'product.js'],
];

require __DIR__ . '/includes/header.php';
?>

<?php if ($product === null) : ?>
    <section class="container-xl">
        <div class="state-block">
            <span class="state-block__icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
            <h1 class="state-block__title">Bidhaa haipatikani</h1>
            <p class="state-block__text">Huenda imeondolewa dukani au kiungo si sahihi.</p>
            <a class="btn btn-primary" href="<?= e(url('search.php')) ?>">Tazama bidhaa zote</a>
        </div>
    </section>
<?php else : ?>
    <section class="container-xl product-page" data-product-page>
        <nav aria-label="Ulipo">
            <ol class="breadcrumb page-breadcrumb">
                <li class="breadcrumb-item"><a href="<?= e(url('')) ?>">Nyumbani</a></li>
                <?php if ($parent_category !== null) : ?>
                    <li class="breadcrumb-item"><a href="<?= e(categoryUrl($parent_category['category_id'], $parent_category['category_slug'])) ?>"><?= e($parent_category['category_name']) ?></a></li>
                    <li class="breadcrumb-item"><a href="<?= e(categoryUrl($parent_category['category_id'], $parent_category['category_slug'], $product['category_id'])) ?>"><?= e($product['category_name']) ?></a></li>
                <?php endif; ?>
                <li class="breadcrumb-item active" aria-current="page"><?= e($product['product_name']) ?></li>
            </ol>
        </nav>

        <div class="product-layout">
            <div class="product-gallery<?= count($product['gallery']) > 1 ? ' product-gallery--thumbs' : '' ?>" data-gallery>
                <div class="product-gallery__main" data-gallery-main>
                    <?php if ($product['gallery'] !== []) : ?>
                        <img class="product-gallery__image" src="<?= e($product['gallery'][0]['product_image_large_url']) ?>" alt="<?= e($product['product_name']) ?>" fetchpriority="high" decoding="async">
                    <?php else : ?>
                        <div class="product-gallery__image image-placeholder" role="img" aria-label="Picha ya bidhaa bado haijawekwa"><i class="bi bi-image" aria-hidden="true"></i></div>
                    <?php endif; ?>
                    <?php if ($badge_text !== null) : ?>
                        <span class="product-card__badge product-card__badge--<?= e($product['product_badge']) ?>"><?= e($badge_text) ?></span>
                    <?php endif; ?>
                </div>

                <?php if (count($product['gallery']) > 1) : ?>
                    <div class="product-gallery__thumbs" role="group" aria-label="Picha za bidhaa">
                        <?php foreach ($product['gallery'] as $index => $image) : ?>
                            <button class="product-gallery__thumb<?= $index === 0 ? ' is-active' : '' ?>" type="button"
                                aria-label="Picha ya <?= $index + 1 ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                                data-gallery-thumb data-large-url="<?= e($image['product_image_large_url']) ?>">
                                <img src="<?= e($image['product_image_thumb_url']) ?>" alt="" loading="lazy" decoding="async">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="product-info">
                <div class="product-info__top">
                    <p class="product-info__seller">
                        <?= e($product['seller_name']) ?>
                        <?php if ($product['seller_is_verified']) : ?>
                            <i class="bi bi-patch-check-fill" aria-hidden="true"></i><span class="visually-hidden">(muuzaji amethibitishwa)</span>
                        <?php endif; ?>
                    </p>
                    <div class="product-info__tools">
                        <button class="icon-button" type="button" aria-label="Hifadhi kwenye vipendwa" aria-pressed="false" data-product-wishlist><i class="bi bi-heart" aria-hidden="true"></i></button>
                        <button class="icon-button" type="button" aria-label="Shiriki bidhaa hii" data-product-share><i class="bi bi-share" aria-hidden="true"></i></button>
                    </div>
                </div>

                <h1 class="product-info__name"><?= e($product['product_name']) ?></h1>
                <?php if ($product['product_brand']) : ?>
                    <p class="product-info__brand">Chapa: <?= e($product['product_brand']) ?></p>
                <?php endif; ?>

                <div class="product-price">
                    <p class="product-price__now"><span data-unit-price><?= e(formatTzs($moq_tier['tier_unit_price'])) ?></span> <small>kwa pc</small></p>
                    <?php if ($product['product_compare_at_price'] > $product['product_price']) : ?>
                        <p class="product-price__was">Bei ya awali <del><?= e(formatTzs($product['product_compare_at_price'])) ?></del></p>
                    <?php endif; ?>
                </div>

                <section class="tier-panel" aria-labelledby="tier-heading">
                    <h2 class="tier-panel__title" id="tier-heading"><i class="bi bi-tags" aria-hidden="true"></i> Nunua zaidi, lipa kidogo</h2>
                    <table class="tier-table">
                        <thead>
                            <tr><th scope="col">Idadi</th><th scope="col">Bei kwa pc</th><th scope="col">Faida</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tier_rows as $tier) : ?>
                                <tr data-tier-min="<?= e($tier['min_quantity']) ?>">
                                    <td><?= e($tier['label']) ?></td>
                                    <td><?= e(formatTzs($tier['unit_price'])) ?></td>
                                    <td class="tier-table__saving"><?= $tier['savings_percent'] === 0 ? 'Bei ya kawaida' : e("Unaokoa {$tier['savings_percent']}%") ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <div class="product-buy" data-buy-box>
                    <p class="product-buy__stock<?= $product['product_in_stock'] ? '' : ' is-sold-out' ?>">
                        <i class="bi bi-<?= $product['product_in_stock'] ? 'check-circle-fill' : 'x-circle-fill' ?>" aria-hidden="true"></i>
                        <?= $product['product_in_stock'] ? 'Inapatikana' : 'Imeisha kwa sasa' ?>
                        <span class="product-buy__moq">· MOQ <?= e(formatPieces($product['product_moq'])) ?></span>
                    </p>

                    <div class="product-buy__row">
                        <div data-product-quantity></div>
                        <div class="product-buy__total">
                            <span>Jumla</span>
                            <strong data-line-total><?= e(formatTzs($moq_total)) ?></strong>
                        </div>
                    </div>
                    <p class="product-buy__hint" hidden data-next-tier-hint></p>
                    <p class="product-buy__savings" hidden data-savings></p>

                    <button class="btn btn-buy btn-lg w-100" type="button" data-add-to-cart <?= $product['product_in_stock'] ? '' : 'disabled' ?>>
                        <i class="bi bi-bag-plus" aria-hidden="true"></i> Ongeza kikapuni
                    </button>
                    <p class="product-buy__in-cart" hidden data-in-cart-note></p>

                    <ul class="product-promises">
                        <li><i class="bi bi-truck" aria-hidden="true"></i> Inafika ndani ya siku <?= e($product['product_delivery_days_min']) ?>–<?= e($product['product_delivery_days_max']) ?></li>
                        <li><i class="bi bi-cash-coin" aria-hidden="true"></i> Lipa pesa taslimu ukipokea</li>
                        <?php if ($product['seller_is_verified']) : ?>
                            <li><i class="bi bi-patch-check" aria-hidden="true"></i> Muuzaji amethibitishwa na CHIMBO</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <?php if ($product['product_description']) : ?>
                    <section class="product-description" aria-labelledby="description-heading">
                        <h2 class="product-description__title" id="description-heading">Kuhusu bidhaa hii</h2>
                        <p><?= nl2br(e($product['product_description'])) ?></p>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($suggested_products !== []) : ?>
        <section class="container-xl home-section" aria-labelledby="suggestions-heading">
            <div class="section-heading">
                <h2 class="section-heading__title" id="suggestions-heading">Unaweza pia kupenda</h2>
            </div>
            <div class="product-grid product-grid--rail" data-suggested-products></div>
        </section>
    <?php endif; ?>

    <div class="buy-bar" aria-hidden="true" data-buy-bar>
        <div class="buy-bar__summary">
            <span data-buy-bar-quantity><?= e(formatPieces($product['product_moq'])) ?></span>
            <strong data-buy-bar-total><?= e(formatTzs($moq_total)) ?></strong>
        </div>
        <button class="btn btn-buy" type="button" tabindex="-1" data-add-to-cart <?= $product['product_in_stock'] ? '' : 'disabled' ?>>Ongeza kikapuni</button>
    </div>

    <script type="application/json" id="product-data"><?= json_encode(['product' => $product, 'suggested_products' => $suggested_products], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
