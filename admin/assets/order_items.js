/**
 * Product rows of an order (admin/includes/order_item_row.php) on "Add order" and "Edit order": choosing a product
 * fills in its stock, minimum (MOQ) and the unit price its quantity reaches; "Add product" copies the row template;
 * the bin removes a row. The server prices the lines again when saving (with any running offer).
 *
 *   <div data-order-item-editor>
 *     <div data-order-items data-next-index="2"> …rows… </div>
 *     <template data-order-item-template> …one row with __INDEX__… </template>
 *     <button data-add-order-item>
 *   </div>
 *
 *   OrderItemRows.rowProduct(row) · OrderItemRows.tierPrice(tiers, quantity) · OrderItemRows.formatMoney(amount)
 */
window.OrderItemRows = (() => {
  'use strict';

  const formatMoney = (amount) => `TZS ${Number(amount).toLocaleString('en-US')}`;

  /** The tier price reached by this quantity (the biggest level whose minimum is not above it). */
  const tierPrice = (tiers, quantity) => {
    const reached = tiers.filter((tier) => tier.tier_min_quantity <= quantity);
    return (reached.at(-1) ?? tiers[0])?.tier_unit_price ?? 0;
  };

  /** The chosen product of a row (from its option's data-*), or null. */
  const rowProduct = (row) => {
    const option = row.querySelector('select').selectedOptions[0];
    if (!option?.value) {
      return null;
    }
    return {
      name: option.dataset.name,
      moq: Number(option.dataset.moq),
      stock: Number(option.dataset.stock),
      tiers: JSON.parse(option.dataset.tiers),
    };
  };

  /** Shows stock, MOQ and unit price for the row's product; a newly chosen product starts at its MOQ. */
  const updateItemRow = (row, productJustChosen = false) => {
    const product = rowProduct(row);
    const quantityInput = row.querySelector('input[type="number"]');
    const stockOutput = row.querySelector('[data-product-stock]');
    const moqOutput = row.querySelector('[data-product-moq]');
    const priceOutput = row.querySelector('[data-product-price]');

    if (product === null) {
      stockOutput.value = moqOutput.value = priceOutput.value = '—';
      quantityInput.min = '1';
      return;
    }
    if (productJustChosen) {
      quantityInput.value = String(product.moq);
    }
    // On "Edit order" a row may already hold stock reserved by this order (data-reserved), so it can keep that much more
    const reserved = Number(row.dataset.reserved ?? 0);
    stockOutput.value = String(product.stock);
    moqOutput.value = String(product.moq);
    quantityInput.min = String(product.moq);
    quantityInput.max = String(product.stock + reserved);
    priceOutput.value = formatMoney(tierPrice(product.tiers, Number(quantityInput.value || product.moq)));
  };

  /** Wires one editor ([data-order-item-editor] or the element holding its parts). */
  const attach = (root) => {
    const itemList = root.querySelector('[data-order-items]');
    const itemTemplate = root.querySelector('[data-order-item-template]');
    let nextItemIndex = Number(itemList.dataset.nextIndex);

    root.querySelector('[data-add-order-item]').addEventListener('click', () => {
      itemList.insertAdjacentHTML('beforeend', itemTemplate.innerHTML.replaceAll('__INDEX__', String(nextItemIndex)));
      nextItemIndex += 1;
      updateItemRow(itemList.lastElementChild);
    });
    itemList.addEventListener('click', (event) => {
      event.target.closest('[data-remove-order-item]')?.closest('[data-order-item]').remove();
    });
    itemList.addEventListener('change', (event) => {
      if (event.target.matches('select')) {
        updateItemRow(event.target.closest('[data-order-item]'), true);
      }
    });
    itemList.addEventListener('input', (event) => {
      if (event.target.matches('input[type="number"]')) {
        updateItemRow(event.target.closest('[data-order-item]'));
      }
    });
    itemList.querySelectorAll('[data-order-item]').forEach((row) => updateItemRow(row));
    return itemList;
  };

  // Editors on pages that need nothing more than the rows (e.g. "Edit order")
  document.querySelectorAll('[data-order-item-editor]').forEach(attach);

  return { attach, rowProduct, tierPrice, formatMoney };
})();
