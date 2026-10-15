# Catalog Load Testing with k6

This directory contains load testing scripts for Bookly API public catalog endpoints.

> [!WARNING]
> **DO NOT RUN AGAINST RENDER OR NEON LIVE URLS WITHOUT OWNER APPROVAL.**
> Both Render and Neon instances operate on free/hobby compute tiers. Running a 30-VU load test against live production instances can quickly exhaust compute quotas, spike latency, or trigger auto-suspension.
> Only run these scripts against the **local development API** (`http://127.0.0.1:8000`).

---

## Prerequisites

1. Install [k6](https://k6.io/docs/get-started/installation/):
   - Windows (winget): `winget install k6 --source winget`
   - Windows (choco): `choco install k6`
   - MacOS (brew): `brew install k6`
   - Direct download: download `k6-vX.Y.Z-windows-amd64.zip` from https://github.com/grafana/k6/releases/latest, unzip, and run `k6.exe` by path.

2. Ensure your local backend is running on local demo data with trusted proxies enabled for IP spreading:
   ```bash
   TRUSTED_PROXIES="*" php artisan serve --port=8000
   ```
   > [!CAUTION]
   > `TRUSTED_PROXIES="*"` must ONLY be set in the shell for this local test run, and NEVER in production or on any shared deployment.

3. Ensure local PostgreSQL database has seeded demo catalog data:
   ```bash
   php artisan db:seed --class=DemoSeeder
   ```

---

## Running the Test

The API enforces strict per-IP rate limits (`GET /books` at 120/min, `?q=` search at 60/min). Because all virtual users from one machine share the same loopback IP, requests would quickly hit HTTP 429 without simulated distinct IPs.

When `SPREAD_IPS=1` is set, each virtual user sends a simulated header `X-Forwarded-For: 10.0.<vu>.1` (where `<vu>` is the k6 virtual user ID `__VU`). This header is only respected by the local API when started with `TRUSTED_PROXIES=*`. Any HTTP 429 responses are tracked in the custom `rate_limited_429` metric so they are not hidden.

Run the test against your local API:

```bash
SPREAD_IPS=1 BASE_URL=http://127.0.0.1:8000/api/v1 k6 run load/load-test.js
```

To customize the target URL via `API_BASE_URL` or `BASE_URL`:

```bash
SPREAD_IPS=1 BASE_URL=http://127.0.0.1:8000/api/v1 k6 run load/load-test.js
```

---

## Workload & Thresholds

- **Endpoints tested**:
  - `GET /api/v1/books` (browsing)
  - `GET /api/v1/books?q=the` (full-text search)
  - `GET /api/v1/books/1` (single book view)
  - `GET /api/v1/categories` (category taxonomy)
- **Ramping profile**:
  - 0 -> 30 virtual users over 30s
  - 30 virtual users sustained for 1m
  - 30 -> 0 virtual users ramp down over 30s
  - Total duration: 2 minutes
- **Thresholds**:
  - `http_req_duration`: p95 < 800 ms
  - `http_req_failed`: error rate < 1%
