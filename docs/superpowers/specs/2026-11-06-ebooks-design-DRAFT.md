# Ebooks and Browser-Only Reader — Design (DRAFT)

Date: 2026-11-06 · Status: DRAFT for Danish's review

---

## 1. Goal

Allow Bookly customers to purchase and read digital books directly in their web browser (desktop and mobile) across Cambodia.
The system provides a seamless reading experience for English and Khmer typography while protecting intellectual property through browser-only streaming, watermark overlays, strict device limits, and zero direct file downloads.

---

## 2. Format Recommendation: PDF vs. EPUB

### Analysis
| Factor | PDF | EPUB |
|---|---|---|
| **Khmer Typography** | **Flawless**: Embeds glyph shapes, complex subscript ligatures (ជើងអក្សរ), and exact line wraps. | **Fragile**: Dependent on client-side font shaping engines and web font loading; complex Khmer scripts often break on older Android/iOS browsers. |
| **Piracy Resistance** | Chunks can be rasterized or rendered page-by-page as canvas slices. Hard to scrape full text. | Unzipping provides clean HTML/CSS text files that are trivially copy-pasted or dumped. |
| **Responsive Reading** | Fixed layout; requires pan/zoom on small screens. | Fluid reflow; font size and theme can be adjusted. |
| **Server Processing** | Fast extraction of page count and rasterization of pages on demand or pre-chunked. | Requires complex parsing and packaging of HTML chapters and asset manifests. |

### Recommendation
**Support PDF first as the primary format for launch, with EPUB planned for pure-text English novels in a later revision.**
- For Cambodian publishers, books heavily rely on specific Khmer Unicode fonts (e.g., Siemreap, Battambang, Moul) with intricate coeng rules. PDFs guarantee 100% faithful visual representation across every device and browser without font substitution errors.
- Pre-processing PDF into secured page slices allows uniform streaming, client-side canvas rendering, and dynamic watermarking.

---

## 3. User Flows

### 3.1 Customer: Buying and Reading
1. **Discovery & Preview**:
   - Customer opens book detail page. An "Ebook" format chip is available.
   - Customer clicks **Free Preview (~10%)**: opens the reader in preview mode without signing in or purchasing, displaying the first 10% of pages (e.g., 20 pages of a 200-page book).
2. **Purchase**:
   - Customer adds "Ebook" to their normal cart alongside physical books.
   - At checkout, if the order contains only digital items, shipping address is marked optional/not required, and delivery fee is \$0.
   - Once payment is confirmed (`paid` status via KHQR or store credit), the backend automatically grants an `EbookLicense` to the customer.
3. **Reading in Account**:
   - Customer goes to **Account → My Ebooks** (`/account/ebooks`).
   - Clicks **Read now** on an owned book.
   - The reader opens full-screen (`/reader/{bookId}`):
     - Resume reading from last saved page.
     - Visible diagonal watermark across every page: `dara@example.com · ORD-2026-0042`.
     - Controls: page navigation (arrows/swipe/slider), thumbnail bar, zoom, dark/sepia/light background tint.
     - Text selection, right-click context menu, and keyboard shortcuts (`Ctrl+C`, `Ctrl+P`) are blocked.
4. **Device Management**:
   - Each customer can read on up to **3 devices** (tracked via persistent client fingerprint + user-agent hash).
   - If attempting to open on a 4th device, the reader halts and directs the user to **Account → Ebook Devices** to deregister an inactive device (maximum 1 deregistration permitted every 30 days).

### 3.2 Admin / Staff Flow
1. **Upload Ebook**:
   - Staff navigates to **Admin → Books → Edit Book → Formats → Ebook**.
   - Staff uploads PDF file (up to 80MB).
   - Backend uploads master PDF to private Cloudflare R2 bucket (not public).
   - Background queue job inspects page count, extracts table of contents, and computes preview page boundary (`ceil(pages * 0.10)`).
