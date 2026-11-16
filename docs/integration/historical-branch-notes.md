# Historical branch worklog additions

These notes describe their original branch state, not current deployment status. Current checks and decisions are in docs/WORKLOG.md and consolidation.md.

## origin/docs/admin-dashboard-plan

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

- [x] Admin dashboard plan (2026-10-12, branch `docs/admin-dashboard-plan`): architectural plan for ROADMAP Phase 3 admin dashboard in `docs/ADMIN_DASHBOARD_PLAN.md`. Detailed what already exists (routes, services, models, migrations), first slice design (dashboard numbers with previous-period comparisons, PostgreSQL UTC vs Asia/Phnom_Penh handling, cash basis gross/refunds/net revenue, dual USD/KHR, proposed indexes, free-tier Render/Neon caching, verification tests), 7 subsequent PR-sized slices (order workflow, stock movement history, catalog CSV import/export, customer directory & activity, staff permissions & RBAC, strict delete security with 2FA + soft delete restore, real-time Telegram operational notifications), 4 open questions with recommendations for owner, and do-not-break invariants. No application code changed.

## origin/docs/automatic-actions-audit

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

- [x] Automatic actions audit (2026-10-12, branch `docs/automatic-actions-audit`): read-only audit of automatic actions, schedule reality on Render free tier, event triggers, background queue behaviors, and PostgreSQL triggers/functions across the codebase. Documented in `docs/AUTOMATIC_ACTIONS_AUDIT.md`. Found 11 existing automatic actions, 0 PostgreSQL triggers/functions, and verified that the Laravel scheduler never runs on the live Render free tier. Documented recommendations across money, security, data hygiene, and convenience priorities.

## origin/docs/handoff-notes

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.


## 2026-11-03: Security review since 2026-10-15 (`fix/demo-account-limits`)

- Scope: ~10k lines in app/routes/config since the 2026-10-15 review (KHQR, Telegram sign-in and phone codes, offers,
  collections, uploads/R2, CSV import/export, reports, notifications, sessions).
- OK: all 211 routes audited from `route:list` (staff ability + `EnsureStaffTwoFactorEnabled` on every staff route,
  admin ability on admin actions, customer ability on customer routes, throttles on public writes); per-customer
  scoping (payments, credits, stock alerts, data export, sessions, notifications); store-credit codes bound to their
  customer (tested); KHQR simulate test-mode only, Bakong amount/currency match, md5 of our own QR; offer_id is only an
  expectation check; upload validation (ext, real MIME, getimagesize, size/dimensions, UUID names); `url:http,https`
  on cover/photo URLs; CSV formula escaping on all exports; raw SQL only with bindings, search capped at 8 words/200
  chars; no mass assignment of whole requests; no secrets; CORS patterns anchored; frontend raw HTML only for QR SVGs.
- Fixed (demo account): `not.demo` on POST/PATCH/DELETE reviews and POST /auth/telegram/order-updates; `demo:reset`
  clears `telegram_chat_id`/`telegram_order_updates` and removes visitor addresses (keeps `DemoSeeder::ADDRESS_LINE1`
  as default, re-creates it if deleted; orders keep their address snapshot). Owner chose "block reviews" and "remove
  visitor addresses". 4 new tests (red on old code), 765 pass, pint clean; OpenAPI + README updated.

## 2026-11-03: Wishlist price-drop emails (`feature/price-drop-alerts`, backend + frontend)

- Owner choices: opt-in (new `customers.price_drop_alerts`, default false), email only, drop >= 5%, max one per
  book per customer per 7 days.
- `BookVariant::booted` `updated` hook (after commit) → `PriceDropAlerts::priceChanged`: covers single edit, bulk
  edit and CSV import. Recipients: "want_to_read" wishlist, switch on, active, confirmed email, not the demo
  account; book visible, format active. Rows go to the marketing outbox (`kind = price_drop`, daily cap, retries).
- Outbox re-checks before sending (switch still on, book still on the list, price not back up) and cancels
  otherwise. `PriceDropNotification` en/km with old → new price, book link and how to turn it off.
