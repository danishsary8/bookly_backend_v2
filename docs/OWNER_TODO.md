# Owner to-do (your part)

Everything that only you can do (merges, accounts, keys, decisions), in one place. Written 2026-10-30 (overnight).
Tick the boxes as you go. Nothing below breaks the live site if you have not done it yet: every new feature keeps
working with today's settings.

---

## Progress (2026-10-31)

- Done: all 18 branches merged and deployed; backup points at `bookly-2` (green run); demo data is there; live checks
  (Download my data, 404 answer, typo search, Download button, admin orders auto-refresh) passed.
- **Done 2026-11-02:** section 4c (phone code). What we learned: a number typed in **Profile** is only a courier
  contact number. Codes are sent only to a **verified** number linked to the bot. Fix: **Account → Sign-in & security →
  Phone row → Confirm** → share the number in the Bookly bot → then try the phone code again (wait for the
  "Too many attempts" timer first). The "Telegram order updates" card does not verify the number.
- Then: section 3 (R2), section 5 (decisions), sections 4b, 6, 7, 8 when ready.

---

## 0. New since 2026-10-31 (notes for each task: `docs/HANDOFF.md` on this branch)

**Task F: phone sign-in code hint** (frontend only, no backend change, no setting to change)
- [ ] Merge: https://github.com/danishsary8/bookly_frontend/pull/new/fix/phone-code-hint → **Create pull request** →
  wait for green CI → check the reviewer checklist in `docs/prompts/README.md` → **Merge**. Vercel deploys by itself.
- [ ] Live check after Vercel shows **Ready**: https://bookly-frontend-five.vercel.app/login →
  **Sign in with phone number?** → the hint "Codes only go to a confirmed number…" is under the field → type `123` →
  **Send code in Telegram** → red error → type `012 345 678` → the error goes away and the hint is back.
  Then switch the language to Khmer → the same hint in Khmer.
- [ ] Signed in: **Account → Profile** → under **Phone**: "Couriers call this number…" → click **Sign-in & security**
  → that page opens.
- [x] Khmer wording: read the two new Khmer lines (login hint and Profile hint; text in `docs/HANDOFF.md`). Change them (Owner: all ok, 2026-11-02.)
  if a native speaker would say it differently.
- [x] Then finish section 4c below (confirm your phone in Sign-in & security, then try the code).

**Task A: "You may also like" (similar books)** (backend + frontend; no migration, no setting to change; merge order: any,
each branch works alone)
- [ ] Merge the backend: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/better-similar-books →
  **Create pull request** → wait for green CI → check the reviewer checklist → **Merge**. Render deploys by itself.
- [ ] Merge the frontend: https://github.com/danishsary8/bookly_frontend/pull/new/feature/better-similar-books →
  **Create pull request** → green CI → **Merge**. Vercel deploys by itself.
- [ ] Live check when both show **Ready/Live** (the first visit may take ~1 minute while Render wakes up):
  1. Open a book in a series, e.g. the 2nd Sherlock Holmes book on https://bookly-frontend-five.vercel.app/books →
     scroll down: rows "The series", "More by …", then **More like this / You may also like**. No book in the last row
     is in the two rows above, and the book itself is not in it.
  2. Open a book with few matches (e.g. *Meditations*) → **You may also like** shows a full row (up to 12 books).
  3. Sign in, put the 1st book of a series in the cart → open **Cart** → under **Recommended with your order** the
     next book of that series comes first.
  4. Switch the language to Khmer → the row reads **សៀវភៅស្រដៀងគ្នា / អ្នកប្រហែលជាចូលចិត្ត**.
  5. On your phone (or the browser at phone width) → the rows scroll sideways and the page does not.
- [x] Khmer wording: is **សៀវភៅស្រដៀងគ្នា** ("similar books") a natural eyebrow for "More like this"? Change it if not. (Owner: all ok, 2026-11-02.)
- Note (no action): if staff mark a book back in stock, add a format, or a scheduled book goes live, it can take up to
  1 hour to join these lists. A sold-out book never shows.

