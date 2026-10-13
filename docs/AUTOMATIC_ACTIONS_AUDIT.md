# Automatic Actions Audit

**Scope:** Read-only audit of automatic actions (event-triggered, scheduled, background jobs, database triggers) in Bookly Backend V2.  
**Production Target:** Render Free Web Service (Docker) + Neon PostgreSQL (`QUEUE_CONNECTION=sync`, no background queue worker or scheduler service).  
**Alternative Target Reference:** Railway paid deployment (`.railway/railway.ts`: separate `web`, `worker`, `scheduler` containers).

---

> **Status update 2026-10-13 (after this audit):** fixed and merged: housekeeping now runs from requests (`RUN_MAINTENANCE_ON_REQUEST`, covers token, unverified/closed customers, failed jobs and batches pruning); customer emails for every order change and return step (mail failure never blocks); owner Telegram alert for new orders and low stock. Still open from this list: welcome email, password-changed email, back-in-stock, review notices, orphan carts / audit-log / cache retention, exchange-rate refresh, TOTP lockout. See the frontend `docs/ROADMAP.md` for the order of work.

## 1. Summary

### Action Counts
- **Existing Automatic Actions Identified:** 11
- **Expected-Triggers Checklist Evaluation:**
  - **PRESENT:** 10
  - **PARTIAL:** 8
  - **MISSING:** 15
  - **NOT APPLICABLE:** 1

