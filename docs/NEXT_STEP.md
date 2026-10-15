# Next step

## Now (2026-10-15)
Security review done; the fixes wait for the owner's merge (order and owner steps in the frontend repo's
docs/NEXT_STEP.md): `fix/otp-wrong-guess-limit` first, then each pair backend → frontend, `fix/backup-public-key`,
then the two `docs/security-review` branches. After merging: make the backup key (DEPLOYMENT.md → 1b) and run the
backup workflow once by hand.

## Earlier (2026-10-12)
Owner action after merging `feature/scheduler-on-ping`: in Render add `RUN_MAINTENANCE_ON_REQUEST` = `true` (the housekeeping jobs then run from web traffic; the 5-minute uptime ping is enough).

## Earlier (2026-10-10)
The full ordered to-do list and the next session's prompt live in the frontend repo: docs/ROADMAP.md and docs/NEXT_STEP.md. Open items are tracked there.

- **Backup alert bot and secrets**: done and verified. The alert bot was created, repository secrets were added, and the workflow manual trigger with `test_alert` ran green with Telegram delivery confirmed.
- **Backup alert rejection reason (`chore/backup-alert-reason`)**: done and merged. Enhanced the Telegram failure alert step in `.github/workflows/db-backup.yml` to preserve curl responses, parse error descriptions using `jq` (with a 200-char truncated fallback), and log a descriptive GitHub Actions error line `::error::Telegram rejected the alert (HTTP <code>): <description>` without leaking bot tokens or chat IDs. Tested against a local mock server for HTTP 200, 400 (chat not found), and non-JSON 502 responses. Updated troubleshooting notes in `docs/DEPLOYMENT.md`.
- **Safe backend dependency updates (`chore/backend-safe-updates`)**: done and verified (2026-10-12). Pinned `config.platform.php` to `8.3.0` so dependencies remain installable and compatible with PHP 8.3 CI jobs while skipping PHP 8.4+ major bumps (Symfony 8.1 requires PHP >= 8.4.1). 13 packages updated within minor/patch constraints; 0 major changes, 0 downgrades. Production-style installation (`composer install --no-dev --optimize-autoloader`), platform requirements, Pint formatting, and test suite (319 tests, 1931 assertions) all verified.

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
