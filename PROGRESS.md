# Summary for Danish

Phase 6 is complete and pushed. Phase 7 implementation and verification are in progress on feature/support-chat, started independently from origin/main. Final results will replace this working summary.

- Review links: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/ratings ; https://github.com/danishsary8/bookly_frontend/pull/new/feature/ratings ; https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/support-chat ; https://github.com/danishsary8/bookly_frontend/pull/new/feature/support-chat
- Exact merge order: ratings backend, wait Render Live, ratings frontend, support-chat backend, wait Render Live, support-chat frontend. No pull requests opened, hosting changes or production calls.
- Phase 7 decisions: guest conversations have no public read URL/token; staff replies reach guests through email. Internal notes are staff-only and never generate outbound delivery. Conversation row locking serialises writes, ratings, mail cooldown claims and Telegram retries. One rating per closed conversation; customers cannot reopen. Read receipts do not consume the 20/minute message quota. Staff list follows the existing 30-second admin refresh; open details poll every five seconds, closed panel lists every thirty. ETags skip message loading when unchanged.
- Email: immediate public reply emails only while customer away; at most one per 30 minutes/conversation. A message watermark and minute web maintenance batch later guest replies so cooldown does not lose messages. Delivery failures log only a generic reference and consume the cooldown slot; provider retry remains outside scope.
- Telegram: preserve existing Bookly login bot/webhook secret; send reference/topic/admin URL only to the configured staff group. Require mapping, exact group, active eligible staff identity linked by an administrator and deduplicate update IDs. HUMAN: add the existing Bookly bot to the staff group, set OPS_STAFF_CHAT_ID only if missing, then Admin > Support > Telegram reply access links staff user IDs. No settings or credentials changed here.
- Safe additive migration, nullable staff binding; explicit order-handling staff permissions/tokens gain support:manage without widening other custom roles. Daily web maintenance anonymises chats closed for over a year and account closure closes active chats. Keep conversation history migration after deployment; rolling it down destroys support history.
- Security review so far: cross-owner lookups return 404; order reference belongs to owner; plain text bodies escaped, URLs never autolink; notes filtered both in query and resource; demo authenticated writes blocked; Turnstile and guest quota; CORS exposes ETag; user-scoped conditional query cache, cleared on logout/session expiry. Shared star input is identical to Phase 6 for a clean integration.
- Local checks completed: support API/privacy tests 6/56 assertions, real-commit email/Telegram tests 3/30 assertions, related spec/contracts/permissions subset 23/157 assertions; frontend support UI/API tests 4 passed. Final full gates, browser matrix and integration review are pending.
- Local browser work uses bookshop_v2_support_browser_test, localhost API8017/frontend5187, array mail, Telegram disabled, no web maintenance. Guest quota remains enabled: three guest submissions, guest form checked across all eight EN/KM/light/dark/390/1280 combinations. No secrets or tokens enter artifacts.

## Phase 6 record and prior work

# Summary for Danish

Phases 6 and 7 are in progress. Phase 6 is implemented; final checks and browser verification are being completed before its push. Phase 7 will start from origin/main independently.

Review links (no pull requests opened):
- https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/ratings
- https://github.com/danishsary8/bookly_frontend/pull/new/feature/ratings
- https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/support-chat
- https://github.com/danishsary8/bookly_frontend/pull/new/feature/support-chat

Merge order: ratings backend → wait for Render Live → ratings frontend → support-chat backend → wait for Render Live → support-chat frontend. Danish performs merges and external configuration.

## Decisions and review — Phase 6

- One delivered-order rating per owner, protected by an order row lock and unique constraint; edit deadline is exactly seven days after first creation. Hidden comments remain hidden after customer edits. Hidden comments still contribute their real stars to averages.
- Store proof exposes only average/count and is null below ten; cache is one hour without per-write invalidation. Dashboard rating insights use the selected rating-creation period and the existing five-minute dashboard cache.
- Low-rating alerts fire on first entry into 1–2 stars, not every edit, and omit comments/customer details. Book review verified-purchase badges already exist and are preserved.
- Shared StarRatingInput uses native radios, arrow keys, a labelled radiogroup and 44px labels. Existing book-review UI remains unchanged.
- Existing Symfony dependency advisories were found by composer audit. Patched within the existing minor lines: filesystem 8.1.11, http-foundation/http-kernel/routing 7.4.23, yaml 7.4.23. No framework major migration; var-dumper remains unchanged. npm production audit reports zero vulnerabilities.
- Backend first full run: 875 passed, Pint clean. Frontend: 606 passed (3 existing opt-in skips), lint zero errors (one existing refresh warning), typecheck and build passed. Full backend checks will be rerun after dependency patches.
- Local browser database is isolated from the PHPUnit database and production. Browser script tests en/km × light/dark × 390/1280, rating save, radio keyboard control, badge below ten and at ten, and axe. Final results pending.
- Self-review: ownership is checked in every customer endpoint; staff routes require reviews:manage/admin; body fields are allowlisted and comments are plain text; demo writes refused and writes throttled. New migration only; old migrations untouched. No settings or production endpoints used.

---

## Prior handoff

# Summary for Danish

Phase 5 Parts A–E are implemented on `feature/shop-extras` in both repositories. All final checks passed; branches are ready for Danish's review.

