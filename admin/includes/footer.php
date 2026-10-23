<?php

/** Admin layout — bottom part. Closes what header.php opened. */
?>
</main>
</div>
</div>

<!-- The bell's dialog: filled by admin.js from ajax/notifications.php each time it opens -->
<div class="modal fade" id="notifications-modal" tabindex="-1" aria-labelledby="notifications-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-end">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="notifications-modal-title"><i class="bi bi-bell"></i> Notifications</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" data-notification-panel>
                <p class="text-muted p-3 mb-0">Loading…</p>
            </div>
            <div class="modal-footer justify-content-between">
                <form method="post" action="<?= e(url('admin/notifications.php')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return_to" value="<?= e(AdminNotification::safeLink(basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''))) ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="form_action" value="mark_all_read"><i class="bi bi-check2-all"></i> Mark all as read</button>
                </form>
                <a class="btn btn-sm btn-chimbo" href="<?= e(url('admin/notifications.php')) ?>">View all</a>
            </div>
        </div>
    </div>
</div>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(adminAsset('vendor/datatables/jquery.min.js')) ?>"></script>
<script src="<?= e(adminAsset('vendor/datatables/dataTables.min.js')) ?>"></script>
<script src="<?= e(adminAsset('vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(adminAsset('dialogs.js')) ?>"></script>
<script src="<?= e(adminAsset('admin.js')) ?>"></script>
<script src="<?= e(adminAsset('pwa.js')) ?>"></script>
<?php foreach ($page_scripts ?? [] as $page_script): // a page's own script, e.g. $page_scripts = ['order_create.js'] ?>
    <script src="<?= e(adminAsset($page_script)) ?>"></script>
<?php endforeach; ?>
</body>

</html>
