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

Differences from the Railway setup: no queue worker (`QUEUE_CONNECTION=sync`, emails are sent during the request — if
Resend is down, that request fails), no scheduler (expired tokens are still rejected, they just stay in the table),
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
3. **Advanced → Health Check Path:** `/api/v1/health`.
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
| `CACHE_STORE` | `database` |
| `SESSION_DRIVER` | `array` |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` |
| `CORS_ALLOWED_ORIGINS` | your frontend URL |
| `LOG_REQUESTS` | `true` |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | see "Email on Render" below |
| optional | `SENTRY_LARAVEL_DSN`, `GOOGLE_CLIENT_ID/SECRET`, `FACEBOOK_CLIENT_ID/SECRET` |

Save → Render builds the image (a few minutes) and deploys. Check `https://<your-app>.onrender.com/api/v1/health`
→ `{"status":"ok",...}` and `/docs`.

### Email on Render
Render's free plan blocks outgoing SMTP on ports 25, 465 and 587, and Resend without a verified domain only delivers to
the Resend account owner. Working free setup: Brevo SMTP on port 2525 with a verified single sender —
`MAIL_MAILER=smtp`, `MAIL_HOST=smtp-relay.brevo.com`, `MAIL_PORT=2525`, `MAIL_USERNAME`/`MAIL_PASSWORD` = Brevo SMTP
login and key, `MAIL_FROM_ADDRESS` = the verified sender. With a verified domain, Resend (`MAIL_MAILER=resend`,
`RESEND_API_KEY`) works too. If sending fails, the request answers 503 "We couldn't send the email just now…" and
sign-up keeps no half-made account.

### F4. Keep it awake (optional)
Free uptime monitors such as UptimeRobot can call `/api/v1/health` every 10 minutes so visitors rarely hit a sleeping
instance. Render's free plan includes a limited number of instance hours per month; one always-on service fits.

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
| check health | `GET /api/v1/health` — add it to a free uptime monitor (e.g. UptimeRobot) |
| change a setting | edit `.railway/railway.ts`, `railway config plan`, `railway config apply` (or a shared variable in the dashboard) |
| roll back | **web → Deployments →** an older deployment **→ Redeploy** (migrations are not rolled back) |
| use a custom domain later | add it in **web → Settings → Networking**, then update `APP_URL` and the frontend's API URL |

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Deployment stuck on "Waiting for CI" | the GitHub Actions run for that commit failed or is still running — open the Actions tab |
| Pre-deploy failed | a migration error; the old version is still serving. Read the deploy logs, fix, push again |
| Health check fails with `down` | `DB_URL` not set (re-run `railway config apply`) or the database is still starting |
| Health says `degraded` | queued jobs waiting > 10 minutes: the `worker` service is crashed or stopped |
| CORS errors in the browser | add the exact frontend origin (scheme + host, no trailing slash) to `CORS_ALLOWED_ORIGINS` |
| No emails arrive | without a verified Resend domain only your own address receives mail; check `worker` logs and Resend → Logs |
| Every visitor shares one rate limit | `TRUSTED_PROXIES` must be `REMOTE_ADDR` (set by the file); never `*` |
| `MissingAppKeyException` | shared variable `APP_KEY` missing or not `base64:` + 44 characters |
