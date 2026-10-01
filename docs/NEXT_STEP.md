# Next Step

Steps 1-7 are done: schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders (cash on delivery), returns + reviews (144 tests pass). Branch for Step 7: `feature/returns-reviews` (owner merges).

## Step 8 — Admin (decide these first, then implement on `feature/admin`)
Staff management (admin only):
1. New staff accounts: admin creates the account and the new staff member sets their own password through an emailed 6-digit code (reusing the OTP flow) — or admin types a temporary password?
2. Removing staff: `staff_users` has no active flag and audit logs keep a link to the staff member, so hard delete is blocked. Add `is_active` (new migration) and deactivate instead (tokens revoked, login refused)?
3. Lost phone: admin can reset another staff member's 2FA (they set it up again at next login). Admins cannot reset their own or demote the last admin — OK?

Dashboard:
4. Who can see it: admin only, or staff too?
5. Numbers: revenue (orders `delivered` only, or all not-cancelled?), order count by status, average order value, best-selling books, low-stock list, new customers, returns/refunds total. Periods: today, last 7 days, last 30 days, custom from/to?
6. Daily sales chart data (one row per day for the chosen period) — yes?

Other admin tools:
7. Audit log viewer: admin only, filter by staff member, action, entity, date — OK?
8. Exchange rate USD->KHR: admin enters it by hand (history kept, the latest one is used) — or fetch it automatically every day from the National Bank of Cambodia?
9. Customer management: staff can list/search customers and see their orders; admin can deactivate a customer (needs `customers.is_active`, new migration)?
10. CSV export of orders for a date range — now or later?

## After this, in order
logging/Sentry + API docs polish -> card/PayPal/KHQR payments once keys are provided -> frontend V2.

## Payments (blocked on owner)
No Stripe/PayPal/Bakong work until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Reusable pieces
- `App\Services\Auth\OtpService` (6-digit codes, works for `StaffUser`), `TotpService`.
- `App\Services\Admin\AuditLogger` — `admin_audit_logs` already has every staff change since Step 4.
- `App\Models\ExchangeRate::current()` + `App\Services\CurrencyService` already read the latest USD->KHR rate.
- Order/return/payment statuses are in `orders`, `order_status_history`, `returns`, `payments` (money in DECIMAL; use `App\Support\Money` for sums in PHP).
- Tests: `Tests\Concerns\ActsAsStaff` (`staffToken(admin: true)`), `Tests\Concerns\BuildsOrders` (`deliveredOrder()`).

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Bookly Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Repo: github.com/danishsary8/bookly_backend_v2 (work from main after merging feature/returns-reviews).
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-7 done (schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders with cash on delivery, returns + reviews; 144 tests pass).
Next: Step 8 admin (staff management, dashboard, audit log viewer, exchange rates, customer management). First walk me through the 10 decisions in docs/NEXT_STEP.md one by one with a recommendation each, and wait for my answers before coding. Then implement on branch feature/admin in small commits, reusing AuditLogger, OtpService and the existing models. No Stripe/PayPal/Bakong code. Don't modify existing migrations or existing feature code without asking. Human-style branch/commit names, no AI/tool names. Update docs/WORKLOG.md after each sub-step, docs/API.md for every new endpoint, and rewrite docs/NEXT_STEP.md for the following step at the end.

Output: end with a short "what was done / what's next" summary, plus a paste-ready prompt for the next session.
```
