# CHIMBO — Backend, Admin & Web Storefront

CHIMBO is a Swahili-first B2B wholesale marketplace (cosmetics + jewelry) for Tanzanian shop owners.

This repository contains:

| Part | Folder | Local URL |
|---|---|---|
| REST API (JSON) for the mobile app and the website | `api/`, `src/` | http://localhost/chimbo/api/v1/ |
| Admin dashboard | `admin/` | http://localhost/chimbo/admin/ |
| Web storefront (PHP + Bootstrap + JavaScript/AJAX) | root pages | http://localhost/chimbo/ |
| Documentation | `docs/` | — |

The Flutter mobile app lives in a separate project: `C:\Users\edgar\AndroidStudioProjects\chimbo\`.

## Documentation
- [docs/PROGRESS.md](docs/PROGRESS.md) — current phase and next step
- [docs/CHIMBO_BLUEPRINT.md](docs/CHIMBO_BLUEPRINT.md) — full development blueprint

## Requirements
- PHP 8.2+ with `pdo_mysql`, `gd`, `curl`, `mbstring`, `fileinfo`
- MySQL 8 / MariaDB 10.4+
- Apache with `mod_rewrite` (XAMPP locally)
- Composer

## Local setup
1. XAMPP: enable `extension=gd` in `C:\xampp\php\php.ini`, restart Apache.
2. Create the database: `CREATE DATABASE chimbo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Composer (Herd's phar, run with XAMPP's PHP):
   `C:\xampp\php\php.exe C:\Users\edgar\.config\herd\bin\composer.phar install`
4. More steps (`.env`, migrations, seeds) will be added in Phase 0 steps 3–6.