### Top 5 Risks
1. **Scheduler never executes in production ([docker/start.sh#L12-L34](../docker/start.sh#L12-L34), [docs/DEPLOYMENT.md#L21-L34](../docs/DEPLOYMENT.md#L21-L34))**: The live free Render service runs only `CONTAINER_ROLE=web`. `schedule:run` / `schedule:work` is never executed. Scheduled tasks (`sanctum:prune-expired`, `customers:prune-unverified`, `customers:erase-closed`) never run on a schedule. Expired Sanctum tokens accumulate forever because unlike customers, Sanctum token pruning has no runtime fallback.
2. **Synchronous email dispatch on live requests ([docs/DEPLOYMENT.md#L30-L34](../docs/DEPLOYMENT.md#L30-L34), [bootstrap/app.php#L54-L60](../bootstrap/app.php#L54-L60))**: On Render free, `QUEUE_CONNECTION=sync` means queued notifications (`ShouldQueue`) are dispatched inline during customer HTTP requests (e.g., checkout, registration, reset password). Network latency or provider outages (Resend/Brevo) directly delay or crash user-facing requests with HTTP 503.
3. **No order status transition customer notifications ([app/Services/Orders/OrderStatusService.php#L53-L86](../app/Services/Orders/OrderStatusService.php#L53-L86))**: When staff change an order's status to `processing`, `shipped`, `delivered`, or `cancelled`, zero emails or notices are dispatched to the customer. Only the initial checkout triggers an email.
4. **No staff alert on new orders ([app/Services/Orders/CheckoutService.php#L148-L157](../app/Services/Orders/CheckoutService.php#L148-L157))**: When a new order is placed, only the customer receives an email. No email, Telegram message, or database notification is created for store staff/admin unless physical stock hits the low-stock threshold.
5. **No return/refund notifications ([app/Services/Returns/ReturnService.php#L130-L177](../app/Services/Returns/ReturnService.php#L130-L177))**: When a return is requested, approved, rejected, or refunded, no notification is sent to the customer or staff. Customers receive no confirmation receipt or update when their refund is issued.

---

## 2. Production Reality Check

### Evidence from Infrastructure and Configuration
1. **Render Free Container Execution:**
   - In [docker/start.sh#L12-L34](../docker/start.sh#L12-L34), role selection is controlled by `CONTAINER_ROLE`:
     ```sh
     case "${CONTAINER_ROLE:-web}" in
       web)
         ...
         exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
         ;;
       worker)
         exec php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=3
         ;;
       scheduler)
         exec php artisan schedule:work
         ;;
     ```
   - In [docs/DEPLOYMENT.md#L21-L34](../docs/DEPLOYMENT.md#L21-L34) and the environment table ([docs/DEPLOYMENT.md#L47-L65](../docs/DEPLOYMENT.md#L47-L65)), the Render free tier runs a single web instance (`CONTAINER_ROLE=web`). It explicitly states:
     > *"Differences from the Railway setup: no queue worker (`QUEUE_CONNECTION=sync`, emails are sent during the request... no scheduler (expired tokens are still rejected, they just stay in the table)..."*
   - Render free has no cron jobs, no background worker service, and sleeps after ~15 minutes without traffic.

2. **Is the Laravel Scheduler (`schedule:run` / `schedule:work`) Executed in Production?**
   - **NO.** The scheduler **never runs** in production on the live Render free tier. There is no host cron, no background process invoking `php artisan schedule:run`, and no separate service running `CONTAINER_ROLE=scheduler`.
   - On the paid Railway setup ([.railway/railway.ts#L59-L65](../.railway/railway.ts#L59-L65)), a dedicated `scheduler` service running `CONTAINER_ROLE=scheduler` (`schedule:work`) is defined, but it is not deployed on Render.

3. **Is a Queue Worker Running, or is `QUEUE_CONNECTION=sync`?**
   - In production on Render, **`QUEUE_CONNECTION=sync`** is enforced ([docs/DEPLOYMENT.md#L56](../docs/DEPLOYMENT.md#L56)).
   - No queue worker process (`php artisan queue:work`) is running.
   - Any notification implementing `ShouldQueue` (such as `OrderPlacedNotification`, `OtpCodeNotification`, `StaffInvitationNotification`, `AccountSecurityNotice`, `AccountReopenedNotification`, `LowStockNotification`) is executed synchronously during the web HTTP request cycle.
   - If mail sending fails or times out, `bootstrap/app.php` catches `TransportExceptionInterface` and renders an HTTP 503 JSON error: `"We couldn't send the email just now. Please try again in a few minutes."` ([bootstrap/app.php#L54-L60](../bootstrap/app.php#L54-L60)).

### Scheduled Commands Reality Table
The commands scheduled in [routes/console.php#L11-L21](../routes/console.php#L11-L21):

| Scheduled Command | Defined Cadence | Production Reality | Evidence & Explanation |
| --- | --- | --- | --- |
| `sanctum:prune-expired --hours=24` | `daily()` | **Never runs** | Defined in [routes/console.php#L12](../routes/console.php#L12). The scheduler is not executed on Render. Unlike customer pruning, there is **no** runtime fallback anywhere in the codebase. Expired personal access tokens remain in the `personal_access_tokens` table indefinitely. |
| `customers:prune-unverified` | `hourly()` | **Never runs via scheduler** (Runs opportunistically on requests) | Defined in [routes/console.php#L16](../routes/console.php#L16). The scheduler never runs it. However, it is opportunistically executed at most once per hour inside HTTP request lifecycles via `UnverifiedCustomers::pruneIfDue()` in [app/Http/Controllers/Api/V1/Customer/AuthController.php#L60](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L60) and [app/Http/Controllers/Api/V1/Staff/CustomerController.php#L33](../app/Http/Controllers/Api/V1/Staff/CustomerController.php#L33) using cache locks ([app/Services/Customers/UnverifiedCustomers.php#L89-L94](../app/Services/Customers/UnverifiedCustomers.php#L89-L94)). |
| `customers:erase-closed` | `daily()` | **Never runs via scheduler** (Runs opportunistically on requests) | Defined in [routes/console.php#L20](../routes/console.php#L20). The scheduler never runs it. It is executed opportunistically at most once per hour when staff view the customer list via `ClosedCustomers::eraseIfDue()` in [app/Http/Controllers/Api/V1/Staff/CustomerController.php#L34](../app/Http/Controllers/Api/V1/Staff/CustomerController.php#L34) and [app/Services/Customers/ClosedCustomers.php#L127-L132](../app/Services/Customers/ClosedCustomers.php#L127-L132). If staff never view `/staff/customers`, closed accounts older than 30 days are never erased. |

---

## 3. Inventory of Existing Automatic Actions

| Trigger | Action | Code Location | Sync or Queued | Failure Behaviour | Runs in Production? |
| --- | --- | --- | --- | --- | --- |
| Customer finishes checkout (`POST /checkout`) | Sends `OrderPlacedNotification` (email with order summary) to customer | [app/Services/Orders/CheckoutService.php#L149](../app/Services/Orders/CheckoutService.php#L149) | Implements `ShouldQueue`, but runs **synchronously** due to `QUEUE_CONNECTION=sync` | Caught by `bootstrap/app.php#L54-L60`; request answers HTTP 503; order transaction has already committed | **Yes** (inline during request) |
| Customer checkout drops physical variant stock to or below threshold | Writes `LowStockNotification` to `notifications` database table for all staff | [app/Services/Orders/CheckoutService.php#L154](../app/Services/Orders/CheckoutService.php#L154) | Implements `ShouldQueue`, but runs **synchronously** | If database insert fails, unhandled exception returns HTTP 500 (reported to Sentry) | **Yes** (inline during request) |
| Staff or customer cancels order (`POST /orders/{id}/cancel` or `POST /staff/orders/{id}/status`) | Restores physical stock, decrements coupon `used_count`, marks pending payment `failed` | [app/Services/Orders/OrderStatusService.php#L89-L104](../app/Services/Orders/OrderStatusService.php#L89-L104) | **Synchronous** (inside DB transaction) | DB transaction rolls back; cancellation rejected with error | **Yes** |
| Staff marks return as `refunded` (`POST /staff/returns/{id}/refund`) | Restocks physical variant (`restockFromReturn`), computes refund amount, auto-marks order `returned` if all physical items returned | [app/Services/Returns/ReturnService.php#L151-L177](../app/Services/Returns/ReturnService.php#L151-L177) | **Synchronous** (inside DB transaction) | DB transaction rolls back; return status not updated | **Yes** |
| Customer requests sign-up, email verification, or password reset | Generates 6-digit OTP code and dispatches `OtpCodeNotification` email | [app/Services/Auth/OtpService.php#L23](../app/Services/Auth/OtpService.php#L23) | Implements `ShouldQueue`, but runs **synchronously** | In registration ([AuthController.php#L66-L85](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L66-L85)), transaction wraps OTP creation and user creation; mail failure rolls back user creation and yields HTTP 503 | **Yes** (inline during request) |
| Customer requests email change (`POST /me/email`) | Caches pending email and sends `OtpCodeNotification` to new email address | [app/Services/Auth/OtpService.php#L35](../app/Services/Auth/OtpService.php#L35) | Implements `ShouldQueue`, but runs **synchronously** | Exception caught by `bootstrap/app.php`, yields HTTP 503 | **Yes** (inline during request) |
| Customer verifies email change (`POST /me/email/verify`) | Dispatches `AccountSecurityNotice` email to old verified email address | [app/Http/Controllers/Api/V1/Customer/EmailController.php#L101](../app/Http/Controllers/Api/V1/Customer/EmailController.php#L101) | Implements `ShouldQueue`, but runs **synchronously** | Sent after DB transaction commits; if sending fails, HTTP 503 returned | **Yes** (inline during request) |
| Customer connects or disconnects social login (`POST/DELETE /me/connections/{provider}`) | Dispatches `AccountSecurityNotice` email to customer | [app/Http/Controllers/Api/V1/Customer/ConnectionController.php#L94](../app/Http/Controllers/Api/V1/Customer/ConnectionController.php#L94) | Implements `ShouldQueue`, but runs **synchronously** | Sent after DB save; if sending fails, HTTP 503 returned | **Yes** (inline during request) |
| Admin creates new staff user (`POST /staff/members`) | Dispatches `StaffInvitationNotification` with 72h setup code | [app/Services/Staff/StaffManagementService.php#L60](../app/Services/Staff/StaffManagementService.php#L60) | Implements `ShouldQueue`, but runs **synchronously** | Uses `afterCommit()`; if mail delivery fails, HTTP 503 returned | **Yes** (inline during request) |
| Admin reopens closed customer account (`POST /staff/customers/{id}/reopen`) | Dispatches `AccountReopenedNotification` email to customer | [app/Http/Controllers/Api/V1/Staff/CustomerController.php#L117](../app/Http/Controllers/Api/V1/Staff/CustomerController.php#L117) | Implements `ShouldQueue`, but runs **synchronously** | If mail delivery fails, HTTP 503 returned | **Yes** (inline during request) |
| Container startup (`docker/start.sh`) | Runs `php artisan telegram:webhook` to register webhook with Telegram Bot API | [docker/start.sh#L18](../docker/start.sh#L18) | **Synchronous** at container boot | Swallows failures (`|| true`); reports `TelegramBotProblem` to Sentry; boot continues | **Yes** (at startup) |

---

## 4. Expected-Triggers Checklist

| Domain | Expected Trigger / Action | Status | Evidence & Details |
| --- | --- | --- | --- |
| **Orders** | Order placed -> customer confirmation email | **PRESENT** | [app/Services/Orders/CheckoutService.php#L149](../app/Services/Orders/CheckoutService.php#L149) sends `OrderPlacedNotification` to customer upon successful order placement. |
| **Orders** | Staff/owner notified of new order (Telegram or email) | **MISSING** | [app/Services/Orders/CheckoutService.php#L148-L157](../app/Services/Orders/CheckoutService.php#L148-L157) only notifies staff if stock falls to or below `low_stock_threshold`. No general new-order notification exists. |
| **Orders** | Status change (confirmed, packed, shipped, delivered, cancelled) -> customer email | **MISSING** | [app/Services/Orders/OrderStatusService.php#L53-L86](../app/Services/Orders/OrderStatusService.php#L53-L86) updates order status and logs history/audit records, but dispatches no notification or email to customer. |
| **Orders** | Order cancelled -> stock restored and logged | **PRESENT** | [app/Services/Orders/OrderStatusService.php#L89-L98](../app/Services/Orders/OrderStatusService.php#L89-L98) invokes `InventoryService::restoreForCancellation()`, logging an `adjustment` inventory movement referencing the order. |
| **Orders** | Delivered -> receipt email/pdf | **MISSING** | [app/Services/Orders/OrderStatusService.php#L72-L75](../app/Services/Orders/OrderStatusService.php#L72-L75) updates COD payment to `succeeded`, but dispatches no receipt to the customer. |
| **Orders** | Status history row written on every change | **PRESENT** | [app/Services/Orders/OrderStatusService.php#L78](../app/Services/Orders/OrderStatusService.php#L78) and [L49](../app/Services/Orders/OrderStatusService.php#L49) write to `order_status_history` on every transition. |
| **Orders** | Refund/return requested, approved, rejected, refunded -> customer email & staff notice | **MISSING** | [app/Services/Returns/ReturnService.php#L74-L207](../app/Services/Returns/ReturnService.php#L74-L207) records return rows, status changes, and admin audit logs, but sends zero emails or notices to customers or staff. |
| **Stock** | Sale / cancel / return / adjustment writes inventory movement | **PRESENT** | [app/Services/Inventory/InventoryService.php#L17-L73](../app/Services/Inventory/InventoryService.php#L17-L73) writes an `inventory_movements` record for every change (`sale`, `adjustment`, `return`, `restock`). |
| **Stock** | Low-stock alert to staff | **PARTIAL** | [app/Services/Orders/CheckoutService.php#L151-L156](../app/Services/Orders/CheckoutService.php#L151-L156) writes database notification `LowStockNotification` for staff users. However, it does not send Telegram or email alerts; staff must query `GET /staff/notifications`. |
| **Stock** | Out-of-stock handling (cart/checkout refuses, book shows out of stock) | **PRESENT** | In [app/Services/Cart/CartService.php#L97-L105](../app/Services/Cart/CartService.php#L97-L105), cart blocks checkout (`can_checkout: false`, `out_of_stock`, `insufficient_stock`). In [app/Http/Resources/BookVariantResource.php](../app/Http/Resources/BookVariantResource.php), public variant displays `in_stock: false`. |
| **Stock** | "Tell me when it is back" (back-in-stock notification) | **MISSING** | No back-in-stock subscriptions, table, or notification mechanism exists. |
| **Accounts** | Verification email on sign-up | **PRESENT** | [app/Http/Controllers/Api/V1/Customer/AuthController.php#L80](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L80) issues email OTP via `OtpService::issue()`. (Optional when `verify_by=telegram`). |
| **Accounts** | Welcome email after verification | **MISSING** | [app/Http/Controllers/Api/V1/Customer/AuthController.php#L142-L160](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L142-L160) verifies email and returns JSON response; no welcome email is sent. |
| **Accounts** | Password changed -> security email | **MISSING** | [app/Http/Controllers/Api/V1/Customer/ProfileController.php#L36-L52](../app/Http/Controllers/Api/V1/Customer/ProfileController.php#L36-L52) changes password and revokes other tokens, but does not send an email alert. |
| **Accounts** | Email changed -> notice to old address | **PRESENT** | [app/Http/Controllers/Api/V1/Customer/EmailController.php#L101-L105](../app/Http/Controllers/Api/V1/Customer/EmailController.php#L101-L105) sends `AccountSecurityNotice` to the old verified email address. |
| **Accounts** | Phone confirmed (notice or verification) | **PRESENT** | Confirmed via Telegram bot webhook contact sharing ([app/Http/Controllers/Api/V1/TelegramWebhookController.php](../app/Http/Controllers/Api/V1/TelegramWebhookController.php)); phone verified at timestamp set. |
| **Accounts** | Failed-login lockout and TOTP lockout | **PARTIAL** | Customer login has IP/email rate limiting (5/min, 30/min per IP via `auth` limiter in [app/Providers/AppServiceProvider.php#L32-L39](../app/Providers/AppServiceProvider.php#L32-L39)) and wrong code lockout (5 attempts in [OtpService.php#L101](../app/Services/Auth/OtpService.php#L101)). Staff 2FA locks out for 15 min after 5 wrong codes on the account ([app/Http/Controllers/Api/V1/Staff/AuthController.php#L90-L95](../app/Http/Controllers/Api/V1/Staff/AuthController.php#L90-L95)). However, there is no permanent account lockout or administrative unlock mechanism. |
| **Accounts** | Expired verification/OTP/Telegram link cleanup | **PARTIAL** | Verification tokens check expiration at query time (`expires_at > now()` in [OtpService.php#L88](../app/Services/Auth/OtpService.php#L88)). Telegram links expire in cache after 10 min ([TelegramLinks.php#L27](../app/Services/Telegram/TelegramLinks.php#L27)). However, old rows in `verification_tokens` table are never pruned. |
| **Accounts** | Sanctum expired token pruning (`sanctum:prune-expired`) | **MISSING** | Scheduled in [routes/console.php#L12](../routes/console.php#L12), but the scheduler **never runs** in production on Render, and there is no runtime fallback. |
| **Accounts** | Account deletion requested -> data erased after 30 days | **PARTIAL** | Scheduled in [routes/console.php#L20](../routes/console.php#L20) (`customers:erase-closed`), but scheduler never runs. Runs opportunistically **only** when staff view `/staff/customers` via [app/Http/Controllers/Api/V1/Staff/CustomerController.php#L34](../app/Http/Controllers/Api/V1/Staff/CustomerController.php#L34). If staff do not visit that page, accounts are never erased. |
| **Accounts** | Reopen closed account within 30 days | **PRESENT** | [app/Http/Controllers/Api/V1/Staff/CustomerController.php#L104-L121](../app/Http/Controllers/Api/V1/Staff/CustomerController.php#L104-L121) allows admin to reopen within 30 days and sends `AccountReopenedNotification`. |
| **Accounts** | Staff invitation email and expiry | **PRESENT** | [app/Services/Staff/StaffManagementService.php#L44-L60](../app/Services/Staff/StaffManagementService.php#L44-L60) generates 72-hour setup token and sends `StaffInvitationNotification`. |
| **Accounts** | Staff password reset | **PRESENT** | [app/Http/Controllers/Api/V1/Staff/AuthController.php#L164-L195](../app/Http/Controllers/Api/V1/Staff/AuthController.php#L164-L195) sends 15-minute OTP code via email; reset preserves 2FA requirement. |
| **Data Hygiene** | Failed jobs and job batches pruning | **MISSING** | No scheduled `queue:prune-failed` or `queue:prune-batches` commands are registered in `routes/console.php`. `failed_jobs` and `job_batches` tables grow unbounded. |
| **Data Hygiene** | Cache / session cleanup | **PARTIAL** | Database cache entries expire based on timestamp when queried, but expired cache rows are never pruned via `cache:prune-stale`. Web sessions are disabled (`SESSION_DRIVER=array`, no session middleware in [bootstrap/app.php#L34](../bootstrap/app.php#L34)). |
| **Data Hygiene** | Soft-deleted records purge after 30 days (Trash) | **NOT APPLICABLE** | Per project rules ([docs/PROJECT_CONTEXT.md#L48-L49](../docs/PROJECT_CONTEXT.md#L48-L49)), soft deletes on `books`, `orders`, and `customers` are intentional permanent history (books can be restored by admin, orders and erased customer records remain for accounting). |
| **Data Hygiene** | Orphan cart cleanup | **MISSING** | Inactive/abandoned carts in `carts` and `cart_items` tables are never pruned. They stay indefinitely. |
| **Data Hygiene** | Old audit log retention/pruning policy | **MISSING** | No retention window or automated pruning command exists for `admin_audit_logs`, `inventory_movements`, or `payment_webhook_logs`. |
| **Money/Catalog** | Exchange rate refresh | **PARTIAL** | Exchange rates are entered manually by admins (`POST /staff/exchange-rates`). No automatic fetching from external FX APIs. System supports future `effective_at` dates ([app/Models/ExchangeRate.php#L22-L26](../app/Models/ExchangeRate.php#L22-L26)). |
| **Money/Catalog** | Coupon expiry and usage-limit enforcement | **PRESENT** | Evaluated on checkout and preview via [app/Services/Cart/CouponService.php#L23-L44](../app/Services/Cart/CouponService.php#L23-L44) (`unusableReason()`, single use per customer, `max_uses`). |
| **Money/Catalog** | Price/stock consistency checks | **PRESENT** | In [app/Services/Orders/CheckoutService.php#L75-L87](../app/Services/Orders/CheckoutService.php#L75-L87), cart lines are locked in ID order, prices re-verified against live variant prices, and stock re-checked before order creation. |
| **Content** | Review created/hidden -> notice | **MISSING** | Reviews are published immediately upon creation ([app/Http/Controllers/Api/V1/Customer/ReviewController.php#L48](../app/Http/Controllers/Api/V1/Customer/ReviewController.php#L48)). Staff hide/show via [Staff\Reviews\ReviewController.php#L41-L62](../app/Http/Controllers/Api/V1/Staff/Reviews/ReviewController.php#L41-L62). No notification is sent to author or staff. |
| **Content** | Low-rated review alert | **MISSING** | No automatic alert to staff or Telegram when a customer posts a 1- or 2-star review. |
| **Operations** | Telegram webhook re-registered at start & bot health check | **PRESENT** | Webhook registered at container startup ([docker/start.sh#L18](../docker/start.sh#L18)) and checked every 10 min on request / health check ([app/Services/Telegram/TelegramBot.php#L75-L90](../app/Services/Telegram/TelegramBot.php#L75-L90), [app/Http/Controllers/Api/V1/HealthController.php#L45-L82](../app/Http/Controllers/Api/V1/HealthController.php#L45-L82)). |
| **Operations** | `/health` degraded alert (external UptimeRobot) | **PARTIAL** | `GET /api/v1/health` endpoint reports `status: ok/degraded/down` ([app/Http/Controllers/Api/V1/HealthController.php#L17-L43](../app/Http/Controllers/Api/V1/HealthController.php#L17-L43)). UptimeRobot configuration is external/documented ([docs/DEPLOYMENT.md#L93-L95](../docs/DEPLOYMENT.md#L93-L95)). |
| **Operations** | Nightly DB backup + failure alert (GitHub Actions) | **PRESENT** | Automated workflow [.github/workflows/db-backup.yml#L1-L162](../.github/workflows/db-backup.yml#L1-L162) runs daily at 19:00 UTC (02:00 Cambodia), dumps, encrypts AES-256, uploads artifact, and alerts private Telegram chat on failure. |
| **Operations** | Sentry error reporting | **PRESENT** | Handled in [bootstrap/app.php#L16](../bootstrap/app.php#L16) and [app/Support/SentryBeforeSend.php](../app/Support/SentryBeforeSend.php) for unhandled 5xx exceptions with sanitized context. |

---

## 5. PostgreSQL Triggers and Functions

A grep was executed across all migrations in `database/migrations/`:
```powershell
Select-String -Path database\migrations\*.php -Pattern "CREATE\s+(OR\s+REPLACE\s+)?(TRIGGER|FUNCTION)|DB::unprepared"
```

**Result:** **None found.**

### Database Schema Mechanisms Present:
1. **No PostgreSQL Triggers or Custom Functions:** There are zero database triggers (`CREATE TRIGGER`) and zero custom stored functions (`CREATE FUNCTION`) in any migration.
2. **Generated Column & Full-Text Search Index:** In migration `2026_10_01_200009_create_books_table.php` ([L30-L31](../database/migrations/2026_10_01_200009_create_books_table.php#L30-L31)), Postgres generated column and GIN index are created:
   ```sql
   ALTER TABLE books ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))) STORED;
   CREATE INDEX books_search_vector_gin ON books USING GIN (search_vector);
   ```
3. **Check Constraints:** Added via `DB::statement` on multiple tables (e.g., `staff_users_role_check`, `verification_tokens_purpose_check`, `orders_status_check`, `book_variants_format_check`).

---

## 6. Missing and Partial Items Ordered by Priority

### Priority: Money
1. **Order status change customer notifications**
   - *Status:* MISSING
   - *Recommendation:* Create `OrderStatusChangedNotification` dispatched when staff transitions order to `processing`, `shipped`, `delivered`, or `cancelled`.
   - *Rough Size:* Medium
2. **Staff / Owner notification on new order placement**
   - *Status:* MISSING
   - *Recommendation:* Dispatch an alert (Telegram bot message to owner or email notification) in `CheckoutService::place()` when an order is created.
   - *Rough Size:* Small
3. **Delivered order receipt email**
   - *Status:* MISSING
   - *Recommendation:* Dispatch `OrderReceiptNotification` with delivered summary when order transitions to `delivered`.
   - *Rough Size:* Small
4. **Return and refund customer & staff notifications**
   - *Status:* MISSING
   - *Recommendation:* Send email/notification when a return is requested (to staff) and when approved, rejected, or refunded (to customer).
   - *Rough Size:* Medium
5. **Low-stock alert channel expansion**
   - *Status:* PARTIAL
   - *Recommendation:* Send Telegram alert to owner/staff chat in addition to database notification so staff are alerted immediately without logging in.
   - *Rough Size:* Small

### Priority: Security
6. **Sanctum expired token pruning in production**
   - *Status:* MISSING (Scheduled command never runs on Render)
   - *Recommendation:* Add an opportunistic runtime pruning method (e.g. `SanctumTokens::pruneIfDue()` called during login or at most once daily via cache lock).
   - *Rough Size:* Small
7. **Password changed security notice**
   - *Status:* MISSING
   - *Recommendation:* Send `AccountSecurityNotice` email to customer when password is updated via `ProfileController::changePassword()`.
   - *Rough Size:* Small
8. **Failed-login / brute force administrative lockout**
   - *Status:* PARTIAL
   - *Recommendation:* Add account-level lock counter after N failed password attempts requiring reset or staff activation.
   - *Rough Size:* Medium

### Priority: Data Hygiene
9. **Automated account deletion (30-day erasure) reliability**
   - *Status:* PARTIAL
   - *Recommendation:* In addition to `Staff\CustomerController::index`, invoke `ClosedCustomers::eraseIfDue()` during public login/health check or external cron trigger so accounts erase even if staff never open the admin panel.
   - *Rough Size:* Small
10. **Orphan cart cleanup**
    - *Status:* MISSING
    - *Recommendation:* Create command `carts:prune` and an opportunistic service to delete carts untouched for > 60 days.
    - *Rough Size:* Small
11. **Failed jobs & job batches table pruning**
    - *Status:* MISSING
    - *Recommendation:* Add `queue:prune-failed --hours=168` and `queue:prune-batches --hours=168` to schedule (and a cleanup command).
    - *Rough Size:* Small
12. **Audit log retention policy**
    - *Status:* MISSING
    - *Recommendation:* Define retention policy (e.g. 1 year) and command `audit-logs:prune`.
    - *Rough Size:* Small
13. **Verification tokens table cleanup**
    - *Status:* PARTIAL
    - *Recommendation:* Add command/routine to prune `verification_tokens` where `expires_at < now() - 30 days`.
    - *Rough Size:* Small

### Priority: Convenience / Customer Experience
14. **Welcome email after email verification**
    - *Status:* MISSING
    - *Recommendation:* Send a friendly onboarding email when customer verifies email code or Telegram phone.
    - *Rough Size:* Small
15. **"Tell me when it is back" (Back-in-stock alerts)**
    - *Status:* MISSING
    - *Recommendation:* Add `stock_subscriptions` table and notify subscribed customers when `InventoryService::setStock()` restocks a variant.
    - *Rough Size:* Large
16. **Review moderation & low-rating alert**
    - *Status:* MISSING
    - *Recommendation:* Send Telegram alert to staff when a review with rating <= 2 is submitted.
    - *Rough Size:* Small
17. **Exchange rate automated sync**
    - *Status:* PARTIAL
    - *Recommendation:* Optional scheduled job querying National Bank of Cambodia (NBC) or open exchange rate API for USD/KHR rates.
    - *Rough Size:* Medium

---

## 7. Open Questions for the Owner

1. **Production Scheduler Strategy on Render Free Tier:**
   Since Render free does not run background workers or schedulers, should we:
   - Add a lightweight secret-protected cron endpoint (e.g. `POST /api/v1/cron/run?key=...`) triggered by an external cron service (like cron-job.org or GitHub Actions schedule)?
   - Rely solely on opportunistic runtime hooks (like `pruneIfDue()`) during customer requests?
   - Or keep the scheduler defined for when the app migrates to Railway?
2. **Customer Order Status Notifications:**
   Should customers receive emails on every status change (`processing`, `shipped`, `delivered`, `cancelled`), or only on major milestones (e.g. `shipped` and `delivered`)?
3. **Owner New-Order Notifications:**
   Should new orders send a direct Telegram notification to the store owner's personal chat ID (similar to the backup failure alert), or an email?
4. **Data Retention Window for Audit Logs & Carts:**
   What should the retention window be for `admin_audit_logs`, `inventory_movements`, and abandoned customer carts (e.g., 90 days, 180 days, or keep forever)?

---

## 8. How I Verified

### Commands Executed (Read-Only)
- `git status --porcelain` (verified clean working tree)
- `git branch --show-current` (verified `main` prior to checkout)
- `git checkout -b docs/automatic-actions-audit` (switched to dedicated documentation branch)
- `Select-String -Path database\migrations\*.php -Pattern "CREATE\s+(OR\s+REPLACE\s+)?(TRIGGER|FUNCTION)|DB::unprepared"` (verified 0 PostgreSQL triggers)
- `Select-String -Path database\migrations\*.php -Pattern "DB::statement|statement\("` (inspected all raw statements in migrations)
- `php artisan schedule:list` (listed all registered artisan schedules in `routes/console.php`)
- `php artisan event:list` (verified event listeners)
- `Get-ChildItem -Path app -Recurse -Filter "*.php"` searches for `notify(`, `Notification::send`, `Mail::`, `dispatch(`, `Event::`, `eraseIfDue`, `pruneIfDue`, `booted`, `Observer`.

### Files Read
1. [CLAUDE.md](../CLAUDE.md)
2. [docs/PROJECT_CONTEXT.md](../docs/PROJECT_CONTEXT.md)
3. [docs/WORKLOG.md](../docs/WORKLOG.md)
4. [docs/NEXT_STEP.md](../docs/NEXT_STEP.md)
5. [docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)
6. [Dockerfile](../Dockerfile)
7. [docker/start.sh](../docker/start.sh)
8. [composer.json](../composer.json)
9. [routes/console.php](../routes/console.php)
10. [bootstrap/app.php](../bootstrap/app.php)
11. [routes/api.php](../routes/api.php)
12. [config/queue.php](../config/queue.php)
13. [.railway/railway.ts](../.railway/railway.ts)
14. [.github/workflows/db-backup.yml](../.github/workflows/db-backup.yml)
15. [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php)
16. [app/Services/Orders/CheckoutService.php](../app/Services/Orders/CheckoutService.php)
17. [app/Services/Orders/OrderStatusService.php](../app/Services/Orders/OrderStatusService.php)
18. [app/Services/Returns/ReturnService.php](../app/Services/Returns/ReturnService.php)
19. [app/Services/Inventory/InventoryService.php](../app/Services/Inventory/InventoryService.php)
20. [app/Services/Auth/OtpService.php](../app/Services/Auth/OtpService.php)
21. [app/Services/Auth/TotpService.php](../app/Services/Auth/TotpService.php)
22. [app/Services/Staff/StaffManagementService.php](../app/Services/Staff/StaffManagementService.php)
23. [app/Services/Customers/ClosedCustomers.php](../app/Services/Customers/ClosedCustomers.php)
24. [app/Services/Customers/UnverifiedCustomers.php](../app/Services/Customers/UnverifiedCustomers.php)
25. [app/Services/Cart/CartService.php](../app/Services/Cart/CartService.php)
26. [app/Services/Cart/CouponService.php](../app/Services/Cart/CouponService.php)
27. [app/Services/CurrencyService.php](../app/Services/CurrencyService.php)
28. [app/Services/Telegram/TelegramBot.php](../app/Services/Telegram/TelegramBot.php)
29. [app/Services/Telegram/TelegramLinks.php](../app/Services/Telegram/TelegramLinks.php)
30. [app/Rules/Turnstile.php](../app/Rules/Turnstile.php)
31. [app/Notifications/AccountReopenedNotification.php](../app/Notifications/AccountReopenedNotification.php)
32. [app/Notifications/AccountSecurityNotice.php](../app/Notifications/AccountSecurityNotice.php)
33. [app/Notifications/LowStockNotification.php](../app/Notifications/LowStockNotification.php)
34. [app/Notifications/OrderPlacedNotification.php](../app/Notifications/OrderPlacedNotification.php)
35. [app/Notifications/OtpCodeNotification.php](../app/Notifications/OtpCodeNotification.php)
36. [app/Notifications/StaffInvitationNotification.php](../app/Notifications/StaffInvitationNotification.php)
37. [app/Http/Controllers/Api/V1/HealthController.php](../app/Http/Controllers/Api/V1/HealthController.php)
38. [app/Http/Controllers/Api/V1/Customer/AuthController.php](../app/Http/Controllers/Api/V1/Customer/AuthController.php)
39. [app/Http/Controllers/Api/V1/Customer/EmailController.php](../app/Http/Controllers/Api/V1/Customer/EmailController.php)
40. [app/Http/Controllers/Api/V1/Customer/ConnectionController.php](../app/Http/Controllers/Api/V1/Customer/ConnectionController.php)
41. [app/Http/Controllers/Api/V1/Customer/ProfileController.php](../app/Http/Controllers/Api/V1/Customer/ProfileController.php)
42. [app/Http/Controllers/Api/V1/Customer/ReviewController.php](../app/Http/Controllers/Api/V1/Customer/ReviewController.php)
43. [app/Http/Controllers/Api/V1/Staff/AuthController.php](../app/Http/Controllers/Api/V1/Staff/AuthController.php)
44. [app/Http/Controllers/Api/V1/Staff/CustomerController.php](../app/Http/Controllers/Api/V1/Staff/CustomerController.php)
45. [app/Http/Controllers/Api/V1/Staff/Reviews/ReviewController.php](../app/Http/Controllers/Api/V1/Staff/Reviews/ReviewController.php)
46. [app/Console/Commands/EraseClosedCustomers.php](../app/Console/Commands/EraseClosedCustomers.php)
47. [app/Console/Commands/PruneUnverifiedCustomers.php](../app/Console/Commands/PruneUnverifiedCustomers.php)
48. [app/Console/Commands/RegisterTelegramWebhook.php](../app/Console/Commands/RegisterTelegramWebhook.php)
