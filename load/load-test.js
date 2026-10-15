import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

// k6 load test script for busiest public endpoints
// Target: Local API only (http://127.0.0.1:8000)

export const options = {
  stages: [
    { duration: '30s', target: 30 }, // Ramp-up to 30 VUs
    { duration: '1m', target: 30 },  // Stay at 30 VUs for 1 minute
    { duration: '30s', target: 0 },  // Ramp-down to 0 VUs
  ],
  thresholds: {
    http_req_duration: ['p(95)<800'], // p95 response time must be under 800ms
    http_req_failed: ['rate<0.01'],   // Error rate must be under 1%
  },
};

const BASE_URL = __ENV.BASE_URL || __ENV.API_BASE_URL || 'http://127.0.0.1:8000/api/v1';

// Custom metrics to track 429 responses and per-endpoint response times
const rateLimitCounter = new Counter('rate_limited_429');
const booksDuration = new Trend('endpoint_books_duration');
const searchDuration = new Trend('endpoint_search_duration');
const detailDuration = new Trend('endpoint_detail_duration');
const categoriesDuration = new Trend('endpoint_categories_duration');

export default function () {
  const headers = { Accept: 'application/json' };
  if (__ENV.SPREAD_IPS === '1') {
    headers['X-Forwarded-For'] = `10.0.${__VU}.1`;
  }

  // 1. Browse public book list
  const booksRes = http.get(`${BASE_URL}/books`, {
    headers,
    tags: { name: 'GET /books' },
  });
  booksDuration.add(booksRes.timings.duration);
  if (booksRes.status === 429) {
    rateLimitCounter.add(1);
  }
  check(booksRes, {
    'books status is 200': (r) => r.status === 200,
  });

  // Pick a real book ID from the first /books response
  let bookId = 1;
  try {
    const json = booksRes.json();
    if (json && json.data && json.data.length > 0 && json.data[0].id) {
      bookId = json.data[0].id;
    }
  } catch (e) {
    // fallback if json parsing fails
  }

  sleep(1);

  // 2. Search book catalog
  const searchRes = http.get(`${BASE_URL}/books?q=the`, {
    headers,
    tags: { name: 'GET /books?q=the' },
  });
  searchDuration.add(searchRes.timings.duration);
  if (searchRes.status === 429) {
    rateLimitCounter.add(1);
  }
  check(searchRes, {
    'search status is 200': (r) => r.status === 200,
  });
  sleep(1);

  // 3. View single book details
  const bookDetailRes = http.get(`${BASE_URL}/books/${bookId}`, {
    headers,
    tags: { name: 'GET /books/{id}' },
  });
  detailDuration.add(bookDetailRes.timings.duration);
  if (bookDetailRes.status === 429) {
    rateLimitCounter.add(1);
  }
  check(bookDetailRes, {
    'book detail status is 200': (r) => r.status === 200,
  });
  sleep(1);

  // 4. View category tree
  const categoriesRes = http.get(`${BASE_URL}/categories`, {
    headers,
    tags: { name: 'GET /categories' },
  });
  categoriesDuration.add(categoriesRes.timings.duration);
  if (categoriesRes.status === 429) {
    rateLimitCounter.add(1);
  }
  check(categoriesRes, {
    'categories status is 200': (r) => r.status === 200,
  });
  sleep(1);
}
