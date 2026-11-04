# Antigravity — overnight work prompt for Bookly (read all of it before starting)

You are working **alone overnight (about 3–4 hours)** on Bookly, an online bookshop for Cambodia. The owner,
Danish, is asleep. He will review and merge everything tomorrow morning together with Claude.

**Take your time. Quality over speed.** There is no rush: it is better to finish 6 tasks well (tested, documented,
clean commits) than 9 tasks badly. **Never stop to ask a question.** When you must choose, pick the safest
reasonable option, write the decision and the reason in `PROGRESS.md`, and continue.

---

## 1. Project context

### The product
Bookly sells books online in Cambodia (English and Khmer), with cash on delivery and Bakong KHQR payments (live).
It must stay on free hosting. It is Danish's career showcase project, so code quality, tests, accessibility and
security matter a lot.

### Repositories (both on GitHub, both cloned next to each other locally)
| | Backend | Frontend |
|---|---|---|
| Repo | `danishsary8/bookly_backend_v2` | `danishsary8/bookly_frontend` |
| Stack | Laravel 13, PHP 8.4, PostgreSQL, Sanctum tokens | React 19, Vite 7, TypeScript 5.9, TanStack Query 5, React Router 7, Tailwind CSS 4, Zod 4 |
| API / types | REST `/api/v1`, spec `public/openapi.yaml` (a contract test checks responses match it), `docs/API.md` | API types generated from the spec: `OPENAPI_SOURCE=../bookly_backend_v2/public/openapi.yaml npm run api:types` |
| Tests | PHPUnit feature tests in `tests/Feature/**` (~820) | Vitest + Testing Library (~556), Playwright e2e in `e2e/` |
| Style | `vendor/bin/pint` | ESLint, `design-system/bookly/MASTER.md` (design rules) |
| Text | `lang/en/*.php`, `lang/km/*.php` | `src/i18n/en.ts`, `src/i18n/km.ts` |
| Hosting | Render (free; no scheduler, jobs run via `App\Support\MaintenanceTasks` + `DueMaintenance`) | Vercel |

### Read these first (15 minutes, do not skip)
1. Both repos: `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` (working rules) and `README.md`.
2. This repo: `GEMINI_ROADMAP.md` (what is done / remaining / human-only).
3. Backend branch `docs/handoff-notes`: `docs/WORKLOG.md` (latest sections at the bottom), `docs/OWNER_TODO.md`,
   `docs/superpowers/specs/2026-11-05-bookly-v3-roadmap-design.md` (the approved v3 plan).
4. Frontend: `design-system/bookly/MASTER.md`.

### Current status (2026-11-05)
- Everything up to **v3 Phase 1 (category tree + filters)** is merged to `main` in both repos and deployed.
- Phase 1 added: `App\Support\StarterCategories` (12 top-level categories, ~65 children), `categories.parent_id`,
  `sort_order`, `icon`; `GET /categories?tree=1`; book fields `age_min`, `age_max`, `school_grade`, `level`
  (`Book::LEVELS` = A1–C2); `GET /books` filters `category_ids[]`, `formats[]`, `age`, `school_grade`, `level`,
  `has_ebook`, sorts `best_selling`, `most_reviewed`, `facets=1`. Frontend: `src/components/catalog/CatalogFilters.tsx`,
  `src/lib/catalog.ts` (`LEVELS`, `GRADES`, `AGE_BANDS`), admin `src/pages/admin/LookupPage.tsx` (tree + parent/order),
  book form `src/features/admin/books/bookSchema.ts` + `src/pages/admin/BookEditPage.tsx`.
- Next product phases (2–11) need Danish's design approval before any code. Tonight you only **draft** designs.

---

## 2. Workflow rules (follow every one)

1. **Branch:** in **each** repo, work only on `feature/antigravity-overnight`.
   - Backend: this branch already exists and contains this file; `git fetch origin && git checkout feature/antigravity-overnight && git pull`.
   - Frontend: create it from the latest main: `git fetch origin && git checkout -b feature/antigravity-overnight origin/main`.
   - **Never push to `main`. Never merge. Never force-push. Never open pull requests.** Danish does that.
