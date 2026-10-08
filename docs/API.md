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
| GET | /health | `{status: ok\|degraded\|down, checks: {database, queue {pending, failed, oldest_pending_seconds}}}`. 503 when the database is down; `degraded` when a queued job has waited over 10 minutes (worker not running). For uptime monitors. |

## Customer auth
| Method | Path | Body | Notes |
| --- | --- | --- | --- |
| GET | /auth/options | — | `{ telegram }`: true once `TELEGRAM_BOT_TOKEN` is set (sign-up, sign-in and Facebook then offer Telegram) |
| POST | /auth/register | name, email (optional with `verify_by=telegram`), password, password_confirmation, phone?, verify_by? (`email`/`telegram`), turnstile_token* | 201 + token + `verify_by`. `email`: sends a 6-digit code (phone? is just a contact number). `telegram` (422 on `verify_by` while the bot is off): no code and no typed number; the website continues with `POST /auth/telegram/phone`, and the number shared in the bot verifies the account (it then signs in with Continue with Telegram). An email whose sign-up was never verified is taken over (new name/password, old sessions and codes cancelled); verified, deactivated or deleted accounts keep their email (422 "An account with this email already exists…"). |
| POST | /auth/login | email, password, turnstile_token* | token (7 days) |
| POST | /auth/logout | — | auth |
| POST | /auth/verify-email | code | auth. A code is thrown away after 5 wrong guesses (ask for a new one) |
| POST | /auth/resend-verification | turnstile_token* | auth, 3 per 10 min per visitor. A new email code; 422 on `email` when the account has none |
| POST | /auth/forgot-password | email, turnstile_token* | always 200 |
| POST | /auth/telegram | — | **Continue with Telegram** (free Bookly bot). 201 `{url, key, expires_at}`: open `url` (t.me/<bot>?start=…), tap "Share my phone number", and poll `/auth/telegram/status` with `key` (private to this browser). Signs in the active account that proved the shared +855 number. 10 links per 10 min per visitor; 503 while `TELEGRAM_BOT_TOKEN` is empty. |
| POST | /auth/telegram/phone | — | auth. Same, to confirm or change the signed-in customer's number: the shared number is saved as verified (and as the contact number), which also verifies a new account. A number on another account (closed ones too) is refused. |
| POST | /auth/telegram/status | key | `{status: pending}`, `{status: failed, message}` (no account with this number, number on another account, not +855, deactivated) or `{status: done, customer}` (+ `token`, `expires_at` for a sign-in; handed out once). 404 for an unknown or expired key (links live 10 minutes). |
| POST | /telegram/webhook | Telegram update | Called by Telegram only (header `X-Telegram-Bot-Api-Secret-Token`, set by `php artisan telegram:webhook`); 404 otherwise. Replies are a `sendMessage` call in the answer. |
| POST | /auth/reset-password | email, code, password, password_confirmation | revokes all tokens |
| POST | /auth/social/{google\|facebook} | access_token | 201 new / 200 returning (matched by the provider's account id). An email that already has a verified or closed account is **not** linked: 409 "This email already has a Bookly account. Sign in with your email and password instead." (names how that account signs in). An unfinished sign-up with that email becomes the social account (its password and sessions are removed). 401 unless the token was issued to our app (`GOOGLE_CLIENT_ID` / `FACEBOOK_CLIENT_ID`; off until set). Google email must be verified by Google (else 422). **New Facebook accounts confirm a phone number in the Telegram bot, or give an email (owner, 2026-10-08):** the first call answers 422 `{ needs: "phone", profile: { name, email }, telegram }`; call again with the same access_token + `verify_by` (`telegram` / `email`) + email (required with `email`) + turnstile_token*. The email counts as verified only if it's the one Facebook gave. The answer's `verify_by` says what is next: `telegram` (`POST /auth/telegram/phone`), `email` (a code was sent) or null (Facebook's email already verifies the account). |

