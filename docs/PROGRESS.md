# CHIMBO — Progress Log

Update this file at the end of every work session. Newest entry on top.

## Current status
- **Phase:** 0 — Foundation (steps 1–7 done; step 8 = Flutter app setup, started in a separate session)
- **Next step (mobile session):** notifications (bell unread count on Home, list, mark read → open the order), then a global 401 handler. **Live on the API:** catalog, login, profile, regions, wishlist, cart, addresses, checkout, orders (Oda). No dev repositories left in the app.
- **Next step (backend session):** Oda backend is DONE (cash on delivery). Next: payments (mobile money via an aggregator — needs the provider choice D-10) + expire-unpaid-orders cron, receipt PDF, staging deployment. The MVP can already run end to end with cash on delivery.
- **Uncommitted work:** steps 5 onward (user commits when they choose)
- **GitHub:** https://github.com/Edgar-Charz/CHIMBO (backend repo, branch `main`)
- **Waiting on the user:** answers to D-2, D-3, D-4, D-16 (blueprint §23) · start payment aggregator + SMS provider applications (D-10)

## Decisions made
| Date | Decision | 
|---|---|
| 2026-09-28 | Stack: Flutter · PHP 8.2 OOP (SCMRS style) · REST JSON · MySQL · PHP + Bootstrap admin |
| 2026-09-28 | Backend + admin + web storefront + docs in `C:\xampp\htdocs\chimbo\`; Flutter app in `C:\Users\edgar\AndroidStudioProjects\chimbo\` |
| 2026-09-28 | Web storefront = PHP pages + Bootstrap + plain JavaScript/AJAX calling the same API |
| 2026-09-28 | **Mobile app first**; web storefront in Phase 7 |
| 2026-09-28 | Web storefront started early in its own session (design system + layout first, APIs connected as they are built) |
| 2026-09-28 | **Login timing (D-6):** web = guest browsing + guest cart, login at checkout, cart merged after login; app = PDF flow (registration right after onboarding) |
| 2026-09-28 | App count badges (cart) are orange as in the design, not red (§6.3 red stays for errors/logout) |
| 2026-09-28 | D-4: Wasifu follows **PDF p.11** (the more modern design) |
| 2026-09-28 | App language: Kiswahili only for now; English kept as a placeholder (shown as "Inakuja" in Mipangilio) |

## Session log

### 2026-09-30 — Fixes requested by the admin session (backend session)
- **"Add order" 500 error:** `OrderManager::createManualOrder()` (added by the admin session) called `checkCashOnDeliveryLimit()` / `newOrderNumber()`, which were private in `Order`. Now `Order::newOrderNumber()`, `checkCashOnDeliveryLimit()`, `recordStatus()` and `notifyCustomer()` are public and the manual order uses them (no copied timeline/notification code; the notification now matches app orders).
- **Cancelled COD orders kept `cod_pending`:** `Order::moveToStatus()` now sets `order_payment_status = 'cancelled'` when an unpaid/pending/cod_pending order is cancelled or expired (paid orders stay `paid` until refunded). Migration `005_order_payment_cancelled.sql` adds the value and fixed existing orders (CHB765068).
- **Admin order list sorting:** `getOrdersForAdmin()` accepts `sort` (order_placed_at, order_total, order_number via `SORT_COLUMNS`) + `direction`; payment-status filter accepts `cancelled`.
- Tests: 3 new in `OrderTest` (cancelled COD payment, staff-created order, sorting) — **138 passing**. docs: ADMIN_CLASSES, API_REFERENCE (`order_payment_status` values).
- Reminder (CLAUDE.md): classes belong to the backend session — the admin session should ask for class changes instead of editing `OrderManager` directly.

### 2026-09-30 — Admin: order pages cleaned up to the coding standards (admin session)
- The order pages another session added to `admin/` were reworked (same behaviour, standard structure): `orders.php` = list only (filter card + server-side table); new `order_details.php` (items with thumbnails, totals, timeline newest first, customer link, delivery, payment; "Move the order" and "Confirm cash" use `adminHandleForm`, each action checks its permission; the delivery-agent field shows only for "Dispatched" via a reusable `data-show-when` in `admin.js`); `order_create.php` readable, product row in `includes/order_item_row.php`, its 370-line inline script moved to `admin/assets/order_create.js` (settings via data attributes; pages load their own script with `$page_scripts`); delivery agent pages reformatted.
- Raw SQL in `ajax/order_customers.php` moved into `Customer::findCustomersForOrder()` (reuses the name/shop/phone search and `Address::getAddresses()`).
- Status pills: order and payment statuses now have colours (new blue "info" tone) and readable names ("Awaiting payment", "Awaiting cash", "On the way"); payment methods shown as "M-Pesa", "Cash on delivery" …
- Tested end to end with a temporary admin, customer, agent and test orders (all removed): pages, validation messages, confirmed → packed → dispatched (agent required) → delivered → cash confirmed (wrong amount refused), Finance cannot move orders.
- Backend bugs reported the same day and fixed by the backend session: "Add order" 500 error, cancelled orders keeping "cod_pending" (new payment status `cancelled`, red pill + filter option), order sorting. Orders table now sorts by number, total and placed date (newest first by default). Re-tested end to end: Add order → stock reserved → cancel → stock and sold count restored, payment "Cancelled" (test data removed).

### 2026-09-30 — Oda on the API + ngrok header (mobile session)
- Every API request and every network image sends `ngrok-skip-browser-warning: true` (`Env.tunnelHeaders`, used by `ApiHeadersInterceptor` and `AppNetworkImage`), so the tester APK works through the ngrok tunnel.
- Orders use the real API (`ApiOrdersRepository`): place order, Oda Zangu tabs, tracking (timeline, delivery agent), cancel, Agiza Tena. `Order.fromJson` reads the documented order shape; `can_cancel` comes from the server.
- `Idempotency-Key`: one per checkout (`CheckoutSelection.orderKey`, `core/utils/idempotency_key.dart`), kept when retrying, new after an order is placed, so a retry after a timeout can never create a second order.
- Agiza Tena shows the server's `skipped_items` messages instead of the plain success toast.
- `DevOrdersRepository` and `DevCustomerStore` moved to `test/helpers/` as `FakeOrdersRepository` / `FakeCustomerStore`; tests stay offline.
- Payment status `cancelled`: the app goes by `order_status` only, so nothing to parse. Expired orders now show the same timeline as cancelled ones (reached steps, then how it ended) via `OrderStatus.isStopped`.
- Verified: format + analyze clean, **67 tests pass** (new: `api_orders_repository_test.dart`, `order_timeline_test.dart`). APK built by the user.

### 2026-09-29 — Testers via ngrok tunnel (backend session)
- **Tunnel address:** `https://unusual-handbook-breeding.ngrok-free.dev` (free static ngrok domain). API for the tester APK: `https://unusual-handbook-breeding.ngrok-free.dev/chimbo/api/v1`. Admin: `…/chimbo/admin/`.
- Start it with: `ngrok http 8080 --url=https://unusual-handbook-breeding.ngrok-free.dev` (only works while the PC, XAMPP and that window are running).
- **Safety:** ngrok points at a new Apache door on **port 8080** (block added to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, backup `.bak-chimbo-2026-09-29`) that only allows `/chimbo` — phpMyAdmin, the XAMPP dashboard and other projects (SCMRS …) return 403. Port 80 unchanged.
- `.env`: `APP_TRUSTED_HOSTS=unusual-handbook-breeding.ngrok-free.dev`, `APP_DEBUG=false` while shared. `isHttpsRequest()` now trusts `X-Forwarded-Proto: https` only for APP_TRUSTED_HOSTS → links through the tunnel are `https://`. 2 tests — **135 passing**.
- Verified through the tunnel: health 200, OTP login, photo URLs use the tunnel address and load; phpMyAdmin/dashboard/scmrs/.env/classes/docs → 403. Browsers without the `ngrok-skip-browser-warning` header get ngrok's warning page; the Flutter app (Dart user agent) gets JSON, but the app should still send that header.

