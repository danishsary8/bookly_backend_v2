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
