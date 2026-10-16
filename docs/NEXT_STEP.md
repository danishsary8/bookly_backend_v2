# Next step

## Now (2026-10-16): Overnight batch complete (waiting for owner merge)

All 11 tasks implemented, tested locally, and verified green on GitHub Actions CI.
Chain merge order (paired backend -> frontend):

| # | Branch | Repo | Starts from | What | CI Run |
|---|---|---|---|---|---|
| 1 | `feature/stock-history-restock` | backend | `main` | Stock history, restock dialog, running balance | 38781501413 |
| 2 | `feature/stock-history-restock` | frontend | `main` | Stock UI, restock modal, movement ledger | 38785172957 |
| 3 | `feature/refund-records` | backend | `feature/stock-history-restock` | Refund records, store credit coupons, balances | 38787355711 |
| 4 | `feature/refund-records` | frontend | `feature/stock-history-restock` | Refund modal, store credit card, customer view | 38789338825 |
| 5 | `feature/customer-notes-tags` | backend | `feature/refund-records` | Customer notes, tags, admin spend, resend verify | 38791250933 |
| 6 | `feature/customer-notes-tags` | frontend | `feature/refund-records` | CRM notes, tags UI, admin spend visibility | 38793512735 |
| 7 | `feature/book-publishing-bulk-csv` | backend | `feature/customer-notes-tags` | Draft/scheduled/live books, bulk actions, CSV | 38797002359 |
| 8 | `feature/book-publishing-bulk-csv` | frontend | `feature/customer-notes-tags` | Book publishing status, bulk toolbar, CSV preview | 38798474247 |
| 9 | `feature/khmer-emails-api` | backend | `feature/book-publishing-bulk-csv` | Khmer customer mailables, API localization | 38801068980 |
| 10 | `feature/khmer-emails-api` | frontend | `feature/book-publishing-bulk-csv` | Accept-Language header, locale profile sync | 38802897424 |
| 11 | `feature/back-in-stock-also-bought` | backend | `feature/khmer-emails-api` | Stock alerts, capped marketing outbox, also-bought | 38806032521 |
| 12 | `feature/back-in-stock-also-bought` | frontend | `feature/khmer-emails-api` | Stock alerts, recently viewed shelf, also bought | 38808521718 |
| 13 | `feature/khmer-search` | backend | `feature/back-in-stock-also-bought` | Trigram indexes, Khmer substring & ZWSP strip | 38809792242 |
| 14 | `feature/khmer-search` | frontend | `feature/back-in-stock-also-bought` | Khmer highlight, ZWSP strip, listbox a11y | 38810978216 |
| 15 | `feature/newsletter` | backend | `feature/khmer-search` | Newsletter subscription, confirm, unsubscribe, campaigns | 38812962321 |
| 16 | `feature/newsletter` | frontend | `feature/khmer-search` | Newsletter sign-up, confirm/unsub pages, admin campaigns | 38815769579 |
| 17 | `fix/security-leftovers` | backend | `feature/newsletter` | Public health status-only, admin health, passwordless close | 38818126862 |
| 18 | `fix/security-leftovers` | frontend | `feature/newsletter` | Health card, Sentry tunnel hardening, passwordless close | 38819141003 |
| 19 | `feature/strict-delete-trash` | backend | `fix/security-leftovers` | Strict delete with 2FA/password/title, 30-day trash | 38820479741 |
| 20 | `feature/strict-delete-trash` | frontend | `fix/security-leftovers` | Strict delete dialog, trash retention UI & restore | 38821762292 |
| 21 | `feature/staff-permissions-sessions` | backend | `feature/strict-delete-trash` | Granular staff permissions, active sessions, login log | 38840141886 |
| 22 | `feature/staff-permissions-sessions` | frontend | `feature/strict-delete-trash` | Staff security page, session controls, permissions UI | 38841418085 |
| 23 | `docs/overnight-batch` | backend | `feature/staff-permissions-sessions` | Final worklog, docs update, full verification | this branch |
| 24 | `docs/overnight-batch` | frontend | `feature/staff-permissions-sessions` | Final worklog, roadmap ticks, merge sequence | this branch |

## Owner steps after merging
1. In Render / production env, optionally set `MARKETING_DAILY_LIMIT=100` (defaults to 100).
2. Run database migrations: `php artisan migrate --force`.
3. Ensure cron/scheduler triggers `php artisan marketing:send-outbox` and `php artisan books:purge-trash` daily.

