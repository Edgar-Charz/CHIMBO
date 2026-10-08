# Classes for the admin pages (catalog)

Built by the backend session. Admin pages call these methods directly (no API). All tested (`tests/Integration/CatalogAdminTest.php`, `ProductPhotoTest.php`).

## How to use them in a page
```php
$current_admin = AdminSession::requireLogin('products.manage');
$product_editor = new ProductEditor(Database::instance());

if (isPostRequest()) {
    Csrf::verifyOrFail($_POST['csrf_token'] ?? null);
    try {
        $product_id = $product_editor->createProduct($_POST, $current_admin['admin_id']);
        Session::flash('success', 'Product saved.');
        redirect(url("admin/product_edit.php?id={$product_id}"));
    } catch (ApiException $e) {
        $errors = $e->fields();        // ['field_name' => 'message'] → show under each input
        $error_message = $e->getMessage();
    }
}
```
- **Form field names = the keys below** (= database column names). Extra fields (e.g. `csrf_token`) are ignored.
- **Checkboxes** (`*_is_active`, `*_is_verified`, `product_is_bestseller`): unticked sends nothing → saved as 0. Use `value="1"`.
- Every change is written to `audit_logs` automatically.
- Messages from `ApiException` are ready to show (validation messages from `Validator` are Kiswahili, the rest English).

## Category — `new Category($db)` · permission `categories.manage`
| Method | Notes |
|---|---|
| `getAllCategoriesForAdmin()` | top categories each followed by their chips; `parent_category_name`, `product_count` |
| `getTopCategories()` | for the "parent" dropdown |
| `getCategoryForAdmin($id)` | row for the edit form · 404 |
| `createCategory($input, $admin_id)` → id | fields: `category_name`*, `parent_category_id` (empty = top category), `category_tagline`, `category_sort_order`, `category_is_active` |
| `updateCategory($id, $input, $admin_id)` | same fields |
| `setCategoryImage($id, $_FILES['category_image'], $admin_id)` | picture for the Home card |
| `deleteCategory($id, $admin_id)` | only when empty; otherwise 409 `CATEGORY_IN_USE` → tell staff to hide it instead |
Rule: two levels only (a parent must be a top category). Products go in chips.

## Seller — `new Seller($db)` · permission `sellers.manage`
| Method | Notes |
|---|---|
| `getAllSellersForAdmin()` | with `product_count` |
| `getActiveSellers()` | for the product form dropdown |
| `getSellerForAdmin($id)` · `createSeller($input, $admin_id)` → id · `updateSeller($id, $input, $admin_id)` | fields: `seller_name`*, `seller_description`, `seller_phone`, `seller_is_verified`, `seller_status`* (`active`/`inactive` — inactive hides all its products) |

## Products — `new ProductEditor($db)` · permission `products.manage` / `inventory.manage`
| Method | Notes |
|---|---|
| `getProductsForAdmin($_GET)` | filters `q` (name/brand/SKU), `category_id`, `seller_id`, `status` (`active`/`hidden`), `stock` (`low`/`out`), `page`, `per_page` (`ProductEditor::PER_PAGE_OPTIONS` = 10/25/50/100, default 25), `sort` (`product_name`/`product_price`/`product_stock_quantity`/`created_at`) + `direction` (`asc`/`desc`, default `desc`; no sort = newest first) → `['items', 'total', 'page', 'per_page']`; items include `product_price`, `product_price_from`, `product_image_thumb_path` (use `url()`), names |
| `refreshStoredPrices($product_id)` | called for you by create/update. Only call it yourself if you ever write `product_price_tiers` directly — it copies the highest/lowest tier into `products.product_price` / `product_price_from`, which every product list reads |
| `getProductForAdmin($id)` | row + `tiers` + `images` · 404 |
| `createProduct($input, $admin_id)` → id | fields below + `product_stock_quantity` (opening stock) |
| `updateProduct($id, $input, $admin_id)` | fields below (stock is NOT changed here) |
| `adjustStock($id, $input, $admin_id)` → new stock | `movement_quantity_change`* (e.g. 50 or -3), `movement_reason`* (`restock`/`adjustment`/`return`), `movement_note` |
| `getStockMovements($id, $limit = 50)` | history with `admin_full_name` |
| `deleteProduct($id, $admin_id)` | hides it from shop and admin (row kept for history) |
Constants: `ProductEditor::UNIT_LABELS`, `ProductEditor::LOW_STOCK_THRESHOLD` (20).

