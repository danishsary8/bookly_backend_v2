# Staging: try changes before customers see them

A second, private copy of Bookly that runs the same code and settings as the live shop, with **demo data only**.
You merge a feature into the `staging` branch first, check it on the staging site, and only then merge it into
`main` for customers. Everything below is free; the live site does not change.

| | Live (today) | Staging (this guide) |
| --- | --- | --- |
| Code | `main` branch | `staging` branch |
| API | Render `bookly-api` | Render `bookly-api-staging` |
| Database | Neon project `bookly-2` (real customers) | Neon project `bookly-staging` (demo data only) |
| Website | Vercel production (`main`) | Vercel preview of the `staging` branch, with a "Staging site" bar on every page |
| Emails | sent (Brevo) | **never sent**: written to the Render log (sign-in codes are read there) |
| Telegram bot, owner alerts | on | **off** |

Time needed: about 45 minutes, once.

---

## Before you start: Render's free hours

Render's free plan gives a monthly allowance of instance hours that **all your free services share** (750 hours at the
time of writing; check https://render.com/docs/free). The live API, kept awake by UptimeRobot, uses almost all of it
(about 720-744 hours a month). If the allowance runs out, Render stops your free services, **the live shop included**,
until the next month.

So the rule for staging: **keep it suspended when you are not testing.**
- Render → `bookly-api-staging` → **Settings** → **Suspend Web Service** when you finish.
- **Resume Web Service** when you want to test again (it starts in about a minute).
- Never add the staging address to UptimeRobot.
- Render → **Billing** (or your workspace's usage page) shows the hours used this month; look once in a while.

---

## 1. Create the `staging` branch in both repositories

For `bookly_backend_v2` **and** `bookly_frontend`:
1. GitHub → the repository → the branch menu (it shows `main`) → type `staging` → **Create branch staging from main**.

CI runs on `staging` too (after merging `feature/staging-setup-kit` and `feature/staging-banner`), so Render and
Vercel can wait for green tests there as well.

## 2. Neon: a separate project for staging

A separate project (not a branch of `bookly-2`) on purpose: it starts **empty** (no real customer data), has its own
free compute allowance (staging traffic never uses the live database's hours), and nothing you do in it can touch the
live data.

1. https://console.neon.tech → **New project** → name `bookly-staging`, the same Postgres version as `bookly-2`,
   region **AWS Asia Pacific (Singapore)**, compute size **0.25 CU** → **Create**.
   (If Neon says you have too many projects, first finish the old project `bookly`: section 5 of `docs/OWNER_TODO.md`.)
2. **Connect** → turn **Connection pooling off** → copy the connection string. This is the **staging** `DB_URL`;
   keep it apart from the live one (for example in a note named "STAGING").

## 3. Render: the staging API

1. https://dashboard.render.com → **New → Web Service** → `danishsary8/bookly_backend_v2` → branch **`staging`** →
   **Docker** → region **Singapore** → instance **Free** → name `bookly-api-staging`.
2. **Advanced → Health Check Path:** `/up`. **Auto-Deploy:** the option that waits for CI checks (as on the live service).
3. **Environment** → add these (the live service's list in `docs/DEPLOYMENT.md` F3, with the staging values):

| Key | Value on staging |
| --- | --- |
| `APP_ENV` | `production` (the same rules as the live shop: that is the point of staging) |
| `APP_DEBUG` | `false` |
| `APP_KEY` | a **new** key: `php artisan key:generate --show` (never the live one) |
| `APP_URL` | the staging address, e.g. `https://bookly-api-staging.onrender.com` (shown after the first deploy) |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | the **staging** connection string from step 2 |
| `RUN_MIGRATIONS` | `true` |
| `QUEUE_CONNECTION` | `sync` |
| `RUN_MAINTENANCE_ON_REQUEST` | `true` |
| `CACHE_STORE` | `file` |
| `SESSION_DRIVER` | `array` |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` |
| `LOG_REQUESTS` | `true` |
| `MAIL_MAILER` | `log` (emails are written to the Render log, never sent) |
| `LOG_LEVEL` | `debug` (without it the log leaves the emails out; checked 2026-10-31) |
| `MAIL_FROM_ADDRESS` | `staging@bookly.test` |
| `SHOP_URL` | the staging website address from step 5 (links inside emails) |
| `CORS_ALLOWED_ORIGINS` | the staging website address from step 5 |
| `CORS_ALLOWED_ORIGIN_PATTERNS` | `^https://bookly-frontend-[a-z0-9-]+-danishsary8s-projects\.vercel\.app$` (every Vercel preview) |
| `TURNSTILE_SECRET_KEY` | `1x0000000000000000000000000000000AA` (Cloudflare's test secret: the bot check always passes) |
| `SENTRY_LARAVEL_DSN` | optional: the same value as live, so staging errors reach Sentry... |
| `SENTRY_ENVIRONMENT` | ...labelled `staging` (filter them out in Sentry with *environment*) |

**Never copy these from the live service:**

| Key | Why not |
| --- | --- |
| `TELEGRAM_BOT_TOKEN` | the container points the bot at the API that starts, so the **live bot would start answering from staging** and phone sign-in on the live shop would break |
| `OPS_ALERT_BOT_TOKEN`, `OPS_ALERT_CHAT_ID`, `OPS_STAFF_CHAT_ID` | every test order would ping your real Telegram and the staff group |
| `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `RESEND_API_KEY` | staging must never email anyone; it also uses up the daily free email limit |
| `MEDIA_DISK`, `R2_*` | test covers would land in the live bucket |
| `GOOGLE_*`, `FACEBOOK_*` | those sign-ins only work on the live address |
| `DEMO_SEED_ALLOWED` | only for the one-off step 4, from your computer |

4. **Create Web Service.** The first deploy runs the migrations on the empty staging database (a few minutes).
   Check `https://bookly-api-staging.onrender.com/up` → green page, and `/docs`.

## 4. Demo data on staging (once, from your computer)

PowerShell in the backend folder, with the **staging** connection string (check it says `bookly-staging`'s host,
not the live one):
```powershell
$env:DB_URL = "postgresql://...STAGING connection string..."
$env:APP_ENV = "production"
$env:DEMO_SEED_ALLOWED = "true"
php artisan config:clear
php artisan db:seed --class=DemoSeeder --force
```
It prints the staff passwords **once**; save them as "staging staff". Close the terminal afterwards. Staff sign-in on
staging asks for 2FA like live: add a separate "Bookly staging" entry in your authenticator app.

## 5. Vercel: the staging website

1. https://vercel.com → `bookly-frontend` → **Settings → Environment Variables** → for the **Preview** environment
   (all preview branches, so every feature preview also uses staging instead of live data):

   | Key | Value |
   | --- | --- |
   | `VITE_API_BASE_URL` | `https://bookly-api-staging.onrender.com/api/v1` |
   | `VITE_APP_ENV` | `staging` (shows the "Staging site" bar and labels errors `staging`) |
   | `VITE_TURNSTILE_SITE_KEY` | `1x00000000000000000000AA` (Cloudflare's test key) |
   | `VITE_GOOGLE_CLIENT_ID`, `VITE_FACEBOOK_APP_ID` | leave **empty** for Preview (those buttons only work on the live address) |

   Leave the **Production** values as they are.
2. **Deployments** → open the latest `staging` deployment → **Redeploy** (environment variables only apply to new builds).
3. Its fixed address is shown under **Domains**, normally
   `https://bookly-frontend-git-staging-danishsary8s-projects.vercel.app`. Put it in `CORS_ALLOWED_ORIGINS` and
   `SHOP_URL` on `bookly-api-staging` (step 3) → **Save Changes**.
4. Open it: the "Staging site: test data only…" bar is at the top. Vercel previews ask you to sign in to Vercel
   first (Deployment Protection), so only you can open staging. Previews are also hidden from Google.

**Check it works:** sign in as `demo@bookly.test` / `Password123!` → add a book → place an order → in Render →
`bookly-api-staging` → **Logs**, the order email appears as text. Admin: `/admin` with the staging staff account.
To read a sign-in or sign-up code: Logs → search `code`.

Then **suspend** `bookly-api-staging` (see "Render's free hours" above).

---

## Every time: try a change on staging first

1. Resume `bookly-api-staging` on Render.
2. GitHub → **Pull requests → New** → **base: `staging`**, compare: the feature branch → **Create** → wait for green →
   **Merge**. Do the same in the other repository if the feature has a frontend part.
3. Wait for Render (Events: **Deploy live**) and Vercel (the `staging` deployment: **Ready**), then test on the
   staging website.
4. Happy → open the usual pull request into **`main`** and merge it. Not happy → tell me what is wrong; the live shop
   never saw it.
5. Suspend `bookly-api-staging`.

**Keep staging close to live:** after merging things into `main`, open a pull request **base: `staging`, compare:
`main`** and merge it, so staging has everything live has.

**Start staging over** (it holds a feature you dropped, or the data is messy):
- Code: GitHub → **Branches** → delete `staging` → create it again from `main` (step 1). Render and Vercel deploy the
  next push.
- Database: Neon → project **`bookly-staging`** (check the name!) → **SQL Editor** →
  `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` → Render → `bookly-api-staging` → **Manual Deploy** (the
  migrations rebuild the tables) → step 4 again for demo data.