2. **One commit per task** (or a few small commits per task), each with a clear human-style message describing the
   change (e.g. "Add audience fields to the book CSV import"). No `Co-Authored-By` or tool attribution lines.
   Push after every task: `git push -u origin feature/antigravity-overnight`.
3. **Never stop and never ask.** Decide, record the decision in `PROGRESS.md`, continue. If a task is blocked,
   write `BLOCKED` with the reason and move on to the next task.
4. **⚠️ HUMAN tasks are off-limits** (see `GEMINI_ROADMAP.md`). Never touch Render, Vercel, Neon, Google Cloud,
   Meta/Facebook, Bakong, email providers, Telegram bot settings or DNS. Never write, print or commit secrets,
   tokens, passwords or connection strings (`.env` files stay uncommitted). Never call the live API or run anything
   against production. Never run seeders against a real database other than your local/test one.
5. **Do not edit existing migrations.** Tonight no task needs a new migration; if you think one is needed, stop that
   part and write "NEEDS DANISH" instead.
6. **No phase 2–11 feature code.** Task A7 writes documents only.
7. **Follow the existing code patterns.** Before writing a file, open a similar existing one and copy its style:
   small focused files, comments that explain *why*, validation on the server, tests for every behaviour change.
8. **Accessibility and design:** every UI change must be keyboard usable, have proper labels, work at 390 px and
   1280 px wide, in light and dark mode, and use the design tokens from MASTER.md (no new random colours).
9. **Text:** every new user-facing string goes in `en.ts` **and** `km.ts`. Tonight you may write the Khmer yourself
   (natural, polite Khmer as a Cambodian shop would write; keep `${...}` placeholders, function parameters and brand
   names; reuse terms already used in km.ts). Do **not** add `// TODO(antigravity)` markers; remove existing ones
   for groups you translate (a test checks a marked group still equals English).
10. **Checks before every push** (all must pass; if one cannot run on this machine, write that clearly):
    - Backend: `php artisan test` (needs PostgreSQL, see backend README) and `vendor/bin/pint --test`.
    - Frontend: `npm run lint` (0 errors), `npm run typecheck`, `npm test`, `npm run build`.
    - If `public/openapi.yaml` changed: it must parse, the contract test (`tests/Feature/Platform/ApiContractTest.php`)
      must pass, and the frontend types must be regenerated in the frontend branch.
11. **Never weaken tests** to make them pass (no skipping, no deleting assertions). Fix the code or report it.
12. **PROGRESS.md** (in the backend repo root, on `feature/antigravity-overnight`): append a section after every
    task (format in section 4) and push it.

---

## 3. Tonight's tasks (in this order)

### A0 — Setup and baseline (both repos) · ~15 min
- Read the files in section 1. Install dependencies (`composer install`, `npm ci`). Make sure local PostgreSQL works
  for backend tests (README). Run all checks from rule 10 on `main` of both repos and write the baseline numbers
  (tests passed, lint warnings, build size) plus `composer audit` and `npm audit --omit=dev` results in PROGRESS.md.
- If backend tests cannot run (no PostgreSQL), say so and continue: still write backend tests carefully and mark
  them "not run here".

### A1 — Khmer for the catalogue filters (frontend) · ~15 min
- `src/i18n/km.ts`: translate the `catalogFilters` group (English in `en.ts`), delete the `// TODO(antigravity)`
  line above it. Keep `ages` keys and function signatures exactly.
- Check: `grep -n "TODO(antigravity)" src/i18n/km.ts` → nothing; `npm test` passes (Khmer tests included).
- Commit: "Translate the catalogue filters to Khmer".

### A2 — Audience fields in the book CSV and bulk edit (backend, small frontend) · ~45 min
- Backend: `app/Http/Controllers/Api/V1/Staff/Catalog/BookCsvController.php` (+ any service it uses) and
  `BookBulkController.php`. Add `age_min`, `age_max` (0–18, max ≥ min), `school_grade` (1–12), `level`
  (`Book::LEVELS`) to: the CSV export, the template, import preview validation (clear row errors), import confirm,
  and bulk edit (only if bulk edit already handles similar optional book fields; otherwise skip and note why).
  Empty cell = null. Existing CSV files without these columns must still import exactly as before.
