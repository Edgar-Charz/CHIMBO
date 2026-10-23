<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('settings.manage');
$database = Database::instance();
$notification_model = new Notification($database);
$sender = new FcmPushSender();
$templates = [
    'welcome' => [
        'label' => 'Karibu CHIMBO',
        'sw' => ['title' => 'Karibu CHIMBO! 🎉', 'body' => 'CHIMBO sasa iko wazi! Fungua app, vinjari bidhaa za jumla na tuma oda yako leo.'],
        'en' => ['title' => 'Welcome to CHIMBO! 🎉', 'body' => 'CHIMBO is open! Browse wholesale products in the app and place your order today.'],
    ],
    'new_products' => [
        'label' => 'Bidhaa mpya',
        'sw' => ['title' => 'Bidhaa mpya zimewasili ✨', 'body' => 'Tumeongeza bidhaa mpya CHIMBO. Fungua app uzione na uagize unachohitaji.'],
        'en' => ['title' => 'New products have arrived ✨', 'body' => 'We have added new products to CHIMBO. Open the app to browse and order what you need.'],
    ],
    'special_news' => [
        'label' => 'Taarifa maalum',
        'sw' => ['title' => 'Tuna taarifa mpya kwa ajili yako 📣', 'body' => 'Fungua app ya CHIMBO kuona taarifa yetu mpya.'],
        'en' => ['title' => 'An update for you 📣', 'body' => 'Open the CHIMBO app to read our latest update.'],
    ],
    'come_back' => [
        'label' => 'Karibu tena',
        'sw' => ['title' => 'CHIMBO inakukumbuka 💚', 'body' => 'Bidhaa zako za jumla zinakusubiri. Fungua app ya CHIMBO uendelee kununua.'],
        'en' => ['title' => 'We miss you 💚', 'body' => 'Your wholesale products are waiting. Open CHIMBO and keep shopping.'],
    ],
];
$saved_values = [
    'template' => 'welcome',
    'notification_title_sw' => $templates['welcome']['sw']['title'],
    'notification_body_sw' => $templates['welcome']['sw']['body'],
    'notification_title_en' => $templates['welcome']['en']['title'],
    'notification_body_en' => $templates['welcome']['en']['body'],
];

$form_error = adminHandleForm(function () use ($current_admin, $notification_model, $sender, $templates): void {
    if (!$sender->isConfigured()) {
        Session::flash('error', 'Firebase push is not configured. Set FCM_SERVICE_ACCOUNT_FILE before sending a broadcast.');
        redirect(url('admin/notification_broadcast.php'));
    }

    Validator::validate($_POST, [
        'template' => 'required|in:' . implode(',', array_keys($templates)),
        'notification_title_sw' => 'required|string|max:100',
        'notification_body_sw' => 'required|string|max:255',
        'notification_title_en' => 'required|string|max:100',
        'notification_body_en' => 'required|string|max:255',
    ]);

    $notification_title_sw = trim((string) $_POST['notification_title_sw']);
    $notification_body_sw = trim((string) $_POST['notification_body_sw']);
    $notification_title_en = trim((string) $_POST['notification_title_en']);
    $notification_body_en = trim((string) $_POST['notification_body_en']);
    $translations = [
        'sw' => ['title' => $notification_title_sw, 'body' => $notification_body_sw],
        'en' => ['title' => $notification_title_en, 'body' => $notification_body_en],
    ];
    $recipient_count = $notification_model->notifyActiveUsers($notification_title_sw, $notification_body_sw, $translations);
    (new AuditLog(Database::instance()))->record(
        'admin',
        (int) $current_admin['admin_id'],
        'notification.broadcast.sent',
        'notification_broadcast',
        null,
        null,
        [
            'recipient_count' => $recipient_count,
            'notification_title_sw' => $notification_title_sw,
            'notification_body_sw' => $notification_body_sw,
            'notification_title_en' => $notification_title_en,
            'notification_body_en' => $notification_body_en,
        ]
    );

    Session::flash('success', "Notification saved for {$recipient_count} active customers. Push delivery is queued in batches.");
    redirect(url('admin/notification_broadcast.php'));
});

