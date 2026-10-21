(() => {
  'use strict';

  const order_link_page = document.querySelector('[data-order-link-page]');
  if (!order_link_page) {
    return;
  }

  const query = new URLSearchParams({ code: order_link_page.dataset.code });
  const admin_check_url = `${order_link_page.dataset.adminCheckUrl}?${query.toString()}`;

  fetch(admin_check_url, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  })
    .then((response) => response.ok ? response.json() : null)
    .then((data) => {
      if (data?.url) {
        window.location.replace(data.url);
      }
    })
    .catch(() => {
      // The public order summary remains usable when the staff session check is unavailable.
    });
})();
