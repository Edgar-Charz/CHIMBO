<?php

/** Displays an uploaded payment logo or the built-in icon for its method type. Expects $method. */
$payment_icon_name = [
    'mobile_money' => 'phone',
    'bank'         => 'bank',
    'cash'         => 'cash-coin',
][$method['payment_method_type']] ?? 'wallet2';
?>
<span class="payment-method-icon" aria-hidden="true">
    <?php if (!empty($method['payment_method_icon_url'])): ?>
        <img src="<?= e($method['payment_method_icon_url']) ?>" alt="" loading="lazy" decoding="async">
    <?php else: ?>
        <i class="bi bi-<?= e($payment_icon_name) ?>"></i>
    <?php endif; ?>
</span>