\* `turnstile_token`: the Cloudflare Turnstile token from the form. Required (422 `turnstile_token` "We couldn't check that you're a person…") once `TURNSTILE_SECRET_KEY` is set; ignored while it's empty. Checked with Cloudflare's siteverify and the visitor's IP; if Cloudflare can't be reached the request is refused.
| GET | /me | — | auth |
| PATCH | /me | name?, phone? | auth |
| PUT | /me/password | current_password (not needed if social-only), password, password_confirmation | auth, signs out other sessions |
| DELETE | /me | confirm=`DELETE`, password (or provider + access_token for accounts without a password) | auth. Closes the account: refused (422) while an order is on its way or a return is open; signs out everywhere, removes reviews, wishlist and cart, hides the account. `customers:erase-closed` (daily, and at most hourly from the staff customer list) erases name, email, phone, sign-in ids and addresses after `CLOSED_ACCOUNT_DAYS` (30); orders and returns are kept. |
| POST | /me/email | email, password (when the account has one), turnstile_token* | auth. Add or change the email: emails a 6-digit code to the **new** address (kept aside until confirmed). 422 when the password is wrong, the address is already yours, or another account (also a closed one) uses it. At most 5 codes an hour per account. |
| POST | /me/email/verify | code | auth. Saves the new address as verified, cancels codes sent to the old one, signs out other devices, and emails the old (proven) address "Your Bookly email was changed to n***@…". |
| POST | /me/connections/{google\|facebook} | access_token, password (when the account has one) | auth. Connects the provider: the token must be issued to our app (profile read from the provider); 409 if that provider account is linked to another Bookly account or another one is already connected; 422 if the token can't be verified (not 401: the customer stays signed in). A verified email gets a "was connected" notice. |
| DELETE | /me/connections/{google\|facebook} | — | auth. Disconnects it; 422 `reason: last_way_in` "Add a password first, so you can still sign in." when it's the only way left (`sign_in_methods` on the customer: `password` (with an email), `google`, `facebook`, `telegram` (a verified number, while the Telegram bot is on)). |

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
Every `/api` route: 120 requests per minute per logged-in user, otherwise per IP (`X-RateLimit-*` headers; 429 + `Retry-After`). On top of that: login/OTP/reset 5 per minute per email+IP and 30 per minute per IP; sending codes 3 per 10 minutes; checkout, coupon check and posting reviews 10 per minute; checkout preview 30 per minute; orders export 10 per minute. Email codes die after 5 wrong guesses; staff 2FA allows 5 wrong codes per account per 15 minutes.

## Public catalog (no login)
| Method | Path | Query / notes |
| --- | --- | --- |
| GET | /books | `q` (full-text), `category_id`, `author_id`, `series_id`, `publisher_id`, `format` (hardcover\|paperback\|ebook\|audiobook), `language`, `min_price`, `max_price`, `in_stock=1`, `sort` (relevance\|newest\|price_asc\|price_desc\|title\|rating; default relevance when `q`, else newest), `per_page` (20, max 100) |
| GET | /books/{id} | detail: description, page_count, categories, publisher, series {id,name,order}, active variants |
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
| GET | /staff/books | `q`, `trashed=1`, `per_page` — all books incl. not yet visible; `is_visible` flag |
| GET | /staff/books/{id} | also works for deleted books |
| POST | /staff/books | title, description?, publisher_id?, series_id?, series_order?, language? (default English), page_count?, publish_date?, author_ids[]?, category_ids[]? |
| PATCH | /staff/books/{id} | any of the above; `author_ids`/`category_ids` replace the current list |
| DELETE | /staff/books/{id} | admin; soft delete (hidden from catalog) |
| POST | /staff/books/{id}/restore | admin |
| POST | /staff/books/{id}/variants | format, sku, price_usd (max 2 decimals), isbn?, stock_quantity? (logged as restock), low_stock_threshold?, cover_image_url? (http/https only), is_active? |
| PATCH | /staff/variants/{id} | any variant field; a stock change is logged as an adjustment |
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
| POST | /cart/coupon/check | code — preview only (10 per minute); send the code again at checkout |
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
| POST | /staff/coupons | code (stored uppercase), type (percentage\|fixed), value (percentage max 100), min_order_amount?, max_uses?, starts_at?, expires_at?, is_active? |
| PATCH | /staff/coupons/{id} | any of the above (`used_count` is read-only) |
| DELETE | /staff/coupons/{id} | 409 once used — set `is_active=false` instead |

## Checkout and orders (customer token + verified email)
| Method | Path | Body / notes |
| --- | --- | --- |
| POST | /checkout/preview | coupon_code? — totals for the current cart: `{subtotal, discount, shipping_fee, tax, total}` each `{usd, khr}`, plus `coupon_code`, `requires_shipping`, `can_checkout`. Nothing is saved |
| POST | /checkout | Header `Idempotency-Key: <8-100 chars, A-Z a-z 0-9 - _>` (required, new value per order). Body: address_id, payment_method (`cod` only for now), coupon_code? — 201 + order. Same key again: 200 + the same order and header `Idempotent-Replayed: true` |
| GET | /orders | `status`, `per_page` — newest first |
| GET | /orders/{id} | includes `status_history` |
| POST | /orders/{id}/cancel | reason? — only while `pending` |