Product fields: `product_name`*, `seller_id`*, `category_id`* (a chip), `product_brand`, `product_description`, `product_sku` (empty → auto `CHB-XXXXXX`), `product_unit_label`* (`pc`, `pack`, `set`, `box`, `dozen`, `carton`), `product_moq`*, `product_compare_at_price` (old price → "Ofa"; must be higher than the normal price), `product_is_bestseller`, `product_new_until` (date `YYYY-MM-DD` → "Bidhaa Mpya"), `product_delivery_days_min`*, `product_delivery_days_max`*, `product_is_active`, **`tiers`***.

**Tier editor:** send rows as `tiers[0][tier_min_quantity]`, `tiers[0][tier_unit_price]`, `tiers[1][…]` … (empty rows are ignored; up to 10).
Rules: the first level starts **at the MOQ**; each next level has a **bigger quantity and a lower price**. Errors come under the key `tiers`.

## Photos — `new ProductImage($db)` · permission `products.manage`
`getImages($product_id)` · `addImage($product_id, $_FILES['product_image'], $admin_id)` · `setMainImage($product_id, $image_id, $admin_id)` · `reorderImages($product_id, [$id1, $id2, …], $admin_id)` · `deleteImage($product_id, $image_id, $admin_id)`.
Max 8 photos; JPG/PNG/WEBP up to 8 MB, 200–6000 px; the first photo becomes the main one. Form needs `enctype="multipart/form-data"`. Each call returns the updated gallery (with `product_image_thumb_url` etc.).

## Banner — `new Banner($db)` · permission `banners.manage`
`getAllBannersForAdmin()` · `getBannerForAdmin($id)` · `createBanner($input, $admin_id)` → id · `updateBanner($id, $input, $admin_id)` · `setBannerImage($id, $_FILES['banner_image'], $admin_id)` · `deleteBanner($id, $admin_id)`.
Fields: `banner_title`*, `banner_subtitle`, `banner_button_label`, `banner_target_type` (`category`/`product`/`collection`/`url`) + `banner_target_value` (id, `deals`/`new`/`best_sellers`, or address), `banner_sort_order`, `banner_is_active`, `banner_starts_at` / `banner_ends_at` (`<input type="datetime-local">`, **typed in Tanzania time**; saved in UTC — show stored times with a UTC→local conversion).

## Orders — `new OrderManager($db)` · permission `orders.manage` (view: `orders.view`)
| Method | Notes |
|---|---|
| `getOrdersForAdmin($_GET)` | filters `q` (order number, customer name/phone), `order_status`, `order_payment_status`, `page`, `per_page` (`OrderManager::PER_PAGE_OPTIONS`), `sort` (`order_placed_at` / `order_total` / `order_number`) + `direction` (`asc`/`desc`, default `desc`; no sort = newest first) → `['items', 'total', 'page', 'per_page']`; items have customer name/phone, total, `item_count`, region |
| `getOrderForAdmin($id)` | the full order (same shape as the API: items, `events` timeline, `delivery_agent`, address copy) + `customer`, `delivery`, **`allowed_next_statuses`**, **`can_confirm_cash`** · 404 |
| `changeStatus($id, $input, $admin_id)` | `order_status`* (one of `allowed_next_statuses`), `status_note`, `delivery_agent_id` (**required for `dispatched`**). Cancelling returns the stock. The customer is notified. Wrong step → 409 `STATUS_CHANGE_NOT_ALLOWED` |
| `confirmCashCollected($id, $input, $admin_id)` | cash on delivery, after `delivered`: `delivery_cash_collected`* must equal the order total → payment becomes `paid` |
`getManualOrderOptions()`, `createManualCustomer($input, $admin_id)`, `createManualOrder($input, $admin_id)` → order id: an order taken by phone or in person (same order number format, timeline, notification, stock reservation and COD limit as app orders — they come from the shared `Order` helpers).
Payment status `cancelled` = a cancelled/expired order that owed nothing more (shown instead of `cod_pending`).

