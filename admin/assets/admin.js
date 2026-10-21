/**
 * CHIMBO admin — small page behaviours, switched on with data-* attributes (no inline JavaScript).
 *
 *   <form data-confirm="Delete this banner?">   asks before submitting (also works on a submit button)
 *   [data-tier-editor]                          the product price levels (add / remove rows)
 *   (confirm dialogs and messages: dialogs.js — SweetAlert)
 *   <div data-show-when="order_status=dispatched">   shown only while that field of its form has that value
 *   <div data-show-when="delivery_method_area!=all">  shown while that field has any other value
 *   <button data-open-modal="#reject-modal" data-record-id="12" data-record-label="CHB123 · TZS 5,000">
 *                                               opens that dialog with its form's record_id and [data-record-label] filled in
 *   <tr data-href="order_details.php?id=5">      the whole row opens that page (Ctrl / middle click: a new tab);
 *                                               links, buttons and the ⋮ menu inside the row keep their own action
 *   .row-actions (adminRowActions())            the ⋮ actions menu, placed against the screen so it is never cut off
 *   <div data-bs-toggle="tooltip" data-bs-title="260,498,100 TZS">   a Bootstrap tooltip on hover / focus
 *   [data-notification-bell]                    the bell: its dialog is loaded when opened, the badge is refreshed every minute
 *   [data-read-more]                            shows the rest of a long notification
 *
 * Tables (DataTables — search, sorting, pages):
 *   <table data-datatable>                      all rows are in the page; DataTables searches/sorts/pages them
 *   <table data-datatable-source="ajax/x.php">  rows come page by page from the server as JSON
 *     data-filter-form="#id"                    a form of filters (the filter card) sent along; changing one reloads the table
 *   data-title="All products" data-icon="bi-box-seam"   the card title shown left of the search box
 *     data-order='[[5,"desc"]]'                 first sort · data-ordering="false" no sorting · data-searching="false" no search box
 *     data-page-length="10" (default) · data-length-menu="[25,50,100]" (when the server allows other sizes)
 *     data-search-placeholder · data-empty-message
 *   <th data-column="price">                    (server) the JSON key shown in this column
 *   <th data-sort="created_at">                 (server) sortable, sent as the column name · data-cell-class="text-end"
 *   <th data-orderable="false">                 (in-page) not sortable
 *   Every table gets a first "#" column (S/N) counting the rows as shown: page 2 continues (11, 12 …) and a sort or
 *   search numbers them again from 1. data-serial="false" leaves it out. data-order counts columns without it.
 */
