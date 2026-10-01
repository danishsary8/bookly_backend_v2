# API Reference (v1)

Base URL: `/api/v1`. Send `Accept: application/json`. Authenticated calls send `Authorization: Bearer <token>`.
Errors: `{"message": "..."}`; validation errors are 422 with `{"message", "errors": {"field": ["..."]}}`. 401 = not logged in / bad credentials, 403 = not allowed (wrong token type, email not verified, 2FA not set up), 429 = rate limited.

## Customer auth
| Method | Path | Body | Notes |
| --- | --- | --- | --- |
| POST | /auth/register | name, email, password, password_confirmation, phone? | 201 + token. Emails a 6-digit code. |
| POST | /auth/login | email, password | token (7 days) |
| POST | /auth/logout | — | auth |
| POST | /auth/verify-email | code | auth |
| POST | /auth/resend-verification | — | auth, 3 per 10 min |
| POST | /auth/forgot-password | email | always 200 |
| POST | /auth/reset-password | email, code, password, password_confirmation | revokes all tokens |
| POST | /auth/social/{google\|facebook} | access_token | 201 new / 200 existing, account auto-linked by email |
| GET | /me | — | auth |
| PATCH | /me | name?, phone? | auth |
| PUT | /me/password | current_password (not needed if social-only), password, password_confirmation | auth, signs out other sessions |

Token response shape: `{ "customer": {...}, "token": "...", "token_type": "Bearer", "expires_at": "ISO-8601" }`.
Unverified customers can log in but shopping routes return 403 "Please verify your email address first."

## Staff auth
| Method | Path | Body | Notes |
| --- | --- | --- | --- |
| POST | /staff/auth/login | email, password | 2FA on: `{two_factor_required: true, challenge_token}`. 2FA off: token + `two_factor_setup_required: true` |
| POST | /staff/auth/two-factor/challenge | challenge_token, code | returns token. Challenge valid 5 min, single use |
| POST | /staff/auth/two-factor/setup | — | auth; returns `secret` + `otpauth_uri` (render as QR) |
| POST | /staff/auth/two-factor/confirm | code | auth; enables 2FA |
| GET | /staff/auth/me | — | auth |
| POST | /staff/auth/logout | — | auth |
| POST | /staff/auth/forgot-password | email | always 200 |
| POST | /staff/auth/reset-password | email, code, password, password_confirmation | 2FA stays on |

Every staff feature route returns 403 `{two_factor_setup_required: true}` until 2FA is confirmed. Admin-only routes need an admin account.

## Rate limits
Login/OTP/reset: 5 per minute per email+IP and 30 per minute per IP. Sending codes: 3 per 10 minutes.

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
| POST | /staff/books/{id}/variants | format, sku, price_usd (max 2 decimals), isbn?, stock_quantity? (logged as restock), low_stock_threshold?, cover_image_url?, is_active? |
| PATCH | /staff/variants/{id} | any variant field; a stock change is logged as an adjustment |
| DELETE | /staff/variants/{id} | admin; 409 if ever ordered (set `is_active=false` instead) |
| POST / PATCH | /staff/authors, /staff/authors/{id} | name, bio?, photo_url? |
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

Checkout rules: always today's price; flat shipping fee (`SHIPPING_FLAT_FEE`, default 2.00 USD) when any hardcover/paperback is in the order, 0 if all digital; tax 0; stock is deducted when the order is placed and put back on cancel; the cart is emptied and a confirmation email is queued. Errors (422): `cart` (empty, or items unavailable / not enough stock — reload `GET /cart` to see which), `address_id`, `payment_method`, `coupon_code`, `idempotency_key`.

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

Rules: order must be `delivered`; within `RETURN_WINDOW_DAYS` (default 14) of delivery; physical formats only (ebooks/audiobooks are not returnable); some or all copies, never more than bought minus copies already in requested/approved/refunded returns; one requested/approved return per order at a time. Errors (422): `order` (not delivered, window closed, request in progress, nothing left), `items`.
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
| GET | /staff/customers | staff | `q` (name/email/phone), `active`, `verified`, `per_page` — includes `orders_count`, `login_methods` |
| GET | /staff/customers/{id} | staff | adds `stats {orders_by_status, returns_count, reviews_count, lifetime_spent_usd, last_order_at}` and `recent_orders` (5) |
| POST | /staff/customers/{id}/deactivate | admin | signs them out, blocks login |
| POST | /staff/customers/{id}/activate | admin | |

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
| GET | /staff/dashboard/summary | `period`, `revenue {gross_usd, refunds_usd, net_usd}`, `delivered_orders`, `average_order_value_usd`, `orders_placed`, `orders_by_status`, `new_customers`, `best_sellers[{book_id, title, copies_sold, sales_usd}]` (top 10), `open_returns`, `low_stock[{book_variant_id, book_id, title, format, sku, stock_quantity, low_stock_threshold}]` |
| GET | /staff/dashboard/sales | one row per day: `{date, orders_placed, gross_revenue_usd, refunds_usd, net_revenue_usd}` (days without sales are 0) |

Revenue is cash basis: an order counts on the day it was delivered (cash on delivery is collected then); a refund counts on the day it was refunded.
