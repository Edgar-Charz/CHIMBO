<?php

/** Shown by AdminSession::requireLogin() when the admin's role lacks the permission. */
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not allowed · CHIMBO Admin</title>
    <?php require __DIR__ . '/favicon.php'; ?>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(adminAsset('admin.css')) ?>">
</head>

<body class="admin-auth-page">
    <div class="admin-auth-card text-center">
        <h1 class="h4 mb-3">Not allowed</h1>
        <p class="text-muted">Your role does not have access to this page.</p>
        <a class="btn btn-chimbo" href="<?= e(url('admin/index.php')) ?>">Back to dashboard</a>
    </div>
</body>

</html>