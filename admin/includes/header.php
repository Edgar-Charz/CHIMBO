<?php

/**
 * Admin layout — top part (sidebar + top bar). Close it with footer.php.
 *
 * The page must set before including this file:
 *   $page_title     e.g. 'Dashboard'
 *   $active_menu    the sidebar item to highlight, e.g. 'dashboard'
 *   $current_admin  from AdminSession::requireLogin()
 */

// Payments customers sent that staff still have to check (the badge on the Payments menu item)
$payments_waiting = Admin::can($current_admin, 'payments.manage') ? (new Payment(Database::instance()))->countWaitingForReview() : 0;
$unread_notifications = (new AdminNotification(Database::instance()))->countUnread($current_admin);   // the bell's number
$has_waiting_work     = adminAttentionItems($current_admin) !== [];   // the bell's orange dot when nothing is unread
$can_global_search    = Admin::can($current_admin, 'orders.view')
    || Admin::can($current_admin, 'customers.view')
    || Admin::can($current_admin, 'products.manage');

// Messages from the previous action, shown as SweetAlert popups (dialogs.js)
$flash_messages = array_values(array_filter([
    ['type' => 'success', 'text' => Session::takeFlash('success'), 'title' => Session::takeFlash('success_title')],
    ['type' => 'error',   'text' => Session::takeFlash('error'),   'title' => null],
], fn (array $flash): bool => $flash['text'] !== null));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · CHIMBO Admin</title>
    <?php require __DIR__ . '/favicon.php'; ?>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(adminAsset('vendor/datatables/dataTables.bootstrap5.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(adminAsset('admin.css')) ?>">
</head>

<body data-admin-id="<?= e($current_admin['admin_id']) ?>">
    <div class="admin-layout">

        <!-- Sidebar: fixed on large screens, a slide-in menu on phones -->
        <aside class="admin-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="adminSidebar">
            <div class="admin-sidebar-brand">
                <span class="brand-green">CHI</span><span class="brand-orange">MBO</span>
                <small>Admin</small>
            </div>
            <nav class="nav flex-column">
                <?php foreach (adminVisibleMenu($current_admin) as $group_label => $menu_items): ?>
                    <?php if ($group_label !== ''): ?>
                        <div class="admin-sidebar-group"><?= e($group_label) ?></div>
                    <?php endif; ?>
                    <?php foreach ($menu_items as $menu_key => [$label, $icon, $page]): ?>
                        <a class="nav-link <?= $menu_key === $active_menu ? 'active' : '' ?>" href="<?= e(url('admin/' . $page)) ?>">
                            <i class="bi <?= e($icon) ?>"></i> <?= e($label) ?>
                            <?php if ($menu_key === 'payments' && $payments_waiting > 0): ?>
                                <span class="menu-badge" title="Payments waiting for review"><?= e($payments_waiting) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="admin-main">
            <!-- Top bar -->
            <header class="admin-topbar">
                <button class="btn btn-link d-lg-none text-dark" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-label="Open menu">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h1 class="admin-page-title"><?= e($page_title) ?></h1>

                <?php if ($can_global_search): ?>
                    <div class="admin-global-search" data-admin-global-search data-search-url="<?= e(url('admin/ajax/global_search.php')) ?>">
                        <form role="search" data-global-search-form autocomplete="off">
                            <label class="visually-hidden" for="admin-global-search">Search orders, customers and products</label>
                            <div class="admin-global-search__field">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input id="admin-global-search" class="form-control" type="search" name="q" maxlength="100"
                                       placeholder="Search orders, customers, products" aria-controls="admin-global-search-results"
                                       aria-expanded="false" aria-autocomplete="list">
                                <kbd>/</kbd>
                            </div>
                        </form>
                        <div class="admin-global-search__results" id="admin-global-search-results" role="listbox"
                             aria-label="Search results" aria-live="polite" hidden></div>
                    </div>
                <?php endif; ?>

                <!-- The bell: "Waiting for you" + the latest notifications in a dialog (admin.js loads it when opened) -->
                <button class="btn btn-light notification-bell" type="button" data-bs-toggle="modal" data-bs-target="#notifications-modal"
                        aria-label="Notifications" title="Notifications" data-notification-bell
                        data-panel-url="<?= e(url('admin/ajax/notifications.php?part=panel')) ?>"
                        data-count-url="<?= e(url('admin/ajax/notifications.php?part=count')) ?>">
                    <i class="bi bi-bell"></i>
                    <span class="notification-bell__badge" data-notification-count <?= $unread_notifications > 0 ? '' : 'hidden' ?>><?= e($unread_notifications > 99 ? '99+' : $unread_notifications) ?></span>
                    <span class="notification-bell__dot" data-notification-dot title="Work is waiting for you" <?= $unread_notifications === 0 && $has_waiting_work ? '' : 'hidden' ?>></span>
                </button>

                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= e($current_admin['admin_full_name']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted"><?= e(Admin::ROLE_NAMES[$current_admin['admin_role']] ?? '') ?></span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <!-- Logout is a POST form with a CSRF token, so other sites cannot log the admin out -->
                            <form method="post" action="<?= e(url('admin/logout.php')) ?>">
                                <?= Csrf::field() ?>
                                <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right"></i> Log out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </header>

            <main class="admin-content">
                <?php require __DIR__ . '/flash_messages.php'; ?>
