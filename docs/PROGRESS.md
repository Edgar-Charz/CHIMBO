# CHIMBO — Progress Log

Update this file at the end of every work session. Newest entry on top.

## Current status
- **Phase:** 0 — Foundation (in progress: steps 1–4 done)
- **Next step:** Phase 0, step 5 — `Validator` class + PHPUnit set up with first tests (see blueprint §19)
- **GitHub:** https://github.com/Edgar-Charz/CHIMBO (backend repo, branch `main`)
- **Waiting on the user:** answers to D-2, D-3, D-4, D-16 (blueprint §23) · start payment aggregator + SMS provider applications (D-10)

## Decisions made
| Date | Decision |
|---|---|
| 2026-09-28 | Stack: Flutter · PHP 8.2 OOP (SCMRS style) · REST JSON · MySQL · PHP + Bootstrap admin |
| 2026-09-28 | Backend + admin + web storefront + docs in `C:\xampp\htdocs\chimbo\`; Flutter app in `C:\Users\edgar\AndroidStudioProjects\chimbo\` |
| 2026-09-28 | Web storefront = PHP pages + Bootstrap + plain JavaScript/AJAX calling the same API |
| 2026-09-28 | **Mobile app first**; web storefront in Phase 7 |

## Session log

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