**Task B+D: offers ("Buy 2, get 15% off") + offer reports** (in progress; spec
`docs/superpowers/specs/2026-10-31-offers-design.md` on `feature/offers-engine`)
- [ ] **Rollout order (important):** merge the frontend `feature/offers-storefront` **before** you create or resume
  the first live offer in the admin. Why: today's checkout page does not know about offers, so a coupon that lost to
  an offer would vanish from the summary without an explanation. Until a live offer exists, nothing changes for
  customers (the demo offer from the seeder is created **paused**).
- [ ] **Merge the backend `feature/offers-engine`** (done and reviewed 2026-11-01; its 4 new migrations run by themselves on
  deploy; nothing changes for customers while no offer is live; the demo offer is paused):
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/offers-engine → **Create pull request** → green CI →
  **Merge**. After Render shows **Deploy live**: open the shop → books, cart and checkout still work as before.
  Do **not** create or resume an offer yet.
- [ ] **Merge the frontend `feature/offers-storefront`** (after the backend one; reviewed and browser-checked 2026-11-01):
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/offers-storefront → **Create pull request** → green CI →
  **Merge**. Vercel deploys by itself. Nothing shows until an offer is live.
- [x] Decided 2026-11-01: free delivery starts at **$50**. Fix for the announcement bar (it said $25), frontend only:
- [ ] **Merge** https://github.com/danishsary8/bookly_frontend/pull/new/fix/announcement-free-delivery (fixed 2026-11-01:
  it no longer touches the work log, so it merges cleanly).
