/**
 * The payment box (includes/payment_box.php): "Nakili" buttons for the number to pay and the reference to write,
 * the "Nimelipa" form → POST /orders/{id}/payment, and "Badilisha njia ya malipo" → POST /orders/{id}/payment-method.
 * After a change the page reloads, so it shows the order as the server now has it (e.g. "Tunakagua malipo yako",
 * or no pay-to box at all after switching to "Lipa ukipokea").
 */
(() => {
    'use strict';

    const { api, phone, submitForm, toast, setLoading, paymentChoiceCard } = CHIMBO;

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
                toast('Asante! Tumepokea uthibitisho wako, tunaukagua sasa.', 'success');
                window.location.reload();
            } catch (error) {
                if (STATE_CHANGED_CODES.includes(error.code)) reloadSoon();
            }
        });
    }

    // ------------------------------------------------------------------ "Badilisha njia ya malipo"

    function setUpMethodChange(box) {
        const toggle = box.querySelector('[data-payment-change-toggle]');
        const panel = box.querySelector('[data-payment-change-panel]');
        const list = box.querySelector('[data-payment-change-list]');
        const errorMessage = box.querySelector('[data-payment-change-error]');
        const saveButton = box.querySelector('[data-payment-change-save]');
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

        async function openPanel() {
            showPanel(true);
            showError('');
            setLoading(toggle, true);
            try {
                const methods = await api.get('/payment-methods', { silent: true });
                list.replaceChildren(...methods.map((method) => paymentChoiceCard(method, {
                    isChecked: method.payment_method_code === chosenMethod,
                    tag: method.payment_method_code === currentMethod ? 'Sasa hivi' : null,
                    onSelect: (code) => {
                        chosenMethod = code;
                        saveButton.disabled = code === currentMethod;
                        showError('');
                    },
                })));
            } catch (error) {
                showError(error.displayMessage);
            } finally {
                setLoading(toggle, false);
            }
        }

        async function saveMethod() {
            setLoading(saveButton, true);
            try {
                await api.post(`/orders/${box.dataset.paymentChange}/payment-method`, { payment_method: chosenMethod }, { silent: true });
                toast(chosenMethod === 'cod' ? 'Sawa — utalipa ukipokea. Oda yako imethibitishwa.' : 'Njia ya malipo imebadilishwa.', 'success');
                window.location.reload();
            } catch (error) {
                setLoading(saveButton, false);
                showError(error.displayMessage); // e.g. "Lipa ukipokea" is only for orders up to the cash limit
                if (STATE_CHANGED_CODES.includes(error.code)) reloadSoon();
            }
        }

        toggle.addEventListener('click', openPanel);
        box.querySelector('[data-payment-change-cancel]').addEventListener('click', () => showPanel(false));
        saveButton.addEventListener('click', saveMethod);
    }

    // ------------------------------------------------------------------ Start

    setUpCopyButtons();
    const form = document.querySelector('[data-payment-form]');
    if (form) setUpPaymentForm(form);
    const changeBox = document.querySelector('[data-payment-change]');
    if (changeBox) setUpMethodChange(changeBox);
})();
