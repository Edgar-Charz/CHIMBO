# CHIMBO — Coding Standards

Clean code is a requirement for CHIMBO, on the backend **and** the front-ends. Every change is checked against this list before it is committed.

## 1. General (all code)

- **Readable first.** Code is read far more than it is written. Prefer clear over clever.
- **Descriptive names.** `calculateLineTotal()`, `$unitPrice`, `isMoqMet` — not `calc()`, `$up`, `$flag`.
- **Small, single-purpose functions and classes.** One reason to change. If a function needs "and" to describe it, split it.
- **No duplication (DRY).** If the same logic appears twice, extract it. Rules like pricing live in exactly one place.
- **No magic values.** Statuses, limits and roles are named constants or settings, not repeated strings/numbers.
- **Fail loudly and early.** Validate input at the edges; throw clear exceptions; never silently ignore errors.
- **Comments explain *why*, not *what*.** Class/method doc comments say what something is for; inline comments only for non-obvious reasons.
- **No dead code.** No commented-out code, unused variables, debug prints or leftover test files in commits.
- **Consistent formatting.** Follow `.editorconfig`; format before committing.
- **Same pattern everywhere.** Once a feature is built one way, the next feature follows the same structure.

## 2. PHP backend, admin and web pages

- **PSR-12 style**: 4-space indent, braces on their own line for classes/methods, one class per file, file name = class name.
- **Type everything**: typed properties, parameter types and return types (`: array`, `: ?array`, `: void`, `: Response`).
- **Layers stay separate**:
  - *Controller* — read the request, validate, call one service method, return a `Response`. No SQL, no business rules.
  - *Service* — business rules and transactions. No `$_POST`, no HTML, no JSON output.
  - *Repository* — SQL only, prepared statements with **named placeholders**, returns arrays. No business rules.
- **Constructor injection**: dependencies come in through the constructor (with sensible defaults), so classes are testable.
- **Errors**: throw `ApiException` for client errors; let unexpected errors reach the global handler; never `die()`/`exit` in classes.
- **Transactions** for anything touching money, stock or orders — via `$db->transaction(...)`.
- **SQL**: uppercase keywords, one clause per line for long queries, select only needed columns (no `SELECT *` in repositories that feed the API).
- **Web/admin pages**: logic at the top, HTML below; escape all output with `e()` (`htmlspecialchars`); shared layout in `includes/` partials; no inline `<style>` or `<script>` blocks beyond tiny page config.
- **Short methods**: aim for ≤ 30 lines; extract private helpers when longer.

## 3. JavaScript (web storefront)

- Plain modern JS (ES2020+): `const`/`let` (never `var`), arrow functions, `async`/`await` with `fetch`.
- **One shared helper** (`assets/js/chimbo.js`) for API calls, CSRF, toasts and loading states — pages never call `fetch` directly.
- One file per page/feature, wrapped so nothing leaks into the global scope; functions named by what they do (`renderProductCard`, `updateCartBadge`).
- No inline `onclick=""` handlers — use `addEventListener` and `data-*` attributes.
- Build DOM safely (`textContent`, or escaped templates) — never insert unescaped API data as HTML.

## 4. CSS

- Brand colours, radii and spacing as CSS variables in `theme.css`; never hard-code hex values elsewhere.
- Bootstrap utilities first; custom classes only for CHIMBO components, named by component (`.product-card`, `.tier-table`).
- Mobile-first media queries.

## 5. Flutter / Dart (mobile app)

- `dart format` and `flutter analyze` must be clean (lints from `flutter_lints`, plus stricter rules in `analysis_options.yaml`).
- Feature-first folders: `data/` (API + repository + models), `application/` (Riverpod controllers), `presentation/` (screens + widgets).
- **Widgets only display and react.** No HTTP calls, no business rules, no JSON parsing inside widgets.
- Small widgets: extract a widget when a `build()` method grows beyond ~60 lines or a piece is reused.
- `const` constructors wherever possible; no hard-coded colours, sizes or strings — use `AppColors`, theme text styles and l10n (`app_sw.arb` / `app_en.arb`).
- Immutable models with `fromJson`/`toJson` and `copyWith`.
- Every async screen handles **loading, empty, error and data** states using the shared widgets.

## 6. Git

- One logical change per commit; message says what and why ("Phase 1 step 10: OTP request and verify endpoints").
- Never commit `.env`, secrets, `vendor/`, uploaded media or logs.
- Tests pass before pushing.
