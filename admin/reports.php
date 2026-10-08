<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Sales for a range of days (default the last 30). Cancelled and expired orders are left out (only counted).
$current_admin = AdminSession::requireLogin('reports.view');
$report        = new Report(Database::instance());

// One table per section: [title, icon, column titles, row → cells]. A cell is [escaped HTML, value to sort by].
// The page and the PDF both use these, so they always show the same thing.
$money_cell  = fn (mixed $amount): array => [e(adminMoney((int) $amount)), (int) $amount];
$number_cell = fn (mixed $count): array => [e(number_format((int) $count)), (int) $count];
$sections = [
    'by_day' => ['Sales by day', 'bi-calendar3', ['Day', 'Orders', 'Revenue'], fn (array $row): array => [
        [e(adminCalendarDate($row['sales_date'])), $row['sales_date']], $number_cell($row['order_count']), $money_cell($row['revenue']),
    ]],
    'top_products' => ['Top products', 'bi-trophy', ['Product', 'SKU', 'Pieces sold', 'Revenue'], fn (array $row): array => [
        ['<a href="' . e(url('admin/product_edit.php?id=' . $row['product_id'])) . '">' . e($row['product_name']) . '</a>', $row['product_name']],
        [e($row['product_sku']), $row['product_sku']], $number_cell($row['quantity']), $money_cell($row['revenue']),
    ]],
    'by_category' => ['Sales by category', 'bi-diagram-3', ['Category', 'Pieces sold', 'Revenue'], fn (array $row): array => [
        [e($row['category_name']), $row['category_name']], $number_cell($row['quantity']), $money_cell($row['revenue']),
    ]],
    'by_payment_method' => ['Payment methods', 'bi-credit-card', ['Method', 'Orders', 'Total', 'Paid'], fn (array $row): array => [
        [e(adminPaymentMethodName($row['order_payment_method'])), $row['order_payment_method']],
        $number_cell($row['order_count']), $money_cell($row['revenue']), $money_cell($row['paid_total']),
    ]],
    'by_region' => ['Sales by region', 'bi-geo-alt', ['Region', 'Orders', 'Revenue'], fn (array $row): array => [
        [e($row['region_name']), $row['region_name']], $number_cell($row['order_count']), $money_cell($row['revenue']),
    ]],
];

/** The six summary numbers: [label, value as text, icon]. */
$summary_cards_for = fn (array $summary): array => [
    ['Orders',        number_format((int) $summary['order_count']),     'bi-receipt'],
    ['Revenue',       adminMoney((int) $summary['revenue']),            'bi-cash-stack'],
    ['Average order', adminMoney((int) $summary['average_order']),      'bi-calculator'],
    ['Pieces sold',   number_format((int) $summary['pieces_sold']),     'bi-box-seam'],
    ['Delivery fees', adminMoney((int) $summary['delivery_fees']),      'bi-truck'],
    ['Cancelled',     number_format((int) $summary['cancelled_count']), 'bi-x-circle'],
];

// Downloads are plain links (read-only), so GET is fine: ?export=by_day (CSV) or ?export=all|by_day&format=pdf
if (isset($_GET['export'])) {
    $export = (string) $_GET['export'];
    try {
        if (($_GET['format'] ?? 'csv') !== 'pdf') {
            $file = $report->exportCsv($export, $_GET);
            adminSendFile($file['content'], 'text/csv; charset=utf-8', $file['file_name']);
        }
        if ($export !== 'all' && !isset($sections[$export])) {
            throw ApiException::validation(['section' => 'Unknown report.']);
        }
        $sales            = $report->getSalesReport($_GET);
        $printed_sections = $export === 'all' ? $sections : [$export => $sections[$export]];
        $summary_cards    = $export === 'all' ? $summary_cards_for($sales['summary']) : [];
        ob_start();
        require __DIR__ . '/includes/report_pdf.php';
        $pdf = adminPdfFromHtml((string) ob_get_clean());
        adminSendFile($pdf, 'application/pdf', "chimbo-report-{$export}-{$sales['date_from']}-to-{$sales['date_to']}.pdf");
    } catch (ApiException $e) {
        Session::flash('error', 'That download could not be made. ' . implode(' ', $e->fields() ?: [$e->getMessage()]));
        redirect(url('admin/reports.php'));
    }
}

// A wrong date range shows its message under the dates; the page then shows the default range
try {
    $sales = $report->getSalesReport($_GET);
    $date_errors = [];
} catch (ApiException $e) {
    $sales = $report->getSalesReport([]);
    $date_errors = $e->fields();
}
$range         = ['date_from' => $sales['date_from'], 'date_to' => $sales['date_to']];
$summary_cards = $summary_cards_for($sales['summary']);

