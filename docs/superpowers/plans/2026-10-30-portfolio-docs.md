# Portfolio API Docs and README Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `/docs` with a Scalar-based, portfolio-quality API reference that visitors can test with one click, keep the shared demo account clean, and rewrite the README for recruiters.

**Architecture:** `/docs` stays one Blade view (no build step) that loads a pinned Scalar bundle against `public/openapi.yaml` and adds a custom intro (hero, demo login, security cards, quick start). A new `demo:reset` command, registered in the existing request-driven housekeeping, keeps the shared demo customer usable. README and OpenAPI text are documentation-only changes.

**Tech Stack:** Laravel 13, PHPUnit, Blade, Scalar `@scalar/api-reference@1.74.0` (jsDelivr), Playwright (verification only), Mermaid (README).

**Spec:** `docs/superpowers/specs/2026-10-30-portfolio-docs-design.md`

## Global Constraints

- Branch `feature/portfolio-docs`; human-style commit messages, no tool names (CLAUDE.md).
- External script: exactly `https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.74.0` — pinned, no other new CDNs.
- No staff credentials anywhere in the page, spec or README. Demo customer is `demo@bookly.test` / `Password123!`.
- `/docs` stays in the web group with no session and no DB access (bootstrap/app.php already removes session middleware).
- Token storage: `sessionStorage` only, every access wrapped in try/catch.
- `OpenApiSpecTest` (every route documented) stays green; `public/openapi.yaml` stays valid YAML.
- Full suite + `vendor/bin/pint --test` green before each commit; log the finished work in `docs/WORKLOG.md`.

## Review Focus

- API asleep on Render free (cold start 30–60 s): status badge must show "Waking up…" and retry once, not "down".
- Demo login rate-limited (429) or DB down (503): the button shows the API's message and re-enables; no retry loop.
- `sessionStorage` blocked (private mode): page and reference still render; demo login still works for the tab's lifetime (in-memory fallback).
- Demo reset must never touch other customers or non-pending orders, and must restore stock (it uses the real cancel path).
- Phone width (≤ 400 px): hero, cards and code blocks fit without horizontal page scroll.

---

### Task 1: `demo:reset` command and daily registration

**Files:**
- Create: `app/Console/Commands/ResetDemoCustomer.php`
- Modify: `app/Support/MaintenanceTasks.php` (add entry to the list)
- Test: `tests/Feature/Platform/DemoResetCommandTest.php`; one assertion in `tests/Feature/Platform/DueMaintenanceTest.php`

**Interfaces:**
- Produces: artisan signature `demo:reset`; maintenance entry `['name' => 'demo-reset', 'command' => 'demo:reset', 'parameters' => [], 'cron' => '0 3 * * *']`.
- Consumes: `App\Services\Cart\CartService::clear(Customer)`, `App\Services\Orders\OrderStatusService::cancelByCustomer(Order, ?string)`.

