# Worklog

Append newest entries at the bottom. Every finished step gets an entry.

## Step 0 — Planning (done, owner confirmed)
Requirements, entity list (27 tables), ER diagram, column-level schema. See PROJECT_CONTEXT.md for the schema doc link.

## Step 1 — Laravel skeleton + migrations (done)
- Created Laravel 13.34 project in `book-ecommerce-server-v2/`. Removed default `users` migration, `User` model, `UserFactory` (replaced by customers/staff_users; DatabaseSeeder still references `User` — fix when seeders are written).
- Installed Sanctum via `php artisan install:api` (adds `personal_access_tokens`). API routes are prefixed `api/v1` (`bootstrap/app.php`, `apiPrefix`). `routes/api.php` has only `GET /api/v1/ping`.
- `.env` / `.env.example` -> pgsql (`bookshop_v2`, user/pass `bookshop`), `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database`, `APP_NAME=BookShop`. `phpunit.xml` uses Postgres DB `bookshop_v2_test` (schema needs jsonb/tsvector/CHECK, so sqlite will not work).
- 27 domain migrations (`2026_10_01_2000NN_*`, one file per table, FK order) + `notifications` migration. CHECK constraints are added with `DB::statement`. `books.search_vector` is a generated stored tsvector with a GIN index.
- Verified on real Postgres 16: `migrate:fresh`, `migrate:rollback`, `migrate` all clean (35 tables); FTS works; CHECK/unique constraints reject bad data.
- Tests: `tests/Feature/DatabaseSchemaTest.php` (5) + `ApiPingTest` (1) + Laravel examples (2) — 8 pass.

### Decisions made while implementing (owner: veto any of these)
1. Added CHECK on `orders.status` / `order_status_history.status` (7 listed statuses) and `orders.payment_method` (card|cod|khqr) — the spec listed values but no explicit constraint.
2. `customer_addresses` FK from `orders` is RESTRICT, so an address used by an order cannot be hard-deleted. See open question 1.
3. FKs to staff (`created_by_staff_id`, `changed_by_staff_id`) are SET NULL on delete; `admin_audit_logs.staff_user_id` is RESTRICT.
4. Extra indexes on FK columns beyond the spec (Postgres does not auto-index FKs): e.g. `order_items.book_variant_id`, `reviews.book_id`, `returns.order_id`.
5. Local dev DB for this session ran on a throwaway Postgres cluster; on your machine create DBs `bookshop_v2` and `bookshop_v2_test` and a `bookshop` user.

### Open questions for the owner
1. ~~Order shipping address~~ — RESOLVED in Step 2: owner chose to snapshot the address onto the order.
2. `verification_tokens.user_id` is polymorphic without a DB FK (by design); integrity is enforced in app code.

## Step 2 — Eloquent models, enums, factories (done)
Branch: `feature/domain-models`, one commit per domain (accounts, catalog, inventory, cart/wishlist, promotions, orders, payments, returns, reviews, money/admin, tests).
- 27 models in `app/Models`. `returns` table model is `OrderReturn` (`Return` is a PHP reserved word). Log-style tables use `const UPDATED_AT = null`.
- Customer/StaffUser extend Authenticatable, use `password_hash` (`getAuthPasswordName()`, `hashed` cast). Staff `two_factor_secret` uses the `encrypted` cast.
- PHP backed enums in `app/Enums` (OrderStatus has the allowed status transitions).
- `orders` migration changed: `shipping_address_id` is now nullable/SET NULL and the order stores a snapshot (`shipping_recipient_name`, `shipping_phone`, `shipping_address_line1/2`, `shipping_city/state/postal_code/country`).
- `app/Support/Money.php`: money math in integer cents (the PHP `bcmath` extension is not assumed to be installed). Coupon discount logic lives on the Coupon model.
- Factories for all main models. Tests: 14 passing (`ModelRelationshipsTest`, `MoneyTest`, schema, ping).

### Paused on owner request
The owner asked to stop before (1) Sanctum auth / 2FA / OTP and (2) payment providers. A first auth draft was written before that message arrived; it is NOT committed. It sits in `git stash` ("auth draft ...") in the build environment only — treat it as reference, not as accepted code. See NEXT_STEP.md for the decisions needed.

