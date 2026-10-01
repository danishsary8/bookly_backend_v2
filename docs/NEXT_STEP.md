# Next Step

Steps 1-3 are done: migrations, models, and authentication (48 tests pass). Branch for auth: `feature/auth` (owner merges).

## Step 4 — Catalog API (decide these first, then implement on `feature/catalog`)
1. Book images: upload through the API to Cloudinary (like V1) — or store image URLs only for now?
2. Listing: page size (suggest 20, max 100), filters (category, author, series, publisher, format, language, price range, in stock), sorts (newest, price, title, best rated, relevance for search). Anything to add/remove?
3. Prices in KHR: include `price_khr` next to `price_usd` in every response using the latest `exchange_rates` row — or only convert at checkout?
4. Who manages the catalog: both `staff` and `admin` (create/edit books, variants, authors, categories, series, publishers)? Delete = admin only?
5. Removing a variant that already appears in orders: block delete and add an `is_active` flag (needs a small migration) — or only allow setting stock to 0?

## After the catalog, in order
addresses/cart/wishlist/coupons -> checkout + orders + inventory + low-stock alerts (cash on delivery first) -> returns/reviews -> admin (staff management, dashboard, audit log, exchange rates) -> logging/Sentry/API docs.

## Payments (blocked on owner)
Do not start until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Owner to-do outside the code
- Create Google and Facebook developer apps; put the client id/secret in `.env` (see `.env.example`).
- Configure real SMTP (`MAIL_*`) so OTP emails are delivered; run a queue worker (`php artisan queue:work`) because emails are queued.
- In production, run the scheduler cron (`php artisan schedule:run` every minute) for token pruning.

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Book-Shop Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Folder: book-ecommerce-server-v2.
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-3 done (schema, models, customer + staff auth with OTP, social login, staff TOTP 2FA; 48 tests pass).
Next: Step 4 catalog API. First walk me through the 5 catalog decisions in docs/NEXT_STEP.md one by one with a recommendation each, wait for my answers, then implement on branch feature/catalog in small commits. Staff routes must use ['auth:sanctum','abilities:staff','staff.2fa']. No payment provider work until I give API keys. Human-style branch/commit names, no AI/tool names. Log every finished step in docs/WORKLOG.md as you go.
```
