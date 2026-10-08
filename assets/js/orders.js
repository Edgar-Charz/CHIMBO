/**
 * Oda Zangu and the order page: "Agiza Tena" (products back in the cart at today's prices, then the drawer
 * opens) and "Ghairi oda" (only while the order can still be cancelled).
 */
(() => {
    'use strict';

    const { api, cart, setLoading, submitForm, toast } = CHIMBO;

    async function reorder(button) {
        setLoading(button, true);
        try {
            const result = await api.post(`/orders/${button.dataset.reorder}/reorder`);
            result.skipped_items.forEach((skipped) => toast(skipped.message, 'info'));
            await cart.refresh();
            cart.open();
            toast('Bidhaa zimerudishwa kikapuni kwa bei za leo.', 'success');
        } catch {
            // the error toast was already shown
        } finally {
            setLoading(button, false);
        }
    }

    document.querySelectorAll('[data-reorder]').forEach((button) => {
        button.addEventListener('click', () => reorder(button));
    });

    const cancelForm = document.getElementById('cancel-order-form');
    cancelForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const reason = cancelForm.elements.order_cancel_reason.value.trim();
        try {
            await submitForm(cancelForm, cancelForm.querySelector('[type="submit"]'), () => api.post(
                `/orders/${cancelForm.dataset.cancelOrder}/cancel`,
                { order_cancel_reason: reason || null },
                { silent: true },
            ));
            window.location.reload(); // the page shows the cancelled timeline
        } catch {
            // the message is shown in the dialog
        }
    });
})();
