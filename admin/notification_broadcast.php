<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('settings.manage');
$database = Database::instance();
$notification_model = new Notification($database);
$sender = new FcmPushSender();
$templates = [
    'welcome' => [
        'label' => 'Karibu CHIMBO',
        'title' => 'Karibu CHIMBO! 🎉',
        'body' => 'CHIMBO sasa iko wazi! Fungua app, vinjari bidhaa za jumla na tuma oda yako leo.',
    ],
    'new_products' => [
        'label' => 'Bidhaa mpya',
        'title' => 'Bidhaa mpya zimewasili ✨',
        'body' => 'Tumeongeza bidhaa mpya CHIMBO. Fungua app uzione na uagize unachohitaji.',
    ],
    'special_news' => [
        'label' => 'Taarifa maalum',
        'title' => 'Tuna taarifa mpya kwa ajili yako 📣',
        'body' => 'Fungua app ya CHIMBO kuona taarifa yetu mpya.',
    ],
    'come_back' => [
        'label' => 'Karibu tena',
        'title' => 'CHIMBO inakukumbuka 💚',
        'body' => 'Bidhaa zako za jumla zinakusubiri. Fungua app ya CHIMBO uendelee kununua.',
    ],
];
$saved_values = [
    'template' => 'welcome',
    'notification_title' => $templates['welcome']['title'],
    'notification_body' => $templates['welcome']['body'],
];

$form_error = adminHandleForm(function () use ($current_admin, $notification_model, $sender, $templates): void {
    if (!$sender->isConfigured()) {
        Session::flash('error', 'Firebase push is not configured. Set FCM_SERVICE_ACCOUNT_FILE before sending a broadcast.');
        redirect(url('admin/notification_broadcast.php'));
    }

    Validator::validate($_POST, [
        'template' => 'required|in:' . implode(',', array_keys($templates)),
        'notification_title' => 'required|string|max:100',
        'notification_body' => 'required|string|max:255',
    ]);

    $notification_title = trim((string) $_POST['notification_title']);
    $notification_body = trim((string) $_POST['notification_body']);
    $recipient_count = $notification_model->notifyActiveUsers($notification_title, $notification_body);
    (new AuditLog(Database::instance()))->record(
        'admin',
        (int) $current_admin['admin_id'],
        'notification.broadcast.sent',
        'notification_broadcast',
        null,
        null,
        [
            'recipient_count' => $recipient_count,
            'notification_title' => $notification_title,
            'notification_body' => $notification_body,
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
                        <option value="<?= e($template_key) ?>" data-title="<?= e($template['title']) ?>" data-body="<?= e($template['body']) ?>"
                            <?= $selected_template === $template_key ? 'selected' : '' ?>><?= e($template['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Changing the template fills the fields. Your edits are preserved until you choose another template.</div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="notification-title">Notification title</label>
                <input class="form-control<?= adminInvalidClass($errors, 'notification_title') ?>" type="text" id="notification-title" name="notification_title"
                       maxlength="100" required value="<?= e($form['notification_title'] ?? '') ?>" data-broadcast-title>
                <?= adminFieldError($errors, 'notification_title') ?>
            </div>

            <div class="mb-4">
                <label class="form-label" for="notification-body">Message</label>
                <textarea class="form-control<?= adminInvalidClass($errors, 'notification_body') ?>" id="notification-body" name="notification_body"
                          rows="4" maxlength="255" required data-broadcast-body><?= e($form['notification_body'] ?? '') ?></textarea>
                <div class="d-flex justify-content-between gap-3">
                    <?= adminFieldError($errors, 'notification_body') ?>
                    <small class="form-text ms-auto"><span data-broadcast-length>0</span>/255 characters</small>
                </div>
            </div>

            <div class="border rounded-3 bg-light-subtle p-3 mb-4" aria-live="polite">
                <div class="small text-muted mb-1">Preview</div>
                <div class="fw-semibold" data-broadcast-preview-title><?= e($form['notification_title'] ?? '') ?></div>
                <div class="text-muted" data-broadcast-preview-body><?= e($form['notification_body'] ?? '') ?></div>
            </div>

            <button class="btn btn-chimbo" type="submit" <?= (!$firebase_configured || $recipient_count === 0) ? 'disabled' : '' ?>>
                <i class="bi bi-send me-1"></i> Queue announcement for <?= number_format($recipient_count) ?> customers
            </button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
