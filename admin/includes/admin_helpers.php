<?php

/**
 * Small functions used only by admin pages (loaded by admin_bootstrap.php).
 */

/**
 * URL of an admin CSS/JS file with its last-change time added, e.g. admin.css?v=1727600000.
 * When the file changes the URL changes, so browsers load the new version instead of an old cached one.
 */
function adminAsset(string $path): string
{
    $file_path = BASE_PATH . '/admin/assets/' . $path;
    $version   = is_file($file_path) ? filemtime($file_path) : 0;
    return url('admin/assets/' . $path) . '?v=' . $version;
}

/**
 * Server-side DataTables send: draw, start, length, search[value], order[0][column] + [dir], columns[i][name],
 * plus our filter dropdowns as filters[...]. This turns that into the input the list methods take:
 * the filters, $search_key => the typed text, page, per_page, and sort + direction (the column's name).
 */
function adminDataTablesInput(array $request, string $search_key): array
{
    $length = max(1, (int) ($request['length'] ?? 25));
    $search = (array) ($request['search'] ?? []);
    $order  = (array) ($request['order'][0] ?? []);

    $input = (array) ($request['filters'] ?? []);
    $input[$search_key] = $search['value'] ?? '';
    $input['page']      = intdiv(max(0, (int) ($request['start'] ?? 0)), $length) + 1;
    $input['per_page']  = $length;

    $sort_key = $order ? ($request['columns'][(int) ($order['column'] ?? 0)]['name'] ?? '') : '';
    if ($sort_key !== '') {
        $input['sort']      = $sort_key;
        $input['direction'] = $order['dir'] ?? 'asc';
    }
    return $input;
}

/**
 * Answers a server-side DataTables request with one page of rows and stops.
 * $render_row turns a database row into its cells: ['column name' => escaped HTML, …].
 * $row_class 'row-link' makes the whole row clickable (its first link covers the row); '' for plain rows.
 */
function adminDataTablesJson(array $pagination, callable $render_row, string $row_class = 'row-link'): never
{
    adminSendJson([
        'draw'            => (int) ($_GET['draw'] ?? 0),
        'recordsTotal'    => $pagination['total'],
        'recordsFiltered' => $pagination['total'],
        'data'            => array_map(fn (array $row): array => $render_row($row) + ['DT_RowClass' => $row_class], $pagination['items']),
    ]);
}

/** Tells the table why the list could not be loaded (e.g. an invalid filter); admin.js shows the message. */
function adminDataTablesError(ApiException $e): never
{
    $fields = $e->fields();
    adminSendJson(['draw' => (int) ($_GET['draw'] ?? 0), 'error' => $fields ? reset($fields) : $e->getMessage()]);
}

/**
 * Sends a generated file (PDF, CSV …) to the browser and stops. $inline shows it in the browser tab
 * (e.g. a receipt to print) instead of saving it. Never cached: these files hold business or customer data.
 */
function adminSendFile(string $content, string $content_type, string $file_name, bool $inline = false): never
{
    $safe_file_name = preg_replace('/[^A-Za-z0-9._-]/', '', $file_name);
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe_file_name . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store');
    echo $content;
    exit;
}

/**
 * HTML → PDF with Dompdf, using the same safe settings as the receipts (Receipt::renderPdf()):
 * nothing is downloaded from the internet and only files inside the project can be read.
 */
function adminPdfFromHtml(string $html, string $orientation = 'portrait'): string
{
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');   // has every character used (TZS, –, •)
    $options->set('isRemoteEnabled', false);
    $options->set('chroot', BASE_PATH);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();
    return (string) $dompdf->output();
}