- [ ] **Step 1: Write failing tests** in `DemoResetCommandTest` (RefreshDatabase, `Notification::fake()`; place orders through the real cart + `POST /api/v1/checkout` like `tests/Feature/Orders/ReorderTest.php`, then move `placed_at` back with `forceFill`):
  - `test_it_empties_the_demo_cart_and_cancels_stale_pending_orders_restoring_stock` — demo customer (`email = demo@bookly.test`), variant stock 10, order qty 2 placed 25 h ago, a cart line left; run `demo:reset` → exit 0, cart has 0 items, order status `cancelled`, variant stock back to 10.
  - `test_it_keeps_recent_pending_and_non_pending_orders` — pending order placed 2 h ago stays `pending`; an order set to `delivered` 3 days ago stays `delivered`.
  - `test_it_never_touches_other_customers` — another customer's 25 h-old pending order and cart stay as they were.
  - `test_it_does_nothing_without_the_demo_account` — no demo customer → exit 0, output contains `No demo account`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Platform/DemoResetCommandTest.php` → FAIL (command not defined).
- [ ] **Step 3: Implement** `ResetDemoCustomer` (`$signature = 'demo:reset'`): find customer by `DemoSeeder` email; `CartService::clear`; for each of their orders with status `pending` and `placed_at < now()->subDay()`, call `cancelByCustomer($order, 'Daily demo reset')`; print `Demo reset: cart emptied, N stale orders cancelled.` Follow the style of `app/Console/Commands/PruneUnverifiedCustomers.php`.
- [ ] **Step 4: Register** the entry above in `MaintenanceTasks::all()` (comment: keeps the shared portfolio demo account usable); add to `DueMaintenanceTest` an assertion that `collect(MaintenanceTasks::all())->pluck('name')` contains `demo-reset` (the existing `test_every_registered_job_is_a_real_command_that_runs_cleanly` covers that it runs).
- [ ] **Step 5: Run** the two test files, then the full suite and `vendor/bin/pint --test` → all green.
- [ ] **Step 6: Commit** `Add daily reset for the shared demo customer`.

### Task 2: OpenAPI polish for the visitor path

**Files:**
- Modify: `public/openapi.yaml` (`info`, `components.securitySchemes.bearer`, `tags`, the 10 paths below)
- Test: existing `tests/Feature/Platform/OpenApiSpecTest.php`

- [ ] **Step 1: Write** `info.description` (Markdown, ≤ 25 lines): what the API is; base URL `/api/v1`; auth (customer token from `POST /auth/login`, staff token after 2FA; send `Authorization: Bearer <token>`); errors (`{message, errors?}`, `X-Request-Id`); pagination (`data/links/meta`); rate limits; "Try it" pointer to the demo button.
- [ ] **Step 2: Write** `securitySchemes.bearer.description`: customer vs staff tokens, abilities, expiry (customer 7 days, staff 12 hours — values from `docs/DEPLOYMENT.md`).
- [ ] **Step 3: Add** summaries/descriptions and one request + one response `example` each for: `/auth/login`, `/auth/register`, `/books`, `/books/{id}`, `/cart`, `/cart/items`, `/checkout/preview`, `/checkout`, `/orders`, `/orders/{id}/reorder`. Examples use seeded data (e.g. "Pride and Prejudice", `demo@bookly.test`).
- [ ] **Step 4: Set** the staff tag(s) description to start with `🔒 Staff only — requires a staff token with 2FA. Visitors get 401/403.`
- [ ] **Step 5: Verify** `php -r 'require "vendor/autoload.php"; Symfony\Component\Yaml\Yaml::parseFile("public/openapi.yaml"); echo "yaml ok";'` prints `yaml ok`, and `php artisan test tests/Feature/Platform/OpenApiSpecTest.php` → PASS.
- [ ] **Step 6: Commit** `Describe auth, errors and key endpoints in the API spec`.

### Task 3: New `/docs` page

**Files:**
- Modify: `resources/views/docs.blade.php` (full rewrite)
- Test: `tests/Feature/Platform/DocsPageTest.php` (create)

**Interfaces:**
- Consumes: `GET /up`, `GET /api/v1/health`, `POST /api/v1/auth/login` (`{token, customer}`), `POST /api/v1/auth/logout`, `/openapi.yaml`.

- [ ] **Step 1: Write failing test** `test_docs_page_renders_without_a_database_and_pins_scalar`: point DB at an unreachable port (`config(['database.connections.pgsql.port' => 1])`, purge connection), `GET /docs` → 200; body contains `@scalar/api-reference@1.74.0`, `Try as demo customer`, `demo@bookly.test`; body does not contain `admin@bookly.test` nor `swagger-ui`.
- [ ] **Step 2: Run** → FAIL (old page has swagger-ui).
- [ ] **Step 3: Implement** the page per spec §1: hero (title, pitch, status badge, tech badges, links to `https://github.com/danishsary8/bookly_backend_v2`, storefront URL from `config('shop.url')` if set else omitted, `/openapi.yaml`); "Try it in 30 seconds" card; six security cards (exact topics in spec §1.3); three `curl` blocks using `url('/api/v1')`; Scalar mount (`Scalar.createApiReference('#reference', {url: '/openapi.yaml', authentication: {preferredSecurityScheme: 'bearer', http: {bearer: {token}}}, ...})`, updated after demo login). Colours as CSS variables on `:root` with `prefers-color-scheme: dark` override. Status logic: `/up` ok + health ok → Operational; `/up` ok + health 503 → Database unavailable; network error/timeout → "Waking up…", one retry after 20 s. Demo button: disabled while pending; on non-2xx show `message` from JSON; token kept in a JS variable and mirrored to `sessionStorage` (try/catch).
- [ ] **Step 4: Run** the test → PASS; full suite + pint → green.
- [ ] **Step 5: Runtime check** (Playwright against local API with DemoSeeder, `PW` from `/opt/pw-browsers/chromium`): page has no console errors; badge reaches Operational; click demo button → "Signed in as demo@bookly.test"; a Scalar "Test request" on `GET /cart` returns 200; `GET /staff/books` returns 401; screenshots light + dark at 1280 px and 390 px saved under the scratchpad; no horizontal scroll at 390 px.
- [ ] **Step 6: Save** the 1280 px light screenshot as `docs/images/docs-page.png`; commit `Redesign the API docs page with one-click demo login`.

### Task 4: README rewrite

**Files:**
- Modify: `README.md` (full rewrite), `docs/WORKLOG.md` (entry for Tasks 1–4)

- [ ] **Step 1: Collect facts from code** (not from the old README): test count (`php artisan test` summary), return window (`config/shop.php` / ReturnService), feature list from `routes/api.php`, stack versions from `composer.json`.
- [ ] **Step 2: Write** the README in the spec §4 order: title + pitch + badges (CI badge from `.github/workflows/` file name) + three links (`https://bookly-api-zasc.onrender.com/docs`, storefront, frontend repo); `docs/images/docs-page.png`; six highlights; Mermaid flowchart; two Mermaid sequence diagrams (customer login; staff login → TOTP → token); "Tech choices" table (choice · why · trade-off, ≥ 6 rows: Laravel/Sanctum, PostgreSQL full-text, idempotency keys, request-driven housekeeping, Render+Neon free tier, Telegram alerts); run locally (5 commands); tests; deployment; links into `docs/`.
- [ ] **Step 3: Verify** Mermaid blocks parse (`npx -y @mermaid-js/mermaid-cli@11.4.2 -i <block>.mmd -o /tmp/x.svg` per block, run in scratchpad) and every relative link in README points to an existing file.
- [ ] **Step 4: Log** Tasks 1–4 in `docs/WORKLOG.md`; full suite + pint green; commit `Rewrite README for the portfolio`; push `feature/portfolio-docs`.
