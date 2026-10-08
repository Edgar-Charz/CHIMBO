<?php

/**
 * A top category (/c/{id}-{slug}): sub-category chips, then the shared product list (filters, sort, "Onyesha zaidi").
 * ?chip={id} opens it on one sub-category.
 */

require __DIR__ . '/includes/init.php';

$category_id = requestInt('id');
$category = array_column(shopCategories(), null, 'category_id')[$category_id] ?? null;
$chip = null;

if ($category === null) {
    http_response_code(404);
} else {
    $chip = array_column($category['children'], null, 'category_id')[requestInt('chip')] ?? null;
    $catalog = catalogState(
        ['category_id' => $chip['category_id'] ?? $category['category_id']],
        catalogFilters(),
        [
            'title'        => 'Hakuna bidhaa hapa bado',
            'text'         => 'Bidhaa mpya zinaongezwa kila wiki. Angalia aina nyingine kwa sasa.',
            'action_label' => 'Tazama zinazouzwa zaidi',
            'action_href'  => collectionUrl('best_sellers'),
        ]
    );
}

$page = [
    'title'       => match (true) {
        $category === null => 'Aina haipatikani',
        $chip === null     => $category['category_name'],
        default            => "{$chip['category_name']} — {$category['category_name']}",
    },
    'description' => $category['category_tagline'] ?? 'Bidhaa za jumla kwa duka lako: bei hushuka ukinunua zaidi, lipa ukipokea.',
    'image'       => $category['category_image_url'] ?? url('assets/img/logo-mark.webp'),
    'nav'         => 'explore',
    'scripts'     => $category === null ? [] : ['product_card.js', 'catalog.js'],
];

require __DIR__ . '/includes/header.php';
?>

<?php if ($category === null) : ?>
    <section class="container-xl">
        <div class="state-block">
            <span class="state-block__icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
            <h1 class="state-block__title">Aina hii ya bidhaa haipatikani</h1>
            <p class="state-block__text">Huenda imeondolewa au kiungo si sahihi.</p>
            <a class="btn btn-primary" href="<?= e(url('explore.php')) ?>">Angalia aina zote</a>
        </div>
    </section>
<?php else : ?>
    <section class="container-xl catalog-page">
        <nav aria-label="Ulipo">
            <ol class="breadcrumb page-breadcrumb">
                <li class="breadcrumb-item"><a href="<?= e(url('')) ?>">Nyumbani</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('explore.php')) ?>">Gundua</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($category['category_name']) ?></li>
            </ol>
        </nav>

        <header class="catalog-heading<?= $category['category_image_url'] ? ' catalog-heading--image' : '' ?>">
            <?php if ($category['category_image_url']) : ?>
                <img class="catalog-heading__image" src="<?= e($category['category_image_url']) ?>" alt="" fetchpriority="high">
            <?php endif; ?>
            <h1 class="catalog-heading__title"><?= e($category['category_name']) ?></h1>
            <?php if ($category['category_tagline']) : ?>
                <p class="catalog-heading__text"><?= e($category['category_tagline']) ?></p>
            <?php endif; ?>
        </header>

        <?php if ($category['children'] !== []) : ?>
            <nav class="catalog-chips" aria-label="Aina ndogo">
                <a class="chip<?= $chip === null ? ' is-active' : '' ?>" href="<?= e(categoryUrl($category['category_id'], $category['category_slug'])) ?>" <?= $chip === null ? 'aria-current="page"' : '' ?>>Zote</a>
                <?php foreach ($category['children'] as $child) : ?>
                    <?php $is_current_chip = $child['category_id'] === ($chip['category_id'] ?? null); ?>
                    <a class="chip<?= $is_current_chip ? ' is-active' : '' ?>" href="<?= e(categoryUrl($category['category_id'], $category['category_slug'], $child['category_id'])) ?>" <?= $is_current_chip ? 'aria-current="page"' : '' ?>><?= e($child['category_name']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php require __DIR__ . '/includes/catalog_browser.php'; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
