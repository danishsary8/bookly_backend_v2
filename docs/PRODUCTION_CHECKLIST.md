# Production checklist

What Bookly still needs to run as a real shop with real customers, compared with a complete production e-commerce
platform. Written 2026-10-30.

**Legend:** ✅ done · 🛠 I (the developer) can build it · 👤 your part (accounts, money, settings only you can reach)

## How this list was made

1. **Reverse engineering with REA** (`rea analyze-javascript-application`, REA 6.7.0) on the *built* bundles that
   [Bagisto](https://github.com/bagisto/bagisto) ships (an open-source Laravel shop used in production):
   - Storefront bundle: 5 files, 4 parsed, 0 failures. It only contains Vue, form-validation messages in 25 languages
     and a date picker: Bagisto renders its shop on the server, so its features live in server code, not in the browser.
   - Admin bundle: 49 files, 48 parsed, 0 failures. Modules include `echo` (Laravel Echo: live updates over
     websockets), `chart` and `drag-and-drop`. The shipped admin icons name its settings screens: `amazon-s3`,
     `cloudflare-r2`, `cache-management`, `full-page-cache`, `captcha`, `elasticsearch`, `search-engine`, `sitemap`,
     `gdpr`, `eu-withdrawal`, `invoice`, `unpaid-invoices`, `payment-method`, `shipping`, `tax`, `exchange-rate`,
     `email`, `file-management`, `store-information`, `theme`, `magic-ai`.
   - REA's own limits: the graph is incomplete, and a feature missing from a bundle is not proof it is missing from
     the product. So each item below was also checked in source code.
2. **Source check** of this repository (routes, controllers, config, workflows) for each of those features.

## 1. Must do before real customers

| # | What | Why it matters | Status |
|---|------|----------------|--------|
| 1 | **Keep uploaded images safe** (book covers) | Covers are saved on the server's own disk (`MediaUploadController`, `public` disk). On Render that disk is wiped at every deploy or restart, so **uploaded covers disappear**. Bagisto ships S3 and Cloudflare R2 settings for this. | ✅ code ready (`MEDIA_DISK=r2`, branch `feature/r2-media-storage`) · 👤 create the R2 bucket and keys, set the Render variables (DEPLOYMENT.md → F6b), re-upload old covers |
| 2 | **Online payment** (Bakong KHQR or ABA PayWay) | Today only cash on delivery. The `payments` and `payment_webhook_logs` tables are ready. | 🛠 payment + webhook flow · 👤 open a merchant account and get API keys |
| 3 | **Your own domain** (e.g. `api.bookly.com`, `bookly.com`) | `*.onrender.com` / `*.vercel.app` look temporary to customers and limit email trust. | 👤 buy the domain, add it in Render and Vercel · 🛠 update `APP_URL`, CORS, links |
| 4 | **Email from your domain** (SPF, DKIM) | Codes and order emails land in spam without a verified sending domain. | 👤 verify the domain in Brevo/Resend (DNS records) · 🛠 set `MAIL_FROM_ADDRESS` |
| 5 | **Database that won't lock** | Neon free has 100 compute hours/month; it already ran out once (2026-10-22). | 👤 watch usage weekly; upgrade Neon (or move) before launch |
| 6 | **Background worker for emails** | With `QUEUE_CONNECTION=sync`, every email is sent during the customer's request (slower checkout; a mail outage is felt at once). | 👤 decide: paid Render worker (~$7/month) or keep sync · 🛠 switch config |
| 7 | **"Download my data"** for customers | Bagisto ships a `gdpr` screen. Bookly can close and erase an account but cannot export a customer's data. | 🛠 `GET /me/export` (profile, addresses, orders, reviews as JSON) |

## 2. Should do soon

| # | What | Why | Status |
|---|------|-----|--------|
| 8 | **Staging server** | Test changes on a copy before customers see them. | 👤 second Render service + Neon branch · 🛠 config and docs |
| 9 | **Uptime alert on `/up`** | Know within minutes when the site is down. | ✅ UptimeRobot set to `/up` (2026-10-30) |
| 10 | **Typo-tolerant search** | Bagisto offers Elasticsearch. Bookly uses PostgreSQL full-text (fast, no extra service) but a typo finds nothing: `frankenstein` → 1 book, `frankenstien` → 0, `sherlok` → 0 (checked on the demo data). | ✅ typo fallback with `pg_trgm` (branch `feature/typo-tolerant-search`; no new service, no migration) |
| 11 | **Live admin updates** | Bagisto's admin uses websockets (`echo` module). Bookly staff refresh the page; new orders already reach Telegram. | 🛠 optional; Telegram alerts cover the urgent case |
| 12 | **Real load test, then cache if needed** | Bagisto has full-page cache. Bookly's only load test (`load/RESULTS.md`) ran on the single-threaded dev server, so real production speed is unknown. | 🛠 kit ready: `load/live-check.js` (gentle, stays under the API's own limits; branch `feature/load-test-kit`) · 👤 run it once (`load/README.md`), then caching only if a target is missed |
| 13 | **Cookie notice** | Only needed if you add analytics or ad cookies; sign-in uses a token, not cookies. | 👤 decide when adding analytics |