Constant `OrderManager::ALLOWED_TRANSITIONS` = the allowed steps (confirmed → packed → dispatched → in_transit → delivered; cancel before dispatch).

## Receipts — `new Receipt($db)`
`createReceiptForAdmin($order_id)` → `['pdf' => bytes, 'file_name' => 'Risiti-CHB123456.pdf']` for a "Print receipt" button, e.g.
`header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $receipt['file_name'] . '"'); echo $receipt['pdf'];`
Constants for labels: `Receipt::ORDER_STATUS_NAMES`, `PAYMENT_METHOD_NAMES`, `PAYMENT_STATUS_NAMES` (Kiswahili).

## Delivery agents — `new DeliveryAgent($db)` · permission `delivery.manage`
`getAllAgentsForAdmin()` (with `active_order_count`) · `getActiveAgents()` (for the dispatch dropdown) · `getAgentForAdmin($id)` · `createAgent($input, $admin_id)` → id · `updateAgent($id, $input, $admin_id)` · `setAgentPhoto($id, $_FILES['delivery_agent_photo'], $admin_id)`.
Fields: `delivery_agent_full_name`*, `delivery_agent_phone`*, `delivery_agent_is_active`.

\* = required

## Customer PIN (`CustomerPin`) — added 2026-09-30

| Method | Use |
|---|---|
| `forcePinReset($user_id, $admin_id)` | Button **"Lazimisha kubadili PIN"** on the customer page (POST + CSRF, permission `customers.manage` — operations role and super admin). Locks the PIN and logs the customer out on every device; their next login goes through an SMS code and a new PIN. Writes the audit log (`customer.pin_reset_forced`). Throws `NOT_FOUND` when the customer has no PIN. |

Show "Ana PIN: Ndiyo/Hapana" and "PIN imefungwa" on the customer page from `users.user_pin_hash IS NOT NULL` and `users.user_pin_locked_at IS NOT NULL` (never select or show the hash itself). Staff can never see or set a customer's PIN.

## Admin users, Audit log, Reports, Settings — added 2026-10-01

Permissions: `admins.manage`, `audit.view` and `settings.manage` belong to **super admin only** (its `*`); `reports.view` = finance + super admin.
Every save below writes the audit log itself — pages must not write it again. All methods throw `ApiException`; show `$e->fields()` under the inputs as on the other forms.

### `AdminUser` — page `admin_users.php` (+ an edit page) · `admins.manage`
| Method | Use |
|---|---|
| `listAdmins()` | every account: `admin_id, admin_full_name, admin_email, admin_role, admin_status, admin_is_locked, admin_last_login_at, created_at` (show the role with `Admin::ROLE_NAMES`) |
| `getAdminById($id)` | one account (no password hash) · 404 |
| `createAdmin($_POST, $admin_id)` | fields `admin_full_name, admin_email, admin_role, admin_password, admin_password_confirmation` (password ≥ 10 characters) → new id |
| `updateAdmin($id, $_POST, $admin_id)` | fields `admin_full_name, admin_email, admin_role, admin_status` (`active`/`disabled`). Refuses disabling or demoting yourself and removing the last active super admin |
| `resetPassword($id, $_POST, $admin_id)` | fields `admin_password, admin_password_confirmation`; also unlocks a locked account |

### `AuditLog` — page `audit_log.php` · `audit.view`
| Method | Use |
|---|---|
| `search($_GET)` | filters `admin_id, actor_type (admin/customer/system), action, entity_type, entity_id, date_from, date_to` (Y-m-d, East Africa days), `page`, `per_page` (`AuditLog::PER_PAGE_OPTIONS` 25/50/100, default 50) → `['items', 'total', 'page', 'per_page']`, newest first. Items: `audit_log_id, audit_log_actor_type, audit_log_actor_id, admin_full_name, audit_log_action, audit_log_entity_type, audit_log_entity_id, audit_log_old_values, audit_log_new_values` (already arrays — show as a small "field: old → new" list), `audit_log_ip_address, created_at` (UTC → `localDateTime()`) |
| `getActions()` / `getEntityTypes()` | values for the two filter dropdowns |

