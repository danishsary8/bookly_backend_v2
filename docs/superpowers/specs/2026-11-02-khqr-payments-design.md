# KHQR payments (Bakong) — design

Date: 2026-11-02 · Status: approved by the owner (parts 1-3 in chat: "accept all part you recommend now")

## 1. Goal and decisions

Bookly is a **portfolio project**: online payment must look and work like the real thing, with no real money needed.

| Question | Owner's choice |
|---|---|
| Purpose | Portfolio / school project |
| Approach | **A**: real, scannable KHQR + a test mode by default; real Bakong checking when the owner adds a token |
| Time to pay | **15 minutes**, then the order is cancelled and stock goes back |
| Currency | **Customer chooses** USD (default) or KHR on the payment page |

Cash on delivery stays. No new migration: `orders.payment_method` already allows `khqr`, `payments.provider` allows
`bakong`, `orders.status` allows `paid`.

## 2. What the customer sees

1. Checkout: a new choice **KHQR (Bakong)** next to Cash on delivery, shown only when the API lists it.
2. Placing the order creates it **pending**, holds stock (as COD does), and opens `/account/orders/{id}/pay`:
   KHQR card (red header, shop name, amount), tabs **USD | KHR**, a **15:00 countdown**, "Waiting for payment…",
   **Download QR** (phones).
3. The page asks the API every 3 seconds. Paid → green ✓ screen → order page; the order is **paid**.
4. **Test mode** (default): a banner "Test mode: scanning this QR with a bank app is not checked" and a button
   **Simulate payment (test only — no real money)**.
5. Leaving and coming back: Account → Orders shows **Pay now** while time is left.
6. Time runs out: the order is **cancelled automatically** (stock and coupon/offer use given back), the page says so and
   offers **Buy again**. The usual cancellation update is sent.
7. "Order placed" (email, Telegram, staff alert) for a KHQR order is sent **when it is paid**, not when it is created.

## 3. Backend

### 3.1 Settings (`config/payments.php`)

| Env | Default | Secret? | Meaning |
|---|---|---|---|
| `KHQR_ENABLED` | `true` | no | Offer KHQR at checkout |
| `KHQR_MODE` | `test` | no | `test` (Simulate button, Bakong never called) or `bakong` (real check) |
| `KHQR_ACCOUNT_ID` | `bookly@devb` | no | Bakong account ID shown in the QR (e.g. `name@aclb`) |
| `KHQR_MERCHANT_NAME` | `Bookly` | no | Name in the QR (ASCII, max 25) |
| `KHQR_CITY` | `Phnom Penh` | no | City in the QR (ASCII, max 15) |
| `KHQR_PAY_MINUTES` | `15` | no | Time to pay |
| `BAKONG_API_URL` | `https://api-bakong.nbc.gov.kh` | no | Bakong Open API |
| `BAKONG_RELAY_URL` | empty | no | Optional Cambodia-based relay with the same paths, used instead of the API URL |
| `BAKONG_TOKEN` | empty | **yes** | Bakong Open API token (Render settings only; never logged or returned) |

### 3.2 Units

- `App\Services\Payments\Khqr\KhqrBuilder` — builds an **individual dynamic KHQR** string (EMVCo tags as in NBC's
  official `bakong-khqr` SDK 1.0.20): `00`=01, `01`=12, `29`{`00` account}, `52`=5999, `53` 840/116, `54` amount (USD:
  whole dollars as `5`, else 2 decimals; KHR: whole riel), `58`=KH, `59` name, `60` city, `62`{`01` bill number = order
  number, `03` store label}, `99`{`00` created ms, `01` expires ms}, `63` CRC-16/CCITT-FALSE (upper-case hex). `md5()` of
  the string is the Bakong lookup key. Tested against strings from the SDK's own test suite.
- `App\Services\Payments\Khqr\BakongClient` — `checkByMd5(md5)`: POST `/v1/check_transaction_by_md5` with
  `Authorization: Bearer <token>`, 5 s timeout. Returns the paid transaction (`hash`, `amount`, `currency`, …) or null
  (not paid yet); throws `BakongUnavailable` on 401/403/5xx/timeout (403 often means "not from a Cambodian IP").
  Token expiry read from the token's own `exp` claim (JWT) for the health card; no signature check needed for that.
