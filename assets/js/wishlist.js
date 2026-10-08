/**
 * Vipendwa (the heart) on every page: which products are saved, and saving/removing them.
 *   CHIMBO.wishlist.heartButton(product)          → a ♡ button for a product card
 *   CHIMBO.wishlist.bindButton(button, productId) → turns an existing button into a heart (product page)
 * Guests who tap a heart go to login and come back. Every heart for the same product updates together
 * (document event "chimbo:wishlist-changed").
 */
(() => {
    'use strict';

    const { config, api, el, icon, toast, setLoading } = CHIMBO;

    const WISHLIST_CHANGED_EVENT = 'chimbo:wishlist-changed';

    const savedIds = new Set();

    const isSaved = (productId) => savedIds.has(productId);

    /** Redraws every heart of this product on the page, then tells other scripts (e.g. the Vipendwa page). */
    function announceChange(productId) {
        document.querySelectorAll(`[data-heart-for="${productId}"]`).forEach((button) => showHeart(button, productId));
        document.dispatchEvent(new CustomEvent(WISHLIST_CHANGED_EVENT, { detail: { productId, isSaved: isSaved(productId) } }));
    }

    async function toggle(productId) {
        if (!config.is_logged_in) {
            window.location.href = `${config.login_url}?return=${encodeURIComponent(window.location.pathname + window.location.search)}`;
            return;
        }
        if (isSaved(productId)) {
            await api.delete(`/wishlist/${productId}`);
            savedIds.delete(productId);
            toast('Imeondolewa kwenye vipendwa.', 'success');
        } else {
            await api.post('/wishlist', { product_id: productId });
            savedIds.add(productId);
            toast('Imehifadhiwa kwenye vipendwa.', 'success');
        }
        announceChange(productId);
    }

    function showHeart(button, productId) {
        const saved = isSaved(productId);
        button.classList.toggle('is-saved', saved);
        button.setAttribute('aria-pressed', String(saved));
        button.setAttribute('aria-label', saved ? 'Ondoa kwenye vipendwa' : 'Hifadhi kwenye vipendwa');
        button.replaceChildren(icon(saved ? 'heart-fill' : 'heart'));
    }

    function bindButton(button, productId) {
        button.dataset.heartFor = productId;
        showHeart(button, productId);
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            setLoading(button, true);
            try {
                await toggle(productId);
            } catch {
                // the error toast was already shown
            } finally {
                setLoading(button, false);
            }
        });
        return button;
    }

    const heartButton = (product) => bindButton(
        el('button', { className: 'heart-button', attrs: { type: 'button' } }),
        product.product_id,
    );

    async function loadSavedIds() {
        try {
            (await api.get('/wishlist/ids', { silent: true })).forEach((productId) => savedIds.add(productId));
            savedIds.forEach(announceChange);
        } catch {
            // the hearts simply stay empty
        }
    }

    if (config.is_logged_in) loadSavedIds();

    CHIMBO.wishlist = { isSaved, heartButton, bindButton, changedEvent: WISHLIST_CHANGED_EVENT };
})();
