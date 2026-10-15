# API Reference (v1)

Base URL: `/api/v1`. Send `Accept: application/json`. Authenticated calls send `Authorization: Bearer <token>`.
Errors: `{"message": "..."}`; validation errors are 422 with `{"message", "errors": {"field": ["..."]}}`. 401 = not logged in / bad credentials, 403 = not allowed (wrong token type, email not verified, 2FA not set up), 429 = rate limited (see `Retry-After`). A 500 only says `{"message": "Server Error"}`.

Interactive reference: open **`/docs`** (Swagger UI over `/openapi.yaml`; the site root `/` redirects there). A test fails if a route is added without documenting it there.

Every response carries **`X-Request-Id`** (a UUID, or your own `X-Request-Id` if you send a safe one of 8-100 chars `A-Z a-z 0-9 . _ -`). It is in every log line and Sentry report, so quote it when reporting a problem.

Browsers: only origins in `CORS_ALLOWED_ORIGINS` may call the API. The frontend can read `X-Request-Id`, `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Idempotent-Replayed` and `Content-Disposition`.

## Platform
| Method | Path | Notes |
| --- | --- | --- |
| GET | /ping | `{"status":"ok","version":"v1"}` |
| GET | /health | `{"status":"ok"}` (HTTP 200) or `{"status":"fail"}` (HTTP 503). Public health check for uptime monitors without exposing internal infrastructure details. Side-effects (such as Telegram webhook auto-repair) run automatically. Detailed diagnostic health metrics are restricted to admins via `GET /staff/health`. |

## Customer auth
| Method | Path | Body | Notes |
| --- | --- | --- | --- |
| GET | /auth/options | — | `{ telegram }`: true once `TELEGRAM_BOT_TOKEN` is set (sign-up, sign-in and Facebook then offer Telegram) |
| POST | /auth/register | name, email (optional with `verify_by=telegram`), password, password_confirmation, phone?, verify_by? (`email`/`telegram`), locale? (`en`/`km`), turnstile_token* | 201 + token + `verify_by`. `email`: sends a 6-digit code (phone? is just a contact number). `telegram` (422 on `verify_by` while the bot is off): no code and no typed number; the website continues with `POST /auth/telegram/phone`, and the number shared in the bot verifies the account (it then signs in with Continue with Telegram). With `telegram` a typed email is not saved yet: it gets an email-change code and `pending_email` is in the answer (and on `GET /me`); `POST /me/email/verify` adds it. An email sign-up takes over an email whose sign-up was never verified (new name/password, old sessions and codes cancelled) and takes an unproven copy off a phone account (security review 2026-10-15); a proven email or a deactivated or deleted account keeps it (422 "An account with this email already exists…"). Forgot/reset password never works through an unproven email on a phone account. |
| POST | /auth/login | email, password, turnstile_token* | token (7 days) |
| POST | /auth/logout | — | auth |
| POST | /auth/verify-email | code | auth. The first verification of an account (email code, Telegram phone, verified social email) sends one welcome email. A code is thrown away after 5 wrong guesses (ask for a new one) |
| POST | /auth/resend-verification | turnstile_token* | auth, 3 per 10 min per visitor. A new email code; 422 on `email` when the account has none |
| POST | /auth/forgot-password | email, turnstile_token* | always 200 |
| POST | /auth/telegram | — | **Continue with Telegram** (free Bookly bot). 201 `{url, key, expires_at}`: open `url` (t.me/<bot>?start=…), tap "Share my phone number", and poll `/auth/telegram/status` with `key` (private to this browser). Signs in the active account that proved the shared +855 number. 10 links per 10 min per visitor; 503 while `TELEGRAM_BOT_TOKEN` is empty. |
| POST | /auth/telegram/phone | password (verified accounts that have one) | auth. Same, to confirm or change the signed-in customer's number: the shared number is saved as verified (and as the contact number), which also verifies a new account. A number on another account (closed ones too) is refused.. Once the account is verified, a new number is a new way in: **New way in** (security review 2026-10-15): accounts with a password type it; accounts without one must have signed in within the last 10 minutes, else 403 `reason: sign_in_again` "For your safety, sign in again first, then try this within 10 minutes." The verified email is told when a number is added. |
| POST | /auth/telegram/status | key | `{status: pending}`, `{status: failed, message}` (no account with this number, number on another account, not +855, deactivated) or `{status: done, customer}` (+ `token`, `expires_at` for a sign-in; handed out once). 404 for an unknown or expired key (links live 10 minutes). |
| POST | /telegram/webhook | Telegram update | Called by Telegram only (header `X-Telegram-Bot-Api-Secret-Token`, set by `php artisan telegram:webhook`); 404 otherwise. Replies are a `sendMessage` call in the answer.. Security review 2026-10-15: the bot asks "Sign in to Bookly?" with **✅ Yes, sign me in (share my number)** and **❌ No, it wasn't me** (No cancels the link: status `failed`, no token). After a Telegram sign-in the bot offers **🚪 Sign me out everywhere** for 24 hours (revokes every token of that account and cancels a sign-in the website hasn't collected yet), and the verified email gets a "New sign-in to Bookly with Telegram" notice. |
| POST | /auth/reset-password | email, code, password, password_confirmation | revokes all tokens, emails a security notice |
| POST | /auth/social/{google\|facebook} | access_token | 201 new / 200 returning (matched by the provider's account id). An email that already has a verified or closed account is **not** linked: 409 "This email already has a Bookly account. Sign in with your email and password instead." (names how that account signs in). An unfinished sign-up with that email becomes the social account (its password and sessions are removed). 401 unless the token was issued to our app (`GOOGLE_CLIENT_ID` / `FACEBOOK_CLIENT_ID`; off until set). Google email must be verified by Google (else 422). **New Facebook accounts confirm a phone number in the Telegram bot, or give an email (owner, 2026-10-08):** the first call answers 422 `{ needs: "phone", profile: { name, email }, telegram }`; call again with the same access_token + `verify_by` (`telegram` / `email`) + email (required with `email`) + turnstile_token*. The email counts as verified only if it's the one Facebook gave. The answer's `verify_by` says what is next: `telegram` (`POST /auth/telegram/phone`), `email` (a code was sent) or null (Facebook's email already verifies the account). |

