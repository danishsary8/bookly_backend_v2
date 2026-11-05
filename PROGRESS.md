# Bookly Phase 3 — Series volumes (manga, comics, multi-part books)

## Summary for Danish

### 1. Pull Request Links
- **Backend PR**: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/series-volumes
- **Frontend PR**: https://github.com/danishsary8/bookly_frontend/pull/new/feature/series-volumes

### 2. Merge Order
Backend first, then frontend.
No database migration is required for Phase 3 (it utilizes the existing `series`, `books.series_id`, and `books.series_order`).

### 3. Decisions & Implementation Summary
- **Data model**:
  - Leveraged existing `series` table, `books.series_id`, and `books.series_order`. No new volume table was needed.
  - Ownership: Customers own a book if they have an order containing any variant of that book with status in `['paid', 'processing', 'shipped', 'delivered']`.
- **API Endpoints**:
  - `GET /series/{id}`: Returns series with books ordered by `series_order ASC NULLS LAST, id ASC`. When requested with customer authentication, includes `is_owned` and `is_in_cart` flags on books, plus `total_volumes`, `owned_count`, and `in_cart_count` on the series summary.
  - `GET /books/{id}/next-volume`: Resolves the next volume in the series by series order or ID, returning `BookDetail` or `null`.
  - `POST /cart/series/{id}` ("Complete the set"): Adds the cheapest available in-stock physical format for all missing volumes in the series (skipping owned, in-cart, and out-of-stock volumes), returning arrays of `added`, `skipped` (with reason: `already_owned`, `already_in_cart`, `out_of_stock`), and the updated `cart`.
  - `GET /staff/series/{id}`: Returns series with its volumes in reading order, including duplicate order detection.
  - `PUT /staff/series/{id}/volumes`: Allows staff to update `series_order` in batch, with automatic duplicate order number warnings.
- **Documentation & Testing**:
  - Updated `public/openapi.yaml` and `docs/API.md`.
  - Comprehensive feature tests in `tests/Feature/Catalog/SeriesVolumesTest.php`.
  - Contract tests in `tests/Feature/Platform/ApiContractTest.php` and `OpenApiSpecTest.php` passing.