- Tests: extend `tests/Feature/Admin/BookPublishingBulkCsvTest.php` (or a new focused test file): export contains
  the columns, valid values import, bad values give row errors, old-format CSV still works.
- Docs: `docs/API.md` and `public/openapi.yaml` where CSV columns / bulk fields are described.
- Frontend (on the frontend branch): if the admin CSV import page or help text lists the columns, add the new ones.
- Commits: "Add audience fields to the book CSV import and export" (+ frontend "List the new CSV columns").

### A3 — Sample catalogue seeder (backend) · ~60 min
- New `database/seeders/SampleCatalogSeeder.php` with **at least 120 books** across **every** top-level starter
  category (call `StarterCategories::install()` first; attach books to sub-categories). Content:
  - real, well-known titles and their real authors: world classics, popular fiction, self-help (e.g. Atomic Habits),
    business, psychology, history, science, manga volumes (e.g. One Piece Vol. 1–3, Naruto Vol. 1–2), Marvel/DC
    graphic novels, English learning (English File Elementary/Pre-intermediate/Intermediate with `level`, Oxford
    grammar, IELTS prep), Khmer literature with Khmer titles (e.g. classic Khmer novels and folktales), Khmer school
    books Grade 7–12 per subject with `school_grade` and Khmer titles, kids books with `age_min`/`age_max`, YA;
  - prices $3–$40 (Khmer books and school books cheaper), `paperback` and/or `hardcover` with `stock_quantity`
    10–60, an `ebook` format on about one third, `language` English or Khmer, `status` live, no cover images;
  - authors, publishers and series via `firstOrCreate`; series with `series_order` for manga volumes.
  - Keep the data in a readable array (or a small data file next to the seeder), not generated randomly.
- **Safety:** idempotent (skip a book when the same title + first author exists); **not** called from
  `DatabaseSeeder`; refuses in production unless the same explicit opt-in that `DemoSeeder` uses (copy its guard).
- Test: on an empty test DB it creates ≥ 120 visible books in ≥ 10 top-level categories, every book has an active
  format with stock, running it twice adds nothing, production refuses without the opt-in.
- README: a short "Sample catalogue (local/demo only)" section: `php artisan db:seed --class=SampleCatalogSeeder`,
  marked "⚠️ Danish decides whether to run this anywhere other than local".
- Commit: "Add a sample catalogue seeder with 120+ books".

### A4 — Category page shows its sub-categories (frontend) · ~30 min
- `src/pages/catalog/CategoryPage.tsx`: when the category is top-level and `catalogQueries.categoryTree()` has
  children for it, show them above the book grid as a wrapping row of links ("Fantasy · 12"), each to that
  category's page. When the category is a sub-category, show a small "← Back to <parent>" link. Hidden when nothing
  to show. Use existing components/tokens; 44 px touch targets; good focus styles.
- Text in en.ts + km.ts (new small group, translated).
- Test in the style of `src/pages/catalog/catalog-pages.test.tsx` (mock `catalogApi.categoryTree`).
- Commit: "Show sub-categories on category pages".

### A5 — Icon picker in the admin category dialog (frontend) · ~30 min
- Categories have an `icon` (a lucide icon name; the API accepts `^[a-z0-9-]+$`, max 40). The starter tree uses:
  book-open, languages, school, graduation-cap, sprout, briefcase, landmark, zap, baby, sparkles, library, palette.
- In `src/pages/admin/LookupPage.tsx` (categories only, top-level only), add an optional icon choice from a fixed
  list of about 20 lucide icons (those 12 plus a few book-related ones), shown as a radio grid of icon buttons with
  accessible names, plus "No icon". Send `icon` (or null) with the save. Map names → components in one small file
  (e.g. `src/features/catalog/categoryIcons.ts`) so the future menu can reuse it; unknown names render nothing.
