<?php

/**
 * A whole placeholder page for an admin module that is not built yet.
 *
 * The page sets before including this file:
 *   $active_menu       its menu key in ADMIN_MENU, e.g. 'orders' (title, icon and permission come from there)
 *   $planned_phase     when it will be built, e.g. 'Phase 4'
 *   $planned_features  what the page will do (list of short sentences)
 */

[$page_title, $page_icon, , $permission] = adminMenuItem($active_menu);
$current_admin = AdminSession::requireLogin($permission);

require __DIR__ . '/header.php';
?>

<div class="admin-panel">
    <div class="admin-empty-state">
        <i class="bi <?= e($page_icon) ?>"></i>
        <h2 class="h5 mt-3 mb-1 text-dark"><?= e($page_title) ?> is coming soon</h2>
        <p class="mb-3">Planned for <?= e($planned_phase) ?>. This page will let you:</p>
        <ul class="admin-planned-list">
            <?php foreach ($planned_features as $feature): ?>
                <li><i class="bi bi-check2"></i> <?= e($feature) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
