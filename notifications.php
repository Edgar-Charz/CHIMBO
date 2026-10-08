<?php

/**
 * Arifa — the bell: order updates (shared with the app), newest first, unread ones highlighted.
 * Tapping one marks it read and opens its order; "Soma zote" marks everything read (assets/js/notifications.js).
 */

require __DIR__ . '/includes/init.php';

const NOTIFICATION_ICONS = ['order_status' => 'box-seam', 'payment' => 'wallet2'];

$customer = requireCustomer();
$page_number = requestInt('page') ?? 1;
$notifications = (new Notification(Database::instance()))->getNotifications($customer['user_id'], ['page' => $page_number]);
$last_page = max(1, (int) ceil($notifications['total'] / $notifications['per_page']));
$has_unread = in_array(false, array_column($notifications['items'], 'is_read'), true);

$page = [
    'title'   => 'Arifa',
    'nav'     => 'account',
    'scripts' => ['notifications.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl notifications-page">
    <div class="notifications-page__heading">
        <h1 class="page-title">Arifa</h1>
        <?php if ($has_unread) : ?>
            <button class="btn btn-link" type="button" data-read-all><i class="bi bi-check2-all" aria-hidden="true"></i> Soma zote</button>
        <?php endif; ?>
    </div>

    <?php if ($notifications['items'] === []) : ?>
        <div class="state-block">
            <span class="state-block__icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
            <h2 class="state-block__title">Hakuna arifa bado</h2>
            <p class="state-block__text">Tutakujulisha hapa kila oda yako inapopiga hatua.</p>
            <a class="btn btn-primary" href="<?= e(url('')) ?>">Endelea kununua</a>
        </div>
    <?php else : ?>
        <ul class="notification-list">
            <?php foreach ($notifications['items'] as $notification) : ?>
                <?php $order_id = $notification['notification_data']['order_id'] ?? null; ?>
                <li>
                    <a class="notification-item<?= $notification['is_read'] ? '' : ' is-unread' ?>"
                        href="<?= e($order_id === null ? url('orders.php') : url('order.php?id=' . $order_id)) ?>"
                        data-notification-id="<?= e($notification['notification_id']) ?>">
                        <span class="notification-item__icon" aria-hidden="true"><i class="bi bi-<?= e(NOTIFICATION_ICONS[$notification['notification_type']] ?? 'bell') ?>"></i></span>
                        <span class="notification-item__body">
                            <strong class="notification-item__title"><?= e($notification['notification_title']) ?></strong>
                            <span class="notification-item__text"><?= e($notification['notification_body']) ?></span>
                            <span class="notification-item__time"><?= e(timeAgo($notification['created_at'])) ?></span>
                        </span>
                        <?php if (!$notification['is_read']) : ?>
                            <span class="notification-item__dot"><span class="visually-hidden">Haijasomwa</span></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($last_page > 1) : ?>
            <nav class="orders-pager" aria-label="Kurasa za arifa">
                <?php if ($page_number > 1) : ?>
                    <a class="btn btn-outline-primary" href="<?= e(url('notifications.php?page=' . ($page_number - 1))) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Mpya zaidi</a>
                <?php endif; ?>
                <span>Ukurasa <?= e($page_number) ?> kati ya <?= e($last_page) ?></span>
                <?php if ($page_number < $last_page) : ?>
                    <a class="btn btn-outline-primary" href="<?= e(url('notifications.php?page=' . ($page_number + 1))) ?>">Za zamani <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
