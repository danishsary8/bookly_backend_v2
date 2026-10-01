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