// Quick ranges, counted back from today in Tanzania time
$today        = new DateTimeImmutable('today', new DateTimeZone((string) Env::get('APP_TIMEZONE', 'UTC')));
$quick_ranges = [
    'Last 7 days'  => $today->modify('-6 days'),
    'Last 30 days' => $today->modify('-29 days'),
    'Last 90 days' => $today->modify('-89 days'),
    'This month'   => $today->modify('first day of this month'),
];

$page_title  = 'Reports';
$active_menu = 'reports';
require __DIR__ . '/includes/header.php';
?>

<form class="admin-panel filter-card" method="get" novalidate>
    <div class="filter-grid">
        <div>
            <label class="form-label" for="date_from">From</label>
            <input class="form-control<?= adminInvalidClass($date_errors, 'date_from') ?>" type="date" id="date_from" name="date_from" value="<?= e($range['date_from']) ?>">
            <?= adminFieldError($date_errors, 'date_from') ?>
        </div>
        <div>
            <label class="form-label" for="date_to">To</label>
            <input class="form-control<?= adminInvalidClass($date_errors, 'date_to') ?>" type="date" id="date_to" name="date_to" value="<?= e($range['date_to']) ?>">
            <?= adminFieldError($date_errors, 'date_to') ?>
        </div>
        <div class="filter-actions">
            <button class="btn btn-chimbo" type="submit">Show report</button>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php foreach ($quick_ranges as $range_name => $range_start): ?>
            <a class="btn btn-sm btn-light" href="<?= e(url('admin/reports.php?' . http_build_query(['date_from' => $range_start->format('Y-m-d'), 'date_to' => $today->format('Y-m-d')]))) ?>">
                <?= e($range_name) ?>
            </a>
        <?php endforeach; ?>
    </div>
</form>

<div class="admin-page-actions">
    <p class="text-muted mb-0">
        <?= e(adminCalendarDate($range['date_from'])) ?> – <?= e(adminCalendarDate($range['date_to'])) ?>.
        Cancelled and expired orders are not counted in the sales.
    </p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/reports.php?' . http_build_query(['export' => 'all', 'format' => 'pdf'] + $range))) ?>">
        <i class="bi bi-file-earmark-pdf"></i> Full report (PDF)
    </a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($summary_cards as [$label, $value, $icon]): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-icon mb-2"><i class="bi <?= e($icon) ?>"></i></div>
                    <div class="stat-value report-stat-value"><?= e($value) ?></div>
                    <div class="small text-muted"><?= e($label) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <?php foreach ($sections as $section => [$section_title, $section_icon, $column_titles, $row_cells]): ?>
        <div class="<?= in_array($section, ['by_day', 'top_products'], true) ? 'col-12' : 'col-xl-4 col-lg-6' ?>">
            <section class="admin-panel admin-table-panel h-100">
                <div class="report-section-header">
                    <h2 class="table-card-title"><i class="bi <?= e($section_icon) ?>"></i> <?= e($section_title) ?></h2>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Download <?= e($section_title) ?>">
                        <a class="btn btn-outline-secondary" href="<?= e(url('admin/reports.php?' . http_build_query(['export' => $section] + $range))) ?>">
                            <i class="bi bi-filetype-csv"></i> CSV
                        </a>
                        <a class="btn btn-outline-secondary" href="<?= e(url('admin/reports.php?' . http_build_query(['export' => $section, 'format' => 'pdf'] + $range))) ?>">
                            <i class="bi bi-file-earmark-pdf"></i> PDF
                        </a>
                    </div>
                </div>
                <?php if (!$sales[$section]): ?>
                    <p class="text-muted mb-0">No sales in these days.</p>
                <?php else: ?>
                    <table class="table admin-table w-100" data-datatable data-searching="false" data-order='[]'>
                        <thead>
                            <tr>
                                <?php foreach ($column_titles as $column_index => $column_title): ?>
                                    <th class="<?= $column_index > 0 ? 'text-end' : '' ?>"><?= e($column_title) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales[$section] as $row): ?>
                                <tr>
                                    <?php foreach ($row_cells($row) as $column_index => [$cell_html, $sort_value]): ?>
                                        <td class="<?= $column_index > 0 ? 'text-end text-nowrap' : '' ?>" data-order="<?= e($sort_value) ?>"><?= $cell_html ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
