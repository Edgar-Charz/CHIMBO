<?php

/**
 * A payment method's "pay to" rows (bank, number with "Nakili", account name), for inside a
 * <dl class="payment-box-details">. Used by the order's pay-to box and by "Malipo yangu".
 * Expects $method (the API payment method shape). "Nakili" buttons: assets/js/payment.js.
 */

$is_mobile_money = $method['payment_method_type'] === 'mobile_money';
?>
<?php if ($method['payment_method_bank_name']) : ?>
    <div><dt>Benki</dt><dd><?= e($method['payment_method_bank_name']) ?></dd></div>
<?php endif; ?>
<?php if ($method['payment_method_account_number']) : ?>
    <div>
        <dt><?= $is_mobile_money ? 'Namba ya kulipia' : 'Namba ya akaunti' ?></dt>
        <dd>
            <span class="payment-box-copy-value"><?= e($method['payment_method_account_number']) ?></span>
            <button class="btn btn-link payment-box-copy" type="button" data-copy="<?= e($method['payment_method_account_number']) ?>"><i class="bi bi-copy" aria-hidden="true"></i> Nakili</button>
        </dd>
    </div>
<?php endif; ?>
<?php if ($method['payment_method_account_name']) : ?>
    <div><dt>Jina</dt><dd><?= e($method['payment_method_account_name']) ?></dd></div>
<?php endif; ?>
