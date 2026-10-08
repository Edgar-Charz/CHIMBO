<?php

/**
 * The address form ("Anwani mpya" / edit) used by checkout and Anwani zangu.
 * assets/js/address_form.js fills it, checks the phone, loads regions → districts and saves it.
 */
?>
<form class="address-form" id="address-form" novalidate hidden data-address-form>
    <h3 class="address-form__title" data-address-form-title>Anwani mpya</h3>
    <div class="row g-3">
        <div class="col-12 col-sm-6" data-field>
            <label class="form-label" for="address_recipient_name">Jina la mpokeaji</label>
            <input class="form-control" id="address_recipient_name" name="address_recipient_name" autocomplete="name" required>
        </div>
        <div class="col-12 col-sm-6" data-field>
            <label class="form-label" for="address_phone">Simu ya mpokeaji</label>
            <div class="phone-input">
                <span class="phone-input__prefix">+255</span>
                <input class="form-control" id="address_phone" name="address_phone" type="tel" inputmode="numeric" autocomplete="tel-national"
                    placeholder="712 345 678" maxlength="16" required>
            </div>
        </div>
        <div class="col-12 col-sm-6" data-field>
            <label class="form-label" for="address_region_id">Mkoa</label>
            <select class="form-select" id="address_region_id" name="region_id" required></select>
        </div>
        <div class="col-12 col-sm-6" data-field>
            <label class="form-label" for="address_district_id">Wilaya <span class="text-secondary">(hiari)</span></label>
            <select class="form-select" id="address_district_id" name="district_id" disabled></select>
        </div>
        <div class="col-12" data-field>
            <label class="form-label" for="address_street">Mtaa na eneo</label>
            <input class="form-control" id="address_street" name="address_street" autocomplete="street-address" placeholder="Mf. Kariakoo, Mtaa wa Lumumba" required>
        </div>
        <div class="col-12" data-field>
            <label class="form-label" for="address_landmark">Alama ya karibu <span class="text-secondary">(hiari)</span></label>
            <input class="form-control" id="address_landmark" name="address_landmark" placeholder="Mf. Karibu na msikiti, jengo la bluu">
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" id="address_is_default" name="address_is_default" type="checkbox">
                <label class="form-check-label" for="address_is_default">Tumia kama anwani yangu kuu</label>
            </div>
        </div>
    </div>
    <div class="address-form__actions">
        <button class="btn btn-primary" type="submit">Hifadhi anwani</button>
        <button class="btn btn-link" type="button" data-address-cancel>Ghairi</button>
    </div>
</form>
