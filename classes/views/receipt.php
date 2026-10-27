<?php
/**
 * Receipt layout (turned into a PDF by Receipt). Simple HTML + CSS that Dompdf supports (tables, no flexbox).
 *
 * @var array $order           from Order::getOrder() / OrderManager::getOrderForAdmin()
 * @var array $customer        user_full_name, user_phone, business_name
 * @var array $receipt_details company_address, support_phone, support_whatsapp
 */
$money = fn (int $amount) => 'TZS ' . number_format($amount);
$is_cancelled = in_array($order['order_status'], ['cancelled', 'expired'], true);
?>
<!doctype html>
<html lang="sw">
<head>
<meta charset="utf-8">
<?php require __DIR__ . '/partials/pdf_styles.php'; ?>
<style>
    @page { margin: 28px 32px 72px; }
    .receipt-footer {
        position: fixed;
        bottom: -42px;
        left: 0;
        right: 0;
        border-top: 1px solid #ece6db;
        padding-top: 8px;
        text-align: center;
        font-size: 9px;
        color: #6b7570;
    }
    .receipt-footer-details { margin-top: 4px; }
</style>
</head>
<body>

<table class="header">
    <tr>
        <td>
            <?php if ($logo_uri !== null): ?><img class="brand-logo" src="<?= e($logo_uri) ?>" alt=""><?php endif; ?>
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
        <td class="right" style="width: 50%; vertical-align: top;">
            <?php if ($order['pickup_point'] !== null): ?>
                <div class="box-title">Kuchukua dukani</div>
                <strong><?= e($order['pickup_point']['pickup_address']) ?></strong><br>
                <?= e($order['pickup_point']['pickup_hours']) ?>
            <?php else: ?>
                <div class="box-title">Anwani ya uwasilishaji</div>
                <strong><?= e($order['address']['address_recipient_name']) ?></strong><br>
                <?= e($order['address']['address_street']) ?><br>
                <?= e(trim(($order['address']['district_name'] ? $order['address']['district_name'] . ', ' : '') . $order['address']['region_name'])) ?><br>
                <?= e($order['address']['address_phone']) ?>
            <?php endif; ?>
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
                    <span class="muted"><?= e($item['order_item_tier_min_quantity']) ?>+ <?= e($item['order_item_unit_label']) ?> — bei ya jumla<?= $item['order_item_offer_percent'] > 0 ? ' · Ofa −' . e($item['order_item_offer_percent']) . '%' : '' ?></span>
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
    <tr><td>Usafirishaji</td><td class="right"><?= e(Order::deliveryFeeWords($order) ?? $money($order['order_delivery_fee'])) ?></td></tr>
    <?php if ($order['order_discount_total'] > 0): ?>
        <tr><td>Punguzo<?= $order['order_discount_note'] ? ' — ' . e($order['order_discount_note']) : '' ?></td><td class="right">− <?= e($money($order['order_discount_total'])) ?></td></tr>
    <?php endif; ?>
    <tr class="grand-total"><td>Jumla</td><td class="right"><?= e($money($order['order_total'])) ?></td></tr>
</table>

<table class="section">
    <tr>
        <td><span class="muted">Njia ya malipo:</span> <?= e($order['payment']['payment_method']['payment_method_name'] ?? $order['order_payment_method']) ?></td>
        <td class="right"><span class="muted">Hali ya malipo:</span> <?= e(Receipt::PAYMENT_STATUS_NAMES[$order['order_payment_status']] ?? $order['order_payment_status']) ?></td>
    </tr>
</table>

<div class="receipt-footer">
    <div class="receipt-footer-details">
        <?php if (!empty($receipt_details['company_address'])): ?>Anwani: <?= e($receipt_details['company_address']) ?><?php endif; ?>
        <?php if (!empty($receipt_details['company_address']) && (!empty($receipt_details['support_phone']) || !empty($receipt_details['support_whatsapp']))): ?> · <?php endif; ?>
        <?php if (!empty($receipt_details['support_phone']) && $receipt_details['support_phone'] === $receipt_details['support_whatsapp']): ?>
            Simu / WhatsApp: <?= e(Phone::format($receipt_details['support_phone'])) ?>
        <?php else: ?>
            <?php if (!empty($receipt_details['support_phone'])): ?>Simu: <?= e(Phone::format($receipt_details['support_phone'])) ?><?php endif; ?>
            <?php if (!empty($receipt_details['support_phone']) && !empty($receipt_details['support_whatsapp'])): ?> · <?php endif; ?>
            <?php if (!empty($receipt_details['support_whatsapp'])): ?>WhatsApp: <?= e(Phone::format($receipt_details['support_whatsapp'])) ?><?php endif; ?>
        <?php endif; ?>
    </div>
    Asante kwa kununua na CHIMBO — Agiza. Amini. Pokea. Kuza Biashara.<br>
    Risiti hii imetolewa <?= e(localDateTime(gmdate('Y-m-d H:i:s'))) ?>.
</div>

</body>
</html>
