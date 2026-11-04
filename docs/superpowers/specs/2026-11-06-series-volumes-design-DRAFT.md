# Series Volumes and Complete Set — Design (DRAFT)

Date: 2026-11-06 · Status: DRAFT for Danish's review

---

## 1. Goal

Enhance the browsing and purchasing experience for multi-volume series (manga, light novels, comic book arcs, and book series like Harry Potter or One Piece). Customers can effortlessly view all volumes in sequence, see which volumes they already own, pick missing volumes, jump to the next volume, and purchase the "Complete Set" with a single click.

---

## 2. User Flows

### 2.1 Customer Flows
1. **Volume Picker on Book Detail**:
   - When viewing a book that belongs to a series (e.g., *One Piece Vol. 4*), the page displays a horizontal scrollable **Volume Picker**:
     - Chips for `Vol. 1`, `Vol. 2`, `Vol. 3`, `Vol. 4 (Current)`, `Vol. 5`...
     - Badges on chips: "Owned" (if customer has bought it), "In Cart" (if in current cart), or Price ("\$7.50").
   - Clicking any volume switches to that volume's detail page smoothly.
2. **Next Volume Prompt**:
   - At the bottom of the book detail page (and in the reader when finishing an ebook), a prominent callout shows:
     **"Read next: Volume 5"** with a one-click "Add to cart" button.
3. **Series Hub Page (`/series/{id}`)**:
   - Shows series title, description, cover banner, total volumes, and completion status (*Ongoing* vs. *Completed*).
   - Grid of all volumes ordered by `series_order` asc.
   - **"Complete Your Collection" Widget**:
     - If signed in: highlights all unowned volumes.
     - Displays button: **"Add Missing Volumes to Cart (3 books · \$22.50)"** with bundle savings if applicable.
     - One click adds all missing volumes directly into the cart.

### 2.2 Admin / Staff Flow
1. **Series Management**:
   - In **Admin → Series**, staff sets series metadata:
     - `name`, `slug`, `description`, `status` (`ongoing`, `completed`, `hiatus`).
     - `expected_volumes`: total planned volumes (e.g., 12 for a completed series, null for ongoing).
     - `bundle_discount_percent`: optional discount (e.g., 10%) when purchasing 3+ volumes together.
2. **Assigning Book Order**:
   - In **Admin → Books**, setting `series_id` reveals `series_order` (decimal support, e.g. `1`, `2`, `2.5` for special side volumes).
   - Bulk ordering tool in series admin allows drag-and-drop reordering of volumes.

---

## 3. Proposed Data Model

> [!NOTE]
> All tables and columns below are marked as **proposed** and do not alter existing migrations.

### Proposed Changes to Table: `series`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `status` | varchar(20) | default 'ongoing' | `ongoing`, `completed`, `hiatus` |
| `expected_volumes` | integer | null | Total planned volumes (null if open-ended) |
| `banner_image_url` | varchar(500) | null | Wide banner image for series page |
| `bundle_discount_pct` | integer | null, default 0 | Discount percentage when buying 3+ volumes |

### Existing Relation in `books`
- `books.series_id` (already exists)
- `books.series_order` (already exists, integer; propose altering to `decimal(5,1)` in migration to support side volumes like `7.5`)

### Proposed View / Query Helper: `customer_series_progress`
- Dynamically resolves owned volumes by checking customer orders where `orders.status IN ('paid', 'shipped', 'delivered')` joined to `order_items.book_id`.

---

## 4. API Endpoints

### Storefront Endpoints
- `GET /api/v1/series/{id}`:
  - Returns series details, completion status, total count, and ordered list of volumes (`BookCard` structure with `series_order`).
  - If authenticated, includes `owned_book_ids: [12, 13, 14]` for collection tracking.
- `GET /api/v1/series/{id}/next-volume?current_book_id={id}`:
  - Returns the next book in sequence (`series_order > current.series_order` asc limit 1).
