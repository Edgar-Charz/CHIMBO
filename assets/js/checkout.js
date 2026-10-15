/**
 * Checkout: choose the address → the delivery methods that reach it (GET /checkout/options) → payment →
 * the server's totals (POST /checkout/preview) → "Thibitisha Oda" (POST /orders).
 * The usual choices are picked automatically, so a returning customer only has to confirm.
 * Upcountry transport is the region's estimate: staff confirm it after measuring the load and only then the customer
 * pays (so no cash on delivery for it). Bolt in Dar is paid to the rider (never in the total); pickup is free.
 * One Idempotency-Key per checkout (kept for this tab) means a double tap or a retry never makes two orders.
 */
(() => {
    'use strict';

    const { api, el, formatTzs, formatPieces, productImage, stateBlock, setLoading, toast, cart, choiceCard, paymentChoiceCard } = CHIMBO;

    const IDEMPOTENCY_STORAGE_KEY = 'chimbo_checkout_key';
    const CART_ERROR_CODES = ['CART_EMPTY', 'CART_HAS_PROBLEMS'];
    const checkoutData = JSON.parse(document.getElementById('checkout-data').textContent);
    const root = document.querySelector('[data-checkout]');
    const addressList = root.querySelector('[data-address-list]');
    const addressForm = root.querySelector('[data-address-form]');
    const addressToggle = root.querySelector('[data-address-toggle]');
    const deliveryList = root.querySelector('[data-delivery-list]');
    const paymentList = root.querySelector('[data-payment-list]');
    const totalsBox = root.querySelector('[data-checkout-totals]');
    const placeOrderButton = root.querySelector('[data-place-order]');

    const choice = { addressId: null, deliveryMethodId: null, paymentMethod: null };
    let addresses = checkoutData.addresses;
    let paymentMethods = [];     // payment_method_details from GET /checkout/options
    let deliveryMethods = [];    // delivery_methods from GET /checkout/options
    let pickupPoint = null;      // {pickup_address, pickup_hours} for "Nitachukua mwenyewe dukani"
    let preview = null;          // the server's totals for the current choices
    let latestPreviewRequest = 0;
    let unsavedIdempotencyKey = null; // used only when the browser blocks sessionStorage

    // ------------------------------------------------------------------ Idempotency key (one per checkout)

    function idempotencyKey() {
        try {
            let key = sessionStorage.getItem(IDEMPOTENCY_STORAGE_KEY);
            if (!key) {
                key = CHIMBO.newIdempotencyKey();
                sessionStorage.setItem(IDEMPOTENCY_STORAGE_KEY, key);
            }
            return key;
        } catch {
            unsavedIdempotencyKey ??= CHIMBO.newIdempotencyKey(); // storage blocked: still one key for this page
            return unsavedIdempotencyKey;
        }
    }

    function forgetIdempotencyKey() {
        try {
            sessionStorage.removeItem(IDEMPOTENCY_STORAGE_KEY);
        } catch {
            // nothing was stored
        }
    }

    // ------------------------------------------------------------------ Choice cards (radio buttons)

    const waitingNote = (text) => el('p', { className: 'choice-list__waiting', text });

    // ------------------------------------------------------------------ 1. Address

    function renderAddresses() {
        addressList.replaceChildren(...addresses.map((address) => choiceCard({
            name: 'address_id',
            value: address.address_id,
            title: address.address_recipient_name,
            lines: CHIMBO.addressForm.describe(address),
            tag: address.address_is_default ? 'Kuu' : null,
            isChecked: address.address_id === choice.addressId,
            onSelect: selectAddress,
        })));
    }

    async function selectAddress(addressId) {
        choice.addressId = Number(addressId);
        renderAddresses();
        await loadOptions();
    }

    function showAddAddressButton(isShown) {
        addressToggle.hidden = !isShown;
        addressToggle.setAttribute('aria-expanded', String(!isShown));
    }

    const addressEditor = CHIMBO.addressForm.attach(addressForm, {
        suggested: checkoutData.new_address,
        onClose: () => showAddAddressButton(true),
        onSaved: async (address) => {
            addresses = address.address_is_default
                ? [address, ...addresses.map((other) => ({ ...other, address_is_default: false }))]
                : [...addresses, address];
            toast('Anwani imehifadhiwa.', 'success');
            await selectAddress(address.address_id);
        },
    });

    async function openNewAddressForm() {
        showAddAddressButton(false);
        await addressEditor.open();
    }

    addressToggle.addEventListener('click', openNewAddressForm);

    // ------------------------------------------------------------------ 2 + 3. Delivery and payment

    async function loadOptions() {
        deliveryList.replaceChildren(waitingNote('Inatafuta njia zinazofika kwenye anwani hii…'));
        paymentList.replaceChildren();
        showPreviewWaiting();

        try {
            const options = await api.get('/checkout/options', { query: { address_id: choice.addressId }, silent: true });
            deliveryMethods = options.delivery_methods;
            pickupPoint = options.pickup_point;
            const methodIds = deliveryMethods.map((method) => method.delivery_method_id);
            if (!methodIds.includes(choice.deliveryMethodId)) choice.deliveryMethodId = methodIds[0] ?? null;
            paymentMethods = options.payment_method_details;

            renderDeliveryMethods();
            renderPaymentMethods();
            await loadPreview();
        } catch (error) {
            deliveryList.replaceChildren(waitingNote(error.displayMessage));
        }
    }

    const chosenDeliveryMethod = () => deliveryMethods.find((method) => method.delivery_method_id === choice.deliveryMethodId);

    /**
     * "Bure", "TZS 5,000", "Unamlipa dereva" (Bolt), or for upcountry "~TZS 15,000" (an estimate) /
     * "Bei itathibitishwa" (no estimate yet).
     */
    function deliveryFeeTag(method) {
        if (method.delivery_fee_paid_to_rider) return 'Unamlipa dereva';
        if (method.delivery_method_fee === null) return 'Bei itathibitishwa';
        if (method.delivery_fee_needs_confirmation) return `~${formatTzs(method.delivery_method_fee)}`;
        return method.delivery_method_fee === 0 ? 'Bure' : formatTzs(method.delivery_method_fee);
    }

    /** The line under a delivery option: how its price works, or where to collect. */
    function deliveryMethodNote(method) {
        if (method.delivery_fee_needs_confirmation) return ['Bei ni makadirio. Tutapima mzigo na kukuthibitishia bei kabla hujalipa.'];
        if (method.delivery_fee_paid_to_rider) return ['Usafiri unamlipa dereva ukipokea mzigo — hauko kwenye jumla.'];
        if (method.delivery_method_is_pickup && pickupPoint) return [`${pickupPoint.pickup_address} · ${pickupPoint.pickup_hours}`];
        return [];
    }

    function renderDeliveryMethods() {
        const methods = deliveryMethods;
        if (methods.length === 0) {
            deliveryList.replaceChildren(waitingNote('Samahani, bado hatufikishi kwenye mkoa huu. Chagua anwani nyingine.'));
            return;
        }
        deliveryList.replaceChildren(...methods.map((method) => choiceCard({
            name: 'delivery_method_id',
            value: method.delivery_method_id,
            title: method.delivery_method_name, // already says how long, e.g. "Standard (siku 2–3)"
            lines: deliveryMethodNote(method),
            tag: deliveryFeeTag(method),
            iconName: 'truck',
            isChecked: method.delivery_method_id === choice.deliveryMethodId,
            onSelect: (value) => {
                choice.deliveryMethodId = Number(value);
                renderPaymentMethods(); // cash on delivery isn't offered while the transport price is to be confirmed
                loadPreview();
            },
        })));
    }

    function renderPaymentMethods() {
        const priceToConfirm = chosenDeliveryMethod()?.delivery_fee_needs_confirmation ?? false;
        const methods = paymentMethods.filter((method) => !(priceToConfirm && method.payment_method_type === 'cash'));
        const codes = methods.map((method) => method.payment_method_code);
        if (!codes.includes(choice.paymentMethod)) choice.paymentMethod = codes[0] ?? null;

        paymentList.replaceChildren(...methods.map((method) => paymentChoiceCard(method, {
            isChecked: method.payment_method_code === choice.paymentMethod,
            onSelect: (value) => {
                choice.paymentMethod = value;
                if (preview) renderTotals(); // the note under the button depends on how the customer pays
            },
        })));
    }

    // ------------------------------------------------------------------ 4. Review (server totals)

    function renderItems(currentCart) {
        const items = currentCart.groups.flatMap((group) => group.items);
        root.querySelector('[data-checkout-items]').replaceChildren(...items.map((item) => el('li', { className: 'checkout-item' }, [
            productImage(item.product.product_image_url, item.product.product_name, 'checkout-item__image'),
            el('span', { className: 'checkout-item__name' }, [
                item.product.product_name,
                el('small', {}, [`${formatPieces(item.cart_quantity)} × ${formatTzs(item.unit_price)} `, CHIMBO.offerTag(item.offer_percent)]),
            ]),
            el('span', { className: 'checkout-item__total', text: formatTzs(item.line_total) }),
        ])));
    }

    /** 2, 3 → "siku 2–3"; 1, 1 → "siku 1". */
    const formatDays = (minimum, maximum) => (minimum === maximum ? `siku ${minimum}` : `siku ${minimum}–${maximum}`);

    function totalRow(label, value, className = '') {
        return el('div', { className: `cart-summary__row ${className}`.trim() }, [el('dt', { text: label }), el('dd', { text: value })]);
    }

    function showPreviewWaiting() {
        preview = null;
        placeOrderButton.disabled = true;
        totalsBox.replaceChildren(el('p', { className: 'choice-list__waiting', text: 'Chagua anwani na usafirishaji kuona jumla kuu.' }));
        root.querySelector('[data-pay-note]').textContent = '';
    }

    function renderTotals() {
        const priceToConfirm = preview.delivery_fee_needs_confirmation;
        let deliveryText = preview.delivery_fee === 0 ? 'Bure' : formatTzs(preview.delivery_fee);
        if (priceToConfirm) {
            deliveryText = chosenDeliveryMethod()?.delivery_method_fee === null ? 'Tutakujulisha' : `~${formatTzs(preview.delivery_fee)}`;
        } else if (preview.delivery_fee_paid_to_rider) {
            deliveryText = 'Unamlipa dereva';
        } else if (preview.delivery_method_is_pickup) {
            deliveryText = 'Bure (dukani)';
        }
        totalsBox.replaceChildren(...[
            totalRow(`Bidhaa (${formatPieces(preview.piece_count)})`, formatTzs(preview.subtotal)),
            preview.savings > 0 ? totalRow('Unaokoa', `−${formatTzs(preview.savings)}`, 'cart-summary__row--savings') : null,
            totalRow(priceToConfirm ? 'Usafiri (makadirio)' : 'Usafirishaji', deliveryText),
            preview.discount_total > 0 ? totalRow('Punguzo', `−${formatTzs(preview.discount_total)}`, 'cart-summary__row--savings') : null,
            totalRow(priceToConfirm ? 'Jumla (makadirio)' : 'Jumla kuu', formatTzs(preview.grand_total), 'cart-summary__row--total'),
        ].filter(Boolean));
        const arrival = preview.delivery_method_is_pickup
            ? 'Tutakujulisha ikiwa tayari kuchukuliwa.'
            : `Inafika ndani ya ${formatDays(preview.delivery_days_min, preview.delivery_days_max)}.`;
        const method = paymentMethods.find((candidate) => candidate.payment_method_code === choice.paymentMethod);
        let payNote = `Baada ya kuthibitisha utaona jinsi ya kulipa ${formatTzs(preview.grand_total)} kwa ${method?.payment_method_name ?? 'njia uliyochagua'}. Tunaanza kuandaa oda malipo yakithibitishwa.`;
        if (priceToConfirm) {
            payNote = 'Usilipe sasa. Tutapima mzigo na kukutumia bei kamili ya usafiri; kisha utalipa jumla kamili kwa '
                + `${method?.payment_method_name ?? 'njia uliyochagua'}. ${arrival}`;
        } else if (method?.payment_method_type === 'cash') {
            payNote = `Utalipa ${formatTzs(preview.grand_total)} ukipokea mzigo. ${arrival}`;
        }
        root.querySelector('[data-pay-note]').textContent = payNote;
        placeOrderButton.disabled = choice.paymentMethod === null;
    }

    async function loadPreview() {
        if (choice.addressId === null || choice.deliveryMethodId === null) {
            showPreviewWaiting();
            return;
        }
        const requestNumber = ++latestPreviewRequest;
        placeOrderButton.disabled = true;
        try {
            const result = await api.post('/checkout/preview', { address_id: choice.addressId, delivery_method_id: choice.deliveryMethodId }, { silent: true });
            if (requestNumber !== latestPreviewRequest) return;
            preview = result;
            renderTotals();
        } catch (error) {
            if (requestNumber === latestPreviewRequest && !handleCartError(error)) {
                toast(error.displayMessage, 'error');
            }
        }
    }

    // ------------------------------------------------------------------ Place the order

    /** Empty cart or problem lines: nothing can be ordered here — send the customer to fix the cart. */
    function handleCartError(error) {
        if (!CART_ERROR_CODES.includes(error.code)) return false;
        showCartBlocked(error.message);
        return true;
    }

    function showCartBlocked(message) {
        root.querySelector('[data-checkout-content]').replaceChildren(stateBlock({
            iconName: 'bag-x',
            title: 'Kikapu kinahitaji kurekebishwa',
            text: message,
            actionLabel: 'Nenda kwenye kikapu',
            actionHref: CHIMBO.url('cart.php'),
        }));
    }

    placeOrderButton.addEventListener('click', async () => {
        setLoading(placeOrderButton, true);
        try {
            const order = await api.post('/orders', {
                address_id: choice.addressId,
                delivery_method_id: choice.deliveryMethodId,
                payment_method: choice.paymentMethod,
                expected_total: preview.grand_total,
                order_customer_note: root.querySelector('[data-order-note]').value.trim() || null,
            }, { headers: { 'Idempotency-Key': idempotencyKey() }, silent: true });

            forgetIdempotencyKey();
            window.location.assign(`${checkoutData.success_url}?id=${order.order_id}`);
        } catch (error) {
            setLoading(placeOrderButton, false);
            if (error.code === 'PRICE_CHANGED') {
                toast(error.message, 'info'); // the new total is in the message; show it and ask again
                await cart.refresh().catch(() => {});
                await loadPreview();
            } else if (!handleCartError(error)) {
                toast(error.displayMessage, 'error');
            }
        }
    });

    // ------------------------------------------------------------------ Start

    async function start() {
        let currentCart;
        try {
            currentCart = await cart.whenLoaded();
        } catch (error) {
            showCartBlocked(error.message);
            return;
        }
        if (currentCart.summary.line_count === 0) {
            showCartBlocked('Kikapu chako kiko tupu. Ongeza bidhaa kwanza.');
            return;
        }
        if (!currentCart.summary.can_checkout) {
            showCartBlocked('Baadhi ya bidhaa zina tatizo la idadi au stoku. Zirekebishe kwenye kikapu.');
            return;
        }

        renderItems(currentCart);
        document.addEventListener(cart.changedEvent, (event) => renderItems(event.detail));

        const defaultAddress = addresses.find((address) => address.address_is_default) ?? addresses[0];
        if (defaultAddress) {
            await selectAddress(defaultAddress.address_id);
        } else {
            deliveryList.replaceChildren(waitingNote('Ongeza anwani kwanza.'));
            showPreviewWaiting();
            await openNewAddressForm();
        }
    }

    start();
})();
