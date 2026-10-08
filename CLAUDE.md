# CHIMBO — instructions for Claude

**Read and follow `AGENTS.md` first** — it holds the rules for every AI agent on this project
(which docs to read, which files each area may edit, coding standards, Git rules, "definition of done").

Claude-specific notes (in addition to AGENTS.md):
- Code locations: this folder (`C:\xampp\htdocs\chimbo\`) = backend API (`api/`, `classes/`), admin (`admin/`), web storefront (root pages), docs; `C:\Users\edgar\AndroidStudioProjects\chimbo\` = Flutter app.
- Work one step at a time, in the order of the blueprint §19 / `docs/PROGRESS.md`; don't skip ahead without the user's agreement.
- Backend work: after each step, explain what was built and how the request flows (URL → api/index.php → Router → middleware → API file → class → Validator/Database → JSON), and extend `docs/HOW_THE_BACKEND_WORKS.md` when new concepts appear.
- At the end of every session, update `docs/PROGRESS.md`.
- Never commit or push unless asked; plain commit messages, no "Co-Authored-By" line. Keep replies concise.
