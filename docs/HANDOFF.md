# Hand-over notes

## How to review
1. Read this file from the top. Each task below lists its branches, checks and open points.
2. For every branch listed, open its PR link and check it against the reviewer checklist in `docs/prompts/README.md`.
3. Write what you find under a **Review** heading in that task's section (pass, or the problem and the file/line).
4. Owner-only steps (merge, settings, live checks) are in `docs/OWNER_TODO.md` on this same branch.

---

## 2026-10-31 · Task F: phone sign-in code hint

**Why:** the API only sends a phone sign-in code to a number that is confirmed and linked to the Bookly Telegram bot
(backend `PhoneCodeController::reachable`: `phone_e164` + `phone_verified_at` + `telegram_chat_id`). A number typed in
Account → Profile is only a courier contact and is not confirmed, so people (the owner too) got no code.

**Branches**
- Frontend `fix/phone-code-hint`: https://github.com/danishsary8/bookly_frontend/pull/new/fix/phone-code-hint
  (commits `ccc4e65` "Explain how to get a phone sign-in code", `d78dc9d` "Log the phone sign-in code hint").
- Backend: no change.

**Status:** done (pushed, not merged).

**What changed**
- `src/features/auth/PhoneCodeSignIn.tsx`: hint under the phone field (`TextField` `hint`, so it is the field's
  accessible description). After the first "Send code", the number is re-checked on every key press, so a fixed number
  clears the error and brings the hint back without pressing Send again.
- `src/pages/account/ProfilePage.tsx`: hint under the phone field with a link to `/account/security`.
- `src/i18n/en.ts`, `src/i18n/km.ts`: `signin.phoneCodeHint`, `account.phoneHint`, `account.phoneHintLink`, `account.phoneHintEnd`.
  - EN login: "Codes only go to a confirmed number. Not confirmed yet? Use Continue with Telegram below, or confirm it in
    Account → Sign-in & security → Phone → Confirm."
  - KM login: "លេខកូដផ្ញើទៅតែលេខដែលបានបញ្ជាក់ប៉ុណ្ណោះ។ មិនទាន់បញ្ជាក់? សូមប្រើ «បន្តជាមួយ Telegram» ខាងក្រោម ឬបញ្ជាក់វានៅ គណនី → ការចូល និងសុវត្ថិភាព → លេខទូរស័ព្ទ → បញ្ជាក់។"
  - EN Profile: "Couriers call this number. To sign in with it, it must be confirmed in Sign-in & security."
  - KM Profile: "អ្នកដឹកជញ្ជូនទូរស័ព្ទមកលេខនេះ។ ដើម្បីចូលគណនីដោយលេខនេះ វាត្រូវតែបានបញ្ជាក់នៅក្នុង ការចូល និងសុវត្ថិភាព។"
- Tests: 4 new (`src/pages/auth/phone-code-sign-in.test.tsx`: hint EN, hint KM, error clears once the number is fixed
  with no request sent; `src/pages/account/account-pages.test.tsx`: Profile hint + link).

**Tests run**
- `npm run lint`: 0 errors · `npm run typecheck`: clean · `npm test`: 440 passed, 3 skipped, 0 failed · `npm run build`: ok.
- Browser (Playwright/Chromium, Vite :5173 + local API :8000, demo customer), EN and KM at 1280 px and 390 px, all pass:
  hint under the field; `123` → Send → error replaces the hint; typing `012 345 678` → error gone, hint back, no
  request sent; Profile hint shows and its link opens Sign-in & security; no page or hint overflow; no console errors.

**Not verified**
- The local API has no Telegram bot, which hides phone sign-in, so `/auth/options` was stubbed to `telegram: true` in the
  browser for the check. Not checked on the live site yet (owner step in `OWNER_TODO.md` section 0).
- A real code from the live bot (not part of this change).
- The Khmer wording has not been checked by a native speaker.

**Decisions the owner made**
- Wording A1 (hint under the field, always visible) + B1 (Profile hint with a link, always shown).
- B for the error: re-check the number on every key press after the first Send.
- No extra review file; this file starts with "How to review".

**Open questions:** none.

