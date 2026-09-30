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
 */
function adminDataTablesJson(array $pagination, callable $render_row): never
{
    adminSendJson([
        'draw'            => (int) ($_GET['draw'] ?? 0),
        'recordsTotal'    => $pagination['total'],
        'recordsFiltered' => $pagination['total'],
        'data'            => array_map(fn (array $row): array => $render_row($row) + ['DT_RowClass' => 'row-link'], $pagination['items']),
    ]);
}

/** Tells the table why the list could not be loaded (e.g. an invalid filter); admin.js shows the message. */
function adminDataTablesError(ApiException $e): never
{
    $fields = $e->fields();
    adminSendJson(['draw' => (int) ($_GET['draw'] ?? 0), 'error' => $fields ? reset($fields) : $e->getMessage()]);
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
    'in_transit'      => 'On the way',
    'cod_pending'     => 'Awaiting cash',
];

/** Order statuses in the order they happen (for filters). */
const ADMIN_ORDER_STATUSES = ['pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit', 'delivered', 'cancelled', 'expired'];

const ADMIN_PAYMENT_STATUSES = ['unpaid', 'pending', 'cod_pending', 'paid', 'failed', 'refunded', 'cancelled'];

const ADMIN_PAYMENT_METHOD_NAMES = [
    'mpesa'        => 'M-Pesa',
    'airtel_money' => 'Airtel Money',
    'mixx'         => 'Mixx by Yas',
    'bank'         => 'Bank',
    'cod'          => 'Cash on delivery',
];
