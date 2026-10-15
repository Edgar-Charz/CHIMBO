/**
 * "Edit order" (order_edit.php): the address form shows only the districts of the chosen region. The product rows
 * are order_items.js.
 */
(() => {
  'use strict';

  const form = document.querySelector('[data-address-form]');
  if (!form) {
    return;
  }
  const region = form.elements.region_id;
  const district = form.elements.district_id;

  const showRegionDistricts = () => {
    [...district.options].forEach((option) => {
      if (!option.value) {
        return;
      }
      option.hidden = option.dataset.region !== region.value;
      if (option.hidden && option.selected) {
        district.value = '';
      }
    });
  };
  region.addEventListener('change', showRegionDistricts);
  showRegionDistricts();
})();
