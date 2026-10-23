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

## Admin top-bar quick search

`admin/ajax/global_search.php` returns up to five matches per group for orders, customers and products by calling the existing admin search methods. Each group is included only when the signed-in admin has its matching permission (`orders.view`, `customers.view`, or `products.manage`). Search terms must be 2–100 characters; results are private and not cached.

## Remembered admin tabs

The dashboard, offers, payments and settings pages remember the last selected tab in the current browser tab, scoped to the signed-in admin and page. Returning to one of these pages without a `tab` query parameter restores the last tab; an explicit `?tab=` value takes precedence. This uses `sessionStorage` and does not change server-side permissions.

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
`getManualOrderOptions()`, `createManualCustomer($input, $admin_id)`, `createManualOrder($input, $admin_id)` → order id: an order taken by phone or in person (same shop-based sequential order number, timeline, notification, stock reservation and COD limit as app orders — they come from the shared `Order` helpers).
Payment status `cancelled` = a cancelled/expired order that owed nothing more (shown instead of `cod_pending`).

Constant `OrderManager::ALLOWED_TRANSITIONS` = the allowed steps (confirmed → packed → dispatched → in_transit → delivered; cancel before dispatch).

## Receipts — `new Receipt($db)`
`createReceiptForAdmin($order_id)` → `['pdf' => bytes, 'file_name' => 'Risiti-{order_number}.pdf']` for a "Print receipt" button, e.g.
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
| `Settings::getEditableSettings()` | `[group title => [[setting_key, label, setting_value], …]]` — build the form from this (`legal_terms` / `legal_privacy` use the safe rich-text editor, the rest are inputs); General includes the company address printed on receipts |
| `Settings::updateSettings($_POST, $admin_id)` | send **every** field of the form; only changed values are saved; field errors are keyed by `setting_key` |
| `DeliveryMethod::listForAdmin()` | delivery options with `region_name` (null = all regions), fee, days, `delivery_method_is_active`, sort order |
| `DeliveryMethod::getMethodById($id)` / `createMethod($_POST, $admin_id)` / `updateMethod($id, $_POST, $admin_id)` | fields `delivery_method_name, delivery_method_fee, delivery_method_eta_min_days, delivery_method_eta_max_days, region_id` (empty = every region), `delivery_method_is_active` (checkbox), `delivery_method_sort_order`. Fee changes affect new checkouts only |

`payments.php` stays "coming soon" until mobile money (Phase 5).

### Customer broadcasts — page `notification_broadcast.php` · `settings.manage`
- Four editable message starters cover welcome/opening, new products, special news and return visits. The title and message can be changed before sending.
- A broadcast creates one in-app notification and one push-queue entry per active customer in a single transaction. The scheduled worker delivers pushes in batches of 50; failed deliveries appear in `push_queue.php` for staff retry.
- The page confirms the active customer count, previews the message, and records the broadcast in the audit log.

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
The dashboard's "Needs attention" bar shows payments to check and refunds due (`Dashboard::getAttentionCounts()`).

