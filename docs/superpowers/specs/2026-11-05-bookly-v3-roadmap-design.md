# Bookly v3 roadmap (design)

Owner-approved 2026-11-05. Each phase below gets its own short design, plan and branch(es) before code.
Rules stay the same: small steps, owner merges, no tool names in branches/commits, Khmer text left to Antigravity,
everything must stay on free hosting unless the owner says otherwise.

## Goal

Make Bookly a complete, secure, premium bookshop for Cambodia that sells every kind of book (self-help, personal
growth, adult non-fiction, English learning such as Oxford English File, Khmer high-school books, Khmer fiction,
Marvel/comics, manga, kids), with protected ebooks, strong analytics, support chat and a built-in assistant. It is
the owner's career showcase project, so quality, security and polish come before speed.

## Order of work

| Phase | What | Why first/last |
|---|---|---|
| 0 | Security review of the whole project | Everything new sits on a safe base |
| 1 | Category tree + search filters + sort | Base for selling every book type |
| 2 | Ebooks: protected web reader, library, Look Inside, ebook hub | Biggest feature, strongest CV piece |
| 3 | Series volumes (manga/comics) | Needed for manga and comics |
| 4 | Pre-order + backorder | Sell before release / when out of stock |
| 5 | Shop extras: auto rows, cart shipping estimate, shareable lists, marketing wording | Conversion |
| 6 | Ratings: order/delivery stars, support stars, store rating badge | Trust |
| 7 | Support chat (site bubble, staff reply in admin or Telegram) | Contact and reports |
| 8 | Bookly Assistant (customer) + Admin Assistant | Own, free, no third-party AI |
| 9 | Analytics dashboard v2 + printable reports + staff tools | Run the business |
| 10 | Gift cards + refer a friend | Money features, after the security review |
| 11 | Brand-new UI redesign of every page | Last, when every feature exists |

## Phase 0 — Security review

Review and fix, with tests, across backend and frontend:
- Sign-in: brute force, account enumeration, token lifetime/rotation, password reset, social and Telegram login,
  phone codes, 2FA for staff, session list.
- Authorization: every route checked for the right role/ability; customers can only see their own data (IDOR).
- Input/output: validation, mass assignment, SQL, XSS (rich text, gift/delivery notes, chat), file uploads.
- Payments: KHQR amount/currency checks, idempotency, replay, webhooks.
- Secrets and config: env only, debug off, CORS, CSP, security headers, rate limits, logs without personal data.
- Dependencies: composer/npm audit.
Output: findings list for the owner (severity, fix), then fix branches.

## Phase 1 — Categories, filters, sort

- Category tree with parents: Self-help, Personal growth, Non-fiction (history, business, science…), English
  learning (by publisher/level), Khmer school books (Grade 7–12, by subject), Khmer fiction, Fiction (genres),
  Comics, Manga, Kids (0–3, 3–7, 8–12), Young adult. Admin manages the tree.
- Book fields: language (km/en/both), age range, school grade, level (A1–C2) where useful.
- Filters: format, language, category, age/grade, price range, in stock, ebook available. Sort: newest, best
  selling, price low/high, rating. Mega menu data from the API.

## Phase 2 — Ebooks

- Delivery: read in the browser only, no file download. Pages are served in small encrypted chunks with short-lived
  signed links; the full file never reaches the browser.
- Protection: visible and hidden watermark (buyer email + order), copy/select/print/right-click blocked in the
  reader, max 3 devices per customer (manage in account), rate limits on page requests, audit log.
- Reader: table of contents, page/scroll mode, font size, light/dark/sepia, highlights and notes, search inside,
  progress sync across devices, Khmer font support. Installable as a phone app (PWA); offline reading later.
- Library: "My ebooks" shelf, continue reading.
- Look Inside: first ~10% free, no account needed; also for print books when a preview file exists.
- Ebook hub page: new, deals, free classics, samples.
- Rules: no refunds except a broken file; ebooks bought in the normal cart; gifting later via gift cards.
- Books without an ebook show print formats only; admin can upload an ebook file later per book.

