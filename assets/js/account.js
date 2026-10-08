/** Wasifu page: profile photo, editing the details, "Futa akaunti" and "Badilisha PIN". */
(() => {
    'use strict';

    const MAX_AVATAR_SIZE_BYTES = 8 * 1024 * 1024;

    const avatarForm = document.getElementById('avatar-form');
    const avatarInput = document.getElementById('avatar-file');
    const avatarImage = document.querySelector('[data-avatar-image]');
    const avatarInitials = document.querySelector('[data-avatar-initials]');
    const avatarHint = document.getElementById('avatar-hint');
    const avatarSaveButton = document.getElementById('avatar-save-button');
    const avatarRemoveButton = document.getElementById('avatar-remove-button');
    let savedAvatarUrl = avatarImage?.getAttribute('src') || '';
    let previewUrl = null;

    function showAvatar(imageUrl) {
        const hasImage = Boolean(imageUrl);
        avatarImage.hidden = !hasImage;
        avatarInitials.hidden = hasImage;
        avatarRemoveButton.hidden = !hasImage;
        if (hasImage) avatarImage.src = imageUrl;
        else avatarImage.removeAttribute('src');
    }

    function clearPreview() {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    }

    if (avatarForm && avatarInput && avatarImage && avatarInitials && avatarHint && avatarSaveButton && avatarRemoveButton) {
        avatarInput.addEventListener('change', () => {
            CHIMBO.clearFieldErrors(avatarForm);
            clearPreview();

            const file = avatarInput.files[0];
            avatarSaveButton.disabled = !file;
            showAvatar(savedAvatarUrl);
            if (!file) {
                avatarHint.textContent = 'Picha inaonekana kwenye wasifu wako tu.';
                return;
            }

            if (file.size > MAX_AVATAR_SIZE_BYTES) {
                CHIMBO.showFieldErrors(avatarForm, { avatar: 'Picha isiwe kubwa kuliko MB 8.' });
                avatarSaveButton.disabled = true;
                avatarInput.value = '';
                return;
            }

            previewUrl = URL.createObjectURL(file);
            showAvatar(previewUrl);
            avatarHint.textContent = file.name;
        });

        avatarForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const file = avatarInput.files[0];
            if (!file) return;

            await CHIMBO.submitForm(avatarForm, avatarSaveButton, () => {
                const formData = new FormData();
                formData.append('avatar', file);
                return CHIMBO.api.postForm('/me/avatar', formData, { silent: true });
            }).then((profile) => {
                savedAvatarUrl = profile.user_avatar_url;
                clearPreview();
                showAvatar(savedAvatarUrl);
                avatarInput.value = '';
                avatarSaveButton.disabled = true;
                avatarHint.textContent = 'Picha yako ya wasifu imehifadhiwa.';
                CHIMBO.toast('Picha ya wasifu imehifadhiwa.', 'success');
            }).catch(() => {
                clearPreview();
                showAvatar(savedAvatarUrl);
                avatarHint.textContent = 'Chagua picha nyingine au jaribu tena.';
            });
        });

        avatarRemoveButton.addEventListener('click', async () => {
            CHIMBO.setLoading(avatarRemoveButton, true);
            try {
                await CHIMBO.api.delete('/me/avatar', { silent: true });
                savedAvatarUrl = '';
                clearPreview();
                avatarInput.value = '';
                avatarSaveButton.disabled = true;
                showAvatar('');
                avatarHint.textContent = 'Picha imeondolewa. Herufi za jina lako zinaonekana sasa.';
                CHIMBO.toast('Picha imeondolewa.', 'success');
            } catch (error) {
                CHIMBO.toast(error.displayMessage ?? error.message, 'error');
            } finally {
                CHIMBO.setLoading(avatarRemoveButton, false);
            }
        });
    }

    // ------------------------------------------------------------------ Badilisha taarifa (name, email, business)

    const detailsForm = document.getElementById('details-form');
    const detailsToggle = document.querySelector('[data-details-toggle]');

    async function showDetailsForm(isShown) {
        detailsForm.hidden = !isShown;
        detailsToggle.hidden = isShown;
        detailsToggle.setAttribute('aria-expanded', String(isShown));
        if (!isShown) return;

        const toNumber = (value) => (value === '' ? null : Number(value));
        await CHIMBO.locationSelect.connect(detailsForm.elements.region_id, detailsForm.elements.district_id, {
            regionId: toNumber(detailsForm.dataset.regionId),
            districtId: toNumber(detailsForm.dataset.districtId),
        });
        detailsForm.elements.user_full_name.focus();
    }

    detailsToggle.addEventListener('click', () => showDetailsForm(true));
    detailsForm.querySelector('[data-details-cancel]').addEventListener('click', () => showDetailsForm(false));

    detailsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const { elements } = detailsForm;
        try {
            await CHIMBO.submitForm(detailsForm, detailsForm.querySelector('[type="submit"]'), async () => {
                await CHIMBO.api.patch('/me', {
                    user_full_name: elements.user_full_name.value.trim(),
                    user_email: elements.user_email.value.trim(), // empty removes the email
                }, { silent: true });
                return CHIMBO.api.patch('/me/business', {
                    business_name: elements.business_name.value.trim() || null,
                    region_id: CHIMBO.locationSelect.selectedNumber(elements.region_id),
                    district_id: CHIMBO.locationSelect.selectedNumber(elements.district_id),
                }, { silent: true });
            });
            window.location.reload(); // the page shows the saved details (also in the header greeting)
        } catch {
            // the messages are shown under the fields
        }
    });

    // ------------------------------------------------------------------ Futa akaunti

    const deleteButton = document.querySelector('[data-delete-account]');
    deleteButton.addEventListener('click', async () => {
        CHIMBO.setLoading(deleteButton, true);
        try {
            await CHIMBO.api.delete('/me', { body: { confirm: true } });
            window.location.assign(CHIMBO.url());
        } catch {
            CHIMBO.setLoading(deleteButton, false); // the error toast was already shown
        }
    });

    // ------------------------------------------------------------------ Badilisha PIN

    const pinForm = document.getElementById('change-pin-form');
    if (!pinForm) return;

    const pinSubmitButton = pinForm.querySelector('[type="submit"]');
    pinForm.querySelectorAll('[data-pin-input]').forEach(CHIMBO.formatPinInput);

    pinForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const { elements } = pinForm;
        try {
            await CHIMBO.submitForm(pinForm, pinSubmitButton, () => CHIMBO.api.post('/auth/pin', {
                current_pin: String(elements.current_pin.value),
                user_pin: String(elements.user_pin.value),
                user_pin_confirmation: String(elements.user_pin_confirmation.value),
            }, { silent: true }), {
                fieldForCode: { PIN_INVALID: 'current_pin' },
            });
            pinForm.reset();
            CHIMBO.toast('PIN yako imebadilishwa.', 'success');
        } catch {
            // submitForm shows server field errors or the API message.
        }
    });
})();
