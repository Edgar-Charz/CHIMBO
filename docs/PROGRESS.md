# CHIMBO — Progress Log

Update this file at the end of every work session. Newest entry on top.

## Current status
- **Phase:** 0 — Foundation (not started)
- **Next step:** Phase 0, step 1 — create the `htdocs\chimbo` folder skeleton + `git init` (see blueprint §19)
- **Waiting on the user:** "go" to start Phase 0 · answers to D-2, D-3, D-4, D-16 (blueprint §23) · start payment aggregator + SMS provider applications (D-10)

## Decisions made
| Date | Decision |
|---|---|
| 2026-09-28 | Stack: Flutter · PHP 8.2 OOP (SCMRS style) · REST JSON · MySQL · PHP + Bootstrap admin |
| 2026-09-28 | Backend + admin + web storefront + docs in `C:\xampp\htdocs\chimbo\`; Flutter app in `C:\Users\edgar\AndroidStudioProjects\chimbo\` |
| 2026-09-28 | Web storefront = PHP pages + Bootstrap + plain JavaScript/AJAX calling the same API |
| 2026-09-28 | **Mobile app first**; web storefront in Phase 7 |

## Session log

### 2026-09-28 — Planning session
- Studied `CHIMBO.pdf` (12 pages) and wrote `docs/CHIMBO_BLUEPRINT.md` (v2.0).
- Agreed folder locations, web storefront approach and mobile-first order.
- Created empty folder `C:\Users\edgar\AndroidStudioProjects\chimbo\` (Flutter project goes here in Phase 0 step 8).
- Checked local environment: PHP 8.2.12 ✅, pdo_mysql ✅, curl ✅, MariaDB 10.4 (ok locally), **GD disabled** and **Composer not installed** → fix in Phase 0 step 2.
- No code written yet.