- `POST /api/v1/series/{id}/add-missing-to-cart`:
  - Request body: `{ exclude_ids?: number[] }`.
  - Determines unowned volumes in series and adds all available in-stock formats to the customer's cart in a single batch.

### Staff Endpoints
- `PUT /api/v1/staff/series/{id}`:
  - Update `status`, `expected_volumes`, `bundle_discount_pct`, `banner_image_url`.
- `POST /api/v1/staff/series/{id}/reorder`:
  - Request body: `{ volumes: [{ book_id: 101, series_order: 1 }, { book_id: 102, series_order: 2 }] }`.

---

## 5. Security and Business Logic

1. **Owned Status Verification**:
   - "Owned" check only queries verified orders (`paid`, `shipped`, `delivered`), ignoring cancelled or pending orders.
2. **Stock Verification on Bundle Add**:
   - Adding missing volumes to cart validates stock for each volume. If Volume 4 is out of stock, it adds the remaining available volumes and informs the customer ("Volume 4 is currently out of stock and was skipped").
3. **Price Integrity**:
   - Bulk "Add series to cart" calculates prices through the standard `CartService`, ensuring existing promotional rules, customer tiers, and coupons apply deterministically.

---

## 6. Free-Hosting & Performance Limits

- **Database Performance**:
  - Indexed query: `CREATE INDEX idx_books_series_order ON books (series_id, series_order);`
  - Eager-loading volume lists prevents N+1 queries on series hub pages.
- **Cache Strategy**:
  - Series metadata and volume ordering are cached in Redis / file cache with tag `series_{id}`.
  - Invalidation occurs only when books are added, reordered, or removed.
- **Payload Size**:
  - For massive series (e.g. *One Piece* with 100+ volumes), the volume picker on detail pages fetches lightweight payloads (`id`, `series_order`, `title`, `price`) rather than full book records.

---

## 7. Tests to Write

1. **Volume Ordering**:
   - Volume picker sorts books strictly by `series_order` ascending.
   - Support for decimal orders (e.g., `1`, `1.5`, `2`).
2. **Next Volume Determination**:
   - Finds volume N+1 correctly even when orders have gaps (e.g. orders `1`, `3`, `5`).
   - If on the last volume, `next_volume` cleanly returns `null`.
3. **Collection Completion**:
   - When a user owns volumes 1 and 2 of a 5-volume series, `add-missing-to-cart` adds exactly volumes 3, 4, and 5.
   - Out-of-stock volumes are skipped with informative alert.
   - Volume chips accurately render "Owned", "In Cart", or price.

---

## 8. Rollout Steps

1. **Step 1**: Migration adding `status`, `expected_volumes`, `bundle_discount_pct` to `series` table.
2. **Step 2**: Backend endpoints for series progress and next volume.
3. **Step 3**: Frontend Volume Picker component on `BookDetailPage.tsx`.
4. **Step 4**: Frontend Series Hub redesign with "Complete Your Set" card.
5. **Step 5**: Test with manga and novel series fixtures.

---

## 9. Questions for Danish

1. **Half/Side Volumes**: Do we need decimal ordering (e.g. Volume 7.5, 0.5) for manga side-stories?
   - *Recommendation*: **Yes, use decimal `decimal(5,1)`** for `series_order`. Manga series frequently have `.5` bonus chapters or prequel volumes (`Vol. 0`).
2. **Bundle Discount**: Should buying 3+ volumes or a complete set trigger an automatic bundle discount?
   - *Recommendation*: **Yes, 10% discount** when adding 3 or more volumes of the same series. This significantly boosts Average Order Value (AOV) for manga readers.
3. **Physical + Digital Mix**: If a customer owns Volume 1 as an ebook, does it count as "Owned" on the physical volume picker?
   - *Recommendation*: **Show "Owned (Ebook)" badge**. Let the customer still purchase the physical copy if desired, but make it clear they already have the digital version.
