# Phase 3 Admin Dashboard Architecture & Implementation Plan

Comprehensive implementation plan for ROADMAP Phase 3: the operational and analytical admin dashboard for the Bookly API (`/api/v1/`).

---

## 1. What Already Exists

The backend already has robust core abstractions, services, security gates, and database tables implementing admin and staff controls:

### Existing Routes & Middleware Architecture
- **Route Guard Group:** [`routes/api.php:161`](../routes/api.php#L161) enforces `['auth:sanctum', 'abilities:staff', 'staff.2fa']`.
- **Admin-Only Group:** [`routes/api.php:202`](../routes/api.php#L202) enforces `abilities:admin` on top of staff 2FA.
- **Existing Dashboard Routes:**
  - `GET /api/v1/staff/dashboard/summary` ([`routes/api.php:217`](../routes/api.php#L217)) handled by `DashboardController::summary` ([`DashboardController.php:15`](../app/Http/Controllers/Api/V1/Staff/Admin/DashboardController.php#L15)).
  - `GET /api/v1/staff/dashboard/sales` ([`routes/api.php:218`](../routes/api.php#L218)) handled by `DashboardController::sales` ([`DashboardController.php:22`](../app/Http/Controllers/Api/V1/Staff/Admin/DashboardController.php#L22)).
  - `GET /api/v1/staff/orders/export` ([`routes/api.php:219`](../routes/api.php#L219)) handled by `OrderExportController` ([`OrderExportController.php:22`](../app/Http/Controllers/Api/V1/Staff/Admin/OrderExportController.php#L22)).
  - `GET /api/v1/staff/audit-logs` ([`routes/api.php:216`](../routes/api.php#L216)) handled by `AuditLogController` ([`AuditLogController.php:15`](../app/Http/Controllers/Api/V1/Staff/Admin/AuditLogController.php#L15)).
  - `GET /api/v1/staff/exchange-rates` & `POST /api/v1/staff/exchange-rates` ([`routes/api.php:214-215`](../routes/api.php#L214-L215)).

### Existing Services & Models
- `DashboardService` ([`DashboardService.php:21`](../app/Services/Admin/DashboardService.php#L21)): Computes periods (`today`, `7d`, `30d`, `custom`), gross/refund/net revenue, delivered order count, average order value, order counts by status, new verified customers count, top 10 best sellers by copies sold, open returns count, and top 20 low-stock variants.
- `AuditLogger` ([`app/Services/Admin/AuditLogger.php`](../app/Services/Admin/AuditLogger.php)): Structured audit logging (`created`, `updated`, `deleted`, `custom`) recording `staff_user_id`, `action`, `entity_type`, `entity_id`, and `before_data`/`after_data` into `admin_audit_logs`.
- `StaffManagementService` ([`app/Services/Staff/StaffManagementService.php`](../app/Services/Staff/StaffManagementService.php)): Prevents self-demotion, self-deactivation, and demotion of the last active admin.
- `OrderStatusService` ([`app/Services/Orders/OrderStatusService.php`](../app/Services/Orders/OrderStatusService.php)): Centralized transitions writing to `order_status_history` and `admin_audit_logs`.
- `InventoryService` ([`app/Services/Inventory/InventoryService.php`](../app/Services/Inventory/InventoryService.php)): Atomic row locking and tracking in `inventory_movements`.

### Existing Domain Tables & Columns
- `orders`: `id`, `customer_id`, `order_number`, `status`, `subtotal`, `discount_amount`, `shipping_fee`, `tax_amount`, `total_amount`, `currency`, `payment_method`, `placed_at`, `deleted_at`, `created_at`, `updated_at`.
- `order_status_history`: `id`, `order_id`, `status`, `note`, `changed_by_staff_id`, `created_at`.
- `order_items`: `id`, `order_id`, `book_variant_id`, `quantity`, `unit_price`, `subtotal`.
- `returns`: `id`, `order_id`, `customer_id`, `reason`, `status`, `refund_amount`, `staff_note`, `handled_by_staff_id`, `requested_at`, `resolved_at`, `created_at`, `updated_at`.
- `inventory_movements`: `id`, `book_variant_id`, `change_qty`, `reason`, `reference_type`, `reference_id`, `created_by_staff_id`, `created_at`.
- `admin_audit_logs`: `id`, `staff_user_id`, `action`, `entity_type`, `entity_id`, `before_data`, `after_data`, `created_at`.
- `exchange_rates`: `id`, `base_currency`, `target_currency`, `rate`, `effective_at`, `created_at`.
- `staff_users`: `id`, `name`, `email`, `password_hash`, `role`, `two_factor_secret`, `two_factor_enabled`, `is_active`, `created_at`, `updated_at`.

---

## 2. First Slice: Dashboard Numbers (Enhancement & Period Comparison)

### Target Endpoints
- `GET /api/v1/staff/dashboard/summary`
  - **Permission:** Today both dashboard routes sit inside the admin-only route group (`abilities:admin` at [`routes/api.php:202`](../routes/api.php#L202)). This plan keeps them admin-only (`['auth:sanctum', 'abilities:admin', 'staff.2fa']`). Opening them or a subset of operational metrics to general staff (`abilities:staff`) is an **open question** for the owner (see Section 4, Question 4), not decided here.
  - **Query Parameters:**
    - `period`: `today` | `7d` | `30d` (default) | `custom`
    - `from`: `YYYY-MM-DD` (required if `period=custom`)
    - `to`: `YYYY-MM-DD` (required if `period=custom`, `after_or_equal:from`)
    - `compare`: `boolean` (optional, default `true` — computes identical metrics for the preceding period)

### JSON Response Structure
```json
{
  "data": {
    "period": {
      "from": "2026-09-13",
      "to": "2026-10-12",
      "timezone": "Asia/Phnom_Penh"
    },
    "comparison_period": {
      "from": "2026-08-14",
      "to": "2026-09-12",
      "timezone": "Asia/Phnom_Penh"
    },
    "kpis": {
      "net_revenue": {
        "current_usd": "197.46",
        "current_khr": "809586",
        "previous_usd": "150.00",
        "previous_khr": "615000",
        "change_percentage": 31.64
      },
      "gross_revenue": {
        "current_usd": "217.46",
        "current_khr": "891586",
        "previous_usd": "165.00",
        "previous_khr": "676500",
        "change_percentage": 31.79
      },
      "refunds": {
        "current_usd": "20.00",
        "current_khr": "82000",
        "previous_usd": "15.00",
        "previous_khr": "61500",
        "change_percentage": 33.33
      },
      "delivered_orders": {
        "current": 8,
        "previous": 6,
        "change_percentage": 33.33
      },
      "average_order_value": {
        "current_usd": "27.18",
        "current_khr": "111438",
        "previous_usd": "27.50",
        "previous_khr": "112750",
        "change_percentage": -1.16
      },
      "orders_placed": {
        "current": 13,
        "previous": 10,
        "change_percentage": 30.00
      },
      "new_verified_customers": {
        "current": 5,
        "previous": 4,
        "change_percentage": 25.00
      }
    },
    "orders_by_status": {
      "pending": 2,
      "processing": 1,
      "shipped": 1,
      "delivered": 8,
      "cancelled": 1,
      "returned": 0,
      "paid": 0
    },
    "top_categories": [
      {
        "category_id": 1,
        "name": "Classic Fiction",
        "copies_sold": 12,
        "sales_usd": "144.00",
        "sales_khr": "590400"
      }
    ],
    "best_sellers": [
      {
        "book_id": 4,
        "title": "The Great Gatsby",
        "copies_sold": 5,
        "sales_usd": "65.00",
        "sales_khr": "266500"
      }
    ],
    "open_returns_count": 1,
    "low_stock_count": 3
  }
}
```

### PostgreSQL Computation Specifications
1. **Timezone Handling (`Asia/Phnom_Penh` / UTC):**
   - Timestamps in `orders.placed_at`, `order_status_history.created_at`, `returns.resolved_at`, and `customers.created_at` are stored in UTC.
   - For a given user period `[start_local, end_local)` in `Asia/Phnom_Penh` (UTC+7), the query calculates boundary timestamps in UTC:
     `$fromUtc = $start->setTimezone('UTC')`, `$toUtc = $end->setTimezone('UTC')`.
   - In daily grouped charts, days are cast using:
     `DATE((column AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Phnom_Penh')`.
2. **Revenue Calculation (Cash Basis vs Accrual Basis):**
   - **Gross Revenue:** Sum of `orders.total_amount` for orders that reached status `delivered` within the period.
     Join `order_status_history` on `order_id` where `status = 'delivered'` and `order_status_history.created_at BETWEEN $fromUtc AND $toUtc`.
   - **Refunds:** Sum of `returns.refund_amount` where `status = 'refunded'` and `returns.resolved_at BETWEEN $fromUtc AND $toUtc`.
   - **Net Revenue:** `gross_cents - refund_cents`.
3. **Currency Conversion (USD vs KHR):**
   - All database math is calculated in integer USD cents (`Money::toCents`) to eliminate floating-point drift.
   - Dual currency outputs (`usd` and `khr`) query the active exchange rate via `CurrencyService::usdToKhr()`, based on `ExchangeRate::current('USD', 'KHR')`.
4. **Previous Period Computation Formula:**
   - Let period length be `N` days (`$start->diffInDays($end)`).
   - `$prevEnd = $start;`
   - `$prevStart = $start->subDays($N);`
   - Percentage change formula:
     `$prevValue > 0 ? round((($currentValue - $prevValue) / $prevValue) * 100, 2) : ($currentValue > 0 ? 100.0 : 0.0)`.

### Proposed Indexes (Design Proposal Only — No Migrations)
To sustain performant multi-period aggregation queries on Neon/Render without full table scans:
- `CREATE INDEX idx_order_status_history_status_created ON order_status_history (status, created_at) INCLUDE (order_id);`
  *(Optimizes delivered order revenue joins).*
- `CREATE INDEX idx_orders_placed_status ON orders (placed_at, status) WHERE deleted_at IS NULL;`
  *(Optimizes order volume and status counts).*
- `CREATE INDEX idx_returns_status_resolved ON returns (status, resolved_at) INCLUDE (refund_amount);`
  *(Optimizes refund aggregation).*
- `CREATE INDEX idx_customers_verified_created ON customers (created_at) WHERE phone_verified_at IS NOT NULL OR email_verified_at IS NOT NULL;`
  *(Optimizes new customer metric).*

### Caching Strategy on Free Plan
- Neon compute units sleep when idle, and Render free instances have CPU limits.
- Cache aggregated dashboard responses using Laravel's Cache facade (`CACHE_STORE=database` or Redis):
  `Cache::remember("dashboard:summary:{$period}:{$start->toDateString()}:{$end->toDateString()}", now()->addMinutes(10), fn () => ...)`
- Invalidate cache tag/key whenever `OrderStatusService::changeByStaff` transitions an order or `ReturnService::refund` processes a refund.

### Automated Tests to Prove Slice 1
- `DashboardComparisonTest`:
  - Seed orders placed in period A vs period B. Verify math for `change_percentage`.
  - Edge case: previous period has 0 orders (verify no division by zero error; yields 100.0% or 0.0%).
  - Edge case: delivery occurred across midnight UTC but within the same Phnom Penh calendar day.
  - Verification of dual USD and KHR formatting.

---

## 3. Subsequent Slices (Roadmap Phase 3)

Each task is scoped to a small, independently reviewable PR:

### Slice 2: Order Management & Status Workflow
- **Backend:**
  - `GET /api/v1/staff/orders` enhancements: add filters by payment method (`payment_method`), shipping area (`phnom_penh` vs `provinces`), and date range presets.
  - `POST /api/v1/staff/orders/{id}/status`: return updated order snapshot and audit entry.
- **Frontend:**
  - Order detail view showing customer contact, shipping address snapshot, line items with variant cover image, status timeline with staff attribution (`changed_by_staff_id`).
  - Action buttons enabled dynamically according to `allowed_next_statuses`.
- **Risks & Dependencies:** Concurrency conflicts if two staff members update an order simultaneously (handled by pessimistic locking in `OrderStatusService`).

### Slice 3: Inventory Movement Audit & Stock Adjustment History
- **Backend:**
  - `GET /api/v1/staff/inventory/movements`: paginated inventory audit feed with filters (`book_variant_id`, `reason`: `sale`|`restock`|`return`|`adjustment`, `staff_user_id`, `from`, `to`).
  - `POST /api/v1/staff/variants/{id}/adjust-stock`: endpoint accepting `{ quantity_change, reason, note }` routed through `InventoryService`.
- **Frontend:**
  - Inventory tracking table displaying real-time stock, threshold indicator, and drawer with full movement history per variant.
- **Risks & Dependencies:** Strict validation preventing stock from dropping below zero on physical adjustments.

### Slice 4: Catalog Import / Export (CSV)
- **Backend:**
  - `GET /api/v1/staff/books/export`: streamed CSV with UTF-8 BOM, sanitized against CSV formula injection (reusing `OrderExportController::cell`).
  - `POST /api/v1/staff/books/import`: multipart upload validating book fields, authors, categories, formats, ISBNs, and SKUs in a single database transaction.
- **Frontend:**
  - Upload modal with progress bar and tabular validation error preview before committing import.
- **Risks & Dependencies:** Large CSV memory consumption (streamed chunks required; database transactions to avoid partial imports).

### Slice 5: Customer Directory & Activity Timeline
- **Backend:**
  - `GET /api/v1/staff/customers/{id}/activity`: aggregated activity feed combining orders, returns, reviews, and address changes.
- **Frontend:**
  - Customer profile page with tabs: Overview (lifetime spend, orders by status), Orders, Returns, Reviews, and Account Actions (Deactivate, Activate, Reopen).
- **Risks & Dependencies:** Privacy / PII handling; ensure passwords and tokens are never exposed.

### Slice 6: Staff Permissions & Role-Based Access Control (RBAC)
- **Backend:**
  - Refine abilities middleware to differentiate between `staff` (catalog, order fulfillment) and `admin` (member management, financial dashboard, customer deactivation, exchange rates).
  - Enforce permission checks across all mutating endpoints.
- **Frontend:**
  - Dynamic navigation menu hiding administrative/financial pages from general staff.
- **Risks & Dependencies:** Demoting an admin must revoke all active Sanctum tokens (`StaffManagementService` already enforces this).

### Slice 7: Strict Delete Security (Two-Factor Re-Authentication & Soft Delete)
- **Backend:**
  - Deletion of sensitive entities (`books`, `coupons`, `customers`) requires `X-Staff-2FA-Code` and confirming entity name in request body `{ confirm_name: "..." }`.
  - Enforce soft-deletion with 30-day restore window before permanent wipe.
  - Dispatch audit log entry and optional Telegram security notice.
- **Frontend:**
  - Security modal requiring TOTP code input and exact name confirmation typing.
- **Risks & Dependencies:** Accidental data loss prevented by soft deletes.

### Slice 8: Real-Time Telegram Operational Notifications
- **Backend:**
  - Event listener on order placement and refund issuance triggering Telegram notifications to staff chat (`TELEGRAM_ALERT_CHAT_ID`).
  - Formatted alert: `🔔 New Order #ORD-YYYYMMDD-XXXXX | Total: $XX.XX | Payment: COD | Area: Phnom Penh`.
- **Frontend:**
  - Toggle in admin settings to enable/disable specific operational Telegram alerts.
- **Risks & Dependencies:** Telegram rate limits (30 msgs/second globally); failures must not block customer checkout transactions.

---

## 4. Open Questions for the Owner

1. **Revenue Recognition for Cash on Delivery (COD):**
   * *Option A (Recommended - Current):* Cash basis. Revenue is recognized on the date the order status becomes `delivered` (when cash is physically collected).
   * *Option B:* Accrual basis. Revenue is recognized on the date the order was placed (`placed_at`), regardless of delivery status (excluding cancelled orders).
   * *Recommendation:* Stick with Option A (Cash basis). For COD businesses, unpaid orders that get rejected at delivery never produce cash.
2. **Treatment of Cancelled & Returned Orders:**
   * *Option A (Recommended):* Cancelled orders produce \$0 revenue. Refunded returns subtract from gross revenue on the day the refund was paid (`resolved_at`). Shipping fee is never refunded.
   * *Option B:* Subtracted retroactively from the original order date.
   * *Recommendation:* Option A accurately mirrors accounting cash flow.
3. **Currency Display Preference in Dashboard:**
   * *Option A (Recommended):* Display primary metrics in USD with equivalent KHR beside it (calculated using the current exchange rate).
   * *Option B:* Toggle between USD and KHR mode.
   * *Recommendation:* Option A provides clear, instant visibility for local Cambodian staff.
4. **Access Control for Financial KPIs:**
   * *Option A (Recommended):* Dashboard summary and sales numbers restricted strictly to `admin` role. General `staff` see only operational metrics (orders to ship, open returns, low stock).
   * *Option B:* All staff members see revenue and financial charts.
   * *Recommendation:* Option A safeguards business financial metrics.

---

## 5. Do Not Break (Immutability List)

The following existing endpoints and behaviors must remain strictly unchanged:
1. `GET /api/v1/ping` and `GET /api/v1/health` contracts.
2. Public catalog routes (`/api/v1/books*`, `/api/v1/authors*`, `/api/v1/categories*`) must not require staff tokens.
3. Customer checkout flow (`POST /api/v1/checkout`) and required `Idempotency-Key` header.
4. Existing OpenAPI specification contracts in `public/openapi.yaml` (`OpenApiSpecTest` must continue to pass).
5. Existing 27 domain migrations and table schemas (`database/migrations/`).
6. `OrderStatusService` state transition rules (`allowedNext()`).
7. `StaffManagementService` protections against deactivating the last active admin.
