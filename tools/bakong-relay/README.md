# Bakong relay

Bakong's API refuses servers outside Cambodia (HTTP 403, shown as **blocked** on the admin Health page). Bookly's API
runs on Render in Singapore, so it sends its payment checks through this relay instead.

1. On a VPS with a **Cambodian IP**: install Node 18+, copy `relay.mjs`, run `node relay.mjs` (keep it running with systemd or pm2).
2. Put HTTPS in front of it (Caddy: `relay.example.com { reverse_proxy localhost:8080 }`).
3. Check from the VPS: `curl -X POST localhost:8080/v1/check_transaction_by_md5 -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -d '{"md5":"x"}'` should return Bakong's JSON, not a 403.
4. On Render (bookly-api): `BAKONG_RELAY_URL=https://relay.example.com`, keep `KHQR_MODE=bakong`, redeploy.
5. The admin Health page should now say **ok**. Failures also appear in Render logs as `Bakong check failed`.

The relay only forwards the one check endpoint and stores nothing. The Bakong token stays on Render and passes through.
