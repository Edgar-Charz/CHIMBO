<?php

/**
 * Wasifu — the customer's account: photo, quick links, details (editable), "Badilisha PIN", Toka and
 * "Futa akaunti" (assets/js/account.js).
 */

require __DIR__ . '/includes/init.php';

const ACCOUNT_LINKS = [
    ['href' => 'orders.php',        'icon' => 'box-seam', 'label' => 'Oda Zangu'],
    ['href' => 'wishlist.php',      'icon' => 'heart',    'label' => 'Vipendwa'],
    ['href' => 'notifications.php', 'icon' => 'bell',     'label' => 'Arifa'],
    ['href' => 'addresses.php',     'icon' => 'geo-alt',  'label' => 'Anwani zangu'],
];

$customer = requireCustomer();
$business = $customer['business'] ?? [];
$avatar_url = $customer['user_avatar_url'] ?? null;
$name_parts = preg_split('/\s+/u', trim((string) ($customer['user_full_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
$avatar_initials = mb_strtoupper(
    mb_substr($name_parts[0] ?? 'C', 0, 1) . (count($name_parts) > 1 ? mb_substr(end($name_parts), 0, 1) : ''),
    'UTF-8'
);
$business_is_verified = ($business['business_verification_status'] ?? null) === 'verified';
$page = [
    'title' => 'Wasifu',
    'description' => 'Simamia picha, taarifa na usalama wa akaunti yako ya CHIMBO.',
    'nav' => 'account',
    'scripts' => ['location_select.js', 'account.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl account-page">
    <header class="account-page__heading">
        <div>
            <h1 class="h2 mb-1">Wasifu</h1>
            <p class="text-secondary mb-0">Taarifa zako na usalama wa akaunti yako.</p>
        </div>
    </header>

    <section class="account-profile" aria-labelledby="profile-name">
        <div class="account-profile__identity">
            <div class="account-avatar" data-avatar>
                <img class="account-avatar__image" data-avatar-image <?= $avatar_url ? 'src="' . e($avatar_url) . '"' : '' ?>
                     alt="Picha ya <?= e($customer['user_full_name'] ?? 'mteja') ?>" <?= $avatar_url ? '' : 'hidden' ?>>
                <span class="account-avatar__initials" data-avatar-initials <?= $avatar_url ? 'hidden' : '' ?>><?= e($avatar_initials) ?></span>
            </div>
            <div class="account-profile__copy">
                <p class="account-profile__eyebrow">KARIBU CHIMBO</p>
                <h2 id="profile-name"><?= e($customer['user_full_name'] ?? 'Mteja wa CHIMBO') ?></h2>
                <p class="account-profile__phone"><i class="bi bi-telephone" aria-hidden="true"></i> <?= e($customer['user_phone']) ?></p>
                <span class="account-profile__badge <?= $business_is_verified ? 'is-verified' : '' ?>">
                    <i class="bi <?= $business_is_verified ? 'bi-patch-check-fill' : 'bi-shop' ?>" aria-hidden="true"></i>
                    <?= $business_is_verified ? 'Biashara imethibitishwa' : 'Akaunti ya biashara' ?>
                </span>
            </div>
        </div>

        <form class="account-avatar-editor" id="avatar-form" novalidate>
            <div data-field>
                <label class="account-avatar-editor__label" for="avatar-file">Picha ya wasifu</label>
                <p class="account-avatar-editor__hint">JPG, PNG au WEBP; angalau pikseli 200 na hadi MB 8.</p>
                <input class="visually-hidden" id="avatar-file" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="avatar-hint">
            </div>
            <div class="account-avatar-editor__actions">
                <label class="btn btn-light" for="avatar-file">
                    <i class="bi bi-camera me-1" aria-hidden="true"></i> Chagua picha
                </label>
                <button class="btn btn-primary" id="avatar-save-button" type="submit" disabled>Hifadhi picha</button>
                <button class="btn btn-outline-light" id="avatar-remove-button" type="button" <?= $avatar_url ? '' : 'hidden' ?>>Ondoa picha</button>
            </div>
            <div class="account-avatar-editor__hint" id="avatar-hint" aria-live="polite">Picha inaonekana kwenye wasifu wako tu.</div>
        </form>
    </section>

    <nav class="account-links" aria-label="Akaunti yako">
        <?php foreach (ACCOUNT_LINKS as $account_link) : ?>
            <a class="account-link" href="<?= e(url($account_link['href'])) ?>">
                <span class="account-link__icon"><i class="bi bi-<?= e($account_link['icon']) ?>" aria-hidden="true"></i></span>
                <span class="account-link__label"><?= e($account_link['label']) ?></span>
                <i class="bi bi-chevron-right account-link__arrow" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="account-page__grid">
        <section class="account-card" aria-labelledby="account-details-heading">
            <div class="account-card__heading">
                <span class="account-card__icon"><i class="bi bi-person-lines-fill" aria-hidden="true"></i></span>
                <div>
                    <h2 id="account-details-heading">Taarifa za akaunti</h2>
                    <p>Maelezo ya msingi ya akaunti yako.</p>
                </div>
            </div>
            <dl class="account-details">
                <div class="account-details__row">
                    <dt>Jina kamili</dt>
                    <dd><?= e($customer['user_full_name'] ?? '—') ?></dd>
                </div>
                <div class="account-details__row">
                    <dt>Namba ya simu</dt>
                    <dd><?= e($customer['user_phone']) ?></dd>
                </div>
                <div class="account-details__row">
                    <dt>Duka</dt>
                    <dd><?= e($business['business_name'] ?? 'Bado halijaongezwa') ?></dd>
                </div>
                <div class="account-details__row">
                    <dt>Mkoa</dt>
                    <dd><?= e(implode(', ', array_filter([$business['district_name'] ?? null, $business['region_name'] ?? null])) ?: 'Bado haujachaguliwa') ?></dd>
                </div>
                <div class="account-details__row">
                    <dt>Barua pepe</dt>
                    <dd><?= e($customer['user_email'] ?? 'Hakuna') ?></dd>
                </div>
            </dl>
            <button class="btn btn-outline-primary btn-sm mt-3" type="button" aria-expanded="false" aria-controls="details-form" data-details-toggle>
                <i class="bi bi-pencil" aria-hidden="true"></i> Badilisha taarifa
            </button>

            <form class="account-details-form" id="details-form" novalidate hidden
                data-region-id="<?= e($business['region_id'] ?? '') ?>" data-district-id="<?= e($business['district_id'] ?? '') ?>">
                <div class="row g-3">
                    <div class="col-12" data-field>
                        <label class="form-label" for="details_full_name">Jina kamili</label>
                        <input class="form-control" id="details_full_name" name="user_full_name" autocomplete="name" required value="<?= e($customer['user_full_name'] ?? '') ?>">
                    </div>
                    <div class="col-12" data-field>
                        <label class="form-label" for="details_email">Barua pepe <span class="text-secondary">(hiari)</span></label>
                        <input class="form-control" id="details_email" name="user_email" type="email" autocomplete="email" value="<?= e($customer['user_email'] ?? '') ?>">
                    </div>
                    <div class="col-12" data-field>
                        <label class="form-label" for="details_business_name">Jina la duka <span class="text-secondary">(hiari)</span></label>
                        <input class="form-control" id="details_business_name" name="business_name" autocomplete="organization" value="<?= e($business['business_name'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6" data-field>
                        <label class="form-label" for="details_region_id">Mkoa</label>
                        <select class="form-select" id="details_region_id" name="region_id" required></select>
                    </div>
                    <div class="col-12 col-sm-6" data-field>
                        <label class="form-label" for="details_district_id">Wilaya <span class="text-secondary">(hiari)</span></label>
                        <select class="form-select" id="details_district_id" name="district_id" disabled></select>
                    </div>
                </div>
                <div class="address-form__actions">
                    <button class="btn btn-primary" type="submit">Hifadhi</button>
                    <button class="btn btn-link" type="button" data-details-cancel>Ghairi</button>
                </div>
            </form>
        </section>

        <section class="account-card account-security" aria-labelledby="change-pin-heading">
            <div class="account-card__heading">
                <span class="account-card__icon account-card__icon--security"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <div>
                    <h2 id="change-pin-heading">Usalama wa akaunti</h2>
                    <p>Linda akaunti yako kwa PIN ya kipekee.</p>
                </div>
            </div>
            <div class="account-security__status">
                <span><i class="bi bi-check-circle-fill" aria-hidden="true"></i> PIN imewashwa</span>
                <span>Tarakimu 4–6</span>
            </div>
            <form id="change-pin-form" class="account-pin-form" novalidate>
                <div class="mb-3" data-field>
                    <label class="form-label" for="current_pin">PIN ya sasa</label>
                    <input class="form-control" id="current_pin" name="current_pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6" pattern="[0-9]{4,6}" required data-pin-input>
                </div>
                <div class="mb-3" data-field>
                    <label class="form-label" for="new_pin">PIN mpya</label>
                    <input class="form-control" id="new_pin" name="user_pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6" pattern="[0-9]{4,6}" required data-pin-input>
                </div>
                <div class="mb-3" data-field>
                    <label class="form-label" for="confirm_pin">Rudia PIN mpya</label>
                    <input class="form-control" id="confirm_pin" name="user_pin_confirmation" type="password" inputmode="numeric" autocomplete="off" maxlength="6" pattern="[0-9]{4,6}" required data-pin-input>
                </div>
                <div class="account-pin-form__actions">
                    <button class="btn btn-primary" type="submit">Hifadhi PIN mpya</button>
                    <a href="<?= e(url('login.php')) ?>?forgot_pin=1&amp;return=<?= e(rawurlencode(url('account.php'))) ?>">Umesahau PIN?</a>
                </div>
            </form>
        </section>
    </div>

    <section class="account-card account-exit" aria-labelledby="account-exit-heading">
        <h2 class="visually-hidden" id="account-exit-heading">Toka au futa akaunti</h2>
        <button class="btn btn-outline-primary" type="button" data-logout><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Toka</button>
        <button class="btn btn-link account-exit__delete" type="button" data-bs-toggle="modal" data-bs-target="#delete-account-dialog">Futa akaunti yangu</button>
    </section>
</section>

<div class="modal fade" id="delete-account-dialog" tabindex="-1" aria-labelledby="delete-account-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="delete-account-title">Futa akaunti yako?</h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Funga"></button>
            </div>
            <div class="modal-body">
                <p>Taarifa zako binafsi, anwani, vipendwa na kikapu vitafutwa, na utatoka kwenye vifaa vyote. Oda za zamani zinabaki kwenye kumbukumbu za CHIMBO.</p>
                <p class="mb-0">Huwezi kutendua hili. Unaweza kujisajili tena kwa namba hii baadaye kama mteja mpya.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-link" type="button" data-bs-dismiss="modal">Hapana, nibaki</button>
                <button class="btn btn-danger" type="button" data-delete-account>Ndiyo, futa akaunti</button>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
