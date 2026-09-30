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

## System
| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/health` | — | `{status, app, version, database, time}` |

## Locations
| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/regions` | — | `[{region_id, region_name}]` |
| GET | `/regions/{region_id}/districts` | — | `[{district_id, district_name}]` · 404 `NOT_FOUND` |

## Customer login (phone + OTP — registration and login are the same steps)

### 1. `POST /auth/otp/request` — send the code (also "Tuma tena")
Body: `{"user_phone": "0712 345 678"}` (any Tanzanian format)
```json
{"user_phone": "+255712345678", "otp_expires_in_seconds": 300, "otp_resend_after_seconds": 60,
 "debug_otp_code": "849144"}
```
`debug_otp_code` exists only in **"show code" mode** (`OTP_SHOW_CODE=true`) on a local or staging server — the app should auto-fill/show it when present. It is **never** sent when `APP_ENV=production`.
Errors: `VALIDATION_ERROR` (bad phone) · `OTP_RESEND_TOO_SOON` (429, message says how many seconds) · `RATE_LIMITED` (429) · `SMS_FAILED` (503).

### 2. `POST /auth/otp/verify` — check the code, log in
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
If `user.is_profile_complete` is `false` → show registration step 3.
Errors: `OTP_INVALID` (422) · `OTP_EXPIRED` (422: expired, used, or replaced by a newer code — ask for a new one) · `OTP_TOO_MANY_ATTEMPTS` (429) · `FORBIDDEN` (suspended account) · `CSRF_INVALID` (web).

### 3. `POST /auth/profile` — registration step 3 (also edits later) · 🔒
Body: `{"user_full_name": "Joyce Joseph", "business_name": "Duka la Joyce", "region_id": 2, "district_id": 8}`
(`business_name` and `district_id` optional; the district must belong to the region.) Returns the profile.

### `GET /auth/me` · 🔒 — the logged-in customer (call when the app starts)
### `POST /auth/logout` · 🔒 — revokes the token / ends the web session. Returns `null`.
### `GET /auth/csrf` — website only: returns `{csrf_token}` and starts the shop session.

### Profile object
```json
{"user_id": 1, "user_phone": "+255712000111", "user_full_name": "Joyce Joseph", "user_email": null,
 "user_avatar_url": null, "user_locale": "sw", "created_at": "2026-09-28T14:39:44+00:00",
 "business": {"business_name": "Duka la Joyce", "region_id": 2, "region_name": "Dar es Salaam",
              "district_id": 8, "district_name": "Ilala", "business_verification_status": "unverified"},
 "is_profile_complete": true}
```
`business` is `null` until step 3 is done. 🔒 endpoints answer `401 UNAUTHENTICATED` without a valid login → send the user to the phone screen.

## My account ("Wasifu") · 🔒 all
| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/me` | — | profile |
| PATCH | `/me` | any of `user_full_name`, `user_email` (empty = remove), `user_locale` (`sw`/`en`) — only what changes | profile · `VALIDATION_ERROR` (e.g. email used by another account) |
| PATCH | `/me/business` | `business_name` (optional), `region_id`, `district_id` (optional, must be in the region) | profile |
| DELETE | `/me` | `{"confirm": true}` | `null` — personal data removed, every login ended; the phone number can register again as a new account |

Avatar upload (`POST /me/avatar`) comes later, with the shared image uploader.

## Catalog — Nyumbani & Gundua (public, no login needed)
| Method | Path | Returns |
|---|---|---|
| GET | `/home` | `{banners[], top_categories[], best_sellers[], deals[], new_arrivals[], recently_ordered[]}` — one call for the whole Home tab (`recently_ordered` stays `[]` until orders exist) |
| GET | `/categories` | `[{category_id, category_name, category_slug, category_tagline, category_image_url, children: [same without children]}]` |
| GET | `/categories/{id}` | one top category with its `children` (chips) · 404 |
| GET | `/products` | paginated product cards (`meta`: page, per_page, total, last_page) |

`GET /products` query parameters (all optional): `category_id` (a top category includes its chips), `q` (search name/brand),
`collection` = `deals` \| `new` \| `best_sellers`, `min_price`, `max_price` (normal price, TZS), `max_moq`,
`sort` = `popular` (default) \| `newest` \| `price_asc` \| `price_desc`, `page` (from 1), `per_page` (default 20, max 50).

**Product card:**
```json
{"product_id": 1, "product_name": "Vaseline Petroleum Jelly 400ml", "product_slug": "vaseline-petroleum-jelly-400ml",
 "category_id": 2, "product_price": 5500, "product_price_from": 4400, "product_compare_at_price": null,
 "product_moq": 1, "product_unit_label": "pc", "product_in_stock": true, "product_image_url": null,
 "product_badge": "bestseller", "seller_name": "Shamba la Vipodozi", "seller_is_verified": true}
