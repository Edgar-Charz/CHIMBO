<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('dashboard.view');
$dashboard     = new Dashboard(Database::instance());

$can_see_money = Admin::can($current_admin, 'reports.view');

// The tabs: key => [label, icon, permission needed]; each is a file in includes/dashboard/. Only the tabs this staff
// member may see are shown. Without ?tab= the page opens on the most useful tab for their role.
const DASHBOARD_TABS = [
    'overview'  => ['Overview',  'bi-speedometer2', 'dashboard.view'],
    'customers' => ['Customers', 'bi-people',       'customers.view'],
    'stock'     => ['Stock',     'bi-boxes',        'products.manage'],
];
const DASHBOARD_ROLE_TABS = ['catalog' => 'stock'];
const DASHBOARD_PREVIEW_LIMIT = 5;

$visible_tabs = array_filter(DASHBOARD_TABS, fn (array $tab_info): bool => Admin::can($current_admin, $tab_info[2]));
$role_tab     = DASHBOARD_ROLE_TABS[$current_admin['admin_role']] ?? 'overview';
$tab = match (true) {
    isset($visible_tabs[$_GET['tab'] ?? ''])  => $_GET['tab'],
    isset($visible_tabs[$role_tab])           => $role_tab,
    default                                   => array_key_first($visible_tabs),
};
[$current_tab_label, $current_tab_icon] = $visible_tabs[$tab];
$attention_items = adminAttentionItems($current_admin);
$dashboard_range = $dashboard->getDateRange($_GET);
$custom_date_to = $dashboard_range['date_to'];
$custom_date_from = $dashboard_range['period'] === 'custom'
    ? $dashboard_range['date_from']
    : (new DateTimeImmutable($custom_date_to, new DateTimeZone((string) Env::get('APP_TIMEZONE', 'Africa/Dar_es_Salaam'))))->modify('-29 days')->format('Y-m-d');
$dashboard_filter = ['period' => $dashboard_range['period']];
if ($dashboard_range['period'] === 'custom') {
    $dashboard_filter['date_from'] = $dashboard_range['date_from'];
    $dashboard_filter['date_to'] = $dashboard_range['date_to'];
}
$dashboard_filter_query = http_build_query($dashboard_filter);

$page_title   = 'Dashboard';
$active_menu  = 'dashboard';
$page_scripts = ['vendor/chartjs/chart.umd.min.js', 'dashboard.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="dashboard-page">
<div class="dashboard-heading mb-3">
    <p class="text-muted mb-0">Karibu, <?= e($current_admin['admin_full_name']) ?>.</p>
    <div class="dashboard-heading-controls">
    <form class="dashboard-period-filter" method="get" action="<?= e(url('admin/index.php')) ?>" data-dashboard-period-form>
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label class="visually-hidden" for="dashboard-period">Date range</label>
        <select class="form-select form-select-sm" name="period" id="dashboard-period" data-dashboard-period>
            <option value="all_time" <?= $dashboard_range['period'] === 'all_time' ? 'selected' : '' ?>>All time</option>
            <option value="today" <?= $dashboard_range['period'] === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="custom" <?= $dashboard_range['period'] === 'custom' ? 'selected' : '' ?>>Custom dates</option>
        </select>
        <div class="dashboard-custom-dates<?= $dashboard_range['period'] === 'custom' ? '' : ' d-none' ?>" data-dashboard-custom-dates>
            <label class="visually-hidden" for="dashboard-date-from">Start date</label>
            <input class="form-control form-control-sm" type="date" id="dashboard-date-from" name="date_from" value="<?= e($custom_date_from) ?>" <?= $dashboard_range['period'] === 'custom' ? 'required' : '' ?>>
            <span aria-hidden="true">–</span>
            <label class="visually-hidden" for="dashboard-date-to">End date</label>
            <input class="form-control form-control-sm" type="date" id="dashboard-date-to" name="date_to" value="<?= e($custom_date_to) ?>" <?= $dashboard_range['period'] === 'custom' ? 'required' : '' ?>>
        </div>
        <button class="btn btn-sm btn-chimbo" type="submit">Apply</button>
    </form>
    <div class="dropdown dashboard-view-selector">
        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi <?= e($current_tab_icon) ?>" aria-hidden="true"></i>
            <span><?= e($current_tab_label) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach ($visible_tabs as $tab_key => [$tab_label, $tab_icon]): ?>
                <li>
                    <a class="dropdown-item<?= $tab_key === $tab ? ' active' : '' ?>" href="<?= e(url('admin/index.php?tab=' . $tab_key . '&' . $dashboard_filter_query)) ?>"
                       <?= $tab_key === $tab ? 'aria-current="page"' : '' ?>>
                        <i class="bi <?= e($tab_icon) ?>" aria-hidden="true"></i>
                        <span><?= e($tab_label) ?></span>
                        <?php if ($tab_key === $tab): ?><i class="bi bi-check2 ms-auto" aria-hidden="true"></i><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    </div>
</div>

<?php require __DIR__ . "/includes/dashboard/{$tab}.php"; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
