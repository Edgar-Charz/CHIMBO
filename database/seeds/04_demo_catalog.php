<?php

/**
 * Demo catalog from the CHIMBO design document: 2 sellers, 2 top categories with their chips,
 * and 12 products with MOQs and tier prices. Tier prices other than Vaseline's are examples.
 *
 * Runs only when the catalog is empty, and never in production (real products come from the admin).
 */

$categories = [
    // Top category => [tagline, chips]
    'Cosmetics' => ['Shamba la Vipodozi', ['Skin Care', 'Hair Care', 'Body Care', 'Baby Care', 'Women Care', 'Sanitary', 'Makeup']],
    'Jewelry'   => ['Mrembo Muuza Urembo', ['Earrings', 'Necklaces', 'Bracelets', 'Rings', 'Anklets', 'Watches', 'Hair Accessories', 'Bags']],
];

$sellers = ['Shamba la Vipodozi', 'Mrembo Muuza Urembo'];

// [name, brand, seller, chip, unit, moq, stock, tiers (min quantity => price), extra flags]
$products = [
    ['Vaseline Petroleum Jelly 400ml', 'Vaseline', 'Shamba la Vipodozi', 'Skin Care', 'pc', 1, 500, [1 => 5500, 6 => 5000, 24 => 4700, 60 => 4400], ['bestseller' => true, 'sold' => 820]],
    ['Hair Food 500ml (Dark & Lovely)', 'Dark & Lovely', 'Shamba la Vipodozi', 'Hair Care', 'pc', 1, 300, [1 => 6000, 6 => 5600, 24 => 5300], ['sold' => 410]],
    ['Nivea Body Lotion 400ml', 'Nivea', 'Shamba la Vipodozi', 'Body Care', 'pc', 1, 250, [1 => 7000, 6 => 6600, 24 => 6200], ['new' => true, 'sold' => 95]],
    ['Dove Beauty Soap 100g (Pack of 3)', 'Dove', 'Shamba la Vipodozi', 'Body Care', 'pack', 3, 400, [3 => 4200, 12 => 3950, 36 => 3700], ['compare_at' => 4800, 'sold' => 560]],
    ['Nourish Body Oil 100ml', 'Nourish', 'Shamba la Vipodozi', 'Body Care', 'pc', 2, 180, [2 => 4800, 12 => 4500], ['sold' => 130]],
    ['Dettol Wet Wipes 50s (Pack)', 'Dettol', 'Shamba la Vipodozi', 'Sanitary', 'pack', 4, 350, [4 => 3200, 24 => 3000], ['sold' => 240]],
    ['Seti ya Necklace na Earrings', null, 'Mrembo Muuza Urembo', 'Necklaces', 'set', 6, 150, [6 => 8500, 24 => 8000, 60 => 7500], ['bestseller' => true, 'sold' => 610]],
    ['Hoop Earrings Set', null, 'Mrembo Muuza Urembo', 'Earrings', 'set', 12, 600, [12 => 1200, 48 => 1100], ['sold' => 700]],
    ['Gold Plated Bangles', null, 'Mrembo Muuza Urembo', 'Bracelets', 'set', 6, 200, [6 => 9200, 24 => 8800], ['compare_at' => 10000, 'sold' => 180]],
    ['Stainless Steel Rings', null, 'Mrembo Muuza Urembo', 'Rings', 'pc', 12, 480, [12 => 2500, 48 => 2300], ['sold' => 330]],
    ['Anklet Set', null, 'Mrembo Muuza Urembo', 'Anklets', 'set', 6, 220, [6 => 3800, 24 => 3500], ['new' => true, 'sold' => 60]],
    ['Classic Watch', null, 'Mrembo Muuza Urembo', 'Watches', 'pc', 3, 90, [3 => 18000, 12 => 17000], ['sold' => 75]],
];

