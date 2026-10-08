<?php

/**
 * Search results (?q=…) and collections (?collection=deals|new|best_sellers), with the shared product list.
 * Without either it lists every product.
 */

require __DIR__ . '/includes/init.php';

const COLLECTION_PAGES = [
    'offers'       => ['title' => 'Ofa za muda', 'text' => 'Bei maalum zinazoisha hivi karibuni — wahi kabla hazijaisha.'],
    'deals'        => ['title' => 'Ofa', 'text' => 'Bidhaa zenye bei maalum kwa muda mfupi.'],
    'new'          => ['title' => 'Bidhaa mpya', 'text' => 'Zimewasili hivi karibuni CHIMBO.'],
    'best_sellers' => ['title' => 'Zinazouzwa zaidi', 'text' => 'Bidhaa wanazopenda wenye maduka wengine.'],
];

$search_text = requestText('q');
$collection = array_key_exists(requestText('collection'), COLLECTION_PAGES) ? requestText('collection') : null;

if ($search_text !== '') {
    $heading = ['title' => "Matokeo ya \"{$search_text}\"", 'text' => null];
    $base_query = ['q' => $search_text];
    $empty_state = [
        'title'        => 'Hatukupata bidhaa hiyo',
        'text'         => 'Jaribu jina fupi zaidi, jina la chapa, au angalia aina za bidhaa.',
        'action_label' => 'Angalia aina zote',
        'action_href'  => url('explore.php'),
    ];
} else {
    $heading = COLLECTION_PAGES[$collection] ?? ['title' => 'Bidhaa zote', 'text' => 'Bidhaa zote za jumla za CHIMBO.'];
    $base_query = $collection === null ? [] : ['collection' => $collection];
    $empty_state = [
        'title'        => 'Hakuna bidhaa hapa kwa sasa',
        'text'         => 'Rudi baadaye — bidhaa mpya zinaongezwa kila wiki.',
        'action_label' => 'Tazama bidhaa zote',
        'action_href'  => url('search.php'),
    ];
}

$catalog = catalogState($base_query, catalogFilters($collection === 'new' ? 'newest' : 'popular'), $empty_state);
$collection_images = [];
if ($collection !== null && $search_text === '') {
    $collection_images = array_slice(array_values(array_unique(array_filter(array_column(
        $catalog['products'],
        'product_image_url'
    ), static fn ($image_url): bool => is_string($image_url) && $image_url !== ''))), 0, 4);
}

$page = [
    'title'        => $heading['title'],
    'description'  => $heading['text'] ?? 'Tafuta bidhaa za jumla kwa duka lako kwenye CHIMBO.',
    'nav'          => 'explore',
    'scripts'      => ['product_card.js', 'catalog.js'],
    'search_query' => $search_text,
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl catalog-page">
    <nav aria-label="Ulipo">
        <ol class="breadcrumb page-breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('')) ?>">Nyumbani</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($search_text !== '' ? 'Utafutaji' : $heading['title']) ?></li>
        </ol>
    </nav>

    <header class="catalog-heading<?= $collection !== null && $search_text === '' ? ' catalog-heading--collection' : '' ?>">
        <?php if ($collection_images !== []) : ?>
            <div class="catalog-heading__photos catalog-heading__photos--<?= count($collection_images) ?>" aria-hidden="true">
                <?php foreach ($collection_images as $image_url) : ?>
                    <img src="<?= e($image_url) ?>" alt="">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h1 class="catalog-heading__title"><?= e($heading['title']) ?></h1>
        <?php if ($heading['text'] !== null) : ?>
            <p class="catalog-heading__text"><?= e($heading['text']) ?></p>
        <?php endif; ?>
    </header>

    <?php if ($search_text === '') : ?>
        <nav class="catalog-chips" aria-label="Makundi ya bidhaa">
            <a class="chip<?= $collection === null ? ' is-active' : '' ?>" href="<?= e(url('search.php')) ?>">Zote</a>
            <?php foreach (COLLECTION_PAGES as $collection_key => $collection_page) : ?>
                <a class="chip<?= $collection === $collection_key ? ' is-active' : '' ?>" href="<?= e(collectionUrl($collection_key)) ?>" <?= $collection === $collection_key ? 'aria-current="page"' : '' ?>><?= e($collection_page['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/catalog_browser.php'; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
