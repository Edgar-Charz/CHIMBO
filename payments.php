<?php

/**
 * Malipo yangu — the customer's payments (newest 20) and, in the second tab, the payment methods CHIMBO accepts
 * with where to send the money. "Nakili" buttons: assets/js/payment.js.
 */

require __DIR__ . '/includes/init.php';

const PAYMENT_TABS = ['history' => 'Historia', 'methods' => 'Njia za malipo'];

// The status pill of each payment: [label, tone]
const PAYMENT_STATUS_PILLS = [
    'submitted' => ['Inakaguliwa', 'warning'],
    'confirmed' => ['Imethibitishwa', 'success'],
    'rejected'  => ['Imekataliwa', 'danger'],
];

$customer = requireCustomer();
$tab = array_key_exists(requestText('tab'), PAYMENT_TABS) ? requestText('tab') : 'history';

$database = Database::instance();
$payments = $tab === 'history' ? (new Payment($database))->getPaymentsForCustomer($customer['user_id'], [])['items'] : [];
$methods  = $tab === 'methods' ? (new PaymentMethod($database))->getActiveMethods() : [];

$page = [
    'title'   => 'Malipo yangu',
    'nav'     => 'account',
    'scripts' => ['payment.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl orders-page">
    <h1 class="page-title">Malipo yangu</h1>

    <nav class="catalog-chips" aria-label="Malipo yangu">
        <?php foreach (PAYMENT_TABS as $tab_key => $tab_label) : ?>
            <a class="chip<?= $tab_key === $tab ? ' is-active' : '' ?>" href="<?= e(url('payments.php?tab=' . $tab_key)) ?>" <?= $tab_key === $tab ? 'aria-current="page"' : '' ?>><?= e($tab_label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'history') : ?>
        <?php if ($payments === []) : ?>
            <div class="state-block">
                <span class="state-block-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
                <h2 class="state-block-title">Bado hujafanya malipo</h2>
                <p class="state-block-text">Malipo ya oda zako yataonekana hapa, pamoja na hali yake.</p>
            </div>
        <?php else : ?>
            <div class="orders-list">
                <?php foreach ($payments as $payment) : ?>
                    <?php [$status_label, $status_tone] = PAYMENT_STATUS_PILLS[$payment['payment_status']] ?? [$payment['payment_status'], 'info']; ?>
                    <article class="order-card">
                        <header class="order-card-header">
                            <div>
                                <a class="order-card-number" href="<?= e(url('order.php?id=' . $payment['order_id'])) ?>">
                                    <?= e($payment['payment_method_name']) ?> · <?= e(formatTzs($payment['payment_amount'])) ?>
                                </a>
                                <p class="order-card-date">
                                    Oda #<?= e($payment['order_number']) ?> · <?= e(localDateTime($payment['created_at'], 'd/m/Y, H:i')) ?>
                                </p>
                            </div>
                            <span class="status-pill status-pill--<?= e($status_tone) ?>"><?= e($status_label) ?></span>
                        </header>
                        <p class="payment-card-reference">Namba ya muamala: <strong><?= e($payment['payment_reference']) ?></strong></p>
                        <?php if ($payment['payment_status'] === 'rejected' && $payment['payment_review_note']) : ?>
                            <p class="payment-card-note"><?= e($payment['payment_review_note']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else : ?>
        <h2 class="h5 mb-3">Njia za malipo tunazokubali</h2>
        <div class="orders-list">
            <?php foreach ($methods as $method) : ?>
                <article class="order-card">
                    <h3 class="h6 mb-2"><?php require __DIR__ . '/includes/payment_method_icon.php'; ?> <?= e($method['payment_method_name']) ?></h3>
                    <?php if ($method['payment_method_type'] === 'cash') : ?>
                        <p class="mb-0">Lipa kwa pesa taslimu unapopokea mzigo.</p>
                    <?php else : ?>
                        <dl class="payment-box-details">
                            <?php require __DIR__ . '/includes/payment_method_details.php'; ?>
                        </dl>
                        <?php if ($method['payment_method_instructions']) : ?>
                            <p class="payment-box-instructions mb-0"><?= nl2br(e($method['payment_method_instructions'])) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
