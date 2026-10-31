# Offers v1 + offer reports: design

Date: 2026-10-31 · Task B+D · PRODUCTION_CHECKLIST.md 4.1 · Status: **approved by the owner with changes (review `8437ee5` on `docs/handoff-notes`), O1 = A**

## 1. Goal

Automatic offers that make customers buy more books, without codes: "Buy 2, get 10% off", "Buy 2 from the Sherlock
Holmes series, get 15% off", with optional start/end dates (limited-time deals). Plus a report that shows what each
offer sold. Coupons stay as they are; the customer always gets the better of the two, never both.

Out of scope for v1: fixed bundle prices ("all 4 for $30"), offers that add up on one order, buy-X-get-Y-free,
per-customer offer limits, emails or popups about offers.

## 2. Owner decisions (2026-10-31)

| # | Decision |
|---|---|
| Q1 A | An offer covers all books, or a chosen list of books, categories and series (any mix). |
| Q2 A | One offer per order: the one that saves the most; on a tie the lower offer id wins. |
| Q3 A | N (minimum books) 2 to 20; X (percent off) more than 0, at most 50. |
| Q4 A | Store credit is a coupon like any other: no stacking, the better one wins, an unused credit stays valid. |
| Q5 A | `GET /cart` shows the offer only; the coupon comparison stays in `/cart/coupon/check` (keeps the guess lock). |
| Q6 A | The API sends numbers and the target name; the frontend writes the sentence (EN + KM). `name` is internal. |
| Q7 A | Targets in one `offer_targets` table (each row: a book OR a category OR a series). |
| Q8 A | Book list and book detail responses get an `offer` field (active offers cached 1 minute). |
| Q9 A | The report shows "books per order" with offer vs without. |
| + | `order_items.discount_amount`: the discount on each line, for offers and coupons; returns use it. |
| + | Lock the offer row at checkout, give the use back on cancel, test two checkouts racing for the last use. |
| + | A "selected" offer whose targets were all deleted shows as **No books** in the admin list. |
| + | Only admins create, edit, pause and delete offers; staff can view (same as coupons). |
| + | The offer discount counts like a coupon for free delivery (subtotal minus discount). |

## 3. Data (new migrations only; no existing migration is changed)

### 3.1 `offers` (new) · `2026_10_31_100001_create_offers_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint auto-increment | no | | primary key |
| name | varchar(100) | no | | internal, admin pages + reports |
| type | varchar(30) | no | `'buy_n_percent'` | CHECK `type IN ('buy_n_percent')` |
| applies_to | varchar(10) | no | | CHECK `applies_to IN ('all','selected')` |
| min_quantity | smallint | no | | CHECK `BETWEEN 2 AND 20` |
| percent_off | decimal(5,2) | no | | CHECK `> 0 AND <= 50` |
| starts_at | timestamp | yes | null | null = from now |
| ends_at | timestamp | yes | null | null = no end; CHECK `ends_at > starts_at` when both are set |
| max_orders | integer | yes | null | null = unlimited |
| used_count | integer | no | 0 | |
| is_active | boolean | no | true | false = paused |
| created_at, updated_at | timestamp | yes | | |

### 3.2 `offer_targets` (new) · `2026_10_31_100002_create_offer_targets_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint auto-increment | no | | primary key |
| offer_id | bigint | no | | FK `offers.id` ON DELETE CASCADE |
| book_id | bigint | yes | null | FK `books.id` ON DELETE CASCADE |
| category_id | bigint | yes | null | FK `categories.id` ON DELETE CASCADE |
| series_id | bigint | yes | null | FK `series.id` ON DELETE CASCADE |
| created_at, updated_at | timestamp | yes | | |

Unique `(offer_id, book_id)`, `(offer_id, category_id)`, `(offer_id, series_id)`.
CHECK `num_nonnulls(book_id, category_id, series_id) = 1`.

### 3.3 `orders` (4 new columns, after `coupon_id`) · `2026_10_31_100003_add_offer_to_orders_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| offer_id | bigint | yes | null | FK `offers.id` ON DELETE SET NULL; index |
| offer_name | varchar(100) | yes | null | copy at order time |
| offer_min_quantity | smallint | yes | null | copy |
| offer_percent_off | decimal(5,2) | yes | null | copy |

