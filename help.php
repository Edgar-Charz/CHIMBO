<?php

/**
 * Msaada — common questions and how to reach CHIMBO (phone, WhatsApp, hours come from the admin settings).
 */

require __DIR__ . '/includes/init.php';

$settings = new Settings(Database::instance());
$support = $settings->getSupportContacts();
$questions = (new HelpQuestions(Database::instance()))->getQuestions();   // the same questions as the app

$page = [
    'title'       => 'Msaada',
    'description' => 'Maswali ya mara kwa mara na jinsi ya kuwasiliana na CHIMBO.',
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl text-page">
    <h1 class="page-title">Tunakusaidiaje?</h1>

    <div class="help-contacts">
        <?php if ($support['support_phone']) : ?>
            <a class="help-contact" href="tel:<?= e($support['support_phone']) ?>">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <span><strong>Piga simu</strong><?= e($support['support_phone']) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($support['support_whatsapp_url'] !== null) : ?>
            <a class="help-contact" href="<?= e($support['support_whatsapp_url']) ?>" target="_blank" rel="noopener">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span><strong>WhatsApp</strong>Tuandikie ujumbe</span>
            </a>
        <?php endif; ?>
        <?php if ($support['support_hours']) : ?>
            <div class="help-contact">
                <i class="bi bi-clock" aria-hidden="true"></i>
                <span><strong>Muda wa huduma</strong><?= e($support['support_hours']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <h2 class="text-page__subtitle">Maswali ya mara kwa mara</h2>
    <div class="accordion help-questions" id="help-questions">
        <?php foreach ($questions as $index => $item) : ?>
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help-answer-<?= $index ?>" aria-expanded="false" aria-controls="help-answer-<?= $index ?>">
                        <?= e($item['question']) ?>
                    </button>
                </h3>
                <div class="accordion-collapse collapse" id="help-answer-<?= $index ?>" data-bs-parent="#help-questions">
                    <div class="accordion-body"><?= e($item['answer']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