/** Sends $data as JSON and stops (used by the admin/ajax/ files). */
function adminSendJson(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** "Sold out" (red), the number in orange when low, or just the number. */
function adminStockBadge(int $stock): string
{
    return match (true) {
        $stock === 0                                => adminStatusBadge('sold_out'),
        $stock <= ProductEditor::LOW_STOCK_THRESHOLD => '<span class="status-badge status-warning">' . e(number_format($stock)) . '</span>',
        default                                     => e(number_format($stock)),
    };
}

/**
 * Handles a submitted form: checks the CSRF token, then runs $action($form_action).
 * $form_action is the value of the clicked button's name="form_action" (default 'save'), so one page can hold
 * several forms (save, upload photo, delete …). The action normally saves, flashes a message and redirects.
 *
 * Returns the ApiException when a class refused the input (to show its messages), otherwise null.
 */
function adminHandleForm(callable $action): ?ApiException
{
    if (!isPostRequest()) {
        return null;
    }
    Csrf::verifyOrFail($_POST['csrf_token'] ?? null);

    try {
        $action((string) ($_POST['form_action'] ?? 'save'));
        return null;
    } catch (ApiException $e) {
        return $e;
    }
}

/** Runs $load (e.g. fetch the product being edited); on "not found" goes back to the list page with the message. */
function adminLoadOrRedirect(callable $load, string $list_page): array
{
    try {
        return $load();
    } catch (ApiException $e) {
        Session::flash('error', $e->getMessage());
        redirect(url('admin/' . $list_page));
    }
}

/**
 * The values for the main form: what the admin just typed when saving it failed (so nothing is lost),
 * otherwise the saved row (or the defaults for a new record).
 */
function adminFormValues(?ApiException $form_error, array $saved_values): array
{
    $save_failed = $form_error !== null && ($_POST['form_action'] ?? 'save') === 'save';
    return $save_failed ? $_POST : $saved_values;
}

/** ' is-invalid' when this field has an error (Bootstrap then shows it red). */
function adminInvalidClass(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

/** The error message shown under an input, or '' when the field is fine. */
function adminFieldError(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}

/** 'checked' for a ticked checkbox (form values are 1/0 from the database, or "1"/missing from a POST). */
function adminChecked(array $form, string $field): string
{
    return empty($form[$field]) ? '' : 'checked';
}

/** 'selected' when the form value equals this option. */
function adminSelected(array $form, string $field, int|string $value): string
{
    return (string) ($form[$field] ?? '') === (string) $value ? 'selected' : '';
}

/** A small square picture for list rows (from a saved path like media/...), or a grey box with an icon. */
function adminThumbnail(?string $image_path, string $placeholder_icon = 'bi-image'): string
{
    return adminThumbnailFromUrl($image_path ? url($image_path) : null, $placeholder_icon);
}

/** The same, for data that already carries a full image URL (e.g. order items). */
function adminThumbnailFromUrl(?string $image_url, string $placeholder_icon = 'bi-image'): string
{
    if ($image_url === null || $image_url === '') {
        return '<span class="list-thumb list-thumb-empty"><i class="bi ' . e($placeholder_icon) . '"></i></span>';
    }
    return '<img class="list-thumb" src="' . e($image_url) . '" width="40" height="40" alt="" loading="lazy">';
}

/**
 * The rows of Category::getAllCategoriesForAdmin() grouped for a <select> with <optgroup>s:
 * [top category id => ['category_name' => 'Cosmetics', 'chips' => [chip rows …]], …]
 */
function adminCategoryGroups(array $categories): array
{
    $groups = [];
    foreach ($categories as $category) {
        if ($category['parent_category_id'] === null) {
            $groups[$category['category_id']] = ['category_name' => $category['category_name'], 'chips' => []];
        }
    }
    foreach ($categories as $category) {
        if (isset($groups[$category['parent_category_id']])) {
            $groups[$category['parent_category_id']]['chips'][] = $category;
        }
    }
    return $groups;
}

/**
 * Moves one id a place up (-1) or down (+1) in a list, e.g. to reorder photos: [5, 7, 9], 9, -1 → [5, 9, 7].
 * Moving the first up or the last down changes nothing.
 */
function adminMoveItem(array $ids, int $id, int $step): array
{
    $position     = array_search($id, $ids, true);
    $new_position = $position === false ? false : $position + $step;

    if ($new_position !== false && isset($ids[$new_position])) {
        [$ids[$position], $ids[$new_position]] = [$ids[$new_position], $ids[$position]];
    }
    return $ids;
}

/**
 * An audit-log entry's changes as a short list: "field: old → new" (or just the value when only one side exists);
 * fields whose value stayed the same are left out.
 * Long values (e.g. the legal texts) are shortened; at most $max_fields fields are shown.
 */
function adminAuditChanges(?array $old_values, ?array $new_values, int $max_fields = 8): string
{
    $fields = array_unique(array_merge(array_keys($old_values ?? []), array_keys($new_values ?? [])));
    // Fields saved with the same value are not changes (forms send every field)
    $fields = array_values(array_filter($fields, fn (string $field): bool => !(
        $old_values !== null && $new_values !== null
        && array_key_exists($field, $old_values) && array_key_exists($field, $new_values)
        && adminAuditValue($old_values[$field]) === adminAuditValue($new_values[$field])
    )));
    if ($fields === []) {
        return '<span class="text-muted">—</span>';
    }

    $lines = [];
    foreach (array_slice($fields, 0, $max_fields) as $field) {
        $has_old = $old_values !== null && array_key_exists($field, $old_values);
        $has_new = $new_values !== null && array_key_exists($field, $new_values);
        $change  = match (true) {
            $has_old && $has_new => adminAuditValue($old_values[$field]) . ' → ' . adminAuditValue($new_values[$field]),
            $has_new             => adminAuditValue($new_values[$field]),
            default              => adminAuditValue($old_values[$field]) . ' (before)',
        };
        $lines[] = '<li><span class="text-muted">' . e($field) . ':</span> ' . e($change) . '</li>';
    }
    if (count($fields) > $max_fields) {
        $lines[] = '<li class="text-muted">+ ' . e(count($fields) - $max_fields) . ' more</li>';
    }
    return '<ul class="audit-changes">' . implode('', $lines) . '</ul>';
}

/** One logged value as short text: lists/objects as JSON, empty as "—", long text cut to 60 characters. */
function adminAuditValue(mixed $value): string
{
    $text = match (true) {
        $value === null, $value === '' => '—',
        is_bool($value)                => $value ? 'yes' : 'no',
        is_array($value)               => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        default                        => (string) $value,
    };
    return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
}

/**
 * What an audit entry changed, linked to that record's admin page when there is one, e.g. "order #12".
 * Pages per entity type are in ADMIN_ENTITY_PAGES.
 */
function adminAuditEntity(string $entity_type, ?int $entity_id): string
{
    $label = str_replace('_', ' ', $entity_type) . ($entity_id ? " #{$entity_id}" : '');
    $page  = ADMIN_ENTITY_PAGES[$entity_type] ?? null;
    if ($page === null || ($entity_id === null && str_contains($page, '?'))) {
        return e($label);
    }
    $link = str_contains($page, '?') ? $page . $entity_id : $page;
    return '<a href="' . e(url('admin/' . $link)) . '">' . e($label) . '</a>';
}

/**
 * The "Actions" cell of a table row: small icon buttons made with adminActionLink() / adminActionButton().
 * It sits above the row's stretched link, so its buttons stay clickable.
 */
function adminRowActions(string ...$buttons): string
{
    return '<div class="row-actions">' . implode('', $buttons) . '</div>';
}

/** An icon button that opens a page (edit, view …); $new_tab for files such as a receipt. */
function adminActionLink(string $icon, string $label, string $url, bool $new_tab = false): string
{
    return '<a class="btn btn-sm btn-light" href="' . e($url) . '" title="' . e($label) . '" aria-label="' . e($label) . '"'
        . ($new_tab ? ' target="_blank" rel="noopener"' : '') . '><i class="bi ' . e($icon) . '"></i></a>';
}

/**
 * An icon button that changes something: a tiny POST form (with the CSRF token) sending form_action + record_id
 * to $form_url (the list page; empty = the current page). $confirm asks first, e.g. before deleting.
 */
function adminActionButton(string $icon, string $label, string $form_action, int $record_id, ?string $confirm = null,
                           string $form_url = '', bool $is_danger = false): string
{
    return '<form method="post"' . ($form_url !== '' ? ' action="' . e($form_url) . '"' : '')
        . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . Csrf::field()
        . '<input type="hidden" name="record_id" value="' . e($record_id) . '">'
        . '<button class="btn btn-sm btn-light' . ($is_danger ? ' text-danger' : '') . '" type="submit" name="form_action" value="' . e($form_action) . '"'
        . ' title="' . e($label) . '" aria-label="' . e($label) . '"><i class="bi ' . e($icon) . '"></i></button>'
        . '</form>';
}

/** An ApiException as one line for a flash message: its field messages, or its message when it has none. */
function adminErrorText(ApiException $e): string
{
    return implode(' ', $e->fields() ?: [$e->getMessage()]);
}

/**
 * The buttons for one payment waiting for review: Confirm (asks "did you see it on the statement?") and Reject
 * (opens the reason dialog, includes/payment_reject_modal.php). $form_url = the page that handles them.
 */
function adminPaymentReviewButtons(array $payment, string $order_number, string $form_url = ''): string
{
    $amount = adminMoney((int) $payment['payment_amount']);
    $payer  = adminPayerAccount($payment['payment_payer_account']);
    return adminActionButton('bi-check-lg', 'Confirm payment', 'confirm_payment', (int) $payment['payment_id'],
            "Did you see {$amount} from {$payer} with code {$payment['payment_reference']} on the statement?", $form_url)
        . '<button class="btn btn-sm btn-light text-danger" type="button" title="Reject payment" aria-label="Reject payment"'
        . ' data-open-modal="#reject-payment-modal" data-record-id="' . e($payment['payment_id']) . '"'
        . ' data-record-label="' . e($order_number . ' · ' . $amount) . '"><i class="bi bi-x-lg"></i></button>';
}

/** The number or account a payment came from, as shown to staff: phones as "+255 712 345 678", bank accounts unchanged. */
function adminPayerAccount(string $payer_account): string
{
    return str_starts_with($payer_account, '+255') ? Phone::format($payer_account) : $payer_account;
}

/** The record a row action is about (the hidden record_id of adminActionButton()). */
function adminActionRecordId(): int
{
    return (int) ($_POST['record_id'] ?? 0);
}

/** 5500 → "TZS 5,500"; "—" when there is no price. */
function adminMoney(?int $amount): string
{
    return $amount === null ? '—' : 'TZS ' . number_format($amount);
}

/** A UTC date from the database shown in Tanzania time, e.g. "28 Sep 2026, 14:05". "—" when empty. */
function adminDateTime(?string $utc_date_time, string $format = 'j M Y, H:i'): string
{
    if ($utc_date_time === null || $utc_date_time === '') {
        return '—';
    }

    $date = new DateTimeImmutable($utc_date_time, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone((string) Env::get('APP_TIMEZONE', 'UTC')))->format($format);
}

/** A calendar day that is already in Tanzania time ("2026-09-30", e.g. report days) → "30 Sep 2026", no time-zone shift. */
function adminCalendarDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('j M Y');
}

/** Only the date part, e.g. "28 Sep 2026". */
function adminDate(?string $utc_date_time): string
{
    return adminDateTime($utc_date_time, 'j M Y');
}

/**
 * A coloured status pill, e.g. adminStatusBadge('verified').
 * Colours come from ADMIN_STATUS_TONES, so the same status looks the same on every page.
 */
function adminStatusBadge(string $status): string
{
    $tone = ADMIN_STATUS_TONES[$status] ?? 'neutral';
    return '<span class="status-badge status-' . e($tone) . '">' . e(adminStatusName($status)) . '</span>';
}

/** 'cod_pending' → "Awaiting cash", 'packed' → "Packed" (ADMIN_STATUS_NAMES, or the status made readable). */
function adminStatusName(string $status): string
{
    return ADMIN_STATUS_NAMES[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * A payment's review state as a pill: Waiting for review (orange) / Confirmed (green) / Rejected (red).
 * Separate from adminStatusBadge() because "confirmed" means something else (blue) for orders.
 */
function adminPaymentReviewBadge(string $payment_status): string
{
    [$tone, $name] = ADMIN_PAYMENT_REVIEW_STATUSES[$payment_status] ?? ['neutral', ucfirst($payment_status)];
    return '<span class="status-badge status-' . e($tone) . '">' . e($name) . '</span>';
}

/**
 * How long a customer still has to pay, from a UTC deadline: "5 h 20 min left", "12 min left" or "Expired"
 * (orange when less than an hour is left). "—" when there is no deadline.
 */
function adminTimeLeft(?string $utc_deadline): string
{
    if ($utc_deadline === null || $utc_deadline === '') {
        return '<span class="text-muted">—</span>';
    }
    $seconds_left = (new DateTimeImmutable($utc_deadline, new DateTimeZone('UTC')))->getTimestamp() - time();
    if ($seconds_left <= 0) {
        return '<span class="text-danger">Expired</span>';
    }
    $hours   = intdiv($seconds_left, 3600);
    $minutes = intdiv($seconds_left % 3600, 60);
    $text    = ($hours > 0 ? "{$hours} h " : '') . "{$minutes} min left";
    return '<span class="' . ($hours === 0 ? 'text-warning-emphasis fw-semibold' : '') . '">' . e($text) . '</span>';
}

/** 'airtel_money' → "Airtel Money". */
function adminPaymentMethodName(string $payment_method): string
{
    return ADMIN_PAYMENT_METHOD_NAMES[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method));
}

/** "Juma Mushi" → "JM" (for the round avatar in lists). */
function adminInitials(?string $full_name): string
{
    $words = preg_split('/\s+/', trim((string) $full_name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return '?';
    }

    $first = mb_substr($words[0], 0, 1);
    $last  = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * The sidebar: group label => [menu key => [label, icon, page, permission]].
 * Pages use their menu key for $active_menu; placeholder pages read their title and icon from here.
 */
const ADMIN_MENU = [
    '' => [
        'dashboard'       => ['Dashboard', 'bi-speedometer2', 'index.php', 'dashboard.view'],
    ],
    'Sales' => [
        'orders'          => ['Orders', 'bi-receipt', 'orders.php', 'orders.view'],
        'payments'        => ['Payments', 'bi-credit-card', 'payments.php', 'payments.manage'],
        'customers'       => ['Customers', 'bi-people', 'customers.php', 'customers.view'],
        'delivery_agents' => ['Delivery Agents', 'bi-truck', 'delivery_agents.php', 'delivery.manage'],
    ],
    'Catalog' => [
        'products'        => ['Products', 'bi-box-seam', 'products.php', 'products.manage'],
        'categories'      => ['Categories', 'bi-diagram-3', 'categories.php', 'categories.manage'],
        'sellers'         => ['Sellers', 'bi-shop', 'sellers.php', 'sellers.manage'],
        'stock'           => ['Stock', 'bi-boxes', 'stock.php', 'inventory.manage'],
        'banners'         => ['Banners', 'bi-images', 'banners.php', 'banners.manage'],
    ],
    'Management' => [
        'reports'         => ['Reports', 'bi-bar-chart-line', 'reports.php', 'reports.view'],
        'settings'        => ['Settings', 'bi-gear', 'settings.php', 'settings.manage'],
        'admin_users'     => ['Admin Users', 'bi-person-badge', 'admin_users.php', 'admins.manage'],
        'audit_log'       => ['Audit Log', 'bi-journal-text', 'audit_log.php', 'audit.view'],
    ],
];

/** The menu without the items this admin may not open (and without groups that end up empty). */
function adminVisibleMenu(array $admin): array
{
    $visible_menu = [];
    foreach (ADMIN_MENU as $group_label => $menu_items) {
        $allowed_items = array_filter($menu_items, fn (array $item): bool => Admin::can($admin, $item[3]));
        if ($allowed_items) {
            $visible_menu[$group_label] = $allowed_items;
        }
    }
    return $visible_menu;
}

/** One menu item [label, icon, page, permission] by its key. */
function adminMenuItem(string $menu_key): array
{
    foreach (ADMIN_MENU as $menu_items) {
        if (isset($menu_items[$menu_key])) {
            return $menu_items[$menu_key];
        }
    }
    throw new LogicException("Unknown admin menu key: {$menu_key}");
}

/** Which colour each status gets (success = green, warning = orange, danger = red, neutral = grey). */
const ADMIN_STATUS_TONES = [
    'active'     => 'success',
    'verified'   => 'success',
    'pending'    => 'warning',
    'suspended'  => 'warning',
    'rejected'   => 'danger',
    'deleted'    => 'danger',
    'unverified' => 'neutral',
    'disabled'   => 'neutral',
    'locked'     => 'danger',
    'inactive'   => 'neutral',
    'hidden'     => 'neutral',
    'scheduled'  => 'warning',
    'ended'      => 'neutral',
    'in_stock'   => 'success',
    'low_stock'  => 'warning',
    'sold_out'   => 'danger',

    // Orders (info = blue: moving along normally)
    'pending_payment' => 'warning',
    'confirmed'       => 'info',
    'packed'          => 'info',
    'dispatched'      => 'info',
    'in_transit'      => 'info',
    'delivered'       => 'success',
    'cancelled'       => 'danger',
    'expired'         => 'neutral',

    // Order payments ('cancelled' = the order was cancelled before anything was paid; red like the order)
    'unpaid'      => 'warning',
    'cod_pending' => 'warning',
    'paid'        => 'success',
    'refunded'    => 'neutral',
    'failed'      => 'danger',
];

/** Statuses whose readable name is not simply the status with spaces. */
const ADMIN_STATUS_NAMES = [
    'pending_payment' => 'Awaiting payment',
    'pending'         => 'Payment sent',
    'in_transit'      => 'On the way',
    'cod_pending'     => 'Awaiting cash',
];

/** Order statuses in the order they happen (for filters). */
const ADMIN_ORDER_STATUSES = ['pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit', 'delivered', 'cancelled', 'expired'];

const ADMIN_PAYMENT_STATUSES = ['unpaid', 'pending', 'cod_pending', 'paid', 'failed', 'refunded', 'cancelled'];

/** The admin page that shows each kind of record ("?id=" pages get the record id added). */
const ADMIN_ENTITY_PAGES = [
    'admin'           => 'admin_user_edit.php?id=',
    'banner'          => 'banner_edit.php?id=',
    'category'        => 'category_edit.php?id=',
    'delivery_agent'  => 'delivery_agent_edit.php?id=',
    'delivery_method' => 'delivery_method_edit.php?id=',
    'order'           => 'order_details.php?id=',
    'product'         => 'product_edit.php?id=',
    'seller'          => 'seller_edit.php?id=',
    'settings'        => 'settings.php',
    'user'            => 'customer_details.php?id=',
];

/** Payment review states: status => [tone, name]. */
const ADMIN_PAYMENT_REVIEW_STATUSES = [
    'submitted' => ['warning', 'Waiting for review'],
    'confirmed' => ['success', 'Confirmed'],
    'rejected'  => ['danger', 'Rejected'],
];

/** Payment method types (payment_methods.payment_method_type). */
const ADMIN_PAYMENT_METHOD_TYPES = ['mobile_money' => 'Mobile money', 'bank' => 'Bank', 'cash' => 'Cash'];

const ADMIN_PAYMENT_METHOD_NAMES = [
    'mpesa'        => 'M-Pesa',
    'airtel_money' => 'Airtel Money',
    'mixx'         => 'Mixx by Yas',
    'bank'         => 'Bank',
    'cod'          => 'Cash on delivery',
];