Checkout rules: always today's price; shipping fee by the address's area when any hardcover/paperback is in the order (Phnom Penh `SHIPPING_FEE_PHNOM_PENH`, default 1.50 USD, matched on city or province; everywhere else `SHIPPING_FEE_PROVINCES`, default 3.00 USD), 0 if all digital; `POST /checkout/preview` takes an optional `address_id` (else the default address) and returns `delivery_area`; tax 0; stock is deducted when the order is placed and put back on cancel; the cart is emptied and a confirmation email is queued. Errors (422): `cart` (empty, or items unavailable / not enough stock — reload `GET /cart` to see which), `address_id`, `payment_method`, `coupon_code`, `idempotency_key`.

Order shape: `{id, order_number (ORD-YYYYMMDD-XXXXX), status, payment_method, payment_status, placed_at, item_count, items[{id, book_variant_id, book_id, title, format, quantity, unit_price_usd, subtotal_usd}], subtotal_usd, discount_usd, shipping_fee_usd, tax_usd, total_usd, total_khr, coupon_code, shipping_address{...}, can_cancel, status_history[{status, note, created_at}]}`.

Status flow: `pending -> processing -> shipped -> delivered`; `cancelled` only from pending (customer or staff) or processing (staff). Cash on delivery: payment `pending` at checkout, `succeeded` when delivered, `failed` when cancelled.

## Staff orders and notifications (staff token + 2FA)
| Method | Path | Body / notes |
| --- | --- | --- |
| GET | /staff/orders | `status`, `q` (order number, customer email or name), `from`, `to` (dates), `per_page` |
| GET | /staff/orders/{id} | adds `customer {id,name,email,phone}`, history with `changed_by`, `allowed_next_statuses` |
| POST | /staff/orders/{id}/status | status (processing\|shipped\|delivered\|cancelled), note? — 422 for a move the flow does not allow |
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
| POST | /staff/returns/{id}/refund | note? — approved -> refunded: books back in stock, refund amount saved. Pay the customer back outside the system (cash / bank transfer) |

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
| GET | /staff/customers | staff | `q` (name/email/phone), `active`, `verified`, `closed`, `per_page` — includes `orders_count`, `login_methods`, `removal_at` (unfinished sign-ups: when they're deleted), `closed_at` / `erase_at` (closed accounts); `closed=1` lists accounts closed in the last 30 days, newest first; `meta.counts {verified, unverified, closed}` for the tabs |
| GET | /staff/customers/{id} | staff | adds `stats {orders_by_status, returns_count, reviews_count, lifetime_spent_usd, last_order_at}` and `recent_orders` (5) |
| POST | /staff/customers/{id}/reopen | admin | Reopens an account the customer closed, within its 30 days (422 if open or past 30 days). Audit `customer.reopened`; emails the customer; reviews, wishlist and cart removed at closing aren't restored. The detail endpoint also returns closed accounts. |
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
Query for both: `period` = today \| 7d \| 30d (default) \| custom with `from` and `to` (YYYY-MM-DD, max 366 days). Days follow `SHOP_TIMEZONE` (default Asia/Phnom_Penh).
| Method | Path | Returns |
| --- | --- | --- |
| GET | /staff/dashboard/summary | `period`, `revenue {gross_usd, refunds_usd, net_usd}`, `delivered_orders`, `average_order_value_usd`, `orders_placed`, `orders_by_status`, `new_customers` (verified sign-ups in the period), `best_sellers[{book_id, title, copies_sold, sales_usd}]` (top 10), `open_returns`, `low_stock[{book_variant_id, book_id, title, format, sku, stock_quantity, low_stock_threshold}]` |
| GET | /staff/dashboard/sales | one row per day: `{date, orders_placed, gross_revenue_usd, refunds_usd, net_revenue_usd}` (days without sales are 0) |

Revenue is cash basis: an order counts on the day it was delivered (cash on delivery is collected then); a refund counts on the day it was refunded.

### Orders export
| Method | Path | Notes |
| --- | --- | --- |
| GET | /staff/orders/export | `from`, `to` (YYYY-MM-DD, required, max 366 days, `SHOP_TIMEZONE` days, by `placed_at`). Downloads `orders-<from>-to-<to>.csv` (UTF-8 with BOM for Excel). Columns: order_number, placed_at, status, customer_name, customer_email, items, subtotal_usd, discount_usd, shipping_fee_usd, tax_usd, total_usd, coupon_code, payment_method, payment_status, shipping_city, shipping_country. Text starting with `= + - @` is prefixed with `'` so spreadsheets do not run it as a formula. 10 per minute. |
