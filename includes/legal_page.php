<?php

/**
 * A legal page (Vigezo na Masharti, Faragha) from the admin settings — the same text as GET /pages/{name}.
 * Expects $legal_page_name ('terms' or 'privacy'). The text is plain: paragraphs are separated by an empty line.
 */

$legal_page = (new Settings(Database::instance()))->getLegalPage($legal_page_name);
$paragraphs = array_filter(array_map('trim', preg_split('/\R\s*\R/u', $legal_page['page_body'])));

$page = [
    'title'       => $legal_page['page_title'],
    'description' => $legal_page['page_title'] . ' za CHIMBO.',
];

require __DIR__ . '/header.php';
?>

<section class="container-xl text-page">
    <h1 class="page-title"><?= e($legal_page['page_title']) ?></h1>
    <?php if ($legal_page['updated_at'] !== null) : ?>
        <p class="text-page__updated">Imesasishwa: <?= e(swahiliDate($legal_page['updated_at'])) ?></p>
    <?php endif; ?>

    <?php foreach ($paragraphs as $paragraph) : ?>
        <p><?= nl2br(e($paragraph)) ?></p>
    <?php endforeach; ?>

    <p class="text-page__contact">Una swali? <a href="<?= e(url('help.php')) ?>">Wasiliana nasi</a>.</p>
</section>

<?php require __DIR__ . '/footer.php'; ?>
