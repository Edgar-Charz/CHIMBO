# CHIMBO — Coding Standards

Clean code is a requirement for CHIMBO, on the backend **and** the front-ends. Every change is checked against this list before it is committed.

## 1. General (all code)

- **Readable first.** Code is read far more than it is written. Prefer clear over clever.
- **Descriptive names, no abbreviations.** `calculateLineTotal()`, `$unit_price`, `$matches` — not `calc()`, `$up`, `$m`. Common short names are fine: `$stmt`, `$db`, `$id`, `$e` (exceptions), `$auth`, `$i` in a simple loop.
- **Small, single-purpose functions and classes.** One reason to change. If a function needs "and" to describe it, split it.
- **No duplication (DRY).** If the same logic appears twice, extract it. Rules like pricing live in exactly one place.
- **No magic values.** Statuses, limits and roles are named constants or settings, not repeated strings/numbers.
- **Fail loudly and early.** Validate input at the edges; throw clear exceptions; never silently ignore errors.
- **Comments explain *why*, not *what*.** Class/method doc comments say what something is for; inline comments only for non-obvious reasons.
- **No dead code.** No commented-out code, unused variables, debug prints or leftover test files in commits.
- **Consistent formatting.** Follow `.editorconfig`; format before committing.
- **Same pattern everywhere.** Once a feature is built one way, the next feature follows the same structure.

## 2. PHP backend, admin and web pages

- **PSR-12 layout**: 4-space indent, braces on their own line for classes/methods, one class per file, file name = class name. 
- **Naming style (agreed):**
  - Classes: `PascalCase` — `Product`, `OrderItem`, `ApiException`
  - Methods/functions: `camelCase` — `getProductById()`, `addItemToCart()`
  - Variables, parameters and properties: `snake_case` (like SCMRS and the database) — `$user_id`, `$unit_price`, `$this->transaction_depth`
  - Constants: `UPPER_SNAKE_CASE` — `ORDER_STATUS_PENDING_PAYMENT`
  - Web and admin page files: `snake_case.php` — `order_success.php`, `manage_products.php`
  - JSON keys sent by the API: **the same as the database column names** — `user_id`, `user_phone`, `product_name` (no renaming layer).
- **Type everything**: typed properties, parameter types and return types (`: array`, `: ?array`, `: void`, `: Response`).
- **Structure: classes + helpers + API files (not full MVC)**:
  - *Domain classes* (`classes/User.php`, `Product.php`, `Cart.php`, `Order.php`, `Payment.php` …) — like SCMRS: each owns its SQL, **its input validation (using the `Validator` helper)** and business rules for one part of the shop, so the API, admin and website get the same checks written once. Methods have clear names (`getProductById`, `addItemToCart`). The API, the website and the admin all call these same classes.
  - *Helpers* (`classes/core/Validator.php`, `classes/Phone.php`, later `Money`, `Pricing`, `ImageUploader` …) — small reusable tools that keep the domain classes short. Anything used by two classes becomes a helper.
  - *API files* (`api/endpoints/auth.php`, `products.php` …) — one file per module; each endpoint is a short block: read the request → call a class method → return a `Response`. No SQL and no validation rules in API files.
- **The database connection comes in through the constructor** (`new Product(Database::instance())`), like SCMRS.
- **Errors**: throw `ApiException` for client errors; let unexpected errors reach the global handler; never `die()`/`exit` in classes.
- **Transactions** for anything touching money, stock or orders — via `$db->transaction(...)`.
- **SQL**: uppercase keywords, one clause per line for long queries, **named placeholders** (`:user_phone`), select only needed columns (no `SELECT *` for data sent to the API).
- **Web/admin pages**: logic at the top, HTML below; escape all output with `e()` (`htmlspecialchars`); shared layout in `includes/` partials; no inline `<style>` or `<script>` blocks beyond tiny page config.
- **Short methods**: aim for ≤ 30 lines; extract private helpers when longer.

## 3. Database naming

- Table names: plural, lowercase, snake_case — `users`, `products`, `order_items`.
- Primary key: `<singular>_id` — `users.user_id`, `products.product_id`, `order_items.order_item_id`.
- Columns: prefixed with the singular table name so every column is clear on its own, even in joins — `user_phone`, `user_full_name`, `user_email`, `product_name`, `product_moq`, `order_total`.
- Foreign keys: the same name as the key they point to — `orders.user_id` → `users.user_id`.
- Only the standard timestamps are not prefixed: `created_at`, `updated_at`, `deleted_at`.
- Passwords are only ever stored hashed, and the name says so: `admin_password_hash` (customers log in with phone + OTP, so `users` has no password).
- Booleans read as yes/no questions: `product_is_active`, `seller_is_verified`.

## 4. JavaScript (web storefront)

- Plain modern JS (ES2020+): `const`/`let` (never `var`), arrow functions, `async`/`await` with `fetch`.
- **One shared helper** (`assets/js/chimbo.js`) for API calls, CSRF, toasts and loading states — pages never call `fetch` directly.
- One file per page/feature, wrapped so nothing leaks into the global scope; functions named by what they do (`renderProductCard`, `updateCartBadge`).
- No inline `onclick=""` handlers — use `addEventListener` and `data-*` attributes.
- Build DOM safely (`textContent`, or escaped templates) — never insert unescaped API data as HTML.

## 5. CSS

- Brand colours, radii and spacing as CSS variables in `theme.css`; never hard-code hex values elsewhere.
- Bootstrap utilities first; custom classes only for CHIMBO components, named by component (`.product-card`, `.tier-table`).
- Mobile-first media queries.

## 6. Flutter / Dart (mobile app)

- `dart format` and `flutter analyze` must be clean (lints from `flutter_lints`, plus stricter rules in `analysis_options.yaml`).
- Feature-first folders: `data/` (API + repository + models), `application/` (Riverpod controllers), `presentation/` (screens + widgets).
- **Widgets only display and react.** No HTTP calls, no business rules, no JSON parsing inside widgets.
- Small widgets: extract a widget when a `build()` method grows beyond ~60 lines or a piece is reused.
- `const` constructors wherever possible; no hard-coded colours, sizes or strings — use `AppColors`, theme text styles and l10n (`app_sw.arb` / `app_en.arb`).
- Immutable models with `fromJson`/`toJson` and `copyWith`.
- Every async screen handles **loading, empty, error and data** states using the shared widgets.

## 7. Git

- One logical change per commit; message says what and why ("Phase 1 step 10: OTP request and verify endpoints").
- Never commit `.env`, secrets, `vendor/`, uploaded media or logs.
- Tests pass before pushing.
