(() => {
  'use strict';

  const pageSizeForm = document.querySelector('[data-push-page-size]');
  const pageSizeSelect = pageSizeForm?.querySelector('select[name="per_page"]');
  const statusFilter = document.querySelector('#push-status-filter');

  statusFilter?.addEventListener('change', () => statusFilter.form?.requestSubmit());

  if (pageSizeForm && pageSizeSelect) {
    pageSizeSelect.addEventListener('change', () => pageSizeForm.requestSubmit());
  }
})();
