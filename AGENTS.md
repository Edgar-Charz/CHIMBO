# CHIMBO — rules for every AI coding agent

This file is for **any** AI agent working on this project (Claude, Codex, Cursor, Gemini, Copilot …).
Follow it exactly. If anything here conflicts with your own defaults, **this file wins**.

CHIMBO is a Swahili-first B2B wholesale marketplace (cosmetics + jewelry) for Tanzanian shop owners:
tier prices ("buy more, pay less"), minimum order quantities (MOQ), reordering, cash on delivery.

## 1. Read before you change anything
1. `docs/PROGRESS.md` — what exists, what's next (newest entries at the top of "Session log")
2. `docs/CODING_STANDARDS.md` — **mandatory clean-code rules** (PHP, database, JavaScript, CSS, Flutter, Git)
3. `docs/API_REFERENCE.md` — every endpoint that exists, with exact JSON field names
4. The guide for your area:
   - Web storefront → `docs/WEB_STOREFRONT_GUIDE.md`
   - Admin pages → `docs/ADMIN_CLASSES.md`
   - Backend → `docs/HOW_THE_BACKEND_WORKS.md`
5. `docs/CHIMBO_BLUEPRINT.md` — the full plan (design §6, web §10, database §11, security §15)

## 2. Where code lives — edit ONLY your area
| Area | Files you may edit |
|---|---|
| **Backend** | `classes/` (all classes and `classes/core/` helpers), `api/`, `database/` (migrations, seeds), `config/`, `cron/`, `tests/`, `bootstrap.php`, `.env*`, `composer.json` |
| **Admin pages** | `admin/` only (plus `Admin`, `AdminSession`, `AuditLog`, `Dashboard`, `Customer` classes) |
| **Web storefront** | root page files (`index.php`, `category.php`, `product.php`, `cart.php`, `checkout.php` …), `includes/`, `assets/css/`, `assets/js/`, `assets/img/`, `assets/fonts/` |
| **Mobile app** | only `C:\Users\edgar\AndroidStudioProjects\chimbo\` |

- **Never edit another area's files.** If you need a new endpoint, class method, table or column, **stop and tell the user**; the backend agent builds it.
- Several agents work at the same time. Don't reformat, rename or "clean up" files outside your task.
- `docs/PROGRESS.md`: add your own entry at the top of "Session log" when you finish; never edit other entries.

## 3. Non-negotiable rules (details in `docs/CODING_STANDARDS.md`)
- **Style:** SCMRS-like — simple classes + small helpers, **no MVC layers** (no controllers/services/repositories, no frameworks).
- **Naming:** PHP variables/properties `snake_case`, methods `camelCase`, classes `PascalCase`, page files `snake_case.php`. No abbreviations (except `$stmt`, `$db`, `$id`, `$e`, `$auth`, `$i`).
- **JSON and database names match:** `user_phone`, `product_name`, `order_total` … (use the exact names from `docs/API_REFERENCE.md`).
- **Security:** backend validates everything; never trust prices, totals or statuses from the client; PDO named placeholders only; escape all HTML output with `e()`; CSRF on every form/POST; never commit `.env`.
- **Clean code:** small single-purpose functions, no duplication, no magic values, no dead or commented-out code, no leftover debug/test files. Comments explain *why*.
- **Language:** code, comments and docs in English; text customers see in **Kiswahili**; admin pages in English.
- **Brand:** green `#0E3B2A`, orange `#F39200`, cream `#FBF6EE` — as CSS variables / theme tokens, never hard-coded everywhere.

## 4. Before you say "done"
- PHP: every changed file passes `C:\xampp\php\php.exe -l <file>`.
- Backend tests still pass: `cd C:\xampp\htdocs\chimbo` then `C:\xampp\php\php.exe vendor\bin\phpunit`.
- Web/admin: pages open at `http://localhost/chimbo/…` without PHP warnings or browser console errors.
- Flutter: `flutter analyze` is clean and `flutter test` passes.
- You re-read your changes against `docs/CODING_STANDARDS.md`.
- You added your entry to `docs/PROGRESS.md`.

## 5. Git
- **Never commit or push unless the user explicitly asks.**
- Commit messages: plain, descriptive sentences ("Add product page with tier price table"). No "Phase X step Y", no "MVP".
- **No "Co-Authored-By" or "Generated with …" lines** — commits belong to Edgar only.

## 6. Working with the user
- Work in small steps and wait for the user's OK between them; ask when something is unclear.
- The user is an expert in plain PHP (SCMRS style) and is learning APIs — explain what you built and how it flows, briefly.
- Keep replies concise.
