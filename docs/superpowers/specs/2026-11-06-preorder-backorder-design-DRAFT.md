# Pre-Orders and Back-Orders — Design (DRAFT)

Date: 2026-11-06 · Status: DRAFT for Danish's review

---

## 1. Goal

Enable customers to reserve upcoming anticipated books before their release date (**Pre-Orders**) and place orders for high-demand out-of-stock items expected from publishers (**Back-Orders**). This captures sales that would otherwise be lost to competitors, provides demand forecasting to Bookly staff, and automatically fulfills reserved orders the moment stock arrives in Phnom Penh.

---

## 2. User Flows

### 2.1 Customer Flows
1. **Pre-Order Flow**:
   - Customer views an upcoming book (e.g. release date in 3 weeks).
   - Detail page displays a distinctive **Pre-Order** badge and estimated delivery date ("Releases November 28, 2026 · Estimated shipping Nov 29–Dec 1").
   - Primary button is vermilion CTA: **Pre-Order Now**.
   - Customer adds to cart and checks out via standard checkout:
     - Payment options: **Pay Now (KHQR / Card)** or **Cash on Delivery (Pay when delivered)**.
   - Order confirmation clearly states: *"This order contains pre-order items. It will be dispatched as soon as the book releases on Nov 28."*
2. **Back-Order Flow**:
   - Customer views a book variant whose `stock_quantity == 0` but `backorder_allowed == true`.
   - Instead of "Out of stock", button says **Back-Order (Ships in 5–7 days)**.
   - Note explains: *"Currently awaiting stock from publisher. Reserve your copy now to guarantee dispatch on arrival."*
3. **Mixed Cart Options**:
   - If cart contains both in-stock and pre-order/back-order items:
     - **Option A (Ship together)**: Default. Save on delivery; all books dispatch together once pre-order releases.
     - **Option B (Split shipment)**: In-stock books ship immediately; pre-order ships on release date (adds single extra local delivery fee).
4. **Automated Status Updates**:
   - When the release date arrives / stock is received:
     - Automated email and Telegram notification: *"Great news! [Book Title] has arrived at our warehouse and your order is now being packed."*

### 2.2 Admin / Staff Flow
1. **Setting up Pre-Orders**:
   - In **Admin → Books → Edit**:
     - Toggle `is_preorder`: `true`.
     - `release_date`: date (e.g. `2026-11-28`).
     - `preorder_limit`: maximum reservation copies (prevents overselling publisher allocations).
2. **Setting up Back-Orders**:
   - In **Admin → Books → Variants**:
     - Toggle `allow_backorder`: `true`.
     - `restock_expected_date`: optional estimated arrival date.
     - `backorder_cap`: maximum allowable back-orders.
3. **Receiving Stock & Allocation**:
   - In **Admin → Inventory / Restock**:
     - When a shipment arrives, staff inputs received quantity (e.g., 50 copies).
     - System automatically prioritizes reserved pre-orders and back-orders in first-come, first-served (FIFO) sequence.
     - Orders transition from `awaiting_stock` → `processing` (ready for packing and courier pickup).

---

## 3. Proposed Data Model

> [!NOTE]
> All tables and columns below are marked as **proposed** and do not alter existing migrations.

### Proposed Changes to Table: `books`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `is_preorder` | boolean | default false | Marks book as a pre-order item |
| `release_date` | date | null | Public release date |
| `preorder_limit` | integer | null | Max pre-order reservations allowed |
| `preorder_count` | integer | default 0 | Number of pre-orders placed |

### Proposed Changes to Table: `book_variants`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `allow_backorder` | boolean | default false | Allow purchasing when stock is 0 |
| `restock_expected_date` | date | null | Estimated publisher arrival date |
| `backorder_cap` | integer | null | Max back-orders permitted |
| `backorder_count` | integer | default 0 | Number of active back-orders placed |

### Proposed Changes to Table: `orders` and `order_items`
| Table | Column | Type | Constraints | Description |
|---|---|---|---|---|
| `orders` | `has_preorder` | boolean | default false | Flags orders containing pre-orders |
| `orders` | `split_shipment` | boolean | default false | Whether customer selected split shipping |
| `orders` | `fulfillment_status` | varchar(30) | default 'standard' | `awaiting_release`, `awaiting_restock`, `ready_to_pack` |
| `order_items` | `item_type` | varchar(20) | default 'in_stock' | `in_stock`, `preorder`, `backorder` |

---

## 4. API Endpoints

### Storefront Endpoints
- `GET /api/v1/books/{id}`:
  - Extends response with `is_preorder`, `release_date`, and `variants[].allow_backorder`, `variants[].restock_expected_date`.
