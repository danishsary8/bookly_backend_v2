# Deploying Bookly API to Railway

This guide puts the API online at a free `*.up.railway.app` address. It takes about 30 minutes the first time.
You create the accounts and paste the keys; nothing secret is ever committed to git.

**What runs where**

| Railway resource | What it is | Start | Notes |
| --- | --- | --- | --- |
| `postgres` | PostgreSQL 18 database | Railway template | private network only |
| `web` | the API (`CONTAINER_ROLE=web`) | FrankenPHP on `$PORT` | runs `php artisan migrate --force` before each deploy, health check `/api/v1/health` |
| `worker` | queue worker (`CONTAINER_ROLE=worker`) | `queue:work` | sends verification/reset emails and low-stock alerts |
| `scheduler` | scheduler (`CONTAINER_ROLE=scheduler`) | `schedule:work` | daily expired-token cleanup |

All three services are built from the same `Dockerfile`. They are described in `.railway/railway.ts`.

---

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
