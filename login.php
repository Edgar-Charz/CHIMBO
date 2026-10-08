<?php

/**
 * Ingia / Jisajili, all on one page (assets/js/auth.js): phone → PIN for registered numbers, or SMS code →
 * Tengeneza PIN → business details for new ones; "Umesahau PIN?" = SMS code → new PIN (?forgot_pin=1 from Wasifu).
 * Afterwards the guest cart moves into the account and the customer returns to ?return= (only paths inside the shop).
 */

require __DIR__ . '/includes/init.php';

$customer = currentCustomer();
$return_path = safeReturnPath(requestText('return'));
$forgot_pin = requestText('forgot_pin') === '1' && $customer !== null;

if (isCustomerReady($customer) && !$forgot_pin) {
    redirect($return_path);
}

$start_step = 'phone';
if ($forgot_pin) {
    $start_step = 'forgot_pin';
} elseif ($customer !== null) {
    $start_step = !$customer['user_has_pin'] ? 'create_pin' : 'profile';
}

$auth_data = [
    'return_path' => $return_path,
    'start_step'  => $start_step,
    'user_phone'  => $customer['user_phone'] ?? null,
];

$page = [
    'title'       => 'Ingia',
    'description' => 'Ingia au jisajili CHIMBO kwa namba yako ya simu.',
    'is_focused'  => true,
    'scripts'     => ['location_select.js', 'auth.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl auth-page">
    <div class="auth-card">
        <aside class="auth-card__brand">
            <p class="auth-card__eyebrow">CHIMBO kwa wenye maduka</p>
            <h2 class="auth-card__headline">Bidhaa za duka lako kwa bei za jumla</h2>
            <ul class="auth-card__benefits">
                <li><i class="bi bi-tags" aria-hidden="true"></i> Bei hushuka kadri unavyonunua zaidi</li>
                <li><i class="bi bi-cash-coin" aria-hidden="true"></i> Lipa pesa taslimu mzigo ukifika</li>
                <li><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Agiza tena kwa kubofya mara moja</li>
            </ul>
        </aside>

        <div class="auth-card__body">
            <form class="auth-step" id="phone-form" novalidate data-auth-step="phone" <?= $auth_data['start_step'] === 'phone' ? '' : 'hidden' ?>>
                <p class="auth-step__progress">Ingia au jisajili</p>
                <h1 class="auth-step__title">Ingia au jisajili</h1>
                <p class="auth-step__text">Weka namba yako ya simu ili uendelee.</p>

                <div class="auth-step__field" data-field>
                    <label class="form-label" for="user_phone">Namba ya simu</label>
                    <div class="phone-input">
                        <span class="phone-input__prefix">+255</span>
                        <input class="form-control" id="user_phone" name="user_phone" type="tel" inputmode="numeric" autocomplete="tel-national"
                            placeholder="712 345 678" maxlength="16" required>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg w-100" type="submit">Endelea</button>
                <p class="auth-step__legal">
                    Kwa kuendelea unakubali <a href="<?= e(url('terms.php')) ?>">Vigezo na Masharti</a>
                    na <a href="<?= e(url('privacy.php')) ?>">Sera ya Faragha</a>.
                </p>
            </form>

            <form class="auth-step" id="pin-form" novalidate hidden data-auth-step="pin">
                <p class="auth-step__progress">Ingia kwa PIN</p>
                <h1 class="auth-step__title">Weka PIN yako</h1>
                <p class="auth-step__text">PIN ya akaunti ya <strong data-sent-phone></strong>.</p>
                <div class="auth-step__field" data-field>
                    <label class="form-label" for="login_pin">PIN</label>
                    <input class="form-control" id="login_pin" name="user_pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6" pattern="[0-9]{4,6}" required data-pin-input>
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Ingia</button>
                <button class="btn btn-link w-100 mt-2" type="button" data-forgot-pin>Umesahau PIN?</button>
                <button class="btn btn-link auth-step__inline-link" type="button" data-change-phone>Badilisha namba</button>
            </form>

            <div class="auth-step" hidden data-auth-step="pin_locked">
                <p class="auth-step__progress">PIN imefungwa</p>
                <h1 class="auth-step__title">PIN imefungwa kwa muda</h1>
                <p class="auth-step__text" data-pin-locked-message>PIN imefungwa baada ya majaribio mengi.</p>
                <button class="btn btn-primary btn-lg w-100" type="button" data-forgot-pin>Umesahau PIN?</button>
                <button class="btn btn-link w-100 mt-2" type="button" data-change-phone>Badilisha namba</button>
            </div>

            <form class="auth-step" id="code-form" novalidate hidden data-auth-step="code">
                <p class="auth-step__progress">Thibitisha simu</p>
                <h1 class="auth-step__title">Thibitisha namba yako</h1>
                <p class="auth-step__text">
                    Tumetuma msimbo wa uthibitisho kwa <strong data-sent-phone></strong>.
                    <button class="btn btn-link auth-step__inline-link" type="button" data-change-phone>Badilisha</button>
                </p>

                <div class="auth-step__field" data-field>
                    <div class="otp-input" role="group" aria-label="Msimbo wa uthibitisho wenye tarakimu 6" data-otp-digits>
                        <?php for ($digit = 1; $digit <= 6; $digit++) : ?>
                            <input class="form-control otp-input__digit" type="text" inputmode="numeric" maxlength="1"
                                aria-label="Tarakimu ya <?= $digit ?>" <?= $digit === 1 ? 'autocomplete="one-time-code"' : 'autocomplete="off"' ?>>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="otp_code">
                </div>
                <p class="auth-step__dev-hint" hidden data-dev-hint><i class="bi bi-bug" aria-hidden="true"></i> Hali ya majaribio: namba imejazwa yenyewe.</p>

                <button class="btn btn-primary btn-lg w-100" type="submit">Thibitisha</button>
                <p class="auth-step__resend">
                    <span data-resend-wait></span>
                    <button class="btn btn-link auth-step__inline-link" type="button" hidden data-resend>Tuma namba tena</button>
                </p>
            </form>

            <form class="auth-step" id="create-pin-form" novalidate hidden data-auth-step="create_pin">
                <p class="auth-step__progress">Usalama wa akaunti</p>
                <h1 class="auth-step__title">Tengeneza PIN</h1>
                <p class="auth-step__text">Chagua PIN yenye tarakimu 4 hadi 6. Usitumie mfululizo rahisi wa namba.</p>
                <div class="auth-step__field" data-field>
                    <label class="form-label" for="new_pin">PIN mpya</label>
                    <input class="form-control" id="new_pin" name="user_pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6" pattern="[0-9]{4,6}" required data-pin-input>
                </div>
                <div class="auth-step__field" data-field>
                    <label class="form-label" for="confirm_pin">Rudia PIN mpya</label>
                    <input class="form-control" id="confirm_pin" name="user_pin_confirmation" type="password" inputmode="numeric" autocomplete="off" maxlength="6" pattern="[0-9]{4,6}" required data-pin-input>
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Hifadhi PIN</button>
            </form>

            <form class="auth-step" id="profile-form" novalidate hidden data-auth-step="profile">
                <p class="auth-step__progress">Taarifa za biashara</p>
                <h1 class="auth-step__title">Tuambie kuhusu biashara yako</h1>
                <p class="auth-step__text">Tunatumia taarifa hizi kwa oda zako na usafirishaji.</p>

                <div class="auth-step__field" data-field>
                    <label class="form-label" for="user_full_name">Jina lako kamili</label>
                    <input class="form-control" id="user_full_name" name="user_full_name" autocomplete="name" required
                        value="<?= e($customer['user_full_name'] ?? '') ?>">
                </div>
                <div class="auth-step__field" data-field>
                    <label class="form-label" for="business_name">Jina la duka <span class="text-secondary">(hiari)</span></label>
                    <input class="form-control" id="business_name" name="business_name" autocomplete="organization">
                </div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6" data-field>
                        <label class="form-label" for="region_id">Mkoa</label>
                        <select class="form-select" id="region_id" name="region_id" required data-region-select>
                            <option value="">Chagua mkoa</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6" data-field>
                        <label class="form-label" for="district_id">Wilaya <span class="text-secondary">(hiari)</span></label>
                        <select class="form-select" id="district_id" name="district_id" disabled data-district-select>
                            <option value="">Chagua mkoa kwanza</option>
                        </select>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg w-100 mt-4" type="submit">Hifadhi na uendelee</button>
            </form>
        </div>
    </div>
</section>

<script type="application/json" id="auth-data"><?= json_encode($auth_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
