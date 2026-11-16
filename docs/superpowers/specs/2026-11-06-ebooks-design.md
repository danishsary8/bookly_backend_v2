# Ebooks — design (approved 2026-11-06)

Replaces the draft `2026-11-06-ebooks-design-DRAFT.md` (on main). Owner decisions: PDF only; preview 10% by default,
staff can override per book; customers remove at most 1 device per 30 days, staff can reset; no refunds except a
broken file.

## How it works
- **Upload (staff):** book edit page → upload a PDF (max 100 MB) for the book's ebook format. The master PDF goes to a
  **private** disk (`EBOOK_DISK`: `local` in dev/tests, a private R2 bucket in production — owner creates it).
- **Page rendering:** done once, in the background, not per view (Render free has 512 MB RAM). The hourly-style
  maintenance runner (`MaintenanceTasks`, every minute) runs `ebooks:render`: it takes the oldest ebook still
  processing and renders the next 15 pages with `pdftoppm` (poppler-utils, added to the Docker image) to JPEG
  (~1200 px tall), stored as `ebooks/{fileId}/pages/{n}.jpg` on the private disk. `pdfinfo` gives the page count at
  upload. Status: processing → ready / failed (error kept for staff). Re-upload replaces the pages.
- **Ownership:** a customer owns an ebook when they have an order containing that book's ebook format whose status is
  paid, processing, shipped or delivered (cash on delivery: once staff mark it paid/delivered). Cancelled/returned
  orders don't count. No separate licence table (derived, so refunds/cancels are automatic).
- **Reading:** `GET /reader/{book}/pages/{n}` (signed-in owner, registered device) returns the page JPEG with a
  watermark burned in by the server (GD): a repeating diagonal `Bookly · email · order no.` at low opacity. The
  frontend fetches with the normal token and draws it on a canvas; there is no file download, copy, right-click or
  print. Responses are `no-store`, rate limited (e.g. 120 pages/min).
- **Preview:** `GET /reader/{book}/preview/pages/{n}` public for pages ≤ preview limit (default ceil(10%), min 1, staff
  override `preview_pages`), watermark "Bookly preview".
- **Devices:** the frontend creates a random device id (stored in the browser) and sends it as `X-Reader-Device`.
  First use registers it (name from the user agent). Max 3 active devices; a 4th gets 409 "remove a device". The
  customer can remove one device per 30 days; staff can reset all.
- **Progress:** `PUT /reader/{book}/progress {page}`; `GET /me/ebooks` lists owned ebooks with page count, last page,
  last read time, status (ready/processing).

## Data (new migrations, owner-approved feature)
- `ebook_files`: id, book_id (unique), path, page_count, pages_rendered, preview_pages (nullable override), status,
  error, size_bytes, uploaded_by_staff_id, timestamps.
- `ebook_devices`: id, customer_id, device_hash (sha256 of the device id), name, last_used_at, removed_at, timestamps.
- `ebook_progress`: customer_id + book_id primary, page, updated_at.

## API
Customer: `GET /me/ebooks`, `GET /reader/{book}` (meta: pages, preview, owned, last page), `GET /reader/{book}/pages/{n}`,
`PUT /reader/{book}/progress`, `GET /me/ebook-devices`, `DELETE /me/ebook-devices/{id}`.
Public: `GET /reader/{book}/preview` (meta), `GET /reader/{book}/preview/pages/{n}`.
Staff (catalog:manage): `POST /staff/books/{book}/ebook` (multipart pdf), `GET /staff/books/{book}/ebook`,
`PATCH /staff/books/{book}/ebook {preview_pages}`, `DELETE /staff/books/{book}/ebook`;
staff (customers): `POST /staff/customers/{customer}/ebook-devices/reset`.

## Frontend
Book page: "Read a free preview" + "Read now" when owned. Reader page `/read/{bookId}`: one page at a time, next/prev,
keyboard arrows, page slider, fit width, zoom, light/dark/sepia surround, resume where left, preloads next page.
Account → My ebooks (library) and Devices. Admin book page: ebook upload, status/progress, preview pages.

## Delivery
- 2a backend (`feature/ebook-reader-api`): migrations, upload, rendering command, ownership, reader + preview + devices
  + progress endpoints, staff endpoints, OpenAPI, tests, Dockerfile poppler + gd.
- 2b frontend (`feature/ebook-reader`): reader, preview, library, devices, admin upload.
- Owner steps (later): private R2 bucket + keys in Render (`EBOOK_DISK=r2_private`, `R2_PRIVATE_*`).
