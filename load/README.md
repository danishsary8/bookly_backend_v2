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

2. Ensure your local backend is running:
   ```bash
   php artisan serve --port=8000
   ```

3. Ensure local PostgreSQL database has seeded demo catalog data:
   ```bash
   php artisan db:seed --class=DemoSeeder
   ```

---

## Running the Test

Run the test against your local API:

```bash
k6 run load/load-test.js
```

To customize the target URL:

```bash
k6 run -e API_BASE_URL=http://127.0.0.1:8000/api/v1 load/load-test.js
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
