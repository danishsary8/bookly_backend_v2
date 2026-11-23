# Next step

## Current state (2026-11-23)

### 1. Completed & Live on `main`
- **Core Platform (Steps 1–10)**: 85+ migrations, full catalog, customer & staff auth (mandatory TOTP 2FA + granular Sanctum permissions), COD & Bakong KHQR payments, returns, verified reviews, coupons, offers, promotions, store credit & referrals, support inbox, AI assistant + semantic search (`pgvector`), and 16 scheduled/opportunistic maintenance tasks.
- **Ebooks Phase 2 (`feature/ebook-reader`)**: Merged to `main` (`ReaderController`, `PreviewController`, `EbookFileController`, `EbookPages` batch PDF rendering with local master PDF cache, `EbookDevices` 3-device limit & 30-day removal cooldown).
- **Portal Separation & Operational Guards**: Strict `/admin` vs `/staff` portal login check (`Staff\AuthController`), custom staff permissions on member creation, FIFO preorder allocation fix (`PreorderAllocationService`), cost-price restock guard (`StockController`), and `EmbedBook` dispatch guard.

### 2. Pending Owner Deployment Actions
- **Cloudflare R2 Private Bucket for Ebooks**: Follow `DANISH_TODO.md` to create the private R2 bucket (`bookly-ebooks-private`) and add `EBOOK_DISK=r2_private` + `R2_PRIVATE_*` environment variables in Render.
- **Optional AI Semantic Search / Similar Books**: Set `AI_SIMILAR_ENABLED=true` / `AI_SEARCH_ENABLED=true` and `GEMINI_API_KEY` when ready, then run `php artisan books:embed --all`.
- **Deferred Providers**: Stripe/PayPal card payments (blocked on provider keys) and Facebook OAuth live mode (awaiting custom domain verification).
