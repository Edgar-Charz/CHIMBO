/**
 * Home page: draws the product grids from the data the page printed (#home-products-data),
 * the "Ofa za muda" rail, switches the "Zinazouzwa Zaidi / Mpya / Ofa" tabs without reloading, and runs the
 * sub-category train.
 */
(() => {
    'use strict';

    const RAIL_SCROLL_SHARE = 0.8;    // one ‹ › press moves 80% of the visible width
    const RAIL_EDGE_TOLERANCE_PX = 2;

    const homeProducts = JSON.parse(document.getElementById('home-products-data').textContent);

    const showProducts = (grid, products) => grid.replaceChildren(...products.map(CHIMBO.productCard.render));

    // ------------------------------------------------------------------ "Karibu tena" (logged-in customers)

    const recentGrid = document.querySelector('[data-home-recent]');
    if (recentGrid) showProducts(recentGrid, homeProducts.recently_ordered);

    // ------------------------------------------------------------------ "Ofa za muda" (shown only while offers run)

    const offersGrid = document.querySelector('[data-home-offers]');
    if (offersGrid) showProducts(offersGrid, homeProducts.offers);

    // ------------------------------------------------------------------ Sub-category train

    /** ‹ › buttons move the row by most of its width; each shows only when there is more on its side. */
    function setUpScrollRail(rail) {
        const track = rail.querySelector('[data-rail-track]');
        const previousButton = rail.querySelector('[data-rail-prev]');
        const nextButton = rail.querySelector('[data-rail-next]');

        function updateButtons() {
            const canGoBack = track.scrollLeft > RAIL_EDGE_TOLERANCE_PX;
            const canGoForward = track.scrollLeft + track.clientWidth < track.scrollWidth - RAIL_EDGE_TOLERANCE_PX;
            previousButton.hidden = !canGoBack;
            nextButton.hidden = !canGoForward;
            rail.classList.toggle('can-scroll-back', canGoBack);
            rail.classList.toggle('can-scroll-forward', canGoForward);
        }

        const scrollBy = (direction) => track.scrollBy({ left: direction * track.clientWidth * RAIL_SCROLL_SHARE });
        previousButton.addEventListener('click', () => scrollBy(-1));
        nextButton.addEventListener('click', () => scrollBy(1));
        track.addEventListener('scroll', updateButtons, { passive: true });
        window.addEventListener('resize', updateButtons);
        updateButtons();
    }

    document.querySelectorAll('[data-scroll-rail]').forEach(setUpScrollRail);

    // ------------------------------------------------------------------ Product tabs

    const productsGrid = document.querySelector('[data-home-products]');
    if (!productsGrid) return;

    const tabs = [...document.querySelectorAll('[data-home-tab]')];
    const seeAllLink = document.querySelector('[data-home-see-all]');

    function selectTab(selectedTab) {
        tabs.forEach((tab) => {
            const isSelected = tab === selectedTab;
            tab.classList.toggle('is-active', isSelected);
            tab.setAttribute('aria-selected', String(isSelected));
            tab.tabIndex = isSelected ? 0 : -1;
        });
        productsGrid.setAttribute('aria-labelledby', selectedTab.id);
        seeAllLink.href = selectedTab.dataset.seeAll;
        showProducts(productsGrid, homeProducts.tabs[selectedTab.dataset.homeTab]);
    }

    /** Arrow keys move between tabs (the usual keyboard pattern for tabs). */
    function moveTabFocus(event) {
        const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
        if (!step) return;

        const nextTab = tabs[(tabs.indexOf(event.currentTarget) + step + tabs.length) % tabs.length];
        nextTab.focus();
        selectTab(nextTab);
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', moveTabFocus);
    });
    selectTab(tabs[0]);
})();
