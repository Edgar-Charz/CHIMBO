<?php

/**
 * The server's messages for admin/assets/dialogs.js to show as SweetAlert popups. Expects $flash_messages:
 * [['type' => 'success' | 'error', 'text' => …, 'title' => … or null], …]
 */

foreach ($flash_messages as $flash): ?>
    <div hidden data-flash-message="<?= e($flash['type']) ?>"<?= $flash['title'] ? ' data-flash-title="' . e($flash['title']) . '"' : '' ?>><?= e($flash['text']) ?></div>
<?php endforeach; ?>
