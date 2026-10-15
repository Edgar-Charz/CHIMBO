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
        // A row from adminRowLink() brings its own class and data-href; the others get $row_class
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
 * The "Actions" cell of a table row: a ⋮ button that opens a menu of the actions made with adminActionLink() /
 * adminActionButton() (icon + name). Empty items (actions the row doesn't have) are left out; no actions → nothing.
 * The menu is placed against the screen, not the table (admin.js), so it is never cut off; it sits above the row's
 * own link, so clicking it never opens the row.
 */
function adminRowActions(string ...$items): string
{
    $items = array_filter($items, fn (string $item): bool => $item !== '');
    if ($items === []) {
        return '';
    }
    return '<div class="dropdown row-actions">'
        . '<button class="btn btn-sm btn-light row-actions__toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"'
        . ' title="Actions" aria-label="Actions"><i class="bi bi-three-dots-vertical"></i></button>'
        . '<ul class="dropdown-menu dropdown-menu-end row-actions__menu">' . implode('', $items) . '</ul>'
        . '</div>';
}

/** A menu item that opens a page (edit, view …); $new_tab for files such as a receipt. */
function adminActionLink(string $icon, string $label, string $url, bool $new_tab = false): string
{
    return '<li><a class="dropdown-item" href="' . e($url) . '"' . ($new_tab ? ' target="_blank" rel="noopener"' : '') . '>'
        . '<i class="bi ' . e($icon) . '"></i> ' . e($label) . '</a></li>';
}

/**
 * A menu item that changes something: a tiny POST form (with the CSRF token) sending form_action + record_id
 * to $form_url (the list page; empty = the current page). $confirm asks first, e.g. before deleting.
 */
function adminActionButton(string $icon, string $label, string $form_action, int $record_id, ?string $confirm = null,
                           string $form_url = '', bool $is_danger = false): string
{
    return '<li><form method="post"' . ($form_url !== '' ? ' action="' . e($form_url) . '"' : '')
        . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . Csrf::field()
        . '<input type="hidden" name="record_id" value="' . e($record_id) . '">'
        . '<button class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '" type="submit" name="form_action" value="' . e($form_action) . '">'
        . '<i class="bi ' . e($icon) . '"></i> ' . e($label) . '</button>'
        . '</form></li>';
}

/** For adminDataTablesJson() rows: the whole row opens $url (links and the actions menu inside still work). */
function adminRowLink(string $url): array
{
    return ['DT_RowAttr' => ['data-href' => $url], 'DT_RowClass' => 'row-link'];
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
        . '<li><button class="dropdown-item text-danger" type="button"'
        . ' data-open-modal="#reject-payment-modal" data-record-id="' . e($payment['payment_id']) . '"'
        . ' data-record-label="' . e($order_number . ' · ' . $amount) . '"><i class="bi bi-x-lg"></i> Reject payment</button></li>';
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
function adminStatusBadge(string $status, bool $is_pickup = false): string
{
    $tone = ADMIN_STATUS_TONES[$status] ?? 'neutral';
    return '<span class="status-badge status-' . e($tone) . '">' . e(adminStatusName($status, $is_pickup)) . '</span>';
}

/**
 * 'cod_pending' → "Awaiting cash", 'packed' → "Packed" (ADMIN_STATUS_NAMES, or the status made readable).
 * A pickup order's packed / delivered read "Ready for pickup" / "Picked up" (R-26).
 */
function adminStatusName(string $status, bool $is_pickup = false): string
{
    if ($is_pickup && isset(ADMIN_PICKUP_STATUS_NAMES[$status])) {
        return ADMIN_PICKUP_STATUS_NAMES[$status];
    }
    return ADMIN_STATUS_NAMES[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/** The transport line on admin pages: "Paid to the rider" (Bolt), "Pickup at the shop", or the fee. */
function adminDeliveryFee(array $order): string
{
    return match (true) {
        $order['delivery_method']['delivery_fee_paid_to_rider'] => 'Paid to the rider',
        $order['delivery_method']['delivery_method_is_pickup']  => 'Pickup at the shop',
        default                                                 => adminMoney($order['order_delivery_fee']),
    };
}

/**
 * A business's verification state as a pill: Not checked / Waiting for check (orange) / Verified (green) / Rejected (red).
 * Separate from adminStatusBadge() because "pending" means "Payment sent" there.
 */
function adminVerificationBadge(string $verification_status): string
{
    [$tone, $name] = ADMIN_VERIFICATION_STATUSES[$verification_status] ?? ['neutral', ucfirst($verification_status)];
    return '<span class="status-badge status-' . e($tone) . '">' . e($name) . '</span>';
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

/**
 * A row of dashboard number cards, all the same: an icon box, the number, the label under it, and optionally the
 * page the card opens. $per_row: 6 (tabs) or 4 (the top strip); fewer on smaller screens (admin.css).
 * $cards: [[label, value, icon, link or null], …] — value is formatted text ("1,240"), or adminCardMoney($amount)
 * for shillings ("524,100 TZS" with a small TZS; from a million up "260.5M TZS", the exact amount on hover).
 */
function adminStatCards(array $cards, int $per_row = 6): string
{
    $html = '<div class="stat-grid stat-grid--' . ($per_row === 4 ? 4 : 6) . '">';
    foreach ($cards as [$label, $value, $icon, $link]) {
        $value_html = is_array($value)
            ? '<span class="stat-number">' . e(adminShortAmount($value['money'])) . '</span> <span class="stat-currency">TZS</span>'
            : '<span class="stat-number">' . e($value) . '</span>';
        // A shortened amount ("260.5M") shows the exact one in a tooltip over the whole card (also over its link)
        $exact_amount = is_array($value) && abs($value['money']) >= 1_000_000 ? number_format($value['money']) . ' TZS' : null;
        $html .= '<div class="card stat-card h-100"'
            . ($exact_amount !== null ? ' data-bs-toggle="tooltip" data-bs-title="' . e($exact_amount) . '"' : '') . '>'
            . '<div class="card-body d-flex align-items-center gap-3">'
            . '<div class="stat-icon"><i class="bi ' . e($icon) . '"></i></div>'
            . '<div class="min-w-0"><div class="stat-value">' . $value_html . '</div><div class="small text-muted">' . e($label) . '</div></div>'
            . ($link !== null ? '<a class="stretched-link" href="' . e($link) . '" aria-label="Open ' . e($label) . '"></a>' : '')
            . '</div></div>';
    }
    return $html . '</div>';
}

/**
 * A dashboard chart: the canvas and its numbers, drawn by assets/dashboard.js.
 * $type 'daily' (a column per day) or 'bars' (a horizontal bar per item). $data: labels, values, format ('money' |
 * 'count'), optional titles and tooltip lines. $description is read by screen readers; the tab also has a table view.
 * Horizontal bars get a height that fits their number of items.
 */
function adminChart(string $id, string $type, array $data, string $description): string
{
    $height = $type === 'bars' ? max(120, count($data['values']) * 38 + 16) : 260;
    return '<div class="dashboard-chart" style="height: ' . $height . 'px">'
        . '<canvas data-chart="' . e($type) . '" data-chart-source="' . e($id) . '" role="img" aria-label="' . e($description) . '"></canvas>'
        . '</div>'
        . '<script type="application/json" id="' . e($id) . '">'
        . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
        . '</script>';
}

/**
 * An amount short enough for a card: below a million in full ("524,100"), then millions and billions with one
 * decimal ("260.5M", "1.2B", "3M" when the decimal is 0).
 */
function adminShortAmount(int $amount): string
{
    if (abs($amount) < 1_000_000) {
        return number_format($amount);
    }
    $millions = round($amount / 1_000_000, 1);
    // 999,999,999 rounds to 1,000M: say "1B" instead
    [$short, $letter] = abs($millions) < 1000 ? [$millions, 'M'] : [round($amount / 1_000_000_000, 1), 'B'];
    return rtrim(rtrim(number_format($short, 1), '0'), '.') . $letter;
}

/** A card value in shillings for adminStatCards(): shown as "524,100 TZS" with TZS small. */
function adminCardMoney(int $amount): array
{
    return ['money' => $amount];
}

/**
 * "Waiting for you" in the bell: what needs someone right now, as [count, label, icon, page link] — only counts above 0
 * that this staff member may act on (Dashboard::getAttentionCounts()).
 */
function adminAttentionItems(array $admin): array
{
    $counts = (new Dashboard(Database::instance()))->getAttentionCounts();
    $items = [
        ['payments_to_check',    'payment(s) to check',     'bi-cash-coin',              'payments.php?tab=review',  'payments.manage'],
        ['overdue_unpaid',       'overdue unpaid order(s)', 'bi-hourglass-bottom',       'orders.php?overdue=1',     'orders.view'],
        ['transport_to_confirm', 'transport price(s) to confirm', 'bi-truck',            'orders.php?transport_to_confirm=1', 'orders.manage'],
        ['orders_to_pack',       'order(s) to pack',        'bi-box-seam',               'orders.php?order_status=confirmed', 'orders.view'],
        ['refunds_due',          'refund(s) due',           'bi-arrow-counterclockwise', 'payments.php?tab=refunds', 'payments.manage'],
        ['businesses_to_verify', 'business(es) to verify',  'bi-patch-question',         'customers.php',            'customers.view'],
        ['low_stock',            'product(s) low on stock', 'bi-exclamation-triangle',   'stock.php',                'inventory.manage'],
    ];
    $visible = [];
    foreach ($items as [$key, $label, $icon, $page, $permission]) {
        if ($counts[$key] > 0 && Admin::can($admin, $permission)) {
            $visible[] = [$counts[$key], $label, $icon, url('admin/' . $page)];
        }
    }
    return $visible;
}

/** How long ago a UTC time was, for notifications: "just now", "5 min ago", "3 h ago", "2 days ago", then the date. */
function adminTimeAgo(string $utc_time): string
{
    $seconds = max(0, time() - (new DateTimeImmutable($utc_time, new DateTimeZone('UTC')))->getTimestamp());
    return match (true) {
        $seconds < 60          => 'just now',
        $seconds < 3600        => intdiv($seconds, 60) . ' min ago',
        $seconds < 86400       => intdiv($seconds, 3600) . ' h ago',
        $seconds < 7 * 86400   => intdiv($seconds, 86400) . (intdiv($seconds, 86400) === 1 ? ' day ago' : ' days ago'),
        default                => adminDateTime($utc_time, 'j M Y'),
    };
}

/** How long ago a UTC time was, for "waiting" columns: "3 days", "5 h", "12 min". Over 2 days it shows in red. */
function adminWaitingFor(?string $utc_since): string
{
    if ($utc_since === null || $utc_since === '') {
        return '<span class="text-muted">—</span>';
    }
    $seconds = max(0, time() - (new DateTimeImmutable($utc_since, new DateTimeZone('UTC')))->getTimestamp());
    $text = match (true) {
        $seconds >= 86400 => intdiv($seconds, 86400) . (intdiv($seconds, 86400) === 1 ? ' day' : ' days'),
        $seconds >= 3600  => intdiv($seconds, 3600) . ' h',
        default           => max(1, intdiv($seconds, 60)) . ' min',
    };
    return '<span class="' . ($seconds >= 2 * 86400 ? 'text-danger fw-semibold' : '') . '">' . e($text) . '</span>';
}

/**
 * A price before → during an offer, e.g. "TZS 5,500 → TZS 4,675" (the offer price worked out by Pricing,
 * the same way the shop does it).
 */
function adminOfferPrice(int $price, int $offer_percent): string
{
    return adminMoney($price) . ' → ' . adminMoney(Pricing::offerPrice($price, $offer_percent));
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
        'offers'          => ['Offers', 'bi-percent', 'offers.php', 'products.manage'],
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
    'running'    => 'success',
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
const ADMIN_PICKUP_STATUS_NAMES = ['packed' => 'Ready for pickup', 'delivered' => 'Picked up'];

const ADMIN_STATUS_NAMES = [
    'pending_payment' => 'Awaiting payment',
    'confirmed'       => 'Confirmed · to pack',   // the next step is always packing
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

/** Business verification states (business_profiles.business_verification_status): status => [tone, name]. */
const ADMIN_VERIFICATION_STATUSES = [
    'unverified' => ['neutral', 'Not checked'],
    'pending'    => ['warning', 'Waiting for check'],
    'verified'   => ['success', 'Verified'],
    'rejected'   => ['danger', 'Rejected'],
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