### `Report` — page `reports.php` · `reports.view`
| Method | Use |
|---|---|
| `getSalesReport($_GET)` | `date_from`, `date_to` (Y-m-d; default the last 30 days; at most one year) → `date_from, date_to, summary {order_count, revenue, average_order, pieces_sold, delivery_fees, cancelled_count}, by_day [{sales_date, order_count, revenue}], by_category [{category_name, quantity, revenue}], top_products [{product_id, product_name, product_sku, quantity, revenue}] (top 20), by_payment_method [{order_payment_method, order_count, revenue, paid_total}], by_region [{region_name, order_count, revenue}]` |
| `exportCsv($section, $_GET)` | `$section` one of `Report::SECTIONS` → `['file_name', 'content']`; send it with `Response::download($file['content'], 'text/csv; charset=utf-8', $file['file_name'])->send()` or the same headers yourself |

Cancelled and expired orders are left out (only counted in `cancelled_count`). Money = whole TZS.

### `Settings` + `DeliveryMethod` — page `settings.php` · `settings.manage`
| Method | Use |
|---|---|
| `Settings::getEditableSettings()` | `[group title => [[setting_key, label, setting_value], …]]` — build the form from this (`legal_terms` / `legal_privacy` as large textareas, the rest as inputs) |
| `Settings::updateSettings($_POST, $admin_id)` | send **every** field of the form; only changed values are saved; field errors are keyed by `setting_key` |
| `DeliveryMethod::listForAdmin()` | delivery options with `region_name` (null = all regions), fee, days, `delivery_method_is_active`, sort order |
| `DeliveryMethod::getMethodById($id)` / `createMethod($_POST, $admin_id)` / `updateMethod($id, $_POST, $admin_id)` | fields `delivery_method_name, delivery_method_fee, delivery_method_eta_min_days, delivery_method_eta_max_days, region_id` (empty = every region), `delivery_method_is_active` (checkbox), `delivery_method_sort_order`. Fee changes affect new checkouts only |

`payments.php` stays "coming soon" until mobile money (Phase 5).

## One-click switches — added 2026-10-04

For the list buttons (Hide / Show, Activate / Deactivate, Verified). Each changes **only that one column**, writes only that change to the audit log (`<entity>.updated`, old → new), and returns `false` when the value was already set (nothing written). No need to load the record, re-save the form or convert banner times.

| Method | Button |
|---|---|
| `ProductEditor::setProductActive($product_id, bool $is_active, $admin_id)` | products: Hide / Show (deleted products → 404) |
| `Category::setCategoryActive($category_id, bool, $admin_id)` | categories: Hide / Show in the shop |
| `Banner::setBannerActive($banner_id, bool, $admin_id)` | banners: Hide / Show (schedule untouched) |
| `Seller::setSellerVerified($seller_id, bool, $admin_id)` | sellers: Verified |
| `Seller::setSellerStatus($seller_id, 'active' \| 'inactive', $admin_id)` | sellers: Activate / Deactivate |
| `DeliveryAgent::setDeliveryAgentActive($delivery_agent_id, bool, $admin_id)` | delivery agents: Activate / Deactivate |
| `DeliveryMethod::setDeliveryMethodActive($delivery_method_id, bool, $admin_id)` | settings: Offer at checkout / Stop offering |

Example (products.php): `$product_editor->setProductActive($product_id, !$product['product_is_active'], $admin_id);` — you still need the row for the flash message's name, but not for saving.

## Payments page — added 2026-10-04

Customers pay with the "pay to" details, then send the number they paid from + the confirmation code ("Nimelipa"). Staff compare it with the M-Pesa / bank statement and confirm or reject. **Only staff ever mark an order paid.** Every action below writes the audit log and notifies the customer itself.

Permissions: `payments.manage` (finance + super admin) for reviewing, recording and refunds. **`payment_methods.manage` = super admin only** (changing the account customers pay to is the most sensitive setting in the shop).

