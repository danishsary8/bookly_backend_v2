@php
    $api = url('/api/v1');
    $shop = config('shop.url');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Bookly API docs</title>
    <meta name="description" content="Bookly REST API: a Laravel 13 + PostgreSQL bookstore backend. Try it live with a demo account.">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css" integrity="sha384-wxLW6kwyHktdDGr6Pv1zgm/VGJh99lfUbzSn6HNHBENZlCN7W602k9VkGdxuFvPn" crossorigin="anonymous">
    <style>
        :root { --bg: #f6f7f9; --card: #fff; --ink: #1a1f36; --muted: #5d6478; --line: #e3e6ec; --brand: #1d2a6b; --accent: #e8572e; --ok: #1f8a4c; --warn: #b7791f; --bad: #c53030; }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }

        /* Header: one compact bar with everything a visitor needs to start testing. */
        .bar { background: var(--card); border-bottom: 1px solid var(--line); }
        .bar-in { max-width: 1180px; margin: 0 auto; padding: 18px 20px; display: flex; flex-wrap: wrap; gap: 14px 24px; align-items: center; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .logo { width: 38px; height: 38px; border-radius: 10px; background: var(--brand); color: #fff; display: grid; place-items: center; font-size: 20px; }
        .brand h1 { margin: 0; font-size: 20px; line-height: 1.2; }
        .brand p { margin: 0; color: var(--muted); font-size: 13px; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border: 1px solid var(--line); border-radius: 999px; font-size: 13px; background: var(--card); }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); }
        .dot.ok { background: var(--ok); } .dot.warn { background: var(--warn); } .dot.bad { background: var(--bad); }
        .link { color: var(--brand); font-weight: 600; text-decoration: none; padding: 6px 8px; border-radius: 8px; }
        .link:hover { background: #eef0f8; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 10px; border: 1px solid var(--accent); background: var(--accent); color: #fff; font: 600 14px/1 inherit; cursor: pointer; }
        .btn.ghost { background: var(--card); color: var(--ink); border-color: var(--line); }
        .btn[disabled] { opacity: .6; cursor: progress; }

        /* Getting-started guide. */
        .wrap { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        .start { margin: 24px 0 8px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 22px; }
        .start h2 { margin: 0 0 14px; font-size: 18px; }
        .steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; }
        .step { display: flex; gap: 12px; padding: 14px; border: 1px solid var(--line); border-radius: 12px; background: #fbfbfd; }
        .step h3 { margin: 2px 0 4px; font-size: 15px; }
        .step p { margin: 0 0 10px; color: var(--muted); font-size: 14px; }
        .num { flex: none; width: 28px; height: 28px; border-radius: 50%; background: var(--brand); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: 14px; }
        .row { display: flex; flex-wrap: wrap; gap: 8px; }
        .tag { display: inline-block; padding: 1px 6px; border-radius: 5px; color: #fff; font-size: 11px; font-weight: 700; }
        .tag.get { background: #2f6fd6; }
        .facts { margin-top: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px 16px; font-size: 13px; color: var(--muted); }
        .facts strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 2px; }
        .more { margin: 12px 0 8px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px 22px; }
        .more h2 { margin: 0 0 8px; font-size: 18px; }
        .more details { border-top: 1px solid var(--line); }
        .more summary { cursor: pointer; padding: 12px 0; font-weight: 600; list-style: none; display: flex; justify-content: space-between; }
        .more summary::-webkit-details-marker { display: none; }
        .more summary::after { content: "+"; color: var(--muted); font-weight: 400; font-size: 18px; line-height: 1; }
        .more details[open] summary::after { content: "–"; }
        .more ul, .more ol { margin: 0 0 14px; padding-left: 20px; color: var(--muted); font-size: 14px; }
        .more li { margin: 4px 0; }
        .more .note { margin: 0 0 10px; padding: 10px 12px; background: #fff7f3; border: 1px solid #f6d3c4; border-radius: 10px; color: var(--ink); font-size: 13.5px; }
        .more li strong { color: var(--ink); }
        .more code { font: 12.5px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #eef0f4; padding: 1px 5px; border-radius: 5px; overflow-wrap: anywhere; }
        .ok-text { color: var(--ok); font-weight: 600; }
        .err-text { color: var(--bad); }
        .start code, footer code { font: 12.5px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #eef0f4; padding: 1px 5px; border-radius: 5px; }
        footer a { color: var(--brand); }

        /* Swagger UI, restyled: same rows as before, cleaner surfaces. */
        #swagger-ui { max-width: 1180px; margin: 0 auto; padding: 0 20px 40px; }
        .swagger-ui, .swagger-ui .opblock-tag, .swagger-ui .opblock .opblock-summary-description { font-family: inherit; color: var(--ink); }
        .swagger-ui .topbar, .swagger-ui .information-container { display: none; }
        .swagger-ui .scheme-container { background: transparent; box-shadow: none; padding: 12px 0 4px; margin: 0; }
        .swagger-ui .scheme-container .schemes-server-container, .swagger-ui .servers-title, .swagger-ui .servers { display: none; }
        .swagger-ui .wrapper { padding: 0; max-width: none; }
        .swagger-ui .filter-container .filter, .swagger-ui .filter-container .filter > div { width: 100%; max-width: none; flex: 1 1 100%; }
        .swagger-ui .filter .operation-filter-input { width: 100%; max-width: none; }
        .swagger-ui .scheme-container .schemes { justify-content: flex-end; }
        .swagger-ui .filter-container { margin: 0; }
        .swagger-ui .filter-container .filter { margin: 0; padding: 0; }
        .swagger-ui .filter .operation-filter-input { border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; margin: 8px 0 14px; font-size: 14px; background: var(--card); }
        .swagger-ui .opblock-tag-section { background: var(--card); border: 1px solid var(--line); border-radius: 14px; margin: 0 0 10px; padding: 0 14px; }
        .swagger-ui .opblock-tag { border-bottom: 0; padding: 12px 4px; margin: 0; font-size: 17px; font-weight: 700; }
        .swagger-ui .opblock-tag small { font-size: 13px; font-weight: 400; color: var(--muted); padding-left: 10px; }
        .swagger-ui .opblock-tag-section.is-open { padding-bottom: 12px; }
        .swagger-ui .opblock { border-radius: 10px; box-shadow: none; margin: 0 0 6px; background: var(--card) !important; border-width: 1px; }
        .swagger-ui .opblock .opblock-summary { padding: 6px 8px; }
        .swagger-ui .opblock .opblock-summary-method { border-radius: 7px; min-width: 64px; font-size: 12px; padding: 6px 0; font-weight: 700; }
        .swagger-ui .opblock .opblock-summary-path { font-size: 14px; }
        .swagger-ui .opblock .opblock-summary-description { color: var(--muted); font-size: 13px; }
        .swagger-ui .opblock.opblock-get { border-color: #d3e2f7; } .swagger-ui .opblock.opblock-get .opblock-summary-method { background: #2f6fd6; }
        .swagger-ui .opblock.opblock-post { border-color: #cdeedb; } .swagger-ui .opblock.opblock-post .opblock-summary-method { background: #1f9d5b; }
        .swagger-ui .opblock.opblock-put { border-color: #f5e4c6; } .swagger-ui .opblock.opblock-put .opblock-summary-method { background: #d4880f; }
        .swagger-ui .opblock.opblock-patch { border-color: #d7ece6; } .swagger-ui .opblock.opblock-patch .opblock-summary-method { background: #2a9d8f; }
        .swagger-ui .opblock.opblock-delete { border-color: #f5d0d0; } .swagger-ui .opblock.opblock-delete .opblock-summary-method { background: #d64545; }
        .swagger-ui .btn.authorize { border-radius: 10px; color: var(--ok); border-color: var(--ok); background: var(--card); }
        .swagger-ui .btn.authorize svg { fill: var(--ok); }
        .swagger-ui .btn.execute { background: var(--brand); border-color: var(--brand); border-radius: 8px; }
        .swagger-ui .btn { border-radius: 8px; }
        .swagger-ui .opblock-body pre.microlight { border-radius: 10px; }
        footer { max-width: 1180px; margin: 0 auto; padding: 0 20px 32px; color: var(--muted); font-size: 13px; }
        @media (max-width: 640px) { .brand p { display: none; } .swagger-ui .opblock-tag { flex-wrap: wrap; } .swagger-ui .opblock-tag small { flex: 1 0 100%; padding: 4px 0 0; order: 3; } .swagger-ui .opblock-tag > a { flex: 1; } .swagger-ui .opblock .opblock-summary-path { font-size: 12.5px; } .swagger-ui .opblock .opblock-summary-description { display: none; } .start { padding: 16px; } }
    </style>
</head>
<body>
<header class="bar">
    <div class="bar-in">
        <div class="brand">
            <div class="logo" aria-hidden="true">📚</div>
            <div>
                <h1>Bookly API</h1>
                <p>Bookstore backend · Laravel 13 · PostgreSQL · Sanctum tokens + staff 2FA</p>
            </div>
        </div>
        <nav class="actions" aria-label="Links">
            <span class="pill" id="status" aria-live="polite"><span class="dot"></span><span>Checking API…</span></span>
            <a class="link" href="https://github.com/danishsary8/bookly_backend_v2">GitHub</a>
            @if ($shop)
                <a class="link" href="{{ $shop }}">Storefront</a>
            @endif
            <a class="link" href="/openapi.yaml">openapi.yaml</a>
        </nav>
    </div>
</header>

<main class="wrap">
    <section class="start" aria-labelledby="start-title">
        <h2 id="start-title">Test the API in 3 steps</h2>
        <ol class="steps">
            <li class="step">
                <span class="num">1</span>
                <div>
                    <h3>Sign in</h3>
                    <p id="demo-state" aria-live="polite">One click as the demo customer. No password needed.</p>
                    <div class="row">
                        <button class="btn" id="demo-login" type="button">Sign in as demo customer</button>
                        <button class="btn ghost" id="demo-logout" type="button" hidden>Sign out</button>
                    </div>
                </div>
            </li>
            <li class="step">
                <span class="num">2</span>
                <div>
                    <h3>Open an endpoint</h3>
                    <p>Click a section below, for example <strong>Customer</strong>, then a row like <span class="tag get">GET</span> <code>/cart</code>.</p>
                </div>
            </li>
            <li class="step">
                <span class="num">3</span>
                <div>
                    <h3>Execute</h3>
                    <p>Press <strong>Execute</strong>. You get a real answer from the live server, with the status code and JSON.</p>
                </div>
            </li>
        </ol>
        <div class="facts">
            <div><strong>Sign-in</strong> Send <code>Authorization: Bearer &lt;token&gt;</code>. Customer and staff tokens are separate; staff also need a 2FA code.</div>
            <div><strong>Errors</strong> Always JSON. <code>401</code> not signed in · <code>403</code> not allowed · <code>422</code> wrong input · <code>429</code> too many requests.</div>
            <div><strong>Try this</strong> Staff routes answer <code>403</code> to the demo token: the access control working.</div>
        </div>
    </section>

    <section class="more" aria-labelledby="more-title">
        <h2 id="more-title">Details</h2>

        <details>
            <summary>Base URL and format</summary>
            <ul>
                <li>Base URL: <code>{{ $api }}</code></li>
                <li>Send and receive JSON. Always add the header <code>Accept: application/json</code>.</li>
                <li>Dates are ISO 8601 (UTC). Money is a string with 2 decimals, e.g. <code>"12.99"</code>, in USD with a KHR (riel) copy.</li>
                <li>The free server sleeps when idle: the first request can take up to a minute.</li>
            </ul>
        </details>

        <details>
            <summary>Authentication (customers and staff)</summary>
            <ul>
                <li><strong>Customer:</strong> <code>POST /auth/login</code> with email and password → a token valid for 7 days. Shopping routes also need a verified email.</li>
                <li><strong>Staff:</strong> <code>POST /staff/auth/login</code> → <code>challenge_token</code>, then <code>POST /staff/auth/two-factor/challenge</code> with the 6-digit code from an authenticator app → a token valid for 12 hours.</li>
                <li>Send the token on every request: <code>Authorization: Bearer &lt;token&gt;</code>.</li>
                <li>Customer and staff tokens are separate: each only works on its own routes. Log out with <code>POST /auth/logout</code>.</li>
                <li>Demo: <code>POST /demo/token</code> gives a 2-hour token for the protected demo customer (no password; 10 per hour).</li>
            </ul>
        </details>

        <details>
            <summary>Errors and status codes</summary>
            <ul>
                <li>Every error is JSON: <code>{"message": "..."}</code>. Wrong input (<code>422</code>) also has <code>errors: {field: [messages]}</code>.</li>
                <li><code>200/201</code> OK · <code>204</code> done, no body · <code>401</code> not signed in · <code>403</code> not allowed · <code>404</code> not found · <code>422</code> wrong input · <code>429</code> too many requests (see <code>Retry-After</code>) · <code>503</code> temporarily unavailable.</li>
                <li>Every response has an <code>X-Request-Id</code> header: quote it when reporting a problem.</li>
            </ul>
        </details>

        <details>
            <summary>Lists, search and filters</summary>
            <ul>
                <li>Lists are paged: <code>?page=2&amp;per_page=20</code> (max 100). The answer has <code>data</code>, <code>links</code> and <code>meta</code> (<code>current_page</code>, <code>last_page</code>, <code>total</code>).</li>
                <li>Search books: <code>GET /books?q=pride</code>. Filter with <code>category_id</code>, <code>author_id</code>, <code>format</code>, <code>min_price</code>, <code>max_price</code>, <code>in_stock</code>; sort with <code>sort</code>.</li>
            </ul>
        </details>

        <details>
            <summary>Rate limits and safety</summary>
            <ul>
                <li>120 requests per minute per user or IP, with stricter limits on sign-in, email codes, coupon checks and checkout.</li>
                <li>Checkout needs an <code>Idempotency-Key</code> header (any new random text per order), so a retried request never creates a second order.</li>
                <li>The shared demo account can't change its password, email or linked logins, and is tidied every hour.</li>
            </ul>
        </details>

        <details>
            <summary>Test as staff or admin (account owner only)</summary>
            <p class="note">Needs a staff or admin password <strong>and</strong> the 6-digit code from that person's authenticator app, so visitors can't do this. A staff token can change real shop data: use it on your own computer and log out after.</p>
            <ol>
                <li>Open <strong>Staff auth → POST /staff/auth/login</strong>, enter the staff email and password, press <strong>Execute</strong>. Copy <code>challenge_token</code> from the answer.</li>
                <li>Open <strong>POST /staff/auth/two-factor/challenge</strong>. Send <code>challenge_token</code> and <code>code</code> (the 6 digits from the authenticator app). Copy <code>token</code> from the answer.</li>
                <li>Click the green <strong>Authorize</strong> button, paste the token, press <strong>Authorize</strong>, then <strong>Close</strong>.</li>
                <li>Staff routes now work, e.g. <strong>Staff catalog → GET /staff/books</strong> gives <code>200</code>. Admin-only routes (marked <em>(admin)</em>) need an admin account.</li>
                <li>When done: <strong>Authorize → Logout</strong>, and send <code>POST /staff/auth/logout</code> to end the token on the server too.</li>
            </ol>
            <p class="note">First staff sign-in ever? Login answers with <code>two_factor_required: false</code> and a token: use that token for <code>POST /staff/auth/two-factor/setup</code>, add the returned <code>secret</code> to your authenticator app (or make a QR code from <code>otpauth_uri</code>), then send a 6-digit <code>code</code> to <code>/staff/auth/two-factor/confirm</code>. Other staff routes open after that.</p>
        </details>

        <details>
            <summary>Test with Postman</summary>
            <ol>
                <li>In Postman click <strong>Import</strong> → <strong>Link</strong>, paste <code>{{ url('/openapi.yaml') }}</code> and import it as a collection. Every endpoint appears, grouped like this page.</li>
                <li>Open the collection's <strong>Variables</strong> and set <code>baseUrl</code> to <code>{{ $api }}</code>.</li>
                <li>Send <code>POST {{ $api }}/demo/token</code> (no body). Copy the <code>token</code> from the answer.</li>
                <li>On the collection, open <strong>Authorization</strong> → type <strong>Bearer Token</strong> → paste the token. All requests now use it.</li>
                <li>Try <code>GET /cart</code> (200), then <code>GET /staff/books</code> (403: a customer can't use staff routes).</li>
                <li>Add the header <code>Accept: application/json</code> if a request returns a web page instead of JSON.</li>
            </ol>
        </details>
    </section>
</main>

<div id="swagger-ui"></div>

<footer>The demo account is shared, protected and tidied every hour. Built by <a href="https://github.com/danishsary8">@danishsary8</a>.</footer>

<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" integrity="sha384-wmyclcVGX/WhUkdkATwhaK1X1JtiNrr2EoYJ+diV3vj4v6OC5yCeSu+yW13SYJep" crossorigin="anonymous"></script>
<script>
(() => {
    const API = @json($api);
    const KEY = 'bookly-docs-token';

    // Token lives in this variable; sessionStorage (this tab only) is a convenience that may be blocked.
    let token = null;
    try { token = sessionStorage.getItem(KEY); } catch (e) { /* private mode: memory only */ }
    const remember = (t) => { token = t; try { t ? sessionStorage.setItem(KEY, t) : sessionStorage.removeItem(KEY); } catch (e) {} };

    let ui = null;
    if (window.SwaggerUIBundle) {
        ui = SwaggerUIBundle({
            url: '/openapi.yaml',
            dom_id: '#swagger-ui',
            docExpansion: 'none',
            defaultModelsExpandDepth: -1,
            tryItOutEnabled: true,
            filter: true,
            displayRequestDuration: true,
            persistAuthorization: false,
            onComplete: () => { if (token) authorize(token); },
        });
    } else {
        document.getElementById('swagger-ui').innerHTML = '<p class="err-text">The API reference could not load. The raw spec is at <a href="/openapi.yaml">/openapi.yaml</a>.</p>';
    }

    // Fill Swagger's "Authorize" box with the demo token, exactly as if the visitor had pasted it.
    const authorize = (t) => {
        if (!ui) return;
        ui.authActions.authorize({ bearer: { name: 'bearer', schema: { type: 'http', scheme: 'bearer' }, value: t } });
    };

    const loginBtn = document.getElementById('demo-login');
    const logoutBtn = document.getElementById('demo-logout');
    const state = document.getElementById('demo-state');
    const showSignedIn = () => {
        state.innerHTML = '<span class="ok-text">Signed in as demo@bookly.test ✓</span> Every request below now uses this account.';
        loginBtn.hidden = true; logoutBtn.hidden = false;
    };
    const showSignedOut = (error) => {
        if (error) { state.innerHTML = '<span class="err-text"></span>'; state.firstChild.textContent = error; }
        else { state.textContent = 'One click as the demo customer. No password needed.'; }
        loginBtn.hidden = false; logoutBtn.hidden = true; loginBtn.disabled = false; loginBtn.textContent = 'Sign in as demo customer';
    };

    loginBtn.addEventListener('click', async () => {
        loginBtn.disabled = true; loginBtn.textContent = 'Signing in…';
        try {
            const res = await fetch(API + '/demo/token', { method: 'POST', headers: { 'Accept': 'application/json' } });
            const body = await res.json().catch(() => ({}));
            if (!res.ok || !body.token) {
                showSignedOut(body.message || ('Sign-in failed (' + res.status + '). Try again in a minute.'));
                return;
            }
            remember(body.token); authorize(body.token); showSignedIn();
        } catch (e) {
            showSignedOut('The API is not reachable right now (it may be waking up). Try again in a minute.');
        }
    });

    logoutBtn.addEventListener('click', async () => {
        const old = token;
        remember(null); if (ui) ui.authActions.logout(['bearer']); showSignedOut();
        try { await fetch(API + '/auth/logout', { method: 'POST', headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + old } }); } catch (e) {}
    });

    if (token) { showSignedIn(); }

    // Status: /up answers without the database, /health checks it. The free host can take about a minute to wake,
    // so each attempt waits up to 45 s and a first failure ("waking up") is retried once.
    const status = document.getElementById('status');
    const setStatus = (kind, text) => { status.innerHTML = '<span class="dot ' + kind + '"></span><span></span>'; status.lastChild.textContent = text; };
    const get = (url) => fetch(url, { headers: { 'Accept': 'application/json' }, signal: AbortSignal.timeout ? AbortSignal.timeout(45000) : undefined });
    const check = async (retried) => {
        try {
            const up = await get('/up');
            if (!up.ok) throw new Error('down');
            const health = await get(API + '/health');
            if (health.ok) setStatus('ok', 'API operational');
            else if (health.status === 429) setStatus('warn', 'Busy, try again shortly');
            else setStatus('bad', 'Database unavailable');
        } catch (e) {
            if (retried) { setStatus('bad', 'API not reachable'); return; }
            setStatus('warn', 'Waking up…');
            setTimeout(() => check(true), 20000);
        }
    };
    check(false);
})();
</script>
</body>
</html>
