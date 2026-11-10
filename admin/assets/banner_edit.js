/** Destination controls for the banner editor. */
(() => {
  const form = document.querySelector('[data-banner-target-form]');
  if (!form) {
    return;
  }

  const type_select = form.querySelector('[data-banner-target-type]');
  const panels = [...form.querySelectorAll('[data-banner-target-panel]')];
  const category_search = form.querySelector('[data-banner-category-search]');
  const product_search = form.querySelector('[data-banner-product-search]');
  const product_results = form.querySelector('[data-banner-product-results]');
  const product_status = form.querySelector('[data-banner-product-status]');
  const preview = document.querySelector('[data-banner-preview]');
  const preview_title = document.querySelector('[data-banner-preview-title]');
  const preview_subtitle = document.querySelector('[data-banner-preview-subtitle]');
  const preview_button = document.querySelector('[data-banner-preview-button]');
  const preview_action = document.querySelector('[data-banner-preview-action]');
  const preview_image = document.querySelector('[data-banner-preview-image]');
  const preview_placeholder = document.querySelector('[data-banner-preview-placeholder]');
  const preview_size_buttons = document.querySelectorAll('[data-banner-preview-size]');
  const preview_file = document.querySelector('[data-banner-preview-file]');
  const preview_initial_image = preview_image?.getAttribute('src') ?? '';
  let preview_object_url;
  let search_timer;
  let search_request;

  const update_preview = () => {
    if (!preview) {
      return;
    }

    preview_title.textContent = document.querySelector('#banner_title')?.value.trim() || 'Banner title';
    preview_subtitle.textContent = document.querySelector('#banner_subtitle')?.value.trim();
    preview_subtitle.hidden = !preview_subtitle.textContent;

    const selected_type = type_select.value;
    const button_label = document.querySelector('#banner_button_label')?.value.trim() || 'Nunua sasa';
    preview_button.textContent = button_label;
    preview_button.hidden = !selected_type;

    if (!selected_type) {
      preview_action.textContent = 'No banner action selected.';
    } else {
      const active_panel = form.querySelector(`[data-banner-target-panel="${selected_type}"]`);
      const target_field = active_panel?.querySelector('[data-banner-target-value]');
      const category_field = active_panel?.querySelector('[data-banner-category-search]');
      const product_field = active_panel?.querySelector('[data-banner-product-search]');
      const destination = category_field?.value.trim()
        || product_field?.value.trim()
        || target_field?.selectedOptions?.[0]?.textContent.trim()
        || target_field?.value.trim();
      const action_names = {
        category: 'Opens category',
        product: 'Opens product',
        collection: 'Opens collection',
        url: 'Opens web address',
      };
      preview_action.textContent = destination
        ? `${action_names[selected_type]}: ${destination}`
        : `${action_names[selected_type]}. Choose a destination to complete the preview.`;
    }
  };

  for (const field of document.querySelectorAll('#banner_title, #banner_subtitle, #banner_button_label')) {
    field.addEventListener('input', update_preview);
  }
  for (const button of preview_size_buttons) {
    button.addEventListener('click', () => {
      const size = button.dataset.bannerPreviewSize;
      preview.classList.toggle('banner-live-preview-mobile', size === 'mobile');
      preview.classList.toggle('banner-live-preview-desktop', size === 'desktop');
      for (const size_button of preview_size_buttons) {
        const is_selected = size_button === button;
        size_button.classList.toggle('active', is_selected);
        size_button.setAttribute('aria-pressed', String(is_selected));
      }
    });
  }
  preview_file?.addEventListener('change', () => {
    if (preview_object_url) {
      URL.revokeObjectURL(preview_object_url);
      preview_object_url = undefined;
    }
    const file = preview_file.files?.[0];
    const image_url = file ? URL.createObjectURL(file) : preview_initial_image;
    preview_image.src = image_url;
    preview_image.hidden = !image_url;
    preview_placeholder.hidden = Boolean(image_url);
  });

  const update_panels = () => {
    const selected_type = type_select.value;
    for (const panel of panels) {
      const is_active = panel.dataset.bannerTargetPanel === selected_type;
      panel.hidden = !is_active;
      for (const control of panel.querySelectorAll('[data-banner-target-value]')) {
        control.disabled = !is_active;
        if (is_active) {
          control.name = 'banner_target_value';
        } else {
          control.removeAttribute('name');
        }
      }
    }
  };

  const clear_inactive_targets = () => {
    for (const panel of panels) {
      if (panel.dataset.bannerTargetPanel === type_select.value) {
        continue;
      }
      const target_value = panel.querySelector('[data-banner-target-value]');
      if (target_value) {
        target_value.value = '';
      }
      if (panel.dataset.bannerTargetPanel === 'category' && category_search) {
        category_search.value = '';
      }
      if (panel.dataset.bannerTargetPanel === 'product' && product_search) {
        product_search.value = '';
        product_results.replaceChildren();
        product_results.hidden = true;
      }
    }
  };

  type_select.addEventListener('change', () => {
    window.clearTimeout(search_timer);
    search_request?.abort();
    clear_inactive_targets();
    update_panels();
    update_preview();
  });
  update_panels();

  if (category_search) {
    const category_options = form.querySelectorAll('#banner-category-options option');
    const category_value = form.querySelector('[data-banner-target-panel="category"] [data-banner-target-value]');
    const update_category = () => {
      const selected_option = [...category_options].find(
        (option) => option.value === category_search.value,
      );
      category_value.value = selected_option?.dataset.id ?? '';
    };
    category_search.addEventListener('input', update_category);
    category_search.addEventListener('change', update_category);
    category_search.addEventListener('input', update_preview);
    category_search.addEventListener('change', update_preview);
  }

  if (!product_search || !product_results || !product_status) {
    return;
  }

  const product_target = form.querySelector('[data-banner-target-panel="product"] [data-banner-target-value]');

  const show_message = (message) => {
    product_status.textContent = message;
  };

  const show_products = (products) => {
    product_results.replaceChildren();
    for (const product of products) {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'list-group-item list-group-item-action';
      option.setAttribute('role', 'option');
      option.textContent = `${product.title} (#${product.id})${product.detail ? ` - ${product.detail}` : ''}`;
      option.addEventListener('click', () => {
        product_search.value = `${product.title} (#${product.id})`;
        product_target.value = String(product.id);
        product_results.replaceChildren();
        product_results.hidden = true;
        product_search.setAttribute('aria-expanded', 'false');
        show_message(`Selected: ${product.title}`);
        update_preview();
      });
      product_results.append(option);
    }
    product_results.hidden = products.length === 0;
    product_search.setAttribute('aria-expanded', String(products.length > 0));
  };

  const search_products = async (query) => {
    search_request?.abort();
    search_request = new AbortController();
    const search_url = new URL(product_search.dataset.searchUrl, window.location.origin);
    search_url.searchParams.set('q', query);
    show_message('Searching products...');
    try {
      const response = await fetch(search_url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: search_request.signal,
      });
      if (!response.ok) {
        throw new Error('Product search failed. Try again.');
      }
      const result = await response.json();
      const products = result.groups
        ?.find((group) => group.label === 'Products')
        ?.items ?? [];
      show_products(products);
      show_message(products.length ? 'Choose a product from the results.' : 'No matching products found.');
    } catch (error) {
      if (error.name !== 'AbortError') {
        product_results.replaceChildren();
        product_results.hidden = true;
        product_search.setAttribute('aria-expanded', 'false');
        show_message(error.message);
      }
    }
  };

  product_search.addEventListener('input', () => {
    product_target.value = '';
    update_preview();
    product_results.replaceChildren();
    product_results.hidden = true;
    product_search.setAttribute('aria-expanded', 'false');
    window.clearTimeout(search_timer);
    const query = product_search.value.trim();
    if (query.length < 2) {
      show_message('Type at least 2 characters to search.');
      return;
    }
    search_timer = window.setTimeout(() => search_products(query), 250);
  });

  product_search.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      product_results.hidden = true;
      product_search.setAttribute('aria-expanded', 'false');
    } else if (event.key === 'ArrowDown') {
      const first_result = product_results.querySelector('button');
      if (first_result) {
        event.preventDefault();
        first_result.focus();
      }
    } else if (event.key === 'Enter') {
      const first_result = product_results.querySelector('button');
      if (first_result) {
        event.preventDefault();
        first_result.click();
      }
    }
  });

  document.addEventListener('click', (event) => {
    const product_panel = product_search.closest('[data-banner-target-panel="product"]');
    if (!product_panel.contains(event.target)) {
      product_results.hidden = true;
      product_search.setAttribute('aria-expanded', 'false');
    }
  });

  for (const field of form.querySelectorAll('[data-banner-target-value]')) {
    field.addEventListener('change', update_preview);
    field.addEventListener('input', update_preview);
  }
  update_preview();
})();
