/**
 * Product page: quantity stepper with a live tier-price preview, next-tier hint, add to cart
 * (with a sticky buy bar on phones once the main button scrolls away), gallery, wishlist, share, suggestions.
 * The preview uses the tiers the page received; the cart and checkout always use the server's prices.
 */
(() => {
    'use strict';

    const { formatTzs, formatPieces, quantityStepper, setLoading, toast, productCard } = CHIMBO;

    const { product, suggested_products: suggestedProducts } = JSON.parse(document.getElementById('product-data').textContent);
    const { tiers } = product;
    const normalUnitPrice = tiers[0].tier_unit_price;

    let quantity = product.product_moq;

    // ------------------------------------------------------------------ Price preview

    /** The tier this quantity reaches (same rule as the server's Pricing::tierForQuantity). */
    const tierForQuantity = (count) => tiers.filter((tier) => tier.tier_min_quantity <= count).at(-1) ?? tiers[0];
    const nextTierAfter = (tier) => tiers.find((candidate) => candidate.tier_min_quantity > tier.tier_min_quantity) ?? null;

    function showPricePreview() {
        const tier = tierForQuantity(quantity);
        const nextTier = nextTierAfter(tier);
        const total = tier.tier_unit_price * quantity;
        const saved = (normalUnitPrice - tier.tier_unit_price) * quantity;

        document.querySelector('[data-unit-price]').textContent = formatTzs(tier.tier_unit_price);
        document.querySelector('[data-line-total]').textContent = formatTzs(total);
        document.querySelector('[data-buy-bar-quantity]').textContent = formatPieces(quantity);
        document.querySelector('[data-buy-bar-total]').textContent = formatTzs(total);

        const savings = document.querySelector('[data-savings]');
        savings.textContent = `Unaokoa ${formatTzs(saved)} kwa idadi hii`;
        savings.hidden = saved === 0;

        const hint = document.querySelector('[data-next-tier-hint]');
        hint.hidden = nextTier === null;
        if (nextTier) {
            hint.textContent = `Ongeza ${formatPieces(nextTier.tier_min_quantity - quantity)} upate ${formatTzs(nextTier.tier_unit_price)} kila moja`;
        }

        document.querySelectorAll('[data-tier-min]').forEach((row) => {
            row.classList.toggle('is-active', Number(row.dataset.tierMin) === tier.tier_min_quantity);
        });
    }

    // ------------------------------------------------------------------ Cart

    function showInCartNote() {
        const inCart = CHIMBO.cart.quantityOf(product.product_id);
        const note = document.querySelector('[data-in-cart-note]');
        note.hidden = inCart === 0;
        note.textContent = `Tayari una ${formatPieces(inCart)} kikapuni.`;
    }

    function setUpAddToCart() {
        document.querySelectorAll('[data-add-to-cart]').forEach((button) => {
            button.addEventListener('click', async () => {
                setLoading(button, true);
                try {
                    await CHIMBO.cart.add(product.product_id, quantity, { openDrawer: true });
                } catch {
                    // the error toast was already shown
                } finally {
                    setLoading(button, false);
                }
            });
        });
        document.addEventListener(CHIMBO.cart.changedEvent, showInCartNote);
    }

    /**
     * Phones: the buy bar slides up once the main "Ongeza kikapuni" has scrolled up out of sight
     * (hidden under the sticky header counts as out of sight).
     */
    function setUpBuyBar() {
        const buyBar = document.querySelector('[data-buy-bar]');
        const mainButton = document.querySelector('[data-buy-box] [data-add-to-cart]');
        const headerHeight = document.querySelector('[data-site-header]').offsetHeight;

        new IntersectionObserver(([entry]) => {
            const isVisible = !entry.isIntersecting && entry.boundingClientRect.top < headerHeight;
            buyBar.classList.toggle('is-visible', isVisible);
            buyBar.setAttribute('aria-hidden', String(!isVisible));
            buyBar.querySelector('button').tabIndex = isVisible ? 0 : -1;
        }, { rootMargin: `-${headerHeight}px 0px 0px 0px` }).observe(mainButton);
    }

    // ------------------------------------------------------------------ Gallery, wishlist, share

    /**
     * Thumbnails: hovering (or tabbing to) one shows it in the big picture; moving away shows the chosen one
     * again; clicking chooses it.
     */
    function setUpGallery() {
        const mainImage = document.querySelector('[data-gallery-main] img');
        const thumbs = [...document.querySelectorAll('[data-gallery-thumb]')];
        let chosenThumb = thumbs[0];

        const showInMain = (thumb) => { mainImage.src = thumb.dataset.largeUrl; };

        function choose(thumb) {
            chosenThumb = thumb;
            showInMain(thumb);
            thumbs.forEach((other) => {
                other.classList.toggle('is-active', other === thumb);
                other.setAttribute('aria-pressed', String(other === thumb));
            });
        }

        thumbs.forEach((thumb) => {
            thumb.addEventListener('mouseenter', () => showInMain(thumb));
            thumb.addEventListener('focus', () => showInMain(thumb));
            thumb.addEventListener('mouseleave', () => showInMain(chosenThumb));
            thumb.addEventListener('blur', () => showInMain(chosenThumb));
            thumb.addEventListener('click', () => choose(thumb));
        });
    }

    function setUpShare() {
        document.querySelector('[data-product-share]').addEventListener('click', async () => {
            const shareData = { title: product.product_name, text: `${product.product_name} — bei za jumla CHIMBO`, url: window.location.href };
            try {
                if (navigator.share) {
                    await navigator.share(shareData);
                } else {
                    await navigator.clipboard.writeText(shareData.url);
                    toast('Kiungo kimenakiliwa. Kibandike WhatsApp au popote.', 'success');
                }
            } catch (error) {
                if (error.name !== 'AbortError') toast('Imeshindikana kushiriki kiungo hiki.', 'error');
            }
        });
    }

    // ------------------------------------------------------------------ Start

    const stepper = quantityStepper({
        value: quantity,
        min: product.product_moq,
        max: Math.max(product.product_stock_quantity, product.product_moq),
        label: `Idadi ya ${product.product_name}`,
        delayMs: 0, // only the on-page preview changes, nothing is sent
        onChange: (newQuantity) => {
            quantity = newQuantity;
            showPricePreview();
        },
    });
    stepper.querySelectorAll('button, input').forEach((control) => { control.disabled = !product.product_in_stock; });
    document.querySelector('[data-product-quantity]').replaceChildren(stepper);

    const suggestionsGrid = document.querySelector('[data-suggested-products]');
    if (suggestionsGrid) suggestionsGrid.replaceChildren(...suggestedProducts.map(productCard.render));

    showPricePreview();
    setUpAddToCart();
    setUpBuyBar();
    setUpGallery();
    CHIMBO.wishlist.bindButton(document.querySelector('[data-product-wishlist]'), product.product_id);
    setUpShare();
})();