$errors = $form_error?->fields() ?? [];
$form = adminFormValues($form_error, $saved_values);
$selected_template = isset($templates[$form['template'] ?? '']) ? (string) $form['template'] : 'welcome';
$recipient_count = $notification_model->countActiveUsers();
$firebase_configured = $sender->isConfigured();
$page_title = 'Customer Broadcast';
$active_menu = 'notification_broadcast';
$page_scripts = ['notification_broadcast.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions mb-4 d-flex justify-content-between align-items-center">
    <p class="text-muted mb-0">Send an announcement to active customer accounts.</p>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/push_queue.php')) ?>"><i class="bi bi-broadcast"></i> Push Queue</a>
</div>

<section class="admin-panel mb-4" aria-labelledby="broadcast-heading">
    <div class="admin-panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 mb-0" id="broadcast-heading"><i class="bi bi-megaphone text-chimbo me-2"></i>New customer announcement</h2>
        <span class="badge text-bg-light"><?= number_format($recipient_count) ?> active customers</span>
    </div>
    <div class="admin-panel-body">
        <p class="text-muted">Choose a starting message, then edit the title and text before sending. Each active customer gets an in-app notification; customers with registered devices also receive a push notification.</p>
        <?php if (!$firebase_configured): ?>
            <div class="alert alert-warning" role="status">Firebase push is not configured. Set <code>FCM_SERVICE_ACCOUNT_FILE</code> before sending.</div>
        <?php endif; ?>

        <form method="post" data-confirm="Send this announcement to all <?= e(number_format($recipient_count)) ?> active customers? It cannot be recalled.">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="broadcast-template">Message template</label>
                <select class="form-select" id="broadcast-template" name="template" data-broadcast-template>
                    <?php foreach ($templates as $template_key => $template): ?>
                    <option value="<?= e($template_key) ?>"
                            data-title-sw="<?= e($template['sw']['title']) ?>" data-body-sw="<?= e($template['sw']['body']) ?>"
                            data-title-en="<?= e($template['en']['title']) ?>" data-body-en="<?= e($template['en']['body']) ?>"
                            <?= $selected_template === $template_key ? 'selected' : '' ?>><?= e($template['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Changing the template fills the fields. Your edits are preserved until you choose another template.</div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="notification-title-sw">Kiswahili title</label>
                <input class="form-control<?= adminInvalidClass($errors, 'notification_title_sw') ?>" type="text" id="notification-title-sw" name="notification_title_sw"
                       maxlength="100" required value="<?= e($form['notification_title_sw'] ?? '') ?>" data-broadcast-title-sw>
                <?= adminFieldError($errors, 'notification_title_sw') ?>
            </div>

            <div class="mb-3">
                <label class="form-label" for="notification-body-sw">Kiswahili message</label>
                <textarea class="form-control<?= adminInvalidClass($errors, 'notification_body_sw') ?>" id="notification-body-sw" name="notification_body_sw"
                          rows="3" maxlength="255" required data-broadcast-body-sw><?= e($form['notification_body_sw'] ?? '') ?></textarea>
                <div class="d-flex justify-content-between gap-3">
                    <?= adminFieldError($errors, 'notification_body_sw') ?>
                    <small class="form-text ms-auto"><span data-broadcast-length-sw>0</span>/255 characters</small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="notification-title-en">English title</label>
                <input class="form-control<?= adminInvalidClass($errors, 'notification_title_en') ?>" type="text" id="notification-title-en" name="notification_title_en"
                       maxlength="100" required value="<?= e($form['notification_title_en'] ?? '') ?>" data-broadcast-title-en>
                <?= adminFieldError($errors, 'notification_title_en') ?>
            </div>

            <div class="mb-4">
                <label class="form-label" for="notification-body-en">English message</label>
                <textarea class="form-control<?= adminInvalidClass($errors, 'notification_body_en') ?>" id="notification-body-en" name="notification_body_en"
                          rows="3" maxlength="255" required data-broadcast-body-en><?= e($form['notification_body_en'] ?? '') ?></textarea>
                <div class="d-flex justify-content-between gap-3">
                    <?= adminFieldError($errors, 'notification_body_en') ?>
                    <small class="form-text ms-auto"><span data-broadcast-length-en>0</span>/255 characters</small>
                </div>
            </div>

            <div class="border rounded-3 bg-light-subtle p-3 mb-4" aria-live="polite">
                <div class="small text-muted mb-1">Kiswahili preview</div>
                <div class="fw-semibold" data-broadcast-preview-title-sw><?= e($form['notification_title_sw'] ?? '') ?></div>
                <div class="text-muted" data-broadcast-preview-body-sw><?= e($form['notification_body_sw'] ?? '') ?></div>
                <hr>
                <div class="small text-muted mb-1">English preview</div>
                <div class="fw-semibold" data-broadcast-preview-title-en><?= e($form['notification_title_en'] ?? '') ?></div>
                <div class="text-muted" data-broadcast-preview-body-en><?= e($form['notification_body_en'] ?? '') ?></div>
            </div>

            <button class="btn btn-chimbo" type="submit" <?= (!$firebase_configured || $recipient_count === 0) ? 'disabled' : '' ?>>
                <i class="bi bi-send me-1"></i> Queue announcement for <?= number_format($recipient_count) ?> customers
            </button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