- Demo reset turns the switch off. OpenAPI + docs/API.md + README updated. 9 new tests (CSV path included);
  774 pass, pint clean. Found my own wrong `$ref` in the spec via the frontend type generator (the spec tests
  don't resolve refs); fixed.
- Runtime: local API + preview: switch on (toast, saved), wishlist add, staff price cut 20.50 → 16.40,
  outbox row, `marketing:send` → mail log shows subject, "$20.50 → $16.40", links. Test data restored.

## 2026-11-03: Excel report download (`feature/excel-sales-export`, backend + frontend)

- Owner: OpenSpout, all sheets. `ReportsWorkbook` writes 7 sheets from `ReportsService` (same numbers as the JSON
  reports) row by row into a temp file; `GET /staff/reports/export` (admin, 10/min) sends it and deletes it.
- Text always `StringCell` (OpenSpout's `Cell::fromValue` turns "=..." into a formula; a mutation test proved the
  check catches it; note OpenSpout's *reader* also reports "=..." text as formulas, so the test reads the XML).
- Money as numbers with `"$"#,##0.00` / `#,##0" ៛"`; title + header rows frozen on list sheets.
- Dockerfile: `zip` extension. Local PHP is 8.3 (project needs 8.4): `composer require` used
  `--ignore-platform-req=php`; OpenSpout files lint fine on 8.3 and tests pass. CI and Docker use 8.4.
- 3 backend tests (777 pass, pint clean); 2 frontend tests (526 pass), lint, typecheck, build. Browser: button
  downloads `bookly-report-2026-10-01-to-2026-11-03.xlsx`, opened with openpyxl (7 sheets, numbers right);
  axe 0, no overflow at 1280/390 light/dark.

## 2026-11-03: Order lines in a fixed order (`fix/invoice-item-order`)

- CI (push run of the Excel branch) failed `OrderDocumentsTest` once: invoice line 0 was EMMA-EB, not EMMA-PB.
  `Order::items()` had no ORDER BY; Postgres may return rows out of insertion order (reused free space). Could not
  reproduce locally (15/15 green, and a forced-update test also passed), so no new test; fix is `->orderBy('id')`
  on the relation. Checked `sum`, `count` and `withSum` still work on Postgres. 774 pass, pint clean.

## 2026-11-03: Sign-in timing fix (`fix/sign-in-timing`)

- Review measured (local): login 0.22 s known vs 0.03 s unknown email; forgot-password 0.23-0.50 s vs 0.03 s.
- `App\Support\TimingSafe::check()` always does one Hash::check (cached dummy hash, remade if cost changes):
  customer + staff login. After: ~0.22 s for both. `TimingSafe::afterResponse()` (app terminating +
  ignore_user_abort) for customer + staff forgot-password OTP. Locally `php artisan serve` can't finish the request
  early, so forgot-password timing is only equal on FrankenPHP (fastcgi_finish_request; v1.12.7 fixed an abort bug,
  hence ignore_user_abort). Not verified on the live server: owner live check listed.
- Reset-password with a wrong code still answers faster when no code exists; it needs a code first, so left as is.
- 4 new tests (2 fail on the old code); 781 pass, pint clean.

## 2026-11-04: customer sessions, new-device email, wishlist saved price (backend)

- `feature/customer-sessions`: IssuesTokens names every token with DeviceDetector + saves ip for customers too;
  GET /me/sessions, DELETE /me/sessions/{id} (422 for this device), POST /me/sessions/sign-out-others; demo sees
  only its own session and both writes are `not.demo`. 5 tests; 786 pass.
- `feature/new-device-email`: table customer_known_devices (unique customer+device, 30 newest kept);
  KnownDevices::signedIn from IssuesTokens (customers): first device silent, new device → AccountSecurityNotice
  `new_device` (device, Phnom Penh time, IP). Skipped for demo and for Telegram/phone-code sign-ins (the bot emails).
  km mail text = English, TODO(antigravity). 5 tests; 786 pass; merges cleanly with customer-sessions (169 auth
  tests green on the merge).
- `feature/wishlist-price-badge`: wishlists.saved_price_usd (lowest active price at first save; kept on re-save/
  status change); GET /wishlist → wishlist_saved_price_usd; wishlist fields added to the OpenAPI spec. 2 tests; 783.

## 2026-11-04: Block leaked passwords (`feature/block-leaked-passwords`)

- `App\Support\PasswordRules::new()` = min 8, letters, numbers, uncompromised(); used by customer register/reset/
  change, staff reset, admin:create. NotPwnedVerifier timeout 30 → 5 s (AppServiceProvider extend); fails open.
- lang/en/validation.php adds only password.uncompromised (Laravel merges with its own file; checked). km: English +
  TODO(antigravity).
- TestCase binds a stand-in verifier (tests never call the internet; their simple passwords are in real leak lists);
  LeakedPasswordTest drives the real verifier against Http::fake (refused/accepted, only a 5-char hash prefix sent,
  outage → allowed, km). 3 fail without the rule. 796 pass, pint clean.
- api.pwnedpasswords.com is blocked from this sandbox, so not checked live here; frontend e2e sign-up now uses a fresh
  password (merge that first). Local e2e journey "new customer" passes against this branch.

## 2026-11-04: Delivery map pin (`feature/delivery-map-pin`, backend + frontend)

- Owner choices: no shop map yet; optional pin on the delivery address; must stay free.
- Backend: migration adds customer_addresses.latitude/longitude and orders.shipping_latitude/shipping_longitude
  (decimal 9,6, nullable). Address create/update: both or neither, numeric, inside Cambodia's box (lat 9.5–15,
  lng 102–108); null removes. Checkout copies the pin onto the order; Order/OrderDocument resources return it in
  shipping_address; OpsAlerts new-order message adds a free google.com/maps link. OpenAPI + API.md updated.
  DeliveryMapPinTest (4); 800 pass, pint clean.
- Frontend: Maps JS loads only when the picker opens (restricted to Cambodia, fixed centre pin, no Marker/Places);
  "Use my location"; order pages (customer + admin) show "Open in Google Maps" (no key needed). Hidden without
  VITE_GOOGLE_MAPS_KEY. CSP opened for maps.googleapis.com / maps.gstatic.com / fonts.
- Cost guard (owner, see OWNER_TODO): key restricted to the site + Maps JS only, 300 map loads/day quota, $1 budget alert.

## 2026-11-05: Cart reminder emails + gift note (backend)

- Owner choices: reminder on by default, 24 h, max 1 a week; free gift note (no wrapping fee); 4 new columns OK.
- `feature/cart-reminder`: customers.cart_reminders (default true), carts.reminder_sent_at. CartItem touches its cart
  (removal = activity). CartReminders::queueDue (cmd `cart:remind`, cron `5 * * * *`, in MaintenanceTasks): carts
  with items, last change (greatest of cart/lines updated_at) 24 h–7 d ago, verified active non-demo customer,
  reminder never sent or >7 d ago and before the last change → MarketingOutbox kind `cart_reminder`
  (reminder_sent_at set via DB::table so updated_at isn't bumped). staleReason at send: turned off / cart empty /
  cart changed since queued. CartReminderNotification lists 3 titles + "N more", button → /cart, stop link
  (HMAC of customer id with app key) → frontend /email/cart-reminders/stop + RFC 8058 List-Unsubscribe.
  Public POST /cart-reminders/stop and /stop/one-click (throttle 20/min). PATCH /me cart_reminders. 8 tests; 808 pass.
  Cron is single-digit minute: schedule:list pads "15" and broke the DueMaintenance listing test.
- `feature/gift-note`: orders.is_gift (default false), gift_message (200). Checkout is_gift?, gift_message? (kept
  only with is_gift, trimmed, empty → null); Order/OrderDocument resources; Telegram new-order alert "🎁 Gift" line.
  3 tests; 803 pass. Both branches merge cleanly (47 Marketing+Shopping tests green on the merge).
- km mail/messages: English + TODO(antigravity).

## 2026-11-05: Phase 0 security review (read-only, main of both repos)

Method: /security-review rules (high-confidence, real attack paths) across the whole code, not a diff.
Checked, all OK: raw SQL (every raw query bound), customer data scoping (orders, addresses, returns, reviews,
payments, cart, stock alerts, sessions all via the signed-in customer), checkout address ownership, KHQR simulate
(404 outside test mode), 125 staff routes (all auth:sanctum + staff ability + 2FA; admin routes ability:admin),
Telegram webhook (hash_equals secret), media upload (size, extension, real MIME, getimagesize, UUID name),
hidden password/2FA fields, no mass assignment from request->all(), CORS from env without credentials, strict CSP
(no unsafe-inline/eval scripts), React only uses dangerouslySetInnerHTML for locally generated QR SVGs,
composer audit + npm audit (prod): 0 advisories.
No High/Medium findings. Low/hardening notes for the owner: customer token in localStorage (CSP mitigates);
cart-reminder stop link has no expiry (only turns emails off); confirm APP_DEBUG=false on Render (not reachable here).

## 2026-11-05: Phase 1 categories + filters (`feature/category-tree`, both repos)

- Backend: categories.parent_id/sort_order/icon; books.age_min/age_max/school_grade/level. StarterCategories (12
  top-level, ~65 children) installed by migration outside tests; reuses existing slugs (keeps names, sets parent if
  none, restores a trashed one), never removes. Tree endpoint ?tree=1 (non-empty only, total_books unique per book).
  Staff: parent must be top-level, category with children stays top-level and can't be deleted (409). Books filters:
  category includes children, category_ids[], formats[], age, school_grade, level, has_ebook; sorts best_selling
  (90 days, non-cancelled), most_reviewed; facets=1 (+ with_age). DemoSeeder firstOrCreate by slug. 8 tests; 819 pass.
- Frontend: tree in the filter panel (open branch, own totals; facet hiding only before a category is chosen),
  has-ebook switch, age/grade/level groups only when used, new sorts, chips; admin tree list + parent/order;
  book form age/grade/level. 556 tests, build; browser en/km light/dark 1280/390 axe 0, CSP 0.

## origin/docs/overnight-batch

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

- [x] Stock history and restock entries (2026-10-16, branch `feature/stock-history-restock`, commit `edc8de1`): added `inventory_movements` ledger table tracking manual restocks, order deductions, and returns with running balance and admin-restricted unit costs. Added endpoints `GET /staff/stock`, `POST /staff/stock/restock`, `GET /staff/stock/{book}/movements`. Tests: `StockManagementTest` (7 tests). Proved failing first: yes. Verified: 457 tests (2788 assertions), Pint clean, CI run 38781501413 green. What was not verified: automated physical barcode scanner hardware integration.
- [x] Refund records and store credit coupons (2026-10-16, branch `feature/refund-records`, commit `8766e37`): added `refund_records` table and customer-bound `coupons` store credit support. Added endpoints `POST /staff/returns/{id}/refund`, `GET /customer/store-credits`, `GET /staff/orders/{id}/refunds`. Tests: `RefundRecordTest` (5 tests). Proved failing first: yes. Verified: 462 tests (2824 assertions), Pint clean, CI run 38787355711 green. What was not verified: automated refund reversal webhooks with external payment gateways.
- [x] Customer notes, tags, admin-only spend, resend verification (2026-10-16, branch `feature/customer-notes-tags`, commit `ffb701c`): added `customer_notes`, `customer_tags`, `customer_tag_user` pivot table. Restricted `lifetime_spent` to admins. Added endpoints for notes/tags CRUD and `POST /staff/customers/{id}/resend-verification`. Tests: `CustomerNotesAndTagsTest` (6 tests). Proved failing first: yes. Verified: 468 tests (2865 assertions), Pint clean, CI run 38791250933 green. What was not verified: bulk export of customer CRM tags to external email providers.
- [x] Book status draft/scheduled/live, bulk actions, CSV import/export (2026-10-16, branch `feature/book-publishing-bulk-csv`, commit `8547bd8`): added `publishing_status` and `published_at` to `books`. Added endpoints `POST /admin/books/bulk-status`, `GET /staff/books/export-csv`, `POST /admin/books/import-csv/preview`, `POST /admin/books/import-csv/confirm`. Tests: `BookPublishingAndBulkCsvTest` (6 tests). Proved failing first: yes. Verified: 474 tests (2919 assertions), Pint clean, CI run 38797002359 green. What was not verified: asynchronous background queue worker for CSV imports exceeding 10,000 rows (capped at 500 rows per HTTP request).
- [x] Khmer customer emails and API localization (2026-10-16, branch `feature/khmer-emails-api`, commit `eed07b1`): added `locale` column (`en` or `km`) to `users`, created Khmer translations for all customer email templates, added `SetApiLocale` middleware for `Accept-Language` header handling on customer and public routes. Tests: `KhmerEmailAndApiLocalizationTest` (5 tests). Proved failing first: yes. Verified: 479 tests (2955 assertions), Pint clean, CI run 38801068980 green. What was not verified: audio/TTS Khmer reading of customer notification emails.
- [x] Back-in-stock alerts, recently viewed shelf, also-bought, and capped marketing outbox (2026-10-16, branch `feature/back-in-stock-also-bought`, commit `c5baa3e`): added `stock_alerts` and `marketing_outbox` tables, `POST /stock-alerts`, `GET /books/{id}/also-bought`, `GET /books?ids=...`, artisan `marketing:send-outbox` with daily limit. Restock automatically queues alert notifications. Tests: `BackInStockAndMarketingOutboxTest` (6 tests). Proved failing first: yes. Verified: 485 tests (3012 assertions), Pint clean, CI run 38806032521 green. What was not verified: integration with third-party bulk SMS gateways for back-in-stock alerts.
- [x] Khmer trigram search with pg_trgm and ZWSP stripping (2026-10-16, branch `feature/khmer-search`, commit `fc767c0`): added `pg_trgm` extension and GIN trigram indexes on `books.title` and `books.title_khmer`. Added zero-width-space stripping and hybrid trigram/tsvector query routing in `Book::scopeSearch`. Tests: `KhmerSearchTest` (5 tests). Proved failing first: yes. Verified: 490 tests (3058 assertions), Pint clean, CI run 38809792242 green. What was not verified: ICU dictionary-based morphological segmenter in PostgreSQL.
- [x] Newsletter subscription, confirmation, unsubscribe, and admin campaigns (2026-10-16, branch `feature/newsletter`, commit `f1d506d`): added `newsletter_subscribers` and `newsletter_campaigns` tables with RFC 8058 one-click unsubscribe support. Added endpoints `POST /newsletter/subscribe`, `GET /newsletter/confirm/{token}`, `GET/POST /newsletter/unsubscribe/{token}`, `GET/POST /admin/newsletter/campaigns`, `POST /admin/newsletter/campaigns/{id}/send`. Tests: `NewsletterTest` (10 tests). Proved failing first: yes. Verified: 500 tests (3140 assertions), Pint clean, CI run 38812962321 green. What was not verified: automated bounce and spam complaint webhooks from external ESPs.
- [x] Security leftovers: health endpoint separation and passwordless closure (2026-10-16, branch `fix/security-leftovers`, commits `fbf80c0`, `cacc0c2`): restricted `GET /health` to status-only for public, moved full diagnostic system health check to admin-only `GET /admin/health`, added SRI integrity hashes to Swagger UI CDN assets, allowed passwordless/Telegram customer account closure with fresh token check. Tests: `SecurityLeftoversTest` (5 tests). Proved failing first: yes. Verified: 505 tests (3192 assertions), Pint clean, CI run 38818126862 green. What was not verified: hardware security module (HSM) key storage for encryption keys.
- [x] Strict delete verification and 30-day trash retention for books (2026-10-16, branch `feature/strict-delete-trash`, commit `5230e11`): added SoftDeletes `deleted_at` to `books`. Implemented title match, admin password verification, and TOTP 2FA code verification with 5-attempt lockout on `DELETE /admin/books/{id}`. Added `POST /admin/books/{id}/restore` with 30-day window check and `php artisan books:purge-trash` cleanup command. Tests: `StrictDeleteTest` (7 tests). Proved failing first: yes. Verified: 512 tests (3245 assertions), Pint clean, CI run 38820479741 green. What was not verified: multi-region cross-cloud cold storage replica archival prior to permanent purge.
- [x] Staff granular permissions, active sessions, and login history (2026-10-16, branch `feature/staff-permissions-sessions`, commit `4e089f8`): added `permissions` jsonb column to `users`, `login_histories` audit table, and IP/user agent metadata to Sanctum access tokens. Added endpoints `GET /staff/security/sessions`, `DELETE /staff/security/sessions/{id}`, `POST /staff/security/sessions/revoke-others`, `POST /staff/security/sessions/revoke-all`, `GET /staff/members/{id}/login-history`, `PATCH /staff/members/{id}/permissions`. Added granular permission enforcement on staff order, inventory, customer CRM, and returns endpoints. Tests: `StaffPermissionsAndSessionsTest` (14 tests). Proved failing first: yes. Verified: 526 tests (3389 assertions), Pint clean, CI run 38840141886 green. What was not verified: IP geolocation lookup on staff session records (IP stored raw without third-party MaxMind GeoIP dependency).


## origin/docs/rate-limit-audit

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

- [x] Rate limit audit (2026-10-12, branch `docs/rate-limit-audit`): read-only security audit of rate-limiting configurations, middleware, and controllers across the API (`/api/v1/`). Documented in `docs/RATE_LIMITS.md` covering 50+ endpoints, risk-ordered abuse gaps (money/inventory order spam, unthrottled Telegram status polling, heavy tsvector search scraping, coupon dictionary brute-force, admin dashboard date-range aggregation load), proxy considerations for Render (`TRUSTED_PROXIES=REMOTE_ADDR`), pseudocode limiter definitions, and 4 architectural questions for the owner. No application code changed.

## origin/feature/postman-collection

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

- [x] Postman collection `docs/postman/bookly.postman_collection.json`: 28 requests in 4 folders (public, customer auth, shopping incl. cash-on-delivery checkout with `Idempotency-Key: {{$guid}}`, staff with 2FA). Collection variables `baseUrl`, demo logins; login/address/order requests store `token`, `addressId`, `orderId` automatically. Checked with Newman against a local server with demo data: 20 requests, 16 assertions, 0 failures.


## origin/feature/search-authors-series

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

- [x] Search (owner-approved, found while building the frontend): `q` now also matches author, series and category names, and every word matches as a prefix (`sherlock holm`, `baskerv`) for live search. Input is reduced to letters/digits/marks before building the tsquery, so operators cannot break it. Relevance: title match, then author, series, category, then text rank. New migration `2026_10_07_100001_add_name_search_indexes` (GIN expression indexes; existing migrations untouched). Tests: author/series/category/prefix/operator cases and title-above-author ranking (202 total).

## origin/feature/staging-setup-kit

Historical branch notes, retained as originally written; deployment and merge claims are not reverified.

## Staging setup kit (branch `feature/staging-setup-kit`; frontend `feature/staging-banner`)
- [x] Owner chose: a `staging` branch you merge into first (live unchanged), demo data only. `docs/STAGING.md`: step-by-step for the `staging` branches, a separate Neon project `bookly-staging` (empty, own free compute, can't touch live data), Render service `bookly-api-staging` (same production settings; emails to the log only, `LOG_LEVEL=debug` so they show; Turnstile test keys; Sentry labelled `staging`), the variables never to copy (Telegram bot token would move the live bot; ops alerts; mail; R2; social sign-in), demo data from your computer, Vercel Preview variables (every preview now uses staging), the everyday flow, keeping staging close to `main`, and how to start over.
- [x] Render's free hours are shared by all free services and the live API uses almost all of them: the guide says to suspend staging when not testing (couldn't open Render's docs from here to quote the exact limit; the owner checks the usage page).
- [x] CI also runs on pushes to `staging` (`tests.yml`), so Render's "wait for CI" works there.
- [x] Checked: the guide's settings against the code (CORS variable names, Telegram webhook uses `APP_URL`, DemoSeeder random staff passwords with `APP_ENV=production`, `pg_trgm` recreated by its migration); mail log with `LOG_LEVEL=info` drops the email, with `debug` the code shows (tested locally).

