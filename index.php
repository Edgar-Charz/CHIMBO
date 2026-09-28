<?php
// Temporary placeholder — the web storefront replaces this in Phase 7.
require __DIR__ . '/bootstrap.php';
$appName = htmlspecialchars(Env::get('APP_NAME', 'CHIMBO'));
?>
<!doctype html>
<html lang="sw">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $appName ?></title>
</head>

<body style="font-family: system-ui, sans-serif; background: #FBF6EE; color: #0E3B2A; text-align: center; padding: 4rem 1rem;">
    <h1><?= $appName ?></h1>
    <p>BIDHAA BORA • BEI NAFUU • BIASHARA IMARA</p>
    <p>Inakuja hivi karibuni.</p>
</body>

</html>