## 3. Already done ✅

| Area | What is in place |
|------|------------------|
| Accounts and security | Separate customer/staff tokens, mandatory staff 2FA, Turnstile bot check, rate limits, hashed one-time codes, security headers, JSON-only errors with `X-Request-Id` |
| Shop | Catalog, PostgreSQL full-text search (incl. Khmer), cart with issue warnings, coupons, cash-on-delivery checkout with idempotency, orders, buy again, returns, reviews with moderation, wishlist, back-in-stock alerts, newsletter |
| Money | USD + KHR with exchange-rate history, tax and shipping on orders, printable invoices and receipts |
| Back office | Dashboard and reports, CSV import/export, stock history, audit log, staff roles |
| SEO | `/sitemap.xml` (API) and `robots.txt` (frontend) |
| Operations | Docker image, CI on every push, Sentry errors, health checks, uptime monitor, nightly encrypted database backup (GitHub Actions, 02:00 Cambodia), a k6 load-test script (`load/`), request-driven housekeeping |
| Public demo | `/docs` with one-click demo sign-in, protected demo account, hourly reset |

## Suggested order

1 → 3 → 4 → 2 → 7 → 6 → 8 → the rest. Images first (data loss today), then domain and email (trust), then payments.

## 4. Planned features (owner's wishes, 2026-10-31)

Noted for later; nothing is built yet. Each one starts with a short plan and the owner's choices (as before).

### 4.1 Marketing system ("strategy marketing")
Guided by the skills in https://github.com/coreyhaines31/marketingskills (used as the playbook: `offers`, `pricing`,
`marketing-psychology`, `cro`, `popups`, `emails`, `referrals`, `analytics`, `ab-testing`, `marketing-plan`).
- [ ] **"You may also like" on the book page**: similar books when a customer opens one (same author, series,
  category, close title/description; later "customers who bought this also bought" from real orders). Free, runs in
  PostgreSQL (no paid AI service needed to start); an AI model can be added later if wanted.
- [ ] **Offers that make customers buy more**: e.g. "Buy 2, get 10% off", bundle deals ("complete the series"),
  "spend $X more for free delivery" in the cart, limited-time deals. Built on the coupons the shop already has.
- [ ] **Seasonal / weather picks** (owner: "match with weather… idk", to settle): e.g. rainy-season reading lists,
  holiday or exam-season picks shown on the home page. Owner to confirm what is meant.
- [ ] Marketing reports: which offer sold what (so the owner sees what works).

### 4.2 Business intelligence dashboard (admin)
Make the admin dashboard look and work like a real bookstore company's, as good as or better than the owner's
sample https://tour-trip-frontend.vercel.app/admin (screenshot 2026-10-31):
- [ ] KPI cards with a small trend line, "% vs previous 30 days" (green up / red down) and a "View …" link: books,
  orders, customers, income.
- [ ] Income report: this period vs the previous one (solid vs dashed line), switch Income / Orders.
- [ ] Orders by status as a donut with the total in the middle.
- [ ] Side menu in groups (Overview, Catalogue, Operations, Insights), "Search or jump to… Ctrl K", notification
  bell with count, badge counts (e.g. pending orders).
- [ ] Bookstore extras to beat the sample: best sellers, slow movers / dead stock, stock value, category and author
  sales, repeat-customer rate, average order value, coupon and offer results, sales by province.
  (Part of this exists today: `/admin` dashboard, Reports, sales chart; the work is the redesign and the extra numbers.)

More wishes may come; add them here.
