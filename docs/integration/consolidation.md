# Backend consolidation — 2026-11-16

Review branch: `review/backend-complete-updates`, targeting `main`.
Frontend companion: `danishsary8/bookly_frontend`, branch `review/frontend-complete-updates`.

This branch combines the outstanding remote and local branch histories with the previously uncommitted activity/campaign API work. `branch-manifest.json` records incoming tips. Historical worklog additions are preserved separately in `historical-branch-notes.md`; their original deployment/merge claims are not current verification. Original branches remain available. Local recovery commit: `d038821`.

## Conflict and overlap decisions

| Incoming work | Resolution |
| --- | --- |
| `docs/overnight-batch` | Keep current strict deletion, two-step verification, permissions, sessions and device detection. Avoid restoring older implementations or a duplicate migration. |
| `feature/ebook-reader-api` | Keep the reviewed reader already on main, including subsequent page-rendering changes. No duplicate ebook schema or old endpoint implementation is introduced. |
| `feature/search-authors-series` | Keep current multilingual/prefix/SKU/ISBN search and the existing name-index migration, dated 2026-10-13. The old 2026-10-07 equivalent migration would create duplicate index names. |
| `feature/real-catalog-data`, `feature/book-spine-assets` | Include separate batch CSVs, cover tools, ISBN generation deduplication, category guards and spine assets. Keep main's current master books.csv and covers_manifest.csv; the older overlapping dataset is retained in Git history. No import or cleanup command is run. |
| Documentation branches | Retain plans, audits, staging instructions, Postman collection, owner handoff/checklists and historical notes. Keep the current API reference and newer implementation details when they overlap. |
| Dependabot and staging setup | Include the pinned setup-php update and staging CI trigger. |

## Integration fixes for optional catalogue tools

- CSV apply retains its existing default behaviour. Optional automatic cover copying requires `CATALOG_INGEST_COVERS=true`; no environment is enabled by this PR.
- Cover ingestion only fetches HTTPS from the exact `covers.openlibrary.org` host, without credentials, custom ports or redirects. It bounds downloads at 5 MB and validates real JPEG/PNG/WebP image content and dimensions. Unsupported hosts, redirects, errors and invalid images retain their source links. Paths include a source-URL hash, MIME-correct extensions and cache reuse. No network or storage changes occur simply by merging.
- `catalog:sync-covers --dry-run` lists candidates without fetching, uploading or updating records. `--force` refreshes cached external sources; already-owned URLs remain unchanged.
- `catalog:delete-batch` now uses the existing Trash lifecycle instead of permanent deletion. Partial edition matches are refused; editions, reviews, relations and historical records remain. Applying requires `--staff=<active-admin-id>` for audit attribution and confirmation, unless `--force` is supplied. `--dry-run` makes no changes. The normal existing Trash retention/purge rules still apply after an operator deliberately trashes books.
- Tests force local/public media and disabled automatic cover ingestion; the cover tests fake both HTTP and storage explicitly.

No schema migration differs from main. Optional CSVs contain historical supplied metadata; this consolidation does not independently verify every title, cover or release date and does not load them into a database.

## Merge and deployment

Merge this backend PR before the frontend companion so the combined audit sources/search and campaign filters are available. Use **Create a merge commit** to preserve the consolidated branch ancestry. Feature flags remain unchanged, and deployment completion must be verified separately after merging.

Combined-branch verification is recorded in `docs/WORKLOG.md`.
