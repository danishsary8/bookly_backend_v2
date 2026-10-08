# Moving the API from Render to Railway: plan

Status: **shelved by the owner (2026-10-08): the API stays on Render.** Kept for reference only. Written 2026-10-07 after reading this
repo's `Dockerfile`, `docker/start.sh`, `.railway/railway.ts`, `routes/console.php`, `docs/DEPLOYMENT.md`, and Railway's,
Neon's and Brevo's current documentation. ROADMAP Phase 2, first item.

## Why move

| Today (Render free) | After (Railway) |
| --- | --- |
| The API sleeps after 15 min idle: the first visitor waits 30–50 s | Always on: every page answers at once |
| No scheduler: clean-ups run "at most hourly" when someone opens a page | A real scheduler: unfinished sign-ups (hourly), closed accounts (daily), expired tokens (daily) |
| No queue worker (`QUEUE_CONNECTION=sync`): order emails and alerts are sent while the customer waits | A worker sends them in the background |
| SMTP on port 2525 works (Brevo) | **SMTP is blocked on Railway's Free, Trial and Hobby plans** → email must use Brevo's HTTP API |

## What runs where

```
 Vercel (website, unchanged)          Railway project "bookly" (region: Singapore, closest to Cambodia)
 ───────────────────────────          ─────────────────────────────────────────────────────────────────
 bookly-frontend-five.vercel.app ───► web    FrankenPHP, /api/v1, health check, runs migrations before each deploy
                                      jobs   one container: queue worker + scheduler (restarts if either stops)
                                      redis  queue + cache (rate limits, code counters, pending phone/email)
                                         │
                                         ▼ (internet, TLS)
                                      Neon PostgreSQL (unchanged: same database, same data, its own backups)

 Email: Brevo HTTP API (port 443)   Telegram: Telegram Gateway (unchanged)   Errors: Sentry (unchanged)
```

Why these choices:
- **Keep Neon.** The data stays where it is (no copy, no downtime), Neon keeps its free backups/restore window, and
  Render and Railway can both run against it during the switch, so going back is one setting.
- **Redis for the queue and cache.** A queue worker on the *database* asks Postgres for new jobs every few seconds, so
  Neon would never sleep: about 180 compute-hours a month against the free plan's 100. With Redis, Neon only wakes
  for real requests. Redis on Railway is small (~30 MB).
- **One `jobs` container** instead of separate worker + scheduler: half the memory bill. Railway's built-in cron
  (minimum every 5 minutes, start time not exact) could skip an hourly task, so the scheduler stays a long-running
  `schedule:work`. If either process stops, the container exits and Railway restarts it.
- **Region Singapore**: the shortest round trip from Phnom Penh; Neon should be in the same region (check
  Neon → project → Settings; if it isn't, leave it: moving Neon regions is a separate, later job).

## Cost (Railway's published prices, checked 2026-10-07)

RAM $10 per GB a month, CPU $20 per vCPU a month, builds free. Rough steady use for this shop:

| Service | Memory | CPU (avg) | ≈ per month |
| --- | --- | --- | --- |
| web | ~150 MB | ~0.01 vCPU | $1.70 |
| jobs | ~100 MB | ~0.01 vCPU | $1.20 |
| redis | ~30 MB | ~0 | $0.30 |
| **total** | | | **≈ $3–4** (plus a few cents of traffic) |

| Railway plan | What you get | Fits? |
| --- | --- | --- |
| Free | $1 of usage a month, 0.5 GB per service | No: $3–4 needed |
| Trial | $5 once, then must upgrade | About 5–6 weeks, then upgrade |
| **Hobby** | $5 a month, **includes $5 of usage** | **Yes: the bill stays $5** |

Neon free, Vercel hobby, Brevo free (300 emails a day), Sentry free: $0. Telegram codes: ~$0.01 each (prepaid).

## Code changes (small branches, each tested; nothing on Railway yet)

1. **`feature/brevo-api-mail`**: send email through Brevo's HTTP API (`symfony/brevo-mailer`, registered as a
   `brevo` mailer; `MAIL_MAILER=brevo`, `BREVO_API_KEY`). SMTP stays available, so Render keeps working until the
   switch. Test: a mail goes out through a faked Brevo endpoint; the 503 "couldn't send the email" path still works.
2. **`feature/railway-jobs`**: `CONTAINER_ROLE=jobs` in `docker/start.sh` (queue worker + scheduler, exit when either
   stops); Redis support in the image (`predis/predis`, no PHP extension to compile); verification-code emails stay
   immediate (the customer is waiting and gets a clear error if it fails), everything else (order emails, notices,
   low-stock alerts) goes through the queue. CI smoke test starts the `jobs` role against Redis.