- `POST /api/v1/checkout`:
  - Accepts `split_shipment: boolean`.
  - Calculates proper shipping fee based on combined or split delivery.

### Staff Endpoints
- `GET /api/v1/staff/preorders`:
  - Dashboard showing upcoming releases, reserved volume counts, and publisher order shortfalls.
- `POST /api/v1/staff/inventory/receive`:
  - Request body: `{ variant_id: 12, quantity: 50 }`.
  - Automatically fulfills pending pre-orders/back-orders FIFO, assigns stock, and transitions orders to `processing`.
- `POST /api/v1/staff/books/{id}/preorder-release`:
  - Manual trigger to release a pre-order earlier or later than planned, automatically updating customers.

---

## 5. Security and Business Logic

1. **Overselling Guard (Race Conditions)**:
   - When placing a pre-order or back-order, atomic increments ensure `preorder_count` cannot exceed `preorder_limit`:
     ```sql
     UPDATE books SET preorder_count = preorder_count + 1 WHERE id = ? AND (preorder_limit IS NULL OR preorder_count < preorder_limit);
     ```
2. **Payment Policy & Security**:
   - For Cambodia's market, COD and KHQR are standard.
   - For KHQR, pre-orders are paid upfront. The customer receives a guaranteed reservation.
   - For COD, customers are phoned or Telegram-notified 2 days before release to confirm address before courier dispatch.
3. **Cancellation and Price Guarantee**:
   - If a book price drops between pre-order date and release date, Bookly automatically refunds the difference as Store Credit (Pre-Order Price Guarantee).
   - Customers can cancel a pre-order anytime prior to fulfillment (`awaiting_release` status) for a full refund.

---

## 6. Free-Hosting & Infrastructure Limits

- **Notification Batches (Render Free Tier)**:
  - When a popular manga releases with 300 pre-orders, sending 300 emails and Telegram notifications all at once will trigger timeouts on Render's 512MB RAM worker.
  - *Mitigation*: Process fulfillment notifications in chunked queue jobs (`chunk(25)`) with a 2-second delay between batches to stay safely within memory and email provider rate limits.
- **Scheduled Check Command**:
  - A lightweight daily Artisan command (`php artisan books:check-releases`) runs at 00:05 UTC+7 to flag pre-orders reaching their release date.

---

## 7. Tests to Write

1. **Pre-Order Purchase & Cap**:
   - Can pre-order when `is_preorder = true` and `release_date` is in the future.
   - Ordering when `preorder_count == preorder_limit` fails with HTTP 422 `preorder_limit_reached`.
   - Cancelling a pre-order decrements `preorder_count`.
2. **Back-Order Purchase**:
   - Can purchase a variant with 0 stock if `allow_backorder = true`.
   - Cannot purchase if `stock == 0` and `allow_backorder = false`.
3. **Split Shipping Calculations**:
   - Selecting split shipping calculates two distinct delivery fees for in-stock and pre-order batches.
   - Free shipping threshold (\$50) calculates against the total order value.
4. **FIFO Stock Allocation**:
   - When receiving 20 items for 30 reserved pre-orders, the oldest 20 orders transition to `processing`; remaining 10 stay in `awaiting_stock`.

---

## 8. Rollout Steps

1. **Step 1**: Migration adding proposed columns to `books`, `book_variants`, `orders`, and `order_items`.
2. **Step 2**: Backend pre-order and back-order validation rules in checkout and order creation.
3. **Step 3**: Frontend UI for pre-order badges, estimated release dates, and back-order button states.
4. **Step 4**: Staff inventory allocation and pre-order management dashboard.
5. **Step 5**: Test with sample pre-orders and simulated stock arrivals.

---

## 9. Questions for Danish

1. **Payment for Pre-Orders**: Should pre-orders allow Cash on Delivery (COD), or only paid online (KHQR)?
   - *Recommendation*: **Allow both KHQR and COD**. In Cambodia, many customers only use COD; requiring KHQR upfront might lose 60%+ of potential pre-orders. To reduce COD no-shows, send a Telegram confirmation 2 days before release.
2. **Split Shipping Default**: When a cart has in-stock + pre-order items, should Bookly ship together by default or offer split shipping?
   - *Recommendation*: **Default to "Ship together when all items arrive"** to save customers extra delivery fees. Offer a toggle for "Ship in-stock items now (+\$1.50 Phnom Penh / +\$2.50 provinces)".
3. **Pre-Order Price Guarantee**: If a publisher lowers the book price before release, do we refund the difference?
   - *Recommendation*: **Yes, refund difference as Store Credit**. This builds immense customer trust and encourages early reservations.