**Follow-ups found (not changed in this branch)**
- Profile, Khmer, 390 px: the account tab row is cut at the right edge ("អាសយដ្ឋា…"). It scrolls sideways, and it
  existed before this change.

**Review** (2026-10-31, project review; verdict: **pass, OK to merge**)
- Checklist: branch name and both commit messages are plain, no tool names, no Co-authored-by lines. Only the 7
  files the task needs changed (5 source/i18n, 2 tests) + `docs/WORKLOG.md`. No backend, no migrations, no secrets.
- Re-ran on the pushed branch (`d78dc9d`, clean install): `npm run lint` 0 errors, `npm run typecheck` clean,
  `npm test` 440 passed / 3 skipped, `npm run build` ok. Matches the report above.
- Code: reuses `TextField`'s `hint` prop, so the hint is the field's accessible description and the error replaces it
  like every other field. Re-check after the first Send is small and correct (`phoneChecked` + `checkPhone`); the test
  proves no request is sent while typing. Profile link copies the email hint's pattern. Comment updated.
- Nit (no change needed now): the new Khmer login hint writes "លេខទូរស័ព្ទ" while the field label above it writes
  "លេខទូរសព្ទ". The app already mixes both spellings; leave it for the native-speaker review (owner step) and fix all
  of them together then.
- Nit: the work log went into a second commit; fine, no need to squash.
- Not verifiable here: the live check (owner step, section 0) and a real bot code.


**Where I stopped / next step**
- Task F is finished and pushed. Next: the owner merges `fix/phone-code-hint`, does the live check in `OWNER_TODO.md`
  section 0, then confirms the phone in Sign-in & security (section 4c). Next task prompt: `docs/prompts/02-you-may-also-like.md`.

---
## 2026-10-31 · Task A: "You may also like" (similar books), PRODUCTION_CHECKLIST.md 4.1
**Branches**
- Backend `feature/better-similar-books` (pushed): https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/better-similar-books
- Frontend `feature/better-similar-books` (pushed): https://github.com/danishsary8/bookly_frontend/pull/new/feature/better-similar-books
**Status:** done (both branches pushed, not merged). Backend approved by the project lead. (Details at the end.)

**What already exists (do not rebuild)**
- Routes (`routes/api.php`): `GET /books/{id}/related` and `GET /books/{id}/also-bought` (same method,
  `BookController::alsoBought` calls `related`), `GET /catalog/recommendations?cart_ids=&exclude_ids=&limit=`
  (note: the path is `/catalog/...`, not `/books/recommendations`). `limit` 1 to 12, default 6.
- Logic: `app/Services/Catalog/RecommendationService.php`.
  - Book page (`getRecommendedIdsForBook`, cached 1 h, key `books:{id}:recommendations:{limit}`):
    1. co-purchases in >= 2 shared paid orders (not pending/cancelled, not soft-deleted), most shared first;
    2. same category AND same author (no ORDER BY, so the database picks the order);
    3. same categories, by units sold, then rating, then newest id;
    4. same authors, by rating, then newest id;
    nothing after that. Always: not the book itself, no duplicates, visible + buyable (`baseBuyableQuery`: an
    active variant with stock > 0, or an ebook/audiobook).
  - Cart (`getRecommendedIdsForCart`, not cached): same categories by units sold, then same authors (newest),
    then store best sellers (`queryPopularBookIds`). Empty cart = store best sellers.
  - Controller `related()` re-loads with `catalogQuery()` (visible only) but does NOT re-check stock.
- Tests: `tests/Feature/Catalog/RelatedBooksTest.php` (5), `tests/Feature/Platform/AlsoBoughtTest.php` (3).
- Frontend: `src/pages/catalog/BookDetailPage.tsx` rows in order: series (`otherInSeries`, from `/series/{id}`),
  "More by {author}" (`otherByAuthor`, up to 8), also-bought (`alsoBought`, ~line 553, eyebrow
  `book.alsoBoughtEyebrow` "Customers also bought", title `book.alsoBought` "Readers also enjoyed", no `loading`
  prop), recently viewed. `src/pages/cart/CartPage.tsx` `CartRecommendations` (`cartPage.recommendedEyebrow`,
  `recommendedWithOrder` / `popularPicks`). Shared row: `src/components/catalog/BookShelf.tsx` (hides itself when
  loaded and empty; skeleton when `loading`). API calls: `src/api/endpoints/catalog.ts`. No frontend test covers
  these rows.

