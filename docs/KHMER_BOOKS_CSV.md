# Adding Khmer books by CSV (guide for whoever writes the file)

Admin → Books → **Import CSV**. The import shows a preview first; nothing is saved until you confirm.

## Before you import
1. **Categories must already exist** (the import does not create them). In Admin → Categories, under
   **Khmer books**, add the sub-categories you need, e.g. **Khmer self-help & motivation**.
   Use the exact names in the `categories` column.
2. Authors, publishers and series are created automatically if they are new.

## File rules
- UTF-8 CSV with a header row (Khmer text is fine). Wrap any value with a comma in double quotes.
- One row = one format of a book. Two rows with the same `title` + `authors` become one book with two formats.
- Several authors or categories: separate with `;` (e.g. `Khmer books;Khmer self-help & motivation`).
- `language` = `Khmer` (so the shop's Language filter and the admin Language filter find it).

## Columns
| Column | Needed | Example / allowed |
|---|---|---|
| `title` | yes | ក្បួនរស់នៅប្រកបដោយសុភមង្គល |
| `format` | yes | `paperback`, `hardcover`, `ebook`, `audiobook` |
| `sku` | yes, unique | `KH-HAPPY-LIFE-PB` (A–Z, 0–9, dashes; max 50) |
| `price_usd` | yes | `4.90` |
| `cost_price_usd` | recommended | `2.80` (leave empty for ebooks) |
| `authors` | recommended | `សុខ ចាន់ណា` |
| `categories` | recommended | `Khmer books;Khmer self-help & motivation` |
| `language` | recommended | `Khmer` |
| `description` | recommended | Khmer text, up to 20,000 characters |
| `publisher` | optional | name |
| `series`, `series_order` | for sequels | `រឿងភាគ X`, `1`, `2`, … (same series name on every volume) |
| `page_count` | optional | `220` |
| `publish_date` | optional | `2025-06-01` (YYYY-MM-DD) |
| `isbn` | optional | `9789924000000` |
| `stock_quantity` | printed books | `20` |
| `low_stock_threshold` | optional | `3` |
| `status` | optional | `draft` (safest: check, then publish) or `live` |
| `is_active` | optional | `1` / `0` |
| `cover_image_url` | optional | `https://…/cover.jpg` |

## Example
```csv
title,authors,categories,language,format,sku,price_usd,cost_price_usd,stock_quantity,status,description
ក្បួនរស់នៅប្រកបដោយសុភមង្គល,សុខ ចាន់ណា,Khmer books;Khmer self-help & motivation,Khmer,paperback,KH-HAPPY-LIFE-PB,4.90,2.80,20,draft,"សៀវភៅលើកទឹកចិត្ត…"
```

Tip: import as `draft`, check a few books in the admin, then publish them (bulk **Status** on the Books list).
