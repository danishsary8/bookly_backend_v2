# Load testing with k6

Two tests, for two questions:

| Test | Question it answers | Where | Load |
| --- | --- | --- | --- |
| **Live check** — `live-check.js` | "How fast does the real site feel to shoppers?" | the live API | gentle: 2 shoppers, 3 min, ~1 request/s |
| **Local stress test** — `load-test.js` | "Where does the API break under heavy traffic?" | your own computer only | heavy: 30 users |

Why the live check is gentle: the API limits every IP address to 120 requests a minute (search 60). From one computer, a
heavy test would only measure those limits (HTTP 429), not speed, and it would burn the free database's monthly compute
hours. The live check stays under the limits, so it measures what real visitors feel.

## 1. Install k6 (once)

- Windows: `winget install k6 --source winget` (or `choco install k6`)
- macOS: `brew install k6`
- Linux / other: https://grafana.com/docs/k6/latest/set-up/install-k6/

Check: `k6 version`.

## 2. Live check (run this one)

From the repository folder:

```bash
k6 run -e BASE_URL=https://bookly-api-zasc.onrender.com/api/v1 load/live-check.js
```

What happens:
1. **Wake-up** (up to 2.5 min): it calls `/up` and `/health` until the free server is awake. Not counted in the results.
2. **3 minutes of browsing** by 2 shoppers: book list → a search → a book page → categories, with pauses like a person.
3. **A short report** in the terminal, and the full numbers in `load/live-check-result.json` (not committed).

Run it when the site is quiet, and at most a few times a month (each run keeps the database awake ~5 minutes).

### Reading the results

The report ends with `RESULT: PASS` or `RESULT: CHECK`.

| Line | Good | If not |
| --- | --- | --- |
| **Typical (median)** | under ~500 ms | Neon in another region than Render, or the database waking up: run again |
| **Slow ones (p95)** | under 1500 ms (the target) | Look at *Per endpoint*: the slowest one is where to add caching (see below) |
| **Failed requests** | under 1 % | Read the API log on Render and Sentry for 500s at that time |
| **Rate limited 429** | 0 | Another tool (or a second run) was hitting the API from your IP at the same time |

**Cache only if needed.** If one endpoint is slow (most likely `GET /books?q=` or `GET /books`), that is the one to
cache; if everything passes, no cache is needed yet.

Please paste the report into `load/RESULTS.md` (section "Live check") with the date, so the numbers are kept.

## 3. Local stress test (your computer only)

> [!WARNING]
> **Never run `load-test.js` against the live API.** It sends 30 users' worth of traffic and fakes many IP addresses,
> which only works on a local API started with `TRUSTED_PROXIES="*"`.

1. Seed demo data: `php artisan db:seed --class=DemoSeeder`
2. Start the API with trusted proxies **in this shell only** (never in production):
   ```bash
   TRUSTED_PROXIES="*" php artisan serve --port=8000
   ```
3. Run:
   ```bash
   SPREAD_IPS=1 BASE_URL=http://127.0.0.1:8000/api/v1 k6 run load/load-test.js
   ```

`SPREAD_IPS=1` makes each virtual user send `X-Forwarded-For: 10.0.<vu>.1`, so the per-IP limits don't stop the test;
429s are still counted in `rate_limited_429`. Profile: 0 → 30 users over 30 s, 30 users for 1 min, then down
(2 min in total). Thresholds: p95 < 800 ms, errors < 1 %.

Note: `php artisan serve` is single-threaded, so local numbers are much slower than the real FrankenPHP server (see
`RESULTS.md`). Use the local test to compare before/after a change, not as production speed.