### `Payment` — page `payments.php` (tabs: Waiting for review · All payments · Refunds due) · `payments.manage`
| Method | Use |
|---|---|
| `searchPayments($_GET)` | filters `payment_status` (`submitted` = waiting, `confirmed`, `rejected`), `payment_method_code`, `search` (order number, confirmation code or payer number in any format), `page`, `per_page` (`Payment::PER_PAGE_OPTIONS` 25/50/100) → `['items', 'total', 'page', 'per_page']`. Items: every `payments` column + `order_number, order_total, order_status, order_payment_status, order_expires_at, user_full_name, user_phone, business_name, reviewed_by_admin_name, submitted_by_admin_name` |
| `countWaitingForReview()` | badge on the menu item |
| `confirmPayment($payment_id, $admin_id)` | button **Confirm** (POST + CSRF, confirm dialog "Did you see TZS X from {payer} with code {ref} on the statement?"). Order → `confirmed` + `paid`. Errors: `PAYMENT_ALREADY_REVIEWED`, `ORDER_NOT_WAITING_FOR_PAYMENT`, `PAYMENT_REFERENCE_USED` |
| `rejectPayment($payment_id, $_POST, $admin_id)` | button **Reject** with field `payment_review_note` (5–255 characters, Kiswahili — the customer sees it, e.g. "Hatukupata malipo haya kwenye taarifa yetu.") |
| `getRefundsDue()` | paid orders that were cancelled/expired: `order_id, order_number, order_total, order_payment_method, order_status, order_cancelled_at, order_cancel_reason, user_full_name, user_phone, payment_payer_account` (send the money back there) |
| `markRefunded($order_id, $_POST, $admin_id)` | field `refund_note` (e.g. the M-Pesa code of the refund) → `refunded` |
| `recordPaymentByStaff($order_id, $_POST, $admin_id)` | on the **order page**, for phone orders: fields `payment_payer_account, payment_reference`; saved and confirmed at once. Show the form only when `getOrderForAdmin()['can_record_payment']` is true |

`OrderManager::getOrderForAdmin()` now also returns `payments` (every payment of the order, newest first, with `reviewed_by_admin_name`) and `can_record_payment`. Cancelling an order whose payment is waiting for review answers `PAYMENT_UNDER_REVIEW` — confirm or reject it first.
`Dashboard::getSummaryCounts()` now also returns `payments_awaiting_review` and `refunds_due`.

### `PaymentMethod` — a "Payment methods" tab on payments.php (or in Settings) · `payment_methods.manage`
| Method | Use |
|---|---|
| `listForAdmin()` | the 5 methods: `payment_method_id, payment_method_code, payment_method_name, payment_method_type, payment_method_account_name, payment_method_account_number, payment_method_bank_name, payment_method_instructions, payment_method_is_active, payment_method_sort_order` |
| `getMethodById($id)` / `updateMethod($id, $_POST, $admin_id)` | fields `payment_method_name, payment_method_account_name, payment_method_account_number` (Lipa Namba / phone / bank account), `payment_method_bank_name` (banks), `payment_method_instructions` (Kiswahili, ≤ 500), `payment_method_is_active` (checkbox), `payment_method_sort_order`. Switching on a mobile money / bank method needs the account number and name (and bank name for banks) |
| `setPaymentMethodActive($id, bool, $admin_id)` | the one-click Offer / Stop offering switch (same rule) |

Methods can't be added or deleted (the five codes are fixed); unused ones stay switched off. `Lipa ukipokea` (cash) needs no account.

## App pictures (`AppImage`) — added 2026-10-06 · `settings.manage`

A section **"App pictures"** on settings.php: one card per slot (where it shows, recommended size, current picture or "App's own picture"), with **Upload** (form field `app_image`, `enctype="multipart/form-data"`) and **Use the app's picture** (remove).

| Method | Use |
|---|---|
| `listForAdmin()` | `[{app_image_slot, label, size_advice, app_image_path (null = the app's own; show with url()), updated_at}]` |
| `setImage($slot, $_FILES['app_image'] ?? null, $admin_id)` | upload / replace (JPEG, PNG or WebP, saved as WebP; the old file is deleted) |
| `removeImage($slot, $admin_id)` | back to the picture built into the app |

Both write the audit log. Home's pictures are not here: they come from Categories (category pictures) and Banners.
