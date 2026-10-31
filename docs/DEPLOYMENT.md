# Deploying Bookly API

Two ways to put the API online; both use the same Docker image and nothing secret is ever committed to git:

- **Free:** Render (API) + Neon (database) — see the next section. Sleeps when idle, no worker/scheduler.
- **Paid:** Railway Hobby (~$5/month) with web + queue worker + scheduler + PostgreSQL — the rest of this guide.

**What runs where**

| Railway resource | What it is | Start | Notes |
| --- | --- | --- | --- |
| `postgres` | PostgreSQL 18 database | Railway template | private network only |
| `web` | the API (`CONTAINER_ROLE=web`) | FrankenPHP on `$PORT` | runs `php artisan migrate --force` before each deploy, health check `/api/v1/health` |
| `worker` | queue worker (`CONTAINER_ROLE=worker`) | `queue:work` | sends verification/reset emails and low-stock alerts |
| `scheduler` | scheduler (`CONTAINER_ROLE=scheduler`) | `schedule:work` | daily expired-token cleanup |

All three services are built from the same `Dockerfile`. They are described in `.railway/railway.ts`.

---

## Free option: Render + Neon (no credit card)

Use this when a paid Railway plan is not an option. Same Docker image, two free services:

| Part | Service | Free tier limits |
| --- | --- | --- |
| API (`web` role) | [Render](https://render.com) free web service | sleeps after ~15 min without traffic; the next request takes 30-60 s to wake it |
| Database | [Neon](https://neon.tech) free PostgreSQL | 0.5 GB, does not expire (Render's own free database is deleted after 30 days) |

Differences from the Railway setup: no queue worker (`QUEUE_CONNECTION=sync`: one-time codes and invitations are sent
during the request, so if Resend is down that request answers "try again"; order, status and security emails are sent
right after the answer, so a mail outage never fails them — `QUEUE_AFTER_RESPONSE_CONNECTION`, default `deferred`), no scheduler (the housekeeping jobs run from web requests instead, see "Housekeeping without a scheduler" below),
and migrations run when the container starts (`RUN_MIGRATIONS=true`) because the free plan has no pre-deploy step
and no shell — admin and demo data are created from your own computer (steps F5-F6).

### F1. Neon database
1. Sign up at neon.tech (GitHub login) → **New project** → name `bookly`, Postgres 17 or newer, region **AWS Asia Pacific (Singapore)**.
2. **Connect** → turn **Connection pooling off** (direct connection) → copy the connection string:
   `postgresql://<user>:<password>@ep-xxxx.ap-southeast-1.aws.neon.tech/neondb?sslmode=require`

### F2. Render web service
1. Sign up at render.com (GitHub login), allow access to `danishsary8/bookly_backend_v2`.
2. **New → Web Service** → pick the repository → branch `main` → **Language/Runtime: Docker** (Render finds the
   `Dockerfile`) → region **Singapore** → instance type **Free**.
3. **Advanced → Health Check Path:** `/up` (answers without touching the database, so Render's frequent checks let Neon sleep).
4. **Auto-Deploy:** choose the option that waits for CI checks to pass (otherwise "On Commit").

### F3. Environment variables (Render → your service → Environment)

| Key | Value |
| --- | --- |
| `APP_KEY` | `php artisan key:generate --show` (keep a copy) |
| `APP_URL` | your Render URL, e.g. `https://bookly-api.onrender.com` (shown after the first deploy) |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | the Neon connection string from F1 |
| `RUN_MIGRATIONS` | `true` |
| `QUEUE_CONNECTION` | `sync` |
| `RUN_MAINTENANCE_ON_REQUEST` | `true` (runs the housekeeping jobs from web traffic; see "Housekeeping without a scheduler") |
| `CACHE_STORE` | `file` (rate limits and housekeeping state stay on the instance, so normal API requests do not wake Neon; cleared on each restart, which is harmless) |
| `SESSION_DRIVER` | `array` |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` |
| `CORS_ALLOWED_ORIGINS` | your frontend URL |
| `CORS_ALLOWED_ORIGIN_PATTERNS` | optional comma-separated regexes without delimiters for previews, e.g. `^https://bookly-frontend-[a-z0-9-]+-danishsary8s-projects\.vercel\.app$`; leave empty to allow only the exact origin list |
| `LOG_REQUESTS` | `true` |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | see "Email on Render" below |
| optional | `SENTRY_LARAVEL_DSN`, `GOOGLE_CLIENT_ID/SECRET`, `FACEBOOK_CLIENT_ID/SECRET`, `TURNSTILE_SECRET_KEY` (bot check on sign-up, sign-in and code emails; set it together with the frontend's `VITE_TURNSTILE_SITE_KEY`. The **Sign in as demo customer** button on `/docs` keeps working with it set: it uses `POST /demo/token`, which only ever signs in the protected demo account), `TELEGRAM_BOT_TOKEN` (the free Bookly Telegram bot: phone sign-in and phone confirmation; see "Telegram bot" below), `MARKETING_DAILY_LIMIT` (default 60; Resend free allows 100/day total shared with sign-in codes, so 60 leaves 40 for urgent sign-in codes and order confirmations. Sign-in codes and order emails never go through this marketing outbox) |

Sentry's release tag comes automatically from Render's `RENDER_GIT_COMMIT`; `SENTRY_RELEASE` overrides it.

Save → Render builds the image (a few minutes) and deploys. Check `https://<your-app>.onrender.com/api/v1/health`
→ `{"status":"ok",...}` and `/docs`.

### Telegram bot (free)
Customers confirm their phone number, and sign in, by tapping "Share my phone number" in the Bookly bot. Telegram's
Bot API is free; nothing to top up.
1. In Telegram, open **@BotFather** → **/newbot** → a name (e.g. `Bookly`) → a username ending in `bot`
   (e.g. `BooklyKhBot`). BotFather answers with the **token**: copy it (never paste it in a chat or an issue).
2. Optional, in @BotFather: **/setuserpic** (the Bookly logo), **/setdescription** ("Sign in to Bookly and confirm your
   phone number."), **/setabouttext**.
3. Render → service → **Environment** → add `TELEGRAM_BOT_TOKEN` = the token → **Save, rebuild and deploy**.
   `APP_URL` must be the API's own https address: at every start the container runs `php artisan telegram:webhook`,
   which tells Telegram to send the bot's messages to `APP_URL/api/v1/telegram/webhook` (the deploy log shows
   "Telegram bot @… now sends its messages to …").
4. Check: on the website, Sign in → **Continue with Telegram** opens the bot; **Start** shows the
   "Share my phone number" button.

### Owner alerts on Telegram (new orders, low stock, low reviews)
A message from the separate **Bookly Alerts** bot (the same bot as the backup-failure alert) to your private chat for
every new order, whenever a sale pushes a book format to its low-stock threshold, and for every 1-2 star review
(also when an edit brings a review down to 1-2 stars). It is sent after the customer's
response, and a failed message is only logged, so it never blocks or slows an order. Off while either setting is empty.
1. Render → service → **Environment** → add `OPS_ALERT_BOT_TOKEN` = the Bookly Alerts bot's token (the same value as the
   GitHub secret used by the backup alert) and `OPS_ALERT_CHAT_ID` = your private chat id (same as that secret too)
   → **Save, rebuild and deploy**. Never paste either value into chat, docs or code.
2. Check: place a test order on the website → the alert arrives within a few seconds. If not, Render's log shows
   "Ops alert was not delivered" with Telegram's reason (e.g. you never pressed **Start** in the Alerts bot).

### Staff Telegram group (new orders, low stock)
The same Bookly Alerts bot can post new orders (with the books, the delivery city and an **Open** link to the order in
the admin) and low stock to a Telegram group of your staff. Reviews stay in your private chat only. Off while
`OPS_STAFF_CHAT_ID` is empty; if it equals `OPS_ALERT_CHAT_ID` each message is sent once.
1. In Telegram: create a group (e.g. "Bookly Staff"), add your staff, then **Add member** → the Bookly Alerts bot.
2. Get the group's chat id without sharing it: in the group send any message, then open
   `https://api.telegram.org/bot<ALERTS_BOT_TOKEN>/getUpdates` in your own browser (replace `<ALERTS_BOT_TOKEN>` there,
   never in chat) and copy the `"chat":{"id":-100…}` number of the group (it starts with `-`). If the list is empty,
   send another message in the group and reload.
3. Render → service → **Environment** → add `OPS_STAFF_CHAT_ID` = that number, and `SHOP_URL` =
   `https://bookly-frontend-five.vercel.app` (for the **Open** links; optional) → **Save, rebuild and deploy**.
4. Check: place a test order → the group and your private chat each get one message. If the group gets nothing, Render's
   log shows "Ops alert was not delivered" with Telegram's reason (e.g. "chat not found": wrong id or the bot is not in
   the group).

### Housekeeping without a scheduler
Render's free plan runs only the web process, so `schedule:work` never runs. The recurring jobs are listed in
`app/Support/MaintenanceTasks.php` (expired API tokens daily, unfinished sign-ups hourly, closed accounts erased daily,
old failed jobs and job batches daily). With `RUN_MAINTENANCE_ON_REQUEST=true`, any API request checks (at most once a
minute, shared through the cache) whether a job is due and runs it **after the response has been prepared**, so visitors
do not wait. "Due" means a scheduled time has passed since the job last ran, so it catches up even though requests come at
random times. It only runs when real API traffic arrives (the uptime monitor pings `/up`, which skips it), and because the
cache is `file`, every job also runs once after an instance restart; they are cleanup jobs, so repeating them is harmless. Failures never change a
response: they are reported (Sentry) and the job is retried after 10 minutes. Leave the setting off wherever a real
scheduler runs (Railway). The first request after turning it on runs every job once to clear any backlog.

### Email on Render
Render's free plan blocks outgoing SMTP on ports 25, 465 and 587, and Resend without a verified domain only delivers to
the Resend account owner. Working free setup: Brevo SMTP on port 2525 with a verified single sender —
`MAIL_MAILER=smtp`, `MAIL_HOST=smtp-relay.brevo.com`, `MAIL_PORT=2525`, `MAIL_USERNAME`/`MAIL_PASSWORD` = Brevo SMTP
login and key, `MAIL_FROM_ADDRESS` = the verified sender. With a verified domain, Resend (`MAIL_MAILER=resend`,
`RESEND_API_KEY`) works too. If sending fails, the request answers 503 "We couldn't send the email just now…" and
sign-up keeps no half-made account.

### F4. Keep it awake (optional)
Free uptime monitors such as UptimeRobot can call `/up` every 14 minutes so visitors rarely hit a sleeping instance
(Render sleeps after 15 idle minutes). Do **not** ping `/api/v1/health` on a schedule: it queries the database, so Neon
(which suspends after ~5 idle minutes) never sleeps and the free compute quota runs out (seen 2026-10-22). Open
`/api/v1/health` by hand when you want the database checked. Render's free plan includes a limited number of instance hours per month; one always-on service fits.

### F5. First admin (from your computer, against Neon)
PowerShell in the project folder — the variables only last for this terminal window:
```powershell
$env:DB_URL = "postgresql://...neon connection string..."
php artisan config:clear
php artisan staff:create-admin
```
Close the terminal afterwards (or `Remove-Item Env:DB_URL`) so your local commands use your local database again.

### F6. Demo data (optional, once, from your computer)
```powershell
$env:DB_URL = "postgresql://...neon connection string..."
$env:APP_ENV = "production"
$env:DEMO_SEED_ALLOWED = "true"
php artisan config:clear
php artisan db:seed --class=DemoSeeder --force
```
Save the staff password it prints once. Then close the terminal.

### F6b. Book cover images: Cloudflare R2 (free, 10 GB)
Render's own disk is wiped at every deploy and restart, so uploaded covers must live elsewhere. R2 is Cloudflare's
S3-compatible storage: 10 GB free, no download fees.
1. Sign in at https://dash.cloudflare.com (the same account as Turnstile) → **R2 Object Storage** → **Create bucket**,
   name `bookly-media`, location **Automatic** → **Create**. (First time: R2 asks you to accept the free plan; it may ask
   for a card but the free tier is not charged.)
2. In the bucket → **Settings** → **Public access** → **R2.dev subdomain** → **Allow**. Copy the public URL
   (looks like `https://pub-xxxxxxxx.r2.dev`). Later you can connect your own domain here instead.
3. Back on **R2 Object Storage** → **Manage API tokens** → **Create API token**: permission **Object Read & Write**,
   **Apply to specific buckets only** → `bookly-media` → **Create**. Copy the **Access Key ID**, the **Secret Access Key**
   (shown once) and the **S3 endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. Render → your service → **Environment** → add:

| Key | Value |
| --- | --- |
| `MEDIA_DISK` | `r2` |
| `R2_ACCESS_KEY_ID` | the Access Key ID |
| `R2_SECRET_ACCESS_KEY` | the Secret Access Key |
| `R2_BUCKET` | `bookly-media` |
| `R2_ENDPOINT` | the S3 endpoint, without the bucket name |
| `R2_PUBLIC_URL` | the public URL from step 2, no trailing slash |

5. **Save Changes** (Render redeploys). Check: in the admin, upload a cover for a book; the returned `url` starts with
   your `R2_PUBLIC_URL` and the picture still shows after the next deploy. Covers uploaded **before** this change were on
   the wiped disk: upload them again.

### F7. Backups
Neon keeps a restore window (point-in-time restore) on the free plan; for your own copy use `pg_dump` with the Neon
connection string, as in section 10 below.

---

# Paid option: Railway (web + worker + scheduler)

## 1. Accounts you need

| Account | Needed? | What you copy from it |
| --- | --- | --- |
| [Railway](https://railway.com) (sign in with GitHub) | yes | — |
| [Resend](https://resend.com) | yes, for emails | an API key (`re_...`) |
| [Sentry](https://sentry.io), project type "Laravel" | optional | the DSN (`https://...@...ingest.sentry.io/...`) |
| Google Cloud OAuth client / Facebook app | optional | client id + secret (social login stays off until set) |

> **Resend without a domain:** you can send only from `onboarding@resend.dev` and only **to your own Resend
> account email**. That is enough to test. For real customers, add a domain in Resend (Domains → Add, then
> the DNS records it shows) and use e.g. `no-reply@yourdomain.com` as `MAIL_FROM_ADDRESS`.

## 2. Install the Railway CLI and log in (your computer)

```bash
npm install -g @railway/cli      # or: brew install railway
railway login
```

## 3. Create the project

In the Railway dashboard: **New Project → Empty Project**, name it `bookly`. Then, in the repository folder:

```bash
cd bookly_backend_v2
npm install                      # installs the Railway TypeScript SDK used by .railway/railway.ts
railway link                     # choose the bookly project and the production environment
```

## 4. Add the shared variables (secrets)

Railway dashboard → project **bookly** → **Settings → Shared Variables** (production). Add:

| Name | Value |
| --- | --- |
| `APP_KEY` | run `php artisan key:generate --show` locally and paste the whole `base64:...` value |
| `APP_URL` | for now `https://example.com`; you replace it in step 6 |
| `CORS_ALLOWED_ORIGINS` | your frontend URL, e.g. `https://bookly-frontend.vercel.app` (comma separated for more) |
| `RESEND_API_KEY` | `re_...` |
| `MAIL_FROM_ADDRESS` | `onboarding@resend.dev` (or your verified domain address) |
| `SENTRY_LARAVEL_DSN` | the DSN, or leave empty |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | optional |
| `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` | optional |

Keep a copy of `APP_KEY` in your password manager: changing it later logs everyone out and makes encrypted values unreadable.

## 5. Create the database and services from the file

```bash
railway config plan              # preview: should say 4 to add (postgres, web, worker, scheduler)
railway config apply             # type "yes" when asked
```

Railway asks to connect GitHub the first time: allow access to `danishsary8/bookly_backend_v2`.
The first build takes a few minutes. Watch **web → Deployments**: the pre-deploy step runs the migrations,
then the health check must pass before the deployment is marked active.

## 6. Give the API a public address

1. **web → Settings → Networking → Generate Domain** (keep the port Railway suggests; the app listens on the `PORT` Railway injects). You get e.g. `bookly-web-production.up.railway.app`.
2. Shared variable `APP_URL` → `https://bookly-web-production.up.railway.app` (all services redeploy).
3. Check: open `https://<your-domain>/api/v1/health` → `{"status":"ok",...}` and `https://<your-domain>/docs`.

Worker and scheduler need no domain.

## 7. Deploy automatically from `main`, only when CI is green

For each of `web`, `worker` and `scheduler`: **Settings → Source**:

- Branch: `main` (already set by the file).
- Turn on **Wait for CI**. Railway then waits until the GitHub Actions run of that commit has finished and
  only deploys when it passed (tests, code style and the Docker smoke test).

From now on: open a pull request → CI runs → you merge → Railway deploys.

## 8. Create the first admin

```bash
railway ssh --service web        # opens a shell in the running web container
php artisan staff:create-admin   # asks for name, email and password
exit
```

Log in with `POST /api/v1/staff/auth/login`; the response asks you to set up 2FA with an authenticator app.
Staff tokens last 12 hours (`STAFF_TOKEN_HOURS`), customer tokens 7 days.

## 9. (Optional) Fill the shop with demo data, once

```bash
railway ssh --service web
php artisan config:clear         # the container caches config at start; the flag below must be read fresh
DEMO_SEED_ALLOWED=true php artisan db:seed --class=DemoSeeder --force
php artisan config:cache
exit
```

It prints a random password for `admin@bookly.test` and `staff@bookly.test` **once** — save it. The demo customer
`demo@bookly.test` uses the public password `Password123!` so visitors can try the shop. The seeder refuses to run
twice and refuses without `DEMO_SEED_ALLOWED=true`.

## 10. Backups

**Automatic:** **postgres → Backups** → enable the **Daily** schedule (kept 6 days; add Weekly for a month).
To restore: find the backup by date → **Restore**. Railway stages it as a new volume (the old one is kept, unmounted)
→ review the staged change → **Deploy**.

**Manual copy to your computer** (before risky changes):

```bash
# postgres → Variables → copy DATABASE_PUBLIC_URL (the public TCP proxy address)
pg_dump --format=custom --no-owner "postgresql://...DATABASE_PUBLIC_URL..." > bookly-$(date +%F).dump
```

**Restore a dump** (overwrites tables; stop the worker and scheduler first):

```bash
pg_restore --clean --if-exists --no-owner --dbname "postgresql://...DATABASE_PUBLIC_URL..." bookly-2026-10-01.dump
```

Use `pg_dump`/`pg_restore` version 18 or newer (same as the server).

## 11. Every day

| I want to... | Do this |
| --- | --- |
| see logs | **web → Logs** (or `railway logs --service web`); every line is JSON with a `request_id` — search for the `X-Request-Id` a user reports |
| see errors | Sentry (5xx only, user id only, no personal data) |
| check health | `GET /up` in the uptime monitor (no database); `GET /api/v1/health` by hand to check the database |
| change a setting | edit `.railway/railway.ts`, `railway config plan`, `railway config apply` (or a shared variable in the dashboard) |
| roll back | **web → Deployments →** an older deployment **→ Redeploy** (migrations are not rolled back) |
| use a custom domain later | add it in **web → Settings → Networking**, then update `APP_URL` and the frontend's API URL |

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| The website says "Telegram isn't available right now" | `TELEGRAM_BOT_TOKEN` is empty, or Telegram refused it: Sentry (and the Telegram alert) shows `Telegram bot getMe failed: Unauthorized` → copy the token again from @BotFather → /mybots → API Token. |
| The bot doesn't answer "Start" | Telegram isn't delivering the bot's messages to the API. The API checks this whenever a customer taps a Telegram button (at most every 10 minutes) and sets its own address if Telegram has another one (log: "Telegram bot webhook was … now …"), so first just try again. If Telegram reports a delivery error, Sentry (and the Telegram alert) says `Telegram couldn't deliver the bot's messages to …: <reason>` with a hint. While the free API is asleep the first answer can take ~30 s (Telegram retries). |
| `/health` shows `telegram_bot: "webhook not set"` | Telegram has no address (or another one) for the shop bot, so the bot answers nobody. The health check repairs this itself (Render and the uptime monitor call it all the time): the answer shows `"repaired": true` once and `ok` on the next check; the log shows "Telegram bot webhook was not set; now …". The bot's address is always `APP_URL` + `/api/v1/telegram/webhook` (never the request's host: behind Render's proxy the app sees an internal host name), so `APP_URL` must be exactly `https://bookly-api-zasc.onrender.com`. Something outside the API removed it (seen 2026-10-13: getWebhookInfo showed `url: ""`): never run `deleteWebhook`/`getUpdates` tools or a local copy of the API with the shop bot's token; use the separate Alerts bot for experiments. |
| Deployment stuck on "Waiting for CI" | the GitHub Actions run for that commit failed or is still running — open the Actions tab |
| Pre-deploy failed | a migration error; the old version is still serving. Read the deploy logs, fix, push again |
| Health check fails with `down` | `DB_URL` not set (re-run `railway config apply`) or the database is still starting |
| Health says `degraded` | queued jobs waiting > 10 minutes: the `worker` service is crashed or stopped |
| CORS errors in the browser | add the exact frontend origin (scheme + host, no trailing slash) to `CORS_ALLOWED_ORIGINS` |
| No emails arrive | without a verified Resend domain only your own address receives mail; check `worker` logs and Resend → Logs |
| Every visitor shares one rate limit | `TRUSTED_PROXIES` must be `REMOTE_ADDR` (set by the file); never `*` |
| `MissingAppKeyException` | shared variable `APP_KEY` missing or not `base64:` + 44 characters |

---

## Nightly database backup

A GitHub Actions workflow (`.github/workflows/db-backup.yml`) runs every night at 02:00 Cambodia time (19:00 UTC).
It dumps the production database with `pg_dump`, encrypts the dump **to your public key** with
[age](https://age-encryption.org) and uploads the `bookly.dump.age` file as a GitHub Actions artifact kept for 30 days.
Only the encrypted file is stored; the plain dump is deleted immediately after encryption.

Why a public key (security review 2026-10-15): the repository is public, so **anyone signed in to GitHub can download
the artifacts**. With a public key, nothing stored on GitHub can open a backup; only your private key file, kept offline,
can. Until the `BACKUP_AGE_RECIPIENT` variable is set, the workflow falls back to the old `BACKUP_PASSPHRASE`
(`bookly.dump.gpg`, with a warning in the run).

### 1. Add the two repository secrets

GitHub → your repository → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret name | Value |
| --- | --- |
| `BACKUP_DATABASE_URL` | The Neon **direct** connection string (not the pooler). In Neon: **Connect** → turn **Connection pooling off** → copy the `postgresql://…` string. |
| `BACKUP_PASSPHRASE` | Old fallback only (see 1b). Delete it 30 days after the first `.age` backup, when the last `.gpg` backup has expired. |

### 1b. Make your backup key (once, on your computer)

1. Install age: Windows `winget install FiloSottile.age`, macOS `brew install age`, Ubuntu `sudo apt install age`.
2. Make the key pair:
   ```bash
   age-keygen -o bookly-backup-key.txt
   ```
   It prints `Public key: age1…`. The file `bookly-backup-key.txt` is the **private key**: keep it in **two offline
   places** (e.g. a USB stick and your password manager). Never commit it, paste it in chat or upload it to GitHub.
   Without it no backup can be opened.
3. GitHub → your repository → **Settings → Secrets and variables → Actions → Variables** tab → **New repository
   variable**: name `BACKUP_AGE_RECIPIENT`, value the `age1…` public key (a public key is not a secret).
4. Run the workflow once by hand (step 2) and check that the artifact holds `bookly.dump.age`.

### 2. Run once by hand

GitHub → **Actions** → **Nightly database backup** (left sidebar) → **Run workflow** → **Run workflow** (green button).
Watch the run; the last step uploads `bookly-db-YYYY-MM-DD`. After that the schedule takes over automatically.

### 3. Restore from a backup

> **Always restore into a new Neon branch first — never straight over production.**
> Never paste connection strings or the passphrase in chat or screenshots.

1. GitHub → **Actions** → pick the backup run → download the `bookly-db-YYYY-MM-DD` artifact (a zip containing
   `bookly.dump.age`, or `bookly.dump.gpg` for backups made before the key was set).
2. Decrypt (use `-o`, not PowerShell `>` redirection, which corrupts binary output):
   ```bash
   age -d -i bookly-backup-key.txt -o bookly.dump bookly.dump.age
   ```
   An older `.gpg` backup: `gpg -o bookly.dump -d bookly.dump.gpg` and enter the `BACKUP_PASSPHRASE` (on Windows,
   [Gpg4win](https://www.gpg4win.org/) or `gpg` from Git for Windows).
3. In Neon: **Branches → New Branch** from the current production branch (this gives you a safe copy to test on).
4. Restore into the new branch:
   A new Neon branch already contains production tables, so `--clean` and `--if-exists` are needed:
   ```bash
   pg_restore --clean --if-exists --no-owner --no-privileges -d "$RESTORE_URL" bookly.dump
   ```
   (Replace `$RESTORE_URL` with the new Neon branch's direct connection string).
5. Compare row counts between the test branch and production (e.g. check `books`, `orders`, `customers`; the temporary `cache` table may differ by a few rows).
6. Clean up: delete the unencrypted `bookly.dump` file from your machine, and delete the temporary test branch in Neon once verified. (If you ever need a real production restore, repeat step 4 against the production connection string, or promote the restored branch in Neon).

---

## Backup failure alert

When a nightly backup fails (or is skipped because `BACKUP_DATABASE_URL` is missing), GitHub Actions sends a Telegram message to your private chat from a dedicated "Bookly Alerts" bot. Successful backups stay silent.

### 1. Create the alert bot in Telegram (free)

1. In Telegram, open **@BotFather** → send `/newbot`.
2. Give it a name (e.g. `Bookly Alerts`) and a username ending in `bot` (e.g. `BooklyAlertsBot`).
3. BotFather replies with the **bot token** (e.g. `123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ`). Copy it.
4. Open your new bot in Telegram and press **Start** (bots cannot message you until you start the conversation).

### 2. Get your numeric Telegram chat ID

1. In Telegram, message **@userinfobot** (or send `/start`).
2. It replies with your account info, including your numeric **Id** (e.g. `123456789`). Copy this number.

### 3. Add the two repository secrets

GitHub → your repository → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret name | Value |
| --- | --- |
| `TELEGRAM_ALERT_BOT_TOKEN` | The bot token from BotFather |
| `TELEGRAM_ALERT_CHAT_ID` | Your numeric chat ID from @userinfobot |

### 4. Test the alert

1. GitHub → **Actions** → **Nightly database backup** (left sidebar) → **Run workflow**.
2. Tick **test_alert** (checkbox).
3. Click **Run workflow** (green button).

The workflow finishes in a few seconds without dumping or uploading anything, and sends a test message to your Telegram chat:
`Bookly backup alert test: this message means alerts work.`

If the test or a real alert fails, open the run and read the red line `Telegram rejected the alert (HTTP ...)`. Common reasons:
- `chat not found`: wrong chat ID, or **Start** was not pressed in the Alerts bot conversation.
- `Unauthorized`: wrong bot token.

## Missed-backup check (Healthchecks.io, free)

The failure alert above only fires when the backup workflow runs. If it never starts (GitHub pauses scheduled
workflows in a public repository after 60 days without activity, or a schedule is skipped), nobody would know. After
every successful backup the workflow now "checks in" with Healthchecks.io; if a check-in is late, Healthchecks.io
alerts you. Off while the `BACKUP_HEALTHCHECK_URL` secret is empty.

1. Sign up free at https://healthchecks.io (email or GitHub).
2. **Add Check** → name `Bookly nightly backup` → **Period** `1 day`, **Grace** `3 hours` → **Save**. (Cron mode also
   works: `0 19 * * *`, time zone UTC.)
3. Copy the check's **ping URL** (`https://hc-ping.com/…`). Treat it like a password: never paste it in chat or docs.
4. GitHub → backend repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret** →
   Name `BACKUP_HEALTHCHECK_URL`, Secret = the ping URL → **Add secret**.
5. Alerts: Healthchecks.io emails you by default. For Telegram: **Integrations** → **Telegram** → follow its link to
   add their bot to your chat.
6. Test: GitHub → **Actions** → **Nightly database backup** → **Run workflow** (leave **test_alert** unticked). When it
   is green, the check in Healthchecks.io turns **up** (green) with a "last ping" time.