- `App\Services\Payments\KhqrPaymentService`:
  - `start(Order)` (inside checkout's transaction): first QR in USD.
  - `qr(Order, currency)`: reuse the pending QR of that currency, else make one (KHR amount = USD total at today's rate,
    whole riel, saved on its own payment row so it never changes).
  - `refresh(Order)`: if pending and in `bakong` mode, check every pending QR with Bakong (at most once per 3 s per order,
    cache lock); if past the deadline (after a last check), cancel automatically.
  - `simulate(Order)`: test mode only.
  - `markPaid(Order, Payment, transaction, by)`: one DB transaction, order row locked, `pending → paid` only once;
    amount and currency must match the QR; other pending QRs → `failed`; then the "order placed" messages.
  - Paid **after** the order was cancelled: the payment is still saved as `succeeded` with `paid_after_cancel = true`,
    a status-history note and a staff notification "refund by hand". Never lost silently.
- Payment row per QR: `provider=bakong`, `status=pending`, `amount`, `currency`, `provider_transaction_id = md5`
  (unique), `raw_response` = `{qr, md5, expires_at, mode}`; on success the Bakong answer (or `{simulated: true}`) is
  merged in. Every Bakong "paid" answer is also stored in `payment_webhook_logs` (`provider=bakong`,
  `event_id = md5`).

### 3.3 API

| Method | Path | Who | What |
|---|---|---|---|
| GET | `/delivery/options` | public | adds `payment_methods`: `[{code: "cod"}, {code: "khqr", mode: "test"\|"bakong", pay_minutes: 15}]` |
| POST | `/checkout` | customer | `payment_method: khqr` accepted when enabled; answer as today plus `payment` |
| GET | `/orders/{id}/payment` | owner | status (`awaiting_payment`, `paid`, `expired`, `cancelled`), `expires_at`, `seconds_left`, `mode`, `qrs` (USD and, once asked, KHR: `currency`, `amount`, `qr`, `md5`). Runs `refresh` |
| POST | `/orders/{id}/payment/qr` | owner | `{currency: USD\|KHR}` → that QR (same shape) |
| POST | `/orders/{id}/payment/simulate` | owner | test mode only; 404 otherwise |
| POST | `/staff/orders/{id}/mark-paid` | admin | `{note}` required; pending KHQR orders only; audit log |
| GET | `/staff/health` | admin | adds `checks.payments`: `{mode, status: test\|ok\|blocked\|token_missing\|token_expiring, token_expires_at}` |

Throttle: payment GET 40/min per user (the page polls every 3 s), POSTs 10/min.

### 3.4 Expiry

Deadline = `placed_at + KHQR_PAY_MINUTES`. Enforced (1) on every `GET /orders/{id}/payment` and (2) by a new
housekeeping command `payments:expire-khqr` every minute (`MaintenanceTasks`, runs from web traffic on Render). Before
cancelling, Bakong mode does one last check, so a payment made in the last seconds is not lost.

### 3.5 Other changes

- `OrderResource.payment_status`: a succeeded payment wins over newer failed QR rows.
- `OrderStatusService`: a `markPaid` path (pending → paid) with history "Paid by KHQR (Bakong)" / "(test)" / "by staff".
- Customer cancel of a pending KHQR order stays allowed (gives the QR up).

## 4. Frontend

- `feature/khqr-checkout`: payment choice at checkout (only if listed), `/account/orders/:id/pay` page (QR card drawn
  with a small QR library from the string, tabs, countdown, polling, Paid/Expired screens, Simulate in test mode,
  Download QR), "Pay now" on order list/detail, EN + KM.
- `feature/khqr-admin`: Payments box on the admin order page (rows, status, time, "Mark as paid" for admins, note
  required), Bakong line on the health card, a payment-method filter on the orders list.
- Each works alone: no `payment_methods` from the API → no KHQR choice; the pay page says "not available" on 404.

## 5. Testing

Backend: builder vs the SDK's strings (exact, incl. CRC); CRC check value; md5; amount formats; checkout with KHQR;
paid once under two concurrent checks; wrong amount/currency refused; expiry cancels and restores stock; late payment
flagged; simulate 404 in bakong mode; Bakong mode with `Http::fake` (paid, not paid, 403 → blocked); token never in
logs or answers; notifications deferred to payment; mark-paid admin only + audit; docs (API.md, openapi.yaml).
Frontend: lint, typecheck, tests, build; browser run (EN 1280 light, KM 390 dark): checkout → QR → Simulate → Paid; KHR
tab; Expired; axe on each page; tall dialogs scrolled to the end; the drawn QR decoded back to the same string.

## 6. Owner setup (in OWNER_TODO)

Now: nothing (test mode). Later, optional real Bakong: Bakong ID from the bank app → token from the Bakong Open API
portal (email) → `KHQR_ACCOUNT_ID`, `BAKONG_TOKEN`, `KHQR_MODE=bakong` in Render → a real 100-riel test → renew the
token every 90 days (the admin warns 7 days before). If the health card says **blocked**, keep test mode or add a
Cambodian relay (`BAKONG_RELAY_URL`).

## 7. Out of scope

Refunds through Bakong (staff refund by hand), cards/PayPal, ABA PayWay, deep links into bank apps.
