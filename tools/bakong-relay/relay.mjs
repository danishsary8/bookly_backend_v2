// Bakong relay: run on a server with a Cambodian IP address. Bookly's API sets BAKONG_RELAY_URL to this server.
// Forwards only POST /v1/check_transaction_by_md5 to Bakong, passing the caller's Authorization header through.
// Needs Node 18+. No packages. Put it behind HTTPS (e.g. Caddy or nginx) so the Bakong token is never sent in clear.
import http from "node:http";

const PORT = Number(process.env.PORT || 8080);
const BAKONG = (process.env.BAKONG_API_URL || "https://api-bakong.nbc.gov.kh").replace(/\/$/, "");
const PATH = "/v1/check_transaction_by_md5";

http.createServer(async (req, res) => {
  if (req.method !== "POST" || req.url !== PATH) {
    res.writeHead(404).end();
    return;
  }
  let body = "";
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 4096) { res.writeHead(413).end(); return; }
  }
  try {
    const upstream = await fetch(BAKONG + PATH, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: req.headers.authorization || "" },
      body,
      signal: AbortSignal.timeout(8000),
    });
    res.writeHead(upstream.status, { "Content-Type": "application/json" }).end(await upstream.text());
  } catch {
    res.writeHead(502, { "Content-Type": "application/json" }).end('{"error":"bakong unreachable"}');
  }
}).listen(PORT, () => console.log(`Bakong relay on :${PORT} -> ${BAKONG}`));
