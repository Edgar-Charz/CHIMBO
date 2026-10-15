<?php

/**
 * What the bell shows (loaded into the modal by admin.js from ajax/notifications.php): "Waiting for you" (work to do
 * now) and the 10 latest notifications. Expects $attention_items and $notifications.
 */
?>
<?php if ($attention_items !== []): ?>
    <div class="notification-attention">
        <div class="notification-section-title">Waiting for you</div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($attention_items as [$count, $label, $icon, $link]): ?>
                <a class="attention-bar__item" href="<?= e($link) ?>"><i class="bi <?= e($icon) ?>"></i> <strong><?= e(number_format($count)) ?></strong> <?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="notification-section-title px-3 pt-3">Latest</div>
<?php if ($notifications === []): ?>
    <p class="text-muted px-3 pb-3 mb-0">No notifications yet. New orders, payments to check, low stock and more will appear here.</p>
<?php else: ?>
    <?php require __DIR__ . '/notification_list.php'; ?>
<?php endif; ?>
