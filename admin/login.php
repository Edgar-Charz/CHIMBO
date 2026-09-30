<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Already logged in → go straight to the dashboard
if (AdminSession::currentAdmin() !== null) {
    redirect(url('admin/index.php'));
}

$error_message = null;
$admin_email   = '';

if (isPostRequest()) {
    Csrf::verifyOrFail($_POST['csrf_token'] ?? null);
    $admin_email = trim($_POST['admin_email'] ?? '');

    try {
        $admin = (new Admin(Database::instance()))->login($_POST);
        AdminSession::logIn($admin);
        redirect(url('admin/index.php'));
    } catch (ApiException $e) {
        // Validation errors list each field; show the first one, otherwise the general message
        $error_message = $e->fields() ? array_values($e->fields())[0] : $e->getMessage();
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

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?= e($error_message) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= Csrf::field() ?>
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
</body>
</html>
