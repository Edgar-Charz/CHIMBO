/**
 * CHIMBO shared helper — the only place the storefront talks to the API.
 * Creates the global CHIMBO namespace: api calls, toasts, formatting, safe DOM building,
 * loading/empty/error states and the quantity stepper. Feature scripts (cart.js, header.js,
 * page scripts) add their own part to CHIMBO and never call fetch() directly.
 */
(() => {
    'use strict';

    const config = JSON.parse(document.getElementById('chimbo-config').textContent);
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    const HTTP_UNAUTHENTICATED = 401;
    const TOAST_DURATION_MS = 4000;
    const STEPPER_COMMIT_DELAY_MS = 450;
    const MESSAGE_NETWORK_ERROR = 'Hakuna mtandao. Angalia intaneti yako kisha ujaribu tena.';
    const MESSAGE_INVALID_RESPONSE = 'Kuna tatizo upande wetu. Tafadhali jaribu tena baada ya muda mfupi.';
    const TOAST_ICONS = { info: 'info-circle-fill', success: 'check-circle-fill', error: 'exclamation-circle-fill' };

    const numberFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

    // ------------------------------------------------------------------ Formatting and links

    /** 5500 → "TZS 5,500" */
    const formatTzs = (amount) => `TZS ${numberFormatter.format(amount)}`;

    /** 1 → "1 pc", 8 → "8 pcs" (the same words the app uses) */
    const formatPieces = (count) => `${numberFormatter.format(count)} ${count === 1 ? 'pc' : 'pcs'}`;

    const url = (path = '') => config.base_url + path.replace(/^\//, '');

    const productUrl = (product) => url(`p/${product.product_id}-${product.product_slug}`);

    function debounce(callback, waitMs) {
        let timer = null;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => callback(...args), waitMs);
        };
    }

    // ------------------------------------------------------------------ Safe DOM building

    /**
     * Creates an element. Text is always set with textContent, so API data can never run as HTML.
     *   el('a', { className: 'chip', text: name, attrs: { href }, dataset: { id }, on: { click: fn } }, [children])
     */
    function el(tag, props = {}, children = []) {
        const element = document.createElement(tag);
        const { className, text, attrs = {}, dataset = {}, on = {} } = props;

        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = String(text);
        Object.entries(attrs).forEach(([name, value]) => {
            if (value === false || value === null || value === undefined) return;
            element.setAttribute(name, value === true ? '' : String(value));
        });
        Object.assign(element.dataset, dataset);
        Object.entries(on).forEach(([eventName, handler]) => element.addEventListener(eventName, handler));
        children.filter((child) => child !== null && child !== undefined && child !== false)
            .forEach((child) => element.append(child instanceof Node ? child : String(child)));

        return element;
    }

    const icon = (name, className = '') => el('i', { className: `bi bi-${name} ${className}`.trim(), attrs: { 'aria-hidden': 'true' } });

    /** A product photo, or a neutral placeholder while the product has none. Photos load lazily. */
    function productImage(imageUrl, altText, className) {
        if (!imageUrl) {
            return el('div', { className: `${className} image-placeholder`, attrs: { role: 'img', 'aria-label': altText } }, [icon('image')]);
        }
        return el('img', { className, attrs: { src: imageUrl, alt: altText, loading: 'lazy', decoding: 'async' } });
    }

    // ------------------------------------------------------------------ Page states

    const skeleton = (className = '') => el('span', { className: `skeleton ${className}`.trim(), attrs: { 'aria-hidden': 'true' } });

    /** Friendly empty/error block: icon, title, one sentence and one action (link or button). */
    function stateBlock({ iconName, title, text, actionLabel, actionHref, onAction, isError = false }) {
        let action = null;
        if (actionLabel && actionHref) {
            action = el('a', { className: 'btn btn-primary', text: actionLabel, attrs: { href: actionHref } });
        } else if (actionLabel && onAction) {
            action = el('button', { className: 'btn btn-primary', text: actionLabel, attrs: { type: 'button' }, on: { click: onAction } });
        }

        return el('div', { className: `state-block${isError ? ' state-block--error' : ''}`, attrs: { role: isError ? 'alert' : null } }, [
            el('span', { className: 'state-block__icon' }, [icon(iconName)]),
            el('h3', { className: 'state-block__title', text: title }),
            text ? el('p', { className: 'state-block__text', text }) : null,
            action,
        ]);
    }

    /** The standard "couldn't load" block with a "Jaribu tena" button. */
    const errorState = (error, retry) => stateBlock({
        iconName: error.code === 'NETWORK_ERROR' ? 'wifi-off' : 'exclamation-triangle',
        title: 'Imeshindwa kupakia',
        text: error.message,
        actionLabel: 'Jaribu tena',
        onAction: retry,
        isError: true,
    });

    /** Shows a spinner on a button and blocks double clicks while an action runs. */
    function setLoading(button, isLoading) {
        button.classList.toggle('is-loading', isLoading);
        button.disabled = isLoading;
        button.setAttribute('aria-busy', String(isLoading));
    }

    // ------------------------------------------------------------------ Choice cards (radio buttons)

    /** One selectable card (address, delivery, payment). `lines` are the texts under the title; `tag` a small label. */
    function choiceCard({ name, value, title, lines, tag = null, iconName = null, isChecked, onSelect }) {
        const input = el('input', { className: 'choice-card__input', attrs: { type: 'radio', name, value, checked: isChecked } });
        input.addEventListener('change', () => onSelect(value));

        return el('label', { className: 'choice-card' }, [
            input,
            iconName ? el('span', { className: 'choice-card__icon' }, [icon(iconName)]) : null,
            el('span', { className: 'choice-card__body' }, [
                el('span', { className: 'choice-card__title' }, [title, tag ? el('span', { className: 'choice-card__tag', text: tag }) : null]),
                ...lines.filter(Boolean).map((line) => el('span', { className: 'choice-card__line', text: line })),
            ]),
        ]);
    }

    // What each kind of payment means for the customer (the method names come from the server)
    const PAYMENT_TYPES = {
        cash: { text: 'Lipa pesa taslimu mzigo ukifika dukani.', icon: 'cash-coin' },
        mobile_money: { text: 'Lipa kwa simu — tutakuonyesha namba ya kulipia.', icon: 'phone' },
        bank: { text: 'Lipia benki — tutakuonyesha akaunti.', icon: 'bank' },
    };

    /** A payment method (from the API) as a choice card — used at checkout and when changing an order's method. */
    function paymentChoiceCard(method, { isChecked, tag = null, onSelect }) {
        const kind = PAYMENT_TYPES[method.payment_method_type] ?? PAYMENT_TYPES.mobile_money;
        return choiceCard({
            name: 'payment_method',
            value: method.payment_method_code,
            title: method.payment_method_name,
            lines: [kind.text],
            iconName: kind.icon,
            tag,
            isChecked,
            onSelect,
        });
    }

    // ------------------------------------------------------------------ Toasts

    function toast(message, type = 'info') {
        const region = document.querySelector('[data-toast-region]');
        const toastElement = el('div', { className: `chimbo-toast chimbo-toast--${type}`, attrs: { role: type === 'error' ? 'alert' : 'status' } }, [
            icon(TOAST_ICONS[type], 'chimbo-toast__icon'),
            el('p', { className: 'chimbo-toast__message', text: message }),
        ]);
        region.append(toastElement);

        setTimeout(() => {
            toastElement.classList.add('is-leaving');
            toastElement.addEventListener('transitionend', () => toastElement.remove(), { once: true });
            setTimeout(() => toastElement.remove(), 500); // in case no transition runs (reduced motion)
        }, TOAST_DURATION_MS);
    }

    // ------------------------------------------------------------------ API

    /** An error answer from the API (or no answer at all). `message` is Kiswahili and ready to show. */
    class ApiError extends Error {
        constructor(status, code, message, fields = {}) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.code = code;
            this.fields = fields;
        }

        /** The most useful text for a toast: the first field message (e.g. the MOQ rule) or the general message. */
        get displayMessage() {
            return Object.values(this.fields)[0] ?? this.message;
        }
    }

    function buildApiUrl(path, query = {}) {
        const apiUrl = new URL(config.api_url + path);
        Object.entries(query).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') apiUrl.searchParams.set(key, value);
        });
        return apiUrl;
    }

    function goToLogin() {
        const returnPath = window.location.pathname + window.location.search;
        window.location.href = `${config.login_url}?return=${encodeURIComponent(returnPath)}`;
    }

    async function sendRequest(method, path, query, body, headers) {
        const requestHeaders = { Accept: 'application/json', 'ngrok-skip-browser-warning': 'true', ...headers };
        const isFormData = body instanceof FormData;
        if (method !== 'GET') requestHeaders['X-CSRF-Token'] = csrfToken;
        if (body !== undefined && !isFormData) requestHeaders['Content-Type'] = 'application/json';

        try {
            return await fetch(buildApiUrl(path, query), {
                method,
                headers: requestHeaders,
                credentials: 'same-origin',
                body: body === undefined ? undefined : (isFormData ? body : JSON.stringify(body)),
            });
        } catch {
            throw new ApiError(0, 'NETWORK_ERROR', MESSAGE_NETWORK_ERROR);
        }
    }

    async function readEnvelope(response) {
        try {
            return await response.json();
        } catch {
            throw new ApiError(response.status, 'INVALID_RESPONSE', MESSAGE_INVALID_RESPONSE);
        }
    }

    /**
     * Sends one request and returns {data, meta}.
     * Options: query, body, headers, silent (no error toast — the caller shows the error itself),
     * redirectOnUnauthenticated (default true: a 401 sends the customer to the login page).
     */
    async function request(method, path, options = {}) {
        const { query, body, headers = {}, silent = false, redirectOnUnauthenticated = true } = options;

        try {
            const response = await sendRequest(method, path, query, body, headers);
            const envelope = await readEnvelope(response);
            if (envelope.success) {
                return { data: envelope.data, meta: envelope.meta ?? {} };
            }

            const { code, message, fields = {} } = envelope.error;
            throw new ApiError(response.status, code, message, fields);
        } catch (error) {
            if (error.status === HTTP_UNAUTHENTICATED && redirectOnUnauthenticated) {
                goToLogin();
            } else if (!silent) {
                toast(error.displayMessage ?? error.message, 'error');
            }
            throw error;
        }
    }

    const api = {
        get: async (path, options = {}) => (await request('GET', path, options)).data,
        /** For paginated lists: returns {data, meta: {page, per_page, total, last_page}}. */
        getPage: (path, options = {}) => request('GET', path, options),
        post: async (path, body, options = {}) => (await request('POST', path, { ...options, body })).data,
        postForm: async (path, formData, options = {}) => (await request('POST', path, { ...options, body: formData })).data,
        patch: async (path, body, options = {}) => (await request('PATCH', path, { ...options, body })).data,
        delete: async (path, options = {}) => (await request('DELETE', path, options)).data,
    };

    // ------------------------------------------------------------------ Forms

    /** Removes the message under one field (e.g. as soon as the customer starts correcting it). */
    function clearFieldError(input) {
        input.classList.remove('is-invalid');
        input.removeAttribute('aria-invalid');
        const messageId = input.getAttribute('aria-describedby');
        if (messageId) document.getElementById(messageId)?.remove();
        input.removeAttribute('aria-describedby');
    }

    function clearFieldErrors(form) {
        form.querySelectorAll('.is-invalid').forEach((input) => {
            input.classList.remove('is-invalid');
            input.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('[data-field-error]').forEach((message) => message.remove());
    }

    /** Shows the server's field messages (error.fields) under the inputs with the same name. True if any matched. */
    function showFieldErrors(form, fields = {}) {
        let firstInvalid = null;
        Object.entries(fields).forEach(([name, message]) => {
            const input = form.elements[name];
            if (!(input instanceof HTMLElement)) return;

            const messageId = `${form.id || 'form'}-${name}-error`;
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', messageId);
            (input.closest('[data-field]') ?? input.parentElement).append(
                el('div', { className: 'invalid-feedback d-block', text: message, attrs: { id: messageId }, dataset: { fieldError: '' } }),
            );
            firstInvalid ??= input;
        });
        firstInvalid?.focus();
        return firstInvalid !== null;
    }

    /**
     * Runs a form's API call: clears old messages, shows a spinner on the button, and on failure puts each
     * field message under its input (or shows a toast when the error is not about a field).
     * `send` must call the API with { silent: true } so the error is not shown twice.
     * fieldForCode puts errors without fields under an input, e.g. { PIN_INVALID: 'user_pin' }.
     */
    async function submitForm(form, submitButton, send, { fieldForCode = {} } = {}) {
        clearFieldErrors(form);
        setLoading(submitButton, true);
        try {
            return await send();
        } catch (error) {
            const fields = fieldForCode[error.code] ? { [fieldForCode[error.code]]: error.message } : error.fields;
            if (!showFieldErrors(form, fields)) toast(error.displayMessage ?? error.message, 'error');
            throw error;
        } finally {
            setLoading(submitButton, false);
        }
    }

    /** A random id for "Idempotency-Key": one per checkout, so a repeated tap or retry never makes a second order. */
    function newIdempotencyKey() {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    // ------------------------------------------------------------------ Tanzanian phone numbers

    const PHONE_NATIONAL_LENGTH = 9;                    // 712 345 678 — after +255
    const PHONE_MOBILE_PATTERN = /^[67]\d{8}$/;         // Tanzanian mobile numbers start with 6 or 7
    const MESSAGE_INVALID_PHONE = 'Weka namba sahihi ya simu, mfano 712 345 678.';

    /** Any way a number is typed or pasted (0712…, 712…, +255 712…, 255712…) → its national digits "712345678". */
    function nationalPhoneDigits(text) {
        let digits = text.replace(/\D/g, '');
        if (digits.startsWith('255')) digits = digits.slice(3);
        if (digits.startsWith('0')) digits = digits.slice(1);
        return digits.slice(0, PHONE_NATIONAL_LENGTH);
    }

    const isTanzanianMobile = (text) => PHONE_MOBILE_PATTERN.test(nationalPhoneDigits(text));

    /** The number to send to the API: "+255712345678". */
    const internationalPhone = (text) => `+255${nationalPhoneDigits(text)}`;

    /** A phone box next to a "+255" label: keeps digits only, one number at most, shown as "712 345 678". */
    function formatPhoneInput(input) {
        const tidy = () => {
            const digits = nationalPhoneDigits(input.value);
            input.value = [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6)].filter(Boolean).join(' ');
        };
        input.addEventListener('input', () => {
            tidy();
            clearFieldError(input);
        });
        tidy();
    }

    /** Checks a phone field before sending; shows the message under it and returns false when it is wrong. */
    function checkPhoneField(form, name) {
        if (isTanzanianMobile(form.elements[name].value)) return true;
        clearFieldErrors(form);
        showFieldErrors(form, { [name]: MESSAGE_INVALID_PHONE });
        return false;
    }

    // ------------------------------------------------------------------ PIN

    const PIN_MAX_LENGTH = 6;

    /** A PIN box: digits only, at most 6, and its message clears while the customer corrects it. */
    function formatPinInput(input) {
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(0, PIN_MAX_LENGTH);
            clearFieldError(input);
        });
    }

    // ------------------------------------------------------------------ Quantity stepper

    /**
     * − [ 8 ] + control used by cards, the product page and cart lines.
     * The number changes at once; onChange(quantity) runs after a short pause (delayMs), so fast tapping sends
     * one request — pass delayMs: 0 when nothing is sent (e.g. a price preview).
     * With allowRemove, "−" at the minimum becomes a bin that calls onChange(0).
     */
    function quantityStepper({ value, min, max, onChange, allowRemove = false, label = 'Idadi', delayMs = STEPPER_COMMIT_DELAY_MS }) {
        let quantity = value;
        const commitChange = delayMs === 0 ? () => onChange(quantity) : debounce(() => onChange(quantity), delayMs);

        const decreaseButton = el('button', { className: 'quantity-stepper__button', attrs: { type: 'button' } });
        const increaseButton = el('button', { className: 'quantity-stepper__button', attrs: { type: 'button', 'aria-label': 'Ongeza moja' } }, [icon('plus-lg')]);
        const input = el('input', {
            className: 'quantity-stepper__input',
            attrs: { type: 'number', inputmode: 'numeric', min, max, value, 'aria-label': label },
        });

        function show() {
            const willRemove = allowRemove && quantity <= min;
            decreaseButton.replaceChildren(icon(willRemove ? 'trash3' : 'dash-lg'));
            decreaseButton.setAttribute('aria-label', willRemove ? 'Ondoa' : 'Punguza moja');
            decreaseButton.disabled = !allowRemove && quantity <= min;
            increaseButton.disabled = quantity >= max;
            input.value = quantity;
        }

        function change(newQuantity) {
            if (newQuantity < min && allowRemove) {
                onChange(0);
                return;
            }
            quantity = Math.min(Math.max(newQuantity, min), max);
            show();
            commitChange();
        }

        decreaseButton.addEventListener('click', () => change(quantity - 1));
        increaseButton.addEventListener('click', () => change(quantity + 1));
        input.addEventListener('change', () => change(Number.parseInt(input.value, 10) || min));

        show();
        return el('div', { className: 'quantity-stepper' }, [decreaseButton, input, increaseButton]);
    }

    window.CHIMBO = {
        config,
        api,
        ApiError,
        url,
        productUrl,
        formatTzs,
        formatPieces,
        debounce,
        el,
        icon,
        productImage,
        skeleton,
        stateBlock,
        errorState,
        setLoading,
        toast,
        choiceCard,
        paymentChoiceCard,
        submitForm,
        clearFieldErrors,
        showFieldErrors,
        phone: { formatInput: formatPhoneInput, check: checkPhoneField, toInternational: internationalPhone },
        formatPinInput,
        newIdempotencyKey,
        quantityStepper,
    };
})();
