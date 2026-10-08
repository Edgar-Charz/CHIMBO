<?php

/**
 * One product row of the "Add order" form (order_create.php).
 * Set before including: $item_index (a number, or '__INDEX__' in the <template> that order_create.js copies),
 * $item (['product_id' => …, 'quantity' => …], or [] for an empty row), $item_errors (field errors, or []),
 * $order_products (the products from OrderManager::getManualOrderOptions()).
 * Stock, MOQ and unit price are filled in by order_create.js from the chosen product's data-* values.
 */

$product_field  = "items.{$item_index}.product_id";
$quantity_field = "items.{$item_index}.quantity";
?>
<div class="row g-2 align-items-end order-item-row" data-order-item>
    <div class="col-12 col-md-5">
        <label class="form-label d-md-none">Product</label>
        <select class="form-select<?= adminInvalidClass($item_errors, $product_field) ?>" name="items[<?= e($item_index) ?>][product_id]" required>
            <option value="">Choose product</option>
            <?php foreach ($order_products as $product): ?>
                <option value="<?= e($product['product_id']) ?>"
                        data-name="<?= e($product['product_name']) ?>"
                        data-moq="<?= e($product['product_moq']) ?>"
                        data-stock="<?= e($product['product_stock_quantity']) ?>"
                        data-tiers="<?= e(json_encode($product['price_tiers'])) ?>"
                        <?= adminSelected($item, 'product_id', $product['product_id']) ?>>
                    <?= e($product['product_name']) ?> · <?= e($product['product_sku']) ?><?= !empty($product['product_offer_percent']) ? ' · −' . e($product['product_offer_percent']) . '% offer' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= adminFieldError($item_errors, $product_field) ?>
    </div>
    <div class="col-4 col-md-1">
        <label class="form-label d-md-none">Stock</label>
        <input class="form-control" type="text" value="—" readonly tabindex="-1" data-product-stock aria-label="Stock">
    </div>
    <div class="col-4 col-md-1">
        <label class="form-label d-md-none">MOQ</label>
        <input class="form-control" type="text" value="—" readonly tabindex="-1" data-product-moq aria-label="Minimum order quantity">
    </div>
    <div class="col-4 col-md-2">
        <label class="form-label d-md-none">Unit price</label>
        <input class="form-control text-nowrap" type="text" value="—" readonly tabindex="-1" data-product-price aria-label="Unit price">
    </div>
    <div class="col-8 col-md-2">
        <label class="form-label d-md-none">Quantity</label>
        <input class="form-control<?= adminInvalidClass($item_errors, $quantity_field) ?>" type="number" min="1" max="100000"
               name="items[<?= e($item_index) ?>][quantity]" value="<?= e($item['quantity'] ?? '') ?>" placeholder="Quantity" required>
        <?= adminFieldError($item_errors, $quantity_field) ?>
    </div>
    <div class="col-4 col-md-1">
        <label class="form-label d-md-none">Remove</label>
        <button class="btn btn-outline-danger w-100" type="button" data-remove-order-item aria-label="Remove product"><i class="bi bi-trash"></i></button>
    </div>
</div>
