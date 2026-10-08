<?php

/**
 * Bottom of every storefront page: the footer (full, or a single line on focused pages), phone bottom
 * navigation, cart drawer, toasts and scripts. Expects $page (set up by header.php).
 */

$shared_scripts = ['vendor/bootstrap/bootstrap.bundle.min.js', 'js/chimbo.js', 'js/cart.js', 'js/wishlist.js'];
if (!$page['is_focused']) {
    $shared_scripts[] = 'js/header.js'; // search, bell and logout live in the full header only
}
$page_scripts = array_map(fn (string $script): string => 'js/' . $script, $page['scripts']);
?>
    </main>

    <?php if ($page['is_focused']) : ?>
        <footer class="focused-footer">
            <div class="container-xl focused-footer__inner">
                <span>&copy; <?= e(date('Y')) ?> <?= e(SHOP_NAME) ?></span>
                <a href="<?= e(url('help.php')) ?>">Msaada</a>
                <a href="<?= e(url('terms.php')) ?>">Vigezo na Masharti</a>
                <a href="<?= e(url('privacy.php')) ?>">Faragha</a>
            </div>
        </footer>
    <?php else : ?>
        <?php require __DIR__ . '/site_footer.php'; ?>
        <?php require __DIR__ . '/bottom_nav.php'; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/cart_drawer.php'; ?>

    <div class="toast-region" aria-live="polite" data-toast-region></div>

    <?php foreach ([...$shared_scripts, ...$page_scripts] as $script) : ?>
        <script src="<?= e(asset($script)) ?>" defer></script>
    <?php endforeach; ?>
</body>

</html>
