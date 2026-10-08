/**
 * The shared address form (includes/address_form.php) for checkout and Anwani zangu: opens empty (with suggested
 * values) or with an address to edit, tidies the phone, loads region → district and saves the address.
 *   const form = CHIMBO.addressForm.attach(formElement, { suggested, onSaved: (address) => …, onClose: () => … });
 *   form.open()  /  form.open(address)  /  form.close()
 *   CHIMBO.addressForm.describe(address) → the text lines for an address card
 */
(() => {
    'use strict';

    const { api, submitForm, clearFieldErrors, locationSelect, phone } = CHIMBO;

    function attach(form, { suggested = {}, onSaved, onClose = () => {} }) {
        const { elements } = form;
        const title = form.querySelector('[data-address-form-title]');
        let editingAddressId = null;

        phone.formatInput(elements.address_phone);

        async function fill(values) {
            elements.address_recipient_name.value = values.address_recipient_name ?? '';
            elements.address_phone.value = values.address_phone ?? '';
            elements.address_phone.dispatchEvent(new Event('input')); // "+255712…" → "712 345 678"
            elements.address_street.value = values.address_street ?? '';
            elements.address_landmark.value = values.address_landmark ?? '';
            elements.address_is_default.checked = Boolean(values.address_is_default);
            await locationSelect.connect(elements.region_id, elements.district_id, {
                regionId: values.region_id ?? null,
                districtId: values.district_id ?? null,
            });
        }

        /** No address → "Anwani mpya" with the suggested values; an address → edit it. */
        async function open(address = null) {
            editingAddressId = address?.address_id ?? null;
            title.textContent = address === null ? 'Anwani mpya' : 'Badilisha anwani';
            clearFieldErrors(form);
            form.hidden = false;
            await fill(address ?? suggested);
            (address === null && elements.address_recipient_name.value ? elements.address_street : elements.address_recipient_name).focus();
        }

        function close() {
            form.hidden = true;
            form.reset();
            editingAddressId = null;
            onClose();
        }

        function readValues() {
            return {
                address_recipient_name: elements.address_recipient_name.value.trim(),
                address_phone: phone.toInternational(elements.address_phone.value),
                region_id: locationSelect.selectedNumber(elements.region_id),
                district_id: locationSelect.selectedNumber(elements.district_id),
                address_street: elements.address_street.value.trim(),
                address_landmark: elements.address_landmark.value.trim() || null,
                address_is_default: elements.address_is_default.checked,
            };
        }

        form.querySelector('[data-address-cancel]').addEventListener('click', close);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!phone.check(form, 'address_phone')) return;

            const values = readValues();
            const save = editingAddressId === null
                ? () => api.post('/addresses', values, { silent: true })
                : () => api.patch(`/addresses/${editingAddressId}`, values, { silent: true });
            try {
                const address = await submitForm(form, form.querySelector('[type="submit"]'), save);
                close();
                onSaved(address);
            } catch {
                // the messages are shown under the fields
            }
        });

        return { open, close };
    }

    /** The lines that describe an address on cards: street · landmark, district, region, phone. */
    const describe = (address) => [
        [address.address_street, address.address_landmark].filter(Boolean).join(' · '),
        [address.district_name, address.region_name].filter(Boolean).join(', '),
        address.address_phone,
    ];

    CHIMBO.addressForm = { attach, describe };
})();