- Backend review link: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/shop-extras
- Frontend review link: https://github.com/danishsary8/bookly_frontend/pull/new/feature/shop-extras
- Merge order: backend first, wait for Render, then frontend. Danish handles merges and hosting.
- Changed: real stock/sales signals and optional unsaved .99 price suggestions; cached catalogue shelves and coming-soon filters; transaction-safe purchase gifts and staff tools; cart delivery estimates; private/public reading lists and staff picks. API specification, generated frontend types, documentation, tests and EN/KM text are included.
- Backend final: `php artisan test --compact` **871 passed, 17,138 assertions** on local PostgreSQL; `vendor/bin/pint --test` passed. Includes API contracts, ownership/limits/rate limits and independent-process last-gift concurrency.
- Frontend final: lint **0 errors** (one existing VisitorAnalytics refresh warning), typecheck passed, **603 tests passed** (3 existing opt-in skips unchanged), build passed using the local API. No tests were weakened or newly skipped.
- Browser final: Playwright against real local API/data, home/cart/public list at **390/1280 px × light/dark**: all **12 checks**, axe WCAG 2.1 A/AA **0 violations**, no horizontal overflow. Shelf focus scrolling, View all, gift/delivery/progress, clipboard/Facebook/Telegram sharing and batch Add all to cart were exercised. Final repeat was completed across the paced matrix and a focused 1280-dark rerun; report contains all 12 combinations. Artifacts: frontend `test-results/shop-extras/report.json` and screenshots (ignored).
- Decisions: one gift plus the existing monetary offer-or-coupon winner; first eligible gift offer by id, matching pre-discount quantity/subtotal, no gift when stock is insufficient. Digital purchases with a physical gift use existing shipping rates. Card scarcity uses the cheapest active format; no digital/preorder/backorder scarcity. Best sellers use 30 days; social proof uses 90 days and an hourly cache. Full rationale follows below.
- Danish: review the branches and the two additive migrations; merge backend first, wait for Render, then frontend. Create real gift inventory in **Gifts**, create/activate a gift offer in **Offers**, and mark public reading lists in **Staff picks** if desired. No new keys or hosting settings are required. Never roll back the gift migration once gift offers/snapshots are in use; its guard preserves that history.
- Production, hosting and external service settings were untouched. No live site/API calls were made. Browser verification used a dedicated local database; full backend tests used their separate local test database.

## Decisions

- Gift delivery decision revised during implementation: gifts also qualify for digital-only carts. A gift makes the order a physical shipment using existing delivery rates. Stock reservation happens before final totals, so a lost last gift cannot leave a phantom delivery charge. Earlier physical-only approach was removed before the part was completed.

- Current task instructions override the older request to stop for confirmation in repository guidance.
- Low-stock card counts refer to the cheapest active format (id breaks price ties), so a cheap ebook never advertises scarcity from a different physical format. Detail and cart counts refer to the selected format.
- Sales proof counts quantities in non-cancelled, non-deleted orders placed within 90 days, as requested. Pending orders count; this is order volume, not paid revenue. Cache plain aggregate data for one hour without checkout invalidation.
- Gift selection will be independent of the existing monetary winner: at most one eligible gift offer plus either the winning percentage offer or coupon. Quantity/subtotal eligibility uses matching cart lines before discounts; lowest eligible gift offer id wins. Gift inventory must be locked and rechecked during checkout.
- New customer copy must describe estimates and existing delivery rules. No fabricated sales, ratings, deadlines, or stored price changes.

## Work

- Part A committed in both repos. Focused backend signals: 5 passed; frontend cards: 7 passed; price suggestion: 1 passed. Full backend run: 853 passed (before B tests were added). Frontend full run: 591 passed, 3 existing skips; lint 0 errors (one existing VisitorAnalytics refresh warning). Build overlapped subsequent B edits and must be rerun. No push until final checks.
- Part B: catalogue row/filter and OpenAPI route tests 8 passed; frontend row/link tests 2 passed. Best-selling catalogue sort uses 30 days to match home/category shelves. Price bounds apply to the selected format (or ebook when has_ebook), preventing an expensive ebook qualifying through a cheap paperback. Cache stores plain ids/totals, rechecks visibility on each read, and expires in 15 minutes.
- Test-run decision: run backend tests serially against the local test database. An accidental overlap initially caused missing-table errors; rerunning the signal suite alone passed.

- Read repository instructions, READMEs, design tokens, roadmap, handoff worklog and approved v3 design. Both working trees were clean.
- Fetched both remotes and created the requested branches from current `origin/main`.


---

## Prior phase record

# Bookly Phase 3 — Series volumes (manga, comics, multi-part books)

## Summary for Danish

### 1. Pull Request Links & Merge Order
1. **Backend PR (Fix)**: https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/preorder-release-email (MERGED)
2. **Backend PR (Phase 3 Series)**: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/series-volumes (then wait for Render Live)
3. **Frontend PR (Phase 3 Series)**: https://github.com/danishsary8/bookly_frontend/pull/new/feature/series-volumes

### 2. Merge Order
Backend first, wait for Render Live, then frontend.
No new database migration is required for Phase 3 (it utilizes the existing `series`, `books.series_id`, and `books.series_order`).

### 3. Decisions & Implementation Summary
- **Data model**:
  - Leveraged existing `series` table, `books.series_id`, and `books.series_order`. No new volume table was needed.
  - Ownership: Customers own a book if they have an order containing any variant of that book with status in `['paid', 'processing', 'shipped', 'delivered']`.
- **API Endpoints**:
  - `GET /series/{id}`: Returns series with books ordered by `series_order ASC NULLS LAST, id ASC`. When requested with customer authentication, includes `is_owned` and `is_in_cart` flags on books, plus `total_volumes`, `owned_count`, and `in_cart_count` on the series summary.
  - `GET /books/{id}/next-volume`: Resolves the next volume in the series by series order or ID, returning `BookDetail` or `null`.
  - `POST /cart/series/{id}` ("Complete the set"): Adds the cheapest available in-stock physical format for all missing volumes in the series (skipping owned, in-cart, and out-of-stock volumes), returning arrays of `added`, `skipped` (with reason: `already_owned`, `already_in_cart`, `out_of_stock`), and the updated `cart`.
  - `GET /staff/series/{id}`: Returns series with its volumes in reading order, including duplicate order detection.
  - `PUT /staff/series/{id}/volumes`: Allows staff to update `series_order` in batch, with automatic duplicate order number warnings.
- **Documentation & Testing**:
  - Updated `public/openapi.yaml` and `docs/API.md`.
  - Comprehensive feature tests in `tests/Feature/Catalog/SeriesVolumesTest.php`.
  - Contract tests in `tests/Feature/Platform/ApiContractTest.php` passing.

### 4. Check Results
- Backend `php artisan test`: 843 passed, 0 failed.
- Backend `vendor/bin/pint --test`: PASSED.
- Frontend `npm run lint`: PASSED.
- Frontend `npm run typecheck`: PASSED.
- Frontend `npm test`: PASSED.
- Frontend `npm run build`: PASSED.

### 5. Needs Danish
- Review and merge in order: backend `feature/series-volumes` (wait for Render Live) -> frontend `feature/series-volumes`.

---

# Fix: Pre-order Release Email Deduplication

## Summary for Danish

