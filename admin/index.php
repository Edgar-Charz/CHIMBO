<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('dashboard.view');
$dashboard     = new Dashboard(Database::instance());
$sales         = $dashboard->getSales();

// Money is shown only to staff who may see reports; everyone else sees the order counts
$can_see_money = Admin::can($current_admin, 'reports.view');
$sales_cards = array_values(array_filter([
    ['Orders today',         number_format($sales['today']['order_count']),       'bi-bag-check',     null],
    $can_see_money ? ['Revenue today', adminCardMoney($sales['today']['revenue']),   'bi-cash-stack',    null] : null,
    ['Orders, last 7 days',  number_format($sales['last_7_days']['order_count']), 'bi-calendar-week', null],
    $can_see_money ? ['Revenue, last 7 days', adminCardMoney($sales['last_7_days']['revenue']), 'bi-graph-up', null] : null,
]));

// The tabs: key => [label, icon, permission needed]; each is a file in includes/dashboard/. Only the tabs this staff
// member may see are shown. Without ?tab= the page opens on the most useful tab for their role.
const DASHBOARD_TABS = [
    'overview'  => ['Overview',  'bi-speedometer2', 'dashboard.view'],
    'orders'    => ['Orders',    'bi-receipt',      'orders.view'],
    'sales'     => ['Sales',     'bi-graph-up',     'reports.view'],
    'customers' => ['Customers', 'bi-people',       'customers.view'],
    'stock'     => ['Stock',     'bi-boxes',        'products.manage'],
];
const DASHBOARD_ROLE_TABS = ['operations' => 'orders', 'finance' => 'sales', 'catalog' => 'stock'];
const DASHBOARD_DEFAULT_DAYS = ['overview' => 14];   // the other tabs show 30 days unless ?days= says otherwise

$visible_tabs = array_filter(DASHBOARD_TABS, fn (array $tab_info): bool => Admin::can($current_admin, $tab_info[2]));
$role_tab     = DASHBOARD_ROLE_TABS[$current_admin['admin_role']] ?? 'overview';
$tab = match (true) {
    isset($visible_tabs[$_GET['tab'] ?? ''])  => $_GET['tab'],
    isset($visible_tabs[$role_tab])           => $role_tab,
    default                                   => array_key_first($visible_tabs),
};
$chart_days = in_array((int) ($_GET['days'] ?? 0), Dashboard::CHART_DAY_OPTIONS, true)
    ? (int) $_GET['days']
    : (DASHBOARD_DEFAULT_DAYS[$tab] ?? 30);

$page_title   = 'Dashboard';
$active_menu  = 'dashboard';
$page_scripts = ['vendor/chartjs/chart.umd.min.js', 'dashboard.js'];
require __DIR__ . '/includes/header.php';
?>

<p class="text-muted mb-3">Karibu, <?= e($current_admin['admin_full_name']) ?>.</p>

<h2 class="dashboard-section-title">Today and this week</h2>
<div class="mb-3"><?= adminStatCards($sales_cards, 4) ?></div>

<ul class="nav nav-tabs dashboard-tabs mb-3">
    <?php foreach ($visible_tabs as $tab_key => [$tab_label, $tab_icon]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $tab_key === $tab ? ' active' : '' ?>" href="<?= e(url('admin/index.php?tab=' . $tab_key)) ?>"
               <?= $tab_key === $tab ? 'aria-current="page"' : '' ?>><i class="bi <?= e($tab_icon) ?>"></i> <?= e($tab_label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php require __DIR__ . "/includes/dashboard/{$tab}.php"; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
