# Bookly Real Catalogue Data

This directory contains the verified, real-world catalogue dataset for Bookly (an online bookshop in Cambodia supporting English and Khmer).

All books, variants, ISBNs, authors, and cover images originate from verified bibliographic sources:
- **Open Library** (APIs and Covers API)
- **Google Books API** (volume verification)
- **Ministry of Education, Youth and Sport (MoEYS), Cambodia** (official syllabus and textbook lists)

---

## Files in this Directory

| File | Description | Committed |
|------|-------------|-----------|
| `books.csv` | Full catalogue import file matching `BookCsvImport::HEADERS`. Covers all 12 starter categories. Physical variants only (Paperback / Hardcover). | Yes |
| `covers_manifest.csv` | Audit trail of all verified cover images (ISBN, source URL, width, height, bytes, fetched timestamp). | Yes |
| `missing_covers.csv` | Books imported without a verified cover (e.g. no Open Library cover found, or image < 300x450 px). | Yes |
| `needs_review.csv` | Titles skipped because the ISBN failed checksum validation or could not be found in authoritative bibliographic sources. | Yes |
| `progress.json` | Resumable state tracker for the `catalog:build` command. | Yes |

> [!NOTE]
> Cover image files themselves (`storage/app/catalog-covers/*.jpg`) are gitignored and not committed to the repository to keep git history lightweight. They are saved locally during build and uploaded to Cloudflare R2 / S3 via `catalog:upload-covers`.

---

## How to Import or Seed the Catalogue

### Option A: Via Admin Dashboard (Danish / Staff)
1. Log in to the Bookly Admin dashboard.
2. Navigate to **Books** &rarr; **Import**.
3. Upload `data/catalog/books.csv`.
4. Review the preview and click **Confirm Import**.

### Option B: Via Local Database Seeder
For local development and testing, run:
```bash
php artisan db:seed --class=RealCatalogSeeder
```
*Note: `RealCatalogSeeder` contains a production safeguard. In production environments, it will refuse to run unless `DEMO_SEED_ALLOWED=true` is set.*

The seeder is completely idempotent:
- Checks for existing records by SKU, then by ISBN, then by title + first author + format.
- Automatically copies local cover images from `storage/app/catalog-covers/` to the configured media disk (`config('filesystems.media_disk')`).

---

## How to Upload Covers to Cloudflare R2 / S3

When Danish is ready to upload covers to production or cloud storage:
1. Ensure cloud storage credentials are configured in your local `.env` (never commit secrets):
   ```env
   MEDIA_DISK=r2
   R2_ACCESS_KEY_ID=your-key
   R2_SECRET_ACCESS_KEY=your-secret
   R2_BUCKET=bookly-media
   R2_URL=https://your-r2-public-url.com
   ```
2. Test first with dry-run mode:
   ```bash
   php artisan catalog:upload-covers --dry-run
   ```
3. Run the live upload:
   ```bash
   php artisan catalog:upload-covers
   ```
The command is idempotent: if an image is already present on the storage disk, it skips re-uploading and simply ensures the book variant's `cover_image_url` is updated.

---

## How to Re-Run or Extend the Catalogue Builder

The Artisan command `catalog:build` fetches facts and covers politely with rate limiting ($\le 1$ req/sec), caching responses in `storage/app/catalog-cache/`.

### Run all categories:
```bash
php artisan catalog:build --resume
```

### Run a specific category:
```bash
php artisan catalog:build --category=comics-manga --resume
```

To add more books, edit the corresponding dataset in `app/Services/Catalog/Data/<category-slug>.php` and re-run the builder.