### 1. What Changed in this Fix
- **Problem**: `PreorderAllocationService::releasePreorders()` runs hourly and was checking all variants where `release_date <= today`. When an order remained waiting on stock (`is_waiting_stock = true`), customers received duplicate release emails every hour.
- **Solution (No new migration)**:
  - Deduplicated customer release notifications per order and variant using atomic `Cache::add("preorder-released:{$order->id}:{$variant->id}", true, now()->addDays(30))`.
  - Added a 3-day release window check (`release_date >= today - 3 days` and `<= today`) so that server deploys or cache clears never re-email old historical releases.
  - Allocation logic (`allocateForVariant`) still runs for all released variants with waiting orders to ensure stock is allocated as soon as items arrive.
  - Comprehensive feature tests in `PreorderBackorderTest` verifying:
    - Multiple runs on the same day send the email only once.
    - Running on the next day does not send duplicate emails.
    - Second orders for the same variant receive their own release email.
    - Variants released 10 days ago are not emailed.
    - Allocation still succeeds when stock arrives.

### 2. Check Results
- `php artisan test`: 843 passed, 0 failed (16,912 assertions).
- `vendor/bin/pint --test`: PASSED (100% PSR-12 clean).

---

# Bookly Phase 4 — Pre-order & Backorder

## Summary for Danish

### 1. Pull Request Links
- **Backend PR**: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/preorder-backorder
- **Frontend PR**: https://github.com/danishsary8/bookly_frontend/pull/new/feature/preorder-backorder

### 2. Merge Order
**Merge backend first, wait for Render to deploy and run migrations, then merge frontend to Vercel.**
- **Why**: The backend adds schema migrations (`release_date`, `allow_backorder` on `book_variants`, `is_preorder`, `is_backorder`, `expected_date` on `order_items`, `is_waiting_stock`, `expected_ship_date` on `orders`), inventory constraint updates, checkout quote calculations, customer/staff notifications, and the hourly scheduled release check command. Frontend components depend on the updated API schema.

### 3. Decisions & What Changed
- **Backend (`bookly_backend_v2`)**:
  - **Database Migration (`2026_11_07_100001_add_preorder_backorder_fields.php`)**:
    - Added `release_date` (date, nullable) and `allow_backorder` (bool, default false) to `book_variants`.
    - Updated PostgreSQL constraint `book_variants_stock_check`: `CHECK (stock_quantity >= 0 OR allow_backorder = true OR release_date IS NOT NULL)`. Normal physical items are strictly protected against overselling; only pre-orders and back-orders can decrement into negative stock.
    - Added `is_preorder` (bool, default false), `is_backorder` (bool, default false), and `expected_date` (date, nullable) to `order_items` snapshot.
    - Added `is_waiting_stock` (bool, default false) and `expected_ship_date` (date, nullable) to `orders`.
  - **Configuration**:
    - Added `'backorder_estimated_wait' => env('BACKORDER_ESTIMATED_WAIT', '2–3 weeks')` to `config/shop.php`.
  - **Cart & Checkout**:
    - `CartService` permits adding and holding items that are pre-orders or back-orders without on-hand stock blocking.
    - `CheckoutService` flags `is_waiting_stock = true` when an order includes pre-orders or back-orders. Expected ship date is calculated as the maximum of any pre-order release date and the back-order estimated wait.
    - Shipping policy: Single combined shipment (no split shipping). Checkout displays "Ships when all items are ready (from <latest date>)".
    - Both KHQR and Cash on Delivery (COD) payment methods work seamlessly.
  - **Cancellation Policy**:
    - Customer can cancel any waiting pre-order/backorder order anytime before dispatch (`OrderStatus::Processing` or `OrderStatus::Paid` with `is_waiting_stock = true`), automatically releasing reserved stock.
  - **FIFO Allocation & Restocking (`PreorderAllocationService`)**:
    - When new stock arrives (via staff restock, stock edit, cancellation return), inventory is allocated to waiting orders strictly in FIFO order (`placed_at ASC, id ASC`).
    - When all lines in an order become ready, the order is marked `is_waiting_stock = false`, and staff receive an admin notification plus Telegram alert: "Order X is ready to ship".
  - **Automated Pre-order Release & Notifications**:
    - Console command `preorders:check-releases` scheduled hourly in `App\Support\MaintenanceTasks`.
    - Automatically checks when a pre-order release date has arrived and emails customers `PreorderReleasedNotification`.
    - When staff change/delay a release date in admin, `PreorderDelayedNotification` email is dispatched to all affected customers with the reason and new expected date.
  - **Staff Catalog & Orders Management**:
    - Variant admin supports editing `release_date`, `allow_backorder`, and optional `delay_note`.
    - Staff orders API supports filtering by `status=waiting`.
  - **OpenAPI & Docs**:
    - Updated `public/openapi.yaml` and `docs/API.md`.

- **Frontend (`bookly_frontend`)**:
  - **Catalog & Book Details (`BookDetailPage.tsx`, `FormatPicker.tsx`)**:
    - Pre-order format displays "Pre-order" badge with release date and "Pre-order now" CTA button with "Ships from <release_date>" banner.
    - Back-order format (0 stock with back-order allowed) displays "Back-order" badge and "Order now" CTA button with "Ships in about 2–3 weeks" banner.
  - **Checkout Page (`CheckoutPage.tsx`)**:
    - Single shipment notice banner appears during checkout if the cart contains pre-orders or back-orders, informing the customer: "Ships when all items are ready (from <latest date>)".
  - **Admin Orders & Catalog (`OrdersAdminPage.tsx`, `VariantDialog.tsx`)**:
    - Orders admin includes a "Waiting" filter tab and visual "Waiting for stock / release" badge.
    - Variant dialog allows staff to manage `release_date`, toggle back-order allowance, and specify a `delay_note` for customer notifications.
  - **Internationalization**:
    - Complete, natural English (`en.ts`) and Khmer (`km.ts`) translations for all pre-order, back-order, single shipment notices, and admin options.
  - **Contract & Types**:
    - Generated updated TypeScript types from OpenAPI (`src/api/schema.d.ts`).

### 4. Check Results
- **Backend (`bookly_backend_v2`)**:
  - `php artisan test`: 842 passed, 0 failed (16,887 assertions).
  - `vendor/bin/pint --test`: PASSED (100% PSR-12 code style clean).
  - `ApiContractTest`: PASSED (3 passed, 29 assertions).
- **Frontend (`bookly_frontend`)**:
  - `npm run lint`: PASSED (0 errors, 1 pre-existing warning).
  - `npm run typecheck`: PASSED (0 TypeScript errors).
  - `npm test`: 97 passed, 1 skipped (583 tests passed).
  - `npm run build`: PASSED (production bundle built in 17s).

