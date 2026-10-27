# CHIMBO API Reference (built endpoints)

Base URL (local): `http://localhost/chimbo/api/v1` Â· Android emulator: `http://10.0.2.2/chimbo/api/v1`
This file lists only endpoints that **exist and are tested**. The full plan is in the blueprint Â§12.

## Conventions
- JSON in, JSON out. Keys = database column names (`user_phone`, `region_id` â€¦).
- Success: `{"success": true, "data": â€¦, "meta": {â€¦}}`
- Error: `{"success": false, "error": {"code": "â€¦", "message": "â€¦", "fields": {"field": "message"}}}` â€” `message` is Kiswahili, ready to show; `fields` appears on `VALIDATION_ERROR` (show each under its input).
- Dates: ISO-8601 UTC, e.g. `2026-09-28T14:39:44+00:00`.
- **Image and link URLs use the address you called the API with** (emulator â†’ `http://10.0.2.2/chimbo/â€¦`, phone on Wi-Fi â†’ `http://192.168.x.x/chimbo/â€¦`, production â†’ its domain). Unknown hosts fall back to `APP_URL`.
- **Mobile app login:** header `Authorization: Bearer <auth_token>`.
- **Website login:** session cookie (sent automatically by the browser) + header `X-CSRF-Token: <csrf_token>` on every POST/PATCH/DELETE.
- **Compression:** answers of 1 KB or more are gzip-compressed when the request says `Accept-Encoding: gzip` (browsers and Dart's `http`/`dio` do this by themselves). Home shrinks from ~8 KB to ~1.3 KB.
- **Photos** under `/media/` are cached for a year (`Cache-Control: immutable`) â€” a changed photo always gets a new file name, so never add `?v=` to image URLs.

## System
| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/health` | â€” | `{status, app, version, database, time}` |

## Support and legal pages (public)
| Method | Path | Answer |
|---|---|---|
| GET | `/support` | `{support_phone, support_whatsapp, support_whatsapp_url, support_hours, help_questions: [{question, answer}]}` (the same "Maswali ya mara kwa mara" as the website; "Ninalipaje?" follows the switched-on payment methods) â€” "Msaada": call button `tel:`, WhatsApp button = `support_whatsapp_url` |
| GET | `/app-images` | `[{app_image_slot, app_image_url, updated_at}]` â€” pictures staff uploaded for the app's fixed screens. Slots: `logo` (the logo uploaded in the admin â€” a square 512 px PNG for the splash, login and Home header; R-27), `onboarding_products`, `onboarding_wholesale`, `onboarding_delivery`, `auth_welcome`, `auth_verify`, `auth_business`, `order_success`. A slot missing from the list â†’ use the picture built into the app. A new upload always has a new URL (cache by URL) |
| GET | `/pages/terms` Â· `/pages/privacy` | `{page_title, page_body, page_body_html, updated_at}` â€” `page_body` remains plain text; `page_body_html` contains safe paragraphs, headings, bold, italic, underline, strikethrough, links (`http`, `https`, `mailto`, `tel`, same-site relative), block quotes and lists. Other names â†’ 404 `NOT_FOUND` |

## Locations
| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/regions` | â€” | `[{region_id, region_name}]` |
| GET | `/regions/{region_id}/districts` | â€” | `[{district_id, district_name}]` Â· 404 `NOT_FOUND` |

## Customer login (phone â†’ PIN; new numbers: phone â†’ SMS code â†’ create PIN â†’ business details)

**Screens and calls**
| Case | Calls |
|---|---|
| Phone number screen (everyone) | `POST /auth/start` â†’ `next_step` says which screen is next |
| `next_step: "otp"` (new number, or no PIN yet) | code already sent â†’ `POST /auth/otp/verify` â†’ `POST /auth/pin` (Tengeneza PIN) â†’ `POST /auth/profile` (business details) |
| `next_step: "pin"` | `POST /auth/pin/login` |
| `next_step: "pin_locked"` or "Umesahau PIN?" | `POST /auth/otp/request` â†’ `POST /auth/otp/verify` â†’ `POST /auth/pin` (new PIN, no old PIN needed) |
| Wasifu â†’ "Badilisha PIN" | `POST /auth/pin` with `current_pin` |

After **any** login (and on app start with `GET /auth/me`) check the profile in this order:
`user_has_pin: false` â†’ "Tengeneza PIN" Â· else `is_profile_complete: false` â†’ business details Â· else â†’ Home.

### `POST /auth/start` â€” the phone number screen
Body: `{"user_phone": "0712 345 678"}`
```json
{"user_phone": "+255712345678", "next_step": "pin"}
{"user_phone": "+255712345678", "next_step": "pin_locked"}
{"next_step": "otp", "user_phone": "+255712345678", "otp_expires_in_seconds": 300, "otp_resend_after_seconds": 60, "debug_otp_code": "849144"}
```
`pin_locked` = 5 wrong PINs: show "PIN imefungwaâ€¦" with a button to "Umesahau PIN?". For `otp` the SMS code has **already been sent** (same fields as `/auth/otp/request`).
Errors: `VALIDATION_ERROR` Â· `FORBIDDEN` (suspended account) Â· `RATE_LIMITED` (429, 30 numbers per hour per device) Â· the `/auth/otp/request` errors for `otp`.

### `POST /auth/pin/login` â€” the PIN screen
Body: `{"user_phone": "0712345678", "user_pin": "4826", "client": "app", "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android"}`
Send the PIN as **text** (keeps a leading zero). Same answer and `client` rules as `/auth/otp/verify` (web needs `X-CSRF-Token`).
Errors: `PIN_INVALID` (422, message says "Umebakiza majaribio N") Â· `PIN_LOCKED` (423 â†’ "Umesahau PIN?") Â· `FORBIDDEN` (suspended) Â· `RATE_LIMITED` Â· `CSRF_INVALID` (web).

### `POST /auth/pin` Â· ðŸ”’ â€” create, reset or change the PIN
Body: `{"user_pin": "4826", "user_pin_confirmation": "4826", "current_pin": "5930"}`
- **Tengeneza PIN** (no PIN yet) and **new PIN after "Umesahau PIN?"**: no `current_pin`. A device that logged in with an SMS code may set a new PIN without the old one **once, within 15 minutes**.
- **Badilisha PIN** (Wasifu): `current_pin` required; wrong tries count towards the 5-try lock.
- Rules: 4â€“6 digits; refused when easy to guess (`0000`, `1234`, `9876`, the end of the customer's phone number) â€” the message is in `fields.user_pin`. Mismatch â†’ `fields.user_pin_confirmation`.
- Replacing a PIN **logs out every other device** (app and website); this device stays logged in.
Returns the profile. Errors: `VALIDATION_ERROR` (`user_pin`, `user_pin_confirmation`, `current_pin`) Â· `PIN_INVALID` / `PIN_LOCKED` (wrong current PIN).

### `POST /auth/otp/request` â€” send the SMS code ("Umesahau PIN?", "Tuma tena")
Body: `{"user_phone": "0712 345 678"}` (any Tanzanian format)
```json
{"user_phone": "+255712345678", "otp_expires_in_seconds": 300, "otp_resend_after_seconds": 60,
 "debug_otp_code": "849144"}
```
`debug_otp_code` exists only in **"show code" mode** (`OTP_SHOW_CODE=true`) on a local or staging server â€” the app should auto-fill/show it when present. It is **never** sent when `APP_ENV=production`.
Errors: `VALIDATION_ERROR` (bad phone) Â· `OTP_RESEND_TOO_SOON` (429, message says how many seconds) Â· `RATE_LIMITED` (429) Â· `SMS_FAILED` (503).

### `POST /auth/otp/verify` â€” check the SMS code, log in
Body:
```json
{"user_phone": "0712345678", "otp_code": "849144", "client": "app",
 "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android"}
```
- `client: "app"` â†’ response includes `auth_token` (save in secure storage) and `auth_token_expires_at` (60 days, renewed on every use).
- `client: "web"` â†’ no token; the browser session is logged in. **Requires** header `X-CSRF-Token` (get it from `GET /auth/csrf` first).
```json
{"user": { â€¦profile, see belowâ€¦ }, "auth_token": "b27aâ€¦cdb", "auth_token_expires_at": "2026-11-27T14:39:44+00:00"}
```
Then follow the profile checks above (`user_has_pin`, then `is_profile_complete`).
Errors: `OTP_INVALID` (422) Â· `OTP_EXPIRED` (422: expired, used, or replaced by a newer code â€” ask for a new one) Â· `OTP_TOO_MANY_ATTEMPTS` (429) Â· `FORBIDDEN` (suspended account) Â· `CSRF_INVALID` (web).

### `POST /auth/profile` â€” business details step (also edits later) Â· ðŸ”’
Body: `{"user_full_name": "Joyce Joseph", "business_name": "Duka la Joyce", "region_id": 2, "district_id": 8}`
(`business_name` and `district_id` optional; the district must belong to the region.) Returns the profile.

### `GET /auth/me` Â· ðŸ”’ â€” the logged-in customer (call when the app starts)
### `POST /auth/logout` Â· ðŸ”’ â€” revokes the token / ends the web session. Returns `null`.
### `GET /auth/csrf` â€” website only: returns `{csrf_token}` and starts the shop session.

### Profile object
```json
{"user_id": 1, "user_phone": "+255712000111", "user_full_name": "Joyce Joseph", "user_email": null,
 "user_avatar_url": null, "user_locale": "sw", "created_at": "2026-09-28T14:39:44+00:00", "user_has_pin": true,
 "business": {"business_name": "Duka la Joyce", "region_id": 2, "region_name": "Dar es Salaam",
              "district_id": 8, "district_name": "Ilala", "business_verification_status": "unverified"},
 "is_profile_complete": true}
```
`business` is `null` until the business details step is done. `user_has_pin: false` â†’ show "Tengeneza PIN" first. ðŸ”’ endpoints answer `401 UNAUTHENTICATED` without a valid login â†’ send the user to the phone screen.

## My account ("Wasifu") Â· ðŸ”’ all
| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/me` | â€” | profile |
| PATCH | `/me` | any of `user_full_name`, `user_email` (empty = remove), `user_locale` (`sw`/`en`) â€” only what changes | profile Â· `VALIDATION_ERROR` (e.g. email used by another account) |
| PATCH | `/me/business` | `business_name` (optional), `region_id`, `district_id` (optional, must be in the region) | profile |
| DELETE | `/me` | `{"confirm": true}` | `null` â€” personal data removed, every login ended; the phone number can register again as a new account |

**Profile photo:**
- `POST /me/avatar` â€” **file upload** (`multipart/form-data`) with the field **`avatar`** (JPG, PNG or WEBP, up to 8 MB, at least 200 px) â†’ the profile with the new `user_avatar_url` (a 300 px WebP). A new photo replaces the old one. Error: `INVALID_IMAGE` (422) with a Kiswahili message.
- `DELETE /me/avatar` â†’ the profile with `user_avatar_url: null` (show initials again).

## Catalog â€” Nyumbani & Gundua (public, no login needed)
| Method | Path | Returns |
|---|---|---|
| GET | `/home` | `{banners[], top_categories[], best_sellers[], deals[], offers[], new_arrivals[], recently_ordered[]}` â€” `offers` = "Ofa za muda" (running offers, ending soonest first; hide the rail when empty) â€” one call for the whole Home tab (`recently_ordered` stays `[]` until orders exist) |

Banner anner_title, anner_subtitle, and anner_button_label are returned in the language from the Accept-Language header (en or sw). Admins enter both versions; banners without an English subtitle/button leave those fields empty.
| GET | `/categories` | `[{category_id, category_name, category_slug, category_tagline, category_image_url, children: [same without children]}]` |
| GET | `/categories/{id}` | one top category with its `children` (chips) Â· 404 |
| GET | `/products` | paginated product cards (`meta`: page, per_page, total, last_page) |
| POST | `/products/search-by-image` | `{detected: {brand, product_name, search_query, keywords, is_ai_active}, items: [product cards], total}` â€” camera search reads visible package words, searches the catalog by phrase and individual word, then returns matching product cards; accepts multipart `image` or JSON `{"image": "base64..."}`. `detected.visible_words` contains recognized label words |

`GET /products` query parameters (all optional): `category_id` (a top category includes its chips), `q` (search name/brand),
`collection` = `deals` \| `new` \| `best_sellers` \| `offers` (running offers, ending soonest first), `min_price`, `max_price` (normal price, TZS â€” offers are not taken into account), `max_moq`,
`sort` = `popular` (default) \| `newest` \| `price_asc` \| `price_desc`, `page` (from 1), `per_page` (default 20, max 50).

Image-search errors: if Gemini is configured but unavailable, the endpoint returns `503 VISUAL_SEARCH_UNAVAILABLE` instead of a successful search with zero matches.

**Product card:**
```json
{"product_id": 1, "product_name": "Vaseline Petroleum Jelly 400ml", "product_slug": "vaseline-petroleum-jelly-400ml",
 "category_id": 2, "product_price": 5500, "product_price_from": 4400, "product_compare_at_price": null,
 "product_moq": 1, "product_unit_label": "pc", "product_in_stock": true, "product_image_url": null,
 "product_offer": null, "product_badge": "bestseller", "seller_name": "Shamba la Vipodozi", "seller_is_verified": true}
```
- `product_price` = price for the smallest quantity; `product_price_from` = best wholesale price ("kuanzia"). **Both already include a running offer.**
- `product_compare_at_price` = the crossed-out price, or `null`: the normal price during an offer, otherwise the old price of a `deal`.
- `product_offer` = `{"product_offer_percent": 15, "product_offer_ends_at": "2026-10-12T17:00:00+00:00"}` while a time-limited offer runs, else `null` â†’ "Ofa âˆ’15%" badge and the countdown "Inaisha baada ya saa X" (count down to `product_offer_ends_at`; when it reaches zero, reload â€” the server decides).
- `product_badge`: `offer` \| `deal` \| `bestseller` \| `new` \| `null` (one at most, in that order).
- `product_image_url` is `null` until the admin uploads photos â†’ show a placeholder.

### `GET /products/{id}` â€” product page (public)
Everything on the card, plus:
```json
{"product_brand": "Vaseline", "product_description": null, "category_name": "Skin Care", "parent_category_name": "Cosmetics",
 "product_stock_quantity": 500, "product_delivery_days_min": 2, "product_delivery_days_max": 3,
 "tiers": [{"tier_min_quantity": 1, "tier_unit_price": 5500}, {"tier_min_quantity": 6, "tier_unit_price": 5000},
           {"tier_min_quantity": 24, "tier_unit_price": 4700}, {"tier_min_quantity": 60, "tier_unit_price": 4400}],
 "images": ["http://â€¦/media/products/1/â€¦-medium.webp", "â€¦"],
 "gallery": [{"product_image_id": 3, "product_image_thumb_url": "â€¦-thumb.webp", "product_image_medium_url": "â€¦-medium.webp",
              "product_image_large_url": "â€¦-large.webp", "product_image_is_primary": true}]}
```
- `images`: the photos **in display order** (medium size) â€” big photo + thumbnail strip. Empty until photos are uploaded.
- `gallery`: every size of each photo (use `thumb` for the thumbnail strip, `large` for zoom).
- Tier ranges for display: each tier runs up to the next tier's `tier_min_quantity âˆ’ 1`; the last one is "60+".
- During an offer every tier's `tier_unit_price` is the offer price and the tier also has `tier_price_before_offer` (show it crossed out). Work out stepper totals from `tier_unit_price` as before.
- 404 `NOT_FOUND` if the product doesn't exist or is hidden.

### `GET /products/{id}/related` â€” up to 10 product cards from the same chip ("You may also like")

## Wishlist (heart button) Â· ðŸ”’ all
| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/wishlist` | â€” | saved products as **product cards**, most recently saved first (hidden products left out) |
| GET | `/wishlist/ids` | â€” | `[7, 1]` â€” ids only, to fill in the hearts on cards |
| POST | `/wishlist` | `{"product_id": 7}` | `null` (saving twice is fine) Â· 404 if the product isn't in the shop |
| DELETE | `/wishlist/{product_id}` | â€” | `null` |

## Cart â€” Kikapu Â· ðŸ”’ all except `/cart/preview` Â· every call returns the whole priced cart
| Method | Path | Body |
|---|---|---|
| GET | `/cart` | â€” |
| POST | `/cart/items` | `{"product_id": 1, "quantity": 8}` â€” **adds** to what's already there |
| PATCH | `/cart/items/{product_id}` | `{"quantity": 12}` â€” sets the quantity |
| DELETE | `/cart/items/{product_id}` | â€” |
| DELETE | `/cart` | â€” empties the cart |
| POST | `/cart/preview` | **public (no login)** â€” website guests: prices the cart kept in the browser **without saving anything**. Body `{"items": [{"product_id": 1, "quantity": 8}]}` (max 100 items; the same product twice is added together) â†’ **the same shape as `GET /cart`**. Below-MOQ / not-enough-stock lines come back with `line_problem` (not an error); unknown or hidden products are left out and listed in `warnings`. Invalid body â†’ `VALIDATION_ERROR` (`fields` like `items.0.quantity`). No CSRF check needed (read-only); limit 600 calls/hour per IP. |
| POST | `/cart/merge` | website after login: `{"items": [{"product_id": 1, "quantity": 8}]}` â†’ cart + `skipped_items[{product_id, message}]` (bigger quantity wins) |

```json
{"groups": [{"category_name": "Cosmetics", "items": [{
    "product": { â€¦product cardâ€¦ }, "cart_quantity": 8,
    "unit_price": 5000, "tier_min_quantity": 6, "line_total": 40000, "line_savings": 4000,
    "next_tier_hint": {"extra_quantity": 16, "unit_price": 4700},
    "line_problem": null, "available_quantity": 500}]}],
 "summary": {"line_count": 1, "piece_count": 8, "subtotal": 40000, "savings": 4000, "can_checkout": true},
 "warnings": []}
```
- Prices are **always calculated by the server** from the tiers â€” the app never sends a price.
- `next_tier_hint` = "Ongeza pcs 16 upate TZS 4,700" (null at the best tier). `line_savings` / `savings` = "Unaokoa" (tiers + offer, against the normal price).
- Each line also has `offer_percent` (0 = no offer) â†’ "Ofa âˆ’15%" on the line. Order items keep it as `order_item_offer_percent`.
- `line_problem` (after stock/MOQ changed): `not_enough_stock` (lower to `available_quantity`), `out_of_stock`, `below_moq`, or null. Checkout is blocked while `can_checkout` is false.
- `warnings`: products that left the shop were removed â€” show the message once.
- Errors: `VALIDATION_ERROR` on `quantity` (below MOQ: "Kiwango cha chini cha kuagiza ni 6 (MOQ).") Â· `OUT_OF_STOCK` (409) Â· `NOT_FOUND`.

## Addresses Â· ðŸ”’ all
| Method | Path | Body / returns |
|---|---|---|
| GET | `/addresses` | list, default first |
| POST | `/addresses` | `address_recipient_name`, `address_phone`, `region_id`, `district_id` (optional), `address_street`, `address_landmark` (optional), `address_is_default` (optional) â†’ the address (201). The first one becomes the default. |
| PATCH | `/addresses/{id}` | same body â†’ the address |
| POST | `/addresses/{id}/default` | â†’ all addresses |
| DELETE | `/addresses/{id}` | â†’ remaining addresses (if it was the default, the newest one becomes default) |

Address: `{address_id, address_recipient_name, address_phone, region_id, region_name, district_id, district_name, address_street, address_landmark, address_is_default}`.

## Checkout Â· ðŸ”’ all
- `GET /checkout/options?address_id=3` â†’ `{"delivery_methods": [{delivery_method_id, delivery_method_code, delivery_method_name, delivery_method_fee, delivery_fee_needs_confirmation, delivery_fee_paid_to_rider, delivery_method_is_pickup, delivery_days_min, delivery_days_max, delivery_method_area, region_id}], "pickup_point": {"pickup_address", "pickup_hours"}, "payment_methods": ["mpesa", "cod"], "payment_method_details": [ â€¦payment method objectsâ€¦ ]}` â€” with `address_id`, only methods that reach that region ("Haraka" = Dar es Salaam only). `payment_methods` = the active codes staff configured.
- **Payment method object** (also `GET /payment-methods`, public): `{payment_method_code, payment_method_name, payment_method_type (mobile_money | bank | cash), payment_method_account_name, payment_method_account_number (Lipa Namba / phone / bank account), payment_method_bank_name, payment_method_instructions, payment_method_icon_url}`. The icon URL is `null` when no logo is uploaded; customer clients show their built-in icon for the method type. An order saved with a removed method returns a safe placeholder with `payment_method_is_available: false`; customers cannot submit payment through that method.
- `GET /products/{id}` also returns `delivery_options` (every delivery option switched on, the same shape as above, without a region) â€” show them on the product page ("Bolt (Dar es Salaam): leo au kesho Â· Usafiri wa mikoani: siku 2â€“5 Â· Nitachukua mwenyewe dukani: bure"). Days read 0â€“0 "leo", 0â€“1 "leo au kesho", 1â€“1 "kesho", else "siku minâ€“max".
- **Dar by Bolt and pickup (R-26):** `delivery_fee_paid_to_rider` true ("Bolt (Dar es Salaam)") â†’ the customer pays the rider directly: show "Unamlipa dereva" (never "Bure" or an amount) with "Usafiri unamlipa dereva ukipokea mzigo â€” hauko kwenye jumla."; the fee is 0 in every total. `delivery_method_is_pickup` true ("Nitachukua mwenyewe dukani", every region, free) â†’ show `pickup_point` (address Â· hours) under it.
- **Upcountry transport (R-25):** `delivery_method_area` is `all`, `only` (just `region_id`) or `except` (every region but `region_id`); today Dar es Salaam gets Bolt, every other region gets "Usafiri wa mikoani", and every region can choose pickup. When `delivery_fee_needs_confirmation` is true the fee is **the region's estimate** â€” show it as "~TZS 15,000" with "Bei ni makadirio. Tutapima mzigo na kukuthibitishia bei kabla hujalipa." `delivery_method_fee` = `null` means the region has no estimate yet â†’ show "Bei itathibitishwa" (the order is placed with transport 0 and staff set the price). Such an option needs `address_id`, and cash on delivery is refused for it (`VALIDATION_ERROR` on `payment_method`) â€” hide cash methods while it is chosen.
- `POST /checkout/preview` `{"delivery_method_id": 1, "address_id": 3}` â†’ `{line_count, piece_count, subtotal, savings, delivery_method_id, delivery_fee, delivery_fee_needs_confirmation, delivery_fee_paid_to_rider, delivery_method_is_pickup, discount_total, grand_total, delivery_days_min, delivery_days_max}` (with `delivery_fee_needs_confirmation` true, label the totals "Usafiri (makadirio)" / "Jumla (makadirio)"). Errors: `CART_EMPTY` (409), `CART_HAS_PROBLEMS` (409), `VALIDATION_ERROR` on `delivery_method_id`.

## Orders â€” Oda Â· ðŸ”’ all
New order numbers are sequential within each shop prefix and Tanzania calendar month: for example, `CHS2610-0001` for Shamba la Vipodozi, `CHM2610-0001` for Mrembo Muuza Urembo, and `CHB2610-0001` for an order containing both shops. The four-digit counter resets each month; existing order numbers remain valid.
| Method | Path | Body / returns |
|---|---|---|
| POST | `/orders` | "Thibitisha Oda": `{"address_id", "delivery_method_id", "payment_method", "expected_total", "order_customer_note"}` + header **`Idempotency-Key: <random 8â€“64 chars, one per checkout>`** â†’ the order (201). Turns the cart into an order and empties the cart. |
| GET | `/orders?group=active\|delivered\|all&page=1` | "Oda Zangu" (paginated, newest first). `active` = Zinazoendelea, `delivered` = Zimefika |
| GET | `/orders/{id}` (also `/orders/{id}/tracking`) | the order with items, `events` (timeline) and `delivery_agent` |
| POST | `/orders/{id}/cancel` | `{"order_cancel_reason"}` (optional) â†’ the order. Only while `can_cancel` is true (before packing). |
| GET | `/orders/{id}/receipt` | "Pakua Risiti": the receipt as a **PDF file** (`Content-Type: application/pdf`, file name `Risiti-{order_number}.pdf`) â€” download and open/share it. Own orders only (404 otherwise). |
| POST | `/orders/{id}/payment` | **"Nimelipa"**: `{"payment_payer_account": "0712345678", "payment_reference": "QJK3X7ABC1"}` â†’ the order. Only while `payment.can_submit_payment` is true. For `bank`, `payment_payer_account` is the account / name paid from. |
| POST | `/orders/{id}/payment-method` | **"Badilisha njia ya malipo"**: `{"payment_method": "airtel_money"}` â†’ the order. Only while `payment.can_change_payment_method` is true. To `cod`: same cash limit as checkout, and the order becomes `confirmed` / `cod_pending` at once. The time to pay does not restart. |
| POST | `/orders/{id}/reorder` | "Agiza Tena" â†’ the cart + `skipped_items` (current prices; unavailable items skipped) |

Place-order errors: `PRICE_CHANGED` (409 â€” the message has the new total; show Hakiki again), `CART_EMPTY`, `CART_HAS_PROBLEMS` (409), `VALIDATION_ERROR` on `payment_method` (not enabled, or cash-on-delivery above the limit) or `delivery_method_id`.
With the same `Idempotency-Key`, a repeated request (double tap, retry after a timeout) returns the first order â€” never a second one.

**Order:**
```json
{"order_id": 1, "order_number": "CHB765068", "order_status": "confirmed", "order_channel": "app",
 "order_payment_method": "cod", "order_payment_status": "cod_pending",
 "order_subtotal": 91000, "order_delivery_fee": 5000, "order_discount_total": 0, "order_total": 96000,
 "order_placed_at": "2026-09-29T13:10:00+00:00", "order_estimated_delivery_date": "2026-10-02",
 "order_delivered_at": null, "order_cancelled_at": null, "order_cancel_reason": null, "order_customer_note": null,
 "order_expires_at": null, "can_cancel": true, "item_count": 2, "piece_count": 14,
 "delivery_method": {"delivery_method_id": 1, "delivery_method_name": "Standard (siku 2â€“3)", "delivery_method_fee": 5000,
                     "delivery_days_min": 2, "delivery_days_max": 3,
                     "delivery_fee_status": "fixed", "delivery_fee_estimate": null, "delivery_fee_note": null,
                     "delivery_fee_paid_to_rider": false, "delivery_method_is_pickup": false},
 "pickup_point": null,
 "address": {"address_recipient_name": "Joyce Joseph", "address_phone": "+255712345678", "region_id": 2,
             "region_name": "Dar es Salaam", "district_name": null, "address_street": "Kariakoo, Lumumba St", "address_landmark": null},
 "items": [{"order_item_id": 1, "product_id": 1, "product_name": "Vaseline Petroleum Jelly 400ml", "product_image_url": null,
            "order_item_unit_label": "pc", "order_item_quantity": 8, "order_item_unit_price": 5000,
            "order_item_tier_min_quantity": 6, "order_item_line_total": 40000}],
 "events": [{"order_status": "confirmed", "status_note": null, "created_at": "2026-09-29T13:10:00+00:00"}],
 "delivery_agent": null,
 "payment": {"payment_method": { â€¦payment method objectâ€¦ }, "payment_amount": 96000, "payment_note_hint": "CHB765068",
             "can_submit_payment": false, "can_change_payment_method": false, "payment_deadline_passed": false,
             "latest_payment": {"payment_id": 4, "payment_payer_account": "+255712000111", "payment_reference": "QJK3X7ABC1",
                                "payment_status": "submitted", "payment_review_note": null, "created_at": "â€¦"}}}
```
- `payment.is_balance_payment` (R-29): staff added to the paid order â€” `payment.payment_amount` is only the balance ("Salio la kulipa"), `payment.amount_paid` what's in; the same "Nimelipa" form pays it. `payment.refund_due` > 0: CHIMBO will send that much back ("Tutakurudishia"). `order_payment_status` can be `balance_due`.
- `order_discount_total` / `order_discount_note`: a discount staff gave and why (R-29) â€” show "Punguzo (reason)" in the totals when it's above 0.
- `order_status`: `pending_payment`, `confirmed`, `packed`, `dispatched`, `in_transit`, `delivered`, `cancelled`, `expired`. Cash on delivery starts at `confirmed`. An `expired` order offers "Agiza Tena" (`POST /orders/{id}/reorder`, today's prices); staff can also reactivate it (R-28) â€” it then comes back as `pending_payment` / `unpaid` with today's prices, a new `order_expires_at` and a timeline row "Imerejeshwa na CHIMBO" (notification "Oda imerejeshwa").
- `order_payment_status`: `unpaid`, `pending`, `paid`, `cod_pending` (cash to collect on delivery), `refunded`, `failed`, `cancelled` (the order was cancelled/expired before payment â€” nothing is owed).
- `events` = the "Fuatilia Oda" timeline (every status reached, with its time).
- `delivery_agent` (from `dispatched` on): `{delivery_agent_full_name, delivery_agent_phone, delivery_agent_photo_url}`.
- `address` is a **copy** made when ordering (it has no `address_id`).
- `GET /home` with a login now also fills `recently_ordered` ("Uliagiza Hivi Karibuni").

**Paying by mobile money or bank (checked by CHIMBO staff until a provider is connected)**
1. The order is placed as `pending_payment` / `unpaid` with `order_expires_at` (the admin's "time to pay" setting, 24 hours by default; orders staff create have none). After a rejected payment the customer gets at least that time again from the rejection.
2. `payment.payment_deadline_passed` is true when an unpaid mobile-money / bank order's deadline has passed but the expiry job has not yet changed its order status. Payment and payment-method changes are blocked during this interval; show that the deadline passed instead of asking the customer to pay.
3. Order screen, while `payment.can_submit_payment`: show the pay-to box â€” "Lipa **TZS {payment_amount}** kwa {payment_method_name}: **{payment_method_account_number}** ({payment_method_account_name})", the bank name for banks, `payment_method_instructions`, and "Andika **{payment_note_hint}** kama maelezo/kumbukumbu" â€” then the **"Nimelipa"** form (number paid from + confirmation code from the SMS).
4. After sending: `order_payment_status` = `pending`, `latest_payment.payment_status` = `submitted` â†’ show "Tunakagua malipo yako". Cancelling is blocked while it is checked (`can_cancel` false).
5. Staff confirm â†’ order `confirmed`, payment `paid` (notification "Oda imethibitishwa"). Staff reject â†’ payment `unpaid` again, `latest_payment.payment_status` = `rejected` with `payment_review_note` (show it), notification type `payment`, and the form is shown again.
6. A paid order that is cancelled is refunded by staff â†’ `order_payment_status` = `refunded` (notification "Pesa imerudishwa").

**Bolt (paid to the rider) and pickup orders (R-26)**
- `delivery_method.delivery_fee_paid_to_rider`: the transport line reads "Unamlipa dereva"; `order_total` is the products only (that's all CHIMBO is paid).
- `delivery_method.delivery_method_is_pickup`: show `pickup_point` {pickup_address, pickup_hours} instead of the delivery address and the expected date. The same statuses, other words: `packed` = "**Tayari kuchukuliwa**" ("Oda yako iko tayari â€” njoo uichukue dukani."), `delivered` = "**Imechukuliwa**"; the timeline is Imethibitishwa â†’ Tayari kuchukuliwa â†’ Imechukuliwa (never dispatched / in transit). Notification when ready: "Oda #â€¦ iko tayari. Njoo uichukue: {address} ({hours})."

**Upcountry transport â€” the price is confirmed before paying (R-25)**
- `delivery_method.delivery_fee_status`: `fixed` (nothing to confirm) Â· `awaiting_confirmation` Â· `confirmed`.
- `awaiting_confirmation`: `order_delivery_fee` is the estimate (`delivery_fee_estimate`, 0 = none), `order_expires_at` is null and `payment.can_submit_payment` is false. Instead of the pay box show **"Tunapima mzigo wako"** â€” "Tunathibitisha bei ya usafiri hadi {region}. Makadirio ni TZS â€¦; mzigo ukiwa mkubwa bei inaweza kuongezeka. Usilipe bado â€” tutakujulisha bei kamili, kisha utalipa hapa." Totals: "Usafiri (makadirio)" / "Jumla (makadirio)". The customer may still cancel. `POST /orders/{id}/payment` answers `DELIVERY_FEE_NOT_CONFIRMED` (409).
- Staff confirm (Admin â†’ order â†’ Transport price) â†’ `confirmed`: `order_delivery_fee` and `order_total` are final, `delivery_fee_note` says why it changed (show it under the transport line), `order_expires_at` starts now, and the normal pay box appears. Notification (type `order_status`): "Usafiri umethibitishwa â€” Usafiri wa oda #CHBâ€¦: 20,000 TZS (Mzigo mkubwa, katoni 6). Jumla: 60,000 TZS â€” lipa sasa."
- When the order is placed the customer gets "Oda imepokelewa â€” Tunapima mzigo wa oda #CHBâ€¦ ili kuthibitisha bei ya usafiri. Tutakujulisha, kisha utalipa."

`POST /orders/{id}/payment` errors: `VALIDATION_ERROR` (`payment_payer_account`: not a Tanzanian mobile number; `payment_reference`: 6â€“30 letters/digits, or already used) Â· `PAYMENT_UNDER_REVIEW` (409, already sent) Â· `PAYMENT_TIME_OVER` (409) Â· `PAYMENT_NOT_EXPECTED` (409: cash order or not waiting for payment) Â· `NOT_FOUND`.
`POST /orders/{id}/cancel` can also answer `PAYMENT_UNDER_REVIEW` (409).
`POST /orders/{id}/payment-method` errors: `VALIDATION_ERROR` on `payment_method` (switched off, or cash above the limit) Â· `PAYMENT_UNDER_REVIEW` Â· `PAYMENT_METHOD_LOCKED` (409: cash order, paid, or not waiting for payment) Â· `PAYMENT_TIME_OVER`.

## Malipo yangu Â· ðŸ”’
`GET /payments?page=1` â†’ the customer's own payments, newest first, 20 per page (`meta` as other lists):
`[{payment_id, order_id, order_number, payment_method_code, payment_method_name, payment_amount, payment_payer_account,
payment_reference, payment_status (submitted | confirmed | rejected | initiated | pending | successful | failed | cancelled | expired | refunded), payment_review_note, created_at, payment_reviewed_at}].
Every "Nimelipa" is listed (also rejected ones, with the reason), payments staff recorded, and cash collected on delivery
(`payment_method_code` `cod`, reference `CASH-{order_number}`). Show "Njia za malipo tunazokubali" below it from `GET /payment-methods`.

## Notifications (the bell) Â· ðŸ”’ all
| Method | Path | Returns |
|---|---|---|
| GET | `/notifications?page=1` | paginated `[{notification_id, notification_type, notification_title, notification_body, notification_data: {"order_id": 1, "translations": {"sw": {"title": "â€¦", "body": "â€¦"}, "en": {"title": "â€¦", "body": "â€¦"}}}, is_read, created_at}]`, newest first. `notification_title` and `notification_body` remain the Kiswahili fallback; the app uses the matching translation when present. Legacy rows without translations continue to show their saved text. |
| GET | `/notifications/unread-count` | `{"unread_count": 2}` â€” the number on the bell |
| POST | `/notifications/{id}/read` | `null` |
| POST | `/notifications/read-all` | `null` |
Every order status change creates a notification with Kiswahili and English copies. `notification_data.order_id` â†’ open that order. `notification_data.translations` is optional for older notifications and contains language-keyed `title` and `body` strings for `sw` and `en`.

## Mobile push registrations Â· ðŸ”’ app login
| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/devices` | `{"device_token": "FCM token", "device_platform": "android", "device_language": "en"}` (`android` or `ios`; language `sw` or `en`, optional for older clients and defaults to `sw`) | `null`; registers or refreshes this installation and language preference for the current login session |
| DELETE | `/devices` | `{"device_token": "FCM token"}` | `null`; removes this installation on logout |

The device token and selected language are saved against the current auth session. Revoked or expired sessions are excluded from push delivery. When `FCM_SERVICE_ACCOUNT_FILE` points to a readable Firebase service-account JSON file, individual in-app notifications are queued and attempted through FCM immediately after their database transaction commits. Each device receives the matching translated system alert when a copy exists; older or untranslated notifications use their stored fallback text. Bulk notifications are saved and queued in one transaction, then the scheduled CLI worker processes recipients in batches of 50 so a broadcast does not hold the admin request open. Failed pushes are marked failed for staff to retry in Push Queue; unregistered tokens are removed. The worker also recovers pending work interrupted before an immediate attempt. Push data includes string `notification_id`, `notification_type`, scalar `notification_data` fields, and flattened `notification_title_en` / `notification_body_en` and `notification_title_sw` / `notification_body_sw` for foreground localization.
