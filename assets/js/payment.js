/**
 * The payment box (includes/payment_box.php): "Nakili" buttons for the number to pay and the reference to write,
 * the "Nimelipa" form → POST /orders/{id}/payment, and "Badilisha njia ya malipo" → POST /orders/{id}/payment-method.
 * After a change the page reloads, so it shows the order as the server now has it (e.g. "Tunakagua malipo yako",
 * or no pay-to box at all after switching to "Lipa ukipokea").
 */
(() => {
    'use strict';

    const { api, phone, submitForm, toast, setLoading } = CHIMBO;

    // These mean the order's payment state changed elsewhere (already sent, locked, or the time to pay is over):
    // show the message, then reload so the page shows the current state.
    const STATE_CHANGED_CODES = ['PAYMENT_UNDER_REVIEW', 'PAYMENT_METHOD_LOCKED', 'PAYMENT_TIME_OVER', 'PAYMENT_NOT_EXPECTED'];
    const RELOAD_DELAY_MS = 2500;

    const reloadSoon = () => setTimeout(() => window.location.reload(), RELOAD_DELAY_MS);

    // ------------------------------------------------------------------ "Nakili"

    function setUpCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(button.dataset.copy);
                    toast(`Imenakiliwa: ${button.dataset.copy}`, 'success');
                } catch {
                    toast('Imeshindikana kunakili. Andika namba hii kwa mkono.', 'error');
                }
            });
        });
    }

    // ------------------------------------------------------------------ "Nimelipa"

    function setUpPaymentForm(form) {
        const payerIsPhone = form.dataset.payerIsPhone === 'true';
        const { payment_payer_account: payerInput, payment_reference: referenceInput } = form.elements;

        if (payerIsPhone) phone.formatInput(payerInput);
        referenceInput.addEventListener('input', () => {
            referenceInput.value = referenceInput.value.toUpperCase().replace(/\s/g, '');
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (payerIsPhone && !phone.check(form, 'payment_payer_account')) return;

            try {
                await submitForm(form, form.querySelector('[type="submit"]'), () => api.post(`/orders/${form.dataset.paymentForm}/payment`, {
                    payment_payer_account: payerIsPhone ? phone.toInternational(payerInput.value) : payerInput.value.trim(),
                    payment_reference: referenceInput.value.trim(),
                }, { silent: true }));
                CHIMBO.successPopupOnNextPage('Tumepokea uthibitisho wako wa malipo, tunaukagua sasa. Tutakujulisha.', 'Asante!');
                window.location.reload();
            } catch (error) {
                if (STATE_CHANGED_CODES.includes(error.code)) reloadSoon();
            }
        });
    }

    function setUpSelcomPaymentForm(form) {
        const phoneInput = form.elements.phone_number;
        const errorMessage = form.querySelector('[data-selcom-payment-error]');
        const submitButton = form.querySelector('[type="submit"]');
        const idempotencyKey = CHIMBO.newIdempotencyKey();

        phone.formatInput(phoneInput);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            errorMessage.hidden = true;
            if (!phone.check(form, 'phone_number')) return;

            CHIMBO.setLoading(submitButton, true);
            try {
                const result = await api.post('/payments/initiate', {
                    order_id: Number(form.dataset.selcomPaymentForm),
                    payment_method: form.dataset.paymentMethod,
                    phone_number: phone.toInternational(phoneInput.value),
                }, { headers: { 'Idempotency-Key': idempotencyKey }, silent: true });
                toast('Tumetuma ombi la malipo. Fuata maelekezo kwenye simu yako.', 'success');
                if (result.payment_status === 'pending' || result.payment_status === 'initiated') window.location.reload();
            } catch (error) {
                errorMessage.textContent = error.displayMessage ?? error.message;
                errorMessage.hidden = false;
            } finally {
                CHIMBO.setLoading(submitButton, false);
            }
        });
    }

    // ------------------------------------------------------------------ "Badilisha njia ya malipo"

    function setUpMethodChange(box) {
        const toggle = box.querySelector('[data-payment-change-toggle]');
        const panel = box.querySelector('[data-payment-change-panel]');
        const errorMessage = box.querySelector('[data-payment-change-error]');
        const saveButton = box.querySelector('[data-payment-change-save]');
        const methodChoices = [...box.querySelectorAll('[data-payment-method-choice]')];
        const currentMethod = box.dataset.currentMethod;
        let chosenMethod = currentMethod;

        const showError = (message) => {
            errorMessage.textContent = message;
            errorMessage.hidden = message === '';
        };

        function showPanel(isShown) {
            panel.hidden = !isShown;
            toggle.hidden = isShown;
            toggle.setAttribute('aria-expanded', String(isShown));
        }

        function openPanel() {
            showPanel(true);
            showError(methodChoices.length === 0 ? 'Hakuna mitandao mingine inayopatikana kwa sasa.' : '');
        }

        function resetSelection() {
            chosenMethod = currentMethod;
            methodChoices.forEach((choice) => {
                choice.checked = choice.value === currentMethod;
            });
            saveButton.disabled = true;
        }

        async function saveMethod() {
            if (chosenMethod === currentMethod || !methodChoices.some((choice) => choice.value === chosenMethod)) return;

            setLoading(saveButton, true);
            try {
                await api.post(`/orders/${box.dataset.paymentChange}/payment-method`, { payment_method: chosenMethod }, { silent: true });
                toast(chosenMethod === 'cod' ? 'Sawa — utalipa ukipokea. Oda yako imethibitishwa.' : 'Njia ya malipo imebadilishwa.', 'success');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                setLoading(saveButton, false);
                saveButton.disabled = chosenMethod === currentMethod;
                showError(error.displayMessage ?? 'Imeshindikana kubadilisha njia ya malipo. Jaribu tena.');
                if (STATE_CHANGED_CODES.includes(error.code)) reloadSoon();
            }
        }

        methodChoices.forEach((choice) => {
            choice.addEventListener('change', () => {
                chosenMethod = choice.value;
                saveButton.disabled = chosenMethod === currentMethod;
                showError('');
            });
        });

        toggle.addEventListener('click', openPanel);
        box.querySelector('[data-payment-change-cancel]').addEventListener('click', () => {
            resetSelection();
            showPanel(false);
        });
        saveButton.addEventListener('click', saveMethod);
    }

    // ------------------------------------------------------------------ Start

    setUpCopyButtons();
    const form = document.querySelector('[data-payment-form]');
    if (form) setUpPaymentForm(form);
    const selcomForm = document.querySelector('[data-selcom-payment-form]');
    if (selcomForm) setUpSelcomPaymentForm(selcomForm);
    const changeBox = document.querySelector('[data-payment-change]');
    if (changeBox) setUpMethodChange(changeBox);
})();
