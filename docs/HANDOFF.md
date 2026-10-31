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
**Branches (planned, not created yet)**
- Backend `feature/better-similar-books`: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/better-similar-books
- Frontend `feature/better-similar-books`: https://github.com/danishsary8/bookly_frontend/pull/new/feature/better-similar-books
**Status:** partly done. Step 1 (read-only audit) finished; the owner chose the fixes. No code changed yet.

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

**Not verified yet:** everything after step 1. 390 px layout of the row in a browser.
**Open questions:** none.
**Where I stopped / next step**
- Step 1 done and decisions recorded. Next: step 2, create backend `feature/better-similar-books` from fresh
  `origin/main` and add "series first" to `RecommendationService` with its tests. Local API was started on :8000
  (`php artisan serve`) for the audit.