3. **`chore/railway-config`**: rewrite `.railway/railway.ts` for this plan (web + jobs + redis, no Postgres
   service, Neon's `DB_URL` and every key as shared variables: Brevo, Telegram, Turnstile, Google, Facebook, Sentry),
   region Singapore; `docs/DEPLOYMENT.md` Railway section rewritten as the owner's click-by-click guide.

## Owner steps (later, after the three branches are merged; click by click in DEPLOYMENT.md)

1. railway.com → sign in with GitHub → **Upgrade to Hobby** (card needed; $5/month, usage included).
2. Railway → **Account Settings → GitHub** → give the Railway app access to `bookly_backend_v2`.
3. Brevo → **SMTP & API → API Keys → Generate a new API key** → keep it for step 5 (never paste it in chat).
4. On your computer: install the Railway CLI, `railway login`, then in the backend folder `railway init`
   (project name `bookly`, region Singapore).
5. Railway → project → **Settings → Shared Variables**: add `APP_KEY` (same as Render's), `APP_URL`, `DB_URL`
   (Neon's connection string, same as Render's), `CORS_ALLOWED_ORIGINS`, `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`,
   `TELEGRAM_GATEWAY_TOKEN`, `TURNSTILE_SECRET_KEY`, `GOOGLE_CLIENT_ID`, `FACEBOOK_CLIENT_ID`,
   `FACEBOOK_CLIENT_SECRET`, `SENTRY_LARAVEL_DSN` (copy each value from Render → Environment).
6. `railway config plan` (read the list), then `railway config apply`.
7. Railway → web → **Settings → Networking → Generate Domain**; put that address in the `APP_URL` shared variable.
8. Each service → **Settings → Wait for CI** on.

## Switching over safely

1. Railway runs next to Render, both on the same Neon database (migrations are safe to run twice).
2. Full check against the Railway address (I run it with the owner): `/api/v1/health` ok; catalogue; sign-up with
   an email code (Brevo API) and with a Telegram code; sign-in with password, Google, phone; cart → checkout → order
   email arrives (from the worker); admin sign-in with 2FA, order status change, audit log; the scheduler ran
   (`schedule:list`, the hourly clean-up in the logs); a forced error reaches Sentry and Telegram.
3. Vercel → bookly-frontend → **Settings → Environment Variables** → `VITE_API_BASE_URL` = Railway address +
   `/api/v1` → **Redeploy** production. Google/Facebook/Turnstile need no change (they only know the website).
4. Watch for a day (Sentry, Railway logs and metrics). **Going back** = set `VITE_API_BASE_URL` to the Render address
   again and redeploy (2 minutes), because both run on the same database.
5. After a week without problems: Render → service → **Suspend** (keep it a while as a spare), then the
   roadmap's next items (uptime monitor on the Railway address, Neon restore drill).

## Risks

- **Card on file**: Hobby bills usage above $5. Set Railway → **Usage → Usage limit** to e.g. $10 so a bug can't
  run up a bill.
- **Neon cold start**: after 5 quiet minutes Neon sleeps; the first query then takes ~0.5–1 s (much better than
  Render's 30–50 s). Neon's paid plan or a Railway database removes this, if ever needed.
- **Telegram codes** and **Turnstile** must be set on Railway before the switch, or sign-up falls back to email
  codes / no bot check.
- Two hosts on one database during the switch: only Railway runs the scheduler and worker, so nothing runs twice.

## Decisions for the owner

- [ ] **Plan**: Hobby ($5/month, usage included) — or start on the Trial's $5 and upgrade in ~5 weeks?
- [ ] **Database**: keep Neon (suggested) — or move to Railway Postgres (+ ~$1–2/month, a copy and a short pause)?
- [ ] **Processes**: web + one `jobs` container + Redis (suggested) — or web + worker + scheduler (+ ~$1/month)?
- [ ] **Email**: Brevo HTTP API (suggested; SMTP is blocked) — or another provider (Resend needs your own domain)?
- [ ] **Code emails**: stay immediate (suggested), everything else queued — or queue them too?

Sources (checked 2026-10-07): Railway [pricing plans](https://docs.railway.com/pricing/plans),
[outbound networking / SMTP](https://docs.railway.com/networking/outbound-networking),
[cron jobs](https://docs.railway.com/cron-jobs), [Infrastructure as Code](https://docs.railway.com/infrastructure-as-code);
[Neon pricing](https://neon.com/pricing).
