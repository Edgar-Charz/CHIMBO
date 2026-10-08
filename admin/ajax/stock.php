<?php

/** Rows for the Stock table (server-side DataTables, see admin/stock.php). */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

$current_admin     = AdminSession::requireAjaxLogin('inventory.manage');
$can_edit_products = Admin::can($current_admin, 'products.manage');

try {
    $pagination = (new ProductEditor(Database::instance()))->getProductsForAdmin(adminDataTablesInput($_GET, 'q'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

adminDataTablesJson($pagination, fn (array $product): array => [
    'product' => '<div class="d-flex align-items-center gap-2">'
        . adminThumbnail($product['product_image_thumb_path'], 'bi-box-seam')
        . '<a class="stretched-link fw-semibold" href="' . e(url('admin/product_stock.php?id=' . $product['product_id'])) . '">'
        . e($product['product_name']) . '</a></div>',
    'sku'      => '<span class="text-muted">' . e($product['product_sku']) . '</span>',
    'moq'      => e($product['product_moq']),
    'in_stock' => adminStockBadge((int) $product['product_stock_quantity'])
        . ' <span class="text-muted">' . e($product['product_unit_label']) . '</span>',
    'actions'  => adminRowActions(
        adminActionLink('bi-plus-slash-minus', 'Adjust stock', url('admin/product_stock.php?id=' . $product['product_id'])),
        $can_edit_products ? adminActionLink('bi-pencil', 'Edit product', url('admin/product_edit.php?id=' . $product['product_id'])) : '',
    ),
]);