The order's total discount stays in the existing `orders.discount_amount`.

### 3.4 `order_items` (1 new column) · `2026_10_31_100004_add_discount_amount_to_order_items_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| discount_amount | decimal(10,2) | no | 0 | discount given on this line (offer or coupon) |

`order_items.subtotal` keeps its meaning (price × quantity, before discount). Rule: the sum of the lines'
`discount_amount` equals `orders.discount_amount` for every new order. Old orders keep 0 on every line.

## 4. Rules

### 4.1 When is an offer live?

`is_active` AND (`starts_at` is null or <= now) AND (`ends_at` is null or > now) AND (`max_orders` is null or
`used_count < max_orders`). For `selected`, it also needs at least one target row.

### 4.2 Which cart lines does an offer cover?

Only lines that can be bought (no blocking issue: unavailable, out of stock, not enough stock).
- `all`: every such line.
- `selected`: the line's book is a target book, OR one of the book's categories is a target category, OR the book's
  series is a target series.
- Ebooks and audiobooks **count** like printed books (each format line counts its quantity; a digital line is
  always 1).

### 4.3 How the offer discount is computed (all in cents, integers)

1. Count the covered units: the sum of the quantities of the covered lines.
2. If covered units < N: no discount (maybe a nudge, 4.6).
3. If covered units >= N: **every** covered unit gets X% off, once. It is not per pair: buying 3 with
   "buy 2, get 15%" gives 15% off all 3, not 2 discounted + 1 full price.
4. Per covered line: `line_discount = floor(line_total_cents × percent_off_basis_points / 10000)`, where
   `percent_off_basis_points = Money::toCents(percent_off)` (15.00% -> 1500). This is the coupon formula, used per line.
5. Offer discount = the sum of the line discounts. Rounding is per line, always down (the customer may get up to
   1 cent less per line than a whole-cart rounding would give; never more than X%).

### 4.4 Offer vs coupon (no stacking)

1. Find the best live offer for the cart: the biggest discount in cents; tie -> lower id. Offers that give 0 do not
   count.
2. If a coupon code was sent, check it exactly as today (`CouponService::evaluate`, on the full subtotal; wrong,
   expired or too-small orders give the same 422 as today; the 5-wrong-guesses lock is unchanged).
3. Winner = the bigger discount in cents. **Tie -> the offer wins** (the coupon stays unused for a later order).
4. Only the winner is saved: offer won -> `orders.offer_id` + copy, `coupon_id = null`, the coupon's `used_count`
   unchanged; coupon won -> `coupon_id`, `offer_id = null`, the offer's `used_count` unchanged.

### 4.5 Free delivery

Free when the order has a printed book AND `subtotal - winning discount >= FREE_SHIPPING_THRESHOLD` (same rule as
coupons today). `amount_needed = max(0, threshold - (subtotal - discount))`.

### 4.6 Nudge (cart only)

At most one nudge (final, owner 2026-10-31). Candidates: live offers where the cart already has at least 1 covered
unit but fewer than N. **When an offer already applies, only candidates with a HIGHER percent than the applied offer
count** (a higher tier); an offer that needs fewer books but saves a lower or equal percent is never shown. Pick the
candidate needing the fewest extra units; tie -> the higher percent, then the lower id. The nudge says how many more books and the percent, not a dollar amount (that
depends on which book is added).

### 4.7 Splitting the discount over the lines (filled at checkout)

- **Offer won:** each covered line gets its own line discount from 4.3; other lines 0.
- **Coupon won:** split by line price: for every line but the last,
  `floor(coupon_discount × line_total / subtotal)`; the last line (highest position) takes the rest so the sum is
  exact. If the rest would be more than that last line's price, it goes to the most expensive line instead.

### 4.8 Returns (refunds)

`ReturnService::refundCents`:
- **The order has a line with `discount_amount > 0`** (new orders with a discount): each returned line refunds
  `unit_price × returned_qty - floor(line_discount × returned_qty / line_qty)`.