2. **Revocation & Support**:
   - If a buyer reports a broken/unreadable file, staff can inspect the license, test file integrity, re-process the file, or manually issue a store credit refund.
   - Ebooks are marked non-refundable in the storefront return policy except for verified corrupt files.

---

## 4. Proposed Data Model

> [!NOTE]
> All tables below are marked as **proposed** and do not alter existing migrations.

### Proposed Table: `ebook_files`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | bigserial | PK | Unique ID |
| `book_id` | bigint | FK -> books.id | Associated book |
| `format` | varchar(10) | default 'pdf' | `pdf` or `epub` |
| `r2_storage_key` | varchar(255) | not null | Cloudflare R2 path (`masters/ebooks/{uuid}.pdf`) |
| `file_size_bytes` | bigint | not null | Master file size |
| `checksum_sha256` | varchar(64) | not null | SHA-256 integrity hash |
| `total_pages` | integer | not null, > 0 | Total number of pages |
| `preview_pages` | integer | not null, default 10 | Pages available for free preview |
| `processing_status` | varchar(20) | default 'ready' | `pending`, `processing`, `ready`, `failed` |
| `created_at` / `updated_at` | timestamp | | Standard timestamps |

### Proposed Table: `ebook_licenses`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | bigserial | PK | Unique ID |
| `customer_id` | bigint | FK -> customers.id | License owner |
| `book_id` | bigint | FK -> books.id | Licensed book |
| `order_id` | bigint | FK -> orders.id | Originating order |
| `order_number` | varchar(50) | not null | Stamped in watermark |
| `status` | varchar(20) | default 'active' | `active`, `revoked` |
| `last_read_page` | integer | default 1 | Reading progress sync |
| `last_read_at` | timestamp | null | Last opened timestamp |
| `created_at` / `updated_at` | timestamp | | Standard timestamps |

### Proposed Table: `customer_ebook_devices`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | bigserial | PK | Unique ID |
| `customer_id` | bigint | FK -> customers.id | Device owner |
| `device_fingerprint` | varchar(64) | not null | SHA-256 of hardware/canvas/UA attributes |
| `device_name` | varchar(100) | not null | E.g., "Safari on iPhone", "Chrome on Windows" |
| `last_active_at` | timestamp | not null | Last time device accessed reader |
| `deregistered_at` | timestamp | null | When removed |
| `created_at` | timestamp | | Creation timestamp |

---

## 5. API Endpoints

### Customer Endpoints
- `GET /api/v1/ebooks`: List owned books with progress (`last_read_page`, `total_pages`, `last_read_at`).
- `GET /api/v1/reader/{bookId}/session`:
  - Request headers: `X-Device-Fingerprint`, `X-Device-Name`.
  - Checks customer license, active status, and 3-device limit.
  - Returns short-lived session token (JWT, 15 min expiry) containing `sub: customerId`, `book: bookId`, `order: orderNumber`, `email: customerEmail`, `device: fingerprint`.
- `GET /api/v1/reader/{bookId}/preview`:
  - Public endpoint. Returns total pages and preview page limit.
- `GET /api/v1/reader/{bookId}/page/{pageNum}`:
  - Header: `Authorization: Bearer <session_token>`.
  - Validates session token, device binding, and page number within total pages (or preview boundary if unauthenticated preview session).
  - Streams encrypted WebP page slice (AES-GCM with session key derived during handshake) or temporary signed URL to pre-rendered watermarked canvas slice.
- `POST /api/v1/reader/{bookId}/progress`:
  - Body: `{ page: 45 }`. Updates `last_read_page` for cross-device synchronization.
- `GET /api/v1/account/ebook-devices`: List active devices.
- `DELETE /api/v1/account/ebook-devices/{deviceId}`: Deregister device (rate-limited to 1 per 30 days).

### Staff Endpoints
- `POST /api/v1/staff/books/{bookId}/ebook`: Upload master PDF.
- `DELETE /api/v1/staff/books/{bookId}/ebook`: Remove ebook file.
- `GET /api/v1/staff/customers/{customerId}/ebook-licenses`: View customer licenses.
- `POST /api/v1/staff/ebook-licenses/{licenseId}/revoke`: Revoke license (fraud/chargeback).

