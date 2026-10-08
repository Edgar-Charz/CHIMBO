/**
 * Region → district dropdowns (login step 3 and the checkout address form).
 *   await CHIMBO.locationSelect.connect(regionSelect, districtSelect, { regionId, districtId })
 * Regions load once per page (GET /regions); districts load when a region is chosen.
 */
(() => {
    'use strict';

    const { api, el } = CHIMBO;

    let regionsRequest = null;

    const loadRegions = () => {
        regionsRequest ??= api.get('/regions');
        return regionsRequest;
    };

    function fillSelect(select, placeholder, options, selectedValue) {
        select.replaceChildren(
            el('option', { text: placeholder, attrs: { value: '' } }),
            ...options.map(({ value, label }) => el('option', { text: label, attrs: { value, selected: String(value) === String(selectedValue) } })),
        );
    }

    async function showDistricts(districtSelect, regionId, selectedDistrictId = null) {
        districtSelect.disabled = true;
        if (!regionId) {
            fillSelect(districtSelect, 'Chagua mkoa kwanza', []);
            return;
        }

        fillSelect(districtSelect, 'Inapakia…', []);
        try {
            const districts = await api.get(`/regions/${regionId}/districts`, { silent: true });
            fillSelect(districtSelect, 'Chagua wilaya (hiari)', districts.map((district) => ({ value: district.district_id, label: district.district_name })), selectedDistrictId);
            districtSelect.disabled = false;
        } catch {
            fillSelect(districtSelect, 'Wilaya hazikupatikana — unaweza kuendelea', []); // the district is optional
        }
    }

    /** Fills both dropdowns with the given choice. Safe to call again (e.g. each time a form opens). */
    async function connect(regionSelect, districtSelect, { regionId = null, districtId = null } = {}) {
        const regions = await loadRegions();
        fillSelect(regionSelect, 'Chagua mkoa', regions.map((region) => ({ value: region.region_id, label: region.region_name })), regionId);
        if (!regionSelect.dataset.locationConnected) {
            regionSelect.addEventListener('change', () => showDistricts(districtSelect, regionSelect.value));
            regionSelect.dataset.locationConnected = 'true';
        }
        await showDistricts(districtSelect, regionId, districtId);
    }

    /** Reads a select as a whole number, or null when nothing is chosen. */
    const selectedNumber = (select) => (select.value === '' ? null : Number(select.value));

    CHIMBO.locationSelect = { connect, selectedNumber };
})();
