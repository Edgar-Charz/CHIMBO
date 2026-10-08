<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The rows are loaded by the table itself from ajax/audit_log.php, newest first
$current_admin = AdminSession::requireLogin('audit.view');
$database      = Database::instance();
$audit_log     = new AuditLog($database);

$staff_members = (new AdminUser($database))->listAdmins();
$actions       = $audit_log->getActions();
$entity_types  = $audit_log->getEntityTypes();
$actor_types   = ['admin' => 'Staff', 'customer' => 'Customer', 'system' => 'System'];

$page_title  = 'Audit log';
$active_menu = 'audit_log';
require __DIR__ . '/includes/header.php';
?>

<p class="text-muted">Every change made by staff, customers and the system: who did it, when, and what changed.</p>

<form class="admin-panel filter-card" id="audit-filters">
    <div class="filter-grid">
        <div>
            <label class="form-label" for="filter-admin">Staff member</label>
            <select class="form-select" id="filter-admin" name="admin_id">
                <option value="">Anyone</option>
                <?php foreach ($staff_members as $staff_member): ?>
                    <option value="<?= e($staff_member['admin_id']) ?>"><?= e($staff_member['admin_full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-actor-type">Done by</label>
            <select class="form-select" id="filter-actor-type" name="actor_type">
                <option value="">Staff, customers and system</option>
                <?php foreach ($actor_types as $actor_type => $actor_name): ?>
                    <option value="<?= e($actor_type) ?>"><?= e($actor_name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-action">Action</label>
            <select class="form-select" id="filter-action" name="action">
                <option value="">All actions</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= e($action) ?>"><?= e($action) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-entity-type">What changed</label>
            <select class="form-select" id="filter-entity-type" name="entity_type">
                <option value="">Anything</option>
                <?php foreach ($entity_types as $entity_type): ?>
                    <option value="<?= e($entity_type) ?>"><?= e(ucfirst(str_replace('_', ' ', $entity_type))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-entity-id">Record number</label>
            <input class="form-control" type="number" min="1" id="filter-entity-id" name="entity_id" placeholder="e.g. 12">
        </div>
        <div>
            <label class="form-label" for="filter-date-from">From</label>
            <input class="form-control" type="date" id="filter-date-from" name="date_from">
        </div>
        <div>
            <label class="form-label" for="filter-date-to">To</label>
            <input class="form-control" type="date" id="filter-date-to" name="date_to">
        </div>
        <div class="filter-actions">
            <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-x-lg"></i> Clear</button>
        </div>
    </div>
</form>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable-source="<?= e(url('admin/ajax/audit_log.php')) ?>"
           data-filter-form="#audit-filters" data-title="Changes" data-icon="bi-journal-text"
           data-ordering="false" data-searching="false" data-page-length="<?= e(AuditLog::PER_PAGE_OPTIONS[1]) ?>"
           data-length-menu="<?= e(json_encode(AuditLog::PER_PAGE_OPTIONS)) ?>" data-empty-message="Nothing logged for these filters.">
        <thead>
            <tr>
                <th data-column="when" data-cell-class="text-nowrap">When</th>
                <th data-column="who">Who</th>
                <th data-column="action">Action</th>
                <th data-column="record" data-cell-class="text-nowrap">What</th>
                <th data-column="changes">Changes</th>
                <th data-column="ip" data-cell-class="text-nowrap small text-muted">IP address</th>
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