---

## 6. Security and IP Protection

1. **Browser-Only Streaming**:
   - Master PDF is never served directly to the client browser.
   - Pages are decrypted in worker memory and painted into an HTML5 `<canvas>`.
2. **Dynamic Visible Watermark**:
   - Rendered across the canvas at a 30-degree angle with 12% opacity:
     `Bookly · {customer.email} · Order #{order.number} · {date}`.
   - Repeating grid ensures any photo or screenshot identifies the source account.
3. **Copy / Print / Inspector Mitigation**:
   - `user-select: none`, `pointer-events: none` on text overlay.
   - Disabled right-click context menu and keyboard shortcuts (`Ctrl+S`, `Ctrl+P`, `Ctrl+C`, `Cmd+C`).
   - CSS print stylesheet: `@media print { body { display: none !important; } }`.
4. **Device Limit Enforcement**:
   - Strict maximum of 3 concurrent active devices per customer account.
   - Fingerprint changes trigger verification or block if limit exceeded.

---

## 7. Free-Hosting & Infrastructure Limits

- **Hosting (Render Free Tier)**:
  - CPU/RAM is constrained (512MB RAM). Converting large 300-page PDFs on-the-fly inside PHP on Render will exhaust memory (OOM).
  - *Solution*: Pre-render preview pages and store encrypted page tiles on Cloudflare R2 on upload; or perform on-demand PDF page slice streaming using a lightweight PDF streaming library with strict memory limits (`memory_limit = 256M`).
- **Storage (Cloudflare R2)**:
  - Free tier offers 10 GB storage, 10M Class B (read) operations/month, and **\$0 egress bandwidth fees**.
  - Perfect fit for Bookly's free tier hosting budget: 10 GB easily accommodates ~250–400 optimized ebooks.

---

## 8. Tests to Write

1. **License & Access**:
   - Buying ebook variant grants license upon `paid` status.
   - Non-buyers cannot access pages past preview limit.
   - Preview endpoint strictly denies access to pages > `preview_pages`.
2. **Device Bounds**:
   - 1st, 2nd, and 3rd unique devices register successfully.
   - 4th device receives HTTP 403 `device_limit_exceeded` with active device list.
   - Deregistering a device frees up a slot.
   - Deregistration cooldown (30 days) is strictly enforced.
3. **Watermark & Integrity**:
   - Verify session token embeds correct buyer email and order number.
   - Revoked license immediately invalidates reading sessions.

---

## 9. Rollout Steps

1. **Step 1**: Create storage bucket on Cloudflare R2 (`bookly-ebooks-private`).
2. **Step 2**: Apply database migration for proposed tables (`ebook_files`, `ebook_licenses`, `customer_ebook_devices`).
3. **Step 3**: Implement backend upload, chunking, and reader streaming API.
4. **Step 4**: Build frontend canvas reader component with Khmer font rendering and watermark overlay.
5. **Step 5**: Test with 5 real sample ebooks across mobile Safari, Android Chrome, and desktop.

---

## 10. Questions for Danish

1. **File Format for Upload**: Should admins upload PDF only, or both PDF and EPUB?
   - *Recommendation*: **PDF only for Phase 2**. PDF guarantees 100% faithful rendering of Khmer scripts, eliminates font substitution glitches, and prevents layout breakage. EPUB can be added in Phase 2.5 for English-only books.
2. **Preview Length**: Should preview be a fixed 10% or configurable per book by staff?
   - *Recommendation*: **Default 10% automatically calculated**, with an optional staff override field (`preview_pages`) in admin for short books or marketing promotions.
3. **Device Deregistration Window**: How often can customers swap devices if they buy a new phone?
   - *Recommendation*: Allow self-service deregistration **once every 30 days**. If needed sooner, staff can reset it via Customer CRM.
