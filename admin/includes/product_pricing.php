<?php

/**
 * Product form — "Pricing" panel: MOQ, unit, price levels (tiers) and the old price for deals.
 * Included by product_edit.php inside its main form (uses $form and $errors).
 */

// Rows to show: the saved/typed levels (empty rows dropped) plus one empty row to add a level
$tier_rows = array_values(array_filter(
    (array) ($form['tiers'] ?? []),
    fn(mixed $row): bool => is_array($row) && trim(($row['tier_min_quantity'] ?? '') . ($row['tier_unit_price'] ?? '')) !== ''
));
$tier_rows[] = ['tier_min_quantity' => '', 'tier_unit_price' => ''];
?>
<div class="admin-panel mb-3">
    <div class="admin-panel-header">
        <h2 class="admin-panel-title">Pricing</h2>
    </div>
    <div class="admin-panel-body">
        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <label class="form-label" for="product_moq">Minimum order (MOQ)</label>
                <input class="form-control<?= adminInvalidClass($errors, 'product_moq') ?>" type="number" min="1" id="product_moq" name="product_moq"
                    value="<?= e($form['product_moq'] ?? '') ?>" required>
                <?= adminFieldError($errors, 'product_moq') ?>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="product_unit_label">Sold per</label>
                <select class="form-select<?= adminInvalidClass($errors, 'product_unit_label') ?>" id="product_unit_label" name="product_unit_label">
                    <?php foreach (ProductEditor::UNIT_LABELS as $unit_label): ?>
                        <option value="<?= e($unit_label) ?>" <?= adminSelected($form, 'product_unit_label', $unit_label) ?>><?= e($unit_label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= adminFieldError($errors, 'product_unit_label') ?>
            </div>
        </div>

        <div data-tier-editor data-next-index="<?= e(count($tier_rows)) ?>">
            <label class="form-label mb-1">Price levels <span class="text-muted">— "Nunua zaidi, lipa kidogo"</span></label>
            <p class="form-text mt-0">The first level starts at the MOQ. Each next level needs a bigger quantity and a lower price per unit.</p>

            <div class="tier-row tier-row-header">
                <span>From quantity</span><span>Price per unit (TZS)</span><span></span>
            </div>
            <div data-tier-rows>
                <?php foreach ($tier_rows as $tier_index => $tier_row): ?>
                    <?php require __DIR__ . '/product_tier_row.php'; ?>
                <?php endforeach; ?>
            </div>
            <template>
                <?php $tier_index = '__INDEX__';
                $tier_row = [];
                require __DIR__ . '/product_tier_row.php'; ?>
            </template>

            <?= adminFieldError($errors, 'tiers') ?>
            <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-add-tier><i class="bi bi-plus-lg"></i> Add level</button>
        </div>

        <hr class="my-4">

        <label class="form-label" for="product_compare_at_price">Old price for a deal ("Ofa") <span class="text-muted">(optional)</span></label>
        <div class="input-group admin-input-medium">
            <span class="input-group-text">TZS</span>
            <input class="form-control<?= adminInvalidClass($errors, 'product_compare_at_price') ?>" type="number" min="1"
                id="product_compare_at_price" name="product_compare_at_price" value="<?= e($form['product_compare_at_price'] ?? '') ?>">
        </div>
        <div class="form-text">Shown struck through next to the normal price. Must be higher than the first level's price.</div>
        <?= adminFieldError($errors, 'product_compare_at_price') ?>
    </div>
</div>