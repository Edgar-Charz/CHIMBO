/**
 * Arifa page: tapping an unread notification marks it read before its order opens;
 * "Soma zote" marks everything read and clears the bell.
 */
(() => {
    'use strict';

    const { api, setLoading, toast } = CHIMBO;

    function showAsRead(item) {
        item.classList.remove('is-unread');
        item.querySelector('.notification-item__dot')?.remove();
    }

    function updateBell(change) {
        const badge = document.querySelector('[data-unread-badge]');
        if (!badge) return;
        const count = change === 'clear' ? 0 : Math.max(0, Number(badge.textContent) - 1);
        badge.textContent = count;
        badge.hidden = count === 0;
    }

    document.querySelectorAll('.notification-item.is-unread').forEach((item) => {
        item.addEventListener('click', async (event) => {
            event.preventDefault();
            try {
                await api.post(`/notifications/${item.dataset.notificationId}/read`, undefined, { silent: true });
                showAsRead(item);
                updateBell('one');
            } catch {
                // reading the order matters more than the dot; open it anyway
            }
            window.location.assign(item.href);
        });
    });

    const readAllButton = document.querySelector('[data-read-all]');
    readAllButton?.addEventListener('click', async () => {
        setLoading(readAllButton, true);
        try {
            await api.post('/notifications/read-all');
            document.querySelectorAll('.notification-item.is-unread').forEach(showAsRead);
            updateBell('clear');
            readAllButton.remove();
            toast('Arifa zote zimesomwa.', 'success');
        } catch {
            setLoading(readAllButton, false); // the error toast was already shown
        }
    });
})();
