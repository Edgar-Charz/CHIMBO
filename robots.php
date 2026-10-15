<?php

/**
 * /robots.txt (rewritten here by .htaccess): search engines may read the shop, not the admin, the API or a
 * customer's own pages (cart, checkout, account, orders); and where the sitemap is. Only takes effect when the shop
 * is at the root of its domain (robots.txt is always read from the domain root).
 */

require __DIR__ . '/includes/init.php';

$base_path = rtrim((string) parse_url(url(''), PHP_URL_PATH), '/');   // '' at the domain root, '/chimbo' locally

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
foreach (['admin/', 'api/', 'cart.php', 'checkout.php', 'account.php', 'addresses.php', 'orders.php', 'order.php',
          'order_success.php', 'payments.php', 'notifications.php', 'wishlist.php', 'login.php'] as $private_path) {
    echo "Disallow: {$base_path}/{$private_path}\n";
}
echo "\nSitemap: ", url('sitemap.xml'), "\n";
