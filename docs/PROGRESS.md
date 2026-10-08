# CHIMBO — Progress Log

Update this file at the end of every work session. Newest entry on top.

## Current status
- **Stage:** MVP features built on backend, admin, website and app (catalog, PIN login, cart, checkout, orders, cash on delivery + mobile money / bank checked by staff, notifications, receipts, admin tools, app pictures). Preparing for the first beta.
- **Next (backend session):** staging server + scheduled jobs (cancel unpaid orders after the time to pay, send queued SMS) → real SMS provider for login codes.
- **Before the first beta:** real "pay to" accounts in the admin · lawyer-reviewed terms and privacy (drafts seeded) · staging hosting with HTTPS · security review (blueprint §15) · Play Store internal test track.
- **Uncommitted work:** everything since commit 6a9c091 (user commits when they choose)
- **GitHub:** https://github.com/Edgar-Charz/CHIMBO (backend repo, branch `main`)
- **Waiting on the user:** answers to D-2, D-3, D-4, D-16 (blueprint §23) · payment aggregator + SMS provider choice (D-10) · hosting choice · for push notifications: Firebase project + `google-services.json` + service-account key

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
| 2026-09-30 | **Push notifications (FCM, R-11) moved forward from v1.1**, to be built in a later session — plan in the 2026-09-30 mobile entry ("Planned: push notifications") |

## Session log

### 2026-10-08 — Mobile: delivery address management
- Wasifu → **Anwani za Usafirishaji** now lists saved addresses, supports adding and editing through the shared form, setting the default, and deleting after confirmation. Successful changes update the shared addresses controller, so checkout sees the same current list.
- Added repository operations for `PATCH /addresses/{id}`, `POST /addresses/{id}/default`, and `DELETE /addresses/{id}`, plus widget and API tests.
- Verified: `flutter analyze` clean; **135 Flutter tests pass**.

### 2026-10-08 — Mobile: product sharing and WhatsApp support
- Product pages share the CHIMBO website product URL and open CHIMBO support on WhatsApp with the product link prefilled. If support contacts are still loading or failed, the button remains usable and can retry.
- Added widget tests for the share text/URL and the WhatsApp URL/message, including the support retry path.

### 2026-10-08 — "Coming soon" buttons: Arifa and Malipo yangu (backend session, app files at the user's request)
- Backend: `GET /payments` (`Payment::getPaymentsForCustomer`) — the customer's payments, newest first. Confirming the cash of a cash-on-delivery order now also saves a confirmed `payments` row (`Payment::recordCashCollected`), so cash shows in "Malipo yangu" too. 206 PHP tests passing.
- App: Wasifu → **Arifa** opens the notifications list (hint "Taarifa za oda na malipo"). Wasifu → **Malipo yangu** (was "Njia za Malipo"): two tabs — **Historia** (status chip, rejection reason, tap → order, pull to refresh; newest 20 for now) and **Njia za malipo** ("Njia za malipo tunazokubali" from `/payment-methods`). New feature folder `lib/features/payments`; the pay-to details widget moved to `checkout/presentation/widgets/payment_method_details.dart` and is shared with the order's pay-to box. 127 app tests passing, analyze clean.
- Still "Inakuja": product page Share and Uliza Muuzaji, Wasifu → Anwani (messages ready), Mipangilio → English (kept on purpose).

### 2026-10-08 — Mobile: login hang in the web build fixed (done in the backend session at the user's request)
- Cause: `DeviceName.read()` asked for iOS/Android details in a browser (DevTools phone emulation) → `UnsupportedError` not caught → the OTP/PIN request was never sent and the button kept spinning.
- `lib/core/device/device_name.dart`: browser name on web, any error → null. `ApiAuthRepository._logIn()` also logs in without the label if reading it fails.
- Forms that could spin forever on a non-API error now catch every error and show "Kuna tatizo limetokea": phone, OTP, PIN login, address, change payment method, "Nimelipa", PIN setup, business details.
- Test: login goes through when the device name can't be read. `flutter analyze` clean, 123 tests passing.

### 2026-10-08 — Web storefront: offer countdown with live seconds (web session)
- `chimbo.js`: countdown "Inaisha baada ya DD:HH:MM:SS" (under a day "HH:MM:SS"), one shared `setInterval(1000)` for every `[data-offer-ends]` on the page. Time comes from the server: `storefrontConfig()` prints `server_time`, then every API answer's `Date` header corrects `serverOffsetMs` (changes under 2 s ignored — the header has whole seconds), so a wrong computer clock does not change the countdown.
- At zero the timer sends `chimbo:offer-ended` {productId} (again after 10 s if the server still had the offer): product cards re-fetch `GET /products/{id}` and redraw (`product_card.js`), the product page reloads for its own product (`product.js`), the cart re-prices when the product is in it (`cart.js`). The old 30-second timer and whole-page reload are gone.
- `CHIMBO.offerCountdown(endsAt, productId, className)`; PHP countdowns carry `data-offer-product`. Countdown digits are tabular so the text does not wobble. Lint clean; not browser-tested (user tests).

### 2026-10-08 — Web storefront: time-limited offers "Ofa za muda" (web session)
- **Countdown (`chimbo.js`):** `CHIMBO.offerCountdown(endsAt)` and any `[data-offer-ends]` printed by PHP show "Inaisha baada ya siku / saa / dakika X"; one shared timer (30 s) updates them all and reloads the page when an offer ends while it is open (the server decides prices). An end time already past when the page opened (phone clock ahead of the server) never reloads, so no reload loop. `CHIMBO.offerTag(percent)` / PHP `offerTag()` = the "Ofa −X%" line tag.
- **Product cards:** badge "Ofa −15%" (`product_badge = offer`, red), normal price crossed out (`product_compare_at_price`), countdown under the price. PHP `productBadgeText()` follows the same rules (product page badge).
- **Product page:** "Bei ya kawaida" crossed out during an offer, countdown pill under the price, each tier's `tier_price_before_offer` crossed out next to `tier_unit_price` (`tierRows()` now has `price_before_offer`). Stepper totals still from `tier_unit_price`.
- **Home:** "Ofa za muda" rail from `home.offers` right after the trust strip (hidden when empty), "Ona zote" → `search.php?collection=offers`; the offers collection has its own heading/chip.
- **Lines:** "Ofa −X%" on cart lines (drawer + cart page, `offer_percent`), checkout items, and order items (`order_item_offer_percent`) on the order and success pages.
- Lint clean. Not browser-tested here (user tests).

