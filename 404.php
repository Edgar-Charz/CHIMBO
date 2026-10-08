<?php

/**
 * "Ukurasa haupo" — shown by Apache for unknown addresses (ErrorDocument in the root .htaccess).
 */

require __DIR__ . '/includes/init.php';

http_response_code(404);

$page = [
    'title'       => 'Ukurasa haupo',
    'description' => 'Ukurasa uliotafuta haupo kwenye CHIMBO.',
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl">
    <div class="state-block">
        <span class="state-block__icon"><i class="bi bi-signpost-split" aria-hidden="true"></i></span>
        <h1 class="state-block__title">Ukurasa huu haupo</h1>
        <p class="state-block__text">Huenda kiungo kimeandikwa vibaya au ukurasa umeondolewa. Tafuta bidhaa juu, au anza hapa:</p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a class="btn btn-primary" href="<?= e(url('')) ?>">Nyumbani</a>
            <a class="btn btn-outline-primary" href="<?= e(url('explore.php')) ?>">Gundua bidhaa</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
