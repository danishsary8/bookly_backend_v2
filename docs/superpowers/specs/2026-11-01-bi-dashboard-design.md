# BI admin dashboard: design

Date: 2026-11-01 · Task E · PRODUCTION_CHECKLIST.md 4.2 · Status: **approved by the owner (choices below)**

## Goal
Make `/admin` read like a real bookstore's dashboard, as good as or better than the owner's sample
(tour-trip-frontend.vercel.app/admin, described in PRODUCTION_CHECKLIST 4.2): KPI cards with a trend line and "% vs
previous period", an income chart against the previous period, an orders-by-status donut, quick jump (Ctrl K), menu
badge counts, and bookstore extras.

## Owner choices (2026-11-01)
1A default period 30 days · 2A dead stock = in stock, no sale in 60 days · 3A stock value at selling price (labelled; no
cost column) · 4A sales by province as a top-10 bar list · 5A menu badges: pending orders, open returns, low stock ·
6A the dashboard stays admin-only.

## Already there (kept)
`GET /staff/dashboard/summary` (KPIs + previous period + change %, orders by status, best sellers, top categories, low
stock, open returns) and `GET /staff/dashboard/sales` (one row per day). Grouped menu, notification bell. Reports
(sales, orders, products, customers, funnel, coupons, offers).

## Branches (each works alone; none changes a migration)
1. Backend `feature/dashboard-insights`
   - `GET /staff/dashboard/insights?period=` (admin, `throttle:admin-analytics`, 5-minute cache, arrays only):
     - `trend`: one row per day for this period and the previous one of the same length (aligned by day index): net
       revenue, orders placed, average order (delivered), new verified customers. For sparklines and the dashed line.
     - `dead_stock`: printed formats in stock (`stock_quantity > 0`, active, book visible) with no copy in a
       non-cancelled order placed in the last 60 days (fixed window, not the period): count, copies, value at selling
       price, top 10 by value (title, format, SKU, stock, last sold date or null).
     - `stock_value`: printed active variants: copies in stock and value at selling price (`price_usd × stock`).
     - `top_authors`: top 10 by copies in orders placed in the period (not cancelled): copies and sales.
     - `provinces`: top 10 by orders placed in the period (not cancelled), province detected from the order's address
       snapshot (`DeliveryArea::detectProvince`), "Unknown" when not found: orders and order totals.
     - `promotions`: orders in the period with an offer / with a coupon / with neither: orders and discount.
     - `repeat_customer_rate`: share of customers with an order in the period who had an earlier order too.
   - `GET /staff/dashboard/counts` (all staff): `pending_orders`, `open_returns`, `low_stock` (menu badges), cached 60 s.
2. Frontend `feature/admin-dashboard-redesign`: KPI cards with sparkline, change vs previous, "View …" link; income chart
   with solid (this period) vs dashed (previous) and an Income / Orders switch; orders-by-status donut with the total in
   the middle (with a text list for screen readers). Without the insights endpoint the cards show no sparkline.
3. Frontend `feature/admin-quick-jump`: "Search or jump to… Ctrl K" (pages, plus orders by number and books by title)
   and badge counts in the menu from `/staff/dashboard/counts` (hidden when the endpoint is missing).
4. Frontend `feature/admin-insights-widgets`: dead stock, stock value, top authors, provinces, promotions, repeat rate.

## Rules
Money in cents, KHR twin at the current rate. Cancelled and deleted orders never count. Charts always have a text or table
alternative and colour is never the only signal. No N+1: a fixed number of queries per request, tested.