\* `turnstile_token`: the Cloudflare Turnstile token from the form. Required (422 `turnstile_token` "We couldn't check that you're a person…") once `TURNSTILE_SECRET_KEY` is set; ignored while it's empty. Checked with Cloudflare's siteverify and the visitor's IP; if Cloudflare can't be reached the request is refused.
| GET | /me | — | auth |
| PATCH | /me | name?, phone?, locale? (`en`/`km`) | auth |
| PUT | /me/password | current_password (not needed if social-only), password, password_confirmation | auth, signs out other sessions, emails a security notice ("Your Bookly password was changed"). A **first** password (account without one): **New way in** (security review 2026-10-15): accounts with a password type it; accounts without one must have signed in within the last 10 minutes, else 403 `reason: sign_in_again` "For your safety, sign in again first, then try this within 10 minutes." |
| DELETE | /me | confirm=`DELETE`, password (or provider + access_token for social accounts; fresh sign-in within 10 min for passwordless accounts without verified email) | auth. Closes the account: refused (422) while an order is on its way or a return is open; signs out everywhere, removes reviews, wishlist and cart, hides the account. Accounts without a password or verified email (e.g. Telegram-only) require a fresh token (< 10 min old, else 403 `reason: sign_in_again`). `customers:erase-closed` (daily, and at most hourly from the staff customer list) erases name, email, phone, sign-in ids and addresses after `CLOSED_ACCOUNT_DAYS` (30); orders and returns are kept. |
| POST | /me/email | email, password (when the account has one), turnstile_token* | auth. Add or change the email: emails a 6-digit code to the **new** address (kept aside until confirmed). 422 when the password is wrong, the address is already yours, or another account (also a closed one) uses it. At most 5 codes an hour per account.. **New way in** (security review 2026-10-15): accounts with a password type it; accounts without one must have signed in within the last 10 minutes, else 403 `reason: sign_in_again` "For your safety, sign in again first, then try this within 10 minutes." |
| POST | /me/email/verify | code | auth. Saves the new address as verified, cancels codes sent to the old one, signs out other devices, and emails the old (proven) address "Your Bookly email was changed to n***@…". |
| POST | /me/connections/{google\|facebook} | access_token, password (when the account has one) | auth. Connects the provider: the token must be issued to our app (profile read from the provider); 409 if that provider account is linked to another Bookly account or another one is already connected; 422 if the token can't be verified (not 401: the customer stays signed in). A verified email gets a "was connected" notice.. **New way in** (security review 2026-10-15): accounts with a password type it; accounts without one must have signed in within the last 10 minutes, else 403 `reason: sign_in_again` "For your safety, sign in again first, then try this within 10 minutes." |
| DELETE | /me/connections/{google\|facebook} | — | auth. Disconnects it; 422 `reason: last_way_in` "Add a password first, so you can still sign in." when it's the only way left (`sign_in_methods` on the customer: `password` (with an email), `google`, `facebook`, `telegram` (a verified number, while the Telegram bot is on)). |
| GET | /me/store-credits | — | auth (customer). Active, unused store credit coupons bound to this customer account. |
| GET | /me/stock-alerts | — | auth (customer). All stock alerts for the customer with book and variant details |
| POST | /me/stock-alerts | book_variant_id | auth (customer). 201. Physical, active format of public book currently out of stock. Max 50 active alerts per customer. |
| DELETE | /me/stock-alerts/{id} | — | auth (customer). 204. Removes an alert |

Token response shape: `{ "customer": {...}, "token": "...", "token_type": "Bearer", "expires_at": "ISO-8601" }`.
Customer `verified` = email **or** phone proven. Unverified customers can log in but shopping routes return 403 "Please verify your account first: enter the code we sent you." `email` is null for Facebook accounts that signed up without one. Sign-ups still unverified after `UNVERIFIED_CUSTOMER_HOURS` (48) are deleted by `customers:prune-unverified` (hourly where a scheduler runs; also at most hourly from sign-up and the staff customer list).

