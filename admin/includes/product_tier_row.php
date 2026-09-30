<?php

/**
 * One price level row of the tier editor.
 * Set before including: $tier_index (a number, or '__INDEX__' in the <template> that admin.js copies)
 * and $tier_row (['tier_min_quantity' => …, 'tier_unit_price' => …], or [] for an empty row).
 */
?>
<div class="tier-row" data-tier-row>
       <input class="form-control" type="number" min="1" name="tiers[<?= e($tier_index) ?>][tier_min_quantity]"
              value="<?= e($tier_row['tier_min_quantity'] ?? '') ?>" aria-label="From quantity" placeholder="e.g. 6">
       <input class="form-control" type="number" min="1" name="tiers[<?= e($tier_index) ?>][tier_unit_price]"
              value="<?= e($tier_row['tier_unit_price'] ?? '') ?>" aria-label="Price per unit" placeholder="e.g. 5000">
       <button class="btn btn-link text-muted" type="button" data-remove-tier aria-label="Remove this level"><i class="bi bi-x-lg"></i></button>
</div>