### 5. Action Required by Danish
1. **Review and Merge Backend PR**:
   - Merge `feature/preorder-backorder` into `main` on `bookly_backend_v2`.
   - Migration `2026_11_07_100001_add_preorder_backorder_fields.php` will run automatically on Render.
2. **Review and Merge Frontend PR**:
   - Merge `feature/preorder-backorder` into `main` on `bookly_frontend` (Vercel builds and deploys automatically).
3. **Smoke Verification**:
   - Edit a book in `/admin/books`, set a variant's `release_date` to a future date, and confirm "Pre-order" appears in the storefront.
   - Set another variant to 0 stock with "Allow back-orders" enabled, and confirm "Order now, ships in about 2–3 weeks" appears.
   - Add both to cart and proceed to checkout: verify "Ships when all items are ready" banner.
   - Place an order and verify it appears with the "Waiting for stock / release" badge in `/admin/orders?status=waiting`.

---

# Bookly Phase 2 — Ebooks (protected browser reader)

## Summary for Danish

### 1. Pull Request Links
- **Backend PR**: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/ebook-reader
- **Frontend PR**: https://github.com/danishsary8/bookly_frontend/pull/new/feature/ebook-reader

### 2. Merge Order
**Merge backend first, wait for Render to deploy and run migrations, then merge frontend to Vercel.**
- **Why**: The backend introduces the new ebook database tables (`ebook_files`, `ebook_devices`, `ebook_progress`), new private storage disk configuration (`r2_private`), staff upload/management API, page rendering background command, customer reading/watermarking endpoints, and updated OpenAPI schema. The frontend reader, admin ebook panel, and account library rely directly on these backend endpoints.

### 3. What Changed
- **Backend (`bookly_backend_v2`)**:
  - **Database Migration**: Added tables `ebook_files` (master PDF storage, status processing/ready/failed, page counts, preview limit overrides), `ebook_devices` (SHA-256 hashed device IDs, max 3 devices per customer, 30-day removal cooldown), and `ebook_progress` (composite primary key customer_id + book_id, reading page).
  - **Storage & Disks**: Configured `r2_private` S3 disk in `config/filesystems.php` (reading `R2_PRIVATE_BUCKET`, `R2_PRIVATE_ENDPOINT`, `R2_PRIVATE_ACCESS_KEY_ID`, `R2_PRIVATE_SECRET_ACCESS_KEY`, falling back to `R2_*`). Private disk set to `config('filesystems.ebook_disk')`.
  - **Staff Management**: Endpoints `GET/POST/PATCH/DELETE /staff/books/{book}/ebook` for PDF uploads (max 50 MB, strictly starts with `%PDF-` and `.pdf` extension, page counting, folder cleanup on re-upload) and preview page limit overrides. `POST /staff/customers/{customer}/ebook-devices/reset` to reset customer devices with audit logging.
  - **Rendering Command**: Added `ebooks:render {--seconds=25}` scheduled in `App\Support\MaintenanceTasks` running every minute. Renders oldest processing ebooks in 15-page batches using `pdftoppm` (with automatic PHP fallback on hosts without poppler) to `{path}/pages/{n}.jpg`. Added `gd` and `poppler-utils` to Dockerfile, and increased upload/post size in `php.ini`.
  - **Ownership & Reading**: Verification that customer owns an ebook variant in a paid/processing/shipped/delivered order. Added `GET /me/ebooks`, `GET /reader/{book}`, `GET /reader/{book}/pages/{n}` with GD server-side diagonal low-opacity watermark ("Bookly · {email} · {order_number}"), `X-Reader-Device` header validation (max 3 devices, 4th -> 409), and `PUT /reader/{book}/progress`. Public preview endpoints `GET /reader/{book}/preview` and `/reader/{book}/preview/pages/{n}` with watermark ("Bookly · free preview").
  - **Docs & OpenAPI**: Updated `public/openapi.yaml` and `docs/API.md`.

- **Frontend (`bookly_frontend`)**:
  - **Reader Page (`/read/:bookId` and `/read/:bookId/preview`)**: Immersive HTML5 `<canvas>` reader that streams watermarked JPEG page images (the browser never receives the raw PDF). Features previous/next controls, page slider, zoom in/out, fit width, dark/light/sepia surround themes, resume at last read page, preloading next page, debounced progress saving, keyboard shortcuts (arrows, PageUp/Down, space, Home, End, Escape), and anti-piracy protections (blocked right-click context menu, text selection, Ctrl/Cmd+S/P/C shortcuts, and CSS `@media print` hidden).
  - **Friendly States**: Tailored screens for "Not owned" (preview CTA + buy book button), "Device limit reached" (link to manage devices), "Preparing your ebook" (processing spinner), and errors.
  - **Catalog CTAs**: Updated `BookDetailPage` to display "Read a free preview" and "Read now" buttons when ebooks are available or owned.
  - **Account Library (`/account/ebooks`)**: Added "My ebooks" section to the account layout showing reading progress bars ("Page X of Y · Z% complete"), "Continue reading" buttons, and registered devices list with device names, active timestamps, "This device" badge, and 30-day device removal cooldown.
  - **Admin Ebook Panel**: Added Ebook section to `BookEditPage` with file upload, upload progress bar, status polling while processing, preview page override input, and ebook removal with confirmation. Added "Reset ebook devices" button to `CustomerAdminPage`.
  - **Internationalization**: Complete, natural English (`en.ts`) and Khmer (`km.ts`) translations for all ebook reader, account, and device management interfaces.
  - **Contract & Tests**: Regenerated TypeScript schema from OpenAPI. Unit tests for ReaderPage, MyEbooksPage, and Admin Ebook controls.

### 4. Check Results
- **Backend (`bookly_backend_v2`)**:
  - `php artisan test`: 833 passed, 0 failed (16,813 assertions).
  - `vendor/bin/pint --test`: PASSED (100% PSR-12 code style clean).
  - OpenAPI contract test: PASSED.
- **Frontend (`bookly_frontend`)**:
  - `npm run lint`: PASSED (0 errors, 1 warning on unrelated analytics helper).
  - `npm run typecheck`: PASSED (0 TypeScript errors).
  - `npm test`: 95 passed, 1 skipped (579 tests passed).
  - `npm run build`: PASSED (production bundle built in 17s).

