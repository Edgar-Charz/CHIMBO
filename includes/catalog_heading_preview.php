<?php

$heading_products_json = json_encode(
    $heading_products,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
?>
<script type="application/json" id="catalog-heading-products"><?= $heading_products_json ?></script>

<div class="modal fade catalog-preview" id="catalog-heading-preview" tabindex="-1" aria-labelledby="catalog-preview-title" aria-hidden="true" data-catalog-preview>
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content catalog-preview__content">
            <div class="catalog-preview__media">
                <div class="catalog-preview__image-stage" data-preview-swipe>
                    <img class="catalog-preview__image" alt="" data-preview-image>
                </div>
                <div class="catalog-preview__gallery-controls" data-preview-gallery-controls hidden>
                    <div class="catalog-preview__dots" role="group" aria-label="Picha za bidhaa" data-preview-dots></div>
                </div>
                <button class="catalog-preview__arrow catalog-preview__arrow--previous" type="button" aria-label="Bidhaa iliyotangulia" data-preview-previous>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </button>
                <button class="catalog-preview__arrow catalog-preview__arrow--next" type="button" aria-label="Bidhaa inayofuata" data-preview-next>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
                <div class="catalog-preview__favorite" data-preview-favorite></div>
                <button class="catalog-preview__close" type="button" data-bs-dismiss="modal" aria-label="Funga picha">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <div class="catalog-preview__details">
                <p class="catalog-preview__seller" data-preview-seller></p>
                <h2 class="catalog-preview__title" id="catalog-preview-title" data-preview-title></h2>
                <p class="catalog-preview__price"><strong data-preview-price></strong><span data-preview-unit></span></p>
                <p class="catalog-preview__moq">Kiwango cha chini: <span data-preview-moq></span></p>
                <div class="catalog-preview__actions">
                    <button class="btn btn-primary" type="button" data-preview-add-to-cart>
                        <i class="bi bi-bag-plus" aria-hidden="true"></i>
                        <span data-preview-add-label>Ongeza kikapuni</span>
                    </button>
                    <a class="btn btn-outline-primary" href="" data-preview-link>Tazama bidhaa</a>
                </div>
            </div>
        </div>
    </div>
</div>
