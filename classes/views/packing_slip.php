<?php
/**
 * Packing slip layout (turned into a PDF by Receipt::createPackingSlip()): what to pack and where it goes.
 * No prices — except the cash the delivery agent must collect for a cash-on-delivery order.
 *
 * @var array  $order        from OrderManager::getOrderForAdmin() (includes 'customer')
 * @var string|null $qr_code_uri  the active signed order URL as a QR code (PNG data URI)
 */
$address        = $order['address'];
$items          = $order['items'];
usort($items, fn (array $first, array $second) => strcasecmp($first['product_name'], $second['product_name']));   // easy to find on the shelves
$piece_count    = array_sum(array_column($items, 'order_item_quantity'));
$cash_to_collect = $order['order_payment_method'] === 'cod' && $order['order_payment_status'] === 'cod_pending'
    ? (int) $order['order_total']
    : null;
?>
<!doctype html>
<html lang="sw">
<head>
<meta charset="utf-8">
<?php require __DIR__ . '/partials/pdf_styles.php'; ?>
</head>
<body>

<table class="header">
    <tr>
        <td>
            <?php if ($logo_uri !== null): ?><img class="brand-logo" src="<?= e($logo_uri) ?>" alt=""><?php endif; ?>
            <div class="brand">CHI<span class="brand-orange">MBO</span></div>
            <div class="tagline">BIDHAA BORA • BEI NAFUU • BIASHARA IMARA</div>
        </td>
        <td class="title">KARATASI YA KUPAKIA<br><span style="font-size: 10px;">Packing slip</span></td>
    </tr>
</table>

<table class="section">
    <tr>
        <td style="vertical-align: middle;">
            <div class="box-title">Oda</div>
            <div class="order-number"><?= e($order['order_number']) ?></div>
            <div class="muted">
                <?= e(localDateTime($order['order_placed_at'])) ?> · <?= e($order['delivery_method']['delivery_method_name']) ?>
            </div>
        </td>
        <td class="right" style="width: 130px;">
            <?php if ($qr_code_uri !== null): ?>
                <img src="<?= e($qr_code_uri) ?>" alt="" style="width: 120px; height: 120px;">
                <div class="muted">Changanua kufuatilia oda</div>
            <?php else: ?>
                <div class="muted">Kiungo cha QR kimesitishwa</div>
            <?php endif; ?>
        </td>
    </tr>
</table>

<table class="section">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <div class="box-title">Mteja</div>
            <strong><?= e($order['customer']['user_full_name'] ?? '') ?></strong><br>
            <?php if (!empty($order['customer']['business_name'])): ?><?= e($order['customer']['business_name']) ?><br><?php endif; ?>
            <?= e(Phone::format($order['customer']['user_phone'])) ?>
        </td>
        <td class="right" style="width: 50%; vertical-align: top;">
            <?php if ($order['delivery_method']['delivery_method_is_pickup']): ?>
                <div class="box-title">Atachukua dukani</div>
                <strong><?= e($address['address_recipient_name']) ?></strong> · <?= e(Phone::format($address['address_phone'])) ?>
            <?php else: ?>
                <div class="box-title">Peleka kwa</div>
                <strong><?= e($address['address_recipient_name']) ?></strong> · <?= e(Phone::format($address['address_phone'])) ?><br>
                <?= e($address['address_street']) ?><br>
                <?= e(trim(($address['district_name'] ? $address['district_name'] . ', ' : '') . $address['region_name'])) ?>
                <?php if (!empty($address['address_landmark'])): ?><br><span class="muted">Alama: <?= e($address['address_landmark']) ?></span><?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<?php if ($order['delivery_method']['delivery_method_is_pickup']): ?>
    <div class="section collect">ANACHUKUA DUKANI — usitume. Weka tayari na mjulishe mteja.</div>
<?php elseif ($order['delivery_method']['delivery_fee_paid_to_rider']): ?>
    <div class="section note"><strong>Usafiri:</strong> mteja anamlipa dereva (Bolt) — si sehemu ya malipo ya CHIMBO.</div>
<?php endif; ?>

<?php if (!empty($order['order_customer_note'])): ?>
    <div class="section note"><strong>Maelezo ya mteja:</strong> <?= e($order['order_customer_note']) ?></div>
<?php endif; ?>

<table class="items section">
    <thead>
        <tr>
            <th style="width: 24px;"></th>
            <th>Bidhaa</th>
            <th>SKU</th>
            <th class="right">Idadi</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><span class="check"></span></td>
                <td><?= e($item['product_name']) ?></td>
                <td class="muted"><?= e($item['order_item_sku'] ?? '') ?></td>
                <td class="right"><strong><?= e($item['order_item_quantity']) ?></strong> <?= e($item['order_item_unit_label']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td></td>
            <td colspan="2"><strong>Jumla</strong> (<?= e(count($items)) ?> bidhaa)</td>
            <td class="right"><strong><?= e($piece_count) ?></strong> vipande</td>
        </tr>
    </tbody>
</table>

<div class="section">
    <?php if ($cash_to_collect !== null): ?>
        <div class="collect">KUSANYA: TZS <?= e(number_format($cash_to_collect)) ?> — Lipa ukipokea</div>
    <?php else: ?>
        <span class="muted">Malipo:</span> <?= e(Receipt::PAYMENT_STATUS_NAMES[$order['order_payment_status']] ?? $order['order_payment_status']) ?>
        (<?= e($order['payment']['payment_method']['payment_method_name'] ?? $order['order_payment_method']) ?>)
    <?php endif; ?>
</div>

<table class="signature">
    <tr>
        <td>Imepakiwa na: ______________________</td>
        <td class="right">Tarehe: ______________</td>
    </tr>
</table>

<div class="footer">CHIMBO · Changanua QR kuona taarifa za oda</div>

</body>
</html>
