/**
 * Vipendwa page: draws the saved products, and takes a card away as soon as its ♡ is removed.
 */
(() => {
    'use strict';

    const { productCard, stateBlock, wishlist } = CHIMBO;

    const grid = document.querySelector('[data-wishlist-grid]');
    const countLabel = document.querySelector('[data-wishlist-count]');
    let products = JSON.parse(document.getElementById('wishlist-data').textContent);

    function showEmptyIfNeeded() {
        countLabel.textContent = products.length === 0 ? '' : `(${products.length})`;
        if (products.length > 0) return;

        grid.replaceChildren(stateBlock({
            iconName: 'heart',
            title: 'Hakuna vipendwa bado',
            text: 'Gusa ♡ kwenye bidhaa ili kuihifadhi hapa — utaipata haraka utakapotaka kuagiza.',
            actionLabel: 'Gundua bidhaa',
            actionHref: CHIMBO.url('explore.php'),
        }));
    }

    document.addEventListener(wishlist.changedEvent, ({ detail }) => {
        if (detail.isSaved) return;
        products = products.filter((product) => product.product_id !== detail.productId);
        grid.querySelector(`.product-card[data-product-id="${detail.productId}"]`)?.remove();
        showEmptyIfNeeded();
    });

    grid.replaceChildren(...products.map(productCard.render));
    showEmptyIfNeeded();
})();
