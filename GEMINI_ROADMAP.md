# Bookly — Roadmap (state on 2026-11-05)

Two repos:
- **Backend** `danishsary8/bookly_backend_v2`: Laravel 13 + PHP 8.4 + PostgreSQL, REST API `/api/v1`, hosted on Render.
- **Frontend** `danishsary8/bookly_frontend`: React 19 + Vite 7 + TypeScript, hosted on Vercel.

Detailed history: backend branch `docs/handoff-notes` (`docs/WORKLOG.md`, `docs/OWNER_TODO.md`) and frontend
branch `docs/worklog-notes` (`docs/WORKLOG.md`). The approved product plan for v3 is
`docs/superpowers/specs/2026-11-05-bookly-v3-roadmap-design.md` on the backend `docs/handoff-notes` branch.

Legend: ✅ done · 🔄 in progress · 📋 to do · ⚠️ HUMAN (Danish only) · 🤖 AI (Antigravity) can do it

---

## ✅ Fully done (merged to `main` and live)

**Shop (customer)**
- Catalogue: books, authors, series, publishers, collections, new arrivals, search (incl. Khmer search and
  typo-tolerant search, Ctrl/⌘ K), book page with formats, reviews (verified purchase), similar books / also bought.
- Category tree (12 top-level categories, ~65 sub-categories), filters: category (incl. sub-categories), format,
  language, price, in stock, has ebook, reader age, school grade, English level; sorts incl. best selling and most
  reviewed; filter counts hide empty choices. (Phase 1 of v3, merged 2026-11-05.)
- Cart, offers (buy N save X%), coupons, free delivery threshold, checkout with cash on delivery or **Bakong KHQR
  (live, real payments)**, gift note at checkout, delivery note, optional map pin on addresses (hidden until a Google
  Maps key is set), order history, cancel, returns, buy again, invoices.
- Accounts: email + password (leaked-password check), Google, Facebook (testers only), Telegram and phone codes,
  email change, sessions list + sign out everywhere, new-device email, wishlist with price-drop badge and emails,
  back-in-stock alerts, cart reminder email (24 h, max 1/week, one-click stop), newsletter, download my data,
  close account. English and Khmer, light and dark, KHR/USD.
- Analytics: Vercel Web Analytics (privacy-safe).

**Admin / staff**
- Staff sign-in with 2FA, roles and abilities, members, audit log, login history, notifications.
- Books (create/edit/bulk/CSV import-export/trash/scheduled publishing/cover upload), formats & stock, categories
  (tree), authors, publishers, series, collections, offers, coupons, orders (bulk status, print invoice/packing slip,
  KHQR mark paid), returns, reviews moderation, customers (CRM notes/tags), newsletter campaigns, exchange rates,
  dashboard + insights, reports with Excel export, system health card, Telegram ops alerts.

**Quality**
- Backend ~820 tests, frontend ~556 tests, OpenAPI contract tests, e2e journeys, axe accessibility checks, CSP.
- Phase 0 security review (2026-11-05): no High/Medium findings.

## 🔄 In progress

- Nothing half-built in code. Phase 2 (ebooks) is next and **waits for Danish's design approval**.
- Khmer for the new `catalogFilters` group (frontend `src/i18n/km.ts`) is still English → 🤖 tonight (task A1).
- Overnight work by Antigravity on branch `feature/antigravity-overnight` in both repos (see `ANTIGRAVITY_PROMPT.md`).

## 📋 Remaining roadmap (v3, approved order)

| Phase | What | Who |
|---|---|---|
| 2 | Ebooks: protected browser reader (encrypted page chunks, watermark, copy/print blocked, max 3 devices), library, Look Inside preview, ebook hub | Design → ⚠️ Danish approves; then AI builds |
| 3 | Series volumes for manga/comics (volume picker, next volume, complete the set) | Design → ⚠️ approve; AI builds |
| 4 | Pre-order + backorder | Design → ⚠️ approve; AI builds |
| 5 | Shop extras: auto rows (best sellers, coming soon), cart delivery estimate, shareable lists, honest marketing wording, gift-with-purchase offer (free sticker/pen) | ⚠️ approve; AI builds |
| 6 | Ratings: order/delivery stars, support stars, store rating badge | ⚠️ approve; AI builds |
| 7 | Support chat (site bubble, staff reply in admin or Telegram) | ⚠️ approve; AI builds |
| 8 | Own assistants (customer + admin), rules/FAQ based, no third-party AI | ⚠️ approve; AI builds |
| 9 | Dashboard v2 (KPI cards, charts vs previous period, donut, top books, payments, activity), printable reports, staff tools | ⚠️ approve; AI builds |
| 10 | Gift cards + refer a friend | ⚠️ approve; AI builds |
| 11 | Brand-new premium UI redesign of every page (last) | ⚠️ Danish leads the style decisions |

## ⚠️ HUMAN — Danish does these (AI must never do or fake them)

- Merge pull requests (AI never merges, never pushes to `main`).
- Approve every design/spec before code for phases 2–11.
- Anything in Render, Vercel, Neon, Google Cloud, Meta/Facebook, Bakong, Brevo/email, Telegram BotFather, domain DNS.
- Secrets and keys (BAKONG_TOKEN, app secrets, API keys). AI never asks for, writes or commits them.
- Check `APP_DEBUG=false` on Render. Renew the Bakong token before **1 Feb 2027**.
- Google Maps key setup (postponed): restricted key, 300/day quota, $1 budget alert, `VITE_GOOGLE_MAPS_KEY` in Vercel.
- Enable Vercel Analytics if not done; own domain purchase (optional, costs money).
- Live checks on the real site (list in backend `docs/OWNER_TODO.md`, "YOUR GUIDE" section).
- Running any seeder or command against production.
- Final word on Khmer wording (AI may draft it).

## 🤖 AI only — Antigravity does these tonight (no Danish needed)

Full instructions: `ANTIGRAVITY_PROMPT.md` (backend repo root). All work goes on branch
`feature/antigravity-overnight` in each repo, one commit per task. Danish reviews and merges in the morning.

| ID | Task | Repo |
|---|---|---|
| A0 | Setup + baseline health check of both `main` branches | both |
| A1 | Khmer translation of the `catalogFilters` group | frontend |
| A2 | Book CSV import/export + bulk edit support the audience fields (age, grade, level) | backend (+ frontend help text) |
| A3 | Sample catalogue seeder: 120+ realistic books across every category with good stock (never auto-runs in production) | backend |
| A4 | Category page shows its sub-categories as links | frontend |
| A5 | Admin category dialog: icon picker (from the fixed icon list) | frontend |
| A6 | Book page: show audience info (age, grade, level) + link to the category; book cards: small level/grade tag | frontend |
| A7 | Draft designs (documents only) for phases 2 (ebooks), 3 (series volumes), 4 (pre-order/backorder) | backend docs |
| A8 | Docs refresh: README + API.md for everything added since 2026-11-04 | both |
| A9 | Final full check of both overnight branches + morning summary in PROGRESS.md | both |