**Audit results on the local demo data (20 books, 10 paid orders)**
- No book pair was bought together twice, so step 1 (co-purchases) never fires: every result is a topic match,
  yet the row says "Customers also bought".
- Book 5 *The Sign of the Four* (Sherlock Holmes #2) → 4, 6, 1, 2, 3, 16. Holmes books appear only because they
  share the author, and they appear in three rows (series, author, also-bought).
- Book 18 *Meditations* → only 1 book (19). No fallback on the book page.
- Cart with book 4 (Holmes #1) → 6, 1, 2, 3, 16, 15. The next book in the series (5) is missing.
- Cache is never cleared; a sold-out book can show for up to 1 h; edits to categories/authors take up to 1 h.
- Khmer strings exist but are marked `// draft`. 390 px browser check not done yet (shared `BookShelf`, 2 cards wide).

**Decisions the owner made (2026-10-31): 1A 2A 3A 4B, with changes**
1. **1A Series first** (book page AND cart): later books in the series first (by `series_order`), then earlier ones;
   step 2 (same category + author) gets a real sort (by sales).
2. **2A Frontend**: remove books already shown in the series or author rows; ask for `limit=12` so enough stay;
   rename the row to eyebrow "More like this", title "You may also like" (+ Khmer); add the loading skeleton;
   **hide the row if fewer than 2 books are left** after removing repeats. Must still work against today's API.
3. **3A changed by the owner**: fill the rest of the book-page row with best sellers from the SAME CATEGORY first,
   then store best sellers. Never the book itself, no duplicates, in stock only.
4. **4B Cache**: cache 12 ids; clear a book's cached list when staff edit the book (version number in the cache
   key, works with the `file` store). **Stock re-check on every read**, so a sold-out book never shows, even when cached.

**Planned steps (one per "confirm")**
- Backend `feature/better-similar-books` from fresh `origin/main`:
  - Step 2: fix 1 (series next-first, then earlier; cart too). Tests: series next-first then earlier; never the book
    itself; no duplicates.
  - Step 3: fix 3 (fallback same-category best sellers, then store best sellers). Test: fallback order.
  - Step 4: fix 4 (cache 12 ids, re-check stock on read, version key bumped on staff book edit). Tests: sold-out
    excluded even when cached; staff edit clears the cache.
  - Then `php artisan test` + `vendor/bin/pint --test`; update `docs/API.md` + `public/openapi.yaml` descriptions
    of the order/behaviour; WORKLOG entry; push.
- Frontend `feature/better-similar-books` from fresh `origin/main`: step 5: fix 2. Then lint, typecheck, test,
  build; browser check: a book in a series (e.g. id 5), a book without one (e.g. id 18 or 1), the cart; desktop +
  390 px; English + Khmer. WORKLOG entry; push.
- Finally: update this section and `docs/OWNER_TODO.md` (merge links, live check).

**Later decisions the owner made (during steps 2 to 4)**
- Series-first matters most in the CART; keep the book page part simple. Cart: never a cart book; with 2 series,
  the next book of each first; out-of-stock rule still applies.
- 3A order fixed as: series > bought together > category+author > same categories > same authors > same-category
  best sellers > store best sellers, filled up to `limit` (frontend will ask 12). Note: "same categories" is
  already sorted by units sold, so it IS the same-category best-seller step; no duplicate step was added.
- 4B changed: ONE catalogue-wide version number in the cache key (not per book), bumped when staff create, edit,
  delete or restore a book or change its authors/categories/series, so other books' lists update too.
- Sales rule = option B: rankings ("best sellers", "bought together") leave out pending, cancelled, **returned**
  and deleted orders; processing and shipped (cash on delivery, not paid yet) still count.
- The "within 1 hour" limits below are accepted; no fix now.

**Backend progress (branch `feature/better-similar-books`)**
| Commit | What |
|---|---|
| `eb682dd` | Series first: book page step 0 (next books, then earlier, then unnumbered); cart: per series after the furthest cart book, series take turns; category+author step sorted by units sold. Tests: `tests/Feature/Catalog/SeriesRecommendationsTest.php` |
| `ff52d01` | Store best sellers fill the book-page list up to `limit`. Pushed with one old test failing (my mistake: commit ran in the same command as the tests). |
| `8a62bd0` | Fixes that test: `AlsoBoughtTest` now checks the exact order (B bought together first, D with only cancelled/pending co-orders last as a filler). |
| `73d891a` | Cache: 12 ids per book under `catalog:recommendations-version`; `RecommendationService::catalogChanged()` bumps it after commit (Book `created/updated/deleted/restored` events + explicit calls after author/category sync in staff `BookController::update`, `BookBulkController`, `BookCsvImport::apply`); stock + visibility re-checked on every read, then cut to `limit`. One `unitsSoldSubquery()` helper for all rankings. Tests: `tests/Feature/Catalog/RecommendationCacheTest.php` |
| `9e5b041` | `docs/API.md` (+ new row for `/catalog/recommendations`) and `public/openapi.yaml`: final order, caching, stock rule |
| `f6e26e9` | Returned orders left out of rankings (`notSales()`); test + docs line updated |
- Checks on `f6e26e9`: `php artisan test` 642 passed, `vendor/bin/pint --test` clean. Each new rule test was run
  against the old code first and failed there (proves it tests something).
- Demo data (local API): *Sign of the Four* → 6, 4, 1, 2, 3, 16; *Meditations* `limit=12` → 12 books (was 1);
  cart with Holmes #1 → 5, 6, … (next book first).

**Known limits (accepted by the owner, no fix now)**
These do not bump the catalogue version, so the change reaches cached lists within 1 hour instead of at once.
None of them can show a wrong book (stock and visibility are re-checked on every read); a book only joins late.
- A book comes back into stock, or a new format (variant) is added or re-activated.
- A scheduled book goes live at its publish time (no save happens then).
- A category or author is deleted (pivot rows go without a book event).
- After a re-check removes sold-out or hidden books, a cached list can be shorter than `limit` until it is rebuilt.

- `00e8612`: backend `docs/WORKLOG.md` entry. **Review** (project lead, 2026-10-31): 642 tests + pint re-run on
  `00e8612`, **approved**.

**Frontend progress (branch `feature/better-similar-books`, commit `a0a8597`)**
- `src/pages/catalog/BookDetailPage.tsx`: the old "Customers also bought" row (`/also-bought`, 6 books) is replaced by
  `SimilarBooks`: `GET /books/{id}/related?limit=12` (exists on today's API too), minus the current book and every book
  the series row and the "More by" row show. Recently viewed is NOT removed (it is history, not a suggestion; removing it
  would empty the row for people who browse a lot; the owner allowed skipping it).
- Loading: the shelf skeleton shows while the list, the book, the series row or the author row is loading (the repeats
  can only be removed once those are known). Loaded with fewer than 2 books left → renders nothing (no heading, no gap).
  Errors behave like before: the row just does not show.
- Strings: `book.similarEyebrow` "More like this" / "សៀវភៅស្រដៀងគ្នា", `book.similarTitle` "You may also like" /
  "អ្នកប្រហែលជាចូលចិត្ត" (same Khmer as the cart's existing "You may also like"). Old `alsoBought*` keys and their
  `// draft` mark removed. `catalogApi.alsoBought` is left in `catalog.ts` (unused now; harmless).
- Tests (`src/pages/catalog/catalog-pages.test.tsx`, 4 new): asks 12 + repeats removed (series/author/self); row hidden
  with < 2 left; skeleton while loading, then nothing when empty; fewer than 12 shown in the API's order. A default
  `related` mock was added to the older detail tests.
- Checks: `npm run lint` 0, `npm run typecheck` clean, `npm test` 444 passed / 3 skipped, `npm run build` ok.
- Browser (Playwright/Chromium, Vite :5173, demo data, EN + KM, 1280 + 390 px), two runs:
  - Local API on the **new backend branch**: Holmes #2 → series row 4, 6; author row 6, 4; "You may also like"
    1, 2, 3, 16, 15, 14, 13, 11, 10, 20 (no repeats, not itself). Meditations → 12 books. Cart (demo customer,
    Holmes #1) → 5, 6, 1, 2, 3, 16. Khmer eyebrow + title show. No horizontal overflow anywhere.
  - Local API on **backend `main`** (= today's live API code): Holmes #2 same row, no repeats; Meditations → only 1
    book left, so the row is hidden (no heading, no gap); cart → 6, 1, 2, … (old order). So the frontend works alone.
  - Console: only cover images from an external photo host failing (no internet in this sandbox); no app errors.

**Review** (project lead, 2026-10-31; verdict: **pass, both branches OK to merge, any order**)
- Frontend `a0a8597` re-run on a clean install: lint 0, typecheck clean, 444 passed / 3 skipped, build ok. Diff is the 5
  files the task needs (page, en/km strings, tests, work log). Commit messages plain, no tool names.
- Backend `00e8612` re-run: 642 tests, pint clean. Cache version bumps only after commit; checked that orders and
  checkout never save `Book`, so normal sales do not clear the cache.
- Good calls: recently viewed left alone; frontend works on today's API (Meditations row hidden, no gap).
- Nit (later, tiny): `catalogApi.alsoBought` is now unused in `catalog.ts`; remove in a future cleanup.
**Not verified:** the live site (Render + Vercel) in a browser (owner step in `OWNER_TODO.md` section 0); the Khmer
eyebrow by a native speaker.
**Open questions:** is "សៀវភៅស្រដៀងគ្នា" a natural Khmer eyebrow for "More like this"? (owner step)
**Where I stopped / next step**
- Task A is finished on both branches, pushed, not merged. Merge order: any.
  Backend https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/better-similar-books ·
  Frontend https://github.com/danishsary8/bookly_frontend/pull/new/feature/better-similar-books
- Next: the owner merges both and does the live check in `OWNER_TODO.md` section 0 (Task A). Next task prompt:
  `docs/prompts/03-offers-and-reports.md`.

---

## 2026-10-31 · Task B+D: offers + offer reports, PRODUCTION_CHECKLIST.md 4.1

**Status:** partly done (design stage). No code, no task branch yet.
- Step 1 (read only, done): audit of coupons, cart, checkout totals, free delivery and reports. Findings: one discount
  kind today (coupon, on the whole subtotal, cents, `CheckoutService::totals()` is the one place preview and checkout
  share); `GET /cart` has no pricing or free-delivery numbers; `/cart/coupon/check` works on the plain subtotal; the
  order keeps `coupon_id` + `discount_amount` but nothing would say which offer gave a discount; the refund share in
  `ReturnService::refundCents` and the sales report "Discounts" already use `discount_amount`.
- Step 2 (design proposal): sent to the owner, waiting for answers. Spec file not written yet (written once agreed).

**Owner decisions so far (2026-10-31)**
- v1 small: "Buy N, get X% off" first; asked whether "complete the series" is cheap enough to add.
- No stacking: the customer automatically gets the better of offer vs coupon; cart and checkout say which one was
  used; `/cart/coupon/check` uses the same comparison.
- The offer discount counts like a coupon for free delivery (subtotal minus discount), with a test.
- `GET /cart` gains one pricing block (discount, applied offer, nudge, free-delivery threshold + amount needed from the
  API), so the cart stops using the fixed $50 from `src/content/shop.ts`.
- Data: smallest change, NEW migration only: `offers` + nullable `orders.offer_id` + a small snapshot on the order.
  Exact migration to be shown before writing it.
- Only admins create/edit/pause offers; staff can view.
- Reports: per offer orders, units, revenue, discount given, AOV vs orders without offer, date range, CSV, new tab.

**Separate tiny task (not part of offers v1): free-delivery bar gaps found in Step 1**
1. Cart page and cart drawer use the fixed $50 from `src/content/shop.ts` (`GET /cart` sends no threshold); only
   checkout uses the API's `free_shipping_threshold`. **Offers v1 fixes this one** (pricing block on `GET /cart`).
2. The cart drawer (`CartDrawer.tsx:78`) passes the subtotal before the coupon; the cart page and checkout pass the
   amount after the discount.
3. `FreeShippingBar.tsx` English and Khmer texts are written inside the component, not in `src/i18n/en.ts` / `km.ts`.
4. Not checked: whether the bar shows for an ebook-only cart (ebook-only orders have no delivery fee, so it should not).

**Owner answers on the Step 2 proposal (2026-10-31):** Q1-Q9 all A (see the spec, section 2). Migrations OK with
additions: `order_items.discount_amount` (line discount for offers and coupons; returns use it, old orders keep the
proportional rule), offer row lock + use given back on cancel + race test, exact discount rule with worked examples
(all covered units once quantity >= N, rounded down per line, ebooks count), "No books" status when all targets are gone.

**Spec:** `docs/superpowers/specs/2026-10-31-offers-design.md` on branch `feature/offers-engine` (commit `770e25f`)
https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/offers-engine
- Tests not run: the commit is one Markdown file only.
- Open question O1 (in the spec): what checkout does when the offer ran out after the preview (recommend A: optional
  `offer_id` in checkout, 422 if it no longer applies).

**Where I stopped / next step**
- Waiting for the owner's review of the spec and an answer to O1. No migration or code written yet.
- Next after OK: build `feature/offers-engine` in small steps (migrations first), each step tested and pushed.

### Review of the offers spec (project lead, 2026-10-31; spec `770e25f` on `feature/offers-engine`)
Verdict: **approved with changes**. Cents math in examples 1-3 re-checked by hand (150+150; 100/100/300; 300+119): correct.
Migration dates sort after the last one on `main` (`2026_10_17_*`). O1 = A.
Changes asked before building:
1. Partial return of an offer book: keep each line's own discount (no claw-back when the remaining books fall under N).
   Write it in 4.8. The floor in the per-unit share favours the customer by at most 1 cent; say so.
2. Cancel: guard `offer_id` null (offer deleted, SET NULL) before `decrement`.
3. Book list `offer` field: no N+1 (categories/series already loaded or eager-loaded); add a query-count test.
4. Demo offer in `DemoSeeder`: create it **paused** (`is_active = false`), so seeding never starts a live discount on the
   real shop.
5. Rollout: until an admin creates a live offer nothing changes for customers. Note in OWNER_TODO: merge
   `offers-storefront` before creating the first live offer (today's checkout page hides a coupon that lost to an offer).

### Build progress (branch `feature/offers-engine`)
https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/offers-engine
| Commit | What |
|---|---|
| `933b6dc` | Spec updated with the review: 4.8 no claw-back + rounding (customer gets at most 1 cent more per line), 4.9 skip the decrement when `offer_id` is null + O1 = A rule, 6.6 no N+1 + query-count test, 6.8 demo offer paused + rollout order. OWNER_TODO rollout note: `011afa9` on this branch. |
| `d1b47f8` | Migrations `2026_10_31_100001` to `100004`: `offers`, `offer_targets`, 4 offer columns + index on `orders`, `order_items.discount_amount` (default 0). Test `tests/Feature/Offers/OfferSchemaTest.php` (6): CHECK limits and edges, one-target rule, unique target, cascade on hard delete of a book and of the offer, SET NULL keeps the order's copy, line discount default 0.00. |
- Checks on `d1b47f8`: `migrate:fresh` on the test DB, then `migrate:rollback --step=4` and `migrate` again (all
  DONE); `php artisan test` 648 passed; `vendor/bin/pint --test` clean. No model, service or endpoint yet, so no
  `docs/API.md` / `openapi.yaml` change in this step.
- Note: books and series soft delete, so the cascade on `offer_targets` only runs on a hard delete. A target whose book
  is in the trash simply matches nothing (the book is not for sale). The "No books" status must treat it the same way.

**Where I stopped / next step**
- Migrations done and pushed. Waiting for the owner's `confirm`.
- Next step: `Offer` + `OfferTarget` models, `OfferService` with the discount rule (spec 4.1-4.3, 4.6) and unit tests for
  examples 1, 3, 5 (no cart, checkout or endpoint change yet).
