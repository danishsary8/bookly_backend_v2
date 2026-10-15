# Rate-Limiting Audit & Abuse Threat Model

Comprehensive audit of rate-limiting configurations, middleware, controller-level protections, and abuse vectors across the Bookly API (`/api/v1/`).

---

> **Status 2026-10-13 / 2026-10-15:** applied in `feature/rate-limits` and `fix/login-limit-per-email` with the owner's numbers: auth endpoints (login, register, reset) limited to 5/min per email+IP, 30/min per IP, plus 20/hour per email across any IP to block distributed credential stuffing; checkout 5 per 5 min + 20 per hour per customer, coupon check 10/min + a 15-minute lock after 5 unknown codes in a row, `GET /books` 60/min with `q` and 120/min without, Telegram status 60/min per IP + link, dashboard 30/min per staff member, Telegram webhook calls with the right secret not limited. The tables below are the original proposal; `docs/API.md` lists what is live.

## 1. Audited Endpoints Table

The API enforces a global base limiter on all routes defined via `$middleware->throttleApi('api')` in [`bootstrap/app.php:30`](../bootstrap/app.php#L30) and [`AppServiceProvider.php:27-28`](../app/Providers/AppServiceProvider.php#L27-L28) (120 req/min keyed by authenticated model key or guest IP). Endpoints below list their effective rate limiters, abuse vectors, and recommendations.

| Method | Path | Access / Role | Current Throttle | Abuse Vector & Risk | Recommended Limit | Rationale |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/ping` | Public | `api` (120/min per IP) | Cheap ping flood; server resource exhaustion | 120/min per IP | Sufficient for basic health / uptime heartbeat. |
| `GET` | `/api/v1/health` | Public | `api` (120/min per IP) | Database query `select 1`, queue count, Telegram webhook check via HTTP call or cache | 30/min per IP | Prevents external polling monitors or attackers from inducing DB or cache connection churn. |
| `POST` | `/api/v1/telegram/webhook` | Telegram Server (secret header) | `api` (120/min per IP) [`routes/api.php:49`](../routes/api.php#L49) | Webhook flooding, replay attacks, spamming bot state transitions | Dedicated webhook limiter (e.g. 300/min per IP or exempt if valid token verified) | Telegram webhook traffic can burst during high concurrency; throttling by raw IP might drop valid Telegram Bot API updates if shared with proxies. |
| `GET` | `/api/v1/books` | Public | `api` (120/min per IP) [`routes/api.php:54`](../routes/api.php#L54) | Heavy PostgreSQL full-text search (`tsvector` with websearch queries), complex joins and ordering | 60/min per IP | Expensive queries with complex ILIKE and text matching should not overwhelm PostgreSQL on Render/Neon. |
| `GET` | `/api/v1/books/{id}` | Public | `api` (120/min per IP) [`routes/api.php:55`](../routes/api.php#L55) | Catalog scraping, cache busting | 120/min per IP | Standard browsing limit is adequate. |
| `GET` | `/api/v1/books/{id}/reviews` | Public | `api` (120/min per IP) [`routes/api.php:56`](../routes/api.php#L56) | Scraping, review farming queries | 120/min per IP | Standard reading traffic. |
| `GET` | `/api/v1/authors` | Public | `api` (120/min per IP) [`routes/api.php:57`](../routes/api.php#L57) | Scraping | 120/min per IP | Unpaginated lookup; standard limiter. |
| `GET` | `/api/v1/authors/{id}` | Public | `api` (120/min per IP) [`routes/api.php:58`](../routes/api.php#L58) | Scraping | 120/min per IP | Standard limiter. |
| `GET` | `/api/v1/categories` | Public | `api` (120/min per IP) [`routes/api.php:59`](../routes/api.php#L59) | Scraping | 120/min per IP | Unpaginated category tree. |
| `GET` | `/api/v1/publishers` | Public | `api` (120/min per IP) [`routes/api.php:60`](../routes/api.php#L60) | Scraping | 120/min per IP | Standard lookup. |
| `GET` | `/api/v1/series` | Public | `api` (120/min per IP) [`routes/api.php:61`](../routes/api.php#L61) | Scraping | 120/min per IP | Standard lookup. |
| `GET` | `/api/v1/series/{id}` | Public | `api` (120/min per IP) [`routes/api.php:62`](../routes/api.php#L62) | Scraping | 120/min per IP | Standard lookup. |
| `GET` | `/api/v1/auth/options` | Public | `api` (120/min per IP) [`routes/api.php:68`](../routes/api.php#L68) | Health reconnaissance of enabled auth features (Telegram bot) | 60/min per IP | Static config check. |
| `POST` | `/api/v1/newsletter/subscribe` | Public | `throttle:newsletter-subscribe` (5/hr per IP) | Inbox spamming, verification mail flooding | 5/hour per IP | 5 per hour per IP prevents automated subscription spam. |
| `POST` | `/api/v1/auth/register` | Public | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:70`](../routes/api.php#L70) + Turnstile check [`AuthController.php:54`](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L54) | Fake account creation, database bloat, OTP email spam | 3/min per IP, 10/hour per IP | Registration is a high-cost event involving password hashing and OTP issuance. 5/min allows up to 300 accounts/hour per IP. |
| `POST` | `/api/v1/auth/login` | Public | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:71`](../routes/api.php#L71) + Turnstile check [`AuthController.php:115`](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L115) | Credential stuffing, password brute force | 5/min per email+IP, 20/min per IP | Current limit is sound; accounts also require Turnstile when enabled. |
| `POST` | `/api/v1/auth/reset-password` | Public | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:72`](../routes/api.php#L72) | 6-digit OTP guessing for password takeover | 5/min per email+IP, 15/min per IP (plus code invalidated after 5 wrong attempts via `OtpService:101`) | Existing 5 wrong guess invalidation protects against brute force; throttle prevents timing attacks. |
| `POST` | `/api/v1/auth/social/{provider}` | Public | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:73`](../routes/api.php#L73) | Third-party OAuth token replay, account linking abuse | 10/min per IP | Relies on external verification via `SocialTokenVerifier`. |
| `POST` | `/api/v1/auth/forgot-password` | Public | `throttle:otp-send` (3 per 10 min per email+IP) [`routes/api.php:75`](../routes/api.php#L75) + Turnstile [`AuthController.php:180`](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L180) | Inbox spamming, email provider quota exhaustion | 3 per 10 min per email+IP, 10 per hour per IP | Prevents distributed inbox flooding across many target emails. |
| `POST` | `/api/v1/auth/telegram` | Public | `throttle:telegram-link` (10 per 10 min per user+IP) [`routes/api.php:76`](../routes/api.php#L76) | Cache bloat (creating disposable auth links in cache) | 10 per 10 min per IP | Current throttle is well calibrated. |
| `POST` | `/api/v1/auth/telegram/status` | Public | `api` only (120/min per IP) [`routes/api.php:77`](../routes/api.php#L77) | Cache polling hammering | 30/min per IP or 1 poll every 2s per key | Polling without dedicated throttle allows clients to loop aggressively against Redis/Cache. |
| `POST` | `/api/v1/auth/logout` | Customer | `api` (120/min) [`routes/api.php:80`](../routes/api.php#L80) | Session deletion churn | 60/min per user | Standard logout endpoint. |
| `POST` | `/api/v1/auth/verify-email` | Customer | `throttle:auth` (5/min per email+IP, 30/min per IP) [`routes/api.php:81`](../routes/api.php#L81) | 6-digit OTP code guessing | 5/min per user+IP | Backed by `OtpService:101` (invalidated after 5 failed guesses). |
| `POST` | `/api/v1/auth/resend-verification` | Customer | `throttle:otp-send` (3 per 10 min per email+IP) [`routes/api.php:82`](../routes/api.php#L82) + Turnstile [`AuthController.php:165`](../app/Http/Controllers/Api/V1/Customer/AuthController.php#L165) | Inbox mail bombardment | 3 per 10 min per user | Well-protected by throttle and Turnstile. |
| `POST` | `/api/v1/auth/telegram/phone` | Customer | `throttle:telegram-link` (10 per 10 min per user+IP) [`routes/api.php:83`](../routes/api.php#L83) | Cache bloat, bot link generation | 10 per 10 min per user | Protected. |
| `GET` | `/api/v1/me` | Customer | `api` (120/min) [`routes/api.php:91`](../routes/api.php#L91) | Profile polling | 120/min per user | Read-only. |
| `PATCH` | `/api/v1/me` | Customer | `api` (120/min) [`routes/api.php:92`](../routes/api.php#L92) | Profile update spam | 30/min per user | Low risk, but updates should be constrained. |
| `PUT` | `/api/v1/me/password` | Customer | `throttle:auth` (5/min per email+IP, 30/min per IP) [`routes/api.php:93`](../routes/api.php#L93) | Password brute force against current password | 5/min per user | Prevents session hijacking guessing customer current password. |
| `DELETE` | `/api/v1/me` | Customer | `throttle:auth` (5/min per email+IP, 30/min per IP) [`routes/api.php:94`](../routes/api.php#L94) | Brute-forcing deletion confirmation password / denial of service | 5/min per user | Highly destructive operation. |
| `POST` | `/api/v1/me/email` | Customer | `throttle:otp-send` (3 per 10 min) [`routes/api.php:95`](../routes/api.php#L95) + custom 5/hour limiter in [`EmailController.php:62-66`](../app/Http/Controllers/Api/V1/Customer/EmailController.php#L62-L66) | Spamming arbitrary third-party email addresses with change codes | 3 per 10 min per user, 5 per hour per user | Excellent multi-layered protection (Turnstile + `otp-send` + `email-change:{user_id}`). |
| `POST` | `/api/v1/me/email/verify` | Customer | `throttle:auth` (5/min) [`routes/api.php:96`](../routes/api.php#L96) | Brute forcing 6-digit email change code | 5/min per user | Backed by `OtpService` invalidation. |
| `POST` | `/api/v1/me/connections/{provider}` | Customer | `throttle:auth` (5/min) [`routes/api.php:97`](../routes/api.php#L97) | Linking unauthorized OAuth identities | 5/min per user | Protected by password check and social verifier. |
| `DELETE` | `/api/v1/me/connections/{provider}` | Customer | `throttle:auth` (5/min) [`routes/api.php:98`](../routes/api.php#L98) | Account lockout / connection churn | 5/min per user | Protected. |
| `GET` | `/api/v1/addresses` | Verified Customer | `api` (120/min) [`routes/api.php:105`](../routes/api.php#L105) | Address data read | 60/min per user | Standard. |
| `POST` | `/api/v1/addresses` | Verified Customer | `api` (120/min) [`routes/api.php:106`](../routes/api.php#L106) | Database row creation spam (max 10 addresses per customer enforced via DB lock [`AddressController.php:34`](../app/Http/Controllers/Api/V1/Customer/AddressController.php#L34)) | 30/min per user | Capped at 10 rows in code. |
| `PATCH` | `/api/v1/addresses/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:107`](../routes/api.php#L107) | Address mutation churn | 60/min per user | Standard. |
| `DELETE` | `/api/v1/addresses/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:108`](../routes/api.php#L108) | Address deletion churn | 60/min per user | Standard. |
| `GET` | `/api/v1/cart` | Verified Customer | `api` (120/min) [`routes/api.php:110`](../routes/api.php#L110) | Polling cart calculations | 60/min per user | Standard. |
| `DELETE` | `/api/v1/cart` | Verified Customer | `api` (120/min) [`routes/api.php:111`](../routes/api.php#L111) | Repeated table deletions | 30/min per user | Standard. |
| `POST` | `/api/v1/cart/items` | Verified Customer | `api` (120/min) [`routes/api.php:112`](../routes/api.php#L112) | Cart row spam, inventory reservation locking | 60/min per user | Row locking during checkout prevents race condition; add is capped per line. |
| `PATCH` | `/api/v1/cart/items/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:113`](../routes/api.php#L113) | Quantity fluctuation | 60/min per user | Standard. |
| `DELETE` | `/api/v1/cart/items/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:114`](../routes/api.php#L114) | Line deletion churn | 60/min per user | Standard. |
| `POST` | `/api/v1/cart/coupon/check` | Verified Customer | `throttle:10,1` (10/min) [`routes/api.php:116`](../routes/api.php#L116) | Coupon dictionary search / brute force | 5/min per user, 15/min per IP | Critical financial control: 10 attempts/min per user is slightly generous for guessing short coupon strings. |
| `POST` | `/api/v1/checkout/preview` | Verified Customer | `throttle:30,1` (30/min) [`routes/api.php:118`](../routes/api.php#L118) | Repeated pricing recalculation and currency exchange query | 15/min per user | 30/min is reasonable for UI live updates. |
| `POST` | `/api/v1/checkout` | Verified Customer | `throttle:10,1` (10/min) [`routes/api.php:119`](../routes/api.php#L119) | Order spam, inventory exhaustion (locking physical stock), fake COD orders | 3/min per user, 10/hour per user | Real money / physical fulfillment vector: placed COD orders decrement actual inventory and queue staff notifications. |
| `GET` | `/api/v1/orders` | Verified Customer | `api` (120/min) [`routes/api.php:120`](../routes/api.php#L120) | Order history polling | 60/min per user | Standard. |
| `GET` | `/api/v1/orders/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:121`](../routes/api.php#L121) | Order tracking | 60/min per user | Standard. |
| `POST` | `/api/v1/orders/{id}/cancel` | Verified Customer | `api` (120/min) [`routes/api.php:122`](../routes/api.php#L122) | Rapid order cancellation and stock bounce | 10/min per user | Only applies while `pending`; restocks inventory. |
| `GET` | `/api/v1/orders/{id}/returnable-items` | Verified Customer | `api` (120/min) [`routes/api.php:123`](../routes/api.php#L123) | Eligibility check | 60/min per user | Standard. |
| `POST` | `/api/v1/orders/{id}/returns` | Verified Customer | `api` (120/min) [`routes/api.php:124`](../routes/api.php#L124) | Return request spam, operational staff overload | 5/min per user | Creating returns requires staff action and audit log rows. |
| `GET` | `/api/v1/returns` | Verified Customer | `api` (120/min) [`routes/api.php:125`](../routes/api.php#L125) | Polling returns | 60/min per user | Standard. |
| `GET` | `/api/v1/returns/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:126`](../routes/api.php#L126) | Return detail | 60/min per user | Standard. |
| `DELETE` | `/api/v1/returns/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:127`](../routes/api.php#L127) | Withdraw return churn | 10/min per user | Allowed only while `requested`. |
| `GET` | `/api/v1/reviews` | Verified Customer | `api` (120/min) [`routes/api.php:129`](../routes/api.php#L129) | Own reviews list | 60/min per user | Standard. |
| `POST` | `/api/v1/books/{id}/reviews` | Verified Customer | `throttle:10,1` (10/min) [`routes/api.php:130`](../routes/api.php#L130) | Review spam, rating manipulation | 5/min per user | Requires verified purchase (`order_item_id`) and exactly one review per book per customer. |
| `PATCH` | `/api/v1/reviews/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:131`](../routes/api.php#L131) | Comment editing churn / spam | 10/min per user | Editing a hidden review does not unhide it, but spamming edits generates database churn. |
| `DELETE` | `/api/v1/reviews/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:132`](../routes/api.php#L132) | Review deletion churn | 10/min per user | Standard. |
| `GET` | `/api/v1/wishlist` | Verified Customer | `api` (120/min) [`routes/api.php:134`](../routes/api.php#L134) | Reading wishlist | 60/min per user | Standard. |
| `POST` | `/api/v1/wishlist` | Verified Customer | `api` (120/min) [`routes/api.php:135`](../routes/api.php#L135) | Wishlist row spam | 60/min per user | Sync without detaching prevents duplicate records. |
| `DELETE` | `/api/v1/wishlist/{id}` | Verified Customer | `api` (120/min) [`routes/api.php:136`](../routes/api.php#L136) | Wishlist removal | 60/min per user | Standard. |
| `POST` | `/api/v1/staff/auth/login` | Staff | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:144`](../routes/api.php#L144) | Staff credential stuffing | 5/min per email+IP, 15/min per IP | Generates 2FA challenge token. |
| `POST` | `/api/v1/staff/auth/two-factor/challenge` | Staff | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:145`](../routes/api.php#L145) + account lockout in [`AuthController.php:26-28`](../app/Http/Controllers/Api/V1/Staff/AuthController.php#L26-L28) | TOTP 6-digit brute force | 5/min per IP (locked after 5 wrong codes) | Account locks for 15 minutes after 5 failed TOTP attempts. |
| `POST` | `/api/v1/staff/auth/reset-password` | Staff | `throttle:auth` (5/min per email+IP, 30/min per IP, 20/hr per email) [`routes/api.php:146`](../routes/api.php#L146) | Staff password reset takeover | 5/min per email+IP | Backed by `OtpService` invalidation. |
| `POST` | `/api/v1/staff/auth/forgot-password` | Staff | `throttle:otp-send` (3 per 10 min) [`routes/api.php:148`](../routes/api.php#L148) | Staff inbox spamming | 3 per 10 min | Mail quota protection. |
| `GET` | `/api/v1/staff/auth/me` | Staff | `api` (120/min) [`routes/api.php:151`](../routes/api.php#L151) | Staff token verify | 120/min | Standard. |
| `POST` | `/api/v1/staff/auth/logout` | Staff | `api` (120/min) [`routes/api.php:152`](../routes/api.php#L152) | Session termination | 60/min | Standard. |
| `POST` | `/api/v1/staff/auth/two-factor/setup` | Staff | `api` (120/min) [`routes/api.php:153`](../routes/api.php#L153) | TOTP secret generation | 10/min | Setup is rare. |
| `POST` | `/api/v1/staff/auth/two-factor/confirm` | Staff | `throttle:auth` (5/min per email+IP, 30/min per IP) [`routes/api.php:154`](../routes/api.php#L154) | TOTP confirmation guessing | 5/min | Protected. |
| `GET` | `/api/v1/staff/notifications` | Staff (2FA) | `api` (120/min) [`routes/api.php:162`](../routes/api.php#L162) | Polling notification counts | 60/min | Polled by admin dashboard UI. |
| `POST` | `/api/v1/staff/notifications/read-all` | Staff (2FA) | `api` (120/min) [`routes/api.php:163`](../routes/api.php#L163) | Mass notification update | 30/min | Standard. |
| `POST` | `/api/v1/staff/notifications/{id}/read` | Staff (2FA) | `api` (120/min) [`routes/api.php:164`](../routes/api.php#L164) | Single notification update | 60/min | Standard. |
| `GET` | `/api/v1/staff/orders` | Staff (2FA) | `api` (120/min) [`routes/api.php:166`](../routes/api.php#L166) | Order querying / filtering | 60/min | Heavy query with search and filters. |
| `GET` | `/api/v1/staff/orders/{id}` | Staff (2FA) | `api` (120/min) [`routes/api.php:167`](../routes/api.php#L167) | Order detail lookup | 120/min | Standard. |
| `POST` | `/api/v1/staff/orders/{id}/status` | Staff (2FA) | `api` (120/min) [`routes/api.php:168`](../routes/api.php#L168) | State machine changes, inventory restoration, audit log bloat | 30/min per staff | Changes order state, triggers inventory movement, logs audit entry. |
| `GET` | `/api/v1/staff/returns` | Staff (2FA) | `api` (120/min) [`routes/api.php:170`](../routes/api.php#L170) | Return management listing | 60/min | Standard. |
| `GET` | `/api/v1/staff/returns/{id}` | Staff (2FA) | `api` (120/min) [`routes/api.php:171`](../routes/api.php#L171) | Return detail | 120/min | Standard. |
| `POST` | `/api/v1/staff/returns/{id}/approve` | Staff (2FA) | `api` (120/min) [`routes/api.php:172`](../routes/api.php#L172) | State transition spam | 30/min per staff | Audit logged. |
| `POST` | `/api/v1/staff/returns/{id}/reject` | Staff (2FA) | `api` (120/min) [`routes/api.php:173`](../routes/api.php#L173) | State transition spam | 30/min per staff | Audit logged. |
| `POST` | `/api/v1/staff/returns/{id}/refund` | Staff (2FA) | `api` (120/min) [`routes/api.php:174`](../routes/api.php#L174) | Financial state transition, inventory restocking, audit logging | 20/min per staff | High-impact action (money & stock). |
| `GET` | `/api/v1/staff/customers` | Staff (2FA) | `api` (120/min) [`routes/api.php:176`](../routes/api.php#L176) | PII customer scraping | 60/min per staff | Reads customer records and counts. |
| `GET` | `/api/v1/staff/customers/{id}` | Staff (2FA) | `api` (120/min) [`routes/api.php:177`](../routes/api.php#L177) | PII detail and order stats aggregation | 60/min per staff | Computes lifetime spend (admin only) and order breakdown. |
| `GET` | `/api/v1/staff/customers/{id}/notes` | Staff (2FA) | `api` (120/min) | Staff customer notes list | 60/min per staff | Audit & customer service history. |
| `POST` | `/api/v1/staff/customers/{id}/notes` | Staff (2FA) | `api` (120/min) | Customer note spam | 30/min per staff | Audit logged. |
| `DELETE` | `/api/v1/staff/customer-notes/{id}` | Staff (2FA) | `api` (120/min) | Note deletion churn | 30/min per staff | Restricted to note author or admin; audit logged. |
| `PUT` | `/api/v1/staff/customers/{id}/tags` | Staff (2FA) | `api` (120/min) | Customer tag churn | 30/min per staff | Max 10 tags per customer; audit logged. |
| `GET` | `/api/v1/staff/customer-tags` | Staff (2FA) | `api` (120/min) | Tag suggestion query flood | 60/min per staff | Top 20 tags with counts. |
| `POST` | `/api/v1/staff/customers/{id}/resend-verification` | Staff (2FA) | Controller rate limited: 3/hour per customer | Email spamming to unverified customer | 3 per hour per customer | Limit 3 per hour enforced via RateLimiter; audit logged. |
| `GET` | `/api/v1/staff/reviews` | Staff (2FA) | `api` (120/min) [`routes/api.php:179`](../routes/api.php#L179) | Moderation listing | 60/min | Standard. |
| `POST` | `/api/v1/staff/reviews/{id}/hide` | Staff (2FA) | `api` (120/min) [`routes/api.php:180`](../routes/api.php#L180) | Visibility moderation | 30/min | Audit logged. |
| `POST` | `/api/v1/staff/reviews/{id}/show` | Staff (2FA) | `api` (120/min) [`routes/api.php:181`](../routes/api.php#L181) | Visibility moderation | 30/min | Audit logged. |
| `GET` | `/api/v1/staff/books` | Staff (2FA) | `api` (120/min) [`routes/api.php:183`](../routes/api.php#L183) | Inventory & book list | 60/min | Standard. |
| `POST` | `/api/v1/staff/books` | Staff (2FA) | `api` (120/min) [`routes/api.php:185`](../routes/api.php#L185) | Catalog spam / DB write churn | 30/min | Audit logged. |
| `POST` | `/api/v1/staff/books/{id}/variants` | Staff (2FA) | `api` (120/min) [`routes/api.php:187`](../routes/api.php#L187) | Inventory movement generation | 30/min | Logs initial restock movement and audit log. |
| `PATCH` | `/api/v1/staff/variants/{id}` | Staff (2FA) | `api` (120/min) [`routes/api.php:188`](../routes/api.php#L188) | Stock adjustment logging | 30/min | Generates adjustment inventory movements. |
| `POST` | `/api/v1/staff/books/bulk` | Staff (2FA) | `api` (120/min) | Mass book updates | 20/min | Bulk category and status updates. |
| `POST` | `/api/v1/staff/variants/bulk` | Staff (2FA) | `api` (120/min) | Mass variant updates | 20/min | Bulk stock, price, and active flag updates. |
| `POST` | `/api/v1/staff/books/import/preview` | Admin (2FA) | `throttle:book-import` (10/hour) | CSV parsing and validation compute load | 10/hour per admin | Parses up to 2,000 rows, caches preview for 30 minutes. |
| `POST` | `/api/v1/staff/books/import/confirm` | Admin (2FA) | `throttle:book-import` (10/hour) | Heavy transactional catalog writes | 10/hour per admin | Applies validated CSV rows in single transaction with audit logging. |
| `POST` | `/api/v1/staff/coupons` | Staff (2FA) | `api` (120/min) [`routes/api.php:199`](../routes/api.php#L199) | Promotion coupon generation | 30/min | Audit logged. |
| `POST` | `/api/v1/staff/members` | Admin (2FA) | `api` (120/min) [`routes/api.php:204`](../routes/api.php#L204) | Staff invitation spam, mail quota consumption | 10/min per admin | Sends 72-hour invitation email. |
| `POST` | `/api/v1/staff/members/{id}/resend-invitation` | Admin (2FA) | `throttle:otp-send` (3 per 10 min) [`routes/api.php:209`](../routes/api.php#L209) | Invitation email spam | 3 per 10 min | Explicitly limited by `otp-send`. |
| `POST` | `/api/v1/staff/customers/{id}/deactivate` | Admin (2FA) | `api` (120/min) [`routes/api.php:210`](../routes/api.php#L210) | Customer account termination | 30/min | Revokes customer tokens; audit logged. |
| `POST` | `/api/v1/staff/customers/{id}/reopen` | Admin (2FA) | `api` (120/min) [`routes/api.php:213`](../routes/api.php#L213) | Reopening accounts, email notifications | 10/min | Emails customer; audit logged. |
| `GET` | `/api/v1/staff/dashboard/summary` | Admin (2FA) | `api` (120/min) [`routes/api.php:217`](../routes/api.php#L217) | Heavy aggregations (gross/net revenue, order counts, best sellers) | 15/min per admin | Aggregates PostgreSQL tables across date ranges; should not be hammered. |
| `GET` | `/api/v1/staff/dashboard/sales` | Admin (2FA) | `api` (120/min) [`routes/api.php:218`](../routes/api.php#L218) | Daily sales breakdown zero-filled over up to 366 days | 15/min per admin | Heavy multi-day aggregation query. |
| `GET` | `/api/v1/staff/orders/export` | Admin (2FA) | `throttle:10,1` (10/min) [`routes/api.php:219`](../routes/api.php#L219) | Massive CSV streaming (up to 366 days, chunks of 500) | 5/min per admin | Heavy streaming response with UTF-8 BOM. |

---

## 2. Gaps Found (Ordered by Risk)

### Gap 1: Money / Inventory — Uncapped Order Creation & Cancellation (`POST /checkout`, `POST /orders/{id}/cancel`)
- **Location:** [`routes/api.php:119`](../routes/api.php#L119), [`routes/api.php:122`](../routes/api.php#L122)
- **Risk Type:** Money & Inventory Denial-of-Service.
- **Analysis:** `POST /checkout` is limited only to 10 requests per minute (`throttle:10,1`). In an e-commerce shop offering Cash On Delivery (COD), creating 10 orders a minute locks and decrements physical inventory in `inventory_movements` (via `InventoryService::deductForSale`). An attacker with a verified account could exhaust physical inventory for popular books without paying a single dollar. Furthermore, `POST /orders/{id}/cancel` only has the baseline `api` 120/min limit. Rapidly alternating between placing orders and cancelling them can flood the inventory log, lock variant rows repeatedly, and trigger notifications to all staff.
- **Remediation:** Introduce a specific `checkout` rate limiter allowing max 3 successful checkout attempts per 5 minutes per customer, and cap total placed orders per customer per hour.

### Gap 2: Sign-In / Account Takeover — Telegram Auth Status Polling (`POST /auth/telegram/status`)
- **Location:** [`routes/api.php:77`](../routes/api.php#L77)
- **Risk Type:** Sign-in Security & Cache Hammering.
- **Analysis:** When a user initiates Telegram authentication, the frontend polls `POST /auth/telegram/status` with `{ "key": "..." }`. This endpoint has **no dedicated rate limiter** and falls back to the generic `api` limiter (120 req/min). A browser script or bot can poll up to 2 requests per second. While `findByKey()` is cached, repeated unthrottled requests against cache/Redis consume execution cycles and could allow timing attacks on key validation.
- **Remediation:** Throttle `POST /auth/telegram/status` to 30 requests per minute per IP, or key by `ip + key`.

### Gap 3: Data / Scraping — Public Catalog Search Queries (`GET /books`)
- **Location:** [`routes/api.php:54`](../routes/api.php#L54), [`BookController.php:39-65`](../app/Http/Controllers/Api/V1/Catalog/BookController.php#L39-L65)
- **Risk Type:** Data Scraping & PostgreSQL Resource Exhaustion.
- **Analysis:** The public catalog endpoint allows complex full-text searches (`q`), multi-column filters, and dynamic ordering (`relevance`, `price`, `rating`). Under the default `api` limiter (120 req/min per IP), an automated scraper can submit 2 queries per second. On Neon/Render free instances with compute limits (0.5 CPU / shared vCPU), complex tsvector queries with `to_tsquery` and subqueries (`withMin`, `withAvg`, `withCount`) can saturate the database CPU.
- **Remediation:** Implement a `catalog-search` limiter allowing 40 requests/min for text-search queries (`q` parameter present) or 60 requests/min overall per IP.

### Gap 4: Spam / Financial Reconnaissance — Coupon Brute-Forcing (`POST /cart/coupon/check`)
- **Location:** [`routes/api.php:116`](../routes/api.php#L116)
- **Risk Type:** Financial / Coupon Leakage.
- **Analysis:** `POST /cart/coupon/check` is throttled to 10 requests per minute (`throttle:10,1`). However, coupon codes are often short marketing codes (e.g., `SAVE10`, `WELCOME5`, `BOOK20`). A distributed attack across rotating IPs or multiple accounts could still test 600 combinations per hour.
- **Remediation:** Lower the limit to 5 per minute per customer and add an exponential backoff or 15-minute cooldown after 5 consecutive failed coupon attempts on the customer's cart.

### Gap 5: Operations / Heavy Computations — Admin Dashboard & Export (`GET /dashboard/*`, `GET /orders/export`)
- **Location:** [`routes/api.php:217-219`](../routes/api.php#L217-L219)
- **Risk Type:** Backend Denial-of-Service / Resource Starvation.
- **Analysis:** While `GET /orders/export` is throttled to 10/min, `GET /dashboard/summary` and `GET /dashboard/sales` fall under the default 120/min `api` limiter. These endpoints run extensive date-range aggregation queries over `orders`, `order_items`, `order_status_history`, and `returns`. If an admin account or compromised staff token sends 120 requests/min of 366-day custom ranges, Neon compute units will spike and freeze incoming customer checkouts.
- **Remediation:** Introduce an `admin-analytics` limiter capping dashboard requests to 20/min per staff user.

---

## 3. Proposed Changes (Pseudocode Definitions)

All rate limiters should be registered in [`app/Providers/AppServiceProvider.php`](../app/Providers/AppServiceProvider.php):

```php
// In AppServiceProvider::boot()

// 1. Checkout Limiter: 3 orders per 5 min per user; 10 per hour per user
RateLimiter::for('checkout', function (Request $request) {
    $userKey = $request->user()?->getKey() ?? $request->ip();
    return [
        Limit::perMinutes(5, 3)->by("checkout-short:{$userKey}"),
        Limit::perHour(10)->by("checkout-hour:{$userKey}"),
    ];
});

// 2. Coupon Validation Limiter: 5 attempts per min per user
RateLimiter::for('coupon-check', function (Request $request) {
    $userKey = $request->user()?->getKey() ?? $request->ip();
    return Limit::perMinute(5)->by("coupon:{$userKey}");
});

// 3. Telegram Polling Limiter: 30 requests per minute per IP + key
RateLimiter::for('telegram-status', function (Request $request) {
    $key = (string) $request->input('key', 'default');
    return Limit::perMinute(30)->by("tg-poll:{$request->ip()}:{$key}");
});

// 4. Public Catalog Search: 40/min if searching, 100/min if browsing
RateLimiter::for('catalog', function (Request $request) {
    if ($request->filled('q')) {
        return Limit::perMinute(40)->by('search:'.$request->ip());
    }
    return Limit::perMinute(100)->by('browse:'.$request->ip());
});

// 5. Admin Analytics / Reporting: 20/min per staff user
RateLimiter::for('admin-analytics', function (Request $request) {
    $staffId = $request->user()?->getKey() ?? $request->ip();
    return Limit::perMinute(20)->by("analytics:{$staffId}");
});

// 6. Registration Limiter: 3 per 10 minutes per IP
RateLimiter::for('registration', function (Request $request) {
    return [
        Limit::perMinutes(10, 3)->by('reg:'.$request->ip()),
        Limit::perDay(20)->by('reg-day:'.$request->ip()),
    ];
});
```

### Proxy & Client IP Considerations on Render
Render sits behind Cloudflare / edge reverse proxies:
- In [`AppServiceProvider.php:23-24`](../app/Providers/AppServiceProvider.php#L23-L24), `TrustProxies::at()` is configured from `config('app.trusted_proxies')`.
- On Render, `TRUSTED_PROXIES` must be set to `REMOTE_ADDR` (as noted in [`DEPLOYMENT.md:59`](../docs/DEPLOYMENT.md#L59) and [`WORKLOG.md:124`](../docs/WORKLOG.md#L124)). **Never set `*`**: setting `*` causes Laravel 13 to trust untrusted client-supplied `X-Forwarded-For` headers, allowing attackers to forge arbitrary visitor IPs and completely bypass IP-based rate limiting.
- When throttling authenticated routes, always throttle by `User Model Class + ID` (`class_basename($user).':'.$user->getKey()`) rather than solely IP, so multiple customers sharing an office or mobile carrier NAT IP are not blocked by one aggressive user.

---

## 4. Open Questions for the Owner

1. **Guest Checkout / Guest Cart Consideration:**
   * Currently, cart and checkout require authenticated and verified customers (`['auth:sanctum', 'abilities:customer', 'verified.customer']` at [`routes/api.php:104`](../routes/api.php#L104)). If guest checkout is ever added in future phases, should checkout throttling be strictly tied to IP + phone number + email hash?
   * *Recommendation:* Keep customer-only checkout; it significantly reduces spam orders.
2. **Telegram Webhook Throttling:**
   * Should Telegram's incoming webhook `POST /api/v1/telegram/webhook` ([`routes/api.php:49`](../routes/api.php#L49)) be completely exempt from the `api` 120/min limiter once `X-Telegram-Bot-Api-Secret-Token` matches?
   * *Recommendation:* Yes, exempt valid webhook tokens from standard rate limiting, as burst events (e.g. 10 users tapping the bot simultaneously) could cause Telegram to receive 429 and retry needlessly.
3. **Coupon Failure Cooldown:**
   * Should 5 failed coupon checks temporarily lock coupon submission for that customer for 15 minutes?
   * *Recommendation:* Yes, this stops automated dictionary attacks against voucher codes.
4. **Registration Limits for Cambodian Mobile Networks:**
   * In Cambodia (Cellcard, Smart, Metfone), cellular mobile data networks frequently use Carrier-Grade NAT (CGNAT), where hundreds of users share a small pool of public IP addresses. Strict per-IP registration caps (e.g. 3/hour per IP) could prevent legitimate users at the same university or coffee shop from signing up.
   * *Recommendation:* Rely on Cloudflare Turnstile bot challenges as the primary defense, keeping IP limits at 10-15 per hour.