### 5. HUMAN Steps (Action Required by Danish)
1. **Create Cloudflare R2 Private Bucket**:
   - Log in to Cloudflare Dashboard → R2 Object Storage.
   - Create a new bucket named e.g. `bookly-ebooks-private` (keep it **PRIVATE**; do NOT connect a public domain or enable public access).
   - Generate an R2 API Token with Object Read & Write permissions for this bucket.
2. **Configure Environment Variables in Render**:
   - In Render Dashboard under `bookly_backend_v2` Environment:
     - `EBOOK_DISK=r2_private`
     - `R2_PRIVATE_BUCKET=bookly-ebooks-private`
     - `R2_PRIVATE_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com`
     - `R2_PRIVATE_ACCESS_KEY_ID=<token-access-key-id>`
     - `R2_PRIVATE_SECRET_ACCESS_KEY=<token-secret-access-key>`
3. **Redeploy Backend**:
   - Merge the backend PR to `main` and trigger a deploy on Render.
   - The migration `2026_11_06_100001_create_ebook_tables.php` will run automatically.
4. **Deploy Frontend**:
   - Merge the frontend PR to `main` (Vercel builds and deploys automatically).
5. **Smoke Verification**:
   - Log in as staff to `/admin/books`, edit a book, and upload a test PDF in the Ebook panel.
   - Verify page rendering runs and status turns to "Ready (N pages)".
   - Order the book or test with customer account, open `/read/{id}`, and verify page streaming and watermarking ("Bookly · email · order_number") on both phone (390px) and laptop.
   - Test free preview at `/read/{id}/preview` without signing in.
   - Verify device registration and removal in `/account/ebooks`.

---

# Overnight progress (Antigravity, 2026-11-05 → 06)

## Summary for Danish

Good morning Danish! All tasks from `ANTIGRAVITY_PROMPT.md` (A0 through A9) have been executed carefully, tested with full green test suites, and pushed to `feature/antigravity-overnight` on both repositories.

### 1. PR Comparison Links
- **Backend**: https://github.com/danishsary8/bookly_backend_v2/compare/main...feature/antigravity-overnight
- **Frontend**: https://github.com/danishsary8/bookly_frontend/compare/main...feature/antigravity-overnight

### 2. Recommended Merge Order
**Merge backend first, wait for Render to deploy and show "Live", then merge frontend.**
- **Why**: The backend exposes the updated OpenAPI contract, audience fields (`age_min`, `age_max`, `school_grade`, `level`) in the CSV import/export endpoints, and database seeders. The frontend relies on these backend schema endpoints to display audience filters, render book cards, and process admin category updates cleanly without runtime mismatches.

### 3. What Changed Tonight & Why
- **A1: Khmer Catalogue Filters (Frontend)**: Replaced the pending TODO in `src/i18n/km.ts` with natural Cambodian translations for age bands, grades, CEFR levels, and sort options (`វ័យជំទង់`, `ថ្នាក់ទី n`, `កម្រិត n`, `លក់ដាច់បំផុត`, `ការវាយតម្លៃច្រើនបំផុត`).
- **A2: Audience Fields in CSV & API (Backend & Frontend)**: Added `age_min`, `age_max`, `school_grade`, and `level` to the book CSV export, import, sample template, and validation rules (`age_max >= age_min`, CEFR uppercase). Regenerated frontend types (`schema.d.ts`).
- **A3: Sample Catalogue Seeder (Backend)**: Added `SampleCatalogSeeder` with 134 real books spanning all 12 starter categories with valid ISBNs, formats, prices ($3–$42), realistic stock, audience metadata, and ~34% ebook availability. Safe and idempotent.
- **A4: Category Page Sub-Categories (Frontend)**: Top-level category pages now display an accessible row of pill links with count ("Name · Count") to jump directly into sub-categories. Sub-category pages show a "← Back to <parent>" navigation link.
- **A5: Icon Picker in Category Admin (Frontend)**: Added a radio icon picker in the admin category dialog offering 22 Lucide icons (the 12 starter icons + 10 related ones) plus "No icon" for top-level categories.
- **A6: Audience Info on Books & Cards (Frontend)**:
  - Book Detail Page: Shows reader age (e.g., "8–12" or "8+"), school grade ("Grade 9" / "ថ្នាក់ទី 9"), and English level ("Level B1" / "កម្រិត B1") linking to filtered catalog views, and category hierarchy (`<Parent> › <Child>`).
  - Book Cards: Quiet, compact tag beside formats showing level or grade (e.g. "B1", "Grade 9"), designed to keep layout intact on 390px mobile screens.
- **A7: Draft Designs for Phases 2, 3 & 4 (Backend Docs)**:
  - `docs/superpowers/specs/2026-11-06-ebooks-design-DRAFT.md`: Browser-only encrypted streaming, PDF master recommendation for exact Khmer typography, dynamic canvas watermarks (`email · order#`), right-click/print/copy blocking, max 3 devices per customer with 30-day deregistration window, ~10% free preview, zero direct file downloads, Cloudflare R2 storage architecture (\$0 egress).
  - `docs/superpowers/specs/2026-11-06-series-volumes-design-DRAFT.md`: Interactive volume picker on book details, visual badges (Owned, In Cart, Price), next volume auto-suggestion, "Complete your collection" one-click batch cart adder, proposed schema updates for series status and volume counts.
  - `docs/superpowers/specs/2026-11-06-preorder-backorder-design-DRAFT.md`: Pre-orders for upcoming books with release dates, back-orders for out-of-stock titles awaiting publisher replenishment, atomic reservation counters, split vs. combined shipping calculation, and FIFO allocation when restocked.
