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
1. Addresses: an order only stores `shipping_address_id`. If a customer edits/deletes that address, order history changes or the delete fails. Recommended: snapshot the address onto the order (or make addresses immutable + soft-delete). Needs a decision before the checkout feature.
2. `verification_tokens.user_id` is polymorphic without a DB FK (by design); integrity is enforced in app code.

## How to run locally
```
composer install
cp .env.example .env && php artisan key:generate
# Postgres: create user bookshop/bookshop, databases bookshop_v2 and bookshop_v2_test
php artisan migrate
php artisan test
php artisan serve   # GET http://localhost:8000/api/v1/ping
```
