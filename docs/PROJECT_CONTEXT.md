# Project Context — Book-Shop Backend V2

Owner: a software-dev student becoming an API developer. Explain step by step, with real examples, practical and structured. Avoid heavy theory.

## What this is
Full rebuild of the legacy PHP backend (V1, folder `book-ecommerce-server/`, plain PHP + Postgres + custom JWT) as V2: Laravel + PostgreSQL, clean 3NF schema, general e-commerce + bookshop features. Backend first; frontend V2 comes later. V1 is never modified.

## Stack (confirmed)
Laravel 13 (PHP 8.3) · PostgreSQL 16 · REST `/api/v1/` · Laravel Sanctum tokens · 2FA for staff · database queue driver (`QUEUE_CONNECTION=database`) · Postgres full-text search (generated `tsvector` on `books`) · Sentry + structured logging (not wired yet) · PHPUnit. Deployment target undecided.

## Requirements (confirmed)
- Roles: customer, admin, staff (inventory). Staff table separate from customers.
- Customer auth: email+password, Google/Facebook login, required email verification. Verification and password reset both use 6-digit OTP (hashed in `verification_tokens`, purpose `email_verify` | `password_reset`, `user_type` customer | staff). Staff: email+password + 2FA. No guest checkout.
- Catalog: books (publisher, language, pages, publish date, series + order) have format variants in `book_variants` (hardcover/paperback/ebook/audiobook; each own ISBN, SKU, price, stock, low-stock threshold, cover). Flat many-to-many categories, many-to-many authors with profile pages, series.
- Money: prices in USD only; KHR derived from `exchange_rates` at display/checkout.
- Cart -> checkout -> order. One cart per customer. Multiple saved addresses. Coupons (percentage|fixed). Wishlist is book-level.
- Payments: card (Stripe/PayPal), cash on delivery, KHQR via Bakong. Webhooks logged in `payment_webhook_logs` with idempotency `event_id`.
- Orders: full status history (pending, paid, processing, shipped, delivered, cancelled, returned); `changed_by_staff_id` null = system.
- Returns/refunds workflow (requested, approved, rejected, refunded).
- Inventory: stock on variant + `inventory_movements` audit log. Low-stock alerts use Laravel's `notifications` table (queued).
- Reviews: 1-5 stars + text, book-level, verified purchase only (via `order_item_id`), one per customer per book.
- Admin: sales dashboard, staff management (role enum admin|staff), `admin_audit_logs` (before/after jsonb).

## Auth (implemented in Step 3)
Customer tokens: Sanctum, 7 days, ability `customer`; routes `['auth:sanctum','abilities:customer']`, shopping routes add `verified.customer`. Staff tokens: ability `staff` (+ `admin`); staff feature routes `['auth:sanctum','abilities:staff','staff.2fa']`, admin-only add `abilities:staff,admin`. Details in docs/API.md.

## Catalog (implemented in Step 4)
Public catalog shows only books with an active variant. Staff edit, admin deletes. Stock only changes through `InventoryService` (always logged). Staff changes are written to `admin_audit_logs` via `AuditLogger`. `book_variants.is_active` hides a format without deleting it.

## Conventions (apply everywhere)
BIGINT auto-increment PKs · DECIMAL(10,2) money · soft deletes ONLY on customers, orders, books · timestamps on all tables (log-style tables have only `created_at`) · 3NF, with the one intentional snapshot `order_items.unit_price` · enum-like columns are VARCHAR + CHECK constraints (not Postgres enums) · password column is `password_hash` (models must override `getAuthPasswordName()`).

## 27 domain tables
Accounts: customers, staff_users, customer_addresses, verification_tokens · Catalog: authors, publishers, categories, series, books, book_authors, book_categories, book_variants · Inventory: inventory_movements · Cart: carts, cart_items, wishlists · Promotions: coupons · Orders: orders, order_items, order_status_history · Payments: payments, payment_webhook_logs · Returns: returns, return_items · Reviews: reviews · Admin/Money: exchange_rates, admin_audit_logs.
Plus framework tables: cache, cache_locks, jobs, job_batches, failed_jobs, personal_access_tokens, notifications.
Full column-level spec + ER diagram doc (source of truth for design intent): https://claude.ai/code/artifact/9190852a-651e-406f-bcd6-da77a6eed490

## Git rules
Owner will move this folder into their own new repo and handles branches/merges themselves. One feature = one branch (`feature/...`), human-style commit messages, no AI/tool names anywhere in branch names or commits, never merge for the owner.

## How to work with the owner
Small steps; summarize; wait for "confirm". Present options with trade-offs for any design decision; recommend one. Flag gaps or better practices as a second opinion, owner decides. Never dump large unrequested code.