```
- `product_price` = normal price (smallest quantity); `product_price_from` = best wholesale price ("kuanzia").
- `product_compare_at_price` = old price, only when the product is on offer (then `product_badge` = `deal`).
- `product_badge`: `deal` \| `bestseller` \| `new` \| `null` (one at most).
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
- 404 `NOT_FOUND` if the product doesn't exist or is hidden.

### `GET /products/{id}/related` — up to 10 product cards from the same chip ("You may also like")

## Wishlist (heart button) · 🔒 all
| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/wishlist` | — | saved products as **product cards**, most recently saved first (hidden products left out) |
| GET | `/wishlist/ids` | — | `[7, 1]` — ids only, to fill in the hearts on cards |
| POST | `/wishlist` | `{"product_id": 7}` | `null` (saving twice is fine) · 404 if the product isn't in the shop |
| DELETE | `/wishlist/{product_id}` | — | `null` |

## Cart — Kikapu · 🔒 all · every call returns the whole priced cart
| Method | Path | Body |
|---|---|---|
| GET | `/cart` | — |
| POST | `/cart/items` | `{"product_id": 1, "quantity": 8}` — **adds** to what's already there |
| PATCH | `/cart/items/{product_id}` | `{"quantity": 12}` — sets the quantity |
| DELETE | `/cart/items/{product_id}` | — |
| DELETE | `/cart` | — empties the cart |
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
- `next_tier_hint` = "Ongeza pcs 16 upate TZS 4,700" (null at the best tier). `line_savings` / `savings` = "Unaokoa".
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
- `GET /checkout/options?address_id=3` → `{"delivery_methods": [{delivery_method_id, delivery_method_code, delivery_method_name, delivery_method_fee, delivery_days_min, delivery_days_max, region_id}], "payment_methods": ["cod"]}` — with `address_id`, only methods that reach that region ("Haraka" = Dar es Salaam only). `payment_methods` lists what is switched on (only `cod` until mobile money is connected).
- `POST /checkout/preview` `{"delivery_method_id": 1, "address_id": 3}` → `{line_count, piece_count, subtotal, savings, delivery_method_id, delivery_fee, discount_total, grand_total, delivery_days_min, delivery_days_max}`. Errors: `CART_EMPTY` (409), `CART_HAS_PROBLEMS` (409), `VALIDATION_ERROR` on `delivery_method_id`.

## Orders — Oda · 🔒 all
| Method | Path | Body / returns |
|---|---|---|
| POST | `/orders` | "Thibitisha Oda": `{"address_id", "delivery_method_id", "payment_method", "expected_total", "order_customer_note"}` + header **`Idempotency-Key: <random 8–64 chars, one per checkout>`** → the order (201). Turns the cart into an order and empties the cart. |
| GET | `/orders?group=active\|delivered\|all&page=1` | "Oda Zangu" (paginated, newest first). `active` = Zinazoendelea, `delivered` = Zimefika |
| GET | `/orders/{id}` (also `/orders/{id}/tracking`) | the order with items, `events` (timeline) and `delivery_agent` |
| POST | `/orders/{id}/cancel` | `{"order_cancel_reason"}` (optional) → the order. Only while `can_cancel` is true (before packing). |
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
 "delivery_agent": null}
```
- `order_status`: `pending_payment`, `confirmed`, `packed`, `dispatched`, `in_transit`, `delivered`, `cancelled`, `expired`. Cash on delivery starts at `confirmed`.
- `order_payment_status`: `unpaid`, `pending`, `paid`, `cod_pending` (cash to collect on delivery), `refunded`, `failed`, `cancelled` (the order was cancelled/expired before payment — nothing is owed).
- `events` = the "Fuatilia Oda" timeline (every status reached, with its time).
- `delivery_agent` (from `dispatched` on): `{delivery_agent_full_name, delivery_agent_phone, delivery_agent_photo_url}`.
- `address` is a **copy** made when ordering (it has no `address_id`).
- `GET /home` with a login now also fills `recently_ordered` ("Uliagiza Hivi Karibuni").

## Notifications (the bell) · 🔒 all
| Method | Path | Returns |
|---|---|---|
| GET | `/notifications?page=1` | paginated `[{notification_id, notification_type, notification_title, notification_body, notification_data: {"order_id": 1}, is_read, created_at}]`, newest first |
| GET | `/notifications/unread-count` | `{"unread_count": 2}` — the number on the bell |
| POST | `/notifications/{id}/read` | `null` |
| POST | `/notifications/read-all` | `null` |
Every order status change creates a notification (Kiswahili title + text). `notification_data.order_id` → open that order.