## Staff auth
| Method | Path | Body | Notes |
| --- | --- | --- | --- |
| POST | /staff/auth/login | email, password | 2FA on: `{two_factor_required: true, challenge_token}`. 2FA off: token + `two_factor_setup_required: true` |
| POST | /staff/auth/two-factor/challenge | challenge_token, code | returns token (valid 12 hours, `STAFF_TOKEN_HOURS`). Challenge valid 5 min, single use. 5 wrong codes on the account (from any IP) = 429 for 15 min |
| POST | /staff/auth/two-factor/setup | — | auth; returns `secret` + `otpauth_uri` (render as QR) |
| POST | /staff/auth/two-factor/confirm | code | auth; enables 2FA |
| GET | /staff/auth/me | — | auth |
| POST | /staff/auth/logout | — | auth |
| POST | /staff/auth/forgot-password | email | always 200 |
| POST | /staff/auth/reset-password | email, code, password, password_confirmation | 2FA stays on |

Every staff feature route returns 403 `{two_factor_setup_required: true}` until 2FA is confirmed. Admin-only routes need an admin account.

## Rate limits
Every `/api` route: 120 requests per minute per logged-in user, otherwise per IP (`X-RateLimit-*` headers; 429 + `Retry-After`); Telegram's own webhook calls with the right `X-Telegram-Bot-Api-Secret-Token` are not limited. On top of that (owner, 2026-10-13): login/OTP/reset 5 per minute per email+IP and 30 per minute per IP; sending codes 3 per 10 minutes; **checkout** 5 per 5 minutes and 20 per hour per customer; checkout preview 30 per minute; **coupon check** 10 per minute per customer, and 5 codes that don't exist in a row lock it for 15 minutes (429, `Retry-After: 900`; a real but unusable code doesn't count, a right code resets the count); **`GET /books`** with `q` 60 per minute per IP, without `q` 120; **`POST /auth/telegram/status`** 60 per minute per IP and link (the website asks every 2 s); **dashboard** summary/sales 30 per minute per staff member; orders export 10 per minute; posting reviews 10 per minute. Email codes die after 5 wrong guesses; staff 2FA allows 5 wrong codes per account per 15 minutes.

## Public catalog (no login)
| Method | Path | Query / notes |
| --- | --- | --- |
| GET | /books | `q` (search as you type...), `ids` (comma-separated IDs or array, max 12; preserves order; drops missing/hidden), `category_id`, `author_id`, `series_id`, `publisher_id`, `format` (hardcover\|paperback\|ebook\|audiobook), `language`, `min_price`, `max_price`, `in_stock=1`, `sort` (relevance\|newest\|price_asc\|price_desc\|title\|rating; default relevance when `q`, else newest), `per_page` (20, max 100) |
| GET | /books/{id} | detail: description, page_count, categories, publisher, series {id,name,order}, active variants |
| GET | /books/{id}/also-bought | up to 6 recommended book cards that appeared in >= 2 orders together with this book (excluding pending/cancelled), fallback to category best sellers. Cached 1 hour. |
| GET | /authors | `q`, `per_page` — each with `books_count` |
| GET | /authors/{id} | author profile; books via `/books?author_id={id}` |
| GET | /categories | all categories (not paginated), with `books_count` |
| GET | /publishers | `q`, `per_page` |
| GET | /series | `per_page` |
| GET | /series/{id} | series with its books in reading order |

Only books with at least one active variant are public. Book card fields: `id, title, language, publish_date, authors[{id,name}], cover_image_url, price_from_usd, price_from_khr, formats[], in_stock, rating_avg, review_count`. Variant fields: `id, format, isbn, price_usd, price_khr, in_stock, cover_image_url` (exact stock is not public). `price_khr` is whole riel from the latest USD->KHR rate, `null` if no rate exists. Lists are paginated: `{data, links, meta}`.

## Staff catalog (staff token + 2FA; DELETE and restore are admin-only)
| Method | Path | Body |
| --- | --- | --- |
| GET | /staff/books | `q`, `status=draft\|scheduled\|live`, `trashed=1`, `per_page` — all books incl. not yet visible; `is_visible` flag |
| GET | /staff/books/{id} | also works for deleted books; includes `status` and `publish_at` |
| POST | /staff/books | title, description?, status? (admin only; staff always draft), publish_at? (admin only, required if scheduled), publisher_id?, series_id?, series_order?, language? (default English), page_count?, publish_date?, author_ids[]?, category_ids[]? |
| PATCH | /staff/books/{id} | any of the above; `status` and `publish_at` can only be changed by admin; editing live book as staff keeps it live |
| POST | /staff/books/bulk | ids (1-100), add_category_ids?, remove_category_ids?, status? (admin only), publish_at? (admin only) |
| POST | /staff/variants/bulk | ids (1-100), stock_quantity? (sets via adjustment), low_stock_threshold?, is_active?, price_usd? (admin only) |
| GET | /staff/books/export | admin only; UTF-8 CSV with BOM of all books and variants; formula injection protected |
| GET | /staff/books/export-template | admin only; sample CSV import template |
| POST | /staff/books/import/preview | admin only (throttle: 10/hour); multipart `file` (.csv <= 2MB, <= 2000 rows); returns `preview_token`, totals (new/update/error), and per-row preview |
| POST | /staff/books/import/confirm | admin only (throttle: 10/hour); `preview_token`; applies non-error rows in one transaction |
| DELETE | /staff/books/{id} | admin; strict soft delete into trash (requires confirm matching title, current password, and 6-digit 2FA code; triggers telegram alert to owner and audit log) |
| POST | /staff/books/{id}/restore | admin; restore from trash within 30 days (422 if > 30 days) |
| POST | /staff/books/{id}/variants | format, sku, price_usd (max 2 decimals), isbn?, stock_quantity? (logged as restock), low_stock_threshold?, cover_image_url? (http/https only), is_active?. Staff (not admin): price must be above $0 (422 "A price of $0, or a cut of more than half, needs an admin.") |
| PATCH | /staff/variants/{id} | any variant field; a stock change is logged as an adjustment. Staff (not admin): a new price of $0 or below half the current one is 422 (same message); admins can |
| POST | /staff/variants/{id}/restock | quantity (1-10000), supplier?, unit_cost_usd? (admin sees this, max 2 decimals), note? — physical formats only (ebook/audiobook 422). Inside a row lock: increases stock, logs movement and audit log |
| GET | /staff/variants/{id}/stock-history | newest first, paginated (20); id, created_at, change_qty, reason, reference (order/return), staff {id,name}, note, supplier, stock_after (running balance), unit_cost_usd (admin only) |
| GET | /staff/stock | physical variants overview with book title, format, sku, stock, threshold, status (`in_stock`\|`low`\|`out`); `status=low\|out`, `q` (title/SKU/ISBN), sorted by stock ascending by default, paginated |
| DELETE | /staff/variants/{id} | admin; 409 if ever ordered (set `is_active=false` instead) |
| POST / PATCH | /staff/authors, /staff/authors/{id} | name, bio?, photo_url? (http/https only) |
| POST / PATCH | /staff/categories, /staff/categories/{id} | name, slug? (auto from name) |
| POST / PATCH | /staff/publishers, /staff/publishers/{id} | name (unique) |
| POST / PATCH | /staff/series, /staff/series/{id} | name, description? |
| DELETE | /staff/{authors\|categories\|publishers\|series}/{id} | admin; 409 while any book (incl. deleted) uses it |

Every staff create/update/delete is recorded in `admin_audit_logs` (who, action like `book_variant.updated`, changed fields before/after).

## Customer shopping (customer token + verified email)
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /addresses | default first |
| POST | /addresses | recipient_name, phone, address_line1, city, country, label?, address_line2?, state?, postal_code?, is_default? — max 10; first one becomes default |
| PATCH | /addresses/{id} | any field; `is_default: true` moves the default (it cannot be switched off directly) |
| DELETE | /addresses/{id} | if it was the default, the newest remaining address becomes default |
| GET | /cart | see cart shape below |
| POST | /cart/items | book_variant_id, quantity? (default 1) — 201 + cart. Same format again = quantity increases |
| PATCH | /cart/items/{id} | quantity (1-10) |
| DELETE | /cart/items/{id} | returns the cart |
| DELETE | /cart | empties the cart |
| POST | /cart/coupon/check | code — preview only (10 per minute; 5 unknown codes in a row lock it 15 minutes); send the code again at checkout |
| GET | /wishlist | book cards, newest first, paginated |
| POST | /wishlist | book_id — 201 added, 200 already there |
| DELETE | /wishlist/{book_id} | 204 |

Cart rules: max 10 per line; physical formats need stock ("Only N left in stock."); ebooks/audiobooks are quantity 1 and need no stock.
Cart shape: `{data: {id, items: [{id, book_variant_id, book {id,title}, format, cover_image_url, quantity, unit_price_usd, unit_price_khr, line_total_usd, line_total_khr, issues: [{code, message, ...}]}], item_count, subtotal_usd, subtotal_khr, can_checkout}}`.
Issue codes: `unavailable`, `out_of_stock`, `insufficient_stock` (+ `available_quantity`) block checkout; `price_changed` (+ `previous_price_usd`) is information only. `subtotal_usd` counts only lines without blocking issues.
Coupon check response: `{data: {code, type, value, subtotal_usd, discount_usd, discount_khr, total_before_shipping_usd, total_before_shipping_khr}}`; errors are 422 on `code` (invalid, inactive, not started, expired, usage limit, minimum subtotal, already used by you).

## Staff coupons (staff token + 2FA; DELETE admin-only)
| Method | Path | Body |
| --- | --- | --- |
| GET | /staff/coupons | `q`, `active=0/1`, `per_page` |
| GET | /staff/coupons/{id} | |
| POST | /staff/coupons | **admin** (owner 2026-10-15). code (stored uppercase), type (percentage\|fixed), value (percentage max 100), min_order_amount?, max_uses?, starts_at?, expires_at?, is_active? |
| PATCH | /staff/coupons/{id} | **admin**. any of the above (`used_count` is read-only) |
| DELETE | /staff/coupons/{id} | 409 once used — set `is_active=false` instead |

## Checkout and orders (customer token + verified email)
| Method | Path | Body / notes |
| --- | --- | --- |
| POST | /checkout/preview | coupon_code?, address_id?, delivery_note? — totals for the current cart: `{subtotal, discount, shipping_fee, tax, total}` each `{usd, khr}`, plus `coupon_code`, `requires_shipping`, `can_checkout`. Nothing is saved |
| POST | /checkout | Header `Idempotency-Key: <8-100 chars, A-Z a-z 0-9 - _>` (required, new value per order). Body: address_id, payment_method (`cod` only for now), coupon_code?, delivery_note? (max 300) — 201 + order. Same key again: 200 + the same order and header `Idempotent-Replayed: true` |
| GET | /orders | `status`, `per_page` — newest first |
| GET | /orders/{id} | includes `status_history`. Line `title`/`format` (and the documents' `sku`, `isbn`, `authors`) are what the book was when ordered, not today's book |
| POST | /orders/{id}/cancel | reason? — only while `pending` |
| GET | /orders/{id}/invoice | print data for the customer's own invoice (same shape as `/staff/orders/documents`); 422 for a cancelled order; 30/min |

Checkout rules: always today's price; shipping fee by the address's area when any hardcover/paperback is in the order (Phnom Penh `SHIPPING_FEE_PHNOM_PENH`, default 1.50 USD, matched on city or province; everywhere else `SHIPPING_FEE_PROVINCES`, default 3.00 USD), 0 if all digital; `POST /checkout/preview` takes an optional `address_id` (else the default address) and returns `delivery_area`; tax 0; stock is deducted when the order is placed and put back on cancel; the cart is emptied and a confirmation email is queued. Errors (422): `cart` (empty, or items unavailable / not enough stock — reload `GET /cart` to see which), `address_id`, `payment_method`, `coupon_code`, `delivery_note`, `idempotency_key`.

Order shape: `{id, order_number (ORD-YYYYMMDD-XXXXX), status, payment_method, payment_status, placed_at, item_count, items[{id, book_variant_id, book_id, title, format, quantity, unit_price_usd, subtotal_usd}], subtotal_usd, discount_usd, shipping_fee_usd, tax_usd, total_usd, total_khr, coupon_code, shipping_address{...}, can_cancel, delivery_note, status_history[{status, note, created_at}]}`.

Status flow: `pending -> processing -> shipped -> delivered`; `cancelled` only from pending (customer or staff) or processing (staff). Cash on delivery: payment `pending` at checkout, `succeeded` when delivered, `failed` when cancelled.

## Staff orders and notifications (staff token + 2FA)
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /staff/orders | `status`, `q` (order number, customer email or name), `from`, `to` (dates), `per_page` |
| POST | /staff/orders/bulk-status | `ids[]` (1–50, distinct), `status` (processing\|shipped\|delivered; cancel stays one order at a time: 422), `note?` (≤ 500, added to every timeline). Each order changes on its own through the same rules as `/staff/orders/{id}/status` (stock, payment, history, audit, customer email); returns `{status, updated[{id, order_number, status}], failed[{id, order_number, reason}]}` in the order asked (unknown id: "Order not found."). 20/min |
| GET | /staff/orders/documents | `ids[]` (1–50, distinct): print data for invoices and packing slips, in the order asked, unknown ids left out. Per order: `customer`, `shipping_address`, `items[{title, authors[], format, is_digital, sku, isbn, quantity, unit_price_usd, subtotal_usd}]`, totals, `coupon_code`, `refunded_usd` (refunded returns), `amount_to_collect_usd` (an unpaid cash-on-delivery order: the total; else 0.00), `delivered_at`, `delivery_note`, `khr {rate, rate_effective_at, total, amount_to_collect}` at the rate in force when the order was placed (null if none was set then). 30/min |
| GET | /staff/orders/{id} | adds `customer {id,name,email,phone}`, `staff_note`, history with `changed_by`, `allowed_next_statuses` |
| POST | /staff/orders/{id}/status | status (processing\|shipped\|delivered\|cancelled), note? — 422 for a move the flow does not allow |
| PATCH | /staff/orders/{id}/staff-note | `staff_note` (nullable, max 1000) — private staff note; audited (order.updated); 30/min |
| GET | /staff/notifications | `unread=1`, `per_page` — in-app alerts; `meta.unread_count` |
| POST | /staff/notifications/{id}/read | |
| POST | /staff/notifications/read-all | |

Low-stock alert (`type: low_stock`) is sent to every staff member once, when a sale takes a format to or below its `low_stock_threshold`: `data {book_variant_id, book_id, title, format, sku, stock_quantity, low_stock_threshold, message}`.

## Returns (customer token + verified email)
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /orders/{id}/returnable-items | `{order_id, returnable_until, can_request_return, reason_unavailable, items[{order_item_id, title, format, purchased_quantity, returnable_quantity}]}` |
| POST | /orders/{id}/returns | reason, items[{order_item_id, quantity, reason?}] — 201 + return |
| GET | /returns | own returns, newest first |
| GET | /returns/{id} | |
| DELETE | /returns/{id} | withdraw; only while `requested` |

Rules: order must be `delivered`; within `RETURN_WINDOW_DAYS` (default 3) of delivery; physical formats only (ebooks/audiobooks are not returnable); some or all copies, never more than bought minus copies already in requested/approved/refunded returns; one requested/approved return per order at a time. Errors (422): `order` (not delivered, window closed, request in progress, nothing left), `items`.
Return shape: `{id, order_id, order_number, status (requested|approved|rejected|refunded), reason, items[{id, order_item_id, title, format, quantity, unit_price_usd, reason}], refund_amount_usd, is_refund_final, staff_note, requested_at, resolved_at, can_withdraw}`. Before the refund `refund_amount_usd` is an estimate: the items minus their share of the order's coupon discount (shipping is not refunded).

## Staff returns (staff token + 2FA)
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /staff/returns | `status`, `q` (order number, customer email or name), `per_page` — adds `customer`, `handled_by` |
| GET | /staff/returns/{id} | |
| POST | /staff/returns/{id}/approve | note? — requested -> approved (no stock change yet) |
| POST | /staff/returns/{id}/reject | note (required) — requested or approved -> rejected |
| POST | /staff/returns/{id}/refund | method (`store_credit`\|`manual_offline`), reference? (required for manual_offline), note? — approved -> refunded: books back in stock, refund amount saved. Issues a store credit coupon (1 year expiry) or records offline reference. |

When every physical copy of an order has been refunded, the order becomes `returned` and its payment `refunded`.

## Reviews
| Method | Path | Auth | Body / notes |
| --- | --- | --- | --- |
| GET | /books/{id}/reviews | public | `sort` (newest\|highest\|lowest), `rating` (1-5), `per_page` (10, max 50). `meta.rating_summary {average, count, distribution {5,4,3,2,1}}` |
| POST | /books/{id}/reviews | customer, verified | rating (1-5), comment? — 403 unless the book (any format) is in one of your delivered orders, 409 if already reviewed |
| GET | /reviews | customer, verified | your reviews, incl. hidden ones (`is_visible`) |
| PATCH | /reviews/{id} | customer, verified | rating?, comment? |
| DELETE | /reviews/{id} | customer, verified | |
| GET | /staff/reviews | staff | `visible`, `book_id`, `max_rating`, `q` (comment text) |
| POST | /staff/reviews/{id}/hide | staff | note? — removes it from the public list and the book's average |
| POST | /staff/reviews/{id}/show | staff | note? |

Public review shape: `{id, book_id, rating, comment, reviewer_name ("Sok D."), verified_purchase, created_at, updated_at}`.

## Admin (admin token + 2FA unless noted)
Deactivated staff and customers get 403 "This account has been deactivated. Please contact support." when logging in (after a correct password).

### Staff members
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /staff/members | `q` (name/email), `role` (admin\|staff), `active`, `per_page` — `{id, name, email, role, two_factor_enabled, is_active, created_at, updated_at}` |
| POST | /staff/members | name, email, role — emails a 6-digit setup code valid 72 h; the new member calls `POST /staff/auth/reset-password {email, code, password, password_confirmation}`, then logs in and sets up 2FA |
| PATCH | /staff/members/{id} | name?, role? — a role change signs the member out everywhere |
| POST | /staff/members/{id}/deactivate | signs them out, blocks login |
| POST | /staff/members/{id}/activate | |
| POST | /staff/members/{id}/reset-two-factor | lost phone: 2FA off, signed out; they set it up again at next login |
| POST | /staff/members/{id}/resend-invitation | new 72 h setup code (3 per 10 min) |

Rules (422 on `staff`): you cannot change your own role, deactivate or reset yourself; the last active admin can never be demoted or deactivated.

### Customers
| Method | Path | Auth | Body / notes |
| --- | --- | --- | --- |
| GET | /staff/customers | staff | `q` (name/email/phone), `tag` (tag name filter), `active`, `verified`, `closed`, `per_page` — includes `tags`, `orders_count`, `login_methods`, `removal_at` (unfinished sign-ups: when they're deleted), `closed_at` / `erase_at` (closed accounts); `closed=1` lists accounts closed in the last 30 days, newest first; `meta.counts {verified, unverified, closed}` for the tabs |
| GET | /staff/customers/{id} | staff | adds `tags`, `stats {orders_by_status, returns_count, reviews_count, lifetime_spent_usd (admin only), last_order_at}` and `recent_orders` (5, with `total_usd` visible to admins only) |
| GET | /staff/customers/{id}/notes | staff | lists staff notes on customer (author name + timestamp) |
| POST | /staff/customers/{id}/notes | staff | adds staff note on customer (`body` 1–2000 chars); audit-logged |
| DELETE | /staff/customer-notes/{id} | staff | deletes note (author or admin only); audit-logged |
| PUT | /staff/customers/{id}/tags | staff | `{tags: string[]}` (max 10 tags, 1–40 chars, inner spaces collapsed, case-insensitive match reuses existing tag spelling); audit-logged |
| GET | /staff/customer-tags | staff | `q` suggestions (name + customer count, only tags in use, top 20) |
| POST | /staff/customers/{id}/resend-verification | staff | resends verification email to unverified customer; limit 3 per hour per customer; audit-logged |
| POST | /staff/customers/{id}/reopen | admin | Reopens an account the customer closed, within its 30 days (422 if open or past 30 days). Audit `customer.reopened`; emails the customer; reviews, wishlist and cart removed at closing aren't restored. The detail endpoint also returns closed accounts. |
| POST | /staff/customers/{id}/close | admin | Closes a customer account: `{password, code, confirm}`. Admin password, current 2FA TOTP code, and customer's email (or phone) typed exactly. Revokes tokens, removes reviews/wishlist/cart, soft-deletes customer (30-day restore window). 422 if open orders/returns, invalid password/code, wrong confirmation. The confirmation and open orders/returns are checked before the password and code, so a typo doesn't use up the one-time code. Audit `customer.closed`; OpsAlert to owner chat only. Throttle 5/min. |
| POST | /staff/customers/{id}/deactivate | admin | signs them out, blocks login |
| POST | /staff/customers/{id}/activate | admin | |
| DELETE | /staff/customers/{id} | admin | deletes an unfinished sign-up now (frees its email); 422 for verified customers or anything with orders, returns, reviews or addresses; audit-logged |

### Exchange rates and audit log
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /staff/exchange-rates | USD->KHR history with `is_current`; `meta.current_rate` |
| POST | /staff/exchange-rates | rate (> 0, up to 6 decimals), effective_at? (default now; a future date schedules the change) |
| GET | /staff/audit-logs | `staff_user_id`, `action` (e.g. `book_variant.updated`), `entity_type`, `entity_id`, `from`, `to`, `per_page` (50) — `{id, staff {id,name,email}, action, entity_type, entity_id, before, after, created_at}`; `meta.entity_types` lists filter values |

### Dashboard
Query for both: `period` = today \| 7d \| 30d (default) \| custom with `from` and `to` (YYYY-MM-DD, max 366 days); summary also `compare` (boolean, default true). Days follow `SHOP_TIMEZONE` (default Asia/Phnom_Penh). Revenue is cash basis: an order counts on the day it was delivered, a refund on the day it was paid back (shipping is never refunded). Admin only.
| Method | Path | Returns |
| --- | --- | --- |
| GET | /staff/dashboard/summary | `period`, `revenue {gross_usd, refunds_usd, net_usd, gross_khr, refunds_khr, net_khr}`, `delivered_orders`, `average_order_value_usd`, `average_order_value_khr`, `orders_placed`, `orders_by_status`, `new_customers` (verified sign-ups in the period), `best_sellers[{book_id, title, copies_sold, sales_usd, sales_khr}]` (top 10), `top_categories[{category_id, name, copies_sold, sales_usd, sales_khr}]` (top 5; a book in two categories counts in both), `open_returns`, `low_stock[{book_variant_id, book_id, title, format, sku, stock_quantity, low_stock_threshold}]`, and unless `compare=0`: `comparison {period, revenue, delivered_orders, average_order_value_usd, average_order_value_khr, orders_placed, new_customers, change_percent {gross_revenue, refunds, net_revenue, delivered_orders, average_order_value, orders_placed, new_customers}}` for the period of the same length just before. `change_percent` has 2 decimals; when the previous value is 0 it is 100.0 if there is something now, else 0.0. `*_khr` amounts are whole riel at the current rate, null while no USD→KHR rate is set. |
| GET | /staff/dashboard/sales | one row per day: `{date, orders_placed, gross_revenue_usd, refunds_usd, net_revenue_usd, net_revenue_khr}` (days without sales are 0) |

Revenue is cash basis: an order counts on the day it was delivered (cash on delivery is collected then); a refund counts on the day it was refunded.

### Orders export
| Method | Path | Notes |
| --- | --- | --- |
| GET | /staff/orders/export | `from`, `to` (YYYY-MM-DD, required, max 366 days, `SHOP_TIMEZONE` days, by `placed_at`). Downloads `orders-<from>-to-<to>.csv` (UTF-8 with BOM for Excel). Columns: order_number, placed_at, status, customer_name, customer_email, items, subtotal_usd, discount_usd, shipping_fee_usd, tax_usd, total_usd, coupon_code, payment_method, payment_status, shipping_city, shipping_country. Text starting with `= + - @` is prefixed with `'` so spreadsheets do not run it as a formula. 10 per minute. |

### System health (admin only)
| Method | Path | Returns |
| --- | --- | --- |
| GET | /staff/health | `{status: ok\|degraded\|down, checks: {database, queue {pending, failed, oldest_pending_seconds}, telegram_bot {status: off\|ok\|degraded, error?}}}`. Database down returns HTTP 503; otherwise 200. Overall `degraded` when a queued job has waited over 10 minutes or the Telegram bot is degraded. Bot `off` without a token; `getWebhookInfo` cached 10 minutes. Bot `ok` when its URL matches this request's webhook address and no delivery error is dated within the last 30 minutes. Auto-repairs webhook if needed. Accessible by admin staff only. |

### Reports (admin only, throttle `admin-analytics` 30/min)
Cached for 5 minutes per report and range. All endpoints take `from` and `to` (YYYY-MM-DD, required, max 366 days, in `SHOP_TIMEZONE`, default Asia/Phnom_Penh) and optional `format=json|csv` (CSV streamed with UTF-8 BOM `\xEF\xBB\xBF` and formula injection prevention).

**Revenue & Accounting Definitions (consistent with Dashboard):**
- **Cash basis:** Gross revenue = orders delivered in the period (by `order_status_history.status = delivered` timestamp).
- **Refunds:** Returns marked refunded in the period (by `returns.resolved_at`). Shipping fees are kept and never refunded.
- **Net revenue:** Gross revenue minus refunds.
- **Cancelled orders:** Excluded from revenue, best sellers, top categories, and coupons. Counted in orders breakdown and cancellation rate.
- **USD -> KHR:** Converted with the current exchange rate, like the dashboard (invoices and receipts use the rate at the order date instead). `null` when no rate is set.
- **Previous period comparison:** Period of equal length immediately preceding `[$start - days, $start)`. Change percent is 2 decimals; when previous value is 0, it is 100.0 if current > 0, else 0.0.

| Method | Path | Query params | Returns / Notes |
| --- | --- | --- | --- |
| GET | /staff/reports/sales | `from`, `to`, `group` (day\|week\|month, default day), `format` (json\|csv) | Totals in USD & KHR (gross revenue, refunds, net revenue, delivered orders count, orders placed, average order value, items sold, discounts, shipping fees), previous period comparison with `%` change, and series per day/week/month (empty intervals filled with 0s). |
| GET | /staff/reports/orders | `from`, `to`, `format` (json\|csv) | Total orders placed in range, breakdown by order status, cancellation rate (`%`), average delivery time from order placed to delivered (hours), breakdown by payment method (`cod`, `card`, `khqr`), and delivery area (`phnom_penh` vs `provinces`, the checkout rule `DeliveryArea`), plus previous period comparison. |
| GET | /staff/reports/products | `from`, `to`, `limit` (default 10), `format` (json\|csv) | Best sellers by units sold and by revenue (book ID, variant ID, title, format, units, revenue in USD & KHR), worst sellers with stock > 0, low-stock list (`stock_quantity <= low_stock_threshold`), and revenue by category. |
| GET | /staff/reports/customers | `from`, `to`, `format` (json\|csv) | Total new verified customers in range, daily new verified customers series, returning customer rate (`customers with >= 2 orders in range / customers with an order in range * 100`), and top 10 customers by total spend in range (name, email, orders count, total spent in USD & KHR). |
| GET | /staff/reports/funnel | `from`, `to`, `format` (json\|csv) | 4 measurable steps from database: Carts with items -> Orders placed -> Orders delivered -> Orders returned. "Carts with items" = carts that still hold items added in the range + orders placed in the range (a cart is emptied at checkout), so it is an estimate; there is no visit tracking. Each step includes count, conversion rate from first step (`%`), conversion rate from previous step (`%`), and drop-off rate (`%`). |
| GET | /staff/reports/coupons | `from`, `to`, `format` (json\|csv) | Coupon performance (code, type, value, times used in range, total discounts given, total order revenue) and refunds breakdown (total count, amount in USD & KHR, and breakdown by refund reason). |

### Newsletter
| Method | Path | Auth | Body / notes |
| --- | --- | --- | --- |
| POST | /newsletter/subscribe | public | `email`, `locale`? (en\|km), `turnstile_token`? — 202 "Check your inbox to confirm." (always same message, no enumeration). Double opt-in confirmation email throttled to at most once per 10 minutes per email. Rate limit 5/hour per IP. |
| POST | /newsletter/confirm | public | `token` — confirms subscription (sets confirmed_at, clears confirm_token_hash). |
| POST | /newsletter/unsubscribe | public | `token` — unsubscribes from newsletter via web form. |
| POST | /newsletter/unsubscribe/one-click | public | query `token` (RFC 8058 one-click unsubscribe for mail user agents). Every newsletter email includes `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click` headers. |
| GET | /me/newsletter | customer | `{subscribed, status, locale}` |
| PUT | /me/newsletter | customer, verified | `subscribed` (bool), `locale`? (en\|km) — verified customer can subscribe or unsubscribe without confirmation email. 403 if unverified. |
| GET | /staff/newsletter/summary | admin | `{confirmed, pending, unsubscribed, total}` counts |
| GET | /staff/newsletter/campaigns | admin | paginated list of campaigns (`status`: draft\|sending\|sent) |
| POST | /staff/newsletter/campaigns | admin | `subject_en`, `subject_km`?, `body_en`, `body_km`?, `book_ids`? (up to 12). Creates draft campaign; audit-logged. |
| GET | /staff/newsletter/campaigns/{id} | admin | campaign details with chosen book previews |
| PUT | /staff/newsletter/campaigns/{id} | admin | update draft campaign (422 if not draft); audit-logged. |
| DELETE | /staff/newsletter/campaigns/{id} | admin | delete draft campaign (422 if not draft); audit-logged. |
| POST | /staff/newsletter/campaigns/{id}/test | admin | sends test newsletter email to authenticated admin's own email address directly; audit-logged. |
| POST | /staff/newsletter/campaigns/{id}/send | admin | queues campaign rows to `marketing_outbox` for all confirmed subscribers in their locale (fallback to English if Khmer empty); transitions status to `sending` -> `sent`. Returns estimated days to complete at `MARKETING_DAILY_LIMIT` (60/day). Cannot be sent twice. Audit-logged. |


