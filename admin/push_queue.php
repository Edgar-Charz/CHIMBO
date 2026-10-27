<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('settings.manage');
$db = Database::instance();
$sender = new FcmPushSender();
$queue = new NotificationPushQueue($db);

$statuses = ['pending', 'processing', 'sent', 'failed'];
$requested_status = $_GET['status'] ?? '';
$status_filter = is_string($requested_status) && in_array($requested_status, $statuses, true) ? $requested_status : '';
$per_page_options = [25, 50, 100];
$requested_per_page_value = $_GET['per_page'] ?? 25;
$requested_per_page = is_scalar($requested_per_page_value) ? (int) $requested_per_page_value : 25;
$per_page = in_array($requested_per_page, $per_page_options, true) ? $requested_per_page : 25;
$requested_page = $_GET['page'] ?? 1;
$page_number = max(1, is_scalar($requested_page) ? (int) $requested_page : 1);
$requested_search = $_GET['search'] ?? '';
$search_query = is_string($requested_search) ? mb_substr(trim($requested_search), 0, 100) : '';

$form_error = adminHandleForm(function (string $form_action) use ($db, $queue, $sender, $status_filter, $per_page, $page_number, $search_query): void {
    if ($form_action === 'retry_single') {
        $id = (int) ($_POST['notification_push_id'] ?? 0);
        $requeued = $db->execute(
            "UPDATE notification_push_queue 
             SET push_status = 'pending', push_next_attempt_at = UTC_TIMESTAMP(), push_locked_until = NULL 
             WHERE notification_push_id = :id AND push_status = 'failed'",
            ['id' => $id]
        );
        if ($requeued > 0) {
            $queue->processOne($id, $sender);
            Session::flash('success', "Failed notification #{$id} was retried.");
        } else {
            Session::flash('error', 'This notification is no longer failed, so it was not sent again.');
        }
    } elseif ($form_action === 'retry_all_failed') {
        $db->execute(
            "UPDATE notification_push_queue 
             SET push_status = 'pending', push_next_attempt_at = UTC_TIMESTAMP(), push_locked_until = NULL 
             WHERE push_status = 'failed'"
        );
        $processed = $queue->process($sender);
        Session::flash('success', "All failed notifications were re-queued; {$processed} push attempts were processed now.");
    } elseif ($form_action === 'process_queue') {
        $processed = $queue->process($sender);
        Session::flash('success', "Queue processed: {$processed} messages attempted.");
    }
    redirect(url('admin/push_queue.php?' . http_build_query([
        'status' => $status_filter ?: null,
        'per_page' => $per_page,
        'page' => $page_number,
        'search' => $search_query ?: null,
    ])));
});

