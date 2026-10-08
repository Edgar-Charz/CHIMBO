<?php
/**
 * The sales report as a printable document (reports.php turns it into a PDF with adminPdfFromHtml()).
 * Simple HTML + CSS that Dompdf supports (tables, no flexbox); colours written out because a PDF has no CSS variables.
 *
 * @var array $sales             from Report::getSalesReport()
 * @var array $printed_sections  the sections to print, in the same form as $sections in reports.php
 * @var array $summary_cards     [label, value, icon] for the full report, or [] for one section
 * @var array $current_admin     who downloaded it
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2a24; }
    table { width: 100%; border-collapse: collapse; }
    .header td { background: #0e3b2a; color: #fff; padding: 14px 18px; }
    .brand { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
    .brand-orange { color: #f39200; }
    .title { font-size: 15px; font-weight: bold; text-align: right; }
    .period { font-size: 9px; text-align: right; color: #d7e4dc; margin-top: 2px; }
    .note { margin: 10px 0 14px; color: #6b7570; font-size: 9px; }
    .summary td { width: 33%; padding: 9px 10px; border: 1px solid #ece6db; }
    .summary-value { font-size: 14px; font-weight: bold; color: #0e3b2a; }
    .summary-label { font-size: 8px; color: #6b7570; text-transform: uppercase; }
    h2 { font-size: 12px; color: #0e3b2a; margin: 18px 0 6px; }
    .data th { background: #f1f3f5; text-align: left; font-size: 8px; text-transform: uppercase; color: #1f2328; padding: 6px; }
    .data td { padding: 5px 6px; border-bottom: 1px solid #ece6db; }
    .data tr { page-break-inside: avoid; }
    .data th.right, .data td.right { text-align: right; white-space: nowrap; }
    .empty { color: #6b7570; font-style: italic; }
    .footer { margin-top: 22px; text-align: center; font-size: 8px; color: #6b7570; }
</style>
</head>
<body>

<table class="header">
    <tr>
        <td><span class="brand">CHI<span class="brand-orange">MBO</span></span></td>
        <td>
            <div class="title">Sales report</div>
            <div class="period"><?= e(adminCalendarDate($sales['date_from'])) ?> – <?= e(adminCalendarDate($sales['date_to'])) ?></div>
        </td>
    </tr>
</table>

<p class="note">Cancelled and expired orders are not counted in the sales. Amounts in Tanzanian shillings.</p>

<?php if ($summary_cards): ?>
    <table class="summary">
        <?php foreach (array_chunk($summary_cards, 3) as $card_row): ?>
            <tr>
                <?php foreach ($card_row as [$label, $value]): ?>
                    <td>
                        <div class="summary-value"><?= e($value) ?></div>
                        <div class="summary-label"><?= e($label) ?></div>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php foreach ($printed_sections as $section => [$section_title, , $column_titles, $row_cells]): ?>
    <h2><?= e($section_title) ?></h2>
    <?php if (!$sales[$section]): ?>
        <p class="empty">No sales in these days.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <?php foreach ($column_titles as $column_index => $column_title): ?>
                        <th class="<?= $column_index > 0 ? 'right' : '' ?>"><?= e($column_title) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales[$section] as $row): ?>
                    <tr>
                        <?php foreach ($row_cells($row) as $column_index => [$cell_html]): ?>
                            <?php // The cells are already escaped; links (e.g. to a product) are dropped on paper ?>
                            <td class="<?= $column_index > 0 ? 'right' : '' ?>"><?= strip_tags($cell_html) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endforeach; ?>

<div class="footer">
    Downloaded by <?= e($current_admin['admin_full_name']) ?> on <?= e(adminDateTime(gmdate('Y-m-d H:i:s'))) ?> (Tanzania time) · CHIMBO admin
</div>

</body>
</html>
