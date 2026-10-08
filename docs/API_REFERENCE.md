# CHIMBO API Reference (built endpoints)

Base URL (local): `http://localhost/chimbo/api/v1` · Android emulator: `http://10.0.2.2/chimbo/api/v1`
This file lists only endpoints that **exist and are tested**. The full plan is in the blueprint §12.

## Conventions
- JSON in, JSON out. Keys = database column names (`user_phone`, `region_id` …).
- Success: `{"success": true, "data": …, "meta": {…}}`
- Error: `{"success": false, "error": {"code": "…", "message": "…", "fields": {"field": "message"}}}` — `message` is Kiswahili, ready to show; `fields` appears on `VALIDATION_ERROR` (show each under its input).
- Dates: ISO-8601 UTC, e.g. `2026-09-28T14:39:44+00:00`.
- **Image and link URLs use the address you called the API with** (emulator → `http://10.0.2.2/chimbo/…`, phone on Wi-Fi → `http://192.168.x.x/chimbo/…`, production → its domain). Unknown hosts fall back to `APP_URL`.
- **Mobile app login:** header `Authorization: Bearer <auth_token>`.
- **Website login:** session cookie (sent automatically by the browser) + header `X-CSRF-Token: <csrf_token>` on every POST/PATCH/DELETE.
- **Compression:** answers of 1 KB or more are gzip-compressed when the request says `Accept-Encoding: gzip` (browsers and Dart's `http`/`dio` do this by themselves). Home shrinks from ~8 KB to ~1.3 KB.
- **Photos** under `/media/` are cached for a year (`Cache-Control: immutable`) — a changed photo always gets a new file name, so never add `?v=` to image URLs.

## System
| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/health` | — | `{status, app, version, database, time}` |

## Support and legal pages (public)
| Method | Path | Answer |
|---|---|---|
| GET | `/support` | `{support_phone, support_whatsapp, support_whatsapp_url, support_hours}` — "Msaada": call button `tel:`, WhatsApp button = `support_whatsapp_url` |
| GET | `/app-images` | `[{app_image_slot, app_image_url, updated_at}]` — pictures staff uploaded for the app's fixed screens. Slots: `onboarding_products`, `onboarding_wholesale`, `onboarding_delivery`, `auth_welcome`, `auth_verify`, `auth_business`, `order_success`. A slot missing from the list → use the picture built into the app. A new upload always has a new URL (cache by URL) |
| GET | `/pages/terms` · `/pages/privacy` | `{page_title, page_body, updated_at}` — `page_body` is plain text, paragraphs separated by an empty line (escape it, then split on `\n\n`). Other names → 404 `NOT_FOUND` |

## Locations
| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/regions` | — | `[{region_id, region_name}]` |
| GET | `/regions/{region_id}/districts` | — | `[{district_id, district_name}]` · 404 `NOT_FOUND` |

## Customer login (phone → PIN; new numbers: phone → SMS code → create PIN → business details)

**Screens and calls**
| Case | Calls |
|---|---|
| Phone number screen (everyone) | `POST /auth/start` → `next_step` says which screen is next |
| `next_step: "otp"` (new number, or no PIN yet) | code already sent → `POST /auth/otp/verify` → `POST /auth/pin` (Tengeneza PIN) → `POST /auth/profile` (business details) |
| `next_step: "pin"` | `POST /auth/pin/login` |
| `next_step: "pin_locked"` or "Umesahau PIN?" | `POST /auth/otp/request` → `POST /auth/otp/verify` → `POST /auth/pin` (new PIN, no old PIN needed) |
| Wasifu → "Badilisha PIN" | `POST /auth/pin` with `current_pin` |

After **any** login (and on app start with `GET /auth/me`) check the profile in this order:
`user_has_pin: false` → "Tengeneza PIN" · else `is_profile_complete: false` → business details · else → Home.

### `POST /auth/start` — the phone number screen
Body: `{"user_phone": "0712 345 678"}`
```json
{"user_phone": "+255712345678", "next_step": "pin"}
{"user_phone": "+255712345678", "next_step": "pin_locked"}
{"next_step": "otp", "user_phone": "+255712345678", "otp_expires_in_seconds": 300, "otp_resend_after_seconds": 60, "debug_otp_code": "849144"}
```
`pin_locked` = 5 wrong PINs: show "PIN imefungwa…" with a button to "Umesahau PIN?". For `otp` the SMS code has **already been sent** (same fields as `/auth/otp/request`).
Errors: `VALIDATION_ERROR` · `FORBIDDEN` (suspended account) · `RATE_LIMITED` (429, 30 numbers per hour per device) · the `/auth/otp/request` errors for `otp`.

### `POST /auth/pin/login` — the PIN screen
Body: `{"user_phone": "0712345678", "user_pin": "4826", "client": "app", "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android"}`
Send the PIN as **text** (keeps a leading zero). Same answer and `client` rules as `/auth/otp/verify` (web needs `X-CSRF-Token`).
Errors: `PIN_INVALID` (422, message says "Umebakiza majaribio N") · `PIN_LOCKED` (423 → "Umesahau PIN?") · `FORBIDDEN` (suspended) · `RATE_LIMITED` · `CSRF_INVALID` (web).

### `POST /auth/pin` · 🔒 — create, reset or change the PIN
Body: `{"user_pin": "4826", "user_pin_confirmation": "4826", "current_pin": "5930"}`
- **Tengeneza PIN** (no PIN yet) and **new PIN after "Umesahau PIN?"**: no `current_pin`. A device that logged in with an SMS code may set a new PIN without the old one **once, within 15 minutes**.
- **Badilisha PIN** (Wasifu): `current_pin` required; wrong tries count towards the 5-try lock.
- Rules: 4–6 digits; refused when easy to guess (`0000`, `1234`, `9876`, the end of the customer's phone number) — the message is in `fields.user_pin`. Mismatch → `fields.user_pin_confirmation`.
- Replacing a PIN **logs out every other device** (app and website); this device stays logged in.
Returns the profile. Errors: `VALIDATION_ERROR` (`user_pin`, `user_pin_confirmation`, `current_pin`) · `PIN_INVALID` / `PIN_LOCKED` (wrong current PIN).

### `POST /auth/otp/request` — send the SMS code ("Umesahau PIN?", "Tuma tena")
Body: `{"user_phone": "0712 345 678"}` (any Tanzanian format)
```json
{"user_phone": "+255712345678", "otp_expires_in_seconds": 300, "otp_resend_after_seconds": 60,
 "debug_otp_code": "849144"}
```
`debug_otp_code` exists only in **"show code" mode** (`OTP_SHOW_CODE=true`) on a local or staging server — the app should auto-fill/show it when present. It is **never** sent when `APP_ENV=production`.
Errors: `VALIDATION_ERROR` (bad phone) · `OTP_RESEND_TOO_SOON` (429, message says how many seconds) · `RATE_LIMITED` (429) · `SMS_FAILED` (503).

### `POST /auth/otp/verify` — check the SMS code, log in
Body:
```json
{"user_phone": "0712345678", "otp_code": "849144", "client": "app",
 "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android"}
```
- `client: "app"` → response includes `auth_token` (save in secure storage) and `auth_token_expires_at` (60 days, renewed on every use).
- `client: "web"` → no token; the browser session is logged in. **Requires** header `X-CSRF-Token` (get it from `GET /auth/csrf` first).
```json
{"user": { …profile, see below… }, "auth_token": "b27a…cdb", "auth_token_expires_at": "2026-11-27T14:39:44+00:00"}
```
Then follow the profile checks above (`user_has_pin`, then `is_profile_complete`).
Errors: `OTP_INVALID` (422) · `OTP_EXPIRED` (422: expired, used, or replaced by a newer code — ask for a new one) · `OTP_TOO_MANY_ATTEMPTS` (429) · `FORBIDDEN` (suspended account) · `CSRF_INVALID` (web).

### `POST /auth/profile` — business details step (also edits later) · 🔒
Body: `{"user_full_name": "Joyce Joseph", "business_name": "Duka la Joyce", "region_id": 2, "district_id": 8}`
(`business_name` and `district_id` optional; the district must belong to the region.) Returns the profile.

### `GET /auth/me` · 🔒 — the logged-in customer (call when the app starts)
### `POST /auth/logout` · 🔒 — revokes the token / ends the web session. Returns `null`.
### `GET /auth/csrf` — website only: returns `{csrf_token}` and starts the shop session.

### Profile object
```json
{"user_id": 1, "user_phone": "+255712000111", "user_full_name": "Joyce Joseph", "user_email": null,
 "user_avatar_url": null, "user_locale": "sw", "created_at": "2026-09-28T14:39:44+00:00", "user_has_pin": true,
 "business": {"business_name": "Duka la Joyce", "region_id": 2, "region_name": "Dar es Salaam",
              "district_id": 8, "district_name": "Ilala", "business_verification_status": "unverified"},
 "is_profile_complete": true}
```
`business` is `null` until the business details step is done. `user_has_pin: false` → show "Tengeneza PIN" first. 🔒 endpoints answer `401 UNAUTHENTICATED` without a valid login → send the user to the phone screen.

## My account ("Wasifu") · 🔒 all
| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/me` | — | profile |
| PATCH | `/me` | any of `user_full_name`, `user_email` (empty = remove), `user_locale` (`sw`/`en`) — only what changes | profile · `VALIDATION_ERROR` (e.g. email used by another account) |
| PATCH | `/me/business` | `business_name` (optional), `region_id`, `district_id` (optional, must be in the region) | profile |
| DELETE | `/me` | `{"confirm": true}` | `null` — personal data removed, every login ended; the phone number can register again as a new account |

**Profile photo:**
- `POST /me/avatar` — **file upload** (`multipart/form-data`) with the field **`avatar`** (JPG, PNG or WEBP, up to 8 MB, at least 200 px) → the profile with the new `user_avatar_url` (a 300 px WebP). A new photo replaces the old one. Error: `INVALID_IMAGE` (422) with a Kiswahili message.
- `DELETE /me/avatar` → the profile with `user_avatar_url: null` (show initials again).

## Catalog — Nyumbani & Gundua (public, no login needed)
| Method | Path | Returns |
|---|---|---|
| GET | `/home` | `{banners[], top_categories[], best_sellers[], deals[], offers[], new_arrivals[], recently_ordered[]}` — `offers` = "Ofa za muda" (running offers, ending soonest first; hide the rail when empty) — one call for the whole Home tab (`recently_ordered` stays `[]` until orders exist) |
| GET | `/categories` | `[{category_id, category_name, category_slug, category_tagline, category_image_url, children: [same without children]}]` |
| GET | `/categories/{id}` | one top category with its `children` (chips) · 404 |
| GET | `/products` | paginated product cards (`meta`: page, per_page, total, last_page) |

`GET /products` query parameters (all optional): `category_id` (a top category includes its chips), `q` (search name/brand),
`collection` = `deals` \| `new` \| `best_sellers` \| `offers` (running offers, ending soonest first), `min_price`, `max_price` (normal price, TZS — offers are not taken into account), `max_moq`,
`sort` = `popular` (default) \| `newest` \| `price_asc` \| `price_desc`, `page` (from 1), `per_page` (default 20, max 50).

**Product card:**
```json
{"product_id": 1, "product_name": "Vaseline Petroleum Jelly 400ml", "product_slug": "vaseline-petroleum-jelly-400ml",
 "category_id": 2, "product_price": 5500, "product_price_from": 4400, "product_compare_at_price": null,
 "product_moq": 1, "product_unit_label": "pc", "product_in_stock": true, "product_image_url": null,
 "product_offer": null, "product_badge": "bestseller", "seller_name": "Shamba la Vipodozi", "seller_is_verified": true}
```
- `product_price` = price for the smallest quantity; `product_price_from` = best wholesale price ("kuanzia"). **Both already include a running offer.**
- `product_compare_at_price` = the crossed-out price, or `null`: the normal price during an offer, otherwise the old price of a `deal`.
- `product_offer` = `{"product_offer_percent": 15, "product_offer_ends_at": "2026-10-12T17:00:00+00:00"}` while a time-limited offer runs, else `null` → "Ofa −15%" badge and the countdown "Inaisha baada ya saa X" (count down to `product_offer_ends_at`; when it reaches zero, reload — the server decides).
- `product_badge`: `offer` \| `deal` \| `bestseller` \| `new` \| `null` (one at most, in that order).
- `product_image_url` is `null` until the admin uploads photos → show a placeholder.

### `GET /products/{id}` — product page (public)
Everything on the card, plus:
```json
{"product_brand": "Vaseline", "product_description": null, "category_name": "Skin Care", "parent_category_name": "Cosmetics",
 "product_stock_quantity": 500, "product_delivery_days_min": 2, "product_delivery_days_max": 3,
 "tiers": [{"tier_min_quantity": 1, "tier_unit_price": 5500}, {"tier_min_quantity": 6, "tier_unit_price": 5000},
           {"tier_min_quantity": 24, "tier_unit_price": 4700}, {"tier_min_quantity": 60, "tier_unit_price": 4400}],
 "images": ["http://…/media/products/1/…-medium.webp", "…"],
 "gallery": [{"product_image_id": 3, "product_image_thumb_url": "…-thumb.webp", "product_image_medium_url": "…-medium.webp",
              "product_image_large_url": "…-large.webp", "product_image_is_primary": true}]}
```
- `images`: the photos **in display order** (medium size) — big photo + thumbnail strip. Empty until photos are uploaded.
- `gallery`: every size of each photo (use `thumb` for the thumbnail strip, `large` for zoom).
- Tier ranges for display: each tier runs up to the next tier's `tier_min_quantity − 1`; the last one is "60+".
- During an offer every tier's `tier_unit_price` is the offer price and the tier also has `tier_price_before_offer` (show it crossed out). Work out stepper totals from `tier_unit_price` as before.
- 404 `NOT_FOUND` if the product doesn't exist or is hidden.

### `GET /products/{id}/related` — up to 10 product cards from the same chip ("You may also like")

## Wishlist (heart button) · 🔒 all
| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/wishlist` | — | saved products as **product cards**, most recently saved first (hidden products left out) |
| GET | `/wishlist/ids` | — | `[7, 1]` — ids only, to fill in the hearts on cards |
| POST | `/wishlist` | `{"product_id": 7}` | `null` (saving twice is fine) · 404 if the product isn't in the shop |
| DELETE | `/wishlist/{product_id}` | — | `null` |

## Cart — Kikapu · 🔒 all except `/cart/preview` · every call returns the whole priced cart
| Method | Path | Body |
|---|---|---|
| GET | `/cart` | — |
| POST | `/cart/items` | `{"product_id": 1, "quantity": 8}` — **adds** to what's already there |
| PATCH | `/cart/items/{product_id}` | `{"quantity": 12}` — sets the quantity |
| DELETE | `/cart/items/{product_id}` | — |
| DELETE | `/cart` | — empties the cart |
| POST | `/cart/preview` | **public (no login)** — website guests: prices the cart kept in the browser **without saving anything**. Body `{"items": [{"product_id": 1, "quantity": 8}]}` (max 100 items; the same product twice is added together) → **the same shape as `GET /cart`**. Below-MOQ / not-enough-stock lines come back with `line_problem` (not an error); unknown or hidden products are left out and listed in `warnings`. Invalid body → `VALIDATION_ERROR` (`fields` like `items.0.quantity`). No CSRF check needed (read-only); limit 600 calls/hour per IP. |
| POST | `/cart/merge` | website after login: `{"items": [{"product_id": 1, "quantity": 8}]}` → cart + `skipped_items[{product_id, message}]` (bigger quantity wins) |

```json
{"groups": [{"category_name": "Cosmetics", "items": [{
    "product": { …product card… }, "cart_quantity": 8,
    "unit_price": 5000, "tier_min_quantity": 6, "line_total": 40000, "line_savings": 4000,
    "next_tier_hint": {"extra_quantity": 16, "unit_price": 4700},
    "line_problem": null, "available_quantity": 500}]}],
 "summary": {"line_count": 1, "piece_count": 8, "subtotal": 40000, "savings": 4000, "can_checkout": true},
 "warnings": []}
```
- Prices are **always calculated by the server** from the tiers — the app never sends a price.
- `next_tier_hint` = "Ongeza pcs 16 upate TZS 4,700" (null at the best tier). `line_savings` / `savings` = "Unaokoa" (tiers + offer, against the normal price).
- Each line also has `offer_percent` (0 = no offer) → "Ofa −15%" on the line. Order items keep it as `order_item_offer_percent`.
- `line_problem` (after stock/MOQ changed): `not_enough_stock` (lower to `available_quantity`), `out_of_stock`, `below_moq`, or null. Checkout is blocked while `can_checkout` is false.
- `warnings`: products that left the shop were removed — show the message once.
- Errors: `VALIDATION_ERROR` on `quantity` (below MOQ: "Kiwango cha chini cha kuagiza ni 6 (MOQ).") · `OUT_OF_STOCK` (409) · `NOT_FOUND`.

## Addresses · 🔒 all
| Method | Path | Body / returns |
|---|---|---|
| GET | `/addresses` | list, default first |
| POST | `/addresses` | `address_recipient_name`, `address_phone`, `region_id`, `district_id` (optional), `address_street`, `address_landmark` (optional), `address_is_default` (optional) → the address (201). The first one becomes the default. |
| PATCH | `/addresses/{id}` | same body → the address |
| POST | `/addresses/{id}/default` | → all addresses |
| DELETE | `/addresses/{id}` | → remaining addresses (if it was the default, the newest one becomes default) |

Address: `{address_id, address_recipient_name, address_phone, region_id, region_name, district_id, district_name, address_street, address_landmark, address_is_default}`.

## Checkout · 🔒 all
- `GET /checkout/options?address_id=3` → `{"delivery_methods": [{delivery_method_id, delivery_method_code, delivery_method_name, delivery_method_fee, delivery_days_min, delivery_days_max, region_id}], "payment_methods": ["mpesa", "cod"], "payment_method_details": [ …payment method objects… ]}` — with `address_id`, only methods that reach that region ("Haraka" = Dar es Salaam only). `payment_methods` = the codes staff switched on; `payment_method_details` = the same methods with their name and "pay to" details (show the name in the list; show the pay-to box after ordering).
- **Payment method object** (also `GET /payment-methods`, public): `{payment_method_code, payment_method_name, payment_method_type (mobile_money | bank | cash), payment_method_account_name, payment_method_account_number (Lipa Namba / phone / bank account), payment_method_bank_name, payment_method_instructions}`.
- `POST /checkout/preview` `{"delivery_method_id": 1, "address_id": 3}` → `{line_count, piece_count, subtotal, savings, delivery_method_id, delivery_fee, discount_total, grand_total, delivery_days_min, delivery_days_max}`. Errors: `CART_EMPTY` (409), `CART_HAS_PROBLEMS` (409), `VALIDATION_ERROR` on `delivery_method_id`.

## Orders — Oda · 🔒 all
| Method | Path | Body / returns |
|---|---|---|
| POST | `/orders` | "Thibitisha Oda": `{"address_id", "delivery_method_id", "payment_method", "expected_total", "order_customer_note"}` + header **`Idempotency-Key: <random 8–64 chars, one per checkout>`** → the order (201). Turns the cart into an order and empties the cart. |
| GET | `/orders?group=active\|delivered\|all&page=1` | "Oda Zangu" (paginated, newest first). `active` = Zinazoendelea, `delivered` = Zimefika |
| GET | `/orders/{id}` (also `/orders/{id}/tracking`) | the order with items, `events` (timeline) and `delivery_agent` |
| POST | `/orders/{id}/cancel` | `{"order_cancel_reason"}` (optional) → the order. Only while `can_cancel` is true (before packing). |
| GET | `/orders/{id}/receipt` | "Pakua Risiti": the receipt as a **PDF file** (`Content-Type: application/pdf`, file name `Risiti-CHB123456.pdf`) — download and open/share it. Own orders only (404 otherwise). |
| POST | `/orders/{id}/payment` | **"Nimelipa"**: `{"payment_payer_account": "0712345678", "payment_reference": "QJK3X7ABC1"}` → the order. Only while `payment.can_submit_payment` is true. For `bank`, `payment_payer_account` is the account / name paid from. |
| POST | `/orders/{id}/payment-method` | **"Badilisha njia ya malipo"**: `{"payment_method": "airtel_money"}` → the order. Only while `payment.can_change_payment_method` is true. To `cod`: same cash limit as checkout, and the order becomes `confirmed` / `cod_pending` at once. The time to pay does not restart. |
| POST | `/orders/{id}/reorder` | "Agiza Tena" → the cart + `skipped_items` (current prices; unavailable items skipped) |

Place-order errors: `PRICE_CHANGED` (409 — the message has the new total; show Hakiki again), `CART_EMPTY`, `CART_HAS_PROBLEMS` (409), `VALIDATION_ERROR` on `payment_method` (not enabled, or cash-on-delivery above the limit) or `delivery_method_id`.
With the same `Idempotency-Key`, a repeated request (double tap, retry after a timeout) returns the first order — never a second one.

**Order:**
```json
{"order_id": 1, "order_number": "CHB765068", "order_status": "confirmed", "order_channel": "app",
 "order_payment_method": "cod", "order_payment_status": "cod_pending",
 "order_subtotal": 91000, "order_delivery_fee": 5000, "order_discount_total": 0, "order_total": 96000,
 "order_placed_at": "2026-09-29T13:10:00+00:00", "order_estimated_delivery_date": "2026-10-02",
 "order_delivered_at": null, "order_cancelled_at": null, "order_cancel_reason": null, "order_customer_note": null,
 "order_expires_at": null, "can_cancel": true, "item_count": 2, "piece_count": 14,
 "delivery_method": {"delivery_method_id": 1, "delivery_method_name": "Standard (siku 2–3)", "delivery_method_fee": 5000,
                     "delivery_days_min": 2, "delivery_days_max": 3},
 "address": {"address_recipient_name": "Joyce Joseph", "address_phone": "+255712345678", "region_id": 2,
             "region_name": "Dar es Salaam", "district_name": null, "address_street": "Kariakoo, Lumumba St", "address_landmark": null},
 "items": [{"order_item_id": 1, "product_id": 1, "product_name": "Vaseline Petroleum Jelly 400ml", "product_image_url": null,
            "order_item_unit_label": "pc", "order_item_quantity": 8, "order_item_unit_price": 5000,
            "order_item_tier_min_quantity": 6, "order_item_line_total": 40000}],
 "events": [{"order_status": "confirmed", "status_note": null, "created_at": "2026-09-29T13:10:00+00:00"}],
 "delivery_agent": null,
 "payment": {"payment_method": { …payment method object… }, "payment_amount": 96000, "payment_note_hint": "CHB765068",
             "can_submit_payment": false, "can_change_payment_method": false,
             "latest_payment": {"payment_id": 4, "payment_payer_account": "+255712000111", "payment_reference": "QJK3X7ABC1",
                                "payment_status": "submitted", "payment_review_note": null, "created_at": "…"}}}
```
- `order_status`: `pending_payment`, `confirmed`, `packed`, `dispatched`, `in_transit`, `delivered`, `cancelled`, `expired`. Cash on delivery starts at `confirmed`.
- `order_payment_status`: `unpaid`, `pending`, `paid`, `cod_pending` (cash to collect on delivery), `refunded`, `failed`, `cancelled` (the order was cancelled/expired before payment — nothing is owed).
- `events` = the "Fuatilia Oda" timeline (every status reached, with its time).
- `delivery_agent` (from `dispatched` on): `{delivery_agent_full_name, delivery_agent_phone, delivery_agent_photo_url}`.
- `address` is a **copy** made when ordering (it has no `address_id`).
- `GET /home` with a login now also fills `recently_ordered` ("Uliagiza Hivi Karibuni").

**Paying by mobile money or bank (checked by CHIMBO staff until a provider is connected)**
1. The order is placed as `pending_payment` / `unpaid` with `order_expires_at` (24 hours by default).
2. Order screen, while `payment.can_submit_payment`: show the pay-to box — "Lipa **TZS {payment_amount}** kwa {payment_method_name}: **{payment_method_account_number}** ({payment_method_account_name})", the bank name for banks, `payment_method_instructions`, and "Andika **{payment_note_hint}** kama maelezo/kumbukumbu" — then the **"Nimelipa"** form (number paid from + confirmation code from the SMS).
3. After sending: `order_payment_status` = `pending`, `latest_payment.payment_status` = `submitted` → show "Tunakagua malipo yako". Cancelling is blocked while it is checked (`can_cancel` false).
4. Staff confirm → order `confirmed`, payment `paid` (notification "Oda imethibitishwa"). Staff reject → payment `unpaid` again, `latest_payment.payment_status` = `rejected` with `payment_review_note` (show it), notification type `payment`, and the form is shown again.
5. A paid order that is cancelled is refunded by staff → `order_payment_status` = `refunded` (notification "Pesa imerudishwa").

`POST /orders/{id}/payment` errors: `VALIDATION_ERROR` (`payment_payer_account`: not a Tanzanian mobile number; `payment_reference`: 6–30 letters/digits, or already used) · `PAYMENT_UNDER_REVIEW` (409, already sent) · `PAYMENT_TIME_OVER` (409) · `PAYMENT_NOT_EXPECTED` (409: cash order or not waiting for payment) · `NOT_FOUND`.
`POST /orders/{id}/cancel` can also answer `PAYMENT_UNDER_REVIEW` (409).
`POST /orders/{id}/payment-method` errors: `VALIDATION_ERROR` on `payment_method` (switched off, or cash above the limit) · `PAYMENT_UNDER_REVIEW` · `PAYMENT_METHOD_LOCKED` (409: cash order, paid, or not waiting for payment) · `PAYMENT_TIME_OVER`.

## Malipo yangu · 🔒
`GET /payments?page=1` → the customer's own payments, newest first, 20 per page (`meta` as other lists):
`[{payment_id, order_id, order_number, payment_method_code, payment_method_name, payment_amount, payment_payer_account,
payment_reference, payment_status (submitted | confirmed | rejected), payment_review_note, created_at, payment_reviewed_at}]`.
Every "Nimelipa" is listed (also rejected ones, with the reason), payments staff recorded, and cash collected on delivery
(`payment_method_code` `cod`, reference `CASH-{order_number}`). Show "Njia za malipo tunazokubali" below it from `GET /payment-methods`.

## Notifications (the bell) · 🔒 all
| Method | Path | Returns |
|---|---|---|
| GET | `/notifications?page=1` | paginated `[{notification_id, notification_type, notification_title, notification_body, notification_data: {"order_id": 1}, is_read, created_at}]`, newest first |
| GET | `/notifications/unread-count` | `{"unread_count": 2}` — the number on the bell |
| POST | `/notifications/{id}/read` | `null` |
| POST | `/notifications/read-all` | `null` |
Every order status change creates a notification (Kiswahili title + text). `notification_data.order_id` → open that order.
