<?php

/**
 * One round sub-category link (photo, or the first letter while there is none).
 * Expects $category (the top category) and $chip (the sub-category).
 */
?>
<a class="subcategory-link" href="<?= e(categoryUrl($category['category_id'], $category['category_slug'], $chip['category_id'])) ?>">
    <span class="subcategory-link__circle">
        <?php if ($chip['category_image_url'] !== null) : ?>
            <img src="<?= e($chip['category_image_url']) ?>" alt="" loading="lazy" decoding="async">
        <?php else : ?>
            <?= e(mb_strtoupper(mb_substr($chip['category_name'], 0, 1))) ?>
        <?php endif; ?>
    </span>
    <span class="subcategory-link__name"><?= e($chip['category_name']) ?></span>
</a>