(() => {
  'use strict';

  const tabNavigation = document.querySelector('.dashboard-tabs, .page-tabs');
  if (tabNavigation) {
    const tabLinks = [...tabNavigation.querySelectorAll('a[href]')];
    const currentUrl = new URL(window.location.href);
    const tabStorageKey = `chimbo-admin:${document.body.dataset.adminId}:page-tab:${currentUrl.pathname}`;
    const currentTabLink = tabLinks.find((link) => link.classList.contains('active'));
    const currentTab = currentTabLink ? new URL(currentTabLink.href).searchParams.get('tab') : null;

    try {
      const savedTab = window.sessionStorage.getItem(tabStorageKey);
      const savedTabLink = tabLinks.find((link) => new URL(link.href).searchParams.get('tab') === savedTab);

      if (!currentUrl.searchParams.has('tab') && savedTabLink && savedTab !== currentTab) {
        currentUrl.searchParams.set('tab', savedTab);
        window.location.replace(currentUrl.toString());
        return;
      }

      if (currentTab) {
        window.sessionStorage.setItem(tabStorageKey, currentTab);
      }

      tabNavigation.addEventListener('click', (event) => {
        const tabLink = event.target.closest('a[href]');
        if (!tabLink || !tabNavigation.contains(tabLink)) {
          return;
        }

        const selectedTab = new URL(tabLink.href).searchParams.get('tab');
        if (selectedTab) {
          window.sessionStorage.setItem(tabStorageKey, selectedTab);
        }
      });
    } catch {
      // Tab memory is optional when browser storage is disabled.
    }
  }

  // Price levels: "Add level" copies the <template> row with a new index; the × button removes a row
  document.querySelectorAll('[data-tier-editor]').forEach((editor) => {
    const rowsContainer = editor.querySelector('[data-tier-rows]');
    const template = editor.querySelector('template');
    const addButton = editor.querySelector('[data-add-tier]');
    let nextIndex = Number(editor.dataset.nextIndex);

    addButton.addEventListener('click', () => {
      rowsContainer.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(nextIndex)));
      nextIndex += 1;
      rowsContainer.lastElementChild.querySelector('input').focus();
    });

    rowsContainer.addEventListener('click', (event) => {
      const removeButton = event.target.closest('[data-remove-tier]');
      if (removeButton) {
        removeButton.closest('[data-tier-row]').remove();
      }
    });
  });

  // Parts of a form that only matter for one choice, e.g. the delivery agent when dispatching
  document.querySelectorAll('[data-show-when]').forEach((part) => {
    const [, fieldName, notEqual, value] = part.dataset.showWhen.match(/^([^!=]+)(!?)=(.*)$/);
    const field = part.closest('form').elements[fieldName];
    const update = () => {
      part.hidden = (field.value === value) === (notEqual === '!');
    };
    field.addEventListener('change', update);
    update();
  });

  // A dialog form for one row (e.g. Reject with a reason): fill in which record, then open it.
  // Listening on the document also covers buttons in table rows loaded later by DataTables.
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-open-modal]');
    if (!button || typeof bootstrap === 'undefined') {
      return;
    }
    const dialog = document.querySelector(button.dataset.openModal);
    const form = dialog.querySelector('form');
    form.reset();
    form.elements.record_id.value = button.dataset.recordId;
    dialog.querySelectorAll('[data-record-label]').forEach((label) => {
      label.textContent = button.dataset.recordLabel ?? '';
    });
    bootstrap.Modal.getOrCreateInstance(dialog).show();
  });

  // The ⋮ actions menu of a table row. Tables sit in scrolling boxes that would cut a normal menu off, so the menu is
  // placed against the screen ("fixed") and kept inside it: it opens upwards near the bottom and moves left near the
  // right edge. The menu is set up just before Bootstrap opens it, which also covers rows that DataTables loads later.
  // Listening on window (capture) matters: Bootstrap's own click handler sits on document in the capture phase and
  // would otherwise create a plain menu first.
  const MENU_EDGE_GAP = 8; // px kept free between the menu and the screen edge
  const prepareRowMenu = (event) => {
    const toggle = event.target.closest?.('.row-actions__toggle');
    if (!toggle || typeof bootstrap === 'undefined' || bootstrap.Dropdown.getInstance(toggle)) {
      return;
    }
    bootstrap.Dropdown.getOrCreateInstance(toggle, {
      popperConfig: (defaultConfig) => ({
        ...defaultConfig,
        strategy: 'fixed',
        modifiers: [
          ...defaultConfig.modifiers,
          { name: 'preventOverflow', options: { boundary: document.documentElement, padding: MENU_EDGE_GAP } },
          { name: 'flip', options: { boundary: document.documentElement, padding: MENU_EDGE_GAP,
            fallbackPlacements: ['top-end', 'bottom-start', 'top-start'] } },
        ],
      }),
    });
  };
  window.addEventListener('click', prepareRowMenu, true);
  window.addEventListener('keydown', prepareRowMenu, true);

  // A fixed menu would stay put while the page scrolls under it: close open row menus when scrolling
  window.addEventListener('scroll', () => {
    document.querySelectorAll('.row-actions__toggle[aria-expanded="true"]').forEach((toggle) => {
      bootstrap.Dropdown.getInstance(toggle)?.hide();
    });
  }, { passive: true, capture: true });

  // A row with data-href opens its page. Clicks on a link, button, form or the actions menu inside the row do their
  // own thing; selecting text doesn't open anything. Ctrl / ⌘ / middle click opens a new tab, like a link.
  const openRow = (event) => {
    const row = event.target.closest('tr[data-href]');
    if (!row || event.target.closest('a, button, input, select, textarea, label, form, .dropdown-menu')
        || window.getSelection()?.toString()) {
      return;
    }
    if (event.button === 1 || event.ctrlKey || event.metaKey) {
      window.open(row.dataset.href, '_blank', 'noopener');
    } else if (event.button === 0) {
      window.location.href = row.dataset.href;
    }
  };
  document.addEventListener('click', openRow);
  document.addEventListener('auxclick', openRow);

  // Tooltips (data-bs-toggle="tooltip" + data-bs-title), e.g. the exact amount over a shortened dashboard card
  if (typeof bootstrap !== 'undefined') {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
      bootstrap.Tooltip.getOrCreateInstance(element, { delay: { show: 100, hide: 0 } });
    });
  }

  // ---------------------------------------------------------------- The bell (staff notifications)

  const bell = document.querySelector('[data-notification-bell]');
  if (bell) {
    const badge = bell.querySelector('[data-notification-count]');
    const dot = bell.querySelector('[data-notification-dot]');
    const panel = document.querySelector('[data-notification-panel]');
    const BADGE_REFRESH_MS = 60_000;

    // Red number = unread notifications; orange dot = nothing unread, but work is waiting ("Waiting for you")
    const showCount = (count, hasWaitingWork) => {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = count === 0;
      dot.hidden = count > 0 || !hasWaitingWork;
    };

    // Each time the dialog opens: the latest "Waiting for you" and notifications from the server
    document.getElementById('notifications-modal')?.addEventListener('show.bs.modal', async () => {
      panel.innerHTML = '<p class="text-muted p-3 mb-0">Loading…</p>';
      try {
        const response = await fetch(bell.dataset.panelUrl, { headers: { Accept: 'text/html' } });
        if (response.status === 401) {
          window.location.reload();   // the session ended → login page
          return;
        }
        panel.innerHTML = response.ok ? await response.text() : '<p class="text-danger p-3 mb-0">Could not load notifications. Try again.</p>';
      } catch {
        panel.innerHTML = '<p class="text-danger p-3 mb-0">No connection. Try again.</p>';
      }
    });

    // The badge, every minute (doesn't count as activity, so the idle logout still happens)
    setInterval(async () => {
      try {
        const response = await fetch(bell.dataset.countUrl, { headers: { Accept: 'application/json' } });
        if (response.ok) {
          const counts = await response.json();
          showCount(counts.unread_count, counts.has_waiting_work);
        }
      } catch {
        // offline for a moment: try again next minute
      }
    }, BADGE_REFRESH_MS);
  }

  // A permission-aware quick search for order, customer and product records.
  const global_search = document.querySelector('[data-admin-global-search]');
  if (global_search) {
    const form = global_search.querySelector('[data-global-search-form]');
    const input = form.elements.q;
    const results_panel = global_search.querySelector('.admin-global-search__results');
    let search_timer;
    let current_request;
    let latest_search = '';

    const showMessage = (message) => {
      results_panel.replaceChildren();
      const paragraph = document.createElement('p');
      paragraph.className = 'admin-global-search__message';
      paragraph.textContent = message;
      results_panel.append(paragraph);
      results_panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    };

    const renderResults = (groups) => {
      results_panel.replaceChildren();
      if (groups.length === 0) {
        showMessage('No matching orders, customers or products.');
        return;
      }

      groups.forEach((group) => {
        const section = document.createElement('section');
        section.className = 'admin-global-search__group';
        section.setAttribute('role', 'group');
        section.setAttribute('aria-label', group.label);

        const heading = document.createElement('h2');
        heading.className = 'admin-global-search__heading';
        const icon = document.createElement('i');
        icon.className = `bi ${group.icon}`;
        icon.setAttribute('aria-hidden', 'true');
        heading.append(icon, ` ${group.label}`);
        section.append(heading);

        group.items.forEach((item) => {
          const link = document.createElement('a');
          link.className = 'admin-global-search__result';
          link.href = item.url;
          link.setAttribute('role', 'option');

          const title = document.createElement('strong');
          title.textContent = item.title;
          const detail = document.createElement('small');
          detail.textContent = item.detail;
          link.append(title, detail);
          section.append(link);
        });
        results_panel.append(section);
      });
      results_panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    };

    const search = async (query) => {
      current_request?.abort();
      current_request = new AbortController();
      latest_search = query;
      showMessage('Searching…');

      try {
        const response = await fetch(`${global_search.dataset.searchUrl}?q=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
          signal: current_request.signal,
        });
        if (response.status === 401) {
          window.location.reload();
          return;
        }
        if (!response.ok) {
          throw new Error('Could not search right now. Try again.');
        }
        const data = await response.json();
        if (input.value.trim() === latest_search) {
          renderResults(data.groups ?? []);
        }
      } catch (error) {
        if (error.name !== 'AbortError' && input.value.trim() === latest_search) {
          showMessage(error.message || 'Could not search right now. Try again.');
        }
      }
    };

    input.addEventListener('input', () => {
      window.clearTimeout(search_timer);
      const query = input.value.trim();
      current_request?.abort();
      if (query.length < 2) {
        results_panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        return;
      }
      search_timer = window.setTimeout(() => search(query), 250);
    });

    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        results_panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
      } else if (event.key === 'ArrowDown') {
        const first_result = results_panel.querySelector('a');
        if (first_result) {
          event.preventDefault();
          first_result.focus();
        }
      }
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      results_panel.querySelector('a')?.click();
    });

    document.addEventListener('click', (event) => {
      if (!global_search.contains(event.target)) {
        results_panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', (event) => {
      const target = event.target;
      const typing = target instanceof HTMLElement
        && (target.matches('input, textarea, select') || target.isContentEditable);
      if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        input.focus();
      }
    });
  }

  // "Read more" on a long notification (the bell dialog and the Notifications page)
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-read-more]');
    if (!button) {
      return;
    }
    const text = button.closest('.notification-item__body');
    text.querySelector('[data-short-text]').hidden = true;
    text.querySelector('[data-full-text]').hidden = false;
    button.remove();
  });

  // ---------------------------------------------------------------- Tables

  if (typeof DataTable === 'undefined') {
    return;
  }
  DataTable.ext.errMode = 'none'; // errors are shown in the page (showTableError), not as a browser alert

  // Use DataTables' own plain layout boxes instead of Bootstrap grid rows (whose negative margins pushed the
  // title and search box against the card edge); admin.css styles them as the table card
  Object.assign(DataTable.ext.classes.layout, {
    row: 'dt-layout-row',
    cell: 'dt-layout-cell',
    tableRow: 'dt-layout-table',
    tableCell: '',
    start: 'dt-layout-start',
    end: 'dt-layout-end',
    full: 'dt-layout-full',
  });

  /** The card title (icon + text) shown top-left of the table, from data-title and data-icon. */
  const tableTitle = (table) => {
    if (!table.dataset.title) {
      return null;
    }
    const title = document.createElement('h2');
    title.className = 'table-card-title';
    if (table.dataset.icon) {
      const icon = document.createElement('i');
      icon.className = `bi ${table.dataset.icon}`;
      title.append(icon, ' ');
    }
    title.append(table.dataset.title);
    return title;
  };

  /** Options shared by every table, read from the table's data-* attributes. */
  const tableStateKey = (table) => {
    const route_parameters = new URLSearchParams(window.location.search);
    const filter_form = table.dataset.filterForm ? document.querySelector(table.dataset.filterForm) : null;
    if (filter_form) {
      [...filter_form.elements].forEach((control) => control.name && route_parameters.delete(control.name));
    }
    route_parameters.sort();
    const route = `${window.location.pathname}?${route_parameters.toString()}`;
    const table_name = table.id || table.dataset.datatableSource || table.dataset.title || 'table';
    return `chimbo-admin:${document.body.dataset.adminId}:${route}:${table_name}`;
  };

  const readSessionState = (key) => {
    try {
      const value = window.sessionStorage.getItem(key);
      return value === null ? null : JSON.parse(value);
    } catch {
      return null;
    }
  };

  const writeSessionState = (key, value) => {
    try {
      window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
      // State retention is optional when browser storage is disabled or full.
    }
  };

  const removeSessionState = (key) => {
    try {
      window.sessionStorage.removeItem(key);
    } catch {
      // State retention is optional when browser storage is disabled or full.
    }
  };

  const restoreFilterForm = (table, filterForm) => {
    if (!filterForm) {
      return;
    }
    if (filterForm.dataset.persistFilters === 'false') {
      removeSessionState(`${tableStateKey(table)}:filters`);
      return;
    }
    const route_parameters = new URLSearchParams(window.location.search);
    const controls = [...filterForm.elements];
    const has_link_filters = controls.some((control) => control.name && route_parameters.has(control.name));

    if (!has_link_filters) {
      const saved_values = readSessionState(`${tableStateKey(table)}:filters`);
      if (saved_values) {
        controls.forEach((control) => {
          if (!control.name || !Object.hasOwn(saved_values, control.name)) {
            return;
          }
          if (control.type === 'checkbox') {
            control.checked = saved_values[control.name] === true;
          } else if (control.type !== 'button' && control.type !== 'submit' && control.type !== 'reset') {
            control.value = saved_values[control.name];
          }
        });
      }
    }

    saveFilterForm(table, filterForm);
  };

  const saveFilterForm = (table, filterForm) => {
    const values = {};
    [...filterForm.elements].forEach((control) => {
      if (!control.name || ['button', 'submit', 'reset'].includes(control.type)) {
        return;
      }
      values[control.name] = control.type === 'checkbox' ? control.checked : control.value;
    });
    writeSessionState(`${tableStateKey(table)}:filters`, values);
  };

  const baseOptions = (table) => {
    const settings = table.dataset;
    return {
      stateSave: true,
      stateDuration: -1,
      stateSaveCallback: (_settings, state) => writeSessionState(tableStateKey(table), state),
      stateLoadCallback: () => readSessionState(tableStateKey(table)),
      pageLength: Number(settings.pageLength ?? 10),
      lengthMenu: settings.lengthMenu ? JSON.parse(settings.lengthMenu) : [10, 25, 50, 100],
      ordering: settings.ordering !== 'false',
      searching: settings.searching !== 'false',
      order: settings.order ? JSON.parse(settings.order) : [],
      autoWidth: false,
      layout: {
        topStart: tableTitle(table),
        topEnd: settings.searching === 'false' ? null : 'search',
        bottomStart: 'pageLength',
        bottomEnd: ['info', 'paging'],
      },
      language: {
        search: '',
        searchPlaceholder: settings.searchPlaceholder ?? 'Search…',
        lengthMenu: 'Show per page: _MENU_',
        info: '_START_ – _END_ of _TOTAL_ items',
        infoEmpty: '0 items',
        infoFiltered: '(filtered from _MAX_)',
        emptyTable: settings.emptyMessage ?? 'Nothing here yet.',
        zeroRecords: 'No matches. Try another search or filter.',
        processing: 'Loading…',
      },
    };
  };

  /** Rows loaded page by page from an admin/ajax/ file, with the filter form's values sent along. */
  const serverOptions = (table, filterForm) => {
    const headers = [...table.querySelectorAll('thead th')];
    return {
      serverSide: true,
      processing: true,
      searchDelay: 400,
      ajax: {
        url: table.dataset.datatableSource,
        data: (request) => {
          if (filterForm) {
            request.filters = Object.fromEntries(new FormData(filterForm));
          }
        },
      },
      columns: headers.map((header) => ({
        data: header.dataset.column,
        name: header.dataset.sort ?? '',
        orderable: Boolean(header.dataset.sort),
        className: header.dataset.cellClass ?? '',
      })),
    };
  };

  /** Shows why the table could not load; a finished session reloads the page (→ login). */
  const showTableError = (table, settings, message) => {
    if (settings.jqXHR?.status === 401) {
      window.location.reload();
      return;
    }
    AdminDialog.error(
      settings.jqXHR?.responseJSON?.error ?? message.replace(/^DataTables warning: .*? - /, ''),
      'The table could not load',
    );
  };

  /** Changing a filter dropdown reloads the table; "Clear" empties them first. */
  const connectFilterForm = (table, filterForm, dataTable) => {
    const persist_filters = filterForm.dataset.persistFilters !== 'false';
    const updateFilterUrl = () => {
      const route_parameters = new URLSearchParams(window.location.search);
      const controls = [...filterForm.elements].filter((control) => control.name);
      controls.forEach((control) => route_parameters.delete(control.name));
      if (persist_filters) {
        controls.forEach((control) => {
          const value = control.type === 'checkbox'
            ? (control.checked ? control.value : '')
            : control.value;
          if (value) {
            route_parameters.set(control.name, value);
          }
        });
      }
      route_parameters.sort();
      const query = route_parameters.toString();
      window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    };

    const rememberOrderReturnLink = () => {
      const return_parameters = new URLSearchParams(window.location.search);
      if (!persist_filters) {
        [...filterForm.elements].filter((control) => control.name)
          .forEach((control) => return_parameters.delete(control.name));
      }
      const return_query = return_parameters.toString();
      const return_to = `orders.php${return_query ? `?${return_query}` : ''}`;
      table.querySelectorAll('a[href*="order_details.php"]').forEach((link) => {
        const order_url = new URL(link.href);
        order_url.searchParams.set('return_to', return_to);
        link.href = order_url.toString();
      });
    };

    dataTable.on('draw', rememberOrderReturnLink);
    rememberOrderReturnLink();
    filterForm.addEventListener('change', (event) => {
      if (event.target instanceof Element && event.target.closest('.dt-search')) {
        return;
      }
      if (persist_filters) {
        saveFilterForm(table, filterForm);
      }
      updateFilterUrl();
      dataTable.ajax.reload();
    });
    filterForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (persist_filters) {
        saveFilterForm(table, filterForm);
      }
      updateFilterUrl();
      dataTable.ajax.reload();
    });
    // Clear the current table view while keeping page tabs such as ?tab=all in the address.
    filterForm.addEventListener('reset', (event) => {
      event.preventDefault();
      const controls = [...filterForm.elements];
      controls.forEach((control) => {
        if (control.tagName === 'SELECT' || ['text', 'search', 'number', 'date'].includes(control.type)) {
          control.value = '';
        } else if (control.type === 'checkbox') {
          control.checked = false;
        }
      });
      if (persist_filters) {
        saveFilterForm(table, filterForm);
      }
      updateFilterUrl();
      const search_input = table.closest('.dt-container')?.querySelector('.dt-search input[type="search"]');
      if (search_input) {
        search_input.value = '';
      }
      dataTable.search('').draw();
    });
  };

  /**
   * Adds the "#" (S/N) column in front of the table before DataTables starts: a header cell, an empty cell in every
   * row already in the page, and the first sort moved one column along (data-order is written without it).
   */
  const addSerialColumn = (table, options) => {
    const header = document.createElement('th');
    header.className = 'serial-column';
    header.textContent = '#';
    header.title = 'Row number';
    table.querySelector('thead tr').prepend(header);
    table.querySelectorAll('tbody tr').forEach((row) => row.prepend(document.createElement('td')));

    const serialColumn = { orderable: false, searchable: false, className: 'serial-column' };
    options.columns = [table.dataset.datatableSource ? { ...serialColumn, data: null, defaultContent: '' } : serialColumn, ...options.columns];
    options.order = options.order.map(([column, direction]) => [column + 1, direction]);
    // DataTables also reads data-order from the table itself; keep that copy moved along too
    if (table.dataset.order) {
      table.dataset.order = JSON.stringify(options.order);
    }
  };

  /** Numbers the rows on screen: 1, 2, 3 … continuing across pages (page 2 starts at 11 with 10 per page). */
  const numberRows = (dataTable) => {
    const { start } = dataTable.page.info();
    dataTable.column(0, { search: 'applied', order: 'applied', page: 'current' }).nodes().each((cell, index) => {
      cell.textContent = String(start + index + 1);
    });
  };

  document.querySelectorAll('table[data-datatable], table[data-datatable-source]').forEach((table) => {
    const filterForm = table.dataset.filterForm ? document.querySelector(table.dataset.filterForm) : null;
    restoreFilterForm(table, filterForm);
    const isServerSide = Boolean(table.dataset.datatableSource);
    const hasSerial = table.dataset.serial !== 'false';

    const options = isServerSide
      ? { ...baseOptions(table), ...serverOptions(table, filterForm) }
      : {
          ...baseOptions(table),
          columns: [...table.querySelectorAll('thead th')].map((header) => ({ orderable: header.dataset.orderable !== 'false' })),
        };
    if (hasSerial) {
      addSerialColumn(table, options);
    }

    const dataTable = new DataTable(table, options);
    const search_control = table.closest('.dt-container')?.querySelector('.dt-search');
    const search_input = search_control?.querySelector('input[type="search"]');
    if (search_control && search_input) {
      const table_name = (table.dataset.title ?? 'this table').replace(/^All\s+/i, '');
      const search_label = table.dataset.searchLabel ?? `Search ${table_name.toLowerCase()}`;
      search_input.setAttribute('aria-label', search_label);
    }
    if (hasSerial) {
      dataTable.on('draw', () => {
        numberRows(dataTable);
        dataTable.state.save();
      });
      numberRows(dataTable);
    } else {
      dataTable.on('draw', () => dataTable.state.save());
    }
    dataTable.on('dt-error', (event, settings, techNote, message) => showTableError(table, settings, message));
    dataTable.on('xhr', () => table.closest('.dt-container').querySelector('.table-error')?.remove());

    if (filterForm && isServerSide) {
      connectFilterForm(table, filterForm, dataTable);
    }

    const saved_scroll = Number(readSessionState(`${tableStateKey(table)}:scroll`) ?? 0);
    let restored_scroll = false;
    const restoreScroll = () => {
      if (!restored_scroll && saved_scroll > 0) {
        restored_scroll = true;
        requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, saved_scroll)));
      }
    };
    dataTable.on('draw', restoreScroll);
    if (!isServerSide) {
      restoreScroll();
    }
    let scroll_timer;
    window.addEventListener('scroll', () => {
      window.clearTimeout(scroll_timer);
      scroll_timer = window.setTimeout(
        () => writeSessionState(`${tableStateKey(table)}:scroll`, window.scrollY),
        120,
      );
    }, { passive: true });
    window.addEventListener('pagehide', () => {
      writeSessionState(`${tableStateKey(table)}:scroll`, window.scrollY);
    }, { once: true });
  });
})();
