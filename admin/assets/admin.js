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
  const baseOptions = (table) => {
    const settings = table.dataset;
    return {
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
  const connectFilterForm = (filterForm, dataTable) => {
    filterForm.addEventListener('change', () => dataTable.ajax.reload());
    filterForm.addEventListener('submit', (event) => {
      event.preventDefault();
      dataTable.ajax.reload();
    });
    // "Clear" empties every filter, also ones opened from a link (?order_status=confirmed is the form's starting value,
    // which a plain reset would bring back), and drops them from the address so a refresh doesn't bring them back
    filterForm.addEventListener('reset', () => setTimeout(() => {
      filterForm.querySelectorAll('select').forEach((select) => { select.value = ''; });
      filterForm.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => { checkbox.checked = false; });
      window.history.replaceState(null, '', window.location.pathname);
      dataTable.ajax.reload();
    }));
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
    if (hasSerial) {
      dataTable.on('draw', () => numberRows(dataTable));
      numberRows(dataTable);
    }
    dataTable.on('dt-error', (event, settings, techNote, message) => showTableError(table, settings, message));
    dataTable.on('xhr', () => table.closest('.dt-container').querySelector('.table-error')?.remove());

    if (filterForm && isServerSide) {
      connectFilterForm(filterForm, dataTable);
    }
  });
})();
