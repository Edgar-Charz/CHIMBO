/**
 * Login page: phone → PIN or SMS code → PIN setup → business details, without page reloads.
 * The website logs in with a session cookie ("client": "web"); nothing secret is stored in the browser.
 * At the end the guest cart moves into the account and the customer goes back to where they came from.
 */
(() => {
    'use strict';

    const { api, submitForm, toast, locationSelect } = CHIMBO;

    const CODE_LENGTH = 6;
    const SECOND_MS = 1000;
    const REDIRECT_DELAY_WITH_MESSAGES_MS = 2500; // time to read "some items could not be added" toasts

    const authData = JSON.parse(document.getElementById('auth-data').textContent);
    const steps = [...document.querySelectorAll('[data-auth-step]')];
    const phoneForm = document.getElementById('phone-form');
    const pinForm = document.getElementById('pin-form');
    const codeForm = document.getElementById('code-form');
    const createPinForm = document.getElementById('create-pin-form');
    const profileForm = document.getElementById('profile-form');
    const digitInputs = [...codeForm.querySelectorAll('.otp-input__digit')];

    let verifiedPhone = '';   // the phone in the server's format (+2557…), from step 1
    let pendingPinReset = false;
    let resendTimer = null;

    const submitButtonOf = (form) => form.querySelector('[type="submit"]');

    function showStep(name) {
        steps.forEach((step) => { step.hidden = step.dataset.authStep !== name; });
        const activeStep = steps.find((step) => step.dataset.authStep === name);
        (activeStep.querySelector('input:not([type="hidden"]), select') ?? activeStep).focus();
    }

    // ------------------------------------------------------------------ Step 1: phone

    async function requestCode(phone) {
        const result = await api.post('/auth/otp/request', { user_phone: phone }, { silent: true });
        verifiedPhone = result.user_phone;
        codeForm.querySelector('[data-sent-phone]').textContent = result.user_phone;
        startResendCountdown(result.otp_resend_after_seconds);
        return result;
    }

    CHIMBO.phone.formatInput(phoneForm.elements.user_phone);

    phoneForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!CHIMBO.phone.check(phoneForm, 'user_phone')) return;
        try {
            const phone = CHIMBO.phone.toInternational(phoneForm.elements.user_phone.value);
            const result = await submitForm(phoneForm, submitButtonOf(phoneForm), () => api.post('/auth/start', { user_phone: phone }, { silent: true }));
            verifiedPhone = result.user_phone;
            pinForm.querySelector('[data-sent-phone]').textContent = verifiedPhone;
            if (result.next_step === 'otp') {
                pendingPinReset = false;
                prepareCodeStep(result);
            } else if (result.next_step === 'pin_locked') {
                document.querySelector('[data-pin-locked-message]').textContent = 'PIN imefungwa baada ya majaribio mengi. Tumia SMS kuthibitisha namba yako na kuweka PIN mpya.';
                showStep('pin_locked');
            } else {
                showStep('pin');
            }
        } catch {
            // the message is shown under the phone field
        }
    });

    function prepareCodeStep(result) {
        showStep('code');
        clearDigits();
        if (result?.otp_resend_after_seconds) startResendCountdown(result.otp_resend_after_seconds);
        if (result?.debug_otp_code) fillDigits(result.debug_otp_code, { isDevelopmentCode: true });
    }

    function bindPinInputs(form) {
        form.querySelectorAll('[data-pin-input]').forEach(CHIMBO.formatPinInput);
    }

    bindPinInputs(pinForm);
    bindPinInputs(createPinForm);

    pinForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const { user } = await submitForm(pinForm, submitButtonOf(pinForm), () => api.post('/auth/pin/login', {
                user_phone: verifiedPhone,
                user_pin: String(pinForm.elements.user_pin.value),
                client: 'web',
            }, { silent: true }), { fieldForCode: { PIN_INVALID: 'user_pin' } });
            await continueAfterLogin(user);
        } catch (error) {
            if (error.code === 'PIN_LOCKED') {
                document.querySelector('[data-pin-locked-message]').textContent = error.message;
                showStep('pin_locked');
            }
        }
    });

    document.querySelectorAll('[data-forgot-pin]').forEach((button) => {
        button.addEventListener('click', async () => {
            CHIMBO.setLoading(button, true);
            try {
                pendingPinReset = true;
                const result = await requestCode(verifiedPhone);
                prepareCodeStep(result);
            } catch (error) {
                toast(error.displayMessage, 'error');
            } finally {
                CHIMBO.setLoading(button, false);
            }
        });
    });

    document.querySelectorAll('[data-change-phone]').forEach((button) => {
        button.addEventListener('click', () => {
            clearInterval(resendTimer);
            pendingPinReset = false;
            showStep('phone');
        });
    });

    // ------------------------------------------------------------------ Step 2: code

    const enteredCode = () => digitInputs.map((input) => input.value).join('');

    function clearDigits() {
        digitInputs.forEach((input) => { input.value = ''; });
        codeForm.querySelector('[data-dev-hint]').hidden = true;
        digitInputs[0].focus();
    }

    function fillDigits(code, { isDevelopmentCode = false } = {}) {
        [...code.slice(0, CODE_LENGTH)].forEach((digit, index) => { digitInputs[index].value = digit; });
        codeForm.querySelector('[data-dev-hint]').hidden = !isDevelopmentCode;
        submitButtonOf(codeForm).focus();
    }

    digitInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(-1);
            if (input.value && index < CODE_LENGTH - 1) digitInputs[index + 1].focus();
            if (enteredCode().length === CODE_LENGTH) codeForm.requestSubmit();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && input.value === '' && index > 0) digitInputs[index - 1].focus();
        });
        input.addEventListener('paste', (event) => {
            const pasted = event.clipboardData.getData('text').replace(/\D/g, '');
            if (pasted.length < 2) return;
            event.preventDefault();
            fillDigits(pasted);
            if (pasted.length >= CODE_LENGTH) codeForm.requestSubmit();
        });
    });

    function startResendCountdown(seconds) {
        const waitLabel = codeForm.querySelector('[data-resend-wait]');
        const resendButton = codeForm.querySelector('[data-resend]');
        let secondsLeft = seconds;

        clearInterval(resendTimer);
        const tick = () => {
            waitLabel.textContent = `Tuma tena baada ya sekunde ${secondsLeft}`;
            waitLabel.hidden = secondsLeft <= 0;
            resendButton.hidden = secondsLeft > 0;
            if (secondsLeft <= 0) clearInterval(resendTimer);
            secondsLeft -= 1;
        };
        tick();
        resendTimer = setInterval(tick, SECOND_MS);
    }

    codeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        codeForm.elements.otp_code.value = enteredCode();
        try {
            const { user } = await submitForm(codeForm, submitButtonOf(codeForm), () => api.post('/auth/otp/verify', {
                user_phone: verifiedPhone,
                otp_code: enteredCode(),
                client: 'web',
            }, { silent: true }));

            if (pendingPinReset) {
                showStep('create_pin');
            } else {
                await continueAfterLogin(user);
            }
        } catch {
            clearDigits(); // wrong or expired code: type it again (the message is shown)
        }
    });

    codeForm.querySelector('[data-resend]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        CHIMBO.setLoading(button, true);
        try {
            const result = await requestCode(verifiedPhone);
            clearDigits();
            if (result.debug_otp_code) fillDigits(result.debug_otp_code, { isDevelopmentCode: true });
            toast('Namba mpya imetumwa.', 'success');
        } catch (error) {
            toast(error.displayMessage, 'error');
        } finally {
            CHIMBO.setLoading(button, false);
        }
    });

    // ------------------------------------------------------------------ Step 3: business details

    async function continueAfterLogin(user) {
        if (!user.user_has_pin) {
            showStep('create_pin');
            return;
        }
        if (!user.is_profile_complete) {
            profileForm.elements.user_full_name.value = user.user_full_name ?? '';
            await showProfileStep();
            return;
        }
        await finishLogin();
    }

    createPinForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const { elements } = createPinForm;
            const user = await submitForm(createPinForm, submitButtonOf(createPinForm), () => api.post('/auth/pin', {
                user_pin: String(elements.user_pin.value),
                user_pin_confirmation: String(elements.user_pin_confirmation.value),
            }, { silent: true }));
            await continueAfterLogin(user);
        } catch {
            // API field messages are shown under the PIN inputs.
        }
    });

    async function showProfileStep() {
        showStep('profile');
        try {
            await locationSelect.connect(profileForm.elements.region_id, profileForm.elements.district_id);
        } catch (error) {
            toast(error.displayMessage, 'error');
        }
    }

    profileForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const { elements } = profileForm;
        try {
            await submitForm(profileForm, submitButtonOf(profileForm), () => api.post('/auth/profile', {
                user_full_name: elements.user_full_name.value.trim(),
                business_name: elements.business_name.value.trim() || null,
                region_id: locationSelect.selectedNumber(elements.region_id),
                district_id: locationSelect.selectedNumber(elements.district_id),
            }, { silent: true }));
            await finishLogin();
        } catch {
            // the messages are shown under the fields
        }
    });

    // ------------------------------------------------------------------ Done

    /** Moves the guest cart into the account, then returns to the page the customer came from. */
    async function finishLogin() {
        let messages = [];
        try {
            messages = (await CHIMBO.cart.mergeGuestCart()).map((skipped) => skipped.message);
        } catch {
            messages = ['Hatukuweza kuhamisha kikapu chako cha awali. Bidhaa zako bado ziko kwenye kivinjari hiki.'];
        }
        messages.forEach((message) => toast(message, 'info'));
        toast('Umeingia. Karibu CHIMBO!', 'success');

        const goBack = () => window.location.assign(authData.return_path);
        if (messages.length > 0) {
            setTimeout(goBack, REDIRECT_DELAY_WITH_MESSAGES_MS);
        } else {
            goBack();
        }
    }

    if (authData.start_step === 'profile') {
        showProfileStep();
    } else if (authData.start_step === 'create_pin') {
        showStep('create_pin');
    } else if (authData.start_step === 'forgot_pin') {
        verifiedPhone = authData.user_phone;
        pendingPinReset = true;
        requestCode(verifiedPhone).then(prepareCodeStep).catch((error) => {
            showStep('pin_locked');
            toast(error.displayMessage, 'error');
        });
    } else {
        showStep('phone');
    }
})();
