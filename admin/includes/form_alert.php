<?php

/**
 * The message above a form when saving failed.
 * The page sets $form_error (from adminHandleForm()); the field messages themselves appear under each input.
 */

if ($form_error !== null): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-circle"></i>
        <?= e($form_error->fields() ? 'Please correct the fields marked in red.' : $form_error->getMessage()) ?>
    </div>
<?php endif; ?>