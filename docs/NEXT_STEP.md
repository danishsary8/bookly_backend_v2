# Next Step

Steps 1-4 are done: schema, models, auth, catalog API (74 tests pass). Branch for the catalog: `feature/catalog-api` (owner merges).

## Step 5 — Addresses, cart, wishlist, coupons (decide these first, then implement on `feature/cart-wishlist-coupons`)
All customer routes here need a verified customer: `['auth:sanctum', 'abilities:customer', 'verified.customer']`. Staff coupon routes use `['auth:sanctum', 'abilities:staff', 'staff.2fa']`.
1. Adding a format that is already in the cart: increase the quantity (usual) — or replace it?
2. Stock check: reject adding more than the available stock right away — or only check at checkout? Max quantity per line (e.g. 10)? Ebooks/audiobooks limited to quantity 1?
3. Cart view: always show current prices plus a warning per line when an item became inactive, out of stock or its price changed — OK?
4. Coupons: one coupon per order; customers preview it with `POST /cart/coupon/check` (no coupon stored on the cart because the schema has no column for it) and send it at checkout — OK? One use per customer, or unlimited per customer within `max_uses`?
5. Who manages coupons: staff + admin create/edit, admin deletes (same as the catalog) — or admin only?
6. Addresses: limit per customer (e.g. 10)? The first address becomes the default automatically; setting a new default clears the old one — OK?
7. Wishlist extras: a "move to cart" endpoint (needs a chosen format) — now or later?

## After this, in order
checkout + orders + inventory deduction + low-stock alerts (cash on delivery first) -> returns/reviews -> admin (staff management, dashboard, audit log viewer, exchange rates) -> logging/Sentry.

## Payments (blocked on owner)
No Stripe/PayPal/Bakong work until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Reusable pieces already in the code
- `App\Services\Inventory\InventoryService` — the only place stock changes (writes `inventory_movements`). Checkout must use it.
- `App\Services\Admin\AuditLogger` — call it for every staff create/update/delete.
- `App\Services\CurrencyService` — `price_khr`.
- `App\Support\Money` — money math in integer cents. `Coupon::unusableReason()` and `Coupon::discountCentsFor()` already exist.
- `BookVariant::isAvailable()` — active and (digital or stock > 0).
- Tests: `Tests\Concerns\ActsAsStaff` (`staffToken()`, `asToken()`).

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Book-Shop Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Folder: book-ecommerce-server-v2.
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-4 done (schema, models, auth, catalog API with search/filters, staff catalog CRUD, audit log; 74 tests pass).
Next: Step 5 addresses + cart + wishlist + coupons. First walk me through the 7 decisions in docs/NEXT_STEP.md one by one with a recommendation each, and wait for my answers before coding. Then implement on branch feature/cart-wishlist-coupons in small commits. No orders/checkout/payment code this step. Don't modify existing migrations, auth or catalog code without asking. Human-style branch/commit names, no AI/tool names. Update docs/WORKLOG.md after each sub-step, docs/API.md for every new endpoint, and rewrite docs/NEXT_STEP.md for checkout at the end.
```