## Phase 3 — Series volumes

Series with numbered volumes, volume picker on the book page, "next volume" button, "complete the set" offer.

## Phase 4 — Pre-order and backorder

- Pre-order: release date on the format; order now (KHQR now or COD), ships on release; customer emails on release
  or delay; cancel before shipping.
- Backorder: order when out of stock with a clear longer wait; staff list of backorders to buy.

## Phase 5 — Shop extras and marketing

- Auto rows: best sellers (real sales), new arrivals, coming soon, ebook deals; "View all" + load more.
- Cart: delivery fee estimate and "free delivery from $50" progress.
- Shareable reading lists and staff-pick lists.
- Marketing wording and psychology, honest only: charm prices ($9.99), real low-stock badges ("Only 3 left"),
  "Limited" for real limited offers, bundle offers, social proof ("120 sold", star average), better button and
  section wording. No fake scarcity or fake numbers.

## Phase 6 — Ratings

- Book reviews with stars: already built (add "verified buyer" badge if missing).
- Rate the order/delivery (1–5 + comment) after delivery.
- Rate the support chat after it is closed.
- Store rating badge on the site (average + count) from order ratings.

## Phase 7 — Support chat

- Chat bubble on every page for signed-in customers (guests leave email). Topics: order, payment, ebook, other;
  attach an order. Messages saved in the database; customer sees replies later in Account.
- Staff inbox in the admin (open/pending/closed, assign, quick replies); new message alert to the staff Telegram
  group; staff can reply from Telegram and it appears in the chat.
- Safety: rate limits, text only (images later), XSS-safe, staff-only notes.

## Phase 8 — Assistants (own, free, no third-party AI)

- Customer "Bookly Assistant": understands common questions in English and Khmer by matching intents and keywords,
  answers from our own data (order status, delivery fee, payment help, policies, FAQ) and searches books ("manga
  under $5"). Not sure → hands over to Support chat.
- Training = staff add Q&A pairs and synonyms in the admin; unanswered questions are listed so staff can add them.
- Admin Assistant: staff ask "sales this week?", "top 5 books?", "low stock?" and it answers from the dashboard
  data with links. Read-only, respects staff roles.
- Built so a real AI model can be plugged in later without rebuilding.

## Phase 9 — Analytics dashboard v2, reports, staff tools

Dashboard (books/orders version of the owner's sample), period 7D/30D/90D/12M + compare to previous period:
- KPI cards with trend and sparkline: revenue, orders, customers, books sold, average order, ebook sales.
- Revenue chart vs previous period; orders by status donut; payment methods chart (COD/KHQR); top books with
  progress bars; top categories; recent orders with quick confirm/ship; activity feed with "Needs attention" tab
  (new orders, returns, low stock, reviews, support chats).
- Analysis: conversion (visits → orders), repeat customers, cart reminders sent → recovered, coupon/offer results,
  Phnom Penh vs provinces.
- Reports: printable/PDF dashboard report and Excel export (Excel exists).
- Staff tools the admin lacks today (owner reviews each): staff activity log view, stock purchase list
  (backorders + low stock), customer notes/tags, bulk order printing improvements, daily summary in Telegram.

## Phase 10 — Gift cards and referral

- Digital gift cards: buy with KHQR, send by email or Telegram on a chosen date, redeem into store credit.
- Refer a friend: friend gets a discount on the first order, sharer gets store credit after delivery; abuse limits.

## Phase 11 — UI redesign

Brand-new, premium, modern, unique UI with good animation for every page (storefront and admin). Starts with a
design proposal (style, colours, type, motion, components) agreed with the owner, then page by page with
screenshots for approval. Done last, when every feature exists.

## Not doing

Choose-a-bookstore/affiliate model, sponsored titles, region switcher, stationery (unless the owner decides later).

## Costs

Everything runs on the current free plans. Possible later costs: own domain (~$10–15/year), Google Maps (free under
the cap), a paid AI model (only if the owner asks), bigger hosting when real traffic grows.
