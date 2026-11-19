<?php

$payment_method_icons = [
    'mobile_money' => 'phone',
    'bank' => 'bank',
    'cash' => 'cash-coin',
];
$payment_method_descriptions = [
    'mobile_money' => 'Lipa kwa simu — tutakuonyesha namba ya kulipia.',
    'bank' => 'Lipia benki — tutakuonyesha akaunti.',
    'cash' => 'Lipa pesa taslimu mzigo ukifika dukani.',
];
?>
<div class="choice-list" role="radiogroup" aria-label="Njia za malipo" data-payment-change-list>
    <?php foreach ($payment['available_payment_methods'] as $payment_option) : ?>
        <?php $is_current_payment_option = $payment_option['payment_method_code'] === $method['payment_method_code']; ?>
        <label class="choice-card">
            <input class="choice-card-input" type="radio" name="payment_method_choice"
                value="<?= e($payment_option['payment_method_code']) ?>" data-payment-method-choice
                <?= $is_current_payment_option ? 'checked' : '' ?>>
            <span class="choice-card-icon">
                <?php if (!empty($payment_option['payment_method_icon_url'])) : ?>
                    <img class="choice-card-payment-icon" src="<?= e($payment_option['payment_method_icon_url']) ?>" alt="" loading="lazy" decoding="async">
                <?php else : ?>
                    <i class="bi bi-<?= e($payment_method_icons[$payment_option['payment_method_type']] ?? 'wallet2') ?>" aria-hidden="true"></i>
                <?php endif; ?>
            </span>
            <span class="choice-card-body">
                <span class="choice-card-title">
                    <?= e($payment_option['payment_method_name']) ?>
                    <?php if ($is_current_payment_option) : ?>
                        <span class="choice-card-tag">Sasa hivi</span>
                    <?php endif; ?>
                </span>
                <span class="choice-card-line"><?= e($payment_method_descriptions[$payment_option['payment_method_type']] ?? '') ?></span>
            </span>
        </label>
    <?php endforeach; ?>
</div>