- **Otherwise** (old orders, and orders with no discount): today's whole-order proportional rule, unchanged.
- **Partial return of offer books: no claw-back.** Each line keeps its own discount even when the books the customer
  keeps fall under N. Example 1: return Holmes A only, refund $8.50; the customer keeps Holmes B at $8.50, although
  one Holmes book alone would not reach "buy 2".
- **Rounding:** the per-unit share `floor(line_discount × returned_qty / line_qty)` rounds the *discount taken back*
  down, so the refund favours the customer by at most 1 cent per returned line. Example: a line of 3 with a 100-cent
  discount, 1 returned: share floor(33.33) = 33, refund = price − $0.33.

### 4.9 Uses, locks and cancel

- At checkout, after the cart and variant locks and the coupon lock (today's order), lock the live offers that cover
  the cart, in id order (`lockForUpdate`), then compute the totals. If the winning offer is still live, save it and
  `increment('used_count')`.
- Cancelling an order (customer, staff or automatic) gives the use back: `decrement('used_count')` on the locked
  offer row, like coupons today. A returned order keeps its use (like coupons).
- When `orders.offer_id` is null (no offer, or the offer was deleted and the FK set it to null), the decrement is
  skipped; the order's copy (`offer_name`, …) stays for reports.
- Offer ran out or ended between the preview and "Place order" (O1 = A): checkout accepts an optional `offer_id`
  (the offer the customer saw). If it was sent and that offer is no longer the winner (not live, used up, or no longer
  covering the cart), answer 422 on `offer_id` ("This offer has just ended; check your new total") and place nothing.
  Without `offer_id` (today's frontend), the order is placed with whatever wins at that moment.

### 4.10 Admin rules

- Validation: name 1-100; `min_quantity` 2-20; `percent_off` 0.01-50.00; `ends_at` after `starts_at`; `selected` needs
  1-200 targets that exist; `max_orders` >= 1 or empty. `used_count` cannot be set by hand.
- An offer that was used on an order cannot be deleted (409, "pause it instead"), same as coupons.
- Status shown in the list (computed): **Live**, **Scheduled** (starts later), **Paused**, **Ended**, **Used up**,
  **No books** (`selected` with no target rows left, e.g. all targets deleted by cascade).
- Every create, edit, pause and delete is written to `admin_audit_logs` (`AuditLogger`).

## 5. Worked examples (these become tests)

Phnom Penh delivery $1.50, free delivery from $50.00.

**Example 1 (owner's).** Cart: Holmes A $10.00, Holmes B $10.00, cookbook $30.00. Offer "Buy 2 Holmes, get 15%"
(target: Holmes series), no coupon.
- Covered units: 2 (A, B) >= 2, so 15% on A and B: 150 + 150 = **300 cents ($3.00)**; cookbook 0.
- Subtotal $50.00, discount $3.00, net $47.00 < $50.00, so delivery **$1.50** is charged; amount needed **$3.00**.
- Total $47.00 + $1.50 = **$48.50**.
- Saved lines: A `discount_amount` 1.50, B 1.50, cookbook 0.00 (sum 3.00 = `orders.discount_amount`).
- Return the cookbook after delivery: refund **$30.00** (it had no discount). Return Holmes A: **$8.50**.

**Example 2 (coupon wins).** Same cart with coupon `READ10` (10%): coupon $5.00 > offer $3.00, so the coupon wins.
- Split: A floor(500 × 1000 / 5000) = 100, B 100, cookbook (last line) 500 - 200 = 300.
- Net $45.00, delivery $1.50, total $46.50; `offer_id` null; the offer's `used_count` unchanged.
- Return the cookbook: refund $30.00 - $3.00 = **$27.00**.

**Example 3 (all units, not per pair; ebooks count).** Holmes #1 paperback $10.00 × 2 + Holmes #2 ebook $7.99,
"Buy 2 Holmes, get 15%": 3 covered units, all discounted. Line 1: floor(2000 × 1500 / 10000) = 300; ebook line:
floor(799 × 1500 / 10000) = 119 (119.85 rounded down). Discount $4.19.

**Example 4 (tie).** Offer and coupon both give $3.00: the offer wins, the coupon is not used.

**Example 5 (nudge).** Cart: Holmes A only, offers "Buy 2 Holmes, 15%": nudge = add 1 more, 15%, "Sherlock Holmes".

## 6. API changes (all added to `docs/API.md` and `public/openapi.yaml`)

Existing fields keep their meaning, so today's frontend keeps working on the new API.

### 6.1 `GET /cart`: new `pricing` block, and `in_offer` on each line

```json
"pricing": {
  "subtotal_usd": "50.00", "subtotal_khr": "...",
  "discount_usd": "3.00", "discount_khr": "...",
  "total_before_shipping_usd": "47.00", "total_before_shipping_khr": "...",
  "offer": { "id": 3, "type": "buy_n_percent", "min_quantity": 2, "percent_off": "15.00",
             "ends_at": "2026-11-15T16:59:59Z", "target_label": "Sherlock Holmes",
             "discount_usd": "3.00", "discount_khr": "..." },
  "nudge": { "offer_id": 4, "add_quantity": 1, "percent_off": "20.00", "target_label": "Sherlock Holmes" },
  "free_shipping": { "threshold_usd": "50.00", "threshold_khr": "...", "amount_needed_usd": "3.00",
                     "amount_needed_khr": "...", "is_free": false }
}
```
`offer`, `nudge` and `free_shipping` can each be null (`free_shipping` is null when free delivery is off or the
cart has only ebooks/audiobooks). `target_label`: the series or category name when the offer has exactly one
series or one category target; null for `all` or mixed targets (the frontend then says "selected books").

### 6.2 `POST /cart/coupon/check`

Today's fields stay and still describe the coupon alone. New: `applied` (`"coupon"` or `"offer"`), `offer` (same
shape as 6.1 or null), `best_discount_usd/khr`, `best_total_before_shipping_usd/khr`, `free_shipping` (as 6.1, for
the winner). Example: coupon $5.00 vs offer $3.00 -> `applied: "coupon"`.

### 6.3 `POST /checkout/preview`

`discount` = the winner. `coupon_code` is set only when the coupon won (today a code that is checked is always
shown). New: `applied` (`"offer"`, `"coupon"` or null), `offer` (6.1 shape or null), `coupon_not_used`
(`{ "code": "READ10", "reason": "offer_better" }` or null). Free-delivery fields use the winner.

### 6.4 `POST /checkout`

Same request as today, plus an optional `offer_id` (integer; O1 = A, see 4.9). The order is saved as in 4.4, 4.7, 4.9.

### 6.5 Order responses (`OrderResource`, customer and staff)

New: `offer` (`{ "id", "name", "min_quantity", "percent_off" }` from the order's copy, or null); each item gets
`discount_usd`. The order CSV export gets an `offer_name` column (added at the end, so old columns don't move).

### 6.6 Book list and book detail: `offer` field (Q8 A)

`offer`: the best live offer that covers this book (biggest percent; tie -> lower N, then lower id), as
`{ "id", "min_quantity", "percent_off", "ends_at", "target_label" }`, or null. Live offers + targets are read once
and cached 60 seconds; the cache is cleared after an admin saves, pauses or deletes an offer. Dates and limits are
checked on every read; a cached `used_count` can be up to 60 seconds old (checkout re-checks with the lock).
**No N+1 queries:** the matching runs in memory on the cached offers, using the page's books with their categories
eager-loaded (`series_id` is a column on `books`). A query-count test proves a page of 24 books makes the same number
of queries with 0 offers and with several offers.

### 6.7 Staff endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/staff/offers` (filters: status, search) | staff with `catalog:view`, admin |
| GET | `/staff/offers/{offer}` | staff with `catalog:view`, admin |
| POST | `/staff/offers` | admin |
| PATCH | `/staff/offers/{offer}` (also pause/resume via `is_active`) | admin |
| DELETE | `/staff/offers/{offer}` (409 when used) | admin |
| GET | `/staff/reports/offers?from&to` (+ CSV, like other reports) | admin |

Offer response: all columns, `status` (4.10), `targets` (`[{ "type": "book"|"category"|"series", "id", "label" }]`),
`orders_count`.

### 6.8 Demo data and rollout

- `DemoSeeder` creates one sample offer ("Buy 2 Sherlock Holmes, get 15%") **paused** (`is_active = false`), so
  seeding never starts a live discount on the real shop.
- Until an admin creates (or resumes) a live offer, nothing changes for customers.
- Owner step (OWNER_TODO): merge frontend `feature/offers-storefront` **before** creating the first live offer.
  Reason: today's checkout page does not know about offers, so a coupon that lost to an offer would just disappear
  from the summary without a word.

## 7. Reports (Task D)

`GET /staff/reports/offers?from=&to=`, same basis as the coupons report: orders **placed** in the range, not
cancelled, not deleted, shop time zone, 5-minute cache, CSV with UTF-8 BOM.

Per offer (grouped by `orders.offer_id`; the current offer name, or the order copy if the offer was deleted):
orders, units (all books in those orders), units with the offer discount (`order_items.discount_amount > 0`),
revenue (sum of `total_amount`), discount given, average order value, books per order.
One more row, **Orders without an offer** (`offer_id` null, coupon orders included): orders, units, revenue,
discount, average order value, books per order. Admin page: a new **Offers** tab in `ReportsPage.tsx`.

## 8. Where the customer sees it (frontend)

- Book card: the existing `SaleBadge` with "Buy 2, save 15%".
- Book page: one line near the price, e.g. "Buy 2 from Sherlock Holmes, save 15%. Ends 15 Nov."
- Cart: discounted lines marked (`in_offer`), an "Offer −$3.00" line, the nudge ("Add 1 more from Sherlock Holmes to
  save 15%"), the free-delivery bar with the API's numbers (no more fixed $50 from `src/content/shop.ts`).
- Checkout: "Offer: Buy 2, save 15% −$3.00" or "Coupon READ10 −$5.00"; if the coupon lost, "Your coupon READ10
  wasn't used: the offer saves you more."
- Order page and confirmation: the offer line.
- Every piece shows nothing when the API has no `pricing` / `offer` fields yet.

## 9. Branches (each works alone; none is merged by me)

1. Backend `feature/offers-engine`: this spec, migrations, model, rules (`OfferService`), cart, coupon check,
   preview, checkout, cancel, returns, book `offer` field, staff CRUD, order resource + CSV, paused demo offer
   in `DemoSeeder`, docs, tests.
2. Frontend `feature/offers-storefront`: badge, book line, cart, checkout, order page.
3. Frontend `feature/offers-admin`: `/admin/offers` list + form; "not available yet" on a 404.
4. Backend + frontend `feature/offer-reports`: report endpoint + CSV, Offers tab.

## 10. Tests (backend, engine branch)

- Examples 1-5 above, exact cents, including saved line discounts summing to `orders.discount_amount`.
- No double discount: offer and coupon never both on one order; the loser's `used_count` unchanged.
- Paused, scheduled, ended, used-up and "No books" offers are ignored; blocked cart lines don't count.
- Free delivery with an offer: the $52 cart with a 10% offer ($46.80) pays delivery, amount needed $3.20.
- Stock and totals: stock goes down exactly as today; `total = subtotal - discount + shipping`.
- Race: `max_orders = 1`, two customers check out at the same time: only one order gets the offer; the other
  (sent with `offer_id`) gets 422 and no order; sent without `offer_id`, it is placed at full price.
- Cancel gives the offer use back; a returned order does not; cancel with the offer deleted (`offer_id` null) works.
- O1: checkout with an `offer_id` that no longer applies -> 422, nothing placed, cart kept.
- Book list query count is the same with 0 and with several offers.
- `DemoSeeder` offer is paused.
- Returns: Example 1 cookbook $30.00, Holmes A $8.50; Example 2 cookbook $27.00; an old order (all lines 0) still
  uses the proportional rule.
- Coupon check and preview report `applied`, `coupon_not_used`, the tie rule.
- Staff: admins create/edit/pause/delete, staff only view, customers 403; used offers can't be deleted; audit rows;
  validation limits (N 2-20, X <= 50, dates).
- `OpenApiSpecTest` passes (every new route documented).

## 11. Decided questions

**O1 = A (owner, 2026-10-31).** The offer runs out between the preview and "Place order": checkout accepts an
optional `offer_id`; if it no longer applies, 422 and nothing is placed (rule in 4.9).
