# Next Step

Steps 1-2 are done (migrations + models for every domain). Work is paused for owner decisions before Step 3.

## Step 3 — Authentication (needs owner decisions first)
Decide each item, then implement on branch `feature/auth`:
1. Tokens: Sanctum personal access tokens valid 7 days, no refresh tokens (simple) — or short-lived access + refresh tokens (like V1)?
2. Unverified customers: get a token at registration but are blocked from cart/orders/reviews until they enter the email OTP — or no token until verified?
3. OTP: 6 digits, 15-minute expiry, stored hashed, older codes invalidated, max 5 attempts per minute per email+IP. OK?
4. Staff 2FA: authenticator-app TOTP (Google Authenticator/Authy), mandatory before any staff feature works — or email OTP instead?
5. First admin account: created with an artisan command on the server (`php artisan staff:create-admin`) — or a secret-protected register endpoint like V1's ADMIN_REGISTER_SECRET?
6. Social login: frontend signs in with Google/Facebook and sends the provider access token; backend verifies it with the provider and links to an existing account with the same email. OK?
7. Password rule: min 8 chars with letters and numbers. Stronger?

## Step 4+ (after auth), in order
catalog API + search -> addresses/cart/wishlist/coupons -> checkout + orders + inventory + low-stock alerts -> returns/reviews -> admin (staff mgmt, dashboard, audit log, exchange rates) -> logging/Sentry/API docs.

## Payments (blocked on owner)
Needs developer accounts and keys from the owner before integration:
`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`. Cash on delivery needs no keys and can be built with checkout.

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Book-Shop Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Folder: book-ecommerce-server-v2.
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md.
Status: Steps 1-2 done (27 migrations + models/enums/factories, 14 tests pass). Paused before auth.
Next: Step 3 auth. First walk me through the 7 auth decisions in docs/NEXT_STEP.md one by one with a recommendation each, wait for my answers, then implement on branch feature/auth in small commits. Do not start payment providers until I give API keys. Human-style branch/commit names, no AI/tool names. Log every finished step in docs/WORKLOG.md.
```
