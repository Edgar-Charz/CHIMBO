# CHIMBO — instructions for Claude

CHIMBO is a Swahili-first B2B wholesale marketplace (cosmetics + jewelry) for Tanzanian shop owners.

Before doing anything, read:
1. `docs/PROGRESS.md` — current phase, next step, what we're waiting on
2. `docs/CHIMBO_BLUEPRINT.md` — the full plan (architecture §7–§14, implementation order §19, decisions §23)
3. `docs/CODING_STANDARDS.md` — **clean-code rules for PHP, JS, CSS and Flutter. The user cares a lot about clean code; every change must follow them.**

Code locations:
- This folder (`C:\xampp\htdocs\chimbo\`): PHP backend API (`api/`, `src/`), admin (`admin/`), web storefront (root pages), docs
- `C:\Users\edgar\AndroidStudioProjects\chimbo\`: Flutter mobile app

Working rules:
- Work one phase/step at a time, in the order of blueprint §19. Don't skip ahead without the user's agreement.
- Keep it simple and SCMRS-like (`C:\xampp\htdocs\scmrs`): OOP classes, PDO prepared statements, try/catch, transactions for money/orders, backend validates everything.
- The user is still learning — explain new concepts briefly when they first appear.
- At the end of every session, update `docs/PROGRESS.md` (status, next step, session log).
