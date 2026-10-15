<?php

/**
 * Why saving failed, as a SweetAlert popup (dialogs.js).
 * The page sets $form_error (from adminHandleForm()); the field messages themselves stay under each input, in red.
 */

if ($form_error !== null): ?>
    <div hidden data-flash-message="error" data-flash-title="Not saved"><?= e($form_error->fields() ? 'Please correct the fields marked in red.' : $form_error->getMessage()) ?></div>
<?php endif; ?>