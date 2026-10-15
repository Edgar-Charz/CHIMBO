<?php
/**
 * Browser-tab and home-screen icons: the logo uploaded in Settings → Logo & pictures, else the built-in CHIMBO mark.
 * Included in the <head> of every admin page.
 */
$site_logo = new SiteLogo(Database::instance());
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e($site_logo->getUrl('32') ?? adminAsset('img/favicon-32.png')) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= e($site_logo->getUrl('192') ?? adminAsset('img/favicon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= e($site_logo->getUrl('180') ?? adminAsset('img/apple-touch-icon.png')) ?>">
