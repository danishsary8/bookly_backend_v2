# Next step

## Now (2026-10-08)
Telegram codes work on the live API (address `gatewayapi.telegram.org`, token IP restriction lifted). The Railway move is
removed from the plan: the API stays on Render (`docs/RAILWAY_PLAN.md` is shelved). The full ordered to-do list and the
next session's prompt live in the frontend repo: `docs/ROADMAP.md` and `docs/NEXT_STEP.md`.

## Roadmap complete

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

200 tests pass; CI runs tests on PHP 8.3/8.4/8.5 (PostgreSQL 16), code style, a Railway file type-check and a
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
