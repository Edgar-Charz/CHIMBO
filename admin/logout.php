<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Only a POST with a valid CSRF token can log out (a link or another website cannot)
if (!isPostRequest()) {
    redirect(url('admin/index.php'));
}
Csrf::verifyOrFail($_POST['csrf_token'] ?? null);

AdminSession::logOut();
redirect(url('admin/login.php'));
