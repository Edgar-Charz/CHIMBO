/**
 * The product card — one template for every product grid (home, category, search, suggestions, Vipendwa),
 * with a ♡ (CHIMBO.wishlist) in the photo corner.
 *   CHIMBO.productCard.render(product)        → <article> element
 *   CHIMBO.productCard.renderSkeletons(count) → grey placeholder cards while loading
 * Quick add puts the MOQ in the cart; the card then shows a stepper. Cards stay in step with the cart
 * through the "chimbo:cart-changed" event (also when the change happens in the drawer), and redraw themselves
 * when their offer countdown reaches zero ("chimbo:offer-ended").
 */
(() => {
    'use strict';

    const { el, icon, formatTzs, productUrl, productImage, quantityStepper, setLoading, skeleton } = CHIMBO;

    const CARD_MAX_QUANTITY = 9999; // the server checks the real stock and answers with a clear message
    const BADGE_LABELS = { deal: 'Ofa', bestseller: 'Inauzwa sana', new: 'Mpya' };
    const productsByCard = new WeakMap(); // card element → its product, to redraw the add button later

    /** "Ofa −15%" during a time-limited offer, "−12%" for a deal with an old price, otherwise the badge name. */
    function badgeText(product) {
        const { product_badge: badge, product_compare_at_price: compareAt, product_price: price, product_offer: offer } = product;
        if (badge === 'offer' && offer) {
            return `Ofa −${offer.product_offer_percent}%`;
        }
        if (badge === 'deal' && compareAt > price) {
            return `−${Math.round((1 - price / compareAt) * 100)}%`;
        }
        return BADGE_LABELS[badge];
    }

    function renderAddButton(product) {
        if (!product.product_in_stock) {
            return el('span', { className: 'product-card__sold-out', text: 'Imeisha' });
        }

        const button = el('button', {
            className: 'product-card__add',
            attrs: { type: 'button', 'aria-label': `Ongeza ${product.product_name} kikapuni (pcs ${product.product_moq})` },
        }, [icon('plus-lg'), el('span', { className: 'product-card__add-label', text: 'Ongeza kikapuni' })]);

        button.addEventListener('click', async () => {
            setLoading(button, true);
            try {
                await CHIMBO.cart.add(product.product_id, product.product_moq);
            } catch {
                setLoading(button, false); // the error toast was already shown
            }
        });
        return button;
    }

    function renderStepper(product, quantity) {
        return quantityStepper({
            value: quantity,
            min: product.product_moq,
            max: CARD_MAX_QUANTITY,
            allowRemove: true,
            label: `Idadi ya ${product.product_name}`,
            onChange: (newQuantity) => CHIMBO.cart.setQuantity(product.product_id, newQuantity).catch(() => {}),
        });
    }

    /** Button when the product isn't in the cart, stepper when it is. */
    function renderAction(product) {
        const quantity = CHIMBO.cart.quantityOf(product.product_id);
        return el('div', { className: `product-card__action${quantity > 0 ? ' is-in-cart' : ''}` }, [
            quantity > 0 ? renderStepper(product, quantity) : renderAddButton(product),
        ]);
    }

    function renderPrice(product) {
        const hasOffer = product.product_compare_at_price > product.product_price;
        return el('p', { className: 'product-card__price' }, [
            el('span', { className: 'product-card__price-label', text: 'Kuanzia' }),
            el('strong', { text: formatTzs(product.product_price_from) }),
            hasOffer ? el('del', { className: 'product-card__compare', text: formatTzs(product.product_compare_at_price) }) : null,
        ]);
    }

    function render(product) {
        const link = productUrl(product);
        const badge = badgeText(product);
        const card = el('article', { className: 'product-card', dataset: { productId: product.product_id } }, [
            el('div', { className: 'product-card__media' }, [
                el('a', { className: 'product-card__image-link', attrs: { href: link, tabindex: '-1', 'aria-hidden': 'true' } }, [
                    productImage(product.product_image_url, product.product_name, 'product-card__image'),
                ]),
                badge ? el('span', { className: `product-card__badge product-card__badge--${product.product_badge}`, text: badge }) : null,
                CHIMBO.wishlist.heartButton(product),
                renderAction(product),
            ]),
            el('div', { className: 'product-card__body' }, [
                el('p', { className: 'product-card__seller' }, [
                    product.seller_name,
                    product.seller_is_verified ? icon('patch-check-fill', 'product-card__verified') : null,
                ]),
                el('a', { className: 'product-card__name', text: product.product_name, attrs: { href: link } }),
                renderPrice(product),
                product.product_offer ? CHIMBO.offerCountdown(product.product_offer.product_offer_ends_at, product.product_id, 'product-card__countdown') : null,
                el('p', { className: 'product-card__moq', text: `MOQ ${CHIMBO.formatPieces(product.product_moq)}` }),
            ]),
        ]);
        productsByCard.set(card, product);
        return card;
    }

    const renderSkeletons = (count) => Array.from({ length: count }, () => el('div', { className: 'product-card product-card--skeleton', attrs: { 'aria-hidden': 'true' } }, [
        skeleton('product-card__media'),
        el('div', { className: 'product-card__body' }, [skeleton('skeleton--text'), skeleton('skeleton--text'), skeleton('skeleton--text skeleton--short')]),
    ]));

    /** Redraws the add button / stepper of every card whose cart quantity changed. */
    function syncCardsWithCart() {
        document.querySelectorAll('.product-card[data-product-id]').forEach((card) => {
            if (!productsByCard.has(card)) return;
            const product = productsByCard.get(card);
            const shownAsInCart = card.querySelector('.product-card__action').classList.contains('is-in-cart');
            const stepperInput = card.querySelector('.quantity-stepper__input');
            const quantity = CHIMBO.cart.quantityOf(product.product_id);

            if (shownAsInCart !== quantity > 0 || (stepperInput && Number(stepperInput.value) !== quantity)) {
                card.querySelector('.product-card__action').replaceWith(renderAction(product));
            }
        });
    }

    /** An offer reached zero: load that product again (the server now gives the normal price) and redraw its cards. */
    async function refreshCardsAfterOffer({ detail }) {
        const cards = [...document.querySelectorAll(`.product-card[data-product-id="${detail.productId}"]`)];
        if (cards.length === 0) return;
        try {
            const product = await CHIMBO.api.get(`/products/${detail.productId}`, { silent: true });
            cards.forEach((card) => card.replaceWith(render(product)));
        } catch {
            // the product may have left the shop; the card stays until the next page load
        }
    }

    document.addEventListener(CHIMBO.cart.changedEvent, syncCardsWithCart);
    document.addEventListener(CHIMBO.offerEndedEvent, refreshCardsAfterOffer);

    CHIMBO.productCard = { render, renderSkeletons };
})();
