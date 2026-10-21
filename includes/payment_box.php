<?php

/**
 * Paying for an order by mobile money or bank (checked by CHIMBO staff): the pay-to details and the "Nimelipa"
 * form (+ "Badilisha njia ya malipo"), "Tunakagua malipo yako" while staff check, the reason when a payment was
 * rejected, or the final result. Upcountry transport still to be confirmed by staff: "Tunapima mzigo wako" (no paying yet).
 * Expects $order (the API order shape). Shows nothing for cash on delivery. Form: assets/js/payment.js.
 */

$payment = $order['payment'];
$method = $payment['payment_method'];
$latest_payment = $payment['latest_payment'];
$is_mobile_money = $method['payment_method_type'] === 'mobile_money';
$is_retired_payment_method = !($method['payment_method_is_available'] ?? true);
$payment_state = match (true) {
    $method['payment_method_type'] === 'cash'                 => null,
    $order['order_status'] === 'pending_payment'
        && $order['delivery_method']['delivery_fee_status'] === 'awaiting_confirmation' => 'transport_pending',
    $payment['payment_deadline_passed'] ?? false             => 'time_over',
    $is_retired_payment_method                                => 'method_disabled',
    $payment['can_submit_payment']                            => 'pay',
    ($latest_payment['payment_status'] ?? null) === 'submitted' => 'checking',
    $order['order_payment_status'] === 'paid'                  => 'paid',
    $order['order_payment_status'] === 'refunded'              => 'refunded',
    $order['order_status'] === 'pending_payment'               => 'time_over',
    default                                                    => null,
};
?>
<?php if ($payment_state === 'pay') : ?>
    <section class="payment-box" id="payment-box" aria-labelledby="payment-box-title">
        <h2 class="payment-box__title" id="payment-box-title"><i class="bi bi-wallet2" aria-hidden="true"></i>
            <?= $payment['is_balance_payment'] ? 'Lipa salio ili tuendelee kuandaa oda yako' : 'Lipa ili tuanze kuandaa oda yako' ?></h2>

        <?php if (($latest_payment['payment_status'] ?? null) === 'rejected') : ?>
            <div class="payment-box__alert" role="alert">
                <strong>Malipo yako ya awali hayakukubaliwa.</strong>
                <?= e($latest_payment['payment_review_note'] ?: 'Tafadhali angalia namba ya muamala kisha utume tena.') ?>
            </div>
        <?php endif; ?>

        <p class="payment-box__amount">Lipa <?= $payment['is_balance_payment'] ? 'salio la ' : '' ?><strong><?= e(formatTzs($payment['payment_amount'])) ?></strong> kwa
            <?php require __DIR__ . '/payment_method_icon.php'; ?> <strong><?= e($method['payment_method_name']) ?></strong>
        </p>
        <?php if ($payment['is_balance_payment']) : ?>
            <p class="payment-box__hint">Oda imebadilishwa: umeshalipa <?= e(formatTzs($payment['amount_paid'])) ?> kati ya <?= e(formatTzs($order['order_total'])) ?>.</p>
        <?php endif; ?>

        <dl class="payment-box__details">
            <?php require __DIR__ . '/payment_method_details.php'; ?>
            <div>
                <dt>Maelezo ya malipo</dt>
                <dd>
                    <span class="payment-box__copy-value"><?= e($payment['payment_note_hint']) ?></span>
                    <button class="btn btn-link payment-box__copy" type="button" data-copy="<?= e($payment['payment_note_hint']) ?>"><i class="bi bi-copy" aria-hidden="true"></i> Nakili</button>
                </dd>
            </div>
        </dl>
        <p class="payment-box__hint">Andika <strong><?= e($payment['payment_note_hint']) ?></strong> kama maelezo / kumbukumbu ya malipo, ili tuitambue oda yako haraka.</p>
        <?php if ($method['payment_method_instructions']) : ?>
            <p class="payment-box__instructions"><?= nl2br(e($method['payment_method_instructions'])) ?></p>
        <?php endif; ?>
        <?php if ($order['order_expires_at'] !== null) : ?>
            <p class="payment-box__deadline"><i class="bi bi-clock" aria-hidden="true"></i> Lipa kabla ya <?= e(localDateTime($order['order_expires_at'], 'd/m/Y, H:i')) ?> — baada ya hapo oda inaghairiwa.</p>
        <?php endif; ?>

        <form class="payment-form" id="payment-form" novalidate data-payment-form="<?= e($order['order_id']) ?>" data-payer-is-phone="<?= $is_mobile_money ? 'true' : 'false' ?>">
            <h3 class="payment-form__title">Umeshalipa? Tuma uthibitisho</h3>
            <div class="row g-3">
                <div class="col-12 col-sm-6" data-field>
                    <label class="form-label" for="payment_payer_account"><?= $is_mobile_money ? 'Namba uliyolipia nayo' : 'Akaunti au jina ulilolipia nalo' ?></label>
                    <?php if ($is_mobile_money) : ?>
                        <div class="phone-input">
                            <span class="phone-input__prefix">+255</span>
                            <input class="form-control" id="payment_payer_account" name="payment_payer_account" type="tel" inputmode="numeric"
                                autocomplete="tel-national" placeholder="712 345 678" maxlength="16" required>
                        </div>
                    <?php else : ?>
                        <input class="form-control" id="payment_payer_account" name="payment_payer_account" autocomplete="off" required>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-sm-6" data-field>
                    <label class="form-label" for="payment_reference">Namba ya muamala (kwenye SMS / risiti)</label>
                    <input class="form-control payment-form__reference" id="payment_reference" name="payment_reference" autocomplete="off"
                        autocapitalize="characters" spellcheck="false" maxlength="30" placeholder="Mf. QJK3X7ABC1" required>
                </div>
            </div>
            <button class="btn btn-buy btn-lg w-100 mt-3" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Nimelipa</button>
        </form>

        <?php if ($payment['can_change_payment_method']) : ?>
            <div class="payment-change" data-payment-change="<?= e($order['order_id']) ?>" data-current-method="<?= e($method['payment_method_code']) ?>">
                <button class="btn btn-link payment-change__toggle" type="button" aria-expanded="false" aria-controls="payment-change-panel" data-payment-change-toggle>
                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Badilisha njia ya malipo
                </button>
                <div class="payment-change__panel" id="payment-change-panel" hidden data-payment-change-panel>
                    <div class="choice-list" role="radiogroup" aria-label="Njia za malipo" data-payment-change-list></div>
                    <p class="payment-change__error" role="alert" hidden data-payment-change-error></p>
                    <div class="address-form__actions">
                        <button class="btn btn-primary" type="button" disabled data-payment-change-save>Tumia njia hii</button>
                        <button class="btn btn-link" type="button" data-payment-change-cancel>Ghairi</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($payment_state === 'transport_pending') : ?>
    <section class="payment-box payment-box--checking" aria-live="polite">
        <h2 class="payment-box__title"><i class="bi bi-truck" aria-hidden="true"></i> Tunapima mzigo wako</h2>
        <p class="mb-1">Tunathibitisha bei ya usafiri hadi <?= e($order['address']['region_name'] ?? 'mkoa wako') ?>.
            <?= $order['delivery_method']['delivery_fee_estimate'] > 0
                ? 'Makadirio ni ' . e(formatTzs($order['delivery_method']['delivery_fee_estimate'])) . '; mzigo ukiwa mkubwa bei inaweza kuongezeka.'
                : 'Tutakutumia bei ya usafiri.' ?></p>
        <p class="mb-0">Usilipe bado — tutakujulisha bei kamili, kisha utalipa hapa.</p>
    </section>
