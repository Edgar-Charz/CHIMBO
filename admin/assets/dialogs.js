/**
 * Every admin message as a SweetAlert dialog (assets/vendor/sweetalert2). Loaded on every admin page and the login page.
 *
 *   <form data-confirm="Delete it?">           asks first (also on a button: data-confirm on the clicked submit button);
 *                                               a red / danger button gets a red "Yes"
 *   <div hidden data-flash-message="success" data-flash-title="Welcome back">Text</div>
 *                                               a message from the server, shown when the page opens
 *                                               ("success" closes by itself, "error" stays until OK)
 *   AdminDialog.success('Saved.') · AdminDialog.error('Could not load.')   from other scripts
 */
(() => {
  'use strict';

  const GREEN = '#0e3b2a';
  const RED = '#b42318';
  const SUCCESS_CLOSES_AFTER_MS = 2500;

  // The admin's colours: green "Yes" / OK, grey "Cancel", the safe button on the left
  const dialog = Swal.mixin({
    confirmButtonColor: GREEN,
    cancelButtonColor: '#6c757d',
    reverseButtons: true,
  });

  const AdminDialog = {
    success: (text, title = 'Done') => dialog.fire({
      icon: 'success',
      title,
      text,
      timer: SUCCESS_CLOSES_AFTER_MS,
      timerProgressBar: true,
    }),

    // An error stays until OK (or Esc): no timer, and a click beside it doesn't close it unread
    error: (text, title = 'Something went wrong') => dialog.fire({ icon: 'error', title, text, allowOutsideClick: false }),

    /** Resolves true for "Yes". */
    confirm: async (text, { isDanger = false } = {}) => {
      const result = await dialog.fire({
        icon: isDanger ? 'warning' : 'question',
        title: 'Are you sure?',
        text,
        showCancelButton: true,
        confirmButtonText: 'Yes, continue',
        cancelButtonText: 'Cancel',
        confirmButtonColor: isDanger ? RED : GREEN,
      });
      return result.isConfirmed;
    },
  };
  window.AdminDialog = AdminDialog;

  // Ask before actions with data-confirm. The form is sent only after "Yes"; requestSubmit() keeps the clicked
  // button's name / value (form_action).
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    const submitter = event.submitter;
    const message = submitter?.dataset.confirm ?? form.dataset.confirm;
    if (!message) {
      return;
    }
    if (form.dataset.confirmed === 'yes') {
      delete form.dataset.confirmed;   // answered: let this submit through
      return;
    }

    event.preventDefault();
    const isDanger = /danger/.test(submitter?.className ?? '') || /danger/.test(form.className);
    if (await AdminDialog.confirm(message, { isDanger })) {
      form.dataset.confirmed = 'yes';
      form.requestSubmit(submitter ?? undefined);
    }
  }, true);

  // Messages from the server (saved, logged in, an error …)
  document.querySelectorAll('[data-flash-message]').forEach((flash) => {
    const text = flash.textContent.trim();
    const title = flash.dataset.flashTitle || undefined;
    if (flash.dataset.flashMessage === 'success') {
      AdminDialog.success(text, title);
    } else {
      AdminDialog.error(text, title);
    }
  });
})();