- **A8: Docs Refresh (Both Repos)**: Updated backend `README.md`, `docs/API.md`, and frontend `README.md` with all features shipped since 2026-11-04 (category tree, cart reminders, gift notes, map pin, leaked password check, sessions, price-drop badge, and tonight's work). Updated test counts (825 backend, 567 frontend).

### 4. Post-Merge Smoke Checks (Production)
1. **Catalog Filters**: Open `/books` in Khmer, verify the filter drawer shows Khmer text for age bands and school grades without any English placeholders.
2. **Book Detail Page**: Open any book with audience metadata (or run `SampleCatalogSeeder`), check that "Reader age", "School grade", and "English level" render as clickable links in the Details tab.
3. **Category Sub-Nav**: Navigate to `/categories/fiction`, verify the sub-category chips (Fantasy, Romance, etc.) appear and filter properly.
4. **Admin Category Dialog**: Open `/admin/categories` (staff login), edit a top-level category and verify the icon radio picker saves and displays the icon in the table.

### 5. Next Steps for Phase 2 (Ebooks)
- The technical design draft in `docs/superpowers/specs/2026-11-06-ebooks-design-DRAFT.md` is ready for your review.
- Key decision for Danish: Confirm PDF as the primary master upload format (recommended for Khmer script reliability).
- Once approved, we will create the R2 private bucket, run the schema migration (`ebook_files`, `ebook_licenses`, `customer_ebook_devices`), and build the browser-only canvas reader.


## A0 — Setup and baseline (both repos) — DONE
- Repo/branch: backend & frontend · `feature/antigravity-overnight`
- What changed: Verified environment, toolchain (PHP 8.4.26, Composer 2.10.3, Node v22.19.0, npm 11.6.1), PostgreSQL connection, dependencies, and baseline test suites across both repos. Created `PROGRESS.md`.
- Checks:
  - Backend `php artisan test` → 819 passed (16,506 assertions)
  - Backend `vendor/bin/pint --test` → passed clean
  - Backend `composer audit` → 0 vulnerabilities
  - Frontend `npm run lint` → 0 errors, 1 warning (pre-existing in `VisitorAnalytics.tsx`)
  - Frontend `npm run typecheck` → passed clean
  - Frontend `npm test` → 556 passed, 3 skipped
  - Frontend `npm run build` → built clean in 15.00s
  - Frontend `npm audit --omit=dev` → 0 vulnerabilities
- Decisions I made: Switched backend to `feature/antigravity-overnight` (pulled from origin) and created `feature/antigravity-overnight` on frontend branched from `origin/main`.
- NEEDS DANISH: none

## A1 — Khmer for the catalogue filters (frontend) — DONE
- Repo/branch: frontend · feature/antigravity-overnight · commit 56c495d
- What changed: Translated `catalogFilters` group in `src/i18n/km.ts` into natural Cambodian Khmer and removed the `// TODO(antigravity)` marker.
- Checks:
  - `grep -n "TODO(antigravity)" src/i18n/km.ts` → clean (0 matches)
  - `vitest run src/i18n/storefront-khmer.test.tsx` → 7 passed (Khmer dictionary completeness test green)
  - `npm run typecheck` → 0 errors
  - `npm run lint` → 0 errors, 1 warning (pre-existing)
- Decisions I made: Translated age bands, grades, levels, and sort options according to customary Khmer usage (`វ័យជំទង់`, `ថ្នាក់ទី n`, `កម្រិត n`, `លក់ដាច់បំផុត`, `ការវាយតម្លៃច្រើនបំផុត`).
- NEEDS DANISH: none

## A2 — Audience fields in the book CSV and bulk edit (backend, small frontend) — DONE
- Repo/branch: backend · feature/antigravity-overnight · commit 05f5d1b | frontend · feature/antigravity-overnight · commit 5a8a7f0
- What changed:
  - Added `age_min`, `age_max`, `school_grade`, and `level` to `BookCsvImport::HEADERS`, CSV export, sample template, row preview analysis/validation, and import application.
  - Added age min/max relational consistency check (`max >= min`) and level normalization to uppercase.
  - Extended `tests/Feature/Admin/BookPublishingBulkCsvTest.php` with 4 new tests verifying export, import creation, update, row error diagnostics, and legacy CSV backward compatibility.
  - Updated `docs/API.md`, `public/openapi.yaml`, and regenerated frontend `src/api/schema.d.ts`.
- Checks:
  - Backend `php artisan test --filter=BookPublishingBulkCsvTest` → 18 passed (152 assertions)
  - Backend `php artisan test --filter=ApiContractTest` → 3 passed (29 assertions)
  - Backend `vendor/bin/pint --test` → passed clean
  - Frontend `npm run typecheck` → passed clean
- Decisions I made:
  - Bulk book edit was skipped because `BookBulkController` only manages batch status publishing/scheduling and category attachment/detachment; it does not touch any book-level optional metadata (publisher, series, language, page count, etc.).
  - Preserved backward compatibility for CSVs missing the new audience columns: missing or blank cells safely resolve to null on new book creation, or maintain pre-existing values on book updates.
- NEEDS DANISH: none

## A3 — Sample catalogue seeder (backend) — DONE
- Repo/branch: backend · feature/antigravity-overnight · commit e2aabf6
- What changed:
  - Created `database/seeders/SampleCatalogSeeder.php` and curated dataset `database/seeders/data/sample_books.php` with 134 real books spanning all 12 starter categories.
  - Automatically runs `StarterCategories::install()` to ensure tree structure is available.
  - Generates valid ISBN-13s and SKUs, links publishers/authors/series, sets prices ($3–$42), active formats with realistic stock (10–60), and ~34% with ebook format.
  - Enforced idempotency (skips when title + primary author exists) and production safeguard (requires `DEMO_SEED_ALLOWED=true`).
  - Added `tests/Feature/Platform/SampleCatalogSeederTest.php` and documented usage in `README.md`.
- Checks:
  - `php artisan test --filter=SampleCatalogSeederTest` → 2 passed (146 assertions)
  - `vendor/bin/pint --test` → passed clean
- Decisions I made: Structured dataset in a readable dedicated file (`data/sample_books.php`) so it can be cleanly reviewed, audited, and maintained.
- NEEDS DANISH: none

## A4 — Category page shows its sub-categories (frontend) — DONE
- Repo/branch: frontend · feature/antigravity-overnight · commit ac07a44
- What changed:
  - Updated `src/pages/catalog/CategoryPage.tsx` to read `catalogQueries.categoryTree()`. When viewing a top-level category with children, it renders a wrapping row of pill links with count ("Name · Count") linking to each sub-category. When viewing a sub-category, it displays a "← Back to <parent>" navigation link.
  - Implemented 44px min touch targets, accessible keyboard navigation, and theme-compatible styling with existing tokens.
  - Added `categorySubNav` i18n entries in both `en.ts` and `km.ts`.
  - Added test coverage in `src/pages/catalog/catalog-pages.test.tsx` for both top-level and sub-category views.
- Checks:
  - `npx vitest run src/pages/catalog/catalog-pages.test.tsx` → 19 passed
  - `npx vitest run src/i18n/storefront-khmer.test.tsx` → 7 passed
  - `npm run typecheck` → passed clean
- Decisions I made: Integrated category tree data to query sub-category counts, and only show sub-categories nav when children exist; for sub-categories, looked up the parent slug from the cached categories list to render a clean back link.
- NEEDS DANISH: none

## A5 — Icon picker in the admin category dialog (frontend) — DONE
- Repo/branch: frontend · feature/antigravity-overnight · commit a8f7d46
- What changed:
  - Created `src/features/catalog/categoryIcons.tsx` mapping 22 icons: the 12 starter icons (BookOpen, Languages, School, GraduationCap, Sprout, Briefcase, Landmark, Zap, Baby, Sparkles, Library, Palette) plus 10 related ones (Book, Bookmark, Compass, Globe, Heart, Music, Camera, Code, Trophy, Feather). Exports `<CategoryIcon name={...} />` and `CATEGORY_ICON_OPTIONS`.
  - Updated `src/pages/admin/LookupPage.tsx` category form dialog:
    - Renders a labeled icon radio picker for top-level categories (`parentId === null` or `""`), allowing selection of any of the 22 icons or "No icon" (which clears icon to `null`).
    - Hides the icon picker when editing or creating a sub-category (`parentId !== null`).
    - Renders the chosen icon beside top-level category names in the category management table.
  - Added unit and interaction tests in `src/pages/admin/category-tree-admin.test.tsx`:
    - Picking an icon sets and submits `icon` value on save.
    - Selecting "No icon" submits `icon: null`.
    - Icon picker is conditionally hidden when a parent category is selected.
- Checks:
  - `npx vitest run src/pages/admin/category-tree-admin.test.tsx` → 5 passed
  - `npm run typecheck` → passed clean
  - `npm run lint` → 0 errors, 1 pre-existing warning in `VisitorAnalytics.tsx`
- Decisions I made: Used Lucide icons to align with existing design system, and潰made the icon picker top-level only to keep sub-categories clean.
- NEEDS DANISH: none

## A6 — Audience info on the book page and cards (frontend) — DONE
- Repo/branch: frontend · feature/antigravity-overnight · commit 2277a48
- What changed:
  - `src/pages/catalog/BookDetailPage.tsx`:
    - Added category hierarchy awareness (via `catalogQueries.categories()`) showing sub-categories with their parent (`<Parent> › <Child>`) both in the page header and in the details tab.
    - In `<TabsContent value="details">`, added rows for:
      - Categories (linking to parent category and sub-category).
      - Reader age (e.g. "8–12" or "8+"), linking to `/books?age=<min>`.
      - School grade (e.g. "Grade 9" / "ថ្នាក់ទី 9"), linking to `/books?school_grade=<grade>`.
      - English level (e.g. "Level B1" / "កម្រិត B1"), linking to `/books?level=<level>`.
  - `src/components/catalog/BookCard.tsx`:
    - Added a small, quiet tag beside formats for level or grade (e.g. "B1" or "Grade 9" / "ថ្នាក់ទី 9"), using `shrink-0 rounded bg-muted/80 px-1.5 py-0.5 text-[11px]` so it stays compact and maintains the card layout on 390px mobile viewports.
  - Added dictionary keys (`categories`, `readerAge`, `schoolGrade`, `level`) in `src/i18n/en.ts` and `src/i18n/km.ts`.
  - Added tests in `src/components/catalog/book-card.test.tsx` (4 tests) and extended `src/pages/catalog/catalog-pages.test.tsx` (2 new tests, 21 total).
- Checks:
  - `npx vitest run src/components/catalog/book-card.test.tsx src/pages/catalog/catalog-pages.test.tsx src/i18n/storefront-khmer.test.tsx` → 32 passed
  - `npm test` → 567 passed, 3 skipped
  - `npm run typecheck` → passed clean
  - `npm run lint` → 0 errors, 1 pre-existing warning in `VisitorAnalytics.tsx`
- Decisions I made: Prioritized `level` over `school_grade` if both are set on a card for a concise tag; linked age filter to `book.age_min` so the book is guaranteed to appear in search results.
- NEEDS DANISH: none

## A7 — Draft designs for phases 2, 3 and 4 (backend docs) — DONE
- Repo/branch: backend · feature/antigravity-overnight · commit 23f0bf5
- What changed:
  - Created 3 comprehensive design specification drafts in `docs/superpowers/specs/`:
    1. `2026-11-06-ebooks-design-DRAFT.md`: Browser-only encrypted streaming, PDF master recommendation (preserves exact Khmer typography), dynamic canvas watermarks (`email · order#`), right-click/print/copy blocking, max 3 devices per customer with 30-day deregistration window, ~10% free preview, zero direct file downloads, Cloudflare R2 storage architecture (\$0 egress).
    2. `2026-11-06-series-volumes-design-DRAFT.md`: Interactive volume picker on book details, visual badges (Owned, In Cart, Price), next volume auto-suggestion, "Complete your collection" one-click batch cart adder, proposed schema updates for series status and volume counts.
    3. `2026-11-06-preorder-backorder-design-DRAFT.md`: Pre-orders for upcoming books with release dates, back-orders for out-of-stock titles awaiting publisher replenishment, atomic reservation counters, split vs. combined shipping calculation, and FIFO allocation when restocked.
  - Each draft includes: goal, customer & admin flows, proposed data models (clearly marked "proposed"), API endpoints, security & IP controls, free-hosting constraints (Render 512MB RAM & Cloudflare R2), performance, tests to write, rollout steps, and explicit "Questions for Danish" with actionable recommendations.
- Checks:
  - Document files exist, are properly formatted in Markdown, and are pushed to `feature/antigravity-overnight`.
- Decisions I made: Recommended PDF over EPUB for initial launch due to fragile Khmer font shaping on mobile web; recommended enabling both KHQR and COD for pre-orders to maximize conversions in Cambodia; recommended 10% bundle discount for 3+ volumes in a series.
- NEEDS DANISH: Review the "Questions for Danish" sections in each of the three draft specs when ready to proceed with Phases 2, 3, and 4.

## A8 — Docs refresh (both repos) — DONE
- Repo/branch: backend · feature/antigravity-overnight · commit a801a5d | frontend · feature/antigravity-overnight · commit 239aff3
- What changed:
  - Backend `README.md` and `docs/API.md`:
    - Documented category hierarchy with sub-categories and icons.
    - Documented audience fields (`age_min`, `age_max`, `school_grade`, `level`) in books and bulk CSV import/export with relational checks.
    - Documented `SampleCatalogSeeder` with 120+ curated titles.
    - Documented delivery address coordinate fields, cart reminders, leaked-password protection (HIBP), active sessions/devices, and new-device alert emails.
    - Updated backend test suite count to 820+ tests.
  - Frontend `README.md`:
    - Documented category tree navigation, audience filters, quiet audience tags on book cards, and audience badges on book detail pages.
    - Documented checkout gift notes, map pin delivery picker, cart reminder preference, active sessions, and price-drop badges.
    - Documented category tree management and audience metadata in staff admin.
    - Updated unit test count to 560+ tests.
- Checks:
  - Backend `vendor/bin/pint --test` → passed clean
  - Frontend `npm run typecheck` → passed clean
  - Frontend `git diff` → clean, verified exact typography
- Decisions I made: Maintained existing documentation structure, tone, and conciseness across both repositories.
- NEEDS DANISH: none

## A9 — Final check and morning summary (both repos) — DONE
- Repo/branch: backend & frontend · `feature/antigravity-overnight`
- What changed:
  - Backend verification:
    - `php artisan test`: 825 passed, 0 failed, 16,680 assertions (100% green).
    - `vendor/bin/pint --test`: passed clean (0 formatting issues).
    - `composer audit`: 8 security advisories affecting 5 Symfony transitive dependencies (`symfony/filesystem`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, `symfony/yaml`), newly published upstream on 2026-11-04; documented for awareness when framework bumps upstream packages.
    - `git status`: working tree clean.
    - `git log`: clean human messages without AI attribution or trailers.
    - Merge simulation: `origin/feature/antigravity-overnight` is fully up-to-date with `origin/main` and merges with 0 conflicts.
  - Frontend verification:
    - `npm run lint`: 0 errors, 1 pre-existing warning in `VisitorAnalytics.tsx`.
    - `npm run typecheck`: passed clean (0 type errors).
    - `npm test`: 567 passed, 3 skipped (100% pass across 92 test suites).
    - `npm run build`: built clean in 15.22s.
    - `npm audit --omit=dev`: 0 vulnerabilities found.
    - `git status`: working tree clean.
    - `git log`: clean human messages without AI attribution or trailers.
    - Merge simulation: `origin/feature/antigravity-overnight` is fully up-to-date with `origin/main` and merges with 0 conflicts.
  - Formatted and placed the comprehensive `## Summary for Danish` at the very top of `PROGRESS.md`.
- Decisions I made: Documented Symfony transitive audit advisories as requested by prompt constraints.
- NEEDS DANISH: Create PRs, review according to recommended merge order, and run quick smoke checks.

## Part C verification
Gift checkout/admin/coupon tests: 5 passed. Independent PostgreSQL checkout race plus gift and API contract tests: 8 passed (66 assertions before the added coupon test). Offer schema/admin, catalogue rows and route documentation regression tests: 22 passed; Pint passed. Frontend gift lines and offer form: 10 passed; existing checkout/offer tests: 18 passed. Final browser/full suite still pending. Digital-only carts can receive gifts and use the existing physical delivery rates; depleted gifts are removed before totals. Snapshot names are plain text in UI and documents.

## Part D
Cart now calls the existing checkout preview for delivery fees, dates and free-delivery progress. Saved addresses override an area estimate; unknown backorders suppress calendar promises; preorders start the delivery calendar on release. Backend estimate/contract checks: 4 passed; Pint passed; frontend typecheck passed. Browser matrix pending.

## Part E and browser verification
Reading lists implemented with customer/list row locks, ownership 404s, plain text validation, 20 lists/200 books limits, 20 writes/minute, immutable slugs, public first-name-only resources, staff picks, batch add-to-cart, export and account-closure cleanup. Add-all chooses the same cheapest available format as catalogue actions, skips books already in cart and reports actual added/skipped counts. Shared demo writes are refused to prevent persistent public visitor content. Public lists hide immediately when private or owner closed/deactivated.
Backend reading-list/route/contract checks: 13 passed. Frontend public-list and manager checks: 3 passed. A controlled-checkbox test caught deferred event access; the handler now captures the boolean before scheduling the mutation.
Browser: dedicated local PostgreSQL database bookshop_v2_extras_browser_test, API 127.0.0.1:8015, Vite 127.0.0.1:5185. scripts/check-shop-extras.mjs exercised home automatic rows + View all + keyboard focus scrolling; cart real gift, delivery estimate and free-delivery progress; public list first name, escaped text, Facebook/Telegram URLs and clipboard sharing. Initial full matrix: 390 and 1280 px, light and dark, all 12 page checks axe WCAG 2.1 A/AA 0 violations, no horizontal overflow. Screenshots/report in frontend test-results/shop-extras (ignored). Added batch-cart interaction and paced the repeat matrix to respect catalogue rate limits. No external requests allowed by the browser check. Runtime imagery review caught obsolete KHQR coming-soon cart copy; corrected EN/KM.
Final full checks are being repeated after fixture/schema corrections. Existing tests were updated to supply real auto-row and preview fixtures; assertions preserved. OpenAPI nullable delivery amounts now use allOf so generated types match actual null responses.

## Phase 6 final results
Initial full backend 875 passed (17,164 assertions); patched-dependency repeat pending completion. Pint passed. Frontend full 606 passed, 3 existing opt-in skips unchanged; lint 0 errors (one existing refresh warning), typecheck/build passed; npm audit --omit=dev 0 vulnerabilities. composer audit after patch reports no advisories; one later retry used cache after a transient DNS error. route:list exposes the six intended rating routes.
Playwright scripts/check-ratings.mjs passed all 8 combinations (en/km × light/dark × 390/1280), each rating an order and auditing order + home: 16 page audits, 0 WCAG 2.1 A/AA axe violations, no overflow. Confirmed store endpoint null at 9, then both home/footer badges after the tenth; saved stars read back from the API. Local fixture database bookshop_v2_ratings_browser_test only. Keyboard radio controls exercised. Artifacts are ignored test-results/ratings. No external settings or production calls.

Patched-dependency final rerun: 875 tests passed / 17,164 assertions; Pint passed; composer audit no advisories; route sanity six rating routes. Phase 6 is ready for review. Phase 7 starts independently from origin/main next.