### 2026-10-08 — Time-limited offers "Ofa za muda" (mobile session)
- Models: `ProductOffer` (`product_offer` percent + end time) on `ProductSummary`, badge `offer`; `PriceTier.priceBeforeOffer`; `HomeData.offers`; `ProductCollection.offers`; `CartLine.offerPercent`, `OrderItem.offerPercent` (0 = none).
- `offer_widgets.dart`: `OfferLabel` ("Ofa −15%") and `OfferCountdown` ("Inaisha baada ya siku / saa / dakika X", ticks every 30 s, calls `onEnded` once when the time is up; an offer already over shows nothing and never reloads, so a fast phone clock can't loop).
- Card: offer pill + countdown on the picture, normal price crossed out; when it ends Home and lists reload (`WidgetRef.reloadCatalogPrices`). Product page: label + countdown under the name, reloads the product when it ends; tier table shows `tier_price_before_offer` crossed out. Home: "Ofa za muda" rail first (hidden when empty) → list `collection=offers`. Cart lines and Fuatilia Oda items: "Ofa −X%".
- **Live countdown (seconds):** `OfferCountdown` shows `DD:HH:MM:SS` (`HH:MM:SS` under a day; hours 0–23), tabular digits. One shared 1-second `offerTickerProvider` (autoDispose `StreamProvider`, stops when no offer is on screen) — no timer per card. Server time: `ServerTimeInterceptor` reads the HTTP `Date` header; `ServerClock` (`core/time/server_clock.dart`) keeps `server − phone` from the first answer per app start; countdowns use `ServerClock.now()`. At zero: one reload (card → Home/lists, product page → product).
- Verified: format + analyze clean, **122 tests pass** (`offers_test.dart`: formats, clock correction, Date header, shared ticker + single reload). Device test by the user.

### 2026-10-08 — Admin: time-limited offers (admin session)
- `offers.php` (menu "Offers" under Products, `products.manage`): tabs Running / Scheduled / Ended (server-side table `ajax/offers.php`): product, −%, "TZS 6,000 → TZS 5,100" (+ best price), start / end in Tanzania time, created by, status; Edit (running / scheduled) and End now / Cancel (confirm). `offer_edit.php`: product (new offers; `?product_id=` pre-selects), percent 1–90, start (empty = now), end — datetime-local, saved UTC shown back with `adminDateTime($t, 'Y-m-d\TH:i')`; returns to the tab the offer is now in; ended offers can't be edited.
- Product page: "Offers" box (`includes/product_offers.php`) with that product's offers, Edit, End now / Cancel and "New offer". Banner form: collection hint includes `offers`. Add order: products with a running offer show "−15% offer" (prices already include it). Helper `adminOfferPrice()`; status tone `running`.
- Tested with a scheduled 2027 offer only (no live price change): validation (over 90 %, missing end, over 90 days, overlap), create, edit, cancel from the product page, ended offer not editable; test data removed. 205 tests pass.

### 2026-10-08 — Time-limited offers "Ofa" (backend session)
- Migration `010_product_offers.sql`: `product_offers` + `order_items.order_item_offer_percent`. Class `ProductOffer` (create / edit / end, one offer per product at a time, 1–90%, at most 90 days, Tanzania time in forms). `Pricing::withOffer()` / `offerPrice()`: the percentage comes off every tier, rounded to whole shillings.
- Applied everywhere through `Pricing`: product cards (`product_offer`, badge `offer`, crossed-out normal price), product page tiers (`tier_price_before_offer`), cart lines (`offer_percent`), checkout, customer and staff orders (`order_item_offer_percent`), receipts ("Ofa −15%"). An offer ending mid-checkout → `PRICE_CHANGED`.
- New collection `offers` (`GET /products?collection=offers`, Home rail `offers`, banner target).
- Tests: 205 passing (10 new).
- **Admin session:** offers.php + the "Offers" box on product_edit.php (ADMIN_CLASSES.md "Offers"). **Web / mobile sessions:** "Ofa −X%" badge, crossed-out price, countdown, Home rail "Ofa za muda", tier prices crossed out on the product page (API_REFERENCE "Product card").

### 2026-10-08 — Onboarding / login pictures appear at once (mobile session)
- The admin-managed pictures (`features/app_images`) were fetched without waiting and only the next screen's picture was preloaded as the splash left, so the screens showed the fallback icons for a few seconds. Now the splash refreshes the picture list and downloads + decodes every picture shown before Home (`AppImageSlot.beforeHome`: 3 onboarding + 3 auth) while the logo shows, and waits for them (at most 5 s from start) unless the customer goes straight to Home.
- `ReplaceableImageSlot.precache` is the one place that preloads, with the same sizes as the widget, so the picture is found in memory and drawn in the first frame. While a picture still loads the slot shows the plain background (`AppNetworkImage.iconsWhileLoading: false`); icons only when there is no picture.
- Verified: format + analyze clean, **113 tests pass**. Device test by the user.

### 2026-10-06 — Mobile: speed up onboarding and auth illustrations
- The splash now preloads the illustration for the next screen while it is still visible. Replaceable screen images cap memory/disk cached dimensions at 1200px and skip the default 500ms fade, reducing decode work and showing downloaded replacements immediately; the bundled image remains the offline/loading fallback.
- Home category cards and category banners now push the category page, so Back returns to Nyumbani instead of switching to Gundua.
- Verified with `flutter analyze` and focused app-image, onboarding and back-navigation tests.

### 2026-10-06 — Web storefront: product-photo headers for collections
- Ofa, Bidhaa mpya and Zinazouzwa zaidi now use a fixed-height collage of up to four product photos from the collection results, with a shaded Kiswahili heading. The brand-color header remains as the fallback when products have no photos.
- Reuses the initial catalog results, so the collage adds no query and follows the active collection filters. PHP syntax and whitespace checks pass.

### 2026-10-06 — Admin: upload multiple product photos together
- Product photo input now supports selecting several files in one action (Ctrl+click on Windows), then uploading them together. Every image continues through the existing per-file validation and resizing pipeline; selected files are added in selection order, and the first photo in an empty gallery becomes the main photo.
- The admin checks available gallery slots before accepting the batch and reports how many photos were added. Verified PHP syntax and browser form attributes; no uploads made during verification.
- Customer-facing banner frames now have fixed heights on mobile and desktop; uploaded images are cropped with `object-fit: cover` and cannot expand the banner frame.

### 2026-10-06 — Admin: fixed-height previews for uploaded images
- App picture cards, category images, banner images and delivery-agent photos now use fixed-height preview areas so portrait or landscape upload dimensions cannot expand the card. Images are contained to show the full upload; category, banner and agent previews use 220px, matching the App pictures preview.
- Verified in the browser on category and banner edit pages; PHP syntax and diff whitespace checks pass.

### 2026-10-06 — Mobile: app image slots and home banner/category image wiring validated
- Confirmed the app-side implementation for the new public `GET /app-images` flow: splash startup refreshes and caches the slot list, each slot falls back to its bundled asset when there is no uploaded URL or the network is unavailable, and the URL cache is keyed by URL so a new upload produces a fresh image. Home uses `top_categories[].category_image_url` for the Cosmetics/Jewelry cards and `banner_image_url` for the banner, each with the bundled asset fallback when there is no server image.
- Verified with `flutter analyze` and the focused app-image/home tests: the app-image repository/controller tests and the catalog/home image URL tests all pass. No additional code changes were required beyond confirming the implementation is complete.

### 2026-10-06 — Admin: keep app picture previews inside their cards
- Settings → App pictures now clips previews to the card and gives uploaded images explicit width/height with `object-fit: contain`, preserving the complete picture without covering the slot description or upload controls.
- Superseded by the fixed-height App pictures, category, banner and delivery-agent preview update above.

### 2026-10-06 — Web storefront: change the payment method on an unpaid order; "Agiza Tena" only when finished (web session)
- `includes/payment_box.php` + `assets/js/payment.js`: under the pay-to box, while `payment.can_change_payment_method` — "Badilisha njia ya malipo" opens the methods from `GET /payment-methods` (current one tagged "Sasa hivi"; "Tumia njia hii" enabled only for a different one) → `POST /orders/{id}/payment-method` → the page reloads with the server's order (switching to "Lipa ukipokea" confirms the order, so the pay-to box goes away). `VALIDATION_ERROR` (e.g. cash above the limit) shows under the list; `PAYMENT_UNDER_REVIEW` / `PAYMENT_METHOD_LOCKED` / `PAYMENT_TIME_OVER` show the message, then reload.
- `chimbo.js` now holds the shared `choiceCard()` and `paymentChoiceCard()` (moved out of `checkout.js`, used by checkout and the change list).
- "Agiza Tena" only for `delivered` / `cancelled` orders (`ORDER_REORDER_STATUSES`) on Oda Zangu cards and the order page.
- Checked once in headless Chrome with M-Pesa/Benki switched on for the test (and switched back off afterwards): list + "Sasa hivi", cash refused above TZS 300,000 with the server message, M-Pesa → Benki (bank details shown), M-Pesa → Lipa ukipokea (confirmed, no pay box), Agiza Tena hidden on unpaid/confirmed, shown on cancelled. Test orders cancelled, test account deleted. Note: "Mixx by Yas" is switched on in the local database (not by this session). Lint clean, 195 tests pass.
- **From now on (user decision): the web session only makes and lint-checks changes; the user tests in the browser.**

### 2026-10-06 — App pictures replaceable from the admin (backend session)
- Migration `009_app_images.sql` + class `AppImage`: 7 fixed slots (3 onboarding, 3 login/registration, order success) matching the app's built-in pictures. Upload / replace / remove in the admin (Settings → App pictures); public `GET /app-images`.
- The app keeps its built-in pictures as the fallback (first start offline, slot never uploaded).
- Tests: 195 passing (4 new in `AppImageTest`).
- **Admin session:** "App pictures" section on settings.php (ADMIN_CLASSES.md). **Mobile session:** fetch `/app-images`, cache by URL, fall back to the assets; Home cards should use the category pictures from `/home` instead of bundled ones.

### 2026-10-06 — Change the payment method on an unpaid order (backend session)
- `POST /orders/{id}/payment-method` (`Payment::changePaymentMethod`) + `payment.can_change_payment_method` in the order JSON. Allowed while waiting for payment with nothing under review (also after a rejection); not for cash orders. To cash: cash limit applies and the order is confirmed at once. The time to pay does not restart. Logged in the audit log (`order.payment_method_changed`, by the customer).
- Tests: 191 passing (3 new).
- **Web / mobile sessions:** a "Badilisha njia ya malipo" link under the pay-to box when `can_change_payment_method`; show "Agiza Tena" only for delivered or cancelled orders.

### 2026-10-06 — Mobile money / bank payments with staff checking (mobile session)
- **Checkout:** payment choices come from `payment_method_details` (`PaymentMethod` class: code, name, type, pay-to details — replaces the fixed enum); icons by type; Hakiki says where to pay is shown after ordering. `POST /orders` sends the method's code.
- **Order:** `payment` block → `OrderPayment` (method, amount, `payment_note_hint`, `can_submit_payment`, `latest_payment`), plus `order_expires_at`.
- **`OrderPaymentSection`** on "Oda Imepokelewa!" and Fuatilia Oda: while `can_submit_payment` → pay-to card (amount, number with copy, account name, bank, instructions, the order number to write as description with copy, "Lipa kabla ya …") + **Nimelipa** sheet (`POST /orders/{id}/payment`; payer number pre-filled with the customer's phone for mobile money; field errors under their field, `PAYMENT_UNDER_REVIEW` / `PAYMENT_TIME_OVER` / `PAYMENT_NOT_EXPECTED` shown as the server's message). `submitted` → "Tunakagua malipo yako"; `rejected` → staff's note, then the form again. Payment notifications already open `data.order_id`.
- **Badilisha njia ya malipo:** link under the pay-to box while `payment.can_change_payment_method` → sheet listing `GET /payment-methods` (current one marked) → `POST /orders/{id}/payment-method`; the answer is shown directly (`orderProvider` is now an `OrderController` with `show(order)`, also used by cancel and Nimelipa). Cash on delivery confirms the order (pay-to box gone). Errors show the server's message (`VALIDATION_ERROR` field, `PAYMENT_UNDER_REVIEW`, `PAYMENT_METHOD_LOCKED`, `PAYMENT_TIME_OVER`).
- **Agiza Tena** only for delivered or cancelled orders (`OrderStatus.canReorder`), in Oda Zangu and Fuatilia Oda.
- **Login screens fit the screen:** `AuthStepLayout` fills the viewport; the picture takes the space left (min 72 px, `_ShrinkToFit` reports a small intrinsic height) and the page scrolls only on very small screens. PIN keys 56 px.
- Verified: format + analyze clean, **107 tests pass** (payment and payment-method-change cases in `api_orders_repository_test.dart`, checkout options with details, PIN screen fits 360×800 / scrolls when tiny). Device test by the user.

### 2026-10-06 — Admin: payments (manual checking) (admin session)
- `payments.php` (replaces "coming soon"), tabs: **Waiting for review** (Payment::searchPayments status submitted: order, customer, method, amount — red note when it differs from the order total —, paid from, code, sent, time left to pay; Confirm with "Did you see TZS X from … with code … on the statement?", Reject with a Kiswahili reason in a dialog), **All payments** (status / method filters + search, 25/50/100 per page), **Refunds due** (Mark refunded with a refund note in a dialog), **Payment methods** (super admin only: list, on/off switch, `payment_method_edit.php`). Shared `ajax/payments.php`, dialogs `includes/payment_reject_modal.php` and `includes/refund_modal.php` (opened by the reusable `data-open-modal` in admin.js).
- Order page: Payments list (with Confirm / Reject on a waiting payment), "Record payment" form when `can_record_payment`, "Mark refunded" when a paid order was cancelled. All need `payments.manage`.
- Menu badge (payments waiting) on Payments; dashboard cards "Payments to check" and "Refunds due" link to their tab and are outlined orange while not zero.
- Helpers: `adminPaymentReviewBadge()` (payment "confirmed" is green, unlike the blue order status), `adminTimeLeft()`, `adminPaymentReviewButtons()`, `adminPayerAccount()`, `adminErrorText()`.
- Settings → **App pictures** (`AppImage`, migration 009): a card per slot (where it shows, best size, current picture or "App's own picture"), Upload (multipart `app_image`) and "Use the app's picture" (confirm). Upload errors come back as an alert; page jumps back to `#app-pictures`. Tested: upload, replace (old file deleted), fake file / no file / unknown slot refused, remove (file deleted), Finance 403.
- Tested end to end as Finance and Super Admin with a test customer and 3 test orders (all removed): reject (too-short reason refused), confirm (order → confirmed / paid; confirming twice refused), record payment (field errors, then recorded + confirmed), refund, method switch refused without an account, Finance gets 403 on payment methods. 188 tests pass.
- **Found during testing (backend):** `Payment::searchPayments()` search by code or order number also matches every payer number containing its digits (e.g. "TESTREFA1" → digits "1" → `LIKE '%1%'`), so it returns too many rows; phone searches are fine. **Also:** payment methods M-Pesa and Bank were cleared and switched off by a direct database change (not in the audit log) at 06:26 UTC while I was testing — not by the admin pages. My test save briefly put the old M-Pesa details back; I restored M-Pesa to the cleared state found before it.

### 2026-10-06 — Web storefront: mobile money / bank payments checked by staff (web session)
- **Checkout:** payment choices come from `payment_method_details` (server names; a short line + icon per type: cash / mobile_money / bank); the note under "Thibitisha Oda" says "Baada ya kuthibitisha utaona jinsi ya kulipa TZS … kwa M-Pesa" for non-cash methods.
- **New `includes/payment_box.php` + `assets/js/payment.js`, on `order.php` and `order_success.php`:** while `payment.can_submit_payment` — pay-to box (amount, method, account number and the reference `payment_note_hint` with "Nakili" copy buttons, account name, bank name, instructions, "Lipa kabla ya …" from `order_expires_at`) and the "Nimelipa" form (mobile money: the +255 phone box; bank: free text; reference upper-cased) → `POST /orders/{id}/payment`, then the page reloads. Rejected → the review note above the form. Submitted → "Tunakagua malipo yako" (with the reference). Paid / refunded / time over → a one-line result. `PAYMENT_UNDER_REVIEW`, `PAYMENT_TIME_OVER`, `PAYMENT_NOT_EXPECTED` → the message, then the page reloads to the current state; `VALIDATION_ERROR` fields under the inputs. Cash on delivery shows nothing new.
- Payment names now come from `order.payment.payment_method` (`PAYMENT_METHOD_NAMES` removed); `PAYMENT_STATUS_LABELS`: pending "Inakaguliwa", refunded "Imerudishwa". Arifa shows a wallet icon for `payment` notifications.
- Verified end to end (M-Pesa and Benki switched on with test details for the test, then switched back off): checkout lists M-Pesa / Benki / Lipa ukipokea → order → pay-to box → bad reference message → "Nimelipa" → Tunakagua → staff reject (via `Payment::rejectPayment`) → reason + form → resend → second send refused (`PAYMENT_UNDER_REVIEW`), cancel hidden → staff confirm → "Imelipwa" → cancel → refund → "Imerudishwa". Test order CHB460310 cancelled/refunded, test account deleted. Lint clean, tests pass.

### 2026-10-04 — Payments checked by staff (backend session)
- Decision: until a payment provider is connected, customers pay mobile money / bank by hand and send the payer number + confirmation code ("Nimelipa"); staff confirm or reject on the Payments page. Later the provider fills the same `payments` rows automatically.
- Migration `008_manual_payments.sql`: `payment_methods` (5 fixed methods with "pay to" details, replaces the `enabled_payment_methods` setting) and `payments`; unpaid orders now wait 24 hours (`unpaid_order_expiry_minutes` = 1440); the draft terms' payment sentence updated.
- New classes `PaymentMethod` and `Payment`. Orders gained a `payment` block (pay-to details, `can_submit_payment`, `latest_payment`). Orders can't be cancelled while a payment is being checked. Checkout options gained `payment_method_details`.
- Endpoints: `POST /orders/{id}/payment`, `GET /payment-methods`.
- Permissions: `payments.manage` (finance) reviews; `payment_methods.manage` (super admin only) edits the pay-to accounts.
- Tests: 188 passing (13 new in `PaymentTest`).
- Not yet: cancelling unpaid orders automatically after the time to pay (cron, blocked on hosting) — staff cancel them for now.
- **Admin session:** Payments page + payment methods + "Record payment" on the order page (ADMIN_CLASSES.md "Payments page").
- **Mobile / web sessions:** payment choice at checkout from `payment_method_details`; on the order: pay-to box, "Nimelipa" form, "Tunakagua malipo yako", rejection reason (API_REFERENCE "Paying by mobile money or bank").

### 2026-10-04 — One-click switches for the admin lists (backend session, suggested by the admin session)
- New `RecordSwitch` (classes/core): changes one on/off column of one record in a locked transaction and logs only that change; does nothing when the value is already set.
- New methods: `ProductEditor::setProductActive`, `Category::setCategoryActive`, `Banner::setBannerActive`, `Seller::setSellerVerified`, `Seller::setSellerStatus`, `DeliveryAgent::setDeliveryAgentActive`, `DeliveryMethod::setDeliveryMethodActive` (ADMIN_CLASSES.md "One-click switches").
- Tests: 175 passing (4 new in `RecordSwitchTest`).
- **Admin session:** switch the list toggles (and the record-page switches if they post alone) to these methods; the banner time conversion in banners.php is then no longer needed.

### 2026-10-01 — Admin: staff accounts, audit log, reports, settings (admin session)
- `admin_users.php` (in-page table: role, status, "locked", last login) + `admin_user_edit.php` (create with password; edit name/email/role/status; separate "Reset password" form; "what each role can open" built from the menu + `Admin::ROLE_PERMISSIONS`); shared `includes/admin_password_fields.php`. Uses `AdminUser` (refuses disabling/demoting yourself — shown under the field).
- `audit_log.php`: filter card (staff member, done by, action, what changed, record number, from/to) + server-side table from `ajax/audit_log.php` (25/50/100 per page, newest first). Changes shown as "field: old → new" (unchanged fields hidden, long values shortened); the record links to its admin page (`ADMIN_ENTITY_PAGES`).
- `reports.php`: date range (default last 30 days, quick ranges 7/30/90 days and this month; wrong range shown under the dates), 6 summary cards, 5 sortable section tables, "Download CSV" per section (`?export=section`, file sent with no-store).
- `settings.php`: form built from `Settings::getEditableSettings()` (input type from the class's own rules: tel / number / text; legal texts as large textareas), one save; delivery options table + `delivery_method_edit.php` (`DeliveryMethod`). `payments.php` stays "coming soon".
- admin.js: `data-length-menu`, `data-searching="false"`; `adminDataTablesJson()` takes an optional row class. Helpers `adminAuditChanges()`, `adminAuditEntity()`, `adminCalendarDate()`.
- Tested end to end with a temporary super admin, staff account and delivery option (all removed; settings restored): validation under fields, own-account protection, password reset, audit filters, report ranges + every CSV, unchanged settings save writes nothing, role access (only Reports for Finance). 171 tests pass.
- Reports also download as PDF: "Full report (PDF)" (summary + all sections) and a PDF button next to each section's CSV; layout `admin/includes/report_pdf.php` (built from the same section definitions as the page), Dompdf through `adminPdfFromHtml()` with the receipt's safe settings; downloads (CSV, PDF, receipt) go through one `adminSendFile()` (no-store).
- Actions column on every list (icon buttons, `adminRowActions()` / `adminActionLink()` / `adminActionButton()` — POST + CSRF, confirm on delete, handled by the list page with `adminHandleForm()`): products (edit, stock, hide/show, delete), stock (adjust, edit), categories (edit, hide/show, delete), sellers (edit, activate/deactivate; verified button kept), banners (edit, hide/show, delete), orders (view, receipt), customers (view), delivery agents (edit, activate/deactivate), admin users (edit), delivery options (edit, enable/disable). Toggles use the backend's one-click switches (setProductActive, setCategoryActive, setBannerActive, setSellerVerified/Status, setDeliveryAgentActive, setDeliveryMethodActive) — the audit log now records only the changed column; tested: toggling twice leaves every record identical. Every action also exists on the record page (switch/delete/stock/receipt).
- Customer page: the Orders section now lists the customer's orders (`Order::getOrders()`, 20 per page with Newer/Older links): number, placed, status, payment, items, total, and View / Print receipt actions for roles with `orders.view`.
- For the backend: the payment-method CSV shows codes (`cod`) — the page shows "Cash on delivery"; maybe use readable names in `Report::exportCsv()`.

### 2026-10-01 — Web storefront step 5 done: Vipendwa, Arifa, Wasifu, Anwani, help/terms/privacy (web session)
- **Vipendwa:** shared `assets/js/wishlist.js` (loaded on every page): saved ids from `/wishlist/ids`, `heartButton()` on every product card (photo corner), `bindButton()` for the product page heart (its own copy removed from `product.js`); all hearts of a product update together (`data-heart-for`), guests go to login. `wishlist.php` + `wishlist_page.js`: saved products with the shared card; removing a heart takes the card away; empty state.
- **Arifa:** `notifications.php` + `notifications.js`: order updates newest first (`timeAgo()` — "Dakika 5 zilizopita", "Jana, 14:20"), unread highlighted with an orange dot; tapping marks read and opens the order; "Soma zote"; bell count kept in step; page links.
- **Anwani zangu:** `addresses.php` + `addresses.js`: list with "Kuu", "Weka kuu", "Badilisha", "Futa". The address form is now one shared piece — `includes/address_form.php` + `assets/js/address_form.js` (`CHIMBO.addressForm.attach/describe`, add or edit) — used by checkout and Anwani zangu. `location_select.connect()` can now be called again safely.
- **Wasifu (`account.php`/`account.js`, built by another session, extended):** quick links (Oda Zangu, Vipendwa, Arifa, Anwani zangu), "Badilisha taarifa" (name, email → `PATCH /me`; shop, region, district → `PATCH /me/business`), "Toka" (phones have no header menu), "Futa akaunti yangu" with a confirmation dialog (`DELETE /me`).
- **Msaada / Vigezo na Masharti / Faragha / 404:** `help.php` (support phone, WhatsApp link, hours from `Settings::getSupportContacts()` — the same as `GET /support` — plus the COD limit; 9 questions in an accordion). `terms.php` / `privacy.php` show `Settings::getLegalPage()` (the same text as `GET /pages/…`, edited by staff in admin settings): escaped, split into paragraphs on empty lines (shared `includes/legal_page.php`). **The legal texts are the backend's drafts — a lawyer must review them before launch.** New `404.php` ("Ukurasa haupo", links home / Gundua), used for every unknown address via `ErrorDocument 404 /chimbo/404.php` in the root `.htaccess` (the path must match the shop folder on the live server). Every page linked from the header/footer now exists.
- Verified in headless Chrome: register (code → PIN → details) → heart on a card and the product page → Vipendwa (2 → 1 after un-hearting) → order → Arifa (bell 1, opens the order, read) → Anwani (add prefilled, make main, edit, delete) → Wasifu (bad email message, details saved) → Futa akaunti (logged out). Test order cancelled, test account deleted. Lint clean, 171 tests pass, no overflow at 360 px.

### 2026-10-01 — Backend for the remaining admin pages and website pages (backend session)
- Decision: finish the admin dashboard and the website before time-limited offers.
- New classes: `AdminUser` (staff accounts: create, edit, disable, reset password; never yourself; always one super admin), `Report` (sales summary, by day / category / top products / payment method / region, CSV), `DeliveryMethod` (delivery options and fees). `AuditLog` gained `search()`, `getActions()`, `getEntityTypes()`. `Settings` gained the editable list (`Settings::EDITABLE`), `updateSettings()`, `getSupportContacts()`, `getLegalPage()`.
- New public endpoints: `GET /support`, `GET /pages/terms`, `GET /pages/privacy`. Draft Kiswahili legal texts seeded (`legal_terms`, `legal_privacy`) — **a lawyer must review them before launch**.
- Tests: 171 passing (10 new in `AdminToolsTest`).
- **Admin session:** build `admin_users.php`, `audit_log.php`, `reports.php`, `settings.php` from ADMIN_CLASSES.md (section "Admin users, Audit log, Reports, Settings"). `payments.php` stays "coming soon" until Phase 5.
- **Web session:** pages still missing from blueprint §10.2: `wishlist.php`, `notifications.php`, `addresses.php`, `help.php` (uses `/support`), `terms.php` / `privacy.php` (use `/pages/…`), `404.php`. All their APIs exist.

### 2026-10-01 — PIN login + global 401 handler (mobile session)
- **Login flow (blueprint §12.5):** phone screen → `POST /auth/start` → `next_step`: `otp` (code already sent → OTP screen), `pin` → new `PinLoginScreen` ("Ingia kwa PIN", `/auth/pin/login`), `pin_locked` → same screen with "PIN imefungwa…" + "Umesahau PIN?". `PIN_INVALID` shows the server message; `PIN_LOCKED` starts "Umesahau PIN?" (SMS code → OTP screen → "Weka PIN Mpya").
- **Order after every login and on start (`/auth/me`):** new `AuthStatus.needsPin` → `CreatePinScreen` (`/auth/pin/new`, "Tengeneza PIN" = registration step 3 of 4); then `needsProfile` → business details (step 4); else Home. An SMS login from "Umesahau PIN?" always asks a new PIN (`AuthState.isPinReset`).
- **PIN entry:** `PinPad` (own number pad, dots, 4–6 digits, PIN sent as text) and `PinSetupForm` (current → new → confirm; server field errors `current_pin` / `user_pin` / `user_pin_confirmation` shown on their step). Wasifu → "Badilisha PIN" (`/profile/pin`, `ChangePinScreen`).
- **Logins send** `auth_token_device_name` (device_info_plus, `core/device/device_name.dart`) and `auth_token_platform`.
- **Global 401 handler:** `SessionExpiredInterceptor` (401 on a request that carried a token) → `sessionExpiredEventsProvider` → `AuthController` forgets the token and shows the phone screen with "Umetolewa kwenye akaunti. Tafadhali ingia tena." (e.g. PIN changed on another phone).
- **Msaada and legal pages** (`features/support/`): Wasifu → Msaada (`/profile/help`: hours from `GET /support`, "Piga simu" `tel:`, WhatsApp via `support_whatsapp_url`); Wasifu → Vigezo na Masharti / Sera ya Faragha and the two links in the login screen's terms sentence open `LegalPageScreen` (`/pages/terms|privacy`, public, allowed by the login redirect). `page_body` is split into paragraphs at empty lines and shown as plain text.
- Test fakes follow the server's PIN rules (easy PINs refused, 5 wrong → locked). Verified: format + analyze clean, **99 tests pass** (new: `pin_login_flow_test.dart`, `session_expired_interceptor_test.dart`, `api_support_repository_test.dart`, `support_pages_test.dart`, PIN cases in `api_auth_repository_test.dart` and the registration flow). Device test by the user.

### 2026-10-01 — Web storefront: PIN login reviewed and tested (web session)
- The PIN login on the website (`login.php`, `auth.js`) and the new Wasifu page (`account.php`, `account.js`: photo upload/remove, account details, "Badilisha PIN", "Umesahau PIN?" via `login.php?forgot_pin=1`) were built by another session on 2026-09-30; this session reviewed them against the API and tested them end to end in headless Chrome.
- Checked: new number → `/auth/start` "otp" → code auto-filled → Tengeneza PIN (`1234` refused: "PIN hii ni rahisi kukisia", mismatch → "PIN hazifanani") → business details → home; Wasifu → Badilisha PIN (wrong current PIN → "Umebakiza majaribio 4" under the field; right → "PIN yako imebadilishwa") → Toka (`/auth/me` 401 afterwards); registered number → PIN step → wrong PIN message under the box → right PIN → logged in. `requireCustomer()` / `isCustomerReady()` also send customers without a PIN back to login.
- Tidied: `account.php` had a second `<main>` inside the layout's `<main>` (now a `<section>`), an unstyled "AKAUNTI YAKO" label removed, file doc comments for `account.php` and an up-to-date one for `login.php`.
- Test account deleted. Lint clean, 171 tests pass, no overflow at 360 px.

### 2026-10-01 — Admin: customer PIN on the customer page (admin session)
- Migrations 006 and 007 were already applied. The PIN rows ("Ana PIN", "PIN imefungwa") and the "Lazimisha kubadili PIN" button had already been added to `admin/customer_details.php` by another agent; reviewed and moved its form handling to `adminHandleForm()` (permission `customers.manage` checked in the action, NOT_FOUND shown as a flash message, other errors in the page alert).
- Tested with temporary customers and admin (removed): with PIN → reset locks the PIN, revokes sessions, writes `customer.pin_reset_forced`; without PIN → button hidden, forced POST gives "This customer has no PIN to reset."; Finance → button hidden, POST refused; bad CSRF → 403; the hash is never shown.
- Open: the page still reads the two PIN flags with its own SQL — needs a class method (e.g. `CustomerPin::getPinStatus($user_id)` → `user_has_pin`, `user_pin_is_locked`) from the backend session.

### 2026-09-30 — PIN login (backend session)
- **New login flow** (blueprint R-23, §12.5): registration = phone → SMS code → create PIN → business details; login = phone → PIN; "Umesahau PIN?" = SMS code → new PIN. 4–6 digits, like M-Pesa.
- Migration `007_customer_pin.sql`: `users.user_pin_hash`, `user_pin_failed_attempts`, `user_pin_locked_at`, `user_pin_changed_at`, `user_sessions_revoked_at`; `auth_tokens.auth_token_pin_reset_until`.
- New class `CustomerPin` (hashing, 5-try lock, easy-PIN check, staff forced reset). `CustomerAuth` gained `startLogin()`, `logInWithPin()`, `savePin()`; both login kinds share one private `logIn()`.
- Endpoints: `POST /auth/start`, `POST /auth/pin/login`, `POST /auth/pin` (🔒). The profile has `user_has_pin`. Details in API_REFERENCE "Customer login".
- Changing or resetting a PIN logs out every other device (tokens revoked; website sessions checked against `user_sessions_revoked_at` in `AuthMiddleware`).
- Tests: 161 passing (13 new in `CustomerPinTest`). Checked the whole flow over HTTP.
- **Mobile session:** phone screen calls `/auth/start` and branches on `next_step`; new screens Tengeneza PIN, Ingia kwa PIN (+ "Umesahau PIN?"), Badilisha PIN in Wasifu; after every login check `user_has_pin` before `is_profile_complete`. Existing test accounts have no PIN, so they go through the SMS code and create one.
- **Web session:** same flow on the login page (`client: "web"` + `X-CSRF-Token` on `/auth/pin/login`).
- **Admin session:** run `php database/migrate.php`; "Ana PIN" / "PIN imefungwa" on the customer page and a "Lazimisha kubadili PIN" button → `CustomerPin::forcePinReset()` (ADMIN_CLASSES.md).

### 2026-09-30 — Speed work; time-limited offers planned (backend session)
- **Smaller answers:** JSON of 1 KB or more is gzip-compressed when the client accepts it (`Response::send`). Home 8.4 KB → 1.3 KB, product list 4.5 KB → 0.9 KB.
- **Photos cached for a year** (`media/.htaccess`, `Cache-Control: public, max-age=31536000, immutable`). Safe because a new photo always gets a new random file name.
- **Fewer database writes:** a Bearer token's last-used time and expiry are refreshed at most once an hour instead of on every request (`AuthToken::findUserIdByToken`).
- **Stored list prices:** migration `006_stored_product_prices.sql` adds `products.product_price` (highest tier) and `product_price_from` (lowest tier) with an index. `ProductEditor::refreshStoredPrices()` updates them whenever tiers are saved (also in the demo seed). Shop lists, price filters/sorting, the admin product list and the manual-order form read these columns instead of re-calculating every product's tiers. **Cart, checkout and orders still price each line from the tiers.** Anyone who changes tiers outside `ProductEditor` must call `refreshStoredPrices()`.
- **Home rails no longer count** matching products (`Product::getCollection` skips the COUNT; only paged lists count).
- Tests: 148 passing (one new assertion for stored prices). Local timings 20–30 ms per endpoint.
- **Planned next (before the first beta): time-limited offers "Ofa"** — blueprint R-22 and §12.4.
- Later, not now: FULLTEXT search (LIKE is fine at the current catalog size).
- **Other sessions:** admin — run `php database/migrate.php` to get migration 006; nothing else to change (it already goes through `ProductEditor`). Mobile/web — nothing to change; gzip is handled by the HTTP client/browser.

### 2026-09-30 — Web storefront: Oda Zangu + order tracking; guest cart live (web session)
- **Guest cart now works** with the backend's new `POST /cart/preview`: quick add as a guest → badge + stepper, product page → drawer, cart page, checkout → login → merge. No web code change was needed.
- **`orders.php` (Oda Zangu):** tabs Zote / Zinazoendelea / Zimefika, server-rendered order cards (`includes/order_card.php`: number, date, status pill, first product photos, pieces, total, "Agiza Tena", "Fuatilia"), page links when there are more than 20. Orders from the app show here too (same account).
- **`order.php` (Fuatilia Oda):** status pill + message, vertical timeline (`orderTimeline()`: done / current in orange / stopped in red for cancelled or expired, with Tanzania times), expected or delivered date, cancel reason, delivery agent with "Piga simu", items, totals, payment (COD: "andaa TZS …"), delivery details, "Agiza Tena", "Pakua Risiti" (`receiptUrl()` → the API's PDF, the login cookie is enough), "Ghairi oda" in a dialog with an optional reason (only while `can_cancel`).
- `assets/js/orders.js`: reorder (skipped-item messages, cart refreshed, drawer opens) and cancel.
- `includes/storefront.php`: `ORDER_STATUS_LABELS` / `ORDER_STATUS_MESSAGES` (the same words as the app), `ORDER_STATUS_TONES`, `PAYMENT_METHOD_NAMES`, `PAYMENT_STATUS_LABELS`, `ORDER_TRACKING_STEPS`.
- Fixes: a stepper with `delayMs: 0` now reports at once (fast taps then "Ongeza kikapuni" added too few); cart lines show one bin (the separate remove button) instead of two at the MOQ.
- Verified end to end in headless Chrome (desktop + phone): guest cart → order CHB576159 → Fuatilia → Oda Zangu → Agiza Tena → receipt `200 application/pdf` → cancel → red timeline. Test orders cancelled, test accounts deleted. Lint clean, 148 tests pass.
- **Phone numbers checked in the browser too** (user report: the login box accepted any length): `CHIMBO.phone` in `chimbo.js` — typing/pasting 0712…, 712…, +255 712… or 255712… is tidied to "712 345 678" (digits only, 9 at most); a number that is not a Tanzanian mobile (6/7 + 8 digits) shows "Weka namba sahihi ya simu, mfano 712 345 678." under the box and nothing is sent; the message clears while correcting; the API gets "+255…". Used on login and the checkout address form (now also with the +255 box). The server still validates as before.
- Next (step 5 rest): Vipendwa (wishlist page + hearts on cards), Arifa (notifications), Wasifu (profile, business, addresses, photo, logout), help/terms/privacy pages.

### 2026-09-30 — Guest cart preview for the website (backend session, requested by the web session)
- `POST /cart/preview` (public, saves nothing): prices a guest's browser cart and returns exactly the `GET /cart` shape; problems come back as `line_problem`, unknown/hidden products in `warnings`; max 100 items; rate limit 600/hour per IP.
- `Cart` refactored so pricing lives in ONE place: new private `priceItems([product_id => quantity])` is used by both `getCart()` (which then removes unavailable saved lines) and new `previewGuestCart()`.
- Tests: 3 new in `ShoppingTest` (preview identical to a saved cart and saves nothing, problems/warnings, duplicates added + bad input/over 100 rejected) — **148 passing**. docs/API_REFERENCE.md updated.

### 2026-09-30 — Web storefront step 4: cart, login, checkout, order success (web session)
- **Focused layout** for login and checkout (`$page['is_focused']`): `includes/focused_header.php` (logo + "Salama na siri"), one-line footer, no menus/search/bottom nav, `header.js` not loaded. Full footer moved to `includes/site_footer.php`.
- **`cart.php` + `cart_page.js`:** lines grouped by category (shared line template), summary panel (pieces, "Unaokoa", subtotal, checkout blocked while `can_checkout` is false, guest hint, "Futa kikapu chote"), phone bar with total + "Endelea kwenye Malipo". `cart.js` now fills every `[data-cart-summary]` box (drawer + cart page) in one place, and offers `whenLoaded()`, `refresh()`, `clear()`, `mergeGuestCart()`, `renderGroups()`.
- **`login.php` + `auth.js`:** phone → 6 code boxes (auto-advance, paste, backspace, auto-submit, resend countdown, dev code auto-filled with a "Hali ya majaribio" note) → "Tuambie kuhusu biashara yako" for new customers (name, shop, region → district). Then the guest cart goes to `POST /cart/merge` (skipped items shown), browser copy cleared, back to `?return=` — `safeReturnPath()` only accepts paths inside the shop. `requireCustomer()` sends guests (and customers without step 3) to login and back.
- **`checkout.php` + `checkout.js`:** 1 address (radio cards; add-address form prefilled from the profile), 2 delivery methods that reach it, 3 payment (cod), 4 review with the server's totals (`POST /checkout/preview`), note, "Thibitisha Oda" → `POST /orders` with `expected_total` and an `Idempotency-Key` kept in sessionStorage for the checkout; `PRICE_CHANGED` refreshes the totals and asks again; `CART_EMPTY`/`CART_HAS_PROBLEMS` send the customer to the cart. Defaults are chosen automatically, so a returning customer only confirms.
- **`order_success.php`:** check-mark + ring animation, order number, total, payment, expected date in Kiswahili (`swahiliDate()`), cash reminder for COD, delivery address, items, "Fuatilia Oda" (order.php — step 5) / "Endelea kununua".
- Shared: `chimbo.js` `submitForm()` (field messages under inputs), `newIdempotencyKey()`; `location_select.js` (region → district, used by login and checkout).
- **Product page:** "Unaweza pia kupenda" always shows up to 10 products — related ones first, topped up from the same top category, then best sellers (`productSuggestions()`), never the product itself.
- **Home:** the sub-category circles are one sideways "train" on every screen; on desktop ‹ › buttons (shown only when there is more that side) and soft fading edges.
- Verified end to end in headless Chrome: guest with a browser cart → checkout → login (dev code) → step 3 (empty fields show messages) → cart merged → address added → Standard/Haraka options → preview totals → order CHB070974 placed (channel `web`) → success page; login again on a phone width, checkout with savings row, cart page. Test order cancelled (stock returned) and test account deleted. Lint clean, 145 tests pass, no overflow at 360 px.
- Still waiting on the backend: `POST /cart/preview` for guest carts.

### 2026-09-30 — Web storefront: one owner; catalog, product and Gundua pages cleaned up (web session)
- **User decision:** this web session now owns the whole storefront (root pages, `includes/`, `assets/css|js|img|fonts`); the other session that built category/product/search in parallel stops. Reviewed and reworked its pages to the coding standards.
- **One catalog for category + search/collections:** `includes/catalog_browser.php` (count, "Chuja" filters with an active-count badge, sort, grid, "Umeona bidhaa X kati ya Y" + "Onyesha zaidi" only when there is more than one page) and one `assets/js/catalog.js` (sort/filters/load more via `GET /products`, address bar kept in step, Back works, late answers ignored, empty state with "Ondoa vichujio" when filters hide everything). `search.js` (a ~90% copy of catalog.js) removed.
- `includes/storefront.php`: `catalogFilters()`, `catalogState()` (server-rendered first page; validation errors from `Product::getProducts()` shown as a Kiswahili message, e.g. search > 100 characters), `requestInt()`, `requestText()`, `formatPieces()`, `productBadgeText()`, `CATALOG_SORT_OPTIONS`, `PRODUCT_BADGE_LABELS`; `tierRows()` now also returns `min_quantity` and `savings_percent` (home uses it too).
- `category.php` / `search.php` rewritten (invalid `?chip=` no longer passed `false` as the category; collections get chips Zote/Ofa/Mpya/Zinazouzwa zaidi). `product.php` / `product.js` cleaned up: first tier from `Pricing::tierForQuantity()`, "pcs" wording, "−8%" badge like the cards, next-tier hint ("Ongeza 18 pcs upate TZS 4,800 kila moja"), "Tayari una N pcs kikapuni", instant price preview (`quantityStepper` got a `delayMs` option), delivery/COD/verified promises, phone buy bar that appears only after the main button scrolls away, thumbnails column only when there are several photos (fixes the empty column), page scripts not loaded on 404 pages. Gallery (user request): hovering or tabbing to a thumbnail shows it in the big picture, moving away shows the chosen photo again, clicking chooses it.
- **New `explore.php` (Gundua tab):** Ofa / Bidhaa mpya / Zinazouzwa zaidi shortcuts + every category with its sub-categories. Shared `includes/subcategory_link.php` (home + Gundua).
- **Bug fixed:** the header/footer category loops used `$category`/`$chip`, which leaked into the page (includes share variables) — the Cosmetics page showed "Jewelry" and the "Bags" chip. Layout partials now use `$menu_category`, `$menu_chip`, `$footer_category`.
- CSS: one-line rules and the product section that had split the product-card block replaced by formatted sections (tier table — shared, breadcrumb, catalog, product page, Gundua); missing `.eyebrow` usages removed.
- Verified: lint clean, 145 tests pass; headless Chrome 360/390/1366 px on home, category, search (incl. 120-character search), product, Gundua, 404s — no console errors, no overflow; sort, filters, filter badge, empty state, address bar; product preview, add → drawer, in-cart note, buy bar. Test account deleted. Guest add-to-cart still waits for `POST /cart/preview` (backend).

### 2026-09-30 — Web storefront standards cleanup
- Moved product tier selection and savings calculations above the product page HTML; the template now only displays prepared values.
- Limited storefront search input to the API's 100-character maximum. Longer queries now show a Kiswahili validation message and do not call the products API.
- Verified changed PHP files with `php -l` and ran PHPUnit (**145 tests passing**). Browser and console checks remain unavailable because no browser session is exposed.

### 2026-09-30 — Web storefront: collection navigation
- Built `search.php` for the existing header links to Ofa (`deals`), Mpya (`new`), and Zinazouzwa Zaidi (`best_sellers`). It reads the matching collection through `Product::getProducts()`, renders products with the shared product card, supports sorting and load-more, and also handles header search queries.
- Added `assets/js/search.js` for product cards, sorting, pagination, URL history, and empty/error states. Existing storefront CSS and header links were reused.
- Verified all three collection URLs return HTTP 200 with the correct distinct heading and product counts (3, 2, 12); PHP lint clean; PHPUnit **145 passing**.

### 2026-09-30 — Web storefront: interactive product image gallery
- Product detail gallery now uses the large image size in its main display. On desktop the thumbnails sit vertically to the left; on phones they remain in a horizontal strip below the image.
- Hover and keyboard focus preview a thumbnail; click selects it and keeps it displayed after pointer exit. The gallery still supports products with a single image or no images.
- Verified: PHP lint clean; product route returned HTTP 200; `product.js` serves and contains hover/focus/click handling; PHPUnit **145 passing**. Current local product records have no multi-image gallery to exercise visually; browser bridge is unavailable.

### 2026-09-30 — Web storefront: product details page
- Added `product.php` and the `/p/{product_id}-{slug}` rewrite using existing product, tier, image-gallery, and related-product data. The page shows product photos, seller verification, wholesale tier prices, MOQ, current quantity-price preview, delivery range, description, wishlist/share actions, add to cart, and related products.
- Added `assets/js/product.js` for gallery selection, tier/quantity previews, wishlist, sharing, cart action, and related product cards. Added product-page component styles using the existing storefront tokens.
- No backend changes. Guest add-to-cart still depends on the missing public cart preview endpoint.
- Verified: `php -l product.php` clean; `/p/1-vaseline-petroleum-jelly-400ml` returned HTTP 200 with its product heading and tier table; both page scripts returned HTTP 200; an unknown product returned 404; PHPUnit **145 passing**. Browser viewport/console checks and JavaScript syntax parsing remain unavailable here (browser bridge unavailable; Node.js is not installed).

### 2026-09-30 — Web storefront: category pages show selected category and products
- Fixed a shared PHP variable collision: the header category menu reused `$category`, overwriting the selected category with the last top-level category (Jewelry). `category.php` now keeps its page data in `$current_category`.
- Loaded `product_card.js` before `catalog.js`, so initial product results render through the shared product card component.
- Verified both `/c/1-cosmetics` and `/c/9-jewelry` return HTTP 200, render their correct distinct headings and include their respective product data and card-renderer script. PHP lint clean; PHPUnit **145 passing**. No styles or backend code changed.

### 2026-09-30 — Web storefront: category listing page
- Added `category.php` for `/c/{category_id}-{slug}` using the existing `Category` and `Product` classes. Includes breadcrumb, category heading, subcategory chips, price/MOQ filters, sort, product grid, empty/error states, and paginated load-more through the existing `/products` API.
- Added the category URL rewrite and `assets/js/catalog.js`; product tiles use the existing `CHIMBO.productCard` component. Added category-page component CSS with the established storefront tokens and controls.
- No backend files changed. Product links still await the product page step.
- Verified: `php -l category.php` clean; PHPUnit **145 passing**; `/c/1-cosmetics` returned HTTP 200 and rendered the catalog page without PHP warning/fatal text. Phone/desktop browser and console checks plus JavaScript syntax parsing remain unavailable here (browser bridge unavailable; Node.js is not installed).

### 2026-09-30 — Web storefront: home page rebuilt as a store layout (web session)
- The first home page (made by another session) was the phone app stretched to desktop (sideways rails with empty space, two competing heroes, busy cards). User asked for a Shopify-style web layout; phones keep the app feel.
- `index.php` (PHP draws the page from `Home::getHome()`; product grids come from the same data printed as JSON — no second request): split hero (text on green + banner photo; photo behind the text on phones; Bootstrap carousel when there are several banners) + Ofa / Bidhaa mpya tiles · trust strip · "Karibu tena" reorder grid (logged in) · "Nunua kwa aina" (big category tiles + round sub-category links) · one product section with tabs (Zinazouzwa Zaidi / Mpya / Ofa) in a 5-column grid (sideways rail on phones) · green "Nunua zaidi, lipa kidogo" band with a real tier table (the best seller with the longest price ladder, "Unaokoa hadi N%").
- New shared `assets/js/product_card.js` (`CHIMBO.productCard.render/renderSkeletons`): photo, badge ("−8%" on offers), seller + verified tick, name, "Kuanzia" price with old price, MOQ; quick add puts the MOQ in the cart and turns into a stepper; cards follow the cart through `chimbo:cart-changed`. Mouse users see "Ongeza kikapuni" on hover; touch screens a round (+). `assets/js/home.js` rewritten (renders grids, tabs with arrow keys).
- `includes/storefront.php`: `collectionUrl()`, `bannerUrl()` (outside links only over http/https), `formatTzs()`, `tierRows()`; `productUrl()`/`categoryUrl()` slug now optional (`/p/{id}` works for banner targets).
- CSS: the old home block (partly one-line rules) and alias tokens (`--chimbo-text`, `--chimbo-border` …) removed; new sections for section headings, hero, trust strip, category tiles, wholesale band, tier table, product grid and card. New token `--chimbo-image-shade`.
- **Data to fix in admin:** the Cosmetics and Skin Care category images are screenshots of an unrelated app; no product has photos yet. Banner images are 800 px (fine for the split hero; a larger size would help on big screens).
- Verified: lint clean, 145 tests pass, headless Chrome 360/390/1366 px — no console errors or overflow; tabs, quick add → stepper + badge, logged-in "Karibu tena". Guest add still waits for `POST /cart/preview` (backend). Test account deleted.

### 2026-09-30 — Web storefront: Home page
- Replaced the Home placeholder with a PHP-rendered storefront homepage using the existing `Home` and catalog classes: greeting, wholesale benefits, active banners, categories, and product rails for deals, new arrivals, best sellers, and (when signed in) recently ordered items.
- Added mobile-first home components and quick-add actions via the existing shared cart helper. Product cards display server-provided tier starting prices, MOQ, seller verification, and image placeholders.
- No backend changes. Guest cart preview (`POST /cart/preview`) is still absent from the API; guest quick-add therefore cannot work until the backend provides it, while signed-in cart quick-add uses the existing endpoint.
- Verified: `php -l index.php` clean; PHPUnit **145 passing**; `http://localhost/chimbo/` returned 200 with the home heading, categories, and collection sections and no PHP warning/fatal text. Browser viewport/console check and JavaScript syntax check could not run in this environment (browser bridge unavailable; Node.js is not installed).

### 2026-09-30 — Web storefront: design system + shared layout (web session)
- Decisions (my proposed defaults; the user said continue): pages READ through the classes for the first render (layout, meta, 404) and draw the rest with JavaScript from the API; pretty URLs `/p/{id}-{slug}` and `/c/{id}-{slug}` (no slug lookup needed; the rewrite rules come with the product/category pages); guest cart in `localStorage`, priced by the server.
- **Waiting on the backend session:** a public `POST /cart/preview` `{"items":[{product_id, quantity}]}` → the same priced cart shape as `GET /cart` (groups, summary, warnings), nothing saved, no login; problems as `line_problem` (not errors), products that left the shop in `warnings`. Until it exists, guests can't add to the cart (clear error toast, nothing saved).
- Assets: Poppins 400/500/600/700 as WOFF2 (~51 KB each, from the app's TTFs) + OFL licence in `assets/fonts/`; `assets/img/logo-mark.webp` (192 px) and `favicon.png`, cropped from the app's `logo_mark.png` (its left edge had a stray line).
- `assets/css/theme.css` (all tokens: brand colours, neutrals, type scale, spacing, radii, shadows, motion; Bootstrap variables mapped to the brand) · `assets/css/app.css` (buttons — green = navigate, orange `.btn-buy` = buy, with dark text for contrast — header, search suggestions, category menu, footer, bottom nav, cart drawer, cart line, quantity stepper, skeleton/empty/error states, toasts, animations; reduced motion respected).
- `includes/`: `init.php` (bootstrap + helpers + `CustomerSession::start()`), `storefront.php` (`asset()` with cache-busting, `productUrl()`, `categoryUrl()`, `loginUrl()`, `currentCustomer()`, `shopCategories()`, `pageSettings()`, `storefrontConfig()`), `head.php`, `header.php`, `footer.php`, `bottom_nav.php`, `cart_drawer.php`. Pages set `$page` (title, description, nav, scripts) and include header/footer.
- JS: `chimbo.js` (the `CHIMBO` namespace: `api.get/getPage/post/patch/delete` with CSRF + ngrok header, error toasts, 401 → login with return path; `el()` safe DOM builder, `formatTzs`, `formatPieces`, `productImage` + placeholder, `stateBlock`/`errorState`, `setLoading`, `toast`, `quantityStepper` with a debounced commit). `cart.js` (guest/account cart behind one interface, badges with bump, drawer with tier price, next-tier hint, line problems + one-tap fixes, "Unaokoa", checkout blocked while `can_checkout` is false; `chimbo:cart-changed` event). `header.js` (live search suggestions with keyboard support, unread bell count, logout, header shadow on scroll).
- `index.php` now uses the layout (the full Home comes in step 3).
- Verified: `php -l` and `node --check` clean, 145 backend tests pass; headless Chrome at 360/390 px and 1366 px — no console errors, no horizontal overflow; search suggestions; logged-in drawer (add, stepper — two taps = one PATCH, remove, badge), account menu. Temporary test account deleted afterwards.

### 2026-09-30 — Rules for any AI agent + web storefront guide (backend session)
- New **`AGENTS.md`** (project root): the shared rules for every AI agent (docs to read, file ownership per area, non-negotiable rules, definition of done, Git rules). `CLAUDE.md` now just points to it plus Claude-specific notes. Flutter folder got its own `AGENTS.md` pointing to the same rules.
- New **`docs/WEB_STOREFRONT_GUIDE.md`**: pages and files, how the website calls the API (web login + CSRF, guest cart + merge, prices from the server, idempotent orders), JS/CSS rules, required page states, definition-of-done checklist.
- Measured API speed: 20–35 ms per endpoint with the demo data. Optimization plan (not done yet, waiting for the user): gzip + media caching in Apache, token "last used" write at most hourly, skip counts for Home rails, stored product prices (migration), full-text search later.

### 2026-09-30 — Notifications, receipt PDF, profile photo (mobile session)
- **Arifa:** Home bell shows the unread count (`unreadNotificationsProvider`, `CountIconBadge` — renamed from `CartIconBadge`); `NotificationsScreen` at `/home/notifications` (paginated, pull to refresh, "Soma zote"); tapping marks read and opens `notification_data.order_id`. Feature folder `features/notifications/`.
- **Pakua Risiti:** `ApiClient.download()` (bytes; JSON errors still become `ApiException`), `OrdersRepository.downloadReceipt`, `OrderActions.shareReceipt` saves `Risiti-CHB….pdf` in the app documents folder (path_provider) and opens the share sheet (share_plus).
- **Profile photo:** `ApiClient.upload()` (multipart), `ProfileRepository.uploadAvatar/deleteAvatar`; Wasifu photo has a camera badge → sheet (Chagua picha / Piga picha / Ondoa picha, image_picker, shrunk to 1080 px). `PersonAvatar` (renamed from `InitialsAvatar`) shows `user_avatar_url` in Wasifu and Home, initials otherwise. iOS camera/photo texts in Info.plist.
- **Back button:** one Android back (button or swipe) never closes the app. `core/widgets/exit_guard.dart` (`ExitGuard`) on the root screens (tabs, onboarding, phone): the first back shows "Bonyeza nyuma tena ili kutoka.", a second within 3 s (`AppDurations.exitConfirmWindow`) exits. Back on another tab's first screen goes to Nyumbani first; screens inside a tab still close normally; "Oda Imepokelewa!" back = Rudi Nyumbani.
- Verified: format + analyze clean, **80 tests pass** (new: `back_button_test.dart`). Device test by the user.

#### Planned: push notifications (not started — user decision: later)
Goal: notifications in the phone's status bar even when the app is closed; if the phone is offline, FCM delivers them when it reconnects (like WhatsApp). Tapping opens the order.
1. **User:** Firebase project "CHIMBO" → Android app `com.chimbo.chimbo` → `google-services.json` into `AndroidStudioProjects/chimbo/android/app/`. Project settings → Service accounts → private key JSON for the **backend only** (secret, outside the web root, never in the app).
2. **Backend session:** table `device_tokens` (user_id, device_token unique, device_platform, last_seen_at) · `POST /devices` 🔒 `{"device_token", "device_platform": "android"}` (save/update for the logged-in user) · `DELETE /devices/{token}` on logout · `Notification::notifyUser()` also sends through the FCM HTTP v1 API to all the user's tokens: `notification {title, body}`, `data {notification_id, order_id}`, Android priority high, channel `chimbo_orders`; delete tokens FCM reports `UNREGISTERED` · document in API_REFERENCE.md (blueprint §12 already lists `POST /devices`, table `device_tokens`).
3. **Mobile session:** `firebase_core` + `firebase_messaging`; ask notification permission (Android 13+); after login register the token (`POST /devices`) and again on token refresh; remove it on logout; tapping a push (app closed or in background) opens the order and marks it read; with the app open, refresh the bell count and show a toast instead.

### 2026-09-30 — Receipt PDF and profile photo (backend session)
- **Receipt ("Pakua Risiti"):** Dompdf 3.1 added via Composer. `Receipt` class (`createReceiptForCustomer` — own orders only; `createReceiptForAdmin`) + HTML template `classes/views/receipt.php` (CHIMBO green header, buyer, delivery address copy, items with tier, totals, payment method/status in Kiswahili, Tanzania time, "ODA IMESITISHWA" stamp when cancelled). Dompdf runs with remote files off and chroot to the project. `GET /orders/{id}/receipt` returns the PDF via new `Response::download()`. Helper `localDateTime()`.
- **Profile photo:** `User::setAvatar()` / `setAvatarFromFile()` / `removeAvatar()` (ImageUploader, 300 px WebP kept, old file deleted on replace); account deletion now deletes the photo file too. `POST /me/avatar` (multipart field `avatar`), `DELETE /me/avatar`.
- Checked visually: receipt PDF renders correctly (fixed the column-title alignment). Live: PDF download 200 `application/pdf`, others' receipts 404, avatar upload/replace/remove, fake image rejected.
- Tests: `tests/Integration/ReceiptAndAvatarTest.php` (7) — **145 passing**.

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
- "Print receipt" button on the order page → `admin/order_receipt.php` sends the PDF from `Receipt::createReceiptForAdmin()` inline (new tab, `Cache-Control: private, no-store`); permission `orders.view`; missing order → back to the list with a message.

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
