/** Customer actions for product request history. */
(() => {
    'use strict';

    const requestList = document.querySelector('[data-product-requests]');
    if (!requestList) return;

    requestList.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-cancel-request]');
        if (!button) return;

        const card = button.closest('[data-product-request]');
        const requestId = card?.dataset.requestId;
        if (!requestId || !window.confirm('Uondoe ombi hili la bidhaa?')) return;

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        try {
            await CHIMBO.api.delete(`/product-requests/${encodeURIComponent(requestId)}`);
            card.remove();
            CHIMBO.toast('Ombi limeondolewa.', 'success');
            if (!requestList.querySelector('[data-product-request]')) {
                window.location.assign(CHIMBO.url('account.php'));
            }
        } catch {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });
})();
