# Prompt 2 (A): "You may also like": audit first, then fill the gaps

```text
TASK A: "You may also like" (similar books). PRODUCTION_CHECKLIST.md section 4.1.

IMPORTANT, a lot already exists. Do NOT rebuild it:
- Backend: GET /books/{id}/related and /books/{id}/also-bought (same code) and GET /books/recommendations?cart_ids=
  in BookController, logic in app/Services/Catalog/RecommendationService.php (cached 1 h): co-purchases (>= 2 shared
  orders), same category + author, same categories, same authors, in-stock and visible only. Cart: same categories
  by units sold, then best sellers.
- Frontend: BookDetailPage.tsx shows an "also bought" row (line ~553); CartPage.tsx has CartRecommendations.

Step 1 (read only, no branch yet): read those files and their tests, call both endpoints on the local API with the
demo data, and give me a short report in simple English: what the customer sees today on the book page and in the
cart, the exact ranking rules, and the gaps. Check at least these possible gaps:
  (a) same SERIES is not used (a series reader should see the next book first);
  (b) the book page row title/wording ("Customers also bought" vs "You may also like") when the list is mostly
      topic matches, not real co-purchases;
  (c) what happens with few books / brand-new book (empty row hidden? fallback to best sellers in the category?);
  (d) the cache is not cleared when stock or a book changes;
  (e) Khmer text, loading skeleton, 390 px layout.
Then propose a small fix list in priority order, each with options and your recommendation. STOP and wait.

Step 2+: only the fixes I approve, one at a time, each step ending with "confirm":
- Backend branch feature/better-similar-books; frontend branch feature/better-similar-books (only if the frontend
  changes). Each must work alone (frontend must still work against today's API).
- No migration expected. If one seems needed, ask me first.
- Backend: feature tests for every rule you add (series first, no duplicates, never the book itself, out-of-stock
  rule), php artisan test + pint. Update docs/API.md and public/openapi.yaml if the response or params change.
- Frontend: lint, typecheck, test, build, and a browser check of a book page (a book in a series, a book without
  series) and the cart, desktop + 390 px, English + Khmer.
- Finish: commit, push, WORKLOG entries, HANDOFF.md + OWNER_TODO.md (merge links, any check I must do) on
  docs/handoff-notes. Give me the PR links.
```
