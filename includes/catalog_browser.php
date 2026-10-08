<?php

/**
 * The product list shared by the category and search pages: count, "Chuja" filters, sort, grid and
 * "Onyesha zaidi". Expects $catalog from catalogState(); assets/js/catalog.js draws and updates the grid.
 */

$filters = $catalog['filters'];
?>
<div class="catalog" data-catalog>
    <div class="catalog-toolbar">
        <p class="catalog-toolbar__count" aria-live="polite"><strong data-catalog-count><?= e($catalog['total']) ?></strong> bidhaa</p>

        <div class="catalog-toolbar__controls">
            <button class="btn btn-outline-primary catalog-toolbar__filter" type="button" aria-expanded="false" aria-controls="catalog-filters" data-filter-toggle>
                <i class="bi bi-sliders" aria-hidden="true"></i> Chuja
                <span class="catalog-toolbar__filter-count" hidden data-filter-count></span>
            </button>

            <label class="visually-hidden" for="catalog-sort">Panga bidhaa kwa</label>
            <select class="form-select catalog-toolbar__sort" id="catalog-sort" data-catalog-sort>
                <?php foreach (CATALOG_SORT_OPTIONS as $sort_value => $sort_label) : ?>
                    <option value="<?= e($sort_value) ?>" <?= $filters['sort'] === $sort_value ? 'selected' : '' ?>>Panga: <?= e($sort_label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <form class="catalog-filters" id="catalog-filters" hidden data-catalog-filters>
        <label class="catalog-filters__field">
            <span>Bei ya chini (TZS)</span>
            <input class="form-control" type="number" name="min_price" min="0" step="100" inputmode="numeric" value="<?= e($filters['min_price'] ?? '') ?>">
        </label>
        <label class="catalog-filters__field">
            <span>Bei ya juu (TZS)</span>
            <input class="form-control" type="number" name="max_price" min="0" step="100" inputmode="numeric" value="<?= e($filters['max_price'] ?? '') ?>">
        </label>
        <label class="catalog-filters__field">
            <span>MOQ isiyozidi (pcs)</span>
            <input class="form-control" type="number" name="max_moq" min="1" step="1" inputmode="numeric" value="<?= e($filters['max_moq'] ?? '') ?>">
        </label>
        <div class="catalog-filters__actions">
            <button class="btn btn-primary" type="submit">Onyesha</button>
            <button class="btn btn-link" type="reset">Ondoa vichujio</button>
        </div>
    </form>

    <div class="product-grid" data-catalog-grid></div>

    <div class="catalog-more" hidden data-catalog-more>
        <p class="catalog-more__label" data-catalog-progress></p>
        <div class="catalog-more__bar" aria-hidden="true"><span data-catalog-progress-bar></span></div>
        <button class="btn btn-outline-primary" type="button" data-catalog-load-more>Onyesha zaidi</button>
    </div>
</div>

<script type="application/json" id="catalog-data"><?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
