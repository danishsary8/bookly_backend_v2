# Owner to-do (your part)

Everything that only you can do (merges, accounts, keys, decisions), in one place. Written 2026-10-30 (overnight).
Tick the boxes as you go. Nothing below breaks the live site if you have not done it yet: every new feature keeps
working with today's settings.

---

## 1. Merge (any order is fine)

All branches start from `main` and were checked together: every order merges cleanly and the combined code passes all
613 backend tests. For each link: **Create pull request** → wait for green CI → **Merge**.

**Backend** (`bookly_backend_v2`)
- [ ] `feature/r2-media-storage` — book covers can be stored on Cloudflare R2 (stays on today's disk until you do step 3)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/r2-media-storage
- [ ] `fix/symfony-mime-advisory` — security update (symfony/mime 7.4.22, CVE-2026-45070)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/symfony-mime-advisory
- [ ] `fix/demo-reset-keeps-going` — the hourly demo reset no longer stops at one bad order, and the order history
  says "Cancelled automatically" instead of "Cancelled by customer"
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/demo-reset-keeps-going
- [ ] `feature/customer-data-export` — new `GET /api/v1/me/export` ("Download my data")
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/customer-data-export
- [ ] `fix/docs-small-followups` — 404s no longer reveal internal class names; two docs fixes
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/docs-small-followups
- [ ] `fix/docs-stale-demo-token` — the docs page checks a saved demo token, so an expired one never shows "Signed in"
  https://github.com/danishsary8/bookly_backend_v2/pull/new/fix/docs-stale-demo-token
- [ ] `feature/typo-tolerant-search` — search finds books despite typos ("frankenstien", "sherlok", "jane austin");
  no migration needed (pg_trgm is already on)
  https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/typo-tolerant-search
- [ ] `docs/overnight-notes` — this file and the work log
  https://github.com/danishsary8/bookly_backend_v2/pull/new/docs/overnight-notes

**Frontend** (`bookly_frontend`)
- [ ] `chore/dependency-audit` — package security fixes + Vitest 5 (shipped code: 0 vulnerabilities; 22 left, all in
  developer/build tools, each needing its own major upgrade)
  https://github.com/danishsary8/bookly_frontend/pull/new/chore/dependency-audit
- [ ] `feature/typo-search-notice` — search results say "No exact match for … Showing books with a similar spelling"
  when the backend used typo matching (shows nothing until `feature/typo-tolerant-search` is merged; safe either order)
  https://github.com/danishsary8/bookly_frontend/pull/new/feature/typo-search-notice

After merging: Render and Vercel deploy by themselves. Wait for **Deploy live** on Render (Events tab).

---

## 2. Urgent (do first)

- [ ] **Point the nightly backup at the new database.** You moved to the Neon project `bookly-2`, but the backup still
  reads the old address. GitHub → `bookly_backend_v2` → **Settings → Secrets and variables → Actions** →
  `BACKUP_DATABASE_URL` → **Update** → paste the `bookly-2` connection string (Neon → `bookly-2` → **Connect**).
  Then **Actions → Nightly database backup → Run workflow** once and check it turns green.
- [ ] **Demo data on the new database** (needed by the docs page's demo button), if not done yet: `docs/DEPLOYMENT.md`
  → F6 (run `DemoSeeder` from your computer with the `bookly-2` `DB_URL`). Check: https://bookly-api-zasc.onrender.com/docs
  → **Sign in as demo customer** → no "demo account is not set up" message.

---

## 3. Cover images on Cloudflare R2 (after merging `feature/r2-media-storage`)

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

- [ ] **Download my data:** https://bookly-api-zasc.onrender.com/docs → **Sign in as demo customer** → **Customer →
  GET /me/export → Execute** → 200 and a JSON file.
- [ ] **404 answer:** `GET /books/999999` in the docs → `{"message": "Not found."}` (no `App\Models\…`).
- [ ] **Staff/admin testing** (only you): docs → **Details → Test as staff or admin**.

---

## 5. Decisions only you can make (reply with your choice)

- [ ] **Buy again:** may customers reorder a *cancelled* order? (today: yes) · When stock is lower than ordered, skip
  the book (today) or add what is left?
- [ ] **Download my data:** it leaves out staff-only notes and tags about the customer (internal remarks). Keep it that
  way, or include them?
- [x] Typo-tolerant search: approved and built (no migration was needed after all).
- [x] Vitest 5: approved and done (in `chore/dependency-audit`).
- [ ] **Email worker:** keep sending emails inside the request (free), or a paid Render worker (~$7/month)?
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
