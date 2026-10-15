import http from 'k6/http';
import { check, sleep } from 'k6';

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

const BASE_URL = __ENV.API_BASE_URL || 'http://127.0.0.1:8000/api/v1';

export default function () {
  const headers = { 'Accept': 'application/json' };

  // 1. Browse public book list
  const booksRes = http.get(`${BASE_URL}/books`, { headers });
  check(booksRes, {
    'books status is 200': (r) => r.status === 200,
  });
  sleep(1);

  // 2. Search book catalog
  const searchRes = http.get(`${BASE_URL}/books?q=the`, { headers });
  check(searchRes, {
    'search status is 200': (r) => r.status === 200,
  });
  sleep(1);

  // 3. View single book details
  const bookDetailRes = http.get(`${BASE_URL}/books/1`, { headers });
  check(bookDetailRes, {
    'book detail status is 200 or 404': (r) => r.status === 200 || r.status === 404,
  });
  sleep(1);

  // 4. View category tree
  const categoriesRes = http.get(`${BASE_URL}/categories`, { headers });
  check(categoriesRes, {
    'categories status is 200': (r) => r.status === 200,
  });
  sleep(1);
}
