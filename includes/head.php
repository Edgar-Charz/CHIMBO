<?php

/**
 * <head> of every storefront page. Expects $page (see pageSettings()).
 * Included by header.php — pages don't include it directly.
 */

$page_title = $page['title'] === SHOP_NAME ? SHOP_NAME . ' — ' . SHOP_TAGLINE : $page['title'] . ' | ' . SHOP_NAME;
?>
<!doctype html>
<html lang="sw">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($page_title) ?></title>
    <meta name="description" content="<?= e($page['description']) ?>">
    <meta name="theme-color" content="<?= e(SHOP_THEME_COLOR) ?>">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">

    <meta property="og:site_name" content="<?= e(SHOP_NAME) ?>">
    <meta property="og:title" content="<?= e($page_title) ?>">
    <meta property="og:description" content="<?= e($page['description']) ?>">
    <meta property="og:image" content="<?= e($page['image']) ?>">

    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <link rel="preload" href="<?= e(url('assets/fonts/poppins-regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

    <script type="application/json" id="chimbo-config"><?= json_encode(storefrontConfig(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
</head>