### `PaymentMethod` — a "Payment methods" tab on payments.php (or in Settings) · `payment_methods.manage`
| Method | Use |
|---|---|
| `createMethod($_POST, $admin_id, $_FILES['payment_method_icon']?)` | adds a **mobile money or bank** method (`payment_method_type` required; cash can't be added); may include an optional validated icon; the code orders store is made from the name ("HaloPesa" → `halopesa`) and never changes; audit `payment_method.created` |
| `nameOf($code)` | the customer-facing name for a code (also switched-off ones) — lists, receipts, dashboard |
| `listForAdmin()` | every method: `payment_method_id, payment_method_code, payment_method_name, payment_method_type, payment_method_account_name, payment_method_account_number, payment_method_bank_name, payment_method_instructions, payment_method_icon_path, payment_method_is_active, payment_method_sort_order` |
| `getMethodById($id)` / `updateMethod($id, $_POST, $admin_id)` | fields `payment_method_name, payment_method_account_name, payment_method_account_number` (Lipa Namba / phone / bank account), `payment_method_bank_name` (banks), `payment_method_instructions` (Kiswahili, ≤ 500), `payment_method_is_active` (checkbox), `payment_method_sort_order`. Switching on a mobile money / bank method needs the account number and name (and bank name for banks) |
| `setPaymentMethodActive($id, bool, $admin_id)` | the one-click Offer / Stop offering switch (same rule) |
| `setIcon($id, $_FILES['payment_method_icon'], $admin_id)` / `removeIcon($id, $admin_id)` | uploads a validated square payment logo or restores the clients' built-in icon |

Built: Payments → Payment methods → **Add payment method** (admin/payment_method_edit.php without an id: type, name, optional icon, account, bank, instructions). Methods are never deleted — old orders still name them; switch unused ones off. `Lipa ukipokea` (cash) is built in and needs no account. Migration 014 turned the fixed code lists into text; migration 017 added the optional icon path.

## App pictures (`AppImage`) — added 2026-10-06 · `settings.manage`

A section **"App pictures"** on settings.php: one card per slot (where it shows, recommended size, current picture or "App's own picture"), with **Upload** (form field `app_image`, `enctype="multipart/form-data"`) and **Use the app's picture** (remove).

| Method | Use |
|---|---|
| `listForAdmin()` | `[{app_image_slot, label, size_advice, app_image_path (null = the app's own; show with url()), updated_at}]` |
| `setImage($slot, $_FILES['app_image'] ?? null, $admin_id)` | upload / replace (JPEG, PNG or WebP, saved as WebP; the old file is deleted) |
| `removeImage($slot, $admin_id)` | back to the picture built into the app |

Both write the audit log. Home's pictures are not here: they come from Categories (category pictures) and Banners.

## Offers ("Ofa") — `ProductOffer` — added 2026-10-08 · `products.manage`

A page **offers.php** (menu "Offers", under Products) with tabs Running · Scheduled · Ended, plus an "Offers" box on product_edit.php showing that product's offers and a "New offer" button.

| Method | Use |
|---|---|
| `getOffersForAdmin($_GET)` | filters `status` (`running`/`scheduled`/`ended`), `product_id`, `page`, `per_page` (`ProductOffer::PER_PAGE_OPTIONS` 25/50/100) → `['items', 'total', 'page', 'per_page']`. Items: every `product_offers` column + `product_offer_status, product_name, product_price, product_price_from` (normal prices — show "5,500 → 4,675" with `Pricing::offerPrice($price, $percent)`), `created_by_admin_name`. Times UTC → `localDateTime()` |
| `getOfferById($id)` | one offer + `product_offer_status` · 404 |
| `createOffer($_POST, $admin_id)` | fields `product_id`, `product_offer_percent` (1–90), `product_offer_starts_at` (empty = now), `product_offer_ends_at` — both `<input type="datetime-local">` in Tanzania time; at most 90 days; no overlap with another offer of the same product → new id |
| `updateOffer($id, $_POST, $admin_id)` | same fields except the product; running or scheduled offers only (`OFFER_ENDED` otherwise). For the form, convert the saved UTC times back with `adminDateTime($time, 'Y-m-d\TH:i')` |
| `endOffer($id, $admin_id)` | **End now** (running) / **Cancel** (scheduled). Confirm dialog |

Prices are applied by the backend everywhere (shop, cart, checkout, staff orders, receipts). The audit log is written by the class. `OrderManager::getManualOrderOptions()` products now carry `product_offer_percent` and their `price_tiers` already include the offer. Banners may now target the collection `offers`.

## Business verification — `Customer::setBusinessVerification()` — added 2026-10-09 · `customers.manage`

| Method | Use |
|---|---|
| `setBusinessVerification($user_id, $_POST, $admin_id)` | fields `business_verification_status` (`verified` / `rejected` / `unverified`) and `business_verification_note` (required for `rejected`, Kiswahili, shown to the customer). Sets / clears `business_verified_at` and who verified. Notifies the customer (verified / rejected) and writes the audit log. `NO_BUSINESS` (409) when the customer hasn't filled in business details. |

Built on customer_details.php (Business panel): **Verify business**, **Reject** (with reason), **Set back to not verified**.

## Suspend / reactivate — `Customer::setCustomerStatus()` — added 2026-10-09 · `customers.manage`

| Method | Use |
|---|---|
| `setCustomerStatus($user_id, $_POST, $admin_id)` | fields `user_status` (`suspended` / `active`) and `user_status_reason` (required for `suspended`; staff-only, audit log). Suspending revokes every app token and ends website sessions; login (SMS code, PIN, `/auth/start`) answers "Akaunti yako imesimamishwa". Orders are not changed. `CUSTOMER_DELETED` (409) for deleted accounts. |

Built on customer_details.php: a panel under the header — **Suspend account** (reason) or, when suspended, **Reactivate account**.

## Overdue unpaid orders — added 2026-10-09 · `orders.manage`

| Method | Use |
|---|---|
| `OrderManager::getOrdersForAdmin(['overdue' => 1, …])` | only unpaid mobile money / bank orders past `order_expires_at` with no payment being checked |
| `OrderManager::countOverdueOrders()` | the number for the filter, the button and the dashboard card |
| `OrderManager::expireOverdueOrders($admin_id)` | marks them all **expired** (stock back, timeline "Muda wa kulipa umeisha", customer notified, audit log `orders.expired_overdue`) → how many. `null` = the scheduled job |

Built: Orders page — "Overdue unpaid (N)" filter and **Expire N overdue order(s)** button; dashboard card "Overdue unpaid". The Orders page also has an **Expired orders (N)** shortcut to the existing `order_status=expired` filter, where staff can open an order and use the current reactivation preview if the customer still wants it.
Scheduled job ready for the server: `cron/expire_unpaid_orders.php` (every 15 minutes; refuses to run from a browser, folder blocked).

## Upcountry transport — added 2026-10-09 (blueprint R-25, §12.7)

| Method | Use |
|---|---|
| `DeliveryMethod::createMethod / updateMethod($id, $input, $admin_id)` | now with **Where** `delivery_method_area` (`all` · `only` · `except` + `region_id`) and **Price** `delivery_method_pricing` (`fixed` uses `delivery_method_fee`; `region_estimate` uses the region's estimate) · `settings.manage` |
| `DeliveryMethod::getRegionEstimates()` / `saveRegionEstimates([region_id => amount or ""], $admin_id)` | each region's usual transport price (empty = "tutakujulisha bei"); only changed regions are saved; audit `transport_estimates.updated` · `settings.manage` |
| `OrderManager::confirmDeliveryFee($order_id, ['order_delivery_fee' => 20000, 'order_delivery_fee_note' => '…'], $admin_id)` | staff measured the load: sets the final transport price (a reason is required when it differs from the estimate), recomputes the total, starts the time to pay, tells the customer; audit `order.delivery_fee_confirmed`. Error `DELIVERY_FEE_NOT_WAITING` · `orders.manage` |
| `OrderManager::getOrdersForAdmin(['transport_to_confirm' => 1, …])` / `countTransportToConfirm()` | upcountry orders still waiting for their transport price |

Built: **Settings in tabs** (General · Orders & payments · Delivery · Login (SMS) · App pictures; each tab saves only its own settings — `Settings::updateSettings($input, $admin_id, $group_titles)`). Delivery tab: the options table (Where, Price) and the **Upcountry transport estimates** form (one box per region). Option form: Where + Price. Order page: the orange **Transport price** box (estimate filled in, reason, "Confirm price and let the customer pay"). Orders page: "Transport to confirm (N)" filter and a "Transport price to confirm" line in the Status column. The bell: `delivery.to_confirm` notification and a "transport price(s) to confirm" line in Waiting for you.

## Edit an order — `OrderEditor` — added 2026-10-15 (blueprint R-29, §12.11) · `orders.manage`

| Method | Use |
|---|---|
| `getEditRules($order_id)` | `{can_edit_unpaid, can_edit_address, reason}` — unpaid = awaiting payment with nothing being checked, or cash on delivery before packing; address = until sent |
| `updateItems($id, ['items' => [{product_id, quantity}]], $admin_id)` | unchanged lines keep their price; changed / new lines today's price; stock follows; ≥ 1 line; audit `order.items_changed` |
| `changeDelivery($id, ['delivery_method_id'], $admin_id)` | must reach the order's region; its price rule (Bolt / pickup 0, fixed fee, upcountry estimate → confirm again); audit `order.delivery_changed` |
| `changePaymentMethod($id, ['payment_method'], $admin_id)` | switched-on methods; to cash → confirmed (limit); cash → phone/bank → awaiting payment with a new time to pay |
| `setDiscount($id, ['order_discount_total', 'order_discount_note'], $admin_id)` | ≤ products total, reason required (shown on the receipt, order page and app); 0 removes it |
| `updateAddress($id, [recipient, phone, region, district, street, landmark], $admin_id)` | until sent; the delivery option must still reach the region; audit `order.address_changed` |
| `addStaffNote($id, ['order_staff_note_text'], $admin_id)` / `getStaffNotes($id)` | private notes (table `order_staff_notes`, migration 015) |

**Paid order, not packed (step 2):** `getEditRules()['can_add_to_paid']`; `updateItems()` then also takes `order_delivery_fee` + `order_delivery_fee_note` for an upcountry order's new load, and settles the money: more → payment status `balance_due` ("Salio la kulipa"; packing blocked until paid; `Payment::submitPayment / confirmPayment / rejectPayment / recordPaymentByStaff` handle the balance), less → `order_refund_due` (Payments → Refunds due; `Payment::markRefunded` gives back just that part, notification `refund.due`).

Every change works the total out again (`saveTotals`) and tells the customer ("Oda imebadilishwa … Jumla mpya: TZS …"). Built: order page **Edit order** button and **Staff notes** panel; admin/order_edit.php (products, delivery option, payment method, discount, address — each its own form). Product rows shared with "Add order": admin/assets/order_items.js. `OrderManager::priceLinesToday()` is shared (it counts stock the order already holds).

## Reactivate an expired order — added 2026-10-15 (blueprint R-28, §12.10) · `orders.manage`

| Method | Use |
|---|---|
| `OrderManager::previewReactivation($order_id)` | `{can_reactivate, lines: [{product_name, quantity, old_unit_price, new_unit_price, …}], problems: […], old_total, new_total}` — the order page's preview |
| `OrderManager::reactivateOrder($order_id, ['status_note' => …], $admin_id)` | expired → awaiting payment at today's prices: stock reserved, payment "unpaid", new time to pay, timeline "Imerejeshwa na CHIMBO", customer told the new total; audit `order.reactivated`. Errors `ORDER_NOT_EXPIRED`, `CANNOT_REACTIVATE` |
| `OrderManager::priceLinesToday()` (private) | today's price per line (levels + running offer) with problems listed — shared by "Add order" and reactivation |

Built: order page (expired orders) — **Reactivate order** panel: old → today's price per product, old and new total, the reasons when it can't be done, an optional note. "Add order" now follows the delivery rules too (Bolt paid to the rider, upcountry estimate to confirm, pickup).

Reactivation preview also shows the delivery order's new estimated date. Reactivation recalculates `order_estimated_delivery_date` from the saved `order_delivery_days_max`, and the customer notification includes the estimate (pickup orders omit it).

## Logo — `SiteLogo` — added 2026-10-15 (blueprint R-27, §12.9) · `settings.manage`

| Method | Use |
|---|---|
| `setLogo($_FILES['logo'], $admin_id)` / `setLogoFromFile($path, $admin_id)` | square PNGs 512 / 192 / 180 / 32 px (`ImageUploader::saveSquareIcons()`, a wider picture is centred on a transparent square); old files removed; audit `logo.changed` |
| `removeLogo($admin_id)` | back to the built-in logo; audit `logo.removed` |
| `getUrl('512' \| '192' \| '180' \| '32')` / `getPath(…)` | the uploaded file, or null → the caller uses its built-in one |
| `pdfDataUri($fallback_png)` | the logo inside a PDF (receipt, packing slip) |

Built: Settings → **Logo & pictures** (the tab was "App pictures"): Logo card with preview, upload and "Use the built-in logo". Used by the website header, footer and tab icons (`logoUrl()`, `iconUrl()`), admin tab icons, receipt and packing-slip headers, and `GET /app-images` (slot `logo`) for the app.

## Dar by Bolt and pickup at the shop — added 2026-10-15 (blueprint R-26, §12.8)

| Method / helper | Use |
|---|---|
| `DeliveryMethod` Price `paid_to_rider` · switch `delivery_method_is_pickup` | "Customer pays the rider (not CHIMBO)" — never in the total; "Pickup at the shop" — collected, never sent |
| `Settings::getPickupPoint()` | `{pickup_address, pickup_hours}` (Settings → General → Pickup point) |
| `Settings::getReceiptBusinessDetails()` | `{company_address, support_phone, support_whatsapp}` printed in the receipt footer; company address is managed in Settings → General → Company details |
| `OrderManager::allowedNextStatuses($status, $is_pickup)` | a pickup order goes packed → delivered (or cancelled); never dispatched |
| `Order::deliveryFeeWords($order)` | "Unamlipa dereva" / "Unachukua dukani" / null — receipts, slips, website |
| `adminStatusName($status, $is_pickup)` / `adminStatusBadge(…)` / `adminDeliveryFee($order)` | admin words: "Ready for pickup", "Picked up", "Paid to the rider", "Pickup at the shop" |

Built: Settings → General "Pickup point"; Delivery tab Price column ("Paid to the rider", "Free · pickup"); option form: third price type + "Pickup at the shop" switch. Order page: "Pickup at the shop" panel / Bolt note, pickup words in the badge, timeline and "Move the order". Orders list: "Pickup" under the region. Packing slip: "ANACHUKUA DUKANI — usitume" or "mteja anamlipa dereva (Bolt)"; receipt transport line in words.

## Dashboard sales and low stock — added 2026-10-09 · `dashboard.view`

| Method | Use |
|---|---|
| `Dashboard::getSales()` | `['today' => summary, 'last_7_days' => summary]` — `order_count, revenue, average_order, pieces_sold, delivery_fees, cancelled_count`; Tanzania days, cancelled / expired left out (`Report::getSummaryForDays`, same numbers as Reports) |
| `Dashboard::getLowStock()` | `['items' => up to 10 products with stock ≤ ProductEditor::LOW_STOCK_THRESHOLD (fewest first), 'low_count', 'out_count']` — the Stock page's rule |

Built on index.php: a "Sales" row (revenue only for `reports.view`), the existing cards under "Today's work", and a "Low stock" table.

## Packing slip — `Receipt::createPackingSlip()` — added 2026-10-09 · `orders.view`

| Method | Use |
|---|---|
| `Receipt::createPackingSlip($order_id)` | `['pdf', 'file_name' => 'Kupakia-CHB….pdf']` — order number + QR code (the order number only), customer (name, shop, phone), delivery details and note, items sorted by name with tick boxes (no prices), total pieces, "KUSANYA: TZS …" for unpaid cash on delivery, signature line |

Built: admin/order_packing_slip.php (opens in the browser), "Packing slip" button on the order page and in the Orders list.
QR codes are drawn locally by `chillerlan/php-qrcode` (Composer); nothing is sent to any website. Templates: `classes/views/packing_slip.php`, shared styles `classes/views/partials/pdf_styles.php`.

`OrderLink::createUrl($order_number)` signs the order reference with `APP_KEY` and builds an absolute URL from `APP_URL`. QR links have a database-backed version and can be revoked or reissued from order details by staff with `orders.manage`; reissuing changes the signature, so previously printed codes stay invalid. `findOrderForCode($code)` checks the active version before returning order data; `getPublicItems($code)` returns only snapshotted product names, quantities and unit labels. The public page exposes no prices, cash amount, customer name, phone or address; staff access is checked separately under `/admin/` so the admin session cookie remains path-scoped.

## Staff notifications (the bell) — `AdminNotification` — added 2026-10-09

The bell in the top bar (header.php) shows unread notifications (badge refreshed every minute without counting as activity, so the 30-minute idle logout still works). Clicking it opens a dialog: **Waiting for you** (`adminAttentionItems()`, the old dashboard "Needs attention") + the **10 latest** notifications (long text → "Read more"), **Mark all as read**, **View all** (admin/notifications.php, paged, "Unread only").

| Method | Use |
|---|---|
| `notify($type, $title, $body, $link)` | saves a notification; `$type` from `AdminNotification::TYPES` decides which staff see it (e.g. `payment.submitted` → `payments.manage`); `$link` = an admin page like `order_details.php?id=12` |
| `getLatest($admin, 10)` / `search($admin, $filters)` / `countUnread($admin)` | only notifications for the staff member's role; `is_read` is per staff member |
| `open($admin, $id)` | marks it read and returns the page to open (admin/notification_open.php) |
| `markAllRead($admin)` | "Mark all as read" |
| `AdminNotification::safeLink($link)` | only pages inside admin/ can be opened; anything else → the dashboard |

Created automatically: new order (`order.placed`), customer cancelled (`order.cancelled`, plus `refund.due` when it was paid), "Nimelipa" (`payment.submitted`), stock drops to low or sold out (`stock.low`, once per drop), a new customer finished registering (`customer.registered`), the scheduled job expired unpaid orders (`orders.expired`). Read state: `admin_notification_reads` (migration 011).
