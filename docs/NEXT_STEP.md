# Next Step

Steps 1-9 are done: schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders (cash on delivery), returns + reviews, admin (staff, customers, exchange rates, audit log, dashboard), production readiness (JSON logs + request IDs, Sentry, rate limit, health check, CSV export, OpenAPI docs at `/docs`, demo seeder, CI with Pint, security review with 6 fixes) — 193 tests pass. Branch for Step 9: `feature/production-ready` (owner merges).

Payments are still blocked until the owner provides keys (see below), so the next step is getting the API online.

## Step 10 — Deployment (decide these first, then implement on `feature/deployment`)
1. Hosting: Railway, Render, Fly.io, or a VPS (e.g. DigitalOcean) with Docker? Recommendation: Railway or Render for a portfolio (managed PostgreSQL, HTTPS and deploys from GitHub; no server to look after).
2. Packaging: a `Dockerfile` (PHP-FPM + Nginx, or FrankenPHP) so the same image runs locally and on any host — or the host's PHP buildpack?
3. Processes: the API needs three: web, `php artisan queue:work` (emails, alerts) and `php artisan schedule:work` (daily token cleanup). Separate services, or one container with a process manager?
4. Trusted proxies: hosts put a load balancer in front, so `trustProxies` must be set or every visitor shares one IP for rate limits and `isSecure()` (HSTS) is wrong. Trust all proxies (fine on Railway/Render) or a fixed IP range?
5. Release steps: run `php artisan migrate --force`, `config:cache`, `route:cache` on each deploy — automatically in the start command, or by hand?
6. Email: real delivery for verification/reset codes — Resend, Mailgun, Postmark, or Gmail SMTP for the demo?
7. Domains: API on `api.<domain>` and frontend on `<domain>`, or the host's free subdomains for now? (Sets `APP_URL` and `CORS_ALLOWED_ORIGINS`.)
8. Database backups: rely on the host's automatic backups, or add a scheduled `pg_dump` to storage?
9. Production checklist values: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=json_stdout`, `LOG_REQUESTS=true`, `SENTRY_LARAVEL_DSN`, social login client ids — who creates which account (Sentry, Google, Facebook)?
10. Deploy from `main` automatically after CI passes, or only by hand?
11. Demo data on the live site: run `DemoSeeder` once (needs a one-off flag because it refuses to run in production), or start empty?
12. Staff token lifetime: keep 7 days (same as customers) or shorten for staff (e.g. 12 hours) now that it will be on the internet?

## After this
Frontend V2 against `/api/v1` (the OpenAPI file can generate a typed client) -> card/PayPal/KHQR payments once keys are provided.

## Payments (blocked on owner)
No Stripe/PayPal/Bakong work until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Reusable pieces
- Services: `CartService`, `CouponService`, `CheckoutService`, `OrderStatusService`, `InventoryService`, `ReturnService`, `StaffManagementService`, `DashboardService`, `AuditLogger`, `CurrencyService`, `OtpService`, `TotpService`, `SocialTokenVerifier`.
- `config/shop.php`: shipping fee, return window, timezone. `config/cors.php`: `CORS_ALLOWED_ORIGINS`.
- Every new endpoint must also go into `public/openapi.yaml` (`OpenApiSpecTest` fails otherwise) and `docs/API.md`.
- Tests: `Tests\Concerns\ActsAsStaff`, `Tests\Concerns\BuildsOrders`. CI: `code-style` (Pint) + tests on PostgreSQL 16 for PHP 8.3/8.4/8.5. Run `vendor/bin/pint` before committing.
- Health: `GET /api/v1/health` (point the host's health check here).

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Bookly Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Repo: github.com/danishsary8/bookly_backend_v2 (work from main after merging feature/production-ready).
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-9 done (schema, auth, catalog, shopping, checkout + orders with cash on delivery, returns + reviews, admin, production readiness: logs + request IDs, Sentry, health check, rate limit, OpenAPI docs at /docs, CSV export, DemoSeeder, CI with Pint, security review; 193 tests pass).
Next: Step 10 deployment. First walk me through the 12 decisions in docs/NEXT_STEP.md one by one with a recommendation each, and wait for my answers before coding. Then implement on branch feature/deployment in small commits. No Stripe/PayPal/Bakong code. Don't modify existing migrations or existing feature code without asking. Human-style branch/commit names, no AI/tool names. Update docs/WORKLOG.md after each sub-step, docs/API.md and public/openapi.yaml for every new endpoint, and rewrite docs/NEXT_STEP.md for the following step at the end.

Output: end with a short "what was done / what's next" summary, plus a paste-ready prompt for the next session.
```
