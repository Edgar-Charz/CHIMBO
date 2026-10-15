<?php

/**
 * Where and when to collect a pickup order ("Nitachukua mwenyewe dukani", R-26). Expects $order (the API order shape).
 */

$pickup_point = $order['pickup_point'];
?>
<p class="order-detail-text">
    <i class="bi bi-shop" aria-hidden="true"></i> <strong><?= e($pickup_point['pickup_address']) ?></strong><br>
    <i class="bi bi-clock" aria-hidden="true"></i> <?= e($pickup_point['pickup_hours']) ?>
</p>
<p class="order-detail-text">Tutakujulisha oda ikiwa tayari. Njoo na namba ya oda <strong><?= e($order['order_number']) ?></strong>.</p>
