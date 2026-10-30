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
        .bar-in { max-width: 1460px; margin: 0 auto; padding: 18px 20px; display: flex; flex-wrap: wrap; gap: 14px 24px; align-items: center; justify-content: space-between; }
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

        /* Demo strip: one sentence telling the visitor what to do next. */
        .demo { max-width: 1460px; margin: 16px auto 0; padding: 0 20px; }
        .demo-in { background: #fff7f3; border: 1px solid #f6d3c4; border-radius: 12px; padding: 12px 16px; display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
        .demo-in .msg { flex: 1 1 320px; }
        .ok-text { color: var(--ok); font-weight: 600; }
        .err-text { color: var(--bad); }
        .demo-in code, footer code { font: 13px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #eef0f4; padding: 1px 5px; border-radius: 5px; }

        /* Swagger UI, lightly restyled: same layout as before, cleaner surfaces. */
        #swagger-ui { max-width: 1460px; margin: 0 auto; padding: 8px 20px 48px; }
        .swagger-ui, .swagger-ui .info .title, .swagger-ui .opblock-tag, .swagger-ui .opblock .opblock-summary-description { font-family: inherit; color: var(--ink); }
        .swagger-ui .topbar { display: none; }
        .swagger-ui .info { margin: 24px 0 8px; }
        .swagger-ui .info .title small { background: var(--brand); }
        .swagger-ui .info .markdown p, .swagger-ui .info .markdown li { font-size: 14px; color: var(--muted); }
        .swagger-ui .scheme-container { background: transparent; box-shadow: none; padding: 8px 0 16px; }
        .swagger-ui .opblock-tag { border-bottom: 1px solid var(--line); font-size: 20px; }
        .swagger-ui .opblock { border-radius: 10px; box-shadow: none; margin: 0 0 8px; background: var(--card) !important; border-width: 1px; }
        .swagger-ui .opblock .opblock-summary { padding: 8px 10px; }
        .swagger-ui .opblock .opblock-summary-method { border-radius: 7px; min-width: 70px; font-weight: 700; }
        .swagger-ui .opblock.opblock-get { border-color: #c9dcf5; } .swagger-ui .opblock.opblock-get .opblock-summary-method { background: #2f6fd6; }
        .swagger-ui .opblock.opblock-post { border-color: #c4e8d4; } .swagger-ui .opblock.opblock-post .opblock-summary-method { background: #1f9d5b; }
        .swagger-ui .opblock.opblock-put { border-color: #f3dfbd; } .swagger-ui .opblock.opblock-put .opblock-summary-method { background: #d4880f; }
        .swagger-ui .opblock.opblock-patch { border-color: #d7e9e4; } .swagger-ui .opblock.opblock-patch .opblock-summary-method { background: #2a9d8f; }
        .swagger-ui .opblock.opblock-delete { border-color: #f3c9c9; } .swagger-ui .opblock.opblock-delete .opblock-summary-method { background: #d64545; }
        .swagger-ui .btn.authorize { border-radius: 10px; color: var(--ok); border-color: var(--ok); }
        .swagger-ui .btn.execute { background: var(--brand); border-color: var(--brand); border-radius: 8px; }
        .swagger-ui .btn { border-radius: 8px; }
        .swagger-ui section.models { border-radius: 10px; border-color: var(--line); background: var(--card); }
        footer { max-width: 1460px; margin: 0 auto; padding: 0 20px 32px; color: var(--muted); font-size: 13px; }
        @media (max-width: 640px) { .brand p { display: none; } .swagger-ui .opblock .opblock-summary-path { font-size: 13px; } }
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

<section class="demo" aria-label="Try the API">
    <div class="demo-in">
        <button class="btn" id="demo-login" type="button">Sign in as demo customer</button>
        <span class="msg" id="demo-state" aria-live="polite">One click, no password. Then open any endpoint, press <strong>Try it out</strong> and <strong>Execute</strong>.</span>
        <button class="btn ghost" id="demo-logout" type="button" hidden>Sign out</button>
    </div>
</section>

<div id="swagger-ui"></div>

<footer>The demo account is shared and tidied every hour. Staff routes answer <code>403</code> to a customer token: that is the access control working.</footer>

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
            docExpansion: 'list',
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
        state.innerHTML = '<span class="ok-text">Signed in as demo@bookly.test ✓</span> Open <strong>Customer → GET /cart</strong>, press <strong>Try it out</strong>, then <strong>Execute</strong>.';
        loginBtn.hidden = true; logoutBtn.hidden = false;
    };
    const showSignedOut = (error) => {
        if (error) { state.innerHTML = '<span class="err-text"></span>'; state.firstChild.textContent = error; }
        else { state.innerHTML = 'One click, no password. Then open any endpoint, press <strong>Try it out</strong> and <strong>Execute</strong>.'; }
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
            health.ok ? setStatus('ok', 'API operational') : setStatus('bad', 'Database unavailable');
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
