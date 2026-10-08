/**
 * Kikapu on every page: the cart badges, the slide-out drawer and all cart changes.
 *
 * Guests keep their cart in localStorage ({product_id, quantity} only) and the server prices it
 * (POST /cart/preview); logged-in customers use the account cart (/cart). Both answers have the
 * same shape, so everything below is drawn one way.
 *
 * Used by other scripts:
 *   CHIMBO.cart.add(product_id, quantity, { openDrawer })   CHIMBO.cart.setQuantity(product_id, quantity)
 *   CHIMBO.cart.quantityOf(product_id)                      CHIMBO.cart.open()
 *   CHIMBO.cart.whenLoaded() / refresh() / clear()          CHIMBO.cart.mergeGuestCart()  (right after login)
 *   CHIMBO.cart.renderGroups(cart) … (cart page)            document event "chimbo:cart-changed" (detail = cart)
 * Summary boxes marked [data-cart-summary] (drawer, cart page) are filled here too.
 */
(() => {
    'use strict';

    const { config, api, el, icon, formatTzs, formatPieces, productUrl, productImage, quantityStepper, stateBlock, errorState, skeleton, toast } = CHIMBO;

    const GUEST_CART_KEY = 'chimbo_guest_cart';
    const CART_CHANGED_EVENT = 'chimbo:cart-changed';
    const DRAWER_SKELETON_LINES = 3;
    const EMPTY_CART = {
        groups: [],
        summary: { line_count: 0, piece_count: 0, subtotal: 0, savings: 0, can_checkout: false },
        warnings: [],
    };

    const drawerElement = document.querySelector('[data-cart-drawer]');
    const drawer = bootstrap.Offcanvas.getOrCreateInstance(drawerElement);

    let currentCart = null;

    // ------------------------------------------------------------------ Guest cart (browser only)

    const guestStore = {
        read() {
            try {
                const items = JSON.parse(localStorage.getItem(GUEST_CART_KEY) ?? '[]');
                return Array.isArray(items) ? items : [];
            } catch {
                return [];
            }
        },
        write(items) {
            try {
                localStorage.setItem(GUEST_CART_KEY, JSON.stringify(items));
            } catch {
                toast('Kivinjari chako hakiruhusu kuhifadhi kikapu. Ingia ili uendelee.', 'error');
            }
        },
        setQuantity(productId, quantity) {
            const others = this.read().filter((item) => item.product_id !== productId);
            this.write(quantity > 0 ? [...others, { product_id: productId, quantity }] : others);
        },
        add(productId, quantity) {
            const existing = this.read().find((item) => item.product_id === productId);
            this.setQuantity(productId, (existing?.quantity ?? 0) + quantity);
        },
        /** Keeps only the products the server still sells (it drops the ones that left the shop). */
        keepOnly(cart) {
            const pricedIds = cartItems(cart).map((item) => item.product.product_id);
            this.write(this.read().filter((item) => pricedIds.includes(item.product_id)));
        },
    };

    /** Asks the server to price the guest cart. Silent when loading (the drawer shows the error), loud after a change. */
    const priceGuestCart = async ({ silent = false } = {}) => {
        const items = guestStore.read();
        if (items.length === 0) return EMPTY_CART;

        const cart = await api.post('/cart/preview', { items }, { silent });
        guestStore.keepOnly(cart);
        return cart;
    };

    // ------------------------------------------------------------------ Where the cart lives

    /** Changes the saved guest cart and prices it; if the server can't answer, the change is undone. */
    async function changeGuestCart(change) {
        const previousItems = guestStore.read();
        change();
        try {
            return await priceGuestCart();
        } catch (error) {
            guestStore.write(previousItems);
            throw error;
        }
    }

    const guestCart = {
        load: () => priceGuestCart({ silent: true }),
        add: (productId, quantity) => changeGuestCart(() => guestStore.add(productId, quantity)),
        setQuantity: (productId, quantity) => changeGuestCart(() => guestStore.setQuantity(productId, quantity)),
        remove: (productId) => changeGuestCart(() => guestStore.setQuantity(productId, 0)),
        clear: async () => {
            guestStore.write([]);
            return EMPTY_CART;
        },
    };

    const accountCart = {
        load: () => api.get('/cart', { silent: true }),
        add: (productId, quantity) => api.post('/cart/items', { product_id: productId, quantity }),
        setQuantity: (productId, quantity) => api.patch(`/cart/items/${productId}`, { quantity }),
        remove: (productId) => api.delete(`/cart/items/${productId}`),
        clear: () => api.delete('/cart'),
    };

    const cartSource = config.is_logged_in ? accountCart : guestCart;

    const cartItems = (cart) => cart.groups.flatMap((group) => group.items);

    // ------------------------------------------------------------------ Changes

    function showCart(cart) {
        currentCart = cart;
        cart.warnings.forEach((warning) => toast(warning.message, 'info'));
        updateBadges(cart.summary.line_count);
        renderDrawer(cart);
        renderSummaries(cart);
        document.dispatchEvent(new CustomEvent(CART_CHANGED_EVENT, { detail: cart }));
        return cart;
    }

    async function refresh() {
        try {
            return showCart(await cartSource.load());
        } catch (error) {
            renderDrawerError(error);
            throw error;
        }
    }

    async function add(productId, quantity, { openDrawer = false } = {}) {
        const cart = showCart(await cartSource.add(productId, quantity));
        if (openDrawer) {
            open();
        } else {
            toast('Imeongezwa kwenye kikapu.', 'success');
        }
        return cart;
    }

    async function setQuantity(productId, quantity) {
        if (quantity === 0) return remove(productId);
        return showCart(await cartSource.setQuantity(productId, quantity));
    }

    const remove = async (productId) => showCart(await cartSource.remove(productId));

    const clear = async () => showCart(await cartSource.clear());

    /**
     * Right after login: moves the browser's guest cart into the account (the bigger quantity wins) and
     * empties the browser copy. Returns the server's messages for items it could not add.
     */
    async function mergeGuestCart() {
        const items = guestStore.read();
        if (items.length === 0) return [];

        const cart = await api.post('/cart/merge', { items }, { silent: true });
        guestStore.write([]);
        return cart.skipped_items;
    }

    const quantityOf = (productId) => {
        const line = currentCart && cartItems(currentCart).find((item) => item.product.product_id === productId);
        return line ? line.cart_quantity : 0;
    };

    function open() {
        if (currentCart === null) {
            renderDrawerLoading();
            refresh().catch(() => {});
        }
        drawer.show();
    }

    // ------------------------------------------------------------------ Badges

    function updateBadges(lineCount) {
        document.querySelectorAll('[data-cart-badge]').forEach((badge) => {
            const previous = badge.textContent;
            badge.textContent = lineCount;
            badge.hidden = lineCount === 0;
            if (previous !== '' && previous !== String(lineCount)) {
                badge.classList.remove('is-bumping');
                void badge.offsetWidth; // restart the animation
                badge.classList.add('is-bumping');
            }
        });
    }

    // ------------------------------------------------------------------ One cart line (drawer and cart page)

    /** The fix for a line whose stock or MOQ changed after it was added. */
    function renderLineProblem(item) {
        const { product, line_problem: problem, available_quantity: available } = item;
        const fixes = {
            not_enough_stock: { text: `Zimebaki ${formatPieces(available)} tu.`, label: `Punguza hadi ${available}`, fix: () => setQuantity(product.product_id, available) },
            out_of_stock: { text: 'Bidhaa hii imeisha.', label: 'Ondoa', fix: () => remove(product.product_id) },
            below_moq: { text: `Kiwango cha chini ni ${formatPieces(product.product_moq)}.`, label: `Ongeza hadi ${product.product_moq}`, fix: () => setQuantity(product.product_id, product.product_moq) },
        };
        const { text, label, fix } = fixes[problem];

        return el('p', { className: 'cart-line__problem' }, [
            icon('exclamation-triangle-fill'), ` ${text} `,
            el('button', { className: 'btn btn-link', text: label, attrs: { type: 'button' }, on: { click: () => fix().catch(() => {}) } }),
        ]);
    }

    function renderLine(item) {
        const { product } = item;
        const line = el('article', { className: `cart-line${item.line_problem ? ' has-problem' : ''}` });

        const updateLine = (quantity) => {
            line.classList.add('is-updating');
            setQuantity(product.product_id, quantity).catch(() => line.classList.remove('is-updating'));
        };

        const hint = item.next_tier_hint;
        line.append(
            el('a', { attrs: { href: productUrl(product), tabindex: '-1', 'aria-hidden': 'true' } }, [
                productImage(product.product_image_url, product.product_name, 'cart-line__image'),
            ]),
            el('div', { className: 'cart-line__body' }, [
                el('div', { className: 'cart-line__top' }, [
                    el('a', { className: 'cart-line__name', text: product.product_name, attrs: { href: productUrl(product) } }),
                    el('span', { className: 'cart-line__total', text: formatTzs(item.line_total) }),
                ]),
                el('p', { className: 'cart-line__price', text: `${formatTzs(item.unit_price)} kila moja · bei ya ${item.tier_min_quantity}+ pcs` }),
                hint ? el('span', { className: 'cart-line__hint', text: `Ongeza ${formatPieces(hint.extra_quantity)} upate ${formatTzs(hint.unit_price)} kila moja` }) : null,
                item.line_problem ? renderLineProblem(item) : null,
                el('div', { className: 'cart-line__actions' }, [
                    quantityStepper({
                        value: item.cart_quantity,
                        min: product.product_moq,
                        max: Math.max(item.available_quantity, item.cart_quantity),
                        label: `Idadi ya ${product.product_name}`,
                        onChange: updateLine,
                    }),
                    el('button', {
                        className: 'icon-button cart-line__remove',
                        attrs: { type: 'button', 'aria-label': `Ondoa ${product.product_name}` },
                        on: { click: () => updateLine(0) },
                    }, [icon('trash3')]),
                ]),
            ]),
        );
        return line;
    }

    // ------------------------------------------------------------------ Lines, summaries and the drawer

    const drawerLines = drawerElement.querySelector('[data-cart-lines]');
    const drawerSummary = drawerElement.querySelector('[data-cart-summary]');

    /** Every line under its category title (drawer and cart page). */
    const renderGroups = (cart) => cart.groups.flatMap((group) => [
        el('h3', { className: 'cart-group__title', text: group.category_name }),
        ...group.items.map(renderLine),
    ]);

    const renderLineSkeletons = (count) => Array.from({ length: count }, () => el('div', { className: 'cart-line', attrs: { 'aria-hidden': 'true' } }, [
        skeleton('cart-line__image'),
        el('div', {}, [skeleton('skeleton--text'), skeleton('skeleton--text skeleton--short')]),
    ]));

    const renderEmptyState = () => stateBlock({
        iconName: 'bag',
        title: 'Kikapu chako kiko tupu',
        text: 'Ongeza bidhaa unazohitaji dukani kwako kwa bei za jumla.',
        actionLabel: 'Anza kununua',
        actionHref: CHIMBO.url(),
    });

    /**
     * Fills every summary box on the page ([data-cart-summary]: the drawer footer, the cart page panel and
     * its phone bar) — each box has only some of the parts. Boxes are hidden while the cart is empty.
     */
    function renderSummaries(cart) {
        const { summary } = cart;
        const setText = (box, selector, text) => box.querySelectorAll(selector).forEach((element) => { element.textContent = text; });

        document.querySelectorAll('[data-cart-summary]').forEach((box) => {
            box.hidden = summary.line_count === 0;
            box.querySelectorAll('[data-cart-savings-row]').forEach((row) => { row.hidden = summary.savings === 0; });
            setText(box, '[data-cart-savings]', formatTzs(summary.savings));
            setText(box, '[data-cart-subtotal]', formatTzs(summary.subtotal));
            setText(box, '[data-cart-pieces]', formatPieces(summary.piece_count));
            setText(box, '[data-cart-note]', summary.can_checkout
                ? 'Gharama ya usafirishaji itaonyeshwa kwenye malipo.'
                : 'Rekebisha bidhaa zenye tatizo kabla ya kuendelea.');
            box.querySelectorAll('[data-cart-note]').forEach((note) => note.classList.toggle('is-problem', !summary.can_checkout));
            box.querySelectorAll('[data-cart-checkout]').forEach((link) => {
                link.classList.toggle('disabled', !summary.can_checkout);
                link.setAttribute('aria-disabled', String(!summary.can_checkout));
            });
        });
    }

    function renderDrawerLoading() {
        drawerSummary.hidden = true;
        drawerLines.replaceChildren(...renderLineSkeletons(DRAWER_SKELETON_LINES));
    }

    function renderDrawerError(error) {
        drawerSummary.hidden = true;
        drawerLines.replaceChildren(errorState(error, () => {
            renderDrawerLoading();
            refresh().catch(() => {});
        }));
    }

    function renderDrawer(cart) {
        const { line_count: lineCount } = cart.summary;
        drawerElement.querySelector('[data-cart-count-label]').textContent = lineCount === 0 ? '' : `(${lineCount})`;
        drawerLines.replaceChildren(...(lineCount === 0 ? [renderEmptyState()] : renderGroups(cart)));
    }

    // ------------------------------------------------------------------ Start

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-cart-open]')) open();
    });

    if (!config.is_logged_in) updateBadges(guestStore.read().length); // show the count before the server answers
    const firstLoad = refresh();
    firstLoad.catch(() => {}); // the drawer shows the error; pages that need the cart use whenLoaded()

    CHIMBO.cart = {
        add,
        setQuantity,
        remove,
        clear,
        refresh,
        whenLoaded: () => firstLoad,
        mergeGuestCart,
        quantityOf,
        open,
        renderGroups,
        renderLineSkeletons,
        renderEmptyState,
        changedEvent: CART_CHANGED_EVENT,
    };
})();
