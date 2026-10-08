<?php
/**
 * Receipt layout (turned into a PDF by Receipt). Simple HTML + CSS that Dompdf supports (tables, no flexbox).
 *
 * @var array $order     from Order::getOrder() / OrderManager::getOrderForAdmin()
 * @var array $customer  user_full_name, user_phone, business_name
 */
$money = fn (int $amount) => 'TZS ' . number_format($amount);
$is_cancelled = in_array($order['order_status'], ['cancelled', 'expired'], true);
?>
<!doctype html>
<html lang="sw">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2a24; }
    .header { background: #0e3b2a; color: #fff; padding: 16px 20px; }
    .brand { font-size: 24px; font-weight: bold; letter-spacing: 1px; }
    .brand-orange { color: #f39200; }
    .tagline { font-size: 8px; letter-spacing: 1px; margin-top: 2px; color: #d7e4dc; }
    .title { font-size: 16px; font-weight: bold; text-align: right; }
    .muted { color: #6b7570; }
    .section { margin-top: 16px; }
    .box-title { font-size: 9px; font-weight: bold; color: #6b7570; text-transform: uppercase; margin-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; }
    .items th { background: #fbf6ee; text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7570; padding: 7px 6px; }
    .items td { padding: 7px 6px; border-bottom: 1px solid #ece6db; vertical-align: top; }
    .right, .items th.right { text-align: right; }
    .totals td { padding: 4px 6px; }
    .grand-total td { font-size: 13px; font-weight: bold; border-top: 2px solid #0e3b2a; padding-top: 8px; }
    .stamp { color: #b42318; border: 2px solid #b42318; padding: 4px 10px; font-weight: bold; display: inline-block; }
    .footer { margin-top: 28px; text-align: center; font-size: 9px; color: #6b7570; }
</style>
</head>
<body>

<table class="header">
    <tr>
        <td>
            <div class="brand">CHI<span class="brand-orange">MBO</span></div>
            <div class="tagline">BIDHAA BORA • BEI NAFUU • BIASHARA IMARA</div>
        </td>
        <td class="title">
            RISITI<br>
            <span style="font-size: 12px;">#<?= e($order['order_number']) ?></span>
        </td>
    </tr>
</table>

<table class="section">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <div class="box-title">Mnunuzi</div>
            <strong><?= e($customer['user_full_name'] ?? '') ?></strong><br>
            <?php if (!empty($customer['business_name'])): ?><?= e($customer['business_name']) ?><br><?php endif; ?>
            <?= e($customer['user_phone'] ?? '') ?>
        </td>
        <td style="width: 50%; vertical-align: top;">
            <div class="box-title">Anwani ya uwasilishaji</div>
            <strong><?= e($order['address']['address_recipient_name']) ?></strong><br>
            <?= e($order['address']['address_street']) ?><br>
            <?= e(trim(($order['address']['district_name'] ? $order['address']['district_name'] . ', ' : '') . $order['address']['region_name'])) ?><br>
            <?= e($order['address']['address_phone']) ?>
        </td>
    </tr>
</table>

<table class="section">
    <tr>
        <td><span class="muted">Tarehe ya oda:</span> <?= e(localDateTime($order['order_placed_at'])) ?></td>
        <td><span class="muted">Hali ya oda:</span> <?= e(Receipt::ORDER_STATUS_NAMES[$order['order_status']] ?? $order['order_status']) ?></td>
        <td class="right"><span class="muted">Usafirishaji:</span> <?= e($order['delivery_method']['delivery_method_name']) ?></td>
    </tr>
</table>

<?php if ($is_cancelled): ?>
    <div class="section"><span class="stamp">ODA IMESITISHWA</span></div>
<?php endif; ?>

<table class="items section">
    <thead>
        <tr>
            <th>Bidhaa</th>
            <th class="right">Kiasi</th>
            <th class="right">Bei (kila moja)</th>
            <th class="right">Jumla</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($order['items'] as $item): ?>
            <tr>
                <td>
                    <?= e($item['product_name']) ?><br>
                    <span class="muted"><?= e($item['order_item_tier_min_quantity']) ?>+ <?= e($item['order_item_unit_label']) ?> — bei ya jumla</span>
                </td>
                <td class="right"><?= e($item['order_item_quantity']) ?> <?= e($item['order_item_unit_label']) ?></td>
                <td class="right"><?= e($money($item['order_item_unit_price'])) ?></td>
                <td class="right"><?= e($money($item['order_item_line_total'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals section" style="width: 45%; margin-left: 55%;">
    <tr><td>Bidhaa (<?= e($order['item_count']) ?>)</td><td class="right"><?= e($money($order['order_subtotal'])) ?></td></tr>
    <tr><td>Usafirishaji</td><td class="right"><?= e($money($order['order_delivery_fee'])) ?></td></tr>
    <?php if ($order['order_discount_total'] > 0): ?>
        <tr><td>Punguzo</td><td class="right">− <?= e($money($order['order_discount_total'])) ?></td></tr>
    <?php endif; ?>
    <tr class="grand-total"><td>Jumla</td><td class="right"><?= e($money($order['order_total'])) ?></td></tr>
</table>

<table class="section">
    <tr>
        <td><span class="muted">Njia ya malipo:</span> <?= e(Receipt::PAYMENT_METHOD_NAMES[$order['order_payment_method']] ?? $order['order_payment_method']) ?></td>
        <td class="right"><span class="muted">Hali ya malipo:</span> <?= e(Receipt::PAYMENT_STATUS_NAMES[$order['order_payment_status']] ?? $order['order_payment_status']) ?></td>
    </tr>
</table>

<div class="footer">
    Asante kwa kununua na CHIMBO — Agiza. Amini. Pokea. Kuza Biashara.<br>
    Risiti hii imetolewa <?= e(localDateTime(gmdate('Y-m-d H:i:s'))) ?>.
</div>

</body>
</html>