- Show the icon next to top-level names in the admin list.
- Test: picking an icon sends it; "No icon" sends null; sub-categories don't show the picker.
- Commit: "Let admins pick an icon for top-level categories".

### A6 — Audience info on the book page and cards (frontend) · ~30 min
- `src/pages/catalog/BookDetailPage.tsx`: in the details list, show "Reader age 8–12" (or "8+"), "School grade 9",
  "English level B1" when set, each linking to the catalogue filtered by it (`/books?age=…`, `?school_grade=9`,
  `?level=B1`), and the book's categories as links (sub-category with its parent).
- `src/components/catalog/BookCard.tsx`: a small quiet tag for level or grade when set (e.g. "B1", "Grade 9");
  must not break the card layout at 390 px.
- Text in en.ts + km.ts. Tests for both.
- Commit: "Show reader age, grade and level on books".

### A7 — Draft designs for phases 2, 3 and 4 (backend docs, no code) · ~45 min
- New files in `docs/superpowers/specs/` on this branch:
  `2026-11-06-ebooks-design-DRAFT.md`, `2026-11-06-series-volumes-design-DRAFT.md`,
  `2026-11-06-preorder-backorder-design-DRAFT.md`.
- Each: goal, user flows (customer + admin), proposed data model (tables/columns, marked "proposed"), API endpoints,
  security, free-hosting limits (Render free, Cloudflare R2 for files), performance, tests to write, rollout steps,
  and **"Questions for Danish"** with your recommended answer for each.
- Ebooks must follow the approved rules: read in the browser only (no download), encrypted short-lived page chunks,
  visible watermark (buyer email + order number), copy/select/print blocked in the reader, max 3 devices per
  customer, no refunds except a broken file, ebooks bought in the normal cart, Khmer font support, free preview
  ~10%, admin uploads an ebook file per book (PDF/EPUB; say which you recommend and why).
- Commit: "Draft designs for ebooks, series volumes and pre-orders".

### A8 — Docs refresh (both repos) · ~20 min
- Backend `README.md` and `docs/API.md`; frontend `README.md`: make sure features added since 2026-11-04 are listed
  briefly and correctly (category tree and filters, cart reminders, gift note, map pin (hidden until a key is set),
  leaked-password check, sessions, new-device email, price-drop badge, tonight's work). Fix only what is wrong or
  missing; keep the existing tone. Do not document secrets or internal hostnames.
- Commits: "Refresh the README and API notes".

### A9 — Final check and morning summary (both repos) · ~20 min
- Rebase nothing. Run every check from rule 10 on both `feature/antigravity-overnight` branches, from a clean state.
- Confirm the two branches don't conflict with `origin/main` (`git merge --no-commit --no-ff origin/main` then
  `git merge --abort`, or `git diff origin/main...`); note any conflict.
- At the **top** of PROGRESS.md write **"Summary for Danish"**:
  - the two PR links to open:
    - https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/antigravity-overnight
    - https://github.com/danishsary8/bookly_frontend/pull/new/feature/antigravity-overnight
  - merge order: **backend first**, wait for Render to show Live, then frontend;
  - what each task changed in one line, check results, open questions ("NEEDS DANISH"), and what to check on the
    live site after merging.
- Final commit: "Add the overnight progress summary". Push both branches.

If you finish early: re-read your own diffs (`git diff origin/main...`) like a strict reviewer, fix issues, add
missing tests, and improve accessibility of what you built. Do **not** start new features.

---

## 4. PROGRESS.md format

```
# Overnight progress (Antigravity, 2026-11-05 → 06)

## Summary for Danish        ← written last (A9), kept at the top

## A<n> — <title> — DONE | PARTLY DONE | BLOCKED | SKIPPED
- Repo/branch: backend|frontend · feature/antigravity-overnight · commit <short sha>
- What changed: <2–6 short lines, files touched>
- Checks: <command → result, e.g. "php artisan test → 845 passed", "npm test → 570 passed">
- Decisions I made: <decision — reason>
- NEEDS DANISH: <question + my recommendation, or "none">
```

Work calmly and carefully. Good night's work = clean commits, passing checks, honest notes.
