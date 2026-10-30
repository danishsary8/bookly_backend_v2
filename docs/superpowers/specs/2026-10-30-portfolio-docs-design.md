# Portfolio API docs and README — design

Date: 2026-10-30 · Branch: `feature/portfolio-docs` · Status: awaiting owner review

## Goal

The owner will show this API in a portfolio and in job interviews. A visitor (recruiter, interviewer) must be able to:

1. understand what the API does and how it is built within a minute,
2. try real requests themselves, including ones that need login, without setup,
3. see the security and authentication design clearly.

Success = a visitor opens `/docs`, clicks one button, sends an authenticated request (e.g. `GET /cart`) and gets a real
answer; the GitHub README makes the project look like a real, production-run system with accurate facts.

Out of scope here (separate sub-projects, later): REA-researched search feature, production-readiness audit/checklist,
`npx rea-agents setup` on the owner's computer.

## Decisions (agreed with the owner)

| Topic | Decision |
|---|---|
| Docs UI | Scalar API Reference (`@scalar/api-reference@1.74.0`, pinned, jsDelivr) rendering `public/openapi.yaml`, with a custom intro section above it |
| Testing protected endpoints | One-click "Try as demo customer" (demo@bookly.test); staff endpoints documented but not usable by visitors |
| Shared demo account | Daily automatic reset of the demo customer's cart and stale pending orders |
| README | Rewritten portfolio-style; facts corrected |

## 1. `/docs` page

One Blade view (`resources/views/docs.blade.php`), no frontend build step. Route and middleware unchanged (web group,
no session, no DB).

Sections, top to bottom:

1. **Hero** — "Bookly API · v1", one-line pitch, live status badge, tech badges (Laravel 13, PostgreSQL, Sanctum),
   links: GitHub repo, live storefront, `openapi.yaml`.
   - Status badge: browser calls `GET /up` (app) and `GET /api/v1/health` (database). States: "Operational",
     "Database unavailable", "Waking up…" (Render free cold start, retried once after 20 s).
2. **Try it in 30 seconds** — button "Try as demo customer":
   - calls `POST /api/v1/auth/login` with `demo@bookly.test` / `Password123!` (already public in the seeder docs),
   - hands the returned token to Scalar's bearer auth so every "Test request" is authenticated,
   - shows "Signed in as demo@bookly.test ✓" and a "Sign out" link (`POST /api/v1/auth/logout`, clear token),
   - keeps the token in `sessionStorage` (per tab, cleared on close); every read/write in try/catch,
   - on failure (429, 503, network) shows the API's message inline; never retries in a loop.
3. **Security at a glance** — six cards, each one sentence + the endpoint/setting that proves it:
   bearer tokens with abilities (customer vs staff); mandatory staff 2FA (TOTP); rate limits (auth, coupon check,
   checkout); verified-email gate for shopping; idempotency keys on checkout; consistent JSON errors with `X-Request-Id`.
4. **Quick start** — three copy-paste `curl` blocks: list books, log in, add to cart.
5. **API reference** — Scalar: sidebar grouped by existing tags, search, dark mode toggle, code samples
   (curl, JavaScript fetch, PHP). Bookly colours via CSS variables.

Constraints: works at phone width; respects `prefers-color-scheme`; all external assets pinned versions; no staff
credentials anywhere on the page.

## 2. Demo account safety

- New console command `demo:reset` (in `app/Console/Commands`):
  - does nothing unless `demo@bookly.test` exists;
  - empties that customer's cart;
  - cancels that customer's **pending** orders placed more than 24 h ago via the existing customer-cancel path
    (`OrderStatusService::cancelByCustomer`), so stock and coupon uses are restored exactly as a real cancel;
  - leaves delivered/shipped seed orders untouched, so the account always shows a realistic history;
  - prints a one-line summary.
- Registered in `app/Support/MaintenanceTasks.php` as `demo-reset`, cron `0 3 * * *` (daily), so it runs both from the
  scheduler (Railway) and from request-driven housekeeping (Render free).
- Existing rate limits stay as they are (`throttle:auth` on login).

## 3. OpenAPI polish (`public/openapi.yaml`)

- `info.description`: short Markdown intro (auth model, base URL, errors, pagination, rate limits).
- `securitySchemes.bearer`: description of customer vs staff tokens and how to get one.
- Summaries/descriptions and request/response examples for the visitor path: `/auth/login`, `/auth/register`,
  `/books`, `/books/{id}`, `/cart`, `/cart/items`, `/checkout/preview`, `/checkout`, `/orders`, `/orders/{id}/reorder`.
- Staff tag description states "staff only, requires 2FA; visitors will get 401/403".
- `OpenApiSpecTest` (every route documented) must stay green; YAML must stay valid for Scalar and for the frontend's
  `npm run api:types`.

## 4. README (`README.md`)

1. Title, pitch, badges (CI workflow status, tests, PHP 8.4, Laravel 13, PostgreSQL 16, license), three big links:
   Live API docs, Live storefront, Frontend repo.
2. Screenshot of the new `/docs` (Playwright, saved to `docs/images/docs-page.png`).
3. Highlights — six bullets: staff 2FA, idempotent checkout, stock-safe cart, full-text search, USD/KHR pricing,
   Telegram/email order alerts.
4. Architecture — Mermaid flowchart: Frontend (Vercel) → API (Render, Docker/FrankenPHP) → PostgreSQL (Neon);
   side services Sentry, email (Brevo/Resend), Telegram.
5. Security — Mermaid sequence diagrams: customer login; staff login with 2FA.
6. Tech choices — table: choice · why · trade-off (interview talking points).
7. Run locally (5 commands), tests (current count, CI), deployment summary, links into `docs/`.
8. Facts corrected from code: test count, return window, feature list.

## 5. Verification

- Tests: `OpenApiSpecTest` green; new `DemoResetCommandTest` (empties cart; cancels only stale pending demo orders and
  restores stock; ignores other customers and non-pending orders; no-op without the demo account); maintenance
  registration asserted in the existing `tests/Feature/Platform/DueMaintenanceTest.php`.
- Full suite + `pint --test` clean.
- Runtime (Playwright against local API + DemoSeeder): `/docs` loads with no console errors; status badge shows
  Operational; demo login → `GET /cart` "Test request" returns 200; staff endpoint returns 401; dark mode; phone width.
  Screenshots shared with the owner.
- README: rendered check of Markdown and Mermaid syntax.

## Owner's part (after merge)

- Merge the PR; Render redeploys.
- Make sure the live database has the demo data (DemoSeeder) so the demo button works.
- Add the docs URL and README to the portfolio.
