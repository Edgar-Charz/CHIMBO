<?php

/**
 * /sitemap.xml (rewritten here by .htaccess): every public page search engines should know — Home, Help, the legal
 * pages, each category on sale and each product on sale, with when it last changed. Built from the database on every
 * request, so it is never out of date.
 */

require __DIR__ . '/includes/init.php';

$database = Database::instance();

// The same rules as the shop: a product shows when it, its seller and its category are on
$products = $database->fetchAll(
    "SELECT p.product_id, p.product_slug, COALESCE(p.updated_at, p.created_at) AS changed_at
     FROM products p
     JOIN sellers s ON s.seller_id = p.seller_id AND s.seller_status = 'active'
     JOIN categories c ON c.category_id = p.category_id AND c.category_is_active = 1
     WHERE p.product_is_active = 1 AND p.deleted_at IS NULL
     ORDER BY p.product_id"
);
$categories = $database->fetchAll(
    'SELECT category_id, category_slug, COALESCE(updated_at, created_at) AS changed_at
     FROM categories WHERE category_is_active = 1 ORDER BY category_id'
);

$pages = [
    ['loc' => url(''), 'changed_at' => null],
    ['loc' => url('help.php'), 'changed_at' => null],
    ['loc' => url('terms.php'), 'changed_at' => null],
    ['loc' => url('privacy.php'), 'changed_at' => null],
];
foreach ($categories as $category) {
    $pages[] = ['loc' => categoryUrl((int) $category['category_id'], (string) $category['category_slug']), 'changed_at' => $category['changed_at']];
}
foreach ($products as $product) {
    $pages[] = ['loc' => productUrl((int) $product['product_id'], (string) $product['product_slug']), 'changed_at' => $product['changed_at']];
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($pages as $page) {
    echo '  <url><loc>', e($page['loc']), '</loc>';
    if ($page['changed_at'] !== null) {
        echo '<lastmod>', e(substr((string) $page['changed_at'], 0, 10)), '</lastmod>';
    }
    echo "</url>\n";
}
echo "</urlset>\n";
