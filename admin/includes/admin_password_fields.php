<?php

/**
 * "New password" + "Repeat it" inputs, used when creating a staff account and when resetting a password
 * (admin_user_edit.php). Set before including: $password_errors (field errors for these two inputs, or []).
 */
?>
<div class="row g-3">
    <div class="col-sm-6">
        <label class="form-label" for="admin_password">New password</label>
        <input class="form-control<?= adminInvalidClass($password_errors, 'admin_password') ?>" type="password" id="admin_password" name="admin_password"
               minlength="10" autocomplete="new-password" required>
        <div class="form-text">At least 10 characters.</div>
        <?= adminFieldError($password_errors, 'admin_password') ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="admin_password_confirmation">Repeat the password</label>
        <input class="form-control<?= adminInvalidClass($password_errors, 'admin_password_confirmation') ?>" type="password" id="admin_password_confirmation"
               name="admin_password_confirmation" autocomplete="new-password" required>
        <?= adminFieldError($password_errors, 'admin_password_confirmation') ?>
    </div>
</div>
