<?php

/**
 * A list of staff notifications: icon, title, the text (long text cut with "Read more"), how long ago, unread dot.
 * Each one opens notification_open.php, which marks it read and goes to its page.
 * Expects $notifications (AdminNotification::getLatest() / search() items).
 */

$short_text_length = 110;   // characters shown before "Read more"
?>
<ul class="notification-list">
    <?php foreach ($notifications as $notification): ?>
        <?php
        $body       = $notification['body'];
        $is_long    = mb_strlen($body) > $short_text_length;
        $open_link  = url('admin/notification_open.php?id=' . $notification['admin_notification_id']);
        ?>
        <li class="notification-item<?= $notification['is_read'] ? '' : ' is-unread' ?>">
            <span class="notification-item__icon"><i class="bi <?= e($notification['icon']) ?>"></i></span>
            <div class="notification-item__text">
                <a class="notification-item__title" href="<?= e($open_link) ?>"><?= e($notification['title']) ?></a>
                <p class="notification-item__body mb-0">
                    <?php if ($is_long): ?>
                        <span data-short-text><?= e(mb_substr($body, 0, $short_text_length)) ?>…</span>
                        <span data-full-text hidden><?= e($body) ?></span>
                        <button class="btn btn-link btn-sm p-0 align-baseline" type="button" data-read-more>Read more</button>
                    <?php else: ?>
                        <?= e($body) ?>
                    <?php endif; ?>
                </p>
                <span class="notification-item__time"><?= e(adminTimeAgo($notification['created_at'])) ?></span>
            </div>
            <?php if (!$notification['is_read']): ?><span class="notification-item__dot" title="Unread"></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
