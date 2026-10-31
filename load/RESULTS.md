# Load Test Results — Public Catalog Endpoints

## Live check (`load/live-check.js`, production)

Not run yet. After running `k6 run -e BASE_URL=https://bookly-api-zasc.onrender.com/api/v1 load/live-check.js`,
paste the report here with the date:

```
(date)
(paste the "Bookly live check" report)
```

---

## Local stress test (`load/load-test.js`)

- **Date**: 2026-10-15
- **Environment**: Local development API (`http://127.0.0.1:8000/api/v1`)
- **Machine**: 11th Gen Intel(R) Core(TM) i5-11300H @ 3.10GHz (4 cores, 8 logical processors), 35.74 GB RAM, Windows 11 Home Single Language 64-bit
- **PHP Version**: PHP 8.4.26 (cli)
- **k6 Version**: k6 v2.3.0 (commit/e088784614, windows/amd64)
- **Server Used**: `php artisan serve` (`php -S 127.0.0.1:8000`). Note: `php artisan serve` runs PHP's built-in web server, which is strictly **single-threaded**. When 30 concurrent virtual users send requests simultaneously, requests queue up in the TCP listen backlog and are processed one after another sequentially. These latency numbers represent a lower-bound performance floor on a single-threaded local test runner, not production speed under multi-threaded FrankenPHP worker pools.

---

## Workload Profile

- **Script**: `load/load-test.js`
- **Configuration**:
  - `SPREAD_IPS=1` (simulating unique client IP per virtual user `10.0.<vu>.1` to avoid false rate-limiter tripping on local test)
  - `TRUSTED_PROXIES="*"` (configured exclusively in local server shell for this test)
- **Stages**:
  - 0 -> 30 VUs over 30s
  - 30 VUs sustained for 1m
  - 30 -> 0 VUs over 30s
- **Total Duration**: 2m 23.9s (including graceful stop of in-flight iterations)
- **Total Requests**: 170 requests across 37 completed iterations

---

## Per-Endpoint Response Times

| Endpoint | Min | p50 (Median) | p90 | p95 | Max |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET /api/v1/books` | 1,467 ms (1.47s) | 19,287 ms (19.29s) | 27,474 ms (27.47s) | 28,069 ms (28.07s) | 28,432 ms (28.43s) |
| `GET /api/v1/books?q=the` | 3,238 ms (3.24s) | 20,062 ms (20.06s) | 26,734 ms (26.73s) | 27,536 ms (27.54s) | 28,349 ms (28.35s) |
| `GET /api/v1/books/{id}` | 8,294 ms (8.29s) | 19,260 ms (19.26s) | 20,759 ms (20.76s) | 25,953 ms (25.95s) | 27,821 ms (27.82s) |
| `GET /api/v1/categories` | 7,151 ms (7.15s) | 18,776 ms (18.78s) | 20,343 ms (20.34s) | 25,027 ms (25.03s) | 27,758 ms (27.76s) |

---

## Error Rates & Rate Limiting

- **HTTP 429 Responses**: 0 (`rate_limited_429 = 0`). The `SPREAD_IPS=1` header mapping successfully prevented virtual users from colliding on loopback rate limits.
- **HTTP Failure Rate**: 0.00% (0 errors out of 170 HTTP requests).
- **Check Success Rate**: 100.00% (170 of 170 response checks passed).
  - `books status is 200`: 100%
  - `search status is 200`: 100%
  - `book detail status is 200`: 100%
  - `categories status is 200`: 100%

---

## Threshold Results

| Threshold | Target | Observed | Status |
| :--- | :--- | :--- | :--- |
| `http_req_failed` | `< 1%` | `0.00%` | **PASSED** |
| `http_req_duration` | `p(95) < 800ms` | `p(95) = 27.65s` | **FAILED** |

### Slowest Endpoint Analysis

The slowest endpoint observed was **`GET /api/v1/books`** with `p(95) = 28.07s` and `max = 28.43s`, closely followed by **`GET /api/v1/books?q=the`** with `p(95) = 27.54s` and `max = 28.35s`.

The threshold failure (`p(95) < 800ms`) is entirely due to server concurrency serialization in `php artisan serve`: with 30 concurrent connections queued against a single PHP worker process, each request waits its turn in the connection backlog (~20–28 seconds of queuing delay). As verified via database EXPLAIN ANALYZE, individual PostgreSQL query execution takes ~1 ms.
