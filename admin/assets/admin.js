/**
 * CHIMBO admin — small page behaviours, switched on with data-* attributes (no inline JavaScript).
 *
 *   <form data-confirm="Delete this banner?">   asks before submitting (also works on a submit button)
 *   [data-tier-editor]                          the product price levels (add / remove rows)
 *   <div data-show-when="order_status=dispatched">   shown only while that field of its form has that value
 *   <button data-open-modal="#reject-modal" data-record-id="12" data-record-label="CHB123 · TZS 5,000">
 *                                               opens that dialog with its form's record_id and [data-record-label] filled in
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
 */
(() => {
  'use strict';

  // Ask before dangerous actions (data-confirm on the form, or on the clicked button)
  document.addEventListener('submit', (event) => {
    const message = event.submitter?.dataset.confirm ?? event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

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
    const [fieldName, shownForValue] = part.dataset.showWhen.split('=');
    const field = part.closest('form').elements[fieldName];
    const update = () => {
      part.hidden = field.value !== shownForValue;
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
    const container = table.closest('.dt-container');
    container.querySelector('.table-error')?.remove();
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger table-error';
    alert.textContent = settings.jqXHR?.responseJSON?.error ?? message.replace(/^DataTables warning: .*? - /, '');
    container.prepend(alert);
  };

  /** Changing a filter dropdown reloads the table; "Clear" empties them first. */
  const connectFilterForm = (filterForm, dataTable) => {
    filterForm.addEventListener('change', () => dataTable.ajax.reload());
    filterForm.addEventListener('submit', (event) => {
      event.preventDefault();
      dataTable.ajax.reload();
    });
    filterForm.addEventListener('reset', () => setTimeout(() => dataTable.ajax.reload()));
  };

  document.querySelectorAll('table[data-datatable], table[data-datatable-source]').forEach((table) => {
    const filterForm = table.dataset.filterForm ? document.querySelector(table.dataset.filterForm) : null;
    const isServerSide = Boolean(table.dataset.datatableSource);

    const options = isServerSide
      ? { ...baseOptions(table), ...serverOptions(table, filterForm) }
      : {
          ...baseOptions(table),
          columns: [...table.querySelectorAll('thead th')].map((header) => ({ orderable: header.dataset.orderable !== 'false' })),
        };

    const dataTable = new DataTable(table, options);
    dataTable.on('dt-error', (event, settings, techNote, message) => showTableError(table, settings, message));
    dataTable.on('xhr', () => table.closest('.dt-container').querySelector('.table-error')?.remove());

    if (filterForm && isServerSide) {
      connectFilterForm(filterForm, dataTable);
    }
  });
})();
