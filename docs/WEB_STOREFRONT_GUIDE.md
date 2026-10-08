# Web storefront guide

For the agent building the customer website. Read `AGENTS.md` and `docs/CODING_STANDARDS.md` first — this guide adds the web-specific details.

## 1. What to build
A modern, Shopify-style customer website for CHIMBO: clean and premium, big product photos, sticky header with search,
slide-out cart drawer, quick-add on product cards, sticky "add to cart" bar on the product page, smooth AJAX updates
(no page reloads for actions), skeleton loaders, small tasteful animations. Inspired by modern stores — **copy no brand, layout or assets.**
It must show CHIMBO's wholesale features: tier price table ("Nunua zaidi, lipa kidogo"), MOQ, "Unaokoa TZS …", "Agiza Tena".
Mobile-first: on phones it feels like the app (bottom navigation: Nyumbani, Gundua, Kikapu, Oda, Wasifu); on desktop a full store layout.
Customer text in **Kiswahili**.

## 2. How it works (agreed architecture)
- **PHP renders each page** (fast first load, readable by Google), then **plain JavaScript `fetch()` calls the existing API** at `/chimbo/api/v1` for every action.
- **Normal links** move between pages; **AJAX** for add to cart, quantities, filters, "load more", wishlist, checkout steps, cancel/reorder.
- **Bootstrap 5** and **Bootstrap Icons** are already in `assets/vendor/`. No React/Vue/jQuery, no build tools, no CDNs.
- The API is finished and tested — **don't change `classes/`, `api/` or `database/`**. Missing something? Tell the user.

## 3. Files
```
index.php  category.php  product.php  search.php  cart.php  checkout.php  order_success.php
orders.php  order.php  wishlist.php  account.php  login.php  help.php  terms.php  privacy.php  404.php
includes/   head.php  header.php  footer.php  bottom_nav.php  cart_drawer.php   (shared layout; the folder is blocked from direct access)
assets/css/ theme.css (brand tokens only)  app.css (components)
assets/js/  chimbo.js (shared helper)  cart.js  catalog.js  product.js  checkout.js  auth.js  orders.js
```
Every page starts with `require __DIR__ . '/bootstrap.php';` and `CustomerSession::start();`, prints the CSRF token for JavaScript
(`<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">`), and escapes every value with `e()`.
Pretty URLs (e.g. `/chimbo/p/{product_slug}`) may be added to the root `.htaccess` — keep its existing security rules.

## 4. Calling the API (all details in `docs/API_REFERENCE.md`)
- Every answer: `{"success": true, "data": …, "meta": …}` or `{"success": false, "error": {"code", "message", "fields"}}`.
  Show `error.message` as-is (it's Kiswahili); show `error.fields` under each input.
- **One helper for everything — `assets/js/chimbo.js`.** Pages never call `fetch` directly. It must: add `X-CSRF-Token` (from the meta tag)
  to every POST/PATCH/DELETE, send/parse JSON, show toasts for errors, handle `401` (go to login), and offer small helpers
  (`formatTzs()`, `debounce()`, `setLoading(button)`, skeletons).
- **Login (phone + code, no password):** `POST /auth/otp/request` → `POST /auth/otp/verify` with `"client": "web"` (starts the website session; no token).
  In development the first answer contains `debug_otp_code` — auto-fill the code boxes with it.
- **Guest shopping (decided):** guests browse, see prices and add to cart **without an account**; keep the guest cart in `localStorage`.
  Ask for login at checkout (and for orders, wishlist, profile). Right after login send the guest cart to `POST /cart/merge`, then clear `localStorage`.
- **Prices are always the server's.** The product page may preview the tier price from the `tiers` it received, but cart and checkout totals come from the API.
- **Placing an order:** `POST /checkout/preview` → show totals → `POST /orders` with an `Idempotency-Key` header (one random key per checkout)
  and `expected_total`. On `PRICE_CHANGED` show the new total and ask again.
- Receipts: link to `GET /orders/{id}/receipt` (PDF). Images: use `product_image_url` / `gallery`; show a placeholder when `null`.

## 5. JavaScript rules (from CODING_STANDARDS §4)
`const`/`let`, `async`/`await`; one file per page/feature, nothing in the global scope except a small `CHIMBO` namespace from `chimbo.js`;
no inline `onclick` — use `addEventListener` + `data-*`; build DOM with `textContent` or escaped templates, **never insert API data as raw HTML**;
names that say what they do (`renderProductCard`, `updateCartBadge`).

## 6. CSS rules (from CODING_STANDARDS §5)
Brand colours, radii, spacing and fonts as CSS variables in `theme.css` (`--chimbo-green: #0E3B2A; --chimbo-orange: #F39200; --chimbo-cream: #FBF6EE` …);
never hard-code hex values elsewhere. Bootstrap utilities first; custom classes named by component (`.product-card`, `.tier-table`, `.cart-drawer`). Mobile-first media queries.

## 7. Every page must have
Loading state (skeletons) · empty state (friendly text + one action) · error state (message + "Jaribu tena") · works on a 360 px wide phone ·
keyboard-accessible buttons/links · `<title>` and meta description · no console errors · no PHP warnings.

## 8. Definition of done (check before telling the user a step is finished)
- [ ] Only storefront files changed (see `AGENTS.md` §2)
- [ ] `C:\xampp\php\php.exe -l` passes for every changed PHP file
- [ ] Backend tests still pass: `C:\xampp\php\php.exe vendor\bin\phpunit`
- [ ] Tried in the browser at `http://localhost/chimbo/` on phone and desktop widths
- [ ] Re-read against `docs/CODING_STANDARDS.md` (names, no duplication, no dead code, escaping, CSRF)
- [ ] Entry added at the top of "Session log" in `docs/PROGRESS.md`
- [ ] Nothing committed unless the user asked
