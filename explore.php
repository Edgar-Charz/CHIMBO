<?php

/**
 * Gundua — every category and sub-category, plus the collections (the "Gundua" tab on phones).
 */

require __DIR__ . '/includes/init.php';

$collection_shortcuts = [
    ['collection' => 'deals',        'title' => 'Ofa',              'icon' => 'lightning-charge-fill', 'modifier' => 'deal'],
    ['collection' => 'new',          'title' => 'Bidhaa mpya',      'icon' => 'stars',                 'modifier' => 'new'],
    ['collection' => 'best_sellers', 'title' => 'Zinazouzwa zaidi', 'icon' => 'graph-up-arrow',        'modifier' => 'best'],
];

$page = [
    'title'       => 'Gundua',
    'description' => 'Aina zote za bidhaa za jumla kwenye CHIMBO: vipodozi, urembo na zaidi.',
    'nav'         => 'explore',
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl explore-page">
    <h1 class="explore-page__title">Gundua</h1>

    <div class="explore-shortcuts">
        <?php foreach ($collection_shortcuts as $shortcut) : ?>
            <a class="promo-tile promo-tile--<?= e($shortcut['modifier']) ?>" href="<?= e(collectionUrl($shortcut['collection'])) ?>">
                <span class="promo-tile__icon"><i class="bi bi-<?= e($shortcut['icon']) ?>" aria-hidden="true"></i></span>
                <span class="promo-tile__title"><?= e($shortcut['title']) ?></span>
                <i class="bi bi-arrow-right promo-tile__arrow" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>

    <?php foreach (shopCategories() as $category) : ?>
        <section class="explore-category" aria-labelledby="explore-category-<?= e($category['category_id']) ?>">
            <a class="explore-category__header" href="<?= e(categoryUrl($category['category_id'], $category['category_slug'])) ?>">
                <span class="explore-category__image">
                    <?php if ($category['category_image_url'] !== null) : ?>
                        <img src="<?= e($category['category_image_url']) ?>" alt="" loading="lazy" decoding="async">
                    <?php else : ?>
                        <i class="bi bi-grid" aria-hidden="true"></i>
                    <?php endif; ?>
                </span>
                <span class="explore-category__copy">
                    <h2 class="explore-category__name" id="explore-category-<?= e($category['category_id']) ?>"><?= e($category['category_name']) ?></h2>
                    <?php if ($category['category_tagline']) : ?>
                        <span class="explore-category__tagline"><?= e($category['category_tagline']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="explore-category__all">Zote <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
            </a>

            <?php if ($category['children'] !== []) : ?>
                <ul class="explore-category__chips">
                    <?php foreach ($category['children'] as $chip) : ?>
                        <li>
                            <?php require __DIR__ . '/includes/subcategory_link.php'; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