## Step 3 — Authentication (branch `feature/auth`)
Owner decisions (all 7 confirmed): 7-day Sanctum tokens, no refresh tokens · unverified customers get a token but cannot shop until they enter the email OTP · OTP = 6 digits, 15 min, hashed, new code cancels old, 5 attempts/min per email+IP · staff 2FA = authenticator app (TOTP), mandatory · first admin via `php artisan staff:create-admin` · social login verified server-side, auto-link by email · passwords min 8 with letters + numbers.

- [x] 3.1 Config: auth providers `customers` + `staff` (removed leftover `User` model reference), Sanctum guard `[]` (token-only API, no session auth), token expiry `SANCTUM_EXPIRATION` (default 7 days), Socialite package, `google`/`facebook` in `config/services.php`, middleware aliases (`abilities`, `ability`, `verified.customer`, `staff.2fa`), rate limiters `auth` (5/min) and `otp-send` (3 per 10 min).
- [x] 3.2 Customer auth: `POST /api/v1/auth/register|login|logout|verify-email|resend-verification|forgot-password|reset-password`. `OtpService` (hashed 6-digit codes, single use, new code cancels old) + queued `OtpCodeNotification` email. Customer tokens carry ability `customer`. Reset password revokes all tokens. Forgot-password never reveals whether an email exists. Tests: `tests/Feature/Auth/CustomerAuthTest.php`, `VerifiedCustomerAccessTest.php` (27 tests total passing).
- [x] 3.3 Customer profile: `GET|PATCH /api/v1/me`, `PUT /api/v1/me/password` (needs current password unless the account is social-only; signs out other sessions). Staff tokens get 403 on customer routes. Tests: `CustomerProfileTest` (31 total).
- [x] 3.4 Social login: `POST /api/v1/auth/social/{google|facebook}` with `{access_token}` from the frontend SDK. The backend asks the provider for the profile (Socialite `userFromToken`), links to an existing account with the same email or creates a verified one. Needs `GOOGLE_CLIENT_ID/SECRET`, `FACEBOOK_CLIENT_ID/SECRET` in `.env` (owner to create the apps). Tests mock the provider: `SocialLoginTest` (36 total).
- [x] 3.5 Staff auth + 2FA: `POST /api/v1/staff/auth/login` -> if 2FA on, returns `challenge_token` (5 min, single use) -> `POST /staff/auth/two-factor/challenge {challenge_token, code}` returns the token. Without 2FA, login returns a token but every staff feature route answers 403 `two_factor_setup_required` until `POST /two-factor/setup` (returns secret + `otpauth_uri` for the QR) and `POST /two-factor/confirm {code}`. TOTP is our own RFC 6238 implementation (`TotpService`, checked against the RFC test vectors), ±30 s drift, a used code cannot be replayed for 2 minutes. Staff tokens carry `staff` (+ `admin` for admins). Staff forgot/reset password via email OTP; reset does not disable 2FA. Staff feature routes must use `['auth:sanctum', 'abilities:staff', 'staff.2fa']` (+ `abilities:staff,admin` for admin-only). Tests: `StaffAuthTest`, `TotpServiceTest` (45 total).
- [x] 3.6 First admin: `php artisan staff:create-admin --name="..." --email=...` (password asked at a hidden prompt, never as an option, so it stays out of shell history). Tests: `CreateAdminCommandTest` (47 total).
- [x] 3.7 Hardening after self-review: `auth` limiter also caps 30 attempts/min per IP (rotating emails no longer bypasses it); daily `sanctum:prune-expired` in `routes/console.php` (needs the Laravel scheduler cron in production). 48 tests passing.

## Step 4 — Catalog API (branch `feature/catalog-api`)
Owner decisions: image URLs only (no upload yet) · 20 per page (max 100) with full filters/sorts · `price_khr` next to `price_usd` from the latest USD->KHR rate (null if none) · staff + admin edit, only admin deletes · `book_variants.is_active` (new migration), ordered variants cannot be hard-deleted · publishers + series included · books without an active variant are hidden from the public catalog · stock may be set on variant create/edit and every change is logged in `inventory_movements` · catalog changes are written to `admin_audit_logs`.

- [x] 4.1 Migration `2026_10_02_100001_add_is_active_to_book_variants_table` (new file; existing migrations untouched). `BookVariant::isAvailable()` = active and (digital format or stock > 0).

## How to run locally
```
composer install
cp .env.example .env && php artisan key:generate
# Postgres: create user bookshop/bookshop, databases bookshop_v2 and bookshop_v2_test
php artisan migrate
php artisan test
php artisan serve   # GET http://localhost:8000/api/v1/ping
```