- **Notes rule (you chose "notes only", 2026-11-01):** feature branches no longer edit `WORKLOG.md`. Notes live on two
  long-lived branches: backend `docs/handoff-notes` and frontend `docs/worklog-notes`
  (https://github.com/danishsary8/bookly_frontend/pull/new/docs/worklog-notes). Merge them whenever you like, as often as
  you like; after a merge I keep using the same branch names.
- [ ] **Merge the frontend `feature/offers-admin`** (any order; it says "not available yet" until the backend is live):
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/offers-admin
- [ ] **Your first offer** (only after `offers-engine`, `offers-storefront` and `offers-admin` are all merged and deployed):
  Admin → **Offers** → **New offer** → e.g. name "Buy 2 Holmes", 2 books, 15%, search "Holmes" → pick the series → check
  the preview → **Create offer** (it starts paused) → switch it **On** in the list → confirm. Then open the shop: the Holmes
  books show "Buy 2, save 15%"; put 2 in the cart → "Offer: buy 2, save 15%". Pause it again with the switch if you only
  wanted to test.
- [ ] **Merge the offers report** (both, any order):
  backend https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/offer-reports ·
  frontend https://github.com/danishsary8/bookly_frontend/pull/new/feature/offer-reports
  Check: Admin → **Reports** → **Offers** tab → choose **30 days** → after your first offer orders you see "Books per order
  with an offer" vs "Without an offer". **Export CSV** downloads the same table.

---

**Task E: BI dashboard (part 1) + a live bug fix** (merge in any order)
- [ ] 🔴 **Merge first, it fixes your live dashboard:** backend `fix/dashboard-status-cache`
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/dashboard-status-cache
  (today "Orders placed by status" shows strange text when you reload the dashboard within 5 minutes).
- [ ] Backend `feature/dashboard-insights`: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/dashboard-insights
- [ ] Frontend `feature/admin-dashboard-redesign`: https://github.com/danishsary8/bookly_frontend/pull/new/feature/admin-dashboard-redesign
- [ ] Check after deploy: Admin → **Dashboard** → 30 days → each top card has a small line and a "View …" link; the
  "Income report" chart has a solid line (this period) and a dashed one (the month before), and an Income / Orders
  switch; "Orders placed by status" is a ring with the total in the middle. Reload twice: still correct.

---

**Task E: BI dashboard (part 2)** (frontend, any order)
- [ ] https://github.com/danishsary8/bookly_frontend/pull/new/feature/admin-quick-jump
- [ ] https://github.com/danishsary8/bookly_frontend/pull/new/feature/admin-insights-widgets
- [ ] Check after deploy: the menu shows red numbers beside Orders / Returns / Stock; press **Ctrl K** (Mac: Cmd K) →
  type part of an order number or a book title → Enter opens it; scroll down the dashboard: Stock value, Dead stock,
  Repeat customers, Slow movers, Top authors, Orders by province, Offers and coupons.

---

**🔴 E2E check failing on PRs (2026-11-01)** — caused by the dashboard donut (already on `main`), not by your new PRs.
- [ ] **Merge first:** https://github.com/danishsary8/bookly_frontend/pull/new/fix/dashboard-donut-focus
- [ ] Then on PR #107 (`feature/admin-insights-widgets`) click **Update branch** (it re-runs the checks with the fix) →
  wait for all green → **Merge**.

---

**Small fixes + PR #107 (2026-11-01)**
- [ ] PR #107 (dashboard extras) was still open: I updated it with `main` (incl. the donut fix). Wait for all checks green,
  then **Merge**: https://github.com/danishsary8/bookly_frontend/pull/107
- [ ] Small fixes (account strip fade, free-delivery texts EN/KM, donut colours):
  https://github.com/danishsary8/bookly_frontend/pull/new/fix/small-cleanups
- [ ] Notes branches whenever you like: https://github.com/danishsary8/bookly_frontend/pull/new/docs/worklog-notes ·
  https://github.com/danishsary8/bookly_backend_v2/pull/new/docs/handoff-notes

**Book cover on the New book page (2026-11-02)** (frontend only; you chose "On New book page")
- [x] Merged (frontend PR #117).
- [x] R2 done (2026-11-02, set up with another helper; checked: a cover uploaded on New book got an `https://pub-…r2.dev/covers/…` address). Earlier note: (section 3 below / backend `docs/DEPLOYMENT.md` F6b): without R2, Render wipes uploaded
  covers at every deploy and restart. With R2 they are kept for good.
- [ ] Live check: Admin → Books → **New book** → title + **Cover** (upload a picture) → **Create** → "Add format" opens
  with the cover → SKU + price → save. The book page shows the **Cover** panel; the shop shows the cover. After the next
  Render deploy the cover is still there (only true with R2).

**Auto refresh (2026-11-02)** (frontend only; you chose "Admin + customer orders")
- [x] Merged (2026-11-02).
- [ ] Live check: open an order in the admin in one tab; in another tab (or your phone) change it, e.g. mark it Shipped.
  Within 30 s the first tab shows Shipped without reloading. Every admin page says "Updates by itself · … ago" with a
  "Refresh now" button. As a customer, an order page that is not finished updates within about a minute.
- [x] KHQR live check passed (2026-11-02: order ORD-20261102-YGP55 shows PAID with the KHQR filter).

**Ideas for later (owner, 2026-11-03), not started, ask before building:**
- Google Maps: e.g. pick the delivery spot on a map at checkout / in the address book, and a shop map on Contact.
- Facebook connection: e.g. turn on "Continue with Facebook" (the code exists; needs a Facebook app ID and secret),
  and/or a Facebook page / Messenger link.
- Stronger sign-in and sign-up security: e.g. a review of the current protections, then additions such as
  new-device sign-in emails, a breached-password check, optional two-step codes for customers.

**Smaller main file (2026-11-03)** (frontend only; merged 2026-11-03)
- The form-checking library (Zod) no longer loads on every page, only with forms: main file 105 → 101 KB.
- Kept as you chose: both languages stay in the main file, so Khmer visitors are not slowed down.
- [x] Merge: https://github.com/danishsary8/bookly_frontend/pull/new/perf/smaller-main-file

**Excel report download (2026-11-03)** (backend + frontend; branch `feature/excel-sales-export` in both)
- Your choice: OpenSpout, all sheets. Admin → Reports → **Excel, all reports**: one .xlsx with Summary, Sales by
  day, Top books, Top customers (names and emails: keep the file private), Orders, Coupons and refunds, Offers.
- [ ] Merge backend: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/excel-sales-export
  (adds the OpenSpout library and PHP's zip extension to the Docker image)
- [ ] Merge frontend: https://github.com/danishsary8/bookly_frontend/pull/new/feature/excel-sales-export
  (any order; before the backend is live the button says "not available yet")
- [ ] Live check: Admin → Reports → pick dates → **Excel, all reports** → open the file in Excel or Google Sheets.

**Wishlist price-drop emails (2026-11-03)** (backend + frontend; both merged 2026-11-03)
- Your choices: opt-in switch (off by default), email only, 5% or more, at most 1 email per book per week.
- [x] Merge backend: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/price-drop-alerts
  (adds one column, `customers.price_drop_alerts`; Render runs the migration on deploy)
- [x] Merge frontend: https://github.com/danishsary8/bookly_frontend/pull/new/feature/price-drop-alerts
  (any order: the switch only shows once the backend is live)
- [ ] Live check: sign in with your own customer account → Account → **Profile** → turn on **Price-drop emails** →
  add a book to your wishlist ("Want to read") → in the admin, lower one of its formats by 5% or more → within a
  minute or two you get the email. Put the price back afterwards.
- [ ] Khmer wording, please check (in `km.ts` → `priceDrop`, and backend `lang/km/mail.php` → `price_drop_*`):
  switch "អ៊ីមែលពេលតម្លៃចុះ"; email subject ":title ថោកជាងមុនហើយ".

**Speed check (2026-11-03)** (frontend only; merged 2026-11-03)
- Fonts now come from our own site (your choice), ~40 tiny code files were joined, and the book page no longer
  jumps while it loads. Phone score (simulated slow phone): home 74 → 76, book page 64 → 71 (main content 1 s sooner).
- [x] Merge: https://github.com/danishsary8/bookly_frontend/pull/new/perf/speed-check
- [ ] After Vercel deploys: open the live shop in English and Khmer. Text should look the same as before (same
  fonts). Open any book on your phone: the page should not jump while it loads.

**Security review of the work since 15 Oct (2026-11-03)** (backend only; merged 2026-11-03)
- Checked and fine: every staff route needs a staff token + 2FA, admin-only actions (mark paid, offers, collections,
  staff) need admin; customers only reach their own orders, payments, credits, alerts and sessions; KHQR (simulate only in
  test mode, Bakong answer must match amount and currency); cover uploads (type, real content, size, random names);
  CSV exports escape formulas; search and reports use bound SQL; phone codes die after 5 wrong guesses; newsletter links
  use long random tokens; no secrets in the code.
- Fixed (the shared demo account): no more public reviews from it; no Telegram order updates linked to it (and the
  hourly reset unlinks any chat); the hourly reset removes addresses visitors typed in and keeps only its sample address.
- [x] Merge: https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/demo-account-limits
- [ ] After Render shows Live: Admin → **Reviews** → look for reviews by "Demo Customer" that visitors wrote (not the
  seeded ones) and **Hide** any you don't want public. The fix stops new ones; it does not remove old ones.

**Portfolio READMEs + Khmer title fix (2026-11-03)** (all 3 merged 2026-11-03)
- [x] Frontend README (new screenshots, features, 2-minute tour):
  https://github.com/danishsary8/bookly_frontend/pull/new/docs/readme-refresh
- [x] Backend README (KHQR, offers, collections, Telegram; 761 tests; 39 tables):
  https://github.com/danishsary8/bookly_backend_v2/pull/new/docs/readme-refresh
- [x] Fix: with "reduce motion" on, the Khmer home title split the word ទូទាំង over two lines on phones:
  https://github.com/danishsary8/bookly_frontend/pull/new/fix/khmer-hero-wrap
- [ ] After merging: open both repos on GitHub and read the README once; tell me anything that sounds wrong.
- Note: screenshots were taken on my local copy, where demo cover pictures can't load, so they show the shop's own
  cover design. If you want real-photo screenshots, take them on the live site and replace the files in
  `bookly_frontend/docs/screenshots/` (same names).

**Site quality check (2026-11-02)** (frontend only; branch `fix/site-check-polish`)
- What was checked: 65 pages (shop, account, admin) in English and Khmer, light and dark, at 320, 360, 390 and 1280 px,
  with axe (WCAG 2.1 A/AA), sideways scroll, console errors, one h1 per page; 13 pop-up windows on a short phone screen
  scrolled to their buttons.
- Fixed: admin top bar and several admin pages scrolled sideways on small phones (320/360 px); the account overview was
  12 px too wide at 390 px; 4 tables could not be scrolled with the keyboard.
- Not a problem (my test setup only): outside pictures blocked, and "too many requests" when two test runs shared one
  staff login (limit: 120 requests a minute per person).
- [x] Merged (2026-11-03).
- [ ] Live check: on your phone open the admin Dashboard: nothing moves sideways when you swipe left/right.

**Online payments: Bakong KHQR (2026-11-02)** (design: `docs/superpowers/specs/2026-11-02-khqr-payments-design.md` on
`feature/khqr-payments`). Merge in any order; each works alone. Backend first is nicest.
- [ ] Backend: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/khqr-payments (no migration, no setting
  needed: test mode is the default)
- [ ] Frontend shop: https://github.com/danishsary8/bookly_frontend/pull/new/feature/khqr-checkout
- [ ] Frontend admin: https://github.com/danishsary8/bookly_frontend/pull/new/feature/khqr-admin
- [ ] Live check (test mode) when all 3 are deployed:
  1. Sign in as a customer → add a book → **Checkout** → choose **Bakong KHQR** → **Place order and pay**.
  2. The payment page shows a red-and-white KHQR card, the amount and a 15:00 countdown. Tap **Riel**: the amount in ៛.
  3. Tap **Simulate payment (test only — no real money)** → "Payment received" → after a few seconds the order page,
     status **Paid**. Check your email/Telegram: the "order placed" message arrives now (not before).
  4. Place another KHQR order and wait 15 minutes (or leave the tab): the order is **cancelled** and the book's stock is
     back. (Housekeeping runs from web traffic, so it may take until the next visit.)
  5. Admin → **Orders** → filter **KHQR** → open an order: the **Payment** box lists the QR codes. On a waiting order,
     **Mark as paid** asks for a note. Dashboard → **System health** → "KHQR payments: Test mode".
- [x] Khmer wording on the payment page (`pay` group in `src/i18n/km.ts`, ask a native speaker). (Owner: all ok, 2026-11-02.)

**Bakong account verified (owner, 2026-11-03).** Step 1 done: account ID `danish_sary@bkrt` (checked: the shop builds a valid QR with it). Next: step 2 (token).

**Optional, later: real Bakong payments** (only if you want real money to be checked; test mode is enough for the
portfolio). Never paste the token into chat, code or docs.
1. In your bank app (ABA, ACLEDA, Wing…), find your **Bakong account ID**. It looks like `yourname@aba` or
   `yourname@aclb` (often under Profile → KHQR / Bakong). This is not secret.
2. Register on the **Bakong Open API** website, https://api-bakong.nbc.gov.kh, with your email (Register). NBC emails you a
   **token**. It expires after about 90 days; you renew it on the same site and get a new one by email.
3. Render → your Bookly API service → **Environment** → add:
   - `KHQR_ACCOUNT_ID` = your Bakong account ID
   - `KHQR_MERCHANT_NAME` = `Bookly` (plain letters only, max 25)
   - `BAKONG_TOKEN` = the token from the email (secret: only here)
   - `KHQR_MODE` = `bakong`
   Then **Save, rebuild and deploy**.
4. Admin → Dashboard → **System health** → "KHQR payments":
   - "Bakong mode, no payment checked yet" or "Bakong connected" = good.
   - "Bakong refused this server (often: not from Cambodia)" = Bakong only answers servers in Cambodia. Set `KHQR_MODE`
     back to `test` (the shop keeps working). A small Cambodian server that forwards the check (`BAKONG_RELAY_URL`) is
     possible later (~$5/month); ask me first.
5. Test with real money: put a cheap book in the cart, pay the QR with your own bank app → the page turns to "Payment
   received" within a few seconds. The money goes to your own account.
6. Every ~90 days: when the health card says "token expires soon", renew the token on the Bakong site and replace
   `BAKONG_TOKEN` in Render.

**To review later (choices I made under your "go ahead" rule, 2026-11-02; tell me to change any):**
- Design part 3 (tests, branches, setup guide) was taken as approved with "accept all part you recommend".
- New KHQR orders send "order placed" only when paid; an unpaid order that times out gets the usual "cancelled" message.
- The payment page opens the order by itself 3.5 s after "Payment received".
- Admin "Mark as paid" needs a note of 3+ characters; staff (not admin) can only look.
- The QR's shop name is `Bookly`, city `Phnom Penh`; the test account ID is `bookly@devb` (Bakong's test bank).

**Dialog scroll fix + Buy again with low stock (2026-11-02)**
- [x] Dialog scroll fix merged (tall forms could not scroll).
- [x] Backend: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/buy-again-partial-stock
- [x] Frontend: https://github.com/danishsary8/bookly_frontend/pull/new/feature/buy-again-partial-stock
  (any order; each works alone)
- [ ] Live check after both deploy: sign in as a customer with a delivered order of 2+ copies of one book → in the admin
  lower that book's stock to 1 → **Account → Orders → the order → Buy again** → the cart has 1 copy and a note says
  "<title>: 1 of 2 added". Put the stock back afterwards.
- [x] Khmer wording: "ស្តុកមិនគ្រប់សម្រាប់សៀវភៅ N មុខ" and "បានបន្ថែម 2 ក្នុងចំណោម 3" (`orders` group in `km.ts`). (Owner: all ok, 2026-11-02.)

**Seasonal collections (2026-11-01)** (merge in any order; backend first is nicest; each works alone)
- [ ] Backend (adds 2 tables; Render runs the migration by itself on deploy):
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/seasonal-collections
- [ ] Frontend shop (home rows + collection page):
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/seasonal-collections-shop
- [ ] Frontend admin (`/admin/collections`):
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/seasonal-collections-admin
- [ ] Live check when all 3 are deployed:
  1. https://bookly-frontend-five.vercel.app/admin/collections → **New collection** → English title "Rainy season reads",
     a Khmer title, add 4+ books, move one with ↑ → **Create collection**. It is **Off** (new ones start off).
  2. Switch it **On** in the list → open the home page → a "Seasonal pick" row is above New arrivals, books in your order.
  3. Click **See all** → its own page with the description and the end date. Switch the language to Khmer → Khmer title.
  4. Switch it **Off** → reload the home page: the row is gone (a tab left open may show it up to 5 minutes) and the page link says "not found".
- [x] Khmer wording: the eyebrow **Seasonal pick** and "See all" in Khmer (`collections` group in `src/i18n/km.ts`). (Owner: all ok, 2026-11-02.)

---

## 1. Merge (any order is fine)

All branches start from `main` and were checked together: every order merges cleanly and the combined code passes all
613 backend tests. For each link: **Create pull request** → wait for green CI → **Merge**.

**Backend** (`bookly_backend_v2`)
- [x] `feature/r2-media-storage` — book covers can be stored on Cloudflare R2 (stays on today's disk until you do step 3)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/r2-media-storage
- [x] `fix/symfony-mime-advisory` — security update (symfony/mime 7.4.22, CVE-2026-45070)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/symfony-mime-advisory
- [x] `fix/demo-reset-keeps-going` — the hourly demo reset no longer stops at one bad order, and the order history
  says "Cancelled automatically" instead of "Cancelled by customer"
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/demo-reset-keeps-going
- [x] `feature/customer-data-export` — new `GET /api/v1/me/export` ("Download my data")
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/customer-data-export
- [x] `fix/docs-small-followups` — 404s no longer reveal internal class names; two docs fixes
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/docs-small-followups
- [x] `fix/docs-stale-demo-token` — the docs page checks a saved demo token, so an expired one never shows "Signed in"
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/docs-stale-demo-token
- [x] `feature/typo-tolerant-search` — search finds books despite typos ("frankenstien", "sherlok", "jane austin");
  no migration needed (pg_trgm is already on)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/typo-tolerant-search
- [x] `feature/load-test-kit` — `load/live-check.js`: a gentle k6 check of the live API (see section 4)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/load-test-kit
- [x] `feature/send-emails-after-response` — order, status and security emails are sent right after the answer
  (faster checkout; a mail outage no longer shows an error after the order is saved). Codes still go out at once.
  Nothing to set up (free, no worker)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/send-emails-after-response
- [x] `feature/staging-setup-kit` — the staging guide (`docs/STAGING.md`) and CI on the `staging` branch. Docs only
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/staging-setup-kit
- [x] `feature/telegram-phone-code` — sign in with a phone number + a 6-digit code from the Bookly bot (no
  migration). Merge together with the frontend branch of the same name (either first is safe)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/telegram-phone-code
- [x] `docs/overnight-notes` — this file and the work log
  https://github.com/danishsary8/bookly_backend_v2/pull/new/docs/overnight-notes

**Frontend** (`bookly_frontend`)
- [x] `chore/dependency-audit` — package security fixes + Vitest 5 (shipped code: 0 vulnerabilities; 22 left, all in
  developer/build tools, each needing its own major upgrade)
  https://github.com/danishsary8/bookly_frontend/pull/new/chore/dependency-audit
- [x] `feature/typo-search-notice` — search results say "No exact match for … Showing books with a similar spelling"
  when the backend used typo matching (shows nothing until `feature/typo-tolerant-search` is merged; safe either order)
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/typo-search-notice
- [x] `feature/download-my-data` — "Download my data" button on Account → Sign-in & security (says "not available yet"
  until `feature/customer-data-export` is merged; safe either order)
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/download-my-data
- [x] `feature/admin-orders-auto-refresh` — new orders appear in the admin by themselves (checks every 30 s while
  the tab is open; a toast, the list reloads, a "New" badge). Frontend only, nothing to set up
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/admin-orders-auto-refresh
- [x] `feature/telegram-phone-code` — the phone sign-in asks for the number and the code; Continue with Telegram
  stays under it as the backup (before the backend part is live, it says codes aren't available yet)
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/telegram-phone-code
- [x] `feature/staging-banner` — a "Staging site: test data only" bar, shown only when `VITE_APP_ENV=staging`
  (nothing changes on the live site); CI on the `staging` branch
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/staging-banner

After merging: Render and Vercel deploy by themselves. Wait for **Deploy live** on Render (Events tab).

---

## 2. Urgent (do first)

- [x] **Point the nightly backup at the new database.** You moved to the Neon project `bookly-2`, but the backup still
  reads the old address. GitHub → `bookly_backend_v2` → **Settings → Secrets and variables → Actions** →
  `BACKUP_DATABASE_URL` → **Update** → paste the `bookly-2` connection string (Neon → `bookly-2` → **Connect**).
  Then **Actions → Nightly database backup → Run workflow** once and check it turns green.
- [x] **Demo data on the new database** (needed by the docs page's demo button), if not done yet: `docs/DEPLOYMENT.md`
  → F6 (run `DemoSeeder` from your computer with the `bookly-2` `DB_URL`). Check: https://bookly-api-zasc.onrender.com/docs
  → **Sign in as demo customer** → no "demo account is not set up" message.

---

## 3. Cover images on Cloudflare R2 — DONE 2026-11-02 (bucket `bookly-media`, covers get r2.dev addresses) (after merging `feature/r2-media-storage`)

Until you do this, covers are still saved on Render's disk (wiped at every deploy), exactly as today.

1. [ ] https://dash.cloudflare.com (the same account as Turnstile) → **R2 Object Storage** → **Create bucket**, name
   `bookly-media`, location **Automatic** → **Create**.
2. [ ] In the bucket → **Settings** → **Public access** → **R2.dev subdomain** → **Allow**. Copy the public URL
   (starts with `https://pub-`).
3. [ ] **R2 Object Storage** → **Manage API tokens** → **Create API token**: **Object Read & Write**, only bucket
   `bookly-media`. Copy the **Access Key ID**, the **Secret Access Key** (shown once) and the **S3 endpoint**
   (`https://<account-id>.r2.cloudflarestorage.com`).
4. [ ] Render → `bookly-api` → **Environment** → add, then **Save Changes**:

   | Key | Value |
   | --- | --- |
   | `MEDIA_DISK` | `r2` |
   | `R2_ACCESS_KEY_ID` | the Access Key ID |
   | `R2_SECRET_ACCESS_KEY` | the Secret Access Key |
   | `R2_BUCKET` | `bookly-media` |
   | `R2_ENDPOINT` | the S3 endpoint (without the bucket name) |
   | `R2_PUBLIC_URL` | the public URL from step 2, no trailing slash |

5. [ ] Test: in the admin, upload a book cover → its address starts with your `https://pub-…r2.dev` URL. Trigger one
   more deploy on Render → the cover still shows.
6. [ ] Re-upload covers added before this change (they were on the wiped disk).

Never paste the R2 keys in chat or commit them; they only go into Render.

---

## 4. Quick checks after the deploy

- [x] **Download my data:** https://bookly-api-zasc.onrender.com/docs → **Sign in as demo customer** → **Customer →
  GET /me/export → Execute** → 200 and a JSON file.
- [x] **404 answer:** `GET /books/999999` in the docs → `{"message": "Not found."}` (no `App\Models\…`).
- [ ] **Staff/admin testing** (only you): docs → **Details → Test as staff or admin**.
- [ ] **Speed check of the live site** (after merging `feature/load-test-kit`, k6 installed: `winget install k6 --source winget`):
  `k6 run -e BASE_URL=https://bookly-api-zasc.onrender.com/api/v1 load/live-check.js` → about 5 minutes → look for
  `RESULT: PASS`, then paste the report into `load/RESULTS.md` ("Live check"). How to read it: `load/README.md`.

---

## 4b. Staging (when you want it, about 45 minutes)

- [ ] After merging the two staging branches: follow `docs/STAGING.md` step by step (staging branches, Neon project
  `bookly-staging`, Render `bookly-api-staging`, demo data, Vercel Preview variables).
- [ ] **Important:** keep `bookly-api-staging` **suspended** when you are not testing. Render's free hours are shared,
  and the live API uses almost all of them; running out stops the live shop too.

---

## 4c. Try the phone code on the live site (after both `feature/telegram-phone-code` branches are deployed)

- [x] Done 2026-11-02 (phone confirmed in Sign-in & security, code sign-in works). Sign in → **Sign in with phone number?** → your own number → **Send code in Telegram** → the Bookly bot sends
  a code → type it → you are signed in, and the bot says "Signed in … Not you?".
- [ ] Nothing arrives? Your number was linked before the bot stored chats: tap **Continue with Telegram** once,
  then codes work for it.

---

## 5. Decisions only you can make (reply with your choice)

- [x] **Buy again** (answered 2026-11-02): cancelled orders: yes (unchanged). Lower stock: **add what is left** (built:
  `feature/buy-again-partial-stock`, see section 0).
- [x] **Download my data** (answered 2026-11-02): keep staff-only notes and tags out (unchanged).
- [x] Typo-tolerant search: approved and built (no migration was needed after all).
- [x] Vitest 5: approved and done (in `chore/dependency-audit`).
- [x] Email worker: you chose the free way (emails after the answer, `feature/send-emails-after-response`). A paid
  worker is only needed later for heavy mail volume.
- [ ] **Old Neon project `bookly`:** after its quota resets, copy anything you need, then delete it (or keep it).

---

## 6. Accounts, keys and settings (when you are ready)

- [ ] **Online payment:** open an ABA PayWay or Bakong KHQR merchant account and get the API keys (then I build the flow).
- [ ] **Own domain** (e.g. `bookly.com`, `api.bookly.com`): buy it, add it in Render and Vercel; then update Google,
  Facebook, Turnstile and CORS to the new domain (I update `APP_URL` and links).
- [ ] **Email from your domain:** verify the domain in Brevo/Resend (SPF, DKIM, DMARC records) so codes don't land in spam.
- [ ] **Neon usage:** check compute hours once a week (Neon → Monitoring); upgrade before real customers.
- [ ] **Facebook sign-in:** put the Facebook app in Live mode, then set `VITE_FACEBOOK_APP_ID` on Vercel and Render.
- [ ] **Healthchecks.io** for the missed-backup alert, if not done (`docs/DEPLOYMENT.md` → "Missed-backup check").
- [ ] **Staging server** (optional): a second Render service + a Neon branch, to try changes before customers do.

---

## 7. Checks on the live site (from the roadmap)

- [ ] A native Khmer speaker reviews `bookly_frontend/src/i18n/km.ts`.
- [ ] Delivery note on a test order; staff note edit; close + reopen a test customer; Khmer switch on every page.
- [ ] Print one invoice and one packing slip on a real printer (only "Save as PDF" was tested).
- [ ] Shop details on printed sheets (`bookly_frontend/src/content/shop.ts`): update the email and address once you
  have the shop email and domain.

---

## 8. Optional

- [ ] Install REA on your own computer: `npx rea-agents setup` (choose your agent, review, approve).

Full list of what is done and what is left: `docs/PRODUCTION_CHECKLIST.md`.