<?php elseif ($payment_state === 'checking') : ?>
    <section class="payment-box payment-box--checking" aria-live="polite">
        <h2 class="payment-box__title"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Tunakagua malipo yako</h2>
        <p class="mb-1">Tumepokea uthibitisho wako: namba ya muamala <strong><?= e($latest_payment['payment_reference']) ?></strong> kutoka <?= e($latest_payment['payment_payer_account']) ?>.</p>
        <p class="mb-0">Tutakujulisha mara tukimaliza kukagua, kisha tutaanza kuandaa oda yako.</p>
    </section>
<?php elseif ($payment_state === 'paid') : ?>
    <p class="payment-box__result payment-box__result--paid"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Malipo yamepokelewa — asante!</p>
<?php elseif ($payment_state === 'refunded') : ?>
    <p class="payment-box__result"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Pesa yako imerudishwa.</p>
<?php elseif ($payment_state === 'time_over') : ?>
    <p class="payment-box__result payment-box__result--late"><i class="bi bi-clock-history" aria-hidden="true"></i> Huwezi kulipa oda hii tena kwa sababu muda wa kulipa umeisha. Oda itasitishwa hivi karibuni. Kama ulilipa au bado unahitaji kuendelea, <a href="<?= e(url('help.php')) ?>">wasiliana nasi</a>.</p>
<?php elseif ($payment_state === 'method_disabled') : ?>
    <section class="payment-box payment-box--checking" aria-live="polite">
        <h2 class="payment-box__title"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Njia ya malipo haipatikani</h2>
        <p class="mb-0">Usiendelee na malipo kupitia njia hii. Ghairi oda hii au <a href="<?= e(url('help.php')) ?>">wasiliana nasi</a> kwa msaada.</p>
    </section>
<?php endif; ?>
