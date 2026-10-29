// The design kit's server. No packages needed: `node serve.mjs` (Node 18+). Two addresses:
//
//   http://localhost:5500  this folder, never cached: the local dashboard (examples/), merchant.css, layout/.
//   http://localhost:5501  the shop's live panel, through this computer: every tab, filter and button works as on the
//                          site, with real data, and every screen is drawn with this folder's merchant.css and
//                          layout/layout.js. Open the design link here: http://localhost:5501/design/<token>.
//
// The live address only passes the browser's requests on to the panel (agents.lets.co.il) and the answers back,
// with the panel's addresses changed to this one, so the browser stays here. Nothing is stored or sent anywhere else.
import { createServer } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { readFile, stat, writeFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { extname, join, normalize, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import zlib from 'node:zlib';

const root = fileURLToPath(new URL('.', import.meta.url));
const port = Number(process.env.PORT || 5500);
const livePort = Number(process.env.LIVE_PORT || port + 1);
const base = process.env.LETS_HOST || 'agents.lets.co.il';
const here = `http://localhost:${livePort}`;
const types = { '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2', '.md': 'text/plain; charset=utf-8' };

// ---------------------------------------------------------------- this folder

async function serveFile(res, pathname, headers = {}) {
    const path = normalize(join(root, decodeURIComponent(pathname)));
    if (!path.startsWith(root.endsWith(sep) ? root : root + sep)) {
        res.writeHead(403, headers);
        return res.end();
    }
    try {
        if (!(await stat(path)).isFile()) throw new Error('not a file');
        res.writeHead(200, { ...headers, 'Cache-Control': 'no-store', 'Content-Type': types[extname(path)] || 'application/octet-stream' });
        res.end(await readFile(path));
    } catch {
        res.writeHead(404, { ...headers, 'Cache-Control': 'no-store' });
        res.end('not found');
    }
}

createServer(async (req, res) => {
    const headers = {
        'Access-Control-Allow-Origin': '*',
        // the panel is on https and this server on the local network: Chrome asks the page for leave to reach it
        'Access-Control-Allow-Private-Network': 'true',
    };
    if (req.method === 'OPTIONS') {
        res.writeHead(204, { ...headers, 'Access-Control-Allow-Methods': 'GET', 'Access-Control-Allow-Headers': '*' });
        return res.end();
    }
    return serveFile(res, new URL(req.url, 'http://localhost').pathname, headers);
}).listen(port, '127.0.0.1');

// ---------------------------------------------------------------- the live panel, through this computer

// The shop's own address (sellameir.agents.lets.co.il), learnt from where the design link sends the browser.
const shopFile = join(root, '.live-shop');
let shopHost = null;
try { shopHost = readFileSync(shopFile, 'utf8').trim() || null; } catch { /* not opened yet */ }

// The design link: the sign-in. Kept in design-link.txt (from the kit, from `node serve.mjs <link>`, or from the link
// opened or pasted here), so the panel never asks for a password: where it would, this server opens the link again.
const linkFile = join(root, 'design-link.txt');
const tokenOf = (s) => (String(s || '').match(/design\/([A-Za-z0-9]{48})/) || [])[1] || null;
let token = null;
try { token = tokenOf(readFileSync(linkFile, 'utf8')); } catch { /* none yet */ }
const keepToken = (t) => {
    if (!t || t === token) return;
    token = t;
    writeFile(linkFile, `https://${base}/design/${t}\n`).catch(() => {});
};
keepToken(tokenOf(process.argv[2]));
// one sign-in at a time: a link that did not sign in (closed, or the browser refused its cookie) is not tried again
// and again, the panel's own page shows instead
const recentlyTried = (req) => /(?:^|;\s*)lets_kit_auto=1/.test(String(req.headers.cookie || ''));
const signIn = (res) => {
    res.writeHead(302, { location: `/design/${token}`, 'set-cookie': 'lets_kit_auto=1; Max-Age=20; Path=/; SameSite=Lax', 'cache-control': 'no-store' });
    res.end();
};
const askForLink = (res, note = '') => {
    res.writeHead(200, { 'Content-Type': types['.html'], 'Cache-Control': 'no-store' });
    res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body dir="rtl" style="font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 16px">
<h1 style="font-size:22px">פאנל החנות מהמחשב שלך</h1>
${note ? `<p style="color:#b42318">${note}</p>` : ''}
<p>מדביקים כאן את קישור העיצוב שקיבלתם מ-Lets. צריך לעשות את זה פעם אחת: מעכשיו הפאנל ייפתח בלי סיסמה, עד שהקישור יפוג.</p>
<form action="/__kit/link" method="get" style="display:flex;gap:8px"><input name="u" dir="ltr" required placeholder="https://${base}/design/..." style="flex:1;padding:10px;font:inherit;border:1px solid #ccc;border-radius:8px"><button style="padding:10px 18px;font:inherit;border:0;border-radius:8px;background:#111;color:#fff">לפתוח</button></form>
</body>`);
};

const escaped = base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
// the panel's addresses, plain and as JSON writes them (https:\/\/…)
const panelUrl = new RegExp(`https?:(\\\\?/)\\1((?:[a-z0-9-]+\\.)*${escaped})`, 'gi');
const toHere = (text) => text.replace(panelUrl, (m, slash) => (slash === '\\/' ? here.replace(/\//g, '\\/') : here));
const isPanelHost = (host) => host === base || host.endsWith('.' + base);

const kitHead = `
<link rel="stylesheet" href="/__kit/merchant.css" data-kit-css media="print" onload="if(localStorage.getItem('lets-kit-css')!=='0'){this.media='all';document.querySelectorAll('style[data-lets-merchant]').forEach(function(s){s.disabled=true})}">
<script>window.__letsKitLayout = localStorage.getItem('lets-kit-layout') !== '0';</script>
<script src="/__kit/layout/default.js" defer></script>
<script src="/__kit/layout/layout.js" defer></script>
<script src="/__kit/layout/apply.js" defer></script>`;

const kitBar = `
<div data-kit-bar dir="rtl" style="position:fixed;inset-inline-start:12px;bottom:64px;z-index:2147483000;display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:6px 12px;border-radius:12px;background:#1d4ed8;color:#fff;font:500 12px/1.4 Heebo,system-ui,sans-serif;box-shadow:0 10px 28px -12px rgba(0,0,0,.5)">
  <strong>מהמחשב שלך</strong>
  <label style="display:flex;gap:5px;align-items:center;cursor:pointer"><input type="checkbox" data-kit-toggle="lets-kit-css"> merchant.css</label>
  <label style="display:flex;gap:5px;align-items:center;cursor:pointer"><input type="checkbox" data-kit-toggle="lets-kit-layout"> סדר ושמות (layout.js)</label>
  <span data-layout-status style="opacity:.8"></span>
</div>
<script>
  document.querySelectorAll('[data-kit-toggle]').forEach(function (box) {
    var key = box.getAttribute('data-kit-toggle');
    box.checked = localStorage.getItem(key) !== '0';
    box.addEventListener('change', function () { localStorage.setItem(key, box.checked ? '1' : '0'); location.reload(); });
  });
</script>`;

// The panel's cookies, for this address: no domain, and no Secure or Partitioned (a partitioned cookie must be
// Secure, and http://localhost is not https), or the browser drops the sign-in.
function rewriteCookie(cookie) {
    return cookie
        .replace(/;\s*domain=[^;]*/gi, '')
        .replace(/;\s*secure/gi, '')
        .replace(/;\s*partitioned/gi, '')
        .replace(/;\s*samesite=none/gi, '; SameSite=Lax');
}

async function serveKitFile(res, pathname) {
    // the layout is laid only while its switch in the bar is on
    if (pathname === '/layout/apply.js') {
        try {
            const js = await readFile(join(root, 'layout', 'apply.js'), 'utf8');
            res.writeHead(200, { 'Cache-Control': 'no-store', 'Content-Type': types['.js'] });
            return res.end(js.replace("'use strict';", "'use strict';\n  if (window.__letsKitLayout === false) { return; }"));
        } catch {
            res.writeHead(404);
            return res.end();
        }
    }
    return serveFile(res, pathname);
}

createServer((req, res) => {
    const url = new URL(req.url, here);
    if (url.pathname === '/__kit/link') {
        const t = tokenOf(url.searchParams.get('u'));
        if (!t) return askForLink(res, 'זה לא קישור עיצוב. הוא נראה כך: https://' + base + '/design/ ואחריו 48 אותיות ומספרים.');
        keepToken(t);
        return signIn(res);
    }
    if (url.pathname.startsWith('/__kit/')) {
        return serveKitFile(res, url.pathname.slice('/__kit'.length));
    }
    const design = url.pathname.match(/^\/design\/([A-Za-z0-9]{48})$/);
    if (design) keepToken(design[1]);
    // the start, or the panel's sign-in page: the design link instead of a password
    if (req.method === 'GET' && (url.pathname === '/' || /\/login$/.test(url.pathname))) {
        if (!token) return askForLink(res);
        if (!recentlyTried(req)) return signIn(res);
    }

    // the design link goes to the platform; everything else to the shop it opened
    const host = /^\/design\/[A-Za-z0-9]{48}$/.test(url.pathname) || !shopHost ? base : shopHost;
    const headers = { ...req.headers, host, 'accept-encoding': 'gzip, deflate, br' };
    for (const h of ['origin', 'referer']) {
        if (headers[h]) headers[h] = headers[h].split(here).join('https://' + host);
    }

    const up = httpsRequest({ host, port: 443, method: req.method, path: req.url, headers }, (upRes) => {
        const out = { ...upRes.headers };
        delete out['strict-transport-security'];
        delete out['content-security-policy'];
        delete out['alt-svc'];
        if (out.location) {
            try {
                const to = new URL(out.location, 'https://' + host);
                if (isPanelHost(to.hostname) && to.hostname !== base && to.hostname !== shopHost) {
                    shopHost = to.hostname;
                    writeFile(shopFile, shopHost).catch(() => {});
                    console.log(`Live panel: ${shopHost}`);
                }
            } catch { /* a relative address stays as it is */ }
            out.location = toHere(out.location);
            // sent to sign in (the session ended): through the design link, no password
            if (token && /\/login(?:\?|$)/.test(out.location) && !recentlyTried(req)) {
                out.location = `/design/${token}`;
                const auto = 'lets_kit_auto=1; Max-Age=20; Path=/; SameSite=Lax';
                out['set-cookie'] = [...(out['set-cookie'] || []), auto];
            }
        }
        if (out['set-cookie']) out['set-cookie'] = out['set-cookie'].map(rewriteCookie);

        const type = String(out['content-type'] || '');
        if (!/text\/|json|javascript/.test(type)) {
            res.writeHead(upRes.statusCode, out);
            return upRes.pipe(res);
        }
        const chunks = [];
        upRes.on('data', (c) => chunks.push(c));
        upRes.on('end', () => {
            let body = Buffer.concat(chunks);
            const enc = String(out['content-encoding'] || '');
            try {
                if (enc.includes('br')) body = zlib.brotliDecompressSync(body);
                else if (enc.includes('gzip')) body = zlib.gunzipSync(body);
                else if (enc.includes('deflate')) body = zlib.inflateSync(body);
            } catch { /* sent as it came */ }
            let text = toHere(body.toString('utf8'));
            if (type.includes('text/html')) {
                text = text.replace('</head>', kitHead + '\n</head>').replace(/<\/body>(?![\s\S]*<\/body>)/, kitBar + '\n</body>');
            }
            delete out['content-encoding'];
            delete out['content-length'];
            out['cache-control'] = 'no-store';
            res.writeHead(upRes.statusCode, out);
            res.end(text);
        });
    });
    up.on('error', (e) => {
        res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('The panel did not answer: ' + e.message);
    });
    req.pipe(up);
}).listen(livePort, '127.0.0.1', () => {
    console.log(`Local dashboard:  http://localhost:${port}/examples/`);
    console.log(`merchant.css:     http://localhost:${port}/merchant.css`);
    console.log(`Live panel:       ${here}` + (token ? '  (signs in with the design link in design-link.txt)' : '  (paste the design link there once)'));
    if (shopHost) console.log(`                  last opened: ${shopHost}`);
});
