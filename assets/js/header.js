/**
 * Header on every page: live search suggestions, the unread-notifications badge, logout,
 * and a shadow under the sticky header once the page scrolls.
 */
(() => {
    'use strict';

    const { config, api, el, formatTzs, productUrl, productImage, debounce, url } = CHIMBO;

    const SUGGESTION_LIMIT = 6;
    const SUGGESTION_MIN_LENGTH = 2;
    const SUGGESTION_DELAY_MS = 250;
    const SCROLLED_OFFSET_PX = 4;

    // ------------------------------------------------------------------ Sticky header shadow

    const header = document.querySelector('[data-site-header]');
    const updateHeaderShadow = () => header.classList.toggle('is-scrolled', window.scrollY > SCROLLED_OFFSET_PX);
    window.addEventListener('scroll', updateHeaderShadow, { passive: true });
    updateHeaderShadow();

    // ------------------------------------------------------------------ Search suggestions

    const searchForm = document.querySelector('[data-search-form]');
    const searchInput = searchForm.querySelector('[data-search-input]');
    const suggestionBox = searchForm.querySelector('[data-search-suggestions]');
    let latestSearch = 0;   // answers to older searches are ignored when they arrive late
    let activeIndex = -1;

    const searchPageUrl = (query) => `${url('search.php')}?q=${encodeURIComponent(query)}`;
    const suggestionOptions = () => [...suggestionBox.querySelectorAll('[role="option"]')];

    function closeSuggestions() {
        suggestionBox.hidden = true;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    }

    function renderSuggestion(product, index) {
        return el('a', {
            className: 'search-suggestion',
            attrs: { href: productUrl(product), role: 'option', id: `search-suggestion-${index}` },
        }, [
            productImage(product.product_image_url, '', 'search-suggestion__image'),
            el('span', { className: 'search-suggestion__name', text: product.product_name }),
            el('span', { className: 'search-suggestion__price', text: `kuanzia ${formatTzs(product.product_price_from)}` }),
        ]);
    }

    function showSuggestions(products, query) {
        const allResults = el('a', {
            className: 'search-suggestions__footer',
            text: `Tazama matokeo yote ya "${query}"`,
            attrs: { href: searchPageUrl(query), role: 'option', id: 'search-suggestion-all' },
        });
        const content = products.length > 0
            ? products.map(renderSuggestion)
            : [el('p', { className: 'search-suggestions__empty', text: `Hakuna bidhaa inayolingana na "${query}".` })];

        suggestionBox.replaceChildren(...content, allResults);
        suggestionBox.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
    }

    const loadSuggestions = debounce(async (query) => {
        const searchNumber = ++latestSearch;
        try {
            const products = await api.get('/products', { query: { q: query, per_page: SUGGESTION_LIMIT }, silent: true });
            if (searchNumber === latestSearch) showSuggestions(products, query);
        } catch {
            closeSuggestions(); // suggestions are a nice extra; pressing Enter still searches
        }
    }, SUGGESTION_DELAY_MS);

    function moveActiveSuggestion(step) {
        const options = suggestionOptions();
        if (options.length === 0) return;

        options[activeIndex]?.classList.remove('is-active');
        activeIndex = (activeIndex + step + options.length) % options.length;
        options[activeIndex].classList.add('is-active');
        searchInput.setAttribute('aria-activedescendant', options[activeIndex].id);
    }

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim();
        if (query.length < SUGGESTION_MIN_LENGTH) {
            latestSearch++;
            closeSuggestions();
            return;
        }
        loadSuggestions(query);
    });

    searchInput.addEventListener('keydown', (event) => {
        if (suggestionBox.hidden) return;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            moveActiveSuggestion(event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            window.location.href = suggestionOptions()[activeIndex].href;
        } else if (event.key === 'Escape') {
            closeSuggestions();
        }
    });

    searchForm.addEventListener('submit', (event) => {
        if (searchInput.value.trim() === '') event.preventDefault();
    });

    document.addEventListener('click', (event) => {
        if (!searchForm.contains(event.target)) closeSuggestions();
    });

    // ------------------------------------------------------------------ Logged-in extras

    async function showUnreadCount() {
        const badge = document.querySelector('[data-unread-badge]');
        try {
            const { unread_count: unreadCount } = await api.get('/notifications/unread-count', { silent: true });
            badge.textContent = unreadCount;
            badge.hidden = unreadCount === 0;
        } catch {
            badge.hidden = true; // the bell still works; the count is only a hint
        }
    }

    async function logOut(button) {
        CHIMBO.setLoading(button, true);
        try {
            await api.post('/auth/logout');
            window.location.href = url();
        } catch {
            CHIMBO.setLoading(button, false);
        }
    }

    if (config.is_logged_in) {
        showUnreadCount();
        document.querySelectorAll('[data-logout]').forEach((button) => button.addEventListener('click', () => logOut(button)));
    }
})();
