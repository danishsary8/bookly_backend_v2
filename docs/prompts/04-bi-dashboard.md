# Prompt 4 (E): business intelligence admin dashboard

```text
TASK E: Make the admin dashboard look and work like a real bookstore company's, as good as or better than the
owner's sample https://tour-trip-frontend.vercel.app/admin . Full wish list: PRODUCTION_CHECKLIST.md section 4.2
(KPI cards with sparkline, "% vs previous 30 days" green/red, "View ..." links; income this period vs previous
(solid vs dashed), Income/Orders switch; orders-by-status donut with total in the middle; grouped side menu
Overview/Catalogue/Operations/Insights, "Search or jump to... Ctrl K", notification bell with count, badge counts;
bookstore extras: best sellers, slow movers/dead stock, stock value, category and author sales, repeat-customer
rate, average order value, coupon/offer results, sales by province).

Already exists (do NOT rebuild): backend DashboardService (summary with compare, daily sales, 5 min cache) via
GET /staff/admin/dashboard/summary and /sales, ReportsService via /staff/admin/reports/{sales,orders,products,
customers,funnel}; frontend src/pages/admin/DashboardPage.tsx and ReportsPage.tsx; plan doc
bookly_backend_v2/docs/ADMIN_DASHBOARD_PLAN.md (read it).

Step 1 (read only): open the sample site and our current dashboard (local, as admin: docs page > Details > Test as
staff or admin, or the admin login), read the code above, and give me a table: wish | already have | missing |
which endpoint/component. STOP.

Step 2 (design, ask me): propose the layout (describe it, or a quick sketch/mockup), the KPI list, chart choices,
date ranges, and the split into branches. Use design-system/bookly/MASTER.md (colours, type, spacing, motion);
page-specific notes go to design-system/bookly/pages/admin-dashboard.md. Ask about: which KPIs first, default
period (30 days?), currency USD/KHR display, which extras are must-have for v1. Write the agreed design to
bookly_backend_v2/docs/superpowers/specs/<date>-bi-dashboard-design.md and ask me to review it.

Step 3+ (build, after my OK), suggested branches, each working alone:
  1. backend feature/dashboard-insights: new numbers (dead stock, stock value, category/author sales, repeat rate,
     AOV, province) with tests, cached, staff/admin abilities respected, rate limit like the others.
  2. frontend feature/admin-dashboard-redesign: KPI cards + sparklines + compare, income chart, donut. Must work
     against today's API (hide cards whose data is missing).
  3. frontend feature/admin-nav-groups: grouped menu, Ctrl K jump, bell + badge counts.
  4. frontend feature/admin-insights-widgets: the extras.
Every step ends with "confirm". Accessibility: charts have text/table alternatives, colour is never the only
signal, keyboard works. Performance: no N+1, heavy queries cached. Backend: php artisan test + pint, API docs.
Frontend: lint, typecheck, test, build, browser check desktop + 1280 + 390 px, light/dark if the admin has it.
Notes: WORKLOG, HANDOFF.md, OWNER_TODO.md on docs/handoff-notes.
```
