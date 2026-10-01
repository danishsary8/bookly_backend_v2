# Next Step

Steps 1-6 are done: schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders with cash on delivery (124 tests pass). Branch for Step 6: `feature/checkout-orders` (owner merges).

## Step 7 — Returns and reviews (decide these first, then implement on `feature/returns-reviews`)
Returns (tables `returns`, `return_items`; statuses requested -> approved | rejected, approved -> refunded):
1. Return window: how many days after delivery can a customer ask for a return (e.g. 14)?
2. Digital formats (ebook/audiobook): never returnable — or returnable too?
3. Partial returns (some items / some copies) and more than one return request per order — allowed?
4. Who handles returns: staff + admin (like orders) — or admin only?
5. Stock: returned physical copies go back to stock when the return is marked `refunded` (reason `return`) — or when approved?
6. Refund for cash on delivery is done outside the system (cash / bank transfer); staff mark the return `refunded` with a note. Order status becomes `returned` only when every item is returned — OK? Payment status `refunded` only on a full return?
7. Can the customer withdraw a return request while it is still `requested`?

Reviews (table `reviews`; one per customer per book, linked to an `order_item` to prove the purchase):
8. Verified purchase = the book was in one of the customer's `delivered` orders (any format) — OK?
9. Moderation: published right away and staff can hide (`is_approved = false`) — or hidden until staff approve?
10. Customers can edit and delete their own review? Rating distribution (count of 1-5 stars) on the book detail?

## After this, in order
admin (staff management, dashboard with sales stats, audit log viewer, exchange rates) -> logging/Sentry -> card/PayPal/KHQR payments once keys are provided.

## Payments (blocked on owner)
No Stripe/PayPal/Bakong work until the owner provides keys: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `BAKONG_API_TOKEN`, `BAKONG_ACCOUNT_ID`.

## Reusable pieces
- `App\Services\Orders\OrderStatusService` — the only place order status changes (history, audit, stock/coupon/payment side effects). Add the `returned` transition here.
- `App\Services\Inventory\InventoryService` — add a "return to stock" method using reason `return`.
- `App\Services\Admin\AuditLogger`, `App\Support\Money`, `App\Services\CurrencyService`.
- Book listing already returns `rating_avg` / `review_count` from approved reviews (`Book::approvedReviews()`).
- Tests: `Tests\Concerns\ActsAsStaff`; see `tests/Feature/Orders/*` for how to place an order in a test.

## Paste this into Claude chat / VS Code CLI / Codex / Gemini to restart cleanly
```
Project: Bookly Backend V2 (Laravel 13 + PostgreSQL, REST /api/v1). Repo: github.com/danishsary8/bookly_backend_v2 (work from main after merging feature/checkout-orders).
Read in order: AGENTS.md, docs/PROJECT_CONTEXT.md, docs/WORKLOG.md, docs/NEXT_STEP.md, docs/API.md.
Status: Steps 1-6 done (schema, models, auth, catalog, cart/wishlist/coupons, checkout + orders with cash on delivery; 124 tests pass).
Next: Step 7 returns + reviews. First walk me through the 10 decisions in docs/NEXT_STEP.md one by one with a recommendation each, and wait for my answers before coding. Then implement on branch feature/returns-reviews in small commits, reusing OrderStatusService and InventoryService. No Stripe/PayPal/Bakong code. Don't modify existing migrations, auth, catalog, cart or checkout code without asking. Human-style branch/commit names, no AI/tool names. Update docs/WORKLOG.md after each sub-step, docs/API.md for every new endpoint, and rewrite docs/NEXT_STEP.md for the following step (admin) at the end.

Output: end with a short "what was done / what's next" summary, plus a paste-ready prompt for the next session.
```