// Summary Counts
$counts = $db->fetchOne("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(push_status = 'pending'), 0) AS pending_count,
        COALESCE(SUM(push_status = 'processing'), 0) AS processing_count,
        COALESCE(SUM(push_status = 'sent'), 0) AS sent_count,
        COALESCE(SUM(push_status = 'failed'), 0) AS failed_count
    FROM notification_push_queue
");

$where_conditions = [];
$query_params = [];
if ($status_filter !== '') {
    $where_conditions[] = 'q.push_status = :push_status';
    $query_params['push_status'] = $status_filter;
}
if ($search_query !== '') {
    $where_conditions[] = '(CAST(q.notification_push_id AS CHAR) LIKE :search_id'
        . ' OR u.user_phone LIKE :search_phone'
        . ' OR n.notification_title LIKE :search_title'
        . ' OR n.notification_body LIKE :search_body'
        . ' OR q.push_last_error LIKE :search_error)';
    $search_value = '%' . $search_query . '%';
    $query_params += [
        'search_id' => $search_value,
        'search_phone' => $search_value,
        'search_title' => $search_value,
        'search_body' => $search_value,
        'search_error' => $search_value,
    ];
}
$where_sql = $where_conditions === [] ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
$filtered_count = (int) $db->fetchValue(
    "SELECT COUNT(*)
     FROM notification_push_queue q
     INNER JOIN notifications n ON n.notification_id = q.notification_id
     LEFT JOIN users u ON u.user_id = q.user_id
     {$where_sql}",
    $query_params
);
$last_page = max(1, (int) ceil($filtered_count / $per_page));
$page_number = min($page_number, $last_page);
$offset = ($page_number - 1) * $per_page;
$page_link = static fn (int $number): string => url('admin/push_queue.php?' . http_build_query([
    'status' => $status_filter ?: null,
    'per_page' => $per_page,
    'page' => $number,
    'search' => $search_query ?: null,
]));

$jobs = $db->fetchAll("
    SELECT q.notification_push_id, q.push_status, q.push_attempts, q.push_last_error,
           q.push_next_attempt_at, q.push_locked_until, q.created_at, n.notification_title, n.notification_body,
           u.user_phone, u.user_id
    FROM notification_push_queue q
    INNER JOIN notifications n ON n.notification_id = q.notification_id
    LEFT JOIN users u ON u.user_id = q.user_id
    {$where_sql}
    ORDER BY q.notification_push_id DESC
    LIMIT :per_page OFFSET :offset
", $query_params + ['per_page' => $per_page, 'offset' => $offset]);

$page_title = 'Push Notification Queue';
$active_menu = 'push_queue';
$page_scripts = ['push_queue.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions mb-4 d-flex justify-content-between align-items-center">
    <div>
        <span class="badge bg-warning text-dark me-2">Pending: <?= (int)$counts['pending_count'] ?></span>
        <span class="badge bg-danger me-2">Failed: <?= (int)$counts['failed_count'] ?></span>
        <span class="badge bg-success me-2">Sent: <?= (int)$counts['sent_count'] ?></span>
    </div>
    <div class="d-flex gap-2">
        <form method="post" class="d-inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="retry_all_failed">
            <button type="submit" class="btn btn-outline-danger btn-sm"
                    data-confirm="<?= e('Retry all ' . (int) $counts['failed_count'] . ' failed notifications now?') ?>"
                    <?= (int)$counts['failed_count'] === 0 ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-clockwise"></i> Retry All Failed
            </button>
        </form>
        <form method="post" class="d-inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="process_queue">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-send"></i> Process Queue Now
            </button>
        </form>
    </div>
</div>

<form class="admin-panel push-queue-filters p-3 mb-3 d-flex flex-wrap align-items-end gap-3" method="get">
    <div class="push-queue-filters-search">
        <label class="form-label mb-1" for="push-search">Search queue</label>
        <input class="form-control" type="search" id="push-search" name="search" value="<?= e($search_query) ?>"
               maxlength="100" placeholder="Customer, notification, error or ID">
    </div>
    <div class="push-queue-filters-status">
        <label class="form-label mb-1" for="push-status-filter">Status</label>
        <select class="form-select" id="push-status-filter" name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($status_filter !== '' || $search_query !== ''): ?>
        <a class="btn btn-link" href="<?= e(url('admin/push_queue.php?per_page=' . $per_page)) ?>">Clear</a>
    <?php endif; ?>
    <span class="small text-muted ms-auto"><?= e($filtered_count) ?> matching notifications</span>
</form>

<div class="admin-panel admin-table-panel">
    <h2 class="table-card-title mb-3"><i class="bi bi-broadcast-pin"></i> Push delivery attempts</h2>
    <div class="table-responsive">
        <table class="table admin-table w-100 table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Title & Body</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Error / Info</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">No notifications in queue.</td></tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>#<?= (int)$job['notification_push_id'] ?></td>
                            <td><?= e($job['user_phone'] ?? ('User #' . $job['user_id'])) ?></td>
                            <td>
                                <strong><?= e($job['notification_title']) ?></strong><br>
                                <small class="text-muted"><?= e(mb_strimwidth($job['notification_body'], 0, 50, '...')) ?></small>
                            </td>
                            <td>
                                <?php if ($job['push_status'] === 'sent'): ?>
                                    <span class="badge bg-success">Sent</span>
                                <?php elseif ($job['push_status'] === 'failed'): ?>
                                    <span class="badge bg-danger">Failed</span>
                                <?php elseif ($job['push_status'] === 'processing'): ?>
                                    <span class="badge bg-info">Processing</span>
                                    <?php if ($job['push_locked_until']): ?>
                                        <div class="small text-muted mt-1">Locked until <?= e(adminDateTime($job['push_locked_until'])) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                    <div class="small text-muted mt-1">
                                        <?= $job['push_next_attempt_at'] <= gmdate('Y-m-d H:i:s') ? 'Ready to send' : 'Next try ' . e(adminDateTime($job['push_next_attempt_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$job['push_attempts'] ?></td>
                            <td>
                                <?php if ($job['push_last_error']): ?>
                                    <span class="text-danger small" title="<?= e($job['push_last_error']) ?>"><?= e(mb_strimwidth($job['push_last_error'], 0, 30, '...')) ?></span>
                                <?php else: ?><span class="text-muted small">-</span><?php endif; ?>
                            </td>
                            <td class="small text-nowrap"><?= e(adminDateTime($job['created_at'])) ?></td>
                            <td>
                                <?php if ($job['push_status'] === 'failed'): ?>
                                    <form method="post" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="form_action" value="retry_single">
                                        <input type="hidden" name="notification_push_id" value="<?= (int)$job['notification_push_id'] ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm"
                                                data-confirm="Retry failed notification #<?= (int)$job['notification_push_id'] ?>?">
                                            <i class="bi bi-arrow-repeat"></i> Retry
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="small text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $first_job = $filtered_count === 0 ? 0 : $offset + 1;
    $last_job = min($offset + $per_page, $filtered_count);
    $page_window_start = max(1, $page_number - 2);
    $page_window_end = min($last_page, $page_number + 2);
    ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pt-3 mt-3 border-top">
        <form class="d-flex align-items-center gap-2" method="get" data-push-page-size>
            <?php if ($status_filter !== ''): ?><input type="hidden" name="status" value="<?= e($status_filter) ?>"><?php endif; ?>
            <?php if ($search_query !== ''): ?><input type="hidden" name="search" value="<?= e($search_query) ?>"><?php endif; ?>
            <label class="small text-muted text-nowrap" for="push-per-page">Show per page:</label>
            <select class="form-select form-select-sm" id="push-per-page" name="per_page">
                <?php foreach ($per_page_options as $option): ?>
                    <option value="<?= e($option) ?>" <?= $per_page === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="d-flex flex-wrap align-items-center gap-3 ms-auto">
            <span class="small text-muted text-nowrap">
                <?= $filtered_count === 0 ? '0 items' : e($first_job) . '–' . e($last_job) . ' of ' . e($filtered_count) . ' items' ?>
            </span>
            <nav aria-label="Push queue pages">
                <ul class="pagination">
                    <li class="page-item <?= $page_number === 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($page_link(1)) ?>" aria-label="First page"><i class="bi bi-chevron-double-left"></i></a>
                    </li>
                    <li class="page-item <?= $page_number === 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($page_link(max(1, $page_number - 1))) ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php for ($visible_page = $page_window_start; $visible_page <= $page_window_end; $visible_page++): ?>
                        <li class="page-item <?= $visible_page === $page_number ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($page_link($visible_page)) ?>" <?= $visible_page === $page_number ? 'aria-current="page"' : '' ?>><?= e($visible_page) ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page_number === $last_page ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($page_link(min($last_page, $page_number + 1))) ?>" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                    </li>
                    <li class="page-item <?= $page_number === $last_page ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($page_link($last_page)) ?>" aria-label="Last page"><i class="bi bi-chevron-double-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
