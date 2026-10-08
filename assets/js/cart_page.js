/**
 * Kikapu page: shows the cart's lines (the totals boxes are filled by cart.js) and "Futa kikapu chote".
 * Lines redraw whenever the cart changes — here, in the drawer or on a product card.
 */
(() => {
    'use strict';

    const { cart, errorState, setLoading, toast } = CHIMBO;

    const LOADING_LINES = 3;

    const linesBox = document.querySelector('[data-cart-page-lines]');
    const clearButton = document.querySelector('[data-cart-clear]');

    function showLines(currentCart) {
        const isEmpty = currentCart.summary.line_count === 0;
        linesBox.replaceChildren(...(isEmpty ? [cart.renderEmptyState()] : cart.renderGroups(currentCart)));
    }

    function showLoading() {
        linesBox.replaceChildren(...cart.renderLineSkeletons(LOADING_LINES));
    }

    function showError(error) {
        linesBox.replaceChildren(errorState(error, () => {
            showLoading();
            cart.refresh().catch(showError);
        }));
    }

    clearButton.addEventListener('click', async () => {
        if (!window.confirm('Una uhakika unataka kuondoa bidhaa zote kwenye kikapu?')) return;

        setLoading(clearButton, true);
        try {
            await cart.clear();
            toast('Kikapu kimefutwa.', 'success');
        } catch {
            // the error toast was already shown
        } finally {
            setLoading(clearButton, false);
        }
    });

    document.addEventListener(cart.changedEvent, (event) => showLines(event.detail));

    showLoading();
    cart.whenLoaded().then(showLines, showError);
})();
