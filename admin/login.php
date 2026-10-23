<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$next_page = AdminNotification::safeLink($_GET['next'] ?? 'index.php');

// Already logged in → go straight to the dashboard
if (AdminSession::currentAdmin() !== null) {
    redirect(url('admin/' . $next_page));
}

$admin_email    = '';
$flash_messages = [];   // SweetAlert popups (dialogs.js)
if (($_GET['logged_out'] ?? '') === '1') {
    $flash_messages[] = ['type' => 'success', 'text' => 'You are logged out.', 'title' => 'See you soon'];
} elseif (($_GET['expired'] ?? '') === '1') {
    $flash_messages[] = ['type' => 'error', 'text' => 'You were logged out after 30 minutes without activity. Please log in again.', 'title' => 'Session ended'];
}

if (isPostRequest()) {
    Csrf::verifyOrFail($_POST['csrf_token'] ?? null);
    $next_page = AdminNotification::safeLink($_POST['next'] ?? $next_page);
    $admin_email = trim($_POST['admin_email'] ?? '');

    try {
        $admin = (new Admin(Database::instance()))->login($_POST);
        AdminSession::logIn($admin);
        Session::flash('success_title', 'Welcome back');
        Session::flash('success', 'Karibu, ' . strtok($admin['admin_full_name'], ' ') . '! You are logged in.');
        redirect(url('admin/' . $next_page));
    } catch (ApiException $e) {
        // Validation errors list each field; show the first one, otherwise the general message
        $flash_messages[] = ['type' => 'error', 'text' => $e->fields() ? array_values($e->fields())[0] : $e->getMessage(), 'title' => 'Could not log in'];
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · CHIMBO Admin</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(adminAsset('admin.css')) ?>">
</head>

<body class="admin-auth-page">
    <div class="admin-auth-card">
        <div class="admin-auth-brand"><span class="brand-green">CHI</span><span class="brand-orange">MBO</span></div>
        <div class="admin-auth-tagline">BIDHAA BORA • BEI NAFUU • BIASHARA IMARA</div>

        <?php require __DIR__ . '/includes/flash_messages.php'; ?>

        <form method="post" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="next" value="<?= e($next_page) ?>">
            <div class="mb-3">
                <label class="form-label" for="admin_email">Email</label>
                <input class="form-control" type="email" id="admin_email" name="admin_email"
                    value="<?= e($admin_email) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="admin_password">Password</label>
                <input class="form-control" type="password" id="admin_password" name="admin_password"
                    autocomplete="current-password" required>
            </div>
            <button class="btn btn-chimbo w-100" type="submit">Log in</button>
        </form>
    </div>

    <script src="<?= e(url('assets/vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
    <script src="<?= e(adminAsset('dialogs.js')) ?>"></script>
</body>

</html>