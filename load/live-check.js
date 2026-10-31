import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

/*
 * Gentle performance check of the LIVE API: 2 shoppers browsing for 3 minutes (~1 request/second).
 *
 * It stays under the API's own per-IP limits (120/min overall, 60/min search), so what it measures is what real
 * visitors feel, not rate limiting. It wakes the free server first, so the ~50 s cold start is not counted as "slow".
 * A heavier test from one computer would only measure 429s; see load/README.md.
 *
 *   k6 run -e BASE_URL=https://bookly-api-zasc.onrender.com/api/v1 load/live-check.js
 */

const BASE_URL = (__ENV.BASE_URL || 'https://bookly-api-zasc.onrender.com/api/v1').replace(/\/+$/, '');
const ORIGIN = BASE_URL.replace(/\/api\/v1$/, '');
const HEADERS = { headers: { Accept: 'application/json' } };

export const options = {
  scenarios: {
    shoppers: { executor: 'constant-vus', vus: 2, duration: '3m' },
  },
  thresholds: {
    // Free hosting (Render + Neon) targets: most pages well under 1.5 s, almost no failures, never rate limited.
    http_req_duration: ['p(95)<1500'],
    http_req_failed: ['rate<0.01'],
    rate_limited_429: ['count==0'],
  },
  // The warm-up requests in setup() are not part of the measurement.
  setupTimeout: '3m',
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max'],
};

const rateLimited = new Counter('rate_limited_429');
const perEndpoint = {
  books: new Trend('endpoint_books', true),
  search: new Trend('endpoint_search', true),
  detail: new Trend('endpoint_book_detail', true),
  categories: new Trend('endpoint_categories', true),
};

/** Wakes the server (Render free sleeps when idle) and finds a real book id to open. */
export function setup() {
  const started = Date.now();
  let awake = false;
  while (Date.now() - started < 150000) {
    const up = http.get(`${ORIGIN}/up`, { timeout: '60s', tags: { name: 'warm-up' } });
    const health = http.get(`${BASE_URL}/health`, { ...HEADERS, timeout: '60s', tags: { name: 'warm-up' } });
    if (up.status === 200 && health.status === 200) {
      awake = true;
      break;
    }
    sleep(5);
  }
  if (!awake) fail(`The API at ${BASE_URL} did not answer /up and /health within 150 s. Is it deployed and is the database up?`);
  console.log(`API awake after ${Math.round((Date.now() - started) / 1000)} s (not counted in the results).`);

  const list = http.get(`${BASE_URL}/books?per_page=1`, { ...HEADERS, tags: { name: 'warm-up' } });
  let bookId = null;
  try {
    bookId = list.json('data.0.id');
  } catch (e) {
    // handled below
  }
  if (!bookId) fail('The catalog is empty: add books (or the demo data) before running this check.');

  return { bookId };
}

function track(res, trend, name) {
  trend.add(res.timings.duration);
  if (res.status === 429) rateLimited.add(1);
  check(res, { [`${name} answers 200`]: (r) => r.status === 200 });
}

const SEARCH_WORDS = ['pride', 'holmes', 'adventure', 'austen', 'island'];

export default function (data) {
  track(http.get(`${BASE_URL}/books`, { ...HEADERS, tags: { name: 'GET /books' } }), perEndpoint.books, 'book list');
  sleep(2);

  const word = SEARCH_WORDS[(__VU + __ITER) % SEARCH_WORDS.length];
  track(http.get(`${BASE_URL}/books?q=${word}`, { ...HEADERS, tags: { name: 'GET /books?q=' } }), perEndpoint.search, 'search');
  sleep(2);

  track(http.get(`${BASE_URL}/books/${data.bookId}`, { ...HEADERS, tags: { name: 'GET /books/{id}' } }), perEndpoint.detail, 'book page');
  sleep(2);

  track(http.get(`${BASE_URL}/categories`, { ...HEADERS, tags: { name: 'GET /categories' } }), perEndpoint.categories, 'categories');
  sleep(2);
}

/** Prints a short plain-English verdict and saves the full numbers next to this script. */
export function handleSummary(summary) {
  const m = summary.metrics;
  const ms = (metric, stat) => (m[metric] && m[metric].values[stat] !== undefined ? `${Math.round(m[metric].values[stat])} ms` : 'n/a');
  const failed = m.http_req_failed ? (m.http_req_failed.values.rate * 100).toFixed(2) : 'n/a';
  const limited = m.rate_limited_429 ? m.rate_limited_429.values.count : 0;
  const passed = Object.values(m).every((metric) => !metric.thresholds || Object.values(metric.thresholds).every((t) => t.ok));

  const lines = [
    '',
    '================ Bookly live check ================',
    `Target:            ${BASE_URL}`,
    `Requests:          ${m.http_reqs ? m.http_reqs.values.count : 0}`,
    `Typical (median):  ${ms('http_req_duration', 'med')}`,
    `Slow ones (p95):   ${ms('http_req_duration', 'p(95)')}   (target < 1500 ms)`,
    `Failed requests:   ${failed} %   (target < 1 %)`,
    `Rate limited 429:  ${limited}   (target 0)`,
    '',
    'Per endpoint (median / p95):',
    `  GET /books          ${ms('endpoint_books', 'med')} / ${ms('endpoint_books', 'p(95)')}`,
    `  GET /books?q=       ${ms('endpoint_search', 'med')} / ${ms('endpoint_search', 'p(95)')}`,
    `  GET /books/{id}     ${ms('endpoint_book_detail', 'med')} / ${ms('endpoint_book_detail', 'p(95)')}`,
    `  GET /categories     ${ms('endpoint_categories', 'med')} / ${ms('endpoint_categories', 'p(95)')}`,
    '',
    passed ? 'RESULT: PASS. Fast enough for real shoppers; no cache needed yet.' : 'RESULT: CHECK. Something missed a target; see load/README.md → "Reading the results".',
    '===================================================',
    '',
  ];

  return {
    stdout: lines.join('\n'),
    'load/live-check-result.json': JSON.stringify(summary, null, 2),
  };
}