## Earlier (2026-10-15)

All 10 planned steps are done (owner merges `feature/deployment` to finish Step 10):

| Step | What | Status |
| --- | --- | --- |
| 1-2 | Schema (27 domain tables, 3NF), models, factories | done |
| 3 | Auth: customers (email OTP, Google/Facebook) and staff (mandatory TOTP 2FA) | done |
| 4 | Catalog + staff catalog management, audit log, inventory movements | done |
| 5 | Addresses, cart, wishlist, coupons | done |
| 6 | Checkout + orders (cash on delivery, idempotency, safe stock locking) | done |
| 7 | Returns and verified-purchase reviews | done |
| 8 | Admin: staff, customers, exchange rates, audit viewer, dashboard | done |
| 9 | Production readiness: logs + request IDs, Sentry, rate limit, health, OpenAPI `/docs`, CSV export, demo seeder, security review | done |
| 10 | Deployment: Docker image (FrankenPHP), Railway infrastructure as code, CI smoke test, deployment guide | done |

200 tests pass; CI runs tests on PHP 8.4/8.5 (PostgreSQL 16), code style, a Railway file type-check and a
Docker build + smoke test (PostgreSQL 18).

## Explicitly deferred: payments (blocked on owner)

Card (Stripe), PayPal and Bakong KHQR payments are the **one deferred item**. Nothing is started; the schema is ready
(`payments`, `payment_webhook_logs` with idempotent `event_id`, order status `paid`, `PaymentProvider` enum).
Start only when the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`,
`PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Owner actions to go live (not code)

Free route (chosen): `docs/DEPLOYMENT.md` → "Free option: Render + Neon" (F1-F6). Paid alternative: Follow `docs/DEPLOYMENT.md`: create the Railway project, add shared variables, `railway config apply`, generate the
domain, turn on **Wait for CI** for web/worker/scheduler, create the first admin, optionally seed demo data, enable
daily database backups. For real customer emails, verify a domain in Resend.

## Possible later work (not planned)
- Frontend V2 against `/api/v1` (the OpenAPI file can generate a typed client).
- Custom domain (`APP_URL`, `CORS_ALLOWED_ORIGINS`, Resend domain).
- Point-in-time recovery for the database, uptime monitor on `/api/v1/health`.

## Reusable pieces
- Services: `CartService`, `CouponService`, `CheckoutService`, `OrderStatusService`, `InventoryService`, `ReturnService`, `StaffManagementService`, `DashboardService`, `AuditLogger`, `CurrencyService`, `OtpService`, `TotpService`, `SocialTokenVerifier`.
- Config: `config/shop.php` (shipping fee, return window, timezone), `config/cors.php` (`CORS_ALLOWED_ORIGINS`), `config/app.php` (`TRUSTED_PROXIES`, `DEMO_SEED_ALLOWED`), `config/auth.php` (`STAFF_TOKEN_HOURS`).
- Every new endpoint must go into `public/openapi.yaml` (`OpenApiSpecTest` fails otherwise) and `docs/API.md`.
- Tests: `Tests\Concerns\ActsAsStaff`, `Tests\Concerns\BuildsOrders`. Run `vendor/bin/pint` before committing.
- Deploy: `Dockerfile` + `docker/start.sh` (`CONTAINER_ROLE`), `.railway/railway.ts`, `docs/DEPLOYMENT.md`.

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Bookly Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Repo: github.com/danishsary8/bookly_backend_v2 (work from main after merging feature/deployment).
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md, docs/DEPLOYMENT.md.
Status: the roadmap is complete (steps 1-10: schema, auth, catalog, shopping, cash-on-delivery checkout, returns + reviews, admin, production readiness, deployment with Docker + Railway infrastructure as code; 200 tests pass, CI includes a Docker smoke test). The only deferred item is Stripe/PayPal/Bakong payments, pending API keys.
Next: [choose] (a) help me go live by following docs/DEPLOYMENT.md step by step, or (b) payments — only if I give you the API keys; walk me through the design decisions first and wait for my answers. Work on a feature branch in small commits, human-style names, no AI/tool names. Don't modify existing migrations or existing feature code without asking. Update docs/WORKLOG.md after each sub-step and docs/API.md + public/openapi.yaml for every new endpoint.

Output: end with a short "what was done / what's next" summary, plus a paste-ready prompt for the next session.
```
