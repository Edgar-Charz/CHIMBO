<?php

/** Rows for the Products table (server-side DataTables, see admin/products.php). */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

$current_admin    = AdminSession::requireAjaxLogin('products.manage');
$can_adjust_stock = Admin::can($current_admin, 'inventory.manage');
$list_page        = url('admin/products.php');   // where the row action forms are handled

try {
    $pagination = (new ProductEditor(Database::instance()))->getProductsForAdmin(adminDataTablesInput($_GET, 'q'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

adminDataTablesJson($pagination, function (array $product) use ($can_adjust_stock, $list_page): array {
    $product_id = (int) $product['product_id'];
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
        'actions'  => adminRowActions(
            adminActionLink('bi-pencil', 'Edit', url("admin/product_edit.php?id={$product_id}")),
            $can_adjust_stock ? adminActionLink('bi-boxes', 'Adjust stock', url("admin/product_stock.php?id={$product_id}")) : '',
            $product['product_is_active']
                ? adminActionButton('bi-eye-slash', 'Hide from the shop', 'toggle_active', $product_id, null, $list_page)
                : adminActionButton('bi-eye', 'Show in the shop', 'toggle_active', $product_id, null, $list_page),
            adminActionButton('bi-trash', 'Delete', 'delete', $product_id, 'Delete ' . $product['product_name'] . '? It disappears from the shop and this list.', $list_page, true),
        ),
    ];
});
