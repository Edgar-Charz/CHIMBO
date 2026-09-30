# CHIMBO — instructions for Claude

CHIMBO is a Swahili-first B2B wholesale marketplace (cosmetics + jewelry) for Tanzanian shop owners.

Before doing anything, read:
1. `docs/PROGRESS.md` — current phase, next step, what we're waiting on
2. `docs/CHIMBO_BLUEPRINT.md` — the full plan (architecture §7–§14, implementation order §19, decisions §23)
3. `docs/CODING_STANDARDS.md` — **clean-code rules for PHP, JS, CSS and Flutter. The user cares a lot about clean code; every change must follow them.**

Code locations:
- This folder (`C:\xampp\htdocs\chimbo\`): PHP backend API (`api/`, `classes/`), admin (`admin/`), web storefront (root pages), docs
- `C:\Users\edgar\AndroidStudioProjects\chimbo\`: Flutter mobile app

Working rules:
- Work one phase/step at a time, in the order of blueprint §19. Don't skip ahead without the user's agreement.
- Keep it simple and SCMRS-like (`C:\xampp\htdocs\scmrs`): domain classes in `classes/` (SQL + rules), small helpers in `classes/core/`, thin API files in `api/endpoints/`. **No MVC layers** (no controllers/services/repositories). Clear names, no abbreviations (except common ones: `$stmt`, `$db`, `$id`, `$e`, `$auth`). **PHP variables/properties snake_case, methods camelCase, classes PascalCase, page files snake_case.php. Validation inside the classes. API JSON keys = DB column names.** DB columns prefixed: `user_id`, `user_phone`, `product_name`. PDO named placeholders, try/catch, transactions for money/orders, backend validates everything.
- The user is an expert in plain PHP (SCMRS-style classes) but new to APIs — explain API concepts by comparing with SCMRS, not PHP basics. **After each backend step, explain what was built and how the request flows** (URL → api/index.php → Router → middleware → API file → class → Validator/Database → JSON), and extend `docs/HOW_THE_BACKEND_WORKS.md` when new concepts appear.
- At the end of every session, update `docs/PROGRESS.md` (status, next step, session log).
- **Never commit or push unless the user says so.** Keep replies concise to save tokens.

Parallel sessions — each session edits only its own files:
- **Backend session:** ALL database migrations and seeds, ALL domain classes in `classes/` (users, catalog: `Category`, `Seller`, `Product`, `ImageUploader`, cart, orders, payments …), `classes/core/`, `api/` (all endpoints), `config/`, `cron/`, `tests/`, `bootstrap.php`, `.env*`, `composer.json`.
- **Admin session:** only the admin pages in `admin/` (plus `Admin`, `AdminSession`, `AuditLog`, `Dashboard`, `Customer`). It uses the backend session's classes (e.g. `Product`) and does **not** create migrations or edit other classes; if it needs a class method, table column or endpoint, it tells the user and the backend session builds it. (Changed 2026-09-29: the catalog tables and classes moved to the backend session.)
- **Web storefront session:** root page files (`index.php`, `category.php`, `product.php`, `cart.php` …), `includes/`, `assets/css/`, `assets/js/`, `assets/img/`, `assets/fonts/`. It reads classes and calls the API but does **not** edit backend files; if it needs a new class method or endpoint, it tells the user, and the backend session builds it.
- **Mobile session:** only `C:\Users\edgar\AndroidStudioProjects\chimbo\`.
- `docs/PROGRESS.md`: every session may add its own log entry at the top of "Session log"; never rewrite other sessions' entries.