### 2026-09-29 — Oda tab backend done (backend session)
- `database/migrations/004_orders.sql`: delivery_agents, orders (prices, delivery method and address **copied** at ordering time; idempotency key; expiry for unpaid orders), order_items, order_status_history (timeline), order_deliveries (agent, cash collected), notifications.
- `Order` (customer): `placeOrder()` in ONE transaction — lock cart products (id order), recalc with `Checkout::calculateTotals()` (the Hakiki maths), refuse `PRICE_CHANGED` if the total differs, COD limit (setting), copy address/prices, reserve stock via `ProductEditor::changeStock()` (movement reason `order_reserve`), sold count, empty cart, timeline row, notification; `Idempotency-Key` → same order on repeats. Also getOrders (active/delivered/all), getOrder (items + events + agent), cancelOrder (before packing, stock returns), reorder (via new `Cart::addItemsFromList()`, shared with the guest-cart merge), getRecentlyOrderedProducts, and the shared `moveToStatus()`.
- `OrderManager` (staff): list/detail with `allowed_next_statuses`, `changeStatus()` with the allowed steps (dispatch needs an agent), `confirmCashCollected()` (must equal the total → paid). `DeliveryAgent` CRUD + photo. `Notification` (bell: list, unread count, mark read).
- Home: `/home` now uses new `OptionalAuthMiddleware` and fills `recently_ordered` for logged-in customers. Account deletion also clears notifications (orders are kept for the records).
- API: `api/endpoints/orders.php`, `notifications.php`. docs: API_REFERENCE (Orders, Notifications), ADMIN_CLASSES (OrderManager, DeliveryAgent).
- Tests: shared `resetCustomerData()` in tests/bootstrap.php; `tests/Integration/OrderTest.php` (14) — **133 passing**. 12 live checks (PRICE_CHANGED, idempotency, stock 180→172→180 on cancel, bell, recently ordered, 404 for others' orders).

### 2026-09-29 — App: Kikapu, addresses and checkout on the real API (mobile session)
- `ApiCartRepository` (`GET/DELETE /cart`, `POST /cart/items`, `PATCH`/`DELETE /cart/items/{id}` — every call returns the priced cart), `ApiAddressRepository` (`GET/POST /addresses`), `ApiCheckoutRepository` (`GET /checkout/options?address_id=`, `POST /checkout/preview {delivery_method_id, address_id}`).
- Cart model: `line_savings`, `line_problem` (`CartLineProblem`: not_enough_stock / out_of_stock / below_moq) + `available_quantity`, `summary.savings`, `summary.can_checkout`, `warnings`.
- Kikapu UI: red problem note on a line with a one-tap fix (Punguza hadi N / Ondoa / Ongeza hadi MOQ); stepper stops at `available_quantity`; "Endelea kwenye Malipo" disabled with a reason while `can_checkout` is false; "Unaokoa" row; `warnings` shown once as a toast.
- Errors: server text as-is — `VALIDATION_ERROR` shows the field message (MOQ text is in `fields.quantity`), `OUT_OF_STOCK` its message.
- Checkout state: `checkoutAddressProvider` → `checkoutOptionsProvider` (per address; Haraka only where the server offers it) → `checkoutChoicesProvider` (chosen or first available delivery and payment method — only `cod` now; default is no longer M-Pesa). Delivery names come from the server ("Standard (siku 2–3)") — no longer re-appended.
- Dev orders now read delivery methods from `CheckoutRepository` and store the method in the dev order. Old dev cart/address/checkout moved to `test/helpers/` as fakes.
- Also fixed: cart line stepper row overflowed by 12 px on 360-dp phones (Wrap).
- Verified: analyze clean; 61 tests pass (new: cart / address / checkout API repositories incl. real error replies). Live check against the local API: login → below-MOQ `VALIDATION_ERROR`, `OUT_OF_STOCK`, add → priced cart, address (201), options, preview, `CART_EMPTY`; test account deleted. Device check left to the user.

### 2026-09-29 — App: login, profile, regions and wishlist on the real API (mobile session)
- **User decision:** switch login too (the wishlist needs a real Bearer token).
- `ApiAuthRepository` (`/auth/otp/request`, `/auth/otp/verify` with `client: app` + platform → token in secure storage, `/auth/profile`, `/auth/me` on start-up, `/auth/logout`, `DELETE /me`), `ApiProfileRepository` (`/me`, edits via `POST /auth/profile`), `ApiLocationRepository` (`/regions`, `/regions/{id}/districts` — district replies get `region_id` from the URL), `ApiWishlistRepository` (`/wishlist/ids`, `/wishlist`, `POST /wishlist`, `DELETE /wishlist/{id}`).
- Models aligned with the API: `OtpRequestResult` (`otp_*_seconds`, `debug_otp_code`), `Profile` = flat profile object, step 3 sends `user_full_name`.
- OTP screen: in "show code" mode the code from the server is filled in, with a small "Hali ya majaribio" hint (never in production).
- Splash: if checking the saved login fails (no network) → error + "Jaribu tena" instead of hanging; an expired token → phone screen.
- Cart, addresses, orders stay dev but now store per logged-in **user id** (`core/storage/dev_customer_store.dart`); dev addresses get region names from the locations API; sample orders get the name from the profile API.
- Dev login/profile/regions/wishlist moved to `test/helpers/` as fakes (`FakeAuthRepository`, `FakeAccountStore`, …); `pumpApp` overrides every server repository, so tests never call the network.
- Also fixed (from outside edits to the category page): filter pills overflowed by 23 px on 360-dp phones → labels now shorten with "…"; one test updated for the new rails.
- Verified: analyze clean; 59 tests pass (new: API auth, wishlist, locations, profile repositories). Live check against the local API: OTP (show-code) → verify → step 3 → `/auth/me` → wishlist add/ids/list/remove → delete account (test account removed). Device check left to the user.

### 2026-09-29 — Kikapu tab backend done (backend session)
- `classes/core/Pricing.php`: tier maths (tierForQuantity, nextTierHint, priceLine with savings) — the only place a unit price is chosen. `tests/Unit/PricingTest.php` checks every Vaseline boundary.
- `Cart`: server-priced cart grouped by top category; add (adds to existing), set quantity, remove, clear, guest-cart merge (web, bigger quantity wins, skipped items reported); MOQ hard minimum, never above stock (409 OUT_OF_STOCK), max 100 lines; products that left the shop are removed with a warning; `line_problem` + `summary.can_checkout` when stock/MOQ changed later. `Product::getPriceTiersForProducts()`.
- `Address`: CRUD + default (first = default, deleting the default promotes the newest), region/district check now shared in `Region::checkLocation()` (moved out of User).
- `Checkout`: `getOptions` (delivery methods by region; payment methods from the new setting `enabled_payment_methods` = `cod` for now), `preview`, and `calculateTotals()` — the single place the order total is computed (order creation will reuse it).
- API files: `cart.php`, `addresses.php`, `checkout.php` (JSON matches the app's models). 11 live checks.
- Tests: `PricingTest` (11) + `ShoppingTest` (15) — **119 passing**.

### 2026-09-29 — Admin product list: sorting + page size (backend session, requested by admin session)
- `ProductEditor::getProductsForAdmin()`: `per_page` (10/25/50/100, default 25, `PER_PAGE_OPTIONS`), `sort` (product_name, product_price, product_stock_quantity, created_at via `SORT_COLUMNS`), `direction` (asc/desc, default desc), tie-breaker product_id; no sort = newest first. docs/ADMIN_CLASSES.md updated. 3 tests — **93 passing**.
- Scale note: price sorting is computed from the tiers per product — fine for thousands of products; add a stored price column/index if the catalog grows to tens of thousands.

### 2026-09-29 — Gundua tab backend done: wishlist (backend session)
- `database/migrations/003_shopping.sql`: wishlist_items, cart_items (no prices stored — always recalculated from tiers), addresses, delivery_methods (`region_id` NULL = all regions). Seed `05_delivery_methods.php` (runs in production too): Standard 5,000 (siku 2–3, all regions), Haraka 10,000 (kesho, Dar es Salaam only).
- `Wishlist` class + `api/endpoints/wishlist.php`: `GET /wishlist`, `GET /wishlist/ids`, `POST /wishlist`, `DELETE /wishlist/{product_id}`. `Product::getProductCardsByIds()` (cards in the given order).
- `User::deleteAccount()` now also clears wishlist, cart and addresses.
- Search suggestions skipped (the app doesn't call them).
- Tests: `tests/Integration/WishlistTest.php` (6) — **90 passing**; 6 live checks.

### 2026-09-29 — Links use the request's own address (backend session)
- Requested by the mobile session: image URLs used APP_URL (localhost), which the emulator can't open.
- `url()` now builds links from the request's scheme + host (`baseUrl()`), **only for trusted hosts**: APP_URL's host, `APP_TRUSTED_HOSTS` (new .env setting), and on APP_ENV=local also localhost + private IPs (10.0.2.2, 192.168.x.x). Anything else, and CLI scripts, fall back to APP_URL (blocks Host-header tricks). The folder part (/chimbo) always comes from APP_URL.
- Shared `isHttpsRequest()` (Session uses it too).
- Tests: `tests/Unit/BaseUrlTest.php` (9) — **84 passing**. Live: emulator, phone and PC each got their own address; a fake host got APP_URL.

### 2026-09-29 — Admin: customers + catalog pages (admin session)
- Customers (Phase 1 step 13): `admin/customers.php` (search name/phone/shop, status, verification and region filters, pages) and `customer_details.php` (account, business, orders placeholder). Class `Customer` (staff view of customers).
- Sidebar: every module from blueprint §14, grouped (Sales, Catalog, Management); unbuilt ones show a "coming soon" page (`includes/coming_soon.php`). Operations role got `orders.view`. Fixed the sidebar background (Bootstrap made `.offcanvas-lg` transparent).
- Catalog pages (Phase 2 step 16), using the backend session's classes (`docs/ADMIN_CLASSES.md`): categories (tree list, edit, picture, delete), sellers (list with one-click verified toggle, edit), products (list with search/category/seller/status/stock filters + pages; edit page with details, tier editor, status/bestseller/"new until", deal price, opening stock, photos: upload, move, main, delete), stock (All/Low/Sold out list; adjust with reason + note, history), banners (list with Live/Scheduled/Ended/Hidden, edit with Tanzania-time schedule, picture, delete).
- Shared admin pieces: `includes/admin_helpers.php` (form handling `adminHandleForm`, field errors, list URLs, dates in Tanzania time, status pills, thumbnails, money), `pagination.php`, `form_alert.php`, `admin/assets/admin.js` (confirm, table search, auto-submit filters, tier rows).
- Tables are DataTables 2 (jQuery + DataTables saved in `admin/assets/vendor/datatables/`): Customers, Products, Stock load pages from `admin/ajax/*.php` (server-side, filters beside the search box); Categories, Sellers, Banners, stock history are in-page tables. `AdminSession::requireAjaxLogin()` answers JSON 401/403. CSS/JS links carry `?v=<file time>` so browsers never use an old cached file. Product flags: orange ★ bestseller / tag = deal icons (tooltips). Table cards: title + search on top, "Show per page" + info + pages below, filter card above. Sorting: every column on Customers and the in-page tables; Products (name, price, stock, added) and Stock (name, in stock — lowest first by default) sort on the server via the backend's new `getProductsForAdmin()` sort/per_page. Sticky top bar.
- Tested with curl as a temporary admin (removed after): every page, validation messages under fields, uploads (good, fake, too small, missing), photo order/main/delete, stock below zero, banner UTC round trip, audit rows, 403 for other roles. Test data removed. 75 tests pass.

### 2026-09-29 — Staff-side catalog classes for the admin pages (backend session)
- Answer to the admin session: no separate "featured" column — `product_is_bestseller` = featured, `product_compare_at_price` = deal (fine for the MVP).
- `Category` (+ createCategory, updateCategory, setCategoryImage, deleteCategory, getAllCategoriesForAdmin, getTopCategories; two levels only), new `Seller`, new `ProductEditor` (admin list with filters, create/update with **tier editor rules**: first level = MOQ, bigger quantity → lower price, ≤ 10; auto SKU; `adjustStock` + `changeStock` with row lock and movement history, never below zero; soft delete), `Banner` (+ CRUD, image, times typed in Tanzania time saved as UTC).
- Helpers: `Slug::unique()`, `localTimeToUtc()`, Validator rules `date` and `datetime`.
- **docs/ADMIN_CLASSES.md** — methods and exact form field names for the admin session.
- Tests: `tests/Integration/CatalogAdminTest.php` (14) — **75 passing**.

### 2026-09-29 — App: catalog switched to the real API (mobile session)
- `ApiCatalogRepository` replaces the dev catalog: `GET /home`, `/categories`, `/products` (all `ProductQuery` params + `per_page`), `/products/{id}`. The app's models already used the API keys — no model changes needed (verified against live replies).
- The dev cart, wishlist and sample order history now read products through `CatalogRepository` (no more sample ids), so Kikapu, Vipendwa and Oda work with the real products until their own endpoints exist.
- Sample catalog moved to `test/helpers/` as `FakeCatalogRepository` (tests never call the network); shared `test/helpers/fake_http.dart` fake server.
- Product photos: `ProductPhoto` (thumb / medium / large from `gallery`; falls back to `images`) — big photo = medium, thumbnail strip = thumb, zoom = large.
- Not used yet: `GET /products/{id}/related`, `category_image_url`, `banners`.
- Verified: analyze clean; 54 tests pass (new: `ApiCatalogRepository` parses real `/products`, `/home`, `/categories`, `/products/{id}` replies and sends the right query). Device check left to the user.

### 2026-09-29 — Product photos + product page API (backend session)
- `classes/core/ImageUploader.php`: checks real type (finfo), size (≤ 8 MB), dimensions (200–6000 px), re-draws with GD (removes hidden data), fixes sideways phone photos (EXIF), saves **3 WebP sizes** (thumb 300, medium 800, large 1600) with random names in `media/{folder}/`. Files on disk, **paths in the database**.
- `classes/ProductImage.php` (for the admin product page): `getImages`, `addImage` ($_FILES), `addImageFile` (server file), `setMainImage`, `reorderImages`, `deleteImage` (removes files too; next photo becomes main), max 8 photos, first photo = main, audit-logged.
- `Product::getProductById()` (card + brand, description, chip + parent category, stock, delivery days, tiers, `images` in order, `gallery` with all sizes), `getRelatedProducts()`, `getPriceTiers()`.
- API: `GET /products/{id}`, `GET /products/{id}/related`.
- Tests: `tests/Integration/ProductPhotoTest.php` (6) — **61 passing**. Live check: 3 photos added, reordered, main changed, fake image rejected, URLs load; test photos removed afterwards.

### 2026-09-29 — Nyumbani tab backend (backend session)
- **Decision:** MVP is built tab by tab (Nyumbani → Gundua → Kikapu → Oda → Wasifu). **All migrations and domain classes now belong to the backend session**; the admin session only builds admin pages (CLAUDE.md updated — tell the admin session).
- `database/migrations/002_catalog.sql`: sellers, categories (2 levels via `parent_category_id`), products, product_price_tiers (`tier_*`), product_images (3 WebP sizes), inventory_movements (`movement_*`), banners.
- `database/seeds/04_demo_catalog.php` (not in production): 2 verified sellers, Cosmetics + Jewelry with their chips, the 12 PDF products with MOQs and tier prices, the "BEI ZA JUMLA" banner. No images yet (app shows placeholders).
- Classes: `Product` (listing with filters/search/collections/sort/pagination, product card format, badge rule deal → bestseller → new), `Category` (tree), `Banner`, `Home`. Helper `slugify()`, `Request::allQuery()`.
- API (`api/endpoints/catalog.php`): `GET /home`, `GET /categories`, `GET /categories/{id}`, `GET /products` — JSON matches the mobile app's models (`product_price_from`, `tier_*`, `q` for search).
- Tests: `tests/Integration/CatalogTest.php` (8) — **55 passing**; 10 live curl checks.

### 2026-09-29 — Phase 1 step 12: profile API (backend session)
- `GET /me`, `PATCH /me` (name, email, language — partial), `PATCH /me/business`, `DELETE /me` (confirm; anonymises to `deleted-<id>`, removes business profile, revokes all tokens, audit log; phone can register again). `User` refactored: shared `BUSINESS_RULES`, `saveBusiness()`, `checkEmailIsFree()`.
- Tests: `tests/Integration/ProfileTest.php` (5) — **47 tests passing**; 7 live curl checks.
- New learning guide: `docs/HOW_THE_BACKEND_WORKS.md` (one request traced file by file). The user wants every backend step explained this way.
- Avatar upload postponed until `ImageUploader` exists (admin session builds it).

### 2026-09-29 — Beta "show code" login mode (backend session)
- New `.env` switch `OTP_SHOW_CODE=true`: the API returns `debug_otp_code` so the app can log in without real SMS — works on local **and staging** (beta APKs), **always off when `APP_ENV=production`** (or APP_ENV missing). Replaces the old "local + SMS_DRIVER=log" rule (`CustomerAuth::shouldShowCodeInResponse()`).
- Use only with test data: while on, anyone who knows a phone number can log in as that user. Staging data must be wiped before launch.
- Tests: 3 new (shown on staging, never in production, off when disabled) — **42 tests passing**.
- **Still needed for beta APKs:** a staging server reachable from the internet (PHP 8.2 + MySQL + HTTPS, not InfinityFree) — user to decide hosting; mobile session must switch to the real login API and build with `--dart-define=API_BASE_URL=https://<staging>/api/v1`. Start SMS provider registration for launch.

### 2026-09-28 — Phase 1 backend: customer login API (backend session)
- **Endpoints (see docs/API_REFERENCE.md):** `POST /auth/otp/request`, `POST /auth/otp/verify` (client app → token, web → session + CSRF), `POST /auth/profile`, `GET /auth/me`, `POST /auth/logout`, `GET /auth/csrf`, `GET /regions`, `GET /regions/{id}/districts`.
- **Classes:** `CustomerAuth` (login steps), `Otp` (HMAC-hashed codes, 5-min expiry, single use, 5 wrong tries, 60-s resend wait, 5/hour per phone, new code cancels old), `AuthToken` (SHA-256 hashed, 60 days sliding, revoke), `User` (find-or-create by phone, profile + business, region/district check), `Region`, `Settings`, `CustomerSession` (web, SameSite=Lax); helpers `RateLimiter`, `AuthMiddleware` (Bearer token or session + X-CSRF-Token); SMS: `SmsGateway` interface, `LogSmsGateway`, `SmsSender` (records in sms_outbox, OTP stored masked).
- **Development without real SMS:** codes go to `storage/logs/sms-YYYY-MM-DD.log` and are returned as `debug_otp_code` only when APP_ENV=local + SMS_DRIVER=log (test proves it never appears otherwise).
- Core changes: `Env` (real env vars win; `Env::set` for tests), `Session::start(..., $same_site)`, `Csrf::verifyOrThrow()`, `isoDate()` helper.
- **Tests:** separate `chimbo_test` database rebuilt each run (`tests/bootstrap.php`, refuses the real DB); `tests/Integration/CustomerLoginTest.php` (11 tests). Total **40 tests passing**. Curl-tested app flow (13 checks) and web flow (7 checks).
- Real DB now has 2 test customers from curl (+255712000111 "Joyce Joseph", +255754222333) — fine as demo data.

### 2026-09-28 — Phases 3–4 (Kikapu, checkout, orders; dev data) — mobile session
- **User decision:** build cart + orders screens on dev data now.
- Kikapu (PDF p.7) `CartScreen`: lines grouped by category ("Bei ya jumla"), "6+ pcs @ TZS 5,000", stepper (MOQ min), line total, "MOQ imetimia", Hariri mode (remove), nudge banner "Ongeza pcs X za … upate TZS Y kila moja" (D-7: per-item nudges, no cart discount → no "Punguzo" line), summary, Endelea kwenye Malipo. Empty/error states. Live Kikapu badge (`cartCountProvider`) on the tab bar and listing app bar.
- Quick-add sheet on the card (+); product page "Ongeza Kikapuni" really adds (shared `addToCart` → snackbar "Imeongezwa kikapuni · Ona Kikapu", or the MOQ/stock reason).
- Checkout (PDF pp.7–8) inside the Kikapu tab: `CheckoutScreen` (numbered stepper Anwani → Usafirishaji → Malipo → Hakiki, address card + Badilisha picker / Ongeza anwani, Standard TZS 5,000 (siku 2–3) / Haraka TZS 10,000 (kesho) per D-9, M-Pesa · Airtel Money · Mixx by Yas · Benki · Lipa ukipokea, totals) → `ReviewScreen` (Hakiki Oda, Thibitisha Oda, "Malipo yako ni salama") → full-screen `OrderSuccessScreen` (check-mark pop, #CHB number, total, ETA, Fuatilia Oda / Rudi Nyumbani). `AddressFormScreen` pre-fills name/phone/region from the profile. Payment itself is Phase 5 — for now every order is placed as confirmed.
- Oda (PDF p.9): `OrdersScreen` tabs Zinazoendelea / Zimefika / Zote (active card: status, ETA, Fuatilia Oda; delivered: date, thumbnails, total, Agiza Tena, Pakua Risiti → coming soon). `OrderTrackingScreen`: status banner ("Njiani — Oda yako inakuja."), timeline with dates, ETA, delivery agent with call / SMS (url_launcher + tel/smsto queries in the manifest), address, items, totals, Ghairi oda (before packing, confirm) and Agiza Tena. Live map deferred (D-15).
- Dev behaviour: cart priced with `TierPricing` (moved to `catalog/data/`), MOQ/stock enforced; a new order advances by itself (packed 2 min, dispatched 4, in transit 6, delivered 10) so tracking can be tried; first visit seeds 2 delivered sample orders.
- Shared: `SectionCard`, `TotalsCard`, `showConfirmDialog` reuse, `AppDates.formatDate/formatDayTime`. Tab placeholder screen removed (all 5 tabs are real).
- Also this session: product card shows only the green tick beside the MOQ; "Muuzaji Aliyethibitishwa" moved to the category header; badge = white pill top-left (user request with screenshots).
- Pictures: `assets/images/orders/success.png` (16:10).
- Later the same day (user feedback): sticky Home header; top slide-down `AppToast` (success / error / info, auto-hide 3–4 s, swipe up) replaces every snackbar; checkout steps joined by a line; "Uliagiza Hivi Karibuni" = picture-only tiles with a (+) re-add; modernised `ProductCard`: heart (wishlist) on the picture, one badge with an icon or a red "-17%" on deals with the old price struck through (`product_compare_at_price`), green "kuanzia TZS …" best tier price (`product_price_from`). Ratings and on-card steppers left out on purpose (D-15, clutter). Wishlist = `WishlistRepository` (+ `getSavedProducts` = `GET /wishlist`) + dev version (saved ids per customer) + `WishlistController`; **Vipendwa** screen (`/profile/wishlist`, Wasifu menu row, grid of saved cards, empty state, unlike removes at once). **Out of stock**: `product_in_stock` on cards → faded picture + "Imeisha" pill, (+) disabled; product page stock "Imeisha" (red) and a disabled "Imeisha stock" button (also in the quick-add sheet); listings put sold-out products last. Sample: Nourish Body Oil and Pearl Hair Clips have 0 stock.
- Verified: analyze clean; 44 tests pass (new: dev cart pricing/MOQ/stock, full product → cart → address → review → order → tracking → cancel flow, Zimefika + Agiza Tena). Device check left to the user.

### 2026-09-28 — Phase 2, step 18 (catalog screens, sample data) — mobile session
- **User decision:** build the Phase 2 screens on sample data first; the backend catalog API comes later.
- Home (PDF p.3): inline logo + bell, `GreetingCard` (time-based Kiswahili greeting, initials, slogan, search → Gundua), Cosmetics/Jewelry `CategoryCard`s (→ category listing in the Gundua tab), quick actions (Ofa / Bidhaa Mpya / Zinazouzwa Sana → `/discover?collection=…`, Agiza Tena → Oda), `WholesaleBanner`, rails "Uliagiza Hivi Karibuni" + "Zinazouzwa Sana". One `homeDataProvider` (= one `/home` request).
- Listing (PDF pp.4–5) `ProductListScreen` for Gundua, categories and collections: search (400 ms debounce), sub-category chips (Zote + children, wrapping), Chuja · Panga · Bei · MOQ sheets, 2-column grid with skeleton / empty / error states, pull-to-refresh, infinite scroll (pages of 20). `ProductQueryController` (chip, search, sort, filters) + `ProductListController` (watches the query, `loadMore`).
- `ProductCard` (one card everywhere, I-7): square image, one badge, 2-line name, price at MOQ (as in the design), MOQ, round (+), verified seller. Grid/rail rows size to the tallest card (no fixed height → no overflow with long names or large text).
- Product page (PDF p.6) `ProductDetailsScreen`: gallery (swipe, thumbnails, pinch-zoom when photos exist), name + seller + verified, MOQ/Stock/Uwasilishaji strip, `TierTable` (current tier highlighted), `QuantityStepper` (MOQ…stock), live "Unaokoa TZS …" + next-tier hint ("Ongeza pcs 16 upate TZS 4,700 kila moja"), sticky Uliza Muuzaji / Ongeza Kikapuni bar. Route `/product/:id` full screen.
- `TierPricing` (pure, tested): tier for quantity, savings vs MOQ price, next-tier hint, ranges — a preview only; binding prices will come from the server (§8.3).
- Data: models `Category`, `PriceTier`, `ProductSummary` (+ `ProductBadge`), `ProductDetails`, `ProductQuery` (+ collection / sort / price & MOQ bands, `toQueryParameters` for `GET /products`), `ProductPage`, `HomeData`. JSON keys follow the column-name convention (`product_id`, `product_moq`, `tier_min_quantity` …) — confirm against the backend's `002_catalog.sql` when it exists.
- `DevCatalogRepository`: 22 sample products (PDF + extras), filtering/sorting/pagination like the server, 400 ms fake delay so loading states show.
- Shared: `AppNetworkImage` (cached_network_image), `ImagePlaceholder` (extracted from `ImageSlot`), `QuantityStepper`, `InitialsAvatar` (moved to core), `AppLogoInline`, chip theme (selected = green).
- Pictures to add: `assets/images/home/{cosmetics,jewelry,wholesale_banner}.png` (see README).
- Deferred on purpose: camera search & ratings display (D-15), wishlist/share/cart/"Uliza Muuzaji" buttons show "coming soon" (Phase 3 / D-14), notifications bell (Phase 4).
- Verified: analyze clean; 38 tests pass (new: tier pricing, Home, category chips, product page savings, empty search, dev filters). Device check left to the user.

### 2026-09-28 — Phase 1, step 12 (Wasifu) — mobile session
- **User decision (D-4):** Wasifu follows PDF p.11.
- `ProfileScreen` (Wasifu tab): header with Mipangilio shortcut, `ProfileCard` (initials avatar, name, phone, district/region, shop name or "Mmiliki wa Biashara", Hariri Wasifu), 7 `ProfileMenuTile`s with coloured icon tiles (`MenuTone`), red outlined "Toka kwenye Akaunti" with confirm. Loading = skeleton, error = `ErrorState`.
- Menu: Taarifa za Biashara → edit; Risiti na Historia → Oda tab; Mipangilio → settings; Anwani / Njia za Malipo / Arifa / Msaada → "coming soon" snackbar (their phases).
- `EditProfileScreen` and registration step 3 share one `BusinessDetailsForm` (same fields + rules). `SettingsScreen`: Lugha (Kiswahili ✓, English "Inakuja"), Futa akaunti (confirm → `DELETE /me` → back to step 1), debug tools link (debug builds).
- Data: `Profile` + `BusinessProfile` models (API keys incl. `region_name`, `district_name`), `ProfileRepository` + `DevProfileRepository`, `ProfileController` (AsyncNotifier, reloads when the logged-in user changes). `AuthRepository.deleteAccount()`. Dev storage moved into one `DevAccountStore` shared by the dev auth + profile repositories.
- Shared: `AppButtonVariant.danger`, `showConfirmDialog`; `LocationFields` moved to `features/locations/presentation/`. Routes `/profile/edit`, `/profile/settings` inside the Wasifu tab.
- Not yet: avatar photo upload (needs image_picker + `/me/avatar`), verified badge (business verification is Phase 6).
- Verified: analyze clean; 29 tests pass (new: Wasifu content, edit + save, logout confirm/cancel, delete account). Device check left to the user.

### 2026-09-28 — Phase 1, step 11 (part 2: registration) — mobile session
- **User decision:** no real SMS yet — accept any valid Tanzanian mobile and **any 6-digit code**; real OTP/SMS later.
- Screens (PDF p.1 design): `PhoneScreen` (step 1, 🇹🇿 +255 field, orange Endelea, terms line), `OtpScreen` (step 2, 6 boxes, auto-verify when full, "Tuma tena" after a 60 s countdown, "Badilisha namba"), `BusinessInfoScreen` (step 3, jina kamili, jina la biashara *Si lazima*, Mkoa, Wilaya *Si lazima*, green Ingia CHIMBO). Back on step 3 = log out and start again.
- Shared: `AuthStepLayout` (logo, back arrow, step dots, 16:10 picture slot, title); UI kit: `StepProgress`, `PhoneField` (drops a typed 0 / pasted 255, groups 712 345 678), `OtpInput` (one hidden field, SMS autofill); `TzPhone` util (same rules as `Phone.php`).
- Data: `AuthRepository` interface + `DevAuthRepository` (token in secure storage, accounts per phone in shared_preferences, so logging in again skips step 3). `LocationRepository` + `DevLocationRepository` with 31 regions / 177 districts **generated from the `chimbo` DB (same ids as the server)**. Models use the API's JSON keys (`user_id`, `user_phone`, `user_full_name`, `profile_complete`, `region_id`, `region_name` …).
- `AuthController` (Riverpod Notifier: unknown → unauthenticated / needsProfile / authenticated). Router `redirect` = `authRedirect()` (pure, tested) + refresh on every auth change, so screens never navigate after login/logout themselves. Splash waits for display time **and** session restore.
- Debug tools: "Toka" (logout) button to rerun the flow. Input hints now grey.
- Pictures: `assets/images/auth/{welcome,verify,business}.png` (16:10) — icon placeholders until added.
- Verified: analyze clean; 25 tests pass (phone util, redirect rules, dev repository, full registration flow, "Badilisha namba"). Device check left to the user.

### 2026-09-28 — Phase 1, step 11 (part 1: splash + onboarding) — mobile session
- Splash (`features/onboarding/presentation/splash_screen.dart`): logo fades/scales in over a decorative background, slogan "Agiza. Amini. Pokea. Kuza Biashara." (D-3 default applied), leaves after 1.8 s counted from the first frame.
- Onboarding: 3 slides as in PDF p.1 (category pills; example tier card + "OKOA ZAIDI" seal — illustrative numbers, I-1; "Chagua • Lipa • Pokea" + delivery promise). Endelea (green) → Anza Sasa (orange). Android back goes to the previous slide.
- Seen flag: `OnboardingRepository` (shared_preferences, key `onboarding_seen`) + `OnboardingController` (start route). `SharedPreferences` loaded once in `main()` and injected via `sharedPreferencesProvider` override. After onboarding → Home for now; becomes the phone screen next.
- Shared widgets: `AppLogo` (mark + CHI/MBO wordmark + tagline), `BrandSlogan`, `SeparatedText`, `ImageSlot` (picture frame with icon placeholder until the real file exists).
- Logo: "C + cart" mark cut from CHIMBO.pdf p.1 (sharpest copy available) into `assets/images/brand/logo_mark.png`: background made transparent, trimmed, 456 × 306 px. Replace with the original vector/large logo when available (D-16). Shown whole (`BoxFit.contain`) in the splash.
- Pictures: icon placeholders for now. Real files go in `assets/images/` with the names/sizes in `assets/images/README.md` (4:5 portrait slides, 512² logo mark) — no code change needed.
- Native launch background set to brand cream (Android + iOS). Android 12+ system splash still shows the default Flutter launcher icon until the real app icon is added.
- Bottom bar restyled to match the design: no pill indicator — active tab = filled dark-green icon + bold green label, inactive = grey outline; icons home / search / cart / document / person; thin top border; white phone gesture strip under it. Cart count badge is **orange** (design) instead of red.
- Step 8 closed: debug screen on the emulator shows `/health` server + database "Iko sawa".
- Verified: analyze clean; 9 tests pass (new: splash text, full onboarding flow + seen flag saved, back on slide 2); on the emulator: splash → 3 slides → Anza Sasa → Nyumbani; relaunch skips onboarding.

### 2026-09-28 — Phase 0, step 8 (Flutter app setup) — mobile session
- Emulator check (later the same day): debug APK builds and runs on Pixel 6a; tabs and UI kit render. `/health` not confirmed — Apache/MySQL were stopped after the disk filled up.
- `flutter create --org com.chimbo --platforms android,ios` in `AndroidStudioProjects\chimbo` (Flutter 3.47.2 / Dart 3.13.2).
- Packages: flutter_riverpod 3, go_router 18, dio 5, flutter_secure_storage, shared_preferences, cached_network_image, skeletonizer, intl, flutter_localizations. (url_launcher, share_plus, photo_view added when a screen needs them.)
- Stricter lints in `analysis_options.yaml` (strict-casts/inference/raw-types, package imports, single quotes …).
- Theme: `core/theme/` — `AppColors` (§6.3 tokens), `AppSpacing`/`AppRadius`/`AppSizes`, `AppText`, `AppTheme.light()`. Poppins (400/500/600/700) bundled in `assets/fonts/` — no google_fonts download.
- l10n: gen-l10n, ARB files in `lib/core/l10n/`. **User decision: Kiswahili only for now**; `app_en.arb` kept as a placeholder, English switch comes later. `context.l10n` extension + `errorMessage()` (server message, or a translated fallback).
- `core/config/env.dart` — `API_BASE_URL` via `--dart-define` (default emulator `http://10.0.2.2/chimbo/api/v1`).
- Network: `ApiClient` (get/post/patch/delete → `ApiResponse` or `ApiException`), `ApiHeadersInterceptor` (Accept-Language + Bearer token), `TokenStorage` (secure storage). App-side error codes: `NETWORK_ERROR`, `TIMEOUT`, `INVALID_RESPONSE`, `UNKNOWN_ERROR`.
- Router: go_router `StatefulShellRoute` with 5 tabs (Nyumbani, Gundua, Kikapu, Oda, Wasifu) showing "Inakuja hivi karibuni" placeholders; `/debug` route (debug builds only), opened from Wasifu.
- UI kit v1 (`core/widgets/`): `AppButton` (primary/accent/outlined, loading), `AppTextField`, `PriceText`, `SectionHeader`, `EmptyState`, `ErrorState`, `VerifiedBadge`, `CartIconBadge`. `Money.format()` → `TZS 5,500`.
- Debug screen (`features/debug/`): calls `GET /health` (repository → FutureProvider → screen) and previews the UI kit.
- Android: INTERNET permission, label CHIMBO, cleartext HTTP **debug builds only**. iOS: display name CHIMBO, `NSAllowsLocalNetworking`.
- Verified: `flutter analyze` clean; `flutter test` 6 pass (Money, ApiClient ×4, tab smoke test). **Not yet verified on a device** — first Gradle build failed because the C: drive is full.

### 2026-09-28 — Phase 0, step 7 (admin skeleton)
- Helpers: `classes/core/Session.php` (secure cookie: HttpOnly, SameSite=Strict, path-scoped), `Csrf.php` (like SCMRS csrf.php), `helpers.php` (`e()`, `url()`, `redirect()`, `isPostRequest()`, `clientIp()`), loaded by bootstrap.
- Classes: `Admin` (login with lockout after 5 wrong passwords for 15 min, same error for unknown email/wrong password, constant-time dummy hash, rehash, role permissions map), `AdminSession` (login/logout, 30-min idle timeout, `requireLogin('permission')`), `AuditLog`, `Dashboard` (all counts in one query).
- Admin pages: `admin/login.php`, `admin/logout.php` (POST + CSRF), `admin/index.php` (dashboard cards); layout `admin/includes/header.php`, `footer.php`, `forbidden.php`; `admin/assets/admin.css` (brand colours as CSS variables); Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 saved in `assets/vendor/`.
- Admin UI language: English (staff tool); customer-facing text stays Kiswahili.
- Tested (curl): redirect when logged out, wrong-password message, missing CSRF → 403, validation message, login → dashboard, logout, cookie flags, `admin/includes` → 403, audit log row written. PHPUnit: 29 tests pass.
- Admin login email: `admin@chimbo.test` (the password was given to the user in chat — not stored here).

**Working in two sessions:** the mobile session works only in `AndroidStudioProjects\chimbo`; the backend session works only in `htdocs\chimbo`. Each adds its own entry to this log; don't edit the same file at the same time.

### 2026-09-28 — Phase 0, step 6 (database)
- `classes/core/Migrator.php` + `database/migrate.php` (CLI only): `migrate.php` applies new migrations, `--seed` also runs seeds, `--status` lists them. Applied files are recorded in the `migrations` table.
- `database/migrations/001_core.sql` — regions, districts, admins, users, business_profiles, otp_codes, auth_tokens, rate_limits, audit_logs, settings, sms_outbox (prefixed column names). Note: the admin table is called **`admins`** (the blueprint said `admin_users`).
- Seeds: 31 regions + 177 districts (verify the district list before launch), 9 default settings, first super admin `admin@chimbo.test` (random password printed once — the user has it).
- `.env`: `APP_KEY` (random secret; used to hash OTP codes with HMAC), `SEED_ADMIN_EMAIL`, `SEED_ADMIN_NAME`.
- Fixed `Env`: `KEY=   # comment` now reads as empty.
- Verified: re-running migrate/seed changes nothing; `database/migrate.php` → 403 in the browser; 26 tests pass.

### 2026-09-28 — Coding rules agreed
- PHP: variables/parameters/properties **snake_case**, methods camelCase, classes PascalCase; page files `snake_case.php`; common short names OK (`$stmt`, `$db`, `$id`, `$e`, `$auth`).
- **Validation lives inside the domain classes** (using `Validator`), so API/admin/website share it; API files stay thin.
- **API JSON keys = database column names** (`user_id`, `user_phone` …).
- Existing code converted to snake_case variables; 26 tests pass; health OK. Docs updated (CODING_STANDARDS, blueprint §9/§10.2/§12.1, CLAUDE.md).

### 2026-09-28 — Structure change (blueprint v2.1)
- User asked for SCMRS-style **classes + helpers + API files instead of MVC**, **no abbreviations** in names, and **prefixed DB columns** (`user_id`, `user_phone` …).
- Moved `src/` → `classes/` (`classes/core/` = helpers, `classes/payments/`, `classes/sms/`); removed `routes/` and controllers.
- API files now live in `api/endpoints/*.php` (blocked from direct access); `api/index.php` loads them all under `/v1`. `system.php` holds `GET /health`.
- `Router` now takes closures; short names renamed (`$e`→`$exception`, `$m`→`$matches`, `$r`→`$router`, `$auth`→`$authorizationHeader`).
- Docs updated: CODING_STANDARDS (structure, naming, new §3 Database naming), blueprint §9 rewritten (+ §9.4 security in this style), CLAUDE.md.
- Verified: 26 tests pass, health 200, `classes/` and `api/endpoints/` → 403.

### 2026-09-28 — Phase 0, step 5 (validation + tests)
- Rules agreed: clean code (`docs/CODING_STANDARDS.md`, `.editorconfig`); **commit/push only when the user says so**.
- `src/Support/Phone.php` — `normalizeTz()` (any TZ mobile format → `+2557XXXXXXXX`/`+2556…`), `format()` for display.
- `src/Core/Validator.php` — `Validator::validate($input, $rules)` returns cleaned data or throws 422 with all field errors (Swahili). Rules: required, nullable, string, int, bool, array, email, phone_tz, digits:N, in:a,b, min:N, max:N.
- `composer.json` (PHPUnit 11.5 dev), `phpunit.xml`, `tests/Unit/` — PhoneTest, ValidatorTest, RouterTest: **26 tests passing**. The tests caught a real Validator bug (argument-less rules were ignored) — fixed.
- Run tests: `C:\xampp\php\php.exe vendor\bin\phpunit`

### 2026-09-28 — Phase 0, step 4 (API foundation)
- `src/Core/Database.php` — PDO helper: `instance()`, `fetchOne/fetchAll/fetchValue/insert/execute`, `transaction()` (nested calls join the outer one), UTC session timezone, real prepared statements.
- `src/Core/ApiException.php` — one class with factories: `badRequest, unauthenticated, forbidden, notFound, methodNotAllowed, conflict, validation, tooManyRequests` (Swahili default messages).
- `src/Core/Response.php` — standard JSON envelope: `success, created, paginated, error, fromException`; controllers return it, `api/index.php` sends it.
- `src/Core/Request.php` — method, path, query, JSON/form body (invalid JSON → 400), headers, Bearer token, files, locale (sw/en), route params, user.
- `src/Core/Router.php` — `get/post/patch/put/delete`, `group(prefix, fn, middleware)`, `{param}` routes, before-middleware, 404 `ROUTE_NOT_FOUND` / 405 `METHOD_NOT_ALLOWED`.
- `src/Core/Logger.php` — `storage/logs/app-YYYY-MM-DD.log`, request id on every line (also sent as `X-Request-Id`).
- `api/.htaccess` (everything → `api/index.php`, passes the Authorization header) and `api/index.php` (JSON for every outcome incl. fatal errors; debug details only when `APP_DEBUG=true`).
- `routes/api.php` + `HealthController` → **`GET /api/v1/health`** returns `{status, app, version, database, time}`.
- Tested with curl: health 200, trailing slash, 404, 405, invalid JSON 400, route params, Bearer token, Accept-Language, crash → safe 500 + log line, UTF-8 body, transaction commit/rollback/nesting.
- Note: when testing with curl on Windows, send non-ASCII JSON from a file (`--data-binary @body.json`), not inline `-d`.

### 2026-09-28 — Phase 0, step 3
- Connected GitHub remote `origin` → https://github.com/Edgar-Charz/CHIMBO.git and pushed.
- Created the full folder skeleton (blueprint §9.2) with `.gitkeep` files.
- Security: root `.htaccess` (no directory listing, blocks private folders, hidden files, `.md/.json/.sql/...`, `bootstrap.php`, security headers) + `Require all denied` in every private folder; `media/.htaccess` blocks scripts. Verified: 19 private URLs → 403, homepage → 200, `media/*.webp` → 200, `media/*.php` → 403.
- `.env.example` (committed) + `.env` (local, git-ignored).
- `src/Core/Env.php` (reads `.env`, supports quotes and inline comments, converts true/false/null).
- `bootstrap.php`: autoloader for `src/` folders (no namespaces), Composer autoload when present, `.env`, UTC timezone, errors → log file (`storage/logs/php-errors.log`), warnings turned into exceptions.
- Temporary homepage `index.php` (replaced by the storefront in Phase 7).

### 2026-09-28 — Phase 0, steps 1–2
- Step 1: `git init` (branch `main`), `.gitignore`, `.gitattributes` (LF line endings), `README.md`, `CLAUDE.md`. First commit.
- Step 2: enabled `extension=gd` in `C:\xampp\php\php.ini` (backup: `php.ini.bak-chimbo-2026-09-28`); GD + WebP verified in CLI. Apache must be restarted to load it.
- Composer: no install needed — Herd's `composer.phar` works with XAMPP PHP 8.2.12:
  `C:\xampp\php\php.exe C:\Users\edgar\.config\herd\bin\composer.phar <command>`
- Created database `chimbo` (utf8mb4_unicode_ci) on local MariaDB 10.4.32.

### 2026-09-28 — Planning session
- Studied `CHIMBO.pdf` (12 pages) and wrote `docs/CHIMBO_BLUEPRINT.md` (v2.0).
- Agreed folder locations, web storefront approach and mobile-first order.
- Created empty folder `C:\Users\edgar\AndroidStudioProjects\chimbo\` (Flutter project goes here in Phase 0 step 8).
- Checked local environment: PHP 8.2.12 ✅, pdo_mysql ✅, curl ✅, MariaDB 10.4 (ok locally), **GD disabled** and **Composer not installed** → fix in Phase 0 step 2.
- No code written yet.