return function (Database $db) use ($categories, $sellers, $products): void {
    $catalog_has_products = (bool) $db->fetchValue('SELECT 1 FROM products LIMIT 1');
    if ($catalog_has_products || Env::get('APP_ENV', 'production') === 'production') {
        return;
    }

    $db->transaction(function (Database $db) use ($categories, $sellers, $products) {
        // Sellers
        $seller_ids = [];
        foreach ($sellers as $seller_name) {
            $seller_ids[$seller_name] = $db->insert(
                'INSERT INTO sellers (seller_name, seller_slug, seller_is_verified) VALUES (:seller_name, :seller_slug, 1)',
                ['seller_name' => $seller_name, 'seller_slug' => slugify($seller_name)]
            );
        }

        // Top categories and their chips
        $chip_ids   = [];
        $sort_order = 0;
        foreach ($categories as $category_name => [$tagline, $chip_names]) {
            $parent_id = $db->insert(
                'INSERT INTO categories (category_name, category_slug, category_tagline, category_sort_order)
                 VALUES (:category_name, :category_slug, :category_tagline, :category_sort_order)',
                [
                    'category_name'       => $category_name,
                    'category_slug'       => slugify($category_name),
                    'category_tagline'    => $tagline,
                    'category_sort_order' => ++$sort_order,
                ]
            );
            foreach ($chip_names as $chip_order => $chip_name) {
                $chip_ids[$chip_name] = $db->insert(
                    'INSERT INTO categories (parent_category_id, category_name, category_slug, category_sort_order)
                     VALUES (:parent_category_id, :category_name, :category_slug, :category_sort_order)',
                    [
                        'parent_category_id'  => $parent_id,
                        'category_name'       => $chip_name,
                        'category_slug'       => slugify($category_name . ' ' . $chip_name),
                        'category_sort_order' => $chip_order + 1,
                    ]
                );
            }
        }

        // Products with their tier prices and opening stock
        $product_editor = new ProductEditor($db);
        foreach ($products as $number => [$name, $brand, $seller, $chip, $unit, $moq, $stock, $tiers, $flags]) {
            $product_id = $db->insert(
                'INSERT INTO products (
                    seller_id, category_id, product_name, product_slug, product_brand, product_sku, product_unit_label,
                    product_moq, product_stock_quantity, product_compare_at_price, product_is_bestseller,
                    product_new_until, product_sold_count
                 ) VALUES (
                    :seller_id, :category_id, :product_name, :product_slug, :product_brand, :product_sku, :product_unit_label,
                    :product_moq, :product_stock_quantity, :product_compare_at_price, :product_is_bestseller,
                    IF(:is_new, UTC_DATE() + INTERVAL 60 DAY, NULL), :product_sold_count
                 )',
                [
                    'seller_id'                => $seller_ids[$seller],
                    'category_id'              => $chip_ids[$chip],
                    'product_name'             => $name,
                    'product_slug'             => slugify($name),
                    'product_brand'            => $brand,
                    'product_sku'              => sprintf('CHB-DEMO-%03d', $number + 1),
                    'product_unit_label'       => $unit,
                    'product_moq'              => $moq,
                    'product_stock_quantity'   => $stock,
                    'product_compare_at_price' => $flags['compare_at'] ?? null,
                    'product_is_bestseller'    => (int) ($flags['bestseller'] ?? false),
                    'is_new'                   => (int) ($flags['new'] ?? false),
                    'product_sold_count'       => $flags['sold'] ?? 0,
                ]
            );

            foreach ($tiers as $min_quantity => $unit_price) {
                $db->insert(
                    'INSERT INTO product_price_tiers (product_id, tier_min_quantity, tier_unit_price)
                     VALUES (:product_id, :tier_min_quantity, :tier_unit_price)',
                    ['product_id' => $product_id, 'tier_min_quantity' => $min_quantity, 'tier_unit_price' => $unit_price]
                );
            }
            $product_editor->refreshStoredPrices($product_id);

            $db->insert(
                "INSERT INTO inventory_movements (product_id, movement_quantity_change, movement_reason, movement_note)
                 VALUES (:product_id, :quantity, 'restock', 'Demo opening stock')",
                ['product_id' => $product_id, 'quantity' => $stock]
            );
        }

        // Home banner from the design ("BEI ZA JUMLA")
        $db->insert(
            "INSERT INTO banners (banner_title, banner_subtitle, banner_button_label, banner_target_type, banner_target_value)
             VALUES ('BEI ZA JUMLA', 'Nunua zaidi, okoa zaidi.', 'Nunua Sasa', 'collection', 'deals')"
        );
    });
};
