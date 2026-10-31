# Prompt 3 (B+D): offers that make customers buy more, plus offer reports

```text
TASK B+D: Offers + offer reports. PRODUCTION_CHECKLIST.md section 4.1. This is the biggest design task: go slowly.
Playbook for ideas (offers, pricing, marketing psychology, CRO, popups, analytics):
https://github.com/coreyhaines31/marketingskills (read it for ideas only; do not install anything from it).

Already exists (do NOT rebuild): coupons table (code, type, value, min_order_amount, max_uses, used_count,
starts_at, expires_at, is_active) with admin page CouponsAdminPage.tsx and POST /cart/coupon/check; orders store
coupon_id + discount_amount; free delivery over FREE_SHIPPING_THRESHOLD (config/shop.php, default $50) with
FreeShippingBar in cart and checkout ("spend $X more for free delivery" may already be done: check it).

Step 1 (read only): read the coupon, cart, checkout (order creation + totals) code and tests, and the admin reports
(ReportsService, ReportsPage). Report in simple English: how discounts are calculated today, where an automatic
offer would plug in, and what the free-delivery nudge already does. STOP.

Step 2 (design, ask me): propose the first version with options + your recommendation for each:
  1. Offer types for v1. Suggested: "Buy N, get X% off" (e.g. buy 2 get 10%), "Bundle / complete the series"
     price, limited-time deal on chosen books/categories. Which ones first?
  2. Automatic offers vs coupon codes: does an offer apply by itself? Can it stack with a coupon? (recommend: no
     stacking in v1, the customer gets the better one, explained in the cart).
  3. Data: is a new table needed (e.g. offers + offer_items, and order_items/order offer link for reports)? Show
     the exact migration you want and WAIT for my OK. Never change existing migrations.
  4. Where the customer sees it: book page badge, cart line "Add 1 more to save 10%", checkout summary.
  5. Admin: create / edit / pause offers, dates, limits.
  6. Reports (D): per offer: orders, units, revenue, discount given, average order value vs orders without offer,
     date range filter, CSV export? Which numbers matter most to you?
Write the agreed design to bookly_backend_v2/docs/superpowers/specs/<date>-offers-design.md and ask me to review it.

Step 3+ (build, after my OK), split into small branches, each working alone:
  - backend feature/offers-engine (tables, rules, cart/checkout totals, staff CRUD, tests: money math in cents,
    no double discount, expired/paused offers ignored, stock and order totals stay correct);
  - frontend feature/offers-storefront (badges, cart nudge, checkout line; works and shows nothing if the API
    has no offers endpoint yet);
  - frontend feature/offers-admin (admin pages);
  - backend + frontend feature/offer-reports.
Every step ends with "confirm". Backend: php artisan test + pint, docs/API.md + public/openapi.yaml.
Frontend: lint, typecheck, test, build, browser check. Notes: WORKLOG, HANDOFF.md, OWNER_TODO.md (merge order,
any setting) on docs/handoff-notes.
```
