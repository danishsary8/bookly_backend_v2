# Next Step

## Step 2 — Eloquent models + relationships (not started)
Branch suggestion: `feature/eloquent-models`.
1. One model per table in `app/Models`; `Customer` and `StaffUser` are authenticatable with `HasApiTokens`, `getAuthPasswordName()` returning `password_hash`, `two_factor_secret` encrypted cast.
2. Relationships exactly as in the ER diagram (books<->authors/categories pivots, variants, orders graph, polymorphic verification tokens).
3. Casts (decimal:2, jsonb arrays, datetimes), SoftDeletes only on Customer/Order/Book, `$fillable`.
4. Model factories for every model + a relationship test file.
5. Fix `DatabaseSeeder` (still references the removed `User`).
Then, in order: auth (Sanctum + OTP + social login + staff 2FA) -> catalog API + search -> cart/wishlist -> checkout/orders/payments/webhooks -> inventory/alerts -> returns/reviews -> admin/audit/dashboard -> logging/Sentry/OpenAPI docs.

Decide with the owner first: open question 1 in WORKLOG.md (address snapshot).

## Paste this into Claude chat / VS Code CLI to restart cleanly
```
Project: Book-Shop Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Repo folder: book-ecommerce-server-v2.
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md.
Status: Step 1 done (skeleton + 27 migrations + notifications, verified on Postgres, 8 tests pass).
Start Step 2: Eloquent models + relationships + factories. Before coding, ask me about open question 1 (order shipping address snapshot) and confirm the plan in 5 bullets. Work in small steps, wait for my "confirm" after each, use human-style branch/commit names with no AI/tool names, and log every finished step in docs/WORKLOG.md.
```
