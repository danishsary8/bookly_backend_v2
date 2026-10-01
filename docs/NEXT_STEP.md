# Next Step

Steps 1-8 are done: schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders (cash on delivery), returns + reviews, admin (staff, customers, exchange rates, audit log, dashboard) — 165 tests pass. Branch for Step 8: `feature/admin` (owner merges).

## Step 9 — Production readiness (decide these first, then implement on `feature/production-ready`)
1. Logging: structured JSON logs with a request ID (`X-Request-Id` header returned on every response and written into each log line) — OK? Log to stdout (for container hosting like Railway/Render) or to daily files?
2. Error tracking: add Sentry (`sentry/sentry-laravel`; owner creates a free Sentry project and provides `SENTRY_LARAVEL_DSN`). Send only server errors (5xx), with the user id but no email/name?
3. API documentation: generate an OpenAPI spec + browsable docs page (e.g. Scribe at `/docs`) from the routes — or keep the hand-written `docs/API.md`?
4. General API rate limit (e.g. 120 requests/min per user or IP) on top of the existing auth/checkout limits?
5. Health check: extend `/up` (or add `/api/v1/health`) to also check the database and queue?
6. Clean up: delete the Laravel template workflows that do not apply to this repo (`issues.yml`, `pull-requests.yml`, `update-changelog.yml`, `dependabot-auto-merge.yml`) and add a Pint (code style) check to CI?
7. Orders CSV export (postponed from Step 8) — now?
8. Demo data for the portfolio: `php artisan db:seed --class=DemoSeeder` with sample books, authors, a demo admin and customer?
9. Deployment target: Railway, Render, a VPS with Docker, or decide later? (Affects queue worker + scheduler setup.)
10. A security review pass of the whole API before payments are added?

## After this
card/PayPal/KHQR payments once keys are provided -> frontend V2 against `/api/v1`.

## Payments (blocked on owner)
No Stripe/PayPal/Bakong work until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Reusable pieces
- Services: `CartService`, `CouponService`, `CheckoutService`, `OrderStatusService`, `InventoryService`, `ReturnService`, `StaffManagementService`, `DashboardService`, `AuditLogger`, `CurrencyService`, `OtpService`, `TotpService`.
- `config/shop.php`: shipping fee, return window, timezone.
- Tests: `Tests\Concerns\ActsAsStaff`, `Tests\Concerns\BuildsOrders`. CI runs on PostgreSQL 16 for PHP 8.3/8.4/8.5.

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Bookly Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Repo: github.com/danishsary8/bookly_backend_v2 (work from main after merging feature/admin).
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-8 done (schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders with cash on delivery, returns + reviews, admin: staff, customers, exchange rates, audit log, dashboard; 165 tests pass).
Next: Step 9 production readiness (logging, error tracking, API docs, rate limits, health check, CI cleanup, demo data). First walk me through the 10 decisions in docs/NEXT_STEP.md one by one with a recommendation each, and wait for my answers before coding. Then implement on branch feature/production-ready in small commits. No Stripe/PayPal/Bakong code. Don't modify existing migrations or existing feature code without asking. Human-style branch/commit names, no AI/tool names. Update docs/WORKLOG.md after each sub-step, docs/API.md for every new endpoint, and rewrite docs/NEXT_STEP.md for the following step at the end.

Output: end with a short "what was done / what's next" summary, plus a paste-ready prompt for the next session.
```
