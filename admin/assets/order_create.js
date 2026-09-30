/**
 * "Add order" page (admin/order_create.php): one form shown as 3 steps.
 *   1. Customer + delivery address — find an existing customer (their saved addresses load), or type a new one.
 *      A new customer is saved first (ajax/order_customer_save.php), so the next steps use their account.
 *   2. Products — each row shows stock, MOQ and the tier price for the typed quantity.
 *   3. Delivery, payment and a summary with the estimated total (the server calculates the real one).
 * Everything the script needs comes from data-* attributes on the form; the server checks everything again.
 */
(() => {
  'use strict';

  const form = document.querySelector('[data-order-create]');
  if (!form) {
    return;
  }

  const find = (selector) => form.querySelector(selector);
  const field = (name) => form.elements[name];
  const formatMoney = (amount) => `TZS ${Number(amount).toLocaleString('en-US')}`;

  const itemList = find('[data-order-items]');
  const itemTemplate = find('[data-order-item-template]');
  const summary = find('[data-order-summary]');
  const steps = [...form.querySelectorAll('[data-order-step]')];
  const stepIndicators = [...form.querySelectorAll('[data-step-indicator]')];

  const customer = {
    userId: find('[data-user-id]'),
    picker: find('[data-customer-picker]'),
    search: find('[data-customer-search]'),
    results: find('[data-customer-results]'),
    selectedCard: find('[data-selected-customer]'),
    modeButton: find('[data-toggle-customer-mode]'),
    newFields: find('[data-new-customer-fields]'),
    saveError: find('[data-customer-save-error]'),
    nameInput: field('user_full_name'),
    phoneInput: field('user_phone'),
  };

  const address = {
    id: find('[data-address-id]'),
    picker: find('[data-saved-address-picker]'),
    savedSelect: find('[data-saved-address]'),
    fields: [...form.querySelectorAll('[data-address-field]')],
  };

  let currentStep = 1;
  let nextItemIndex = Number(itemList.dataset.nextIndex);

  // ---------------------------------------------------------------- Steps

  const goToStep = (step) => {
    currentStep = step;
    steps.forEach((section) => {
      section.hidden = Number(section.dataset.orderStep) !== step;
    });
    stepIndicators.forEach((indicator) => {
      const indicatorStep = Number(indicator.dataset.stepIndicator);
      indicator.classList.toggle('is-active', indicatorStep === step);
      indicator.classList.toggle('is-complete', indicatorStep < step);
    });
    if (step === 3) {
      renderSummary();
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  /** The browser's own checks (required, min …) for the visible fields of one step. */
  const stepIsValid = (step) => {
    const section = steps.find((item) => Number(item.dataset.orderStep) === step);

    if (step === 1 && !customer.userId.value && customer.newFields.hidden) {
      customer.modeButton.focus();
      return false;
    }
    if (step === 2) {
      const hasItems = itemList.querySelector('[data-order-item]') !== null;
      find('[data-order-items-error]').hidden = hasItems;
      if (!hasItems) {
        return false;
      }
    }

    const invalidField = [...section.querySelectorAll('input, select, textarea')]
      .find((input) => !input.disabled && !input.checkValidity());
    invalidField?.reportValidity();
    return invalidField === undefined;
  };

  // ---------------------------------------------------------------- Step 1: customer

  const showNewCustomerFields = (show) => {
    customer.newFields.hidden = !show;
    customer.newFields.querySelectorAll('input').forEach((input) => {
      input.disabled = !show;
    });
  };

  const setModeButton = (icon, text) => {
    customer.modeButton.replaceChildren();
    const iconElement = document.createElement('i');
    iconElement.className = `bi ${icon}`;
    customer.modeButton.append(iconElement, ` ${text}`);
  };

  /** Fills the address fields from a saved address (locked), or empties them for a new one. */
  const fillAddress = (savedAddress = null) => {
    address.id.value = savedAddress?.address_id ?? '';
    const values = {
      address_recipient_name: savedAddress?.address_recipient_name ?? '',
      address_phone: savedAddress?.address_phone ?? customer.phoneInput.value,
      region_id: savedAddress?.region_id ?? '',
      district_id: savedAddress?.district_id ?? '',
      address_street: savedAddress?.address_street ?? '',
      address_landmark: savedAddress?.address_landmark ?? '',
    };
    address.fields.forEach((input) => {
      input.value = values[input.name] ?? '';
      input.readOnly = savedAddress !== null;
      input.classList.toggle('is-locked', savedAddress !== null);
    });
    updateLocationChoices();
  };

  const selectCustomer = (chosen) => {
    customer.userId.value = chosen.user_id;
    customer.nameInput.value = chosen.user_full_name ?? '';
    customer.phoneInput.value = chosen.user_phone;
    customer.nameInput.readOnly = Boolean(chosen.user_full_name);
    customer.phoneInput.readOnly = true;
    showNewCustomerFields(true);

    find('[data-selected-customer-name]').textContent = chosen.user_full_name || 'No name yet';
    find('[data-selected-customer-phone]').textContent = chosen.user_phone;
    find('[data-selected-customer-business]').textContent = chosen.business_name ?? '';
    customer.selectedCard.hidden = false;
    customer.picker.hidden = true;
    customer.modeButton.hidden = true;

    address.savedSelect.replaceChildren(new Option('Enter a new delivery address', ''));
    chosen.addresses.forEach((savedAddress) => {
      const label = [savedAddress.address_recipient_name, savedAddress.address_street, savedAddress.district_name, savedAddress.region_name]
        .filter(Boolean).join(' · ');
      const option = new Option(label, savedAddress.address_id);
      option.dataset.address = JSON.stringify(savedAddress);
      address.savedSelect.add(option);
    });
    address.picker.hidden = false;

    const firstAddress = chosen.addresses[0] ?? null;
    address.savedSelect.value = firstAddress?.address_id ?? '';
    fillAddress(firstAddress);
  };

  const startNewCustomer = () => {
    customer.userId.value = '';
    customer.nameInput.value = '';
    customer.phoneInput.value = '';
    customer.nameInput.readOnly = false;
    customer.phoneInput.readOnly = false;
    showNewCustomerFields(true);
    customer.selectedCard.hidden = true;
    customer.picker.hidden = true;
    customer.modeButton.hidden = false;
    setModeButton('bi-search', 'Find an existing customer');
    address.picker.hidden = true;
    fillAddress();
    customer.nameInput.focus();
  };

  const startSearching = () => {
    customer.userId.value = '';
    customer.picker.hidden = false;
    customer.selectedCard.hidden = true;
    customer.modeButton.hidden = false;
    showNewCustomerFields(false);
    address.picker.hidden = true;
    setModeButton('bi-person-plus', 'Create a new customer');
    customer.search.value = '';
    customer.results.hidden = true;
    customer.search.focus();
  };

  const fetchCustomers = async (text) => {
    const response = await fetch(`${form.dataset.customersUrl}?q=${encodeURIComponent(text)}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      throw new Error('Could not load customers.');
    }
    return (await response.json()).items ?? [];
  };

  const showSearchMessage = (message, className) => {
    const item = document.createElement('div');
    item.className = `list-group-item ${className}`;
    item.textContent = message;
    customer.results.replaceChildren(item);
    customer.results.hidden = false;
  };

  const showSearchResults = (customers) => {
    if (customers.length === 0) {
      showSearchMessage('No customer found. Use "Create a new customer" below.', 'text-muted');
      return;
    }
    customer.results.replaceChildren(...customers.map((found) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'list-group-item list-group-item-action';
      button.textContent = `${found.user_full_name || 'No name yet'} · ${found.user_phone}`;
      button.addEventListener('click', () => selectCustomer(found));
      return button;
    }));
    customer.results.hidden = false;
  };

  let searchTimer;
  customer.search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const text = customer.search.value.trim();
    if (text.length < 2) {
      customer.results.hidden = true;
      return;
    }
    searchTimer = setTimeout(() => {
      fetchCustomers(text).then(showSearchResults).catch(() => showSearchMessage('Could not load customers. Try again.', 'text-danger'));
    }, 300);
  });

  customer.modeButton.addEventListener('click', () => (customer.newFields.hidden ? startNewCustomer() : startSearching()));
  find('[data-new-customer]').addEventListener('click', startNewCustomer);
  address.savedSelect.addEventListener('change', () => {
    const option = address.savedSelect.selectedOptions[0];
    fillAddress(option?.dataset.address ? JSON.parse(option.dataset.address) : null);
  });

  /** A new customer is saved before step 2, so the order is linked to their account and address. */
  const saveNewCustomer = async (button) => {
    const buttonContent = [...button.childNodes];
    button.disabled = true;
    button.textContent = 'Saving customer…';
    customer.saveError.hidden = true;
    try {
      const response = await fetch(form.dataset.saveCustomerUrl, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok) {
        throw new Error(Object.values(result.fields ?? {})[0] ?? result.error ?? 'Could not save this customer.');
      }
      selectCustomer(result.customer);
      address.savedSelect.value = String(result.address_id);
      fillAddress(result.customer.addresses.find((saved) => saved.address_id === result.address_id) ?? null);
      return true;
    } catch (error) {
      customer.saveError.textContent = error.message;
      customer.saveError.hidden = false;
      return false;
    } finally {
      button.disabled = false;
      button.replaceChildren(...buttonContent);
    }
  };

  // ---------------------------------------------------------------- Location choices

  /** Districts and delivery methods of the chosen region only (a method without a region is for everyone). */
  function updateLocationChoices() {
    const regionId = field('region_id').value;
    [[field('district_id'), false], [field('delivery_method_id'), true]].forEach(([select, allowsAnyRegion]) => {
      [...select.options].forEach((option) => {
        if (!option.value) {
          return;
        }
        const isAvailable = (allowsAnyRegion && !option.dataset.region) || option.dataset.region === regionId;
        option.hidden = !isAvailable;
        if (!isAvailable && option.selected) {
          select.value = '';
        }
      });
    });
  }

  field('region_id').addEventListener('change', updateLocationChoices);

  // ---------------------------------------------------------------- Step 2: products

  /** The tier price reached by this quantity (the biggest level whose minimum is not above it). */
  const tierPrice = (tiers, quantity) => {
    const reached = tiers.filter((tier) => tier.tier_min_quantity <= quantity);
    return (reached.at(-1) ?? tiers[0])?.tier_unit_price ?? 0;
  };

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
    stockOutput.value = String(product.stock);
    moqOutput.value = String(product.moq);
    quantityInput.min = String(product.moq);
    quantityInput.max = String(product.stock);
    priceOutput.value = formatMoney(tierPrice(product.tiers, Number(quantityInput.value || product.moq)));
  };

  find('[data-add-order-item]').addEventListener('click', () => {
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

  // ---------------------------------------------------------------- Step 3: summary

  const addSummaryLine = (label, value, className = '') => {
    const line = document.createElement('div');
    line.className = `d-flex justify-content-between gap-3 py-1 ${className}`;
    const labelElement = document.createElement('span');
    labelElement.className = className ? '' : 'text-muted';
    labelElement.textContent = label;
    const valueElement = document.createElement('span');
    valueElement.className = 'text-end';
    valueElement.textContent = value || '—';
    line.append(labelElement, valueElement);
    summary.append(line);
  };

  /** The table of chosen products; returns their subtotal. */
  const addSummaryItems = () => {
    const table = document.createElement('table');
    table.className = 'table admin-table my-2';
    const headerRow = table.createTHead().insertRow();
    ['Product', 'Quantity', 'Unit price', 'Line total'].forEach((heading, index) => {
      const cell = document.createElement('th');
      cell.textContent = heading;
      cell.className = index > 0 ? 'text-end' : '';
      headerRow.append(cell);
    });

    const body = table.createTBody();
    let subtotal = 0;
    itemList.querySelectorAll('[data-order-item]').forEach((row) => {
      const product = rowProduct(row);
      const quantity = Number(row.querySelector('input[type="number"]').value);
      if (product === null || quantity < 1) {
        return;
      }
      const unitPrice = tierPrice(product.tiers, quantity);
      subtotal += unitPrice * quantity;
      const tableRow = body.insertRow();
      [product.name, String(quantity), formatMoney(unitPrice), formatMoney(unitPrice * quantity)].forEach((value, index) => {
        const cell = tableRow.insertCell();
        cell.textContent = value;
        cell.className = index > 0 ? 'text-end text-nowrap' : '';
      });
    });
    summary.append(table);
    return subtotal;
  };

  const selectedText = (name) => {
    const option = field(name).selectedOptions[0];
    return option?.value ? option.textContent.trim() : '';
  };

  function renderSummary() {
    summary.replaceChildren();
    addSummaryLine('Customer', `${customer.nameInput.value} · ${customer.phoneInput.value}`);
    addSummaryLine('Deliver to', [field('address_recipient_name').value, field('address_street').value, selectedText('district_id'), selectedText('region_id')]
      .filter(Boolean).join(', '));

    const subtotal = addSummaryItems();
    const deliveryOption = field('delivery_method_id').selectedOptions[0];
    const deliveryFee = Number(deliveryOption?.dataset.fee ?? 0);
    addSummaryLine('Products', formatMoney(subtotal));
    addSummaryLine('Delivery', deliveryOption?.value ? formatMoney(deliveryFee) : 'Choose a delivery method above');
    addSummaryLine('Payment', selectedText('payment_method'));
    addSummaryLine('Estimated total', formatMoney(subtotal + deliveryFee), 'fw-bold border-top pt-2 mt-1');
  }

  ['delivery_method_id', 'payment_method'].forEach((name) => field(name).addEventListener('change', renderSummary));

  // ---------------------------------------------------------------- Navigation and start

  form.querySelectorAll('[data-step-next]').forEach((button) => {
    button.addEventListener('click', async () => {
      const step = Number(button.dataset.stepNext);
      if (!stepIsValid(step)) {
        return;
      }
      const needsSaving = step === 1 && !customer.userId.value;
      if (needsSaving && !(await saveNewCustomer(button))) {
        return;
      }
      goToStep(step + 1);
    });
  });
  form.querySelectorAll('[data-step-back]').forEach((button) => {
    button.addEventListener('click', () => goToStep(Number(button.dataset.stepBack) - 1));
  });

  form.addEventListener('submit', (event) => {
    const invalidStep = [1, 2, 3].find((step) => !stepIsValid(step));
    if (invalidStep !== undefined) {
      event.preventDefault();
      goToStep(invalidStep);
    }
  });

  /** After a failed save the page comes back with the typed values: restore the customer part. */
  const restoreCustomer = async () => {
    const savedUserId = customer.userId.value;
    if (savedUserId) {
      const savedAddressId = address.id.value;
      const typedAddress = Object.fromEntries(address.fields.map((input) => [input.name, input.value]));
      const found = (await fetchCustomers(customer.phoneInput.value)).find((item) => String(item.user_id) === savedUserId);
      if (found) {
        selectCustomer(found);
        const savedAddress = found.addresses.find((item) => String(item.address_id) === savedAddressId) ?? null;
        address.savedSelect.value = savedAddress?.address_id ?? '';
        fillAddress(savedAddress);
        if (savedAddress === null) {
          // A new address was typed for this customer: keep it
          address.fields.forEach((input) => {
            input.value = typedAddress[input.name];
          });
          updateLocationChoices();
        }
      }
    } else if (form.dataset.customerFormOpen === 'true') {
      showNewCustomerFields(true);
      customer.picker.hidden = true;
      setModeButton('bi-search', 'Find an existing customer');
    }
  };

  itemList.querySelectorAll('[data-order-item]').forEach((row) => updateItemRow(row));
  updateLocationChoices();
  restoreCustomer().catch(() => {});
  goToStep(Number(form.dataset.startStep));
})();
