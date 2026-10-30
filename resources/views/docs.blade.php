@php
    $api = url('/api/v1');
    $shop = config('shop.url');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bookly API docs</title>
    <meta name="description" content="Bookly REST API: a Laravel 13 + PostgreSQL bookstore backend. Try it live with a demo account.">
    <style>
        :root {
            --bg: #f7f6f2; --card: #ffffff; --ink: #14182b; --muted: #5b6075; --line: #e3e1da;
            --brand: #1d2a6b; --accent: #e8572e; --ok: #1f8a4c; --warn: #b7791f; --bad: #c53030;
            --code-bg: #14182b; --code-ink: #e9e7df;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1220; --card: #171b2e; --ink: #ecebe6; --muted: #a3a7ba; --line: #2a2f47;
                --brand: #9fb0ff; --accent: #ff7a52; --ok: #4ade80; --warn: #fbbf24; --bad: #f87171;
                --code-bg: #0a0d18; --code-ink: #e9e7df;
            }
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .intro a { color: var(--brand); }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 16px; }
        .intro header.hero { padding: 48px 0 32px; border-bottom: 1px solid var(--line); }
        .eyebrow { font-size: 13px; letter-spacing: .12em; text-transform: uppercase; color: var(--accent); font-weight: 700; }
        .intro h1 { font: 700 clamp(2rem, 5vw, 3.1rem)/1.1 Georgia, "Times New Roman", serif; margin: 8px 0 12px; }
        .pitch { color: var(--muted); max-width: 720px; margin: 0 0 20px; font-size: 1.08rem; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border: 1px solid var(--line); border-radius: 999px; font-size: 13px; background: var(--card); }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); }
        .dot.ok { background: var(--ok); } .dot.warn { background: var(--warn); } .dot.bad { background: var(--bad); }
        .links { margin-top: 18px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font: 600 15px/1 inherit; text-decoration: none; cursor: pointer; }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn[disabled] { opacity: .6; cursor: progress; }
        .intro section { padding: 32px 0; border-bottom: 1px solid var(--line); }
        .intro h2 { font: 700 1.6rem/1.2 Georgia, "Times New Roman", serif; margin: 0 0 6px; }
        .lead { color: var(--muted); margin: 0 0 18px; }
        .try { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; display: grid; gap: 12px; }
        .signed { color: var(--ok); font-weight: 600; }
        .error { color: var(--bad); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
        .card h3 { margin: 0 0 6px; font-size: 1rem; }
        .card p { margin: 0 0 8px; color: var(--muted); font-size: .95rem; }
        .intro code { font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .card code { color: var(--brand); overflow-wrap: anywhere; }
        .intro pre { margin: 0; background: var(--code-bg); color: var(--code-ink); border-radius: 12px; padding: 14px 16px; overflow-x: auto; }
        .steps { display: grid; gap: 14px; }
        .step-title { font-weight: 600; margin: 0 0 6px; }
        #reference { min-height: 60vh; }
        .site-footer { padding: 24px 0 40px; color: var(--muted); font-size: 14px; }
    </style>
</head>
<body>
<div class="intro">
<header class="hero">
    <div class="wrap">
        <div class="eyebrow">Bookly API · v1</div>
        <h1>A real bookstore backend you can try right now</h1>
        <p class="pitch">REST API for an online bookshop in Cambodia: catalog search, cart, cash-on-delivery checkout,
            returns, reviews and a staff back office with mandatory two-factor login. Built with Laravel 13 and PostgreSQL.</p>
        <div class="row">
            <span class="badge" id="status" aria-live="polite"><span class="dot"></span><span>Checking API status…</span></span>
            <span class="badge">Laravel 13</span>
            <span class="badge">PostgreSQL</span>
            <span class="badge">Sanctum tokens + TOTP 2FA</span>
            <span class="badge">OpenAPI 3</span>
        </div>
        <div class="row links">
            <a class="btn" href="https://github.com/danishsary8/bookly_backend_v2">GitHub repository</a>
            @if ($shop)
                <a class="btn" href="{{ $shop }}">Live storefront</a>
            @endif
            <a class="btn" href="/openapi.yaml">openapi.yaml</a>
        </div>
    </div>
</header>

<section id="try">
    <div class="wrap">
        <h2>Try it in 30 seconds</h2>
        <p class="lead">Sign in as the shared demo customer. Every <em>Test request</em> in the reference below is then sent with that token.</p>
        <div class="try">
            <div class="row">
                <button class="btn primary" id="demo-login" type="button">Try as demo customer</button>
                <span id="demo-state" aria-live="polite">Account <code>demo@bookly.test</code> · password <code>Password123!</code></span>
                <button class="btn" id="demo-logout" type="button" hidden>Sign out</button>
            </div>
            <div>Then open <strong>Customer → GET /cart</strong> and press <em>Test request</em>. Staff routes will answer
                <code>401</code>/<code>403</code> to this token: that is the access control working.</div>
        </div>
    </div>
</section>

<section id="security">
    <div class="wrap">
        <h2>Security at a glance</h2>
        <p class="lead">How the API protects customers, staff and stock.</p>
        <div class="grid">
            <div class="card"><h3>Bearer tokens with abilities</h3><p>Customer and staff tokens are separate: each only works on its own side of the API.</p><code>POST /auth/login · ability customer</code></div>
            <div class="card"><h3>Mandatory staff 2FA</h3><p>Staff sign in with a password plus an authenticator-app (TOTP) code; no 2FA, no access.</p><code>POST /staff/auth/login → /staff/auth/two-factor/challenge</code></div>
            <div class="card"><h3>Rate limiting</h3><p>120 requests/min per user or IP, plus strict limits on login, codes, coupon checks and checkout.</p><code>429 + Retry-After</code></div>
            <div class="card"><h3>Verified-email gate</h3><p>Shopping needs a verified account, so carts and orders belong to real, reachable people.</p><code>middleware verified.customer</code></div>
            <div class="card"><h3>Idempotent checkout</h3><p>Every order needs an Idempotency-Key, so a retried request never charges or ships twice.</p><code>Idempotency-Key header</code></div>
            <div class="card"><h3>Consistent JSON errors</h3><p>Every error is JSON (never a login redirect), and every response has an ID for tracing.</p><code>X-Request-Id</code></div>
        </div>
    </div>
</section>

<section id="quick-start">
    <div class="wrap">
        <h2>Quick start</h2>
        <p class="lead">The same three calls from a terminal.</p>
        <div class="steps">
            <div><p class="step-title">1 · Search the catalog (no login)</p>
<pre><code>curl "{{ $api }}/books?q=pride&amp;per_page=5" -H "Accept: application/json"</code></pre></div>
            <div><p class="step-title">2 · Log in as the demo customer</p>
<pre><code>curl -X POST "{{ $api }}/auth/login" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"demo@bookly.test","password":"Password123!"}'</code></pre></div>
            <div><p class="step-title">3 · Add a book to the cart with the token</p>
<pre><code>curl -X POST "{{ $api }}/cart/items" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer &lt;token&gt;" -d '{"book_variant_id":1,"quantity":1}'</code></pre></div>
        </div>
    </div>
</section>
</div>

<div id="reference"></div>

<footer class="site-footer"><div class="wrap">Bookly API · the demo account is shared and tidied every hour · <a href="https://github.com/danishsary8/bookly_backend_v2">Source on GitHub</a></div></footer>

<script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.74.0/dist/browser/standalone.js" integrity="sha384-VXnKAz61Xwc3tbw1P+KKrUP7YbcsSl1Ik4is5UnogJyw9XEYZ5Ay/T1i0zPYtSZH" crossorigin="anonymous"></script>
<script>
(() => {
    const API = @json($api);
    const DEMO = { email: 'demo@bookly.test', password: 'Password123!' };
    const KEY = 'bookly-docs-token';

    // The token lives in this variable; sessionStorage (this tab only) is a convenience that may be blocked.
    let token = null;
    try { token = sessionStorage.getItem(KEY); } catch (e) { /* private mode: keep it in memory */ }
    const remember = (t) => { token = t; try { t ? sessionStorage.setItem(KEY, t) : sessionStorage.removeItem(KEY); } catch (e) {} };

    const baseConfig = {
        url: '/openapi.yaml',
        withDefaultFonts: false,
        // No third-party AI chat or MCP hooks on a portfolio page: everything a visitor does goes to this API only.
        agent: { disabled: true },
        mcp: { disabled: true },
        showDeveloperTools: 'never',
        darkMode: window.matchMedia('(prefers-color-scheme: dark)').matches,
        defaultHttpClient: { targetKey: 'shell', clientKey: 'curl' },
        hiddenClients: { node: true, python: true, ruby: true, go: true, java: true, kotlin: true, csharp: true, c: true, clojure: true, objc: true, ocaml: true, powershell: true, r: true, swift: true, dart: true, fsharp: true, http: false, javascript: false, php: false, shell: false },
    };
    const withToken = () => ({ ...baseConfig, authentication: { preferredSecurityScheme: 'bearer', securitySchemes: { bearer: { token: token || '' } } } });

    let reference = null;
    if (window.Scalar) {
        reference = Scalar.createApiReference('#reference', withToken());
    } else {
        document.getElementById('reference').innerHTML = '<div class="wrap"><p class="error">The reference viewer could not load. The raw spec is at <a href="/openapi.yaml">/openapi.yaml</a>.</p></div>';
    }

    const loginBtn = document.getElementById('demo-login');
    const logoutBtn = document.getElementById('demo-logout');
    const state = document.getElementById('demo-state');
    const showSignedIn = () => {
        state.innerHTML = '<span class="signed">Signed in as demo@bookly.test ✓</span> · requests below now carry the token';
        loginBtn.hidden = true; logoutBtn.hidden = false;
    };
    const showSignedOut = (message) => {
        state.innerHTML = message ? '<span class="error"></span>' : 'Account <code>demo@bookly.test</code> · password <code>Password123!</code>';
        if (message) state.firstChild.textContent = message;
        loginBtn.hidden = false; logoutBtn.hidden = true; loginBtn.disabled = false; loginBtn.textContent = 'Try as demo customer';
    };
    const applyToken = () => { if (reference) reference.updateConfiguration(withToken()); };

    loginBtn.addEventListener('click', async () => {
        loginBtn.disabled = true; loginBtn.textContent = 'Signing in…';
        try {
            const res = await fetch(API + '/auth/login', {
                method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(DEMO),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok || !body.token) {
                showSignedOut(body.message || ('Sign-in failed (' + res.status + '). Try again in a minute.'));
                return;
            }
            remember(body.token); applyToken(); showSignedIn();
        } catch (e) {
            showSignedOut('The API is not reachable right now (it may be waking up). Try again in a minute.');
        }
    });

    logoutBtn.addEventListener('click', async () => {
        const old = token;
        remember(null); applyToken(); showSignedOut();
        try { await fetch(API + '/auth/logout', { method: 'POST', headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + old } }); } catch (e) {}
    });

    if (token) { showSignedIn(); }

    // Status: /up answers without the database, /health checks it. The free host sleeps when idle and can take about
    // a minute to wake, so each attempt waits up to 45 s and a first failure ("waking up") is retried once.
    const status = document.getElementById('status');
    const setStatus = (kind, text) => { status.innerHTML = '<span class="dot ' + kind + '"></span><span></span>'; status.lastChild.textContent = text; };
    const get = (url) => fetch(url, { headers: { 'Accept': 'application/json' }, signal: AbortSignal.timeout ? AbortSignal.timeout(45000) : undefined });
    const check = async (retried) => {
        try {
            const up = await get('/up');
            if (!up.ok) throw new Error('down');
            const health = await get(API + '/health');
            health.ok ? setStatus('ok', 'API operational') : setStatus('bad', 'Database unavailable');
        } catch (e) {
            if (retried) { setStatus('bad', 'API not reachable'); return; }
            setStatus('warn', 'Waking up… (free hosting sleeps when idle)');
            setTimeout(() => check(true), 20000);
        }
    };
    check(false);
})();
</script>
</body>
</html>
