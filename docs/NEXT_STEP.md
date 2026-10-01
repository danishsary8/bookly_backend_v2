# Next Step

Steps 1-5 are done: schema, models, auth, catalog, addresses/cart/wishlist/coupons (102 tests pass). Branch for Step 5: `feature/cart-wishlist-coupons` (owner merges).

## Step 6 — Checkout + orders + inventory deduction (cash on delivery only), on `feature/checkout-orders`
Decide these first:
1. Shipping fee: flat fee (e.g. $2), free above an amount, or by city (Phnom Penh vs provinces)? Digital-only orders (ebooks/audiobooks): no shipping fee and no address needed?
2. Tax: none for now (prices include tax), or add a tax rate?
3. Price changed since adding to cart: checkout simply uses today's price (customer saw the warning), or require an `accept_price_changes: true` flag?
4. Double-submit protection: require an `Idempotency-Key` header on checkout so a double click can't create two orders?
5. Stock: deducted when the order is placed and put back if the order is cancelled — OK?
6. Order number format: `ORD-20261003-7K2QX` (date + 5 random characters) — OK?
7. Customer cancellation: allowed while `pending` or `processing` (before shipping)? Staff can cancel until shipped?
8. Cash on delivery: payment row `pending` at checkout, marked `succeeded` when staff set the order to `delivered`. Order flow: pending -> processing -> shipped -> delivered (cancelled possible before shipped). OK?
9. Low-stock alerts (when stock drops to `low_stock_threshold` or below): notify all staff or admins only? Email + in-app (database) notifications?
10. After checkout: empty the cart and send an order confirmation email (queued)?

## After this, in order
returns/reviews -> admin (staff management, dashboard, audit log viewer, exchange rates) -> logging/Sentry -> card/PayPal/KHQR payments once keys are provided.

## Payments (blocked on owner)
No Stripe/PayPal/Bakong work until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Reusable pieces checkout must use
- `App\Services\Cart\CartService::summary()` — current prices and per-line `issues`; refuse checkout when `can_checkout` is false.
- `App\Services\Cart\CouponService::evaluate()` — re-check the coupon inside the checkout transaction, then increment `coupons.used_count`.
- `App\Services\Inventory\InventoryService` — the only place stock changes; add a "deduct for sale" method (reason `sale`, reference the order) and a "return to stock" method (reason `return`/`adjustment`). Lock variant rows (`lockForUpdate`) inside one DB transaction.
- `App\Enums\OrderStatus::allowedNext()` — already defines status transitions.
- Orders store an address snapshot (`shipping_*` columns) — copy from `customer_addresses` at checkout.
- `App\Services\Admin\AuditLogger` for staff status changes; `order_status_history` for every status change (`changed_by_staff_id` null = customer/system).
- `App\Support\Money` (integer cents), `App\Services\CurrencyService` (KHR).
- Tests: `Tests\Concerns\ActsAsStaff`.

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Book-Shop Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Folder: book-ecommerce-server-v2.
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-5 done (schema, models, auth, catalog, addresses, cart with issue warnings, wishlist, coupons; 102 tests pass).
Next: Step 6 checkout + orders + stock deduction, cash on delivery only. First walk me through the 10 decisions in docs/NEXT_STEP.md one by one with a recommendation each, and wait for my answers before coding. Then implement on branch feature/checkout-orders in small commits, reusing CartService, CouponService and InventoryService. No Stripe/PayPal/Bakong code. Don't modify existing migrations, auth, catalog or cart code without asking. Human-style branch/commit names, no AI/tool names. Update docs/WORKLOG.md after each sub-step, docs/API.md for every new endpoint, and rewrite docs/NEXT_STEP.md for the following step at the end.
```
