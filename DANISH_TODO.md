# Danish's Action Checklist (Bookly Phase 2 — Ebooks)

When you are ready to deploy and test, follow these steps in this exact order:

---

### Step 1: Cloudflare R2 (Private Bucket)
- [ ] Log in to **Cloudflare Dashboard** → **R2 Object Storage**.
- [ ] Click **Create bucket** and name it (e.g. `bookly-ebooks-private`).
- [ ] **Important**: Keep it **PRIVATE** (do NOT enable Public Access or connect a custom domain; ebooks must never be directly accessible via URL).
- [ ] Go to **R2 Manage API Tokens** → **Create API Token**:
  - Permissions: **Object Read & Write** (scoped to your new private bucket).
  - Note down:
    - **Endpoint**: `https://<account-id>.r2.cloudflarestorage.com`
    - **Access Key ID**
    - **Secret Access Key**

---

### Step 2: Render Environment Variables
- [ ] Open **Render Dashboard** → Your `bookly_backend_v2` service → **Environment**.
- [ ] Add the following variables:
  ```env
  EBOOK_DISK=r2_private
  R2_PRIVATE_BUCKET=bookly-ebooks-private
  R2_PRIVATE_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
  R2_PRIVATE_ACCESS_KEY_ID=<your-access-key-id>
  R2_PRIVATE_SECRET_ACCESS_KEY=<your-secret-access-key>
  ```
- [ ] Save the changes.

---

### Step 3: Merge Pull Requests (Order Matters!)
- [ ] **1st — Backend PR**:
  - URL: https://github.com/danishsary8/bookly_backend_v2/pull/new/feature/ebook-reader
  - Create and merge to `main`.
  - Wait for Render to finish deploying and running migrations (`2026_11_06_100001_create_ebook_tables.php`).
- [ ] **2nd — Frontend PR**:
  - URL: https://github.com/danishsary8/bookly_frontend/pull/new/feature/ebook-reader
  - Create and merge to `main`.
  - Wait for Vercel to finish building and deploying.

---

### Step 4: Verification & Smoke Test
- [ ] **Staff Upload**: Log in to `/admin/books`, edit a book, go to the **Ebook (PDF Reader)** panel, and upload a test PDF (≤ 50 MB).
- [ ] **Render Processing**: Wait 10–30s for the background worker (`ebooks:render`) to render page images. Check that status turns green: `Ready (N pages)`.
- [ ] **Preview Test**: Open `/read/{bookId}/preview` in an incognito window without signing in. Verify page rendering and "Bookly · free preview" watermark.
- [ ] **Customer Reader Test**: Sign in to a customer account that purchased the ebook. Open `/read/{bookId}` on phone (390px) and laptop. Verify:
  - Watermark: `"Bookly · {email} · {order_number}"`.
  - Dark/Light/Sepia themes.
  - Page navigation and progress saving.
- [ ] **Device Management**: Check `/account/ebooks` to verify your browser is registered and test device limits.
