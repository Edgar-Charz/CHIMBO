<?php

/** Rows for the Products table (server-side DataTables, see admin/products.php). */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('products.manage');

try {
    $pagination = (new ProductEditor(Database::instance()))->getProductsForAdmin(adminDataTablesInput($_GET, 'q'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

adminDataTablesJson($pagination, function (array $product): array {
    $price_from = $product['product_price_from'] !== $product['product_price']
        ? '<div class="small text-muted">from ' . e(adminMoney($product['product_price_from'])) . '</div>'
        : '';

    return [
        'product' => '<div class="d-flex align-items-center gap-2">'
            . adminThumbnail($product['product_image_thumb_path'], 'bi-box-seam')
            . '<div><a class="stretched-link fw-semibold" href="' . e(url('admin/product_edit.php?id=' . $product['product_id'])) . '">'
            . e($product['product_name']) . '</a>'
            . '<div class="small text-muted">' . e($product['product_sku']) . ($product['product_brand'] ? ' · ' . e($product['product_brand']) : '') . '</div></div></div>',
        'category' => '<span class="text-muted">' . e($product['parent_category_name']) . ' ›</span> ' . e($product['category_name']),
        'seller'   => e($product['seller_name']),
        'price'    => e(adminMoney($product['product_price'])) . $price_from,
        'moq'      => e($product['product_moq'] . ' ' . $product['product_unit_label']),
        'stock'    => adminStockBadge((int) $product['product_stock_quantity']),
        'status'   => adminStatusBadge($product['product_is_active'] ? 'active' : 'hidden')
            . ($product['product_is_bestseller'] ? ' <i class="bi bi-star-fill product-flag" title="Bestseller" aria-label="Bestseller"></i>' : '')
            . ($product['product_compare_at_price'] ? ' <i class="bi bi-tag-fill product-flag" title="On deal" aria-label="On deal"></i>' : ''),
        'added'    => e(adminDate($product['created_at'])),
    ];
});
