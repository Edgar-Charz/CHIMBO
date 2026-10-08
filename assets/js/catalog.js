/**
 * Product lists (category, search, collections): draws the first page the server printed (#catalog-data),
 * then sort, filters and "Onyesha zaidi" load more pages from GET /products without reloading.
 * The address bar keeps the sort and filters, so a list can be shared and Back works.
 */
(() => {
    'use strict';

    const { api, stateBlock, errorState, setLoading, productCard } = CHIMBO;

    const FILTER_NAMES = ['min_price', 'max_price', 'max_moq'];
    const SKELETON_CARDS = 10;

    const catalog = JSON.parse(document.getElementById('catalog-data').textContent);
    const root = document.querySelector('[data-catalog]');
    const grid = root.querySelector('[data-catalog-grid]');
    const countLabel = root.querySelector('[data-catalog-count]');
    const sortSelect = root.querySelector('[data-catalog-sort]');
    const filterForm = root.querySelector('[data-catalog-filters]');
    const filterToggle = root.querySelector('[data-filter-toggle]');
    const filterCount = root.querySelector('[data-filter-count]');
    const morePanel = root.querySelector('[data-catalog-more]');
    const moreButton = root.querySelector('[data-catalog-load-more]');

    let filters = { ...catalog.filters };
    let loadedPage = 1;
    let shownCount = 0;
    let totalCount = catalog.total;
    let latestRequest = 0; // answers to an older sort/filter are ignored when they arrive late

    const activeFilterCount = () => FILTER_NAMES.filter((name) => filters[name] !== null).length;

    // ------------------------------------------------------------------ Drawing

    function showProducts(products, { append }) {
        const cards = products.map(productCard.render);
        if (append) {
            grid.append(...cards);
        } else {
            grid.replaceChildren(...cards);
        }
        shownCount = (append ? shownCount : 0) + products.length;
        countLabel.textContent = totalCount;

        if (shownCount === 0) showEmpty();
        updateMorePanel();
    }

    function showEmpty() {
        const hasFilters = activeFilterCount() > 0;
        grid.replaceChildren(stateBlock(hasFilters
            ? {
                iconName: 'sliders',
                title: 'Hakuna bidhaa kwa vichujio hivi',
                text: 'Badilisha bei au MOQ, au ondoa vichujio vyote.',
                actionLabel: 'Ondoa vichujio',
                onAction: clearFilters,
            }
            : {
                iconName: 'search',
                title: catalog.empty.title,
                text: catalog.empty.text,
                actionLabel: catalog.empty.action_label,
                actionHref: catalog.empty.action_href,
            }));
    }

    /** "Umeona bidhaa 20 kati ya 45" + "Onyesha zaidi" — only when the list is longer than one page. */
    function updateMorePanel() {
        morePanel.hidden = totalCount <= catalog.per_page;
        root.querySelector('[data-catalog-progress]').textContent = `Umeona bidhaa ${shownCount} kati ya ${totalCount}`;
        root.querySelector('[data-catalog-progress-bar]').style.width = `${totalCount === 0 ? 0 : (shownCount / totalCount) * 100}%`;
        moreButton.hidden = shownCount >= totalCount;
    }

    function updateFilterControls() {
        sortSelect.value = filters.sort;
        FILTER_NAMES.forEach((name) => {
            filterForm.elements[name].value = filters[name] ?? '';
        });
        const count = activeFilterCount();
        filterCount.textContent = count;
        filterCount.hidden = count === 0;
    }

    // ------------------------------------------------------------------ Loading

    async function loadPage({ append }) {
        const requestNumber = ++latestRequest;
        const page = append ? loadedPage + 1 : 1;

        if (append) {
            setLoading(moreButton, true);
        } else {
            grid.replaceChildren(...productCard.renderSkeletons(SKELETON_CARDS));
            morePanel.hidden = true;
        }

        try {
            const { data, meta } = await api.getPage('/products', {
                query: { ...catalog.base_query, ...filters, page, per_page: catalog.per_page },
                silent: !append,
            });
            if (requestNumber !== latestRequest) return;

            loadedPage = page;
            totalCount = meta.total;
            showProducts(data, { append });
        } catch (error) {
            if (!append && requestNumber === latestRequest) {
                grid.replaceChildren(errorState(error, () => loadPage({ append: false })));
            }
        } finally {
            setLoading(moreButton, false);
        }
    }

    /** A new sort or filter: remember it in the address bar, then load page 1. */
    function applyFilters(newFilters) {
        filters = { ...filters, ...newFilters };
        updateFilterControls();
        writeAddress();
        loadPage({ append: false });
    }

    function clearFilters() {
        applyFilters(Object.fromEntries(FILTER_NAMES.map((name) => [name, null])));
    }

    // ------------------------------------------------------------------ Address bar

    function writeAddress() {
        const address = new URL(window.location.href);
        const values = { ...filters, sort: filters.sort === catalog.default_sort ? null : filters.sort };
        Object.entries(values).forEach(([name, value]) => {
            if (value === null) {
                address.searchParams.delete(name);
            } else {
                address.searchParams.set(name, value);
            }
        });
        window.history.pushState(null, '', address);
    }

    function readAddress() {
        const params = new URLSearchParams(window.location.search);
        const readNumber = (name) => (params.has(name) ? Number.parseInt(params.get(name), 10) : null);
        return {
            sort: params.get('sort') ?? catalog.default_sort,
            ...Object.fromEntries(FILTER_NAMES.map((name) => [name, readNumber(name)])),
        };
    }

    // ------------------------------------------------------------------ Events

    const readFormNumber = (name) => {
        const value = Number.parseInt(filterForm.elements[name].value, 10);
        return Number.isNaN(value) ? null : value;
    };

    filterToggle.addEventListener('click', () => {
        const willOpen = filterForm.hidden;
        filterForm.hidden = !willOpen;
        filterToggle.setAttribute('aria-expanded', String(willOpen));
        if (willOpen) filterForm.elements.min_price.focus();
    });

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        applyFilters(Object.fromEntries(FILTER_NAMES.map((name) => [name, readFormNumber(name)])));
    });

    filterForm.addEventListener('reset', (event) => {
        event.preventDefault();
        clearFilters();
    });

    sortSelect.addEventListener('change', () => applyFilters({ sort: sortSelect.value }));
    moreButton.addEventListener('click', () => loadPage({ append: true }));

    window.addEventListener('popstate', () => {
        filters = readAddress();
        updateFilterControls();
        loadPage({ append: false });
    });

    // ------------------------------------------------------------------ Start

    updateFilterControls();
    if (catalog.error) {
        grid.replaceChildren(stateBlock({ iconName: 'exclamation-circle', title: 'Badilisha utafutaji wako', text: catalog.error, isError: true }));
    } else {
        showProducts(catalog.products, { append: false });
    }
})();
