// End-to-end test of the Let Agents plugin against real WordPress and WooCommerce in Playground.
//   LET_AGENTS_BASE_URL=http://127.0.0.1:9400 LET_AGENTS_FIXTURES=/path/fixtures.json node --test tests/playground/smoke.test.mjs
import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';

const base = process.env.LET_AGENTS_BASE_URL ?? 'http://127.0.0.1:9400';
const fixtures = JSON.parse(fs.readFileSync(process.env.LET_AGENTS_FIXTURES, 'utf8'));
const token = fixtures.token;
const P = fixtures.products;
// The version the plugin declares, so this test does not need editing on every release.
const pluginVersion = fs.readFileSync(new URL('../../let-agents.php', import.meta.url), 'utf8').match(/define\( 'LET_AGENTS_VERSION', '([^']+)' \)/)[1];

// ?rest_route= works with or without pretty permalinks.
const url = (route, query = {}) => {
  const params = new URLSearchParams({ rest_route: `/let-agents/v1${route}`, ...query });
  return `${base}/?${params}`;
};

// Redirects are not followed: a REST route that redirects is itself a failure worth seeing.
const get = async (route, { query, headers } = {}) => {
  const res = await fetch(url(route, query), { redirect: 'manual', headers: { Accept: 'application/json', ...headers } });
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = text;
  }
  return { status: res.status, body, headers: res.headers };
};

const authed = (route, query) => get(route, { query, headers: { 'X-LetAgents-Token': token } });

// Playground answers the very first request to a fresh site with a redirect of its own.
before(async () => {
  await fetch(`${base}/`, { redirect: 'follow' });
});

const allProducts = async (query = {}) => {
  const records = [];
  let after = 0;
  for (let page = 0; page < 20; page++) {
    const { status, body } = await authed('/feed/products', { per_page: '2', after: String(after), ...query });
    assert.equal(status, 200, JSON.stringify(body));
    records.push(...body.data);
    if (body.meta.next_after === null) return records;
    after = body.meta.next_after;
  }
  throw new Error('pagination did not end');
};

test('every route refuses requests without a token', async () => {
  for (const route of ['/status', '/feed/manifest', '/feed/products', '/feed/categories', '/feed/attributes', '/feed/content', '/meta-keys']) {
    const { status, body } = await get(route);
    assert.equal(status, 401, route);
    assert.equal(body.code, 'let_agents_missing_token', route);
  }
});

test('a wrong token is refused', async () => {
  const { status, body } = await get('/status', { headers: { 'X-LetAgents-Token': 'lat_' + 'x'.repeat(48) } });
  assert.equal(status, 401);
  assert.equal(body.code, 'let_agents_invalid_token');
});

test('there is no write route', async () => {
  const res = await fetch(url('/feed/products'), { method: 'POST', headers: { 'X-LetAgents-Token': token } });
  assert.ok([404, 405].includes(res.status), `POST returned ${res.status}`);
});

test('status describes the site, WooCommerce and active plugins', async () => {
  const { status, body, headers } = await authed('/status');
  assert.equal(status, 200, JSON.stringify(body));
  assert.equal(headers.get('cache-control'), 'no-store');

  const s = body.data;
  assert.equal(s.plugin.version, pluginVersion);
  assert.equal(s.woocommerce.active, true);
  assert.equal(s.woocommerce.currency, 'ILS');
  assert.ok(s.plugins.some((p) => p.name === 'WooCommerce'));
  assert.ok(s.plugins.some((p) => p.file === 'let-agents/let-agents.php'));
  assert.ok(s.counts.products.publish >= 4);
  assert.equal(s.counts.products.draft, 1);
  assert.match(s.plugin.token.prefix, /^lat_/);
  assert.ok(s.plugin.token.last_used_at, 'last_used_at is recorded');
  assert.equal(JSON.stringify(s).includes(token), false, 'the token itself is never returned');
});

test('a bearer token works too', async () => {
  const { status } = await get('/status', { headers: { Authorization: `Bearer ${token}` } });
  assert.equal(status, 200);
});

test('products page by ID, include only published by default, and never repeat', async () => {
  const records = await allProducts();
  const ids = records.map((r) => Number(r.external_id));

  assert.deepEqual([...ids].sort((a, b) => a - b), ids, 'ascending ID order');
  assert.equal(new Set(ids).size, ids.length, 'no duplicates across pages');
  for (const key of ['bits', 'drill', 'pro', 'variable']) assert.ok(ids.includes(P[key]), `${key} present`);
  assert.equal(ids.includes(P.draft), false, 'draft excluded');
  assert.equal(ids.includes(fixtures.variations.red), false, 'variations are not top-level products');

  const withDrafts = await allProducts({ status: 'any' });
  assert.ok(withDrafts.some((r) => Number(r.external_id) === P.draft));
});

test('a product carries clean text, category path, attributes, public meta, relations, price and stock', async () => {
  const { status, body } = await authed(`/feed/products/${P.drill}`);
  assert.equal(status, 200, JSON.stringify(body));
  const d = body.data;

  assert.equal(d.title, 'מקדחה רוטטת 550W "Pro"');
  assert.equal(d.sku, 'DR-550');
  assert.match(d.description, /550W/);
  assert.match(d.description, /מהירות משתנה\nראש 13 מ"מ/);
  assert.match(d.description, /שימושים:\nקידוח בבטון\nקידוח בעץ/, 'accordion titles and breaks with attributes end lines');
  for (const leak of ['<', 'alert', '[gallery', 'wp:paragraph']) assert.equal(d.description.includes(leak), false, `description leaks ${leak}`);

  assert.deepEqual(d.category_path, ['כלי עבודה', 'כלי עבודה חשמליים', 'מקדחות']);
  assert.deepEqual(d.attributes.map((a) => [a.name, a.values, a.taxonomy]), [['הספק', ['550W'], false]]);

  assert.equal(d.meta.power_watts, '550');
  assert.equal(d.meta.chuck_mm, '13');
  assert.equal('_internal_flag' in d.meta, false, 'private meta is not exported');
  for (const key of ['עלות ליחידה מהספק (לא לפרסום)', 'supplier_cost', 'הערת קליטה']) {
    assert.equal(key in d.meta, false, `sensitive field "${key}" is not exported`);
  }
  assert.equal(JSON.stringify(d).includes('4321.87'), false, 'the cost value appears nowhere in the record');

  assert.deepEqual(
    d.relations.map((r) => [r.type, Number(r.target), r.source]).sort(),
    [['cross_sell', P.bits, 'merchant'], ['upsell', P.pro, 'merchant']].sort(),
  );

  const bits = (await authed(`/feed/products/${P.bits}`)).body.data;
  assert.equal(bits.price.regular_price, '39.9', 'prices share one format');

  assert.equal(d.price.regular_price, '249');
  assert.equal(d.price.sale_price, '219');
  assert.equal(d.price.on_sale, true);
  assert.equal(d.stock.managed, true);
  assert.equal(d.stock.quantity, 7);
  assert.equal(d.stock.in_stock, true);
  assert.equal(d.dimensions.weight, '1.8');
  assert.match(d.hash, /^[0-9a-f]{64}$/);
  assert.ok(d.url.startsWith('http'));
});

test('the same product has the same hash in the list and on its own', async () => {
  const single = (await authed(`/feed/products/${P.drill}`)).body.data;
  const listed = (await allProducts()).find((r) => Number(r.external_id) === P.drill);
  assert.equal(listed.hash, single.hash);
});

test('a variable product lists its variations with readable attribute values', async () => {
  const v = (await authed(`/feed/products/${P.variable}`)).body.data;

  assert.equal(v.type, 'variable');
  assert.equal(v.variations.length, 2);
  assert.equal(v.variations_truncated, false);
  assert.deepEqual(v.variations.map((x) => x.attributes[0].value).sort(), ['אדום', 'שחור'].sort());
  assert.deepEqual(v.variations.map((x) => x.attributes[0].name), ['צבע', 'צבע']);
  assert.equal(v.price.min_price, '299');
  assert.equal(v.price.max_price, '319');
  assert.ok(v.attributes.some((a) => a.key === 'pa_color' && a.used_for_variations));
});

test('a variation or a missing ID is not a product', async () => {
  assert.equal((await authed(`/feed/products/${fixtures.variations.red}`)).status, 404);
  assert.equal((await authed('/feed/products/99999999')).status, 404);
});

test('categories and tags come in a fixed order, so an unchanged product keeps its hash', async () => {
  const first = (await authed(`/feed/products/${P.pro}`)).body.data;
  const ids = first.categories.map((c) => Number(c.id));
  assert.equal(ids.length, 3);
  assert.deepEqual(ids, [...ids].sort((a, b) => a - b), 'categories by ascending ID');
  assert.deepEqual(first.tags, [...first.tags].sort(), 'tags sorted');

  for (let i = 0; i < 3; i++) {
    assert.equal((await authed(`/feed/products/${P.pro}`)).body.data.hash, first.hash);
  }
});

test('since filters to changed products', async () => {
  const future = await authed('/feed/products', { since: '2999-01-01T00:00:00Z' });
  assert.equal(future.body.data.length, 0);

  const past = await authed('/feed/products', { since: '2000-01-01T00:00:00Z', per_page: '100' });
  assert.ok(past.body.data.length >= 4);

  const bad = await authed('/feed/products', { since: 'not a date' });
  assert.equal(bad.status, 400);
  assert.equal(bad.body.code, 'let_agents_invalid_since');
});

test('categories come with their full path', async () => {
  const { body } = await authed('/feed/categories');
  const drills = body.data.find((c) => Number(c.external_id) === fixtures.categories.drills);
  assert.deepEqual(drills.path, ['כלי עבודה', 'כלי עבודה חשמליים', 'מקדחות']);
  assert.equal(Number(drills.parent_id), fixtures.categories.power);
});

test('attributes list global terms and custom attributes typed on products', async () => {
  const { body } = await authed('/feed/attributes');
  const color = body.data.global.find((a) => a.key === 'pa_color');
  assert.deepEqual(color.terms.map((t) => t.name).sort(), ['אדום', 'שחור'].sort());

  const power = body.data.custom.find((a) => a.name === 'הספק');
  assert.equal(power.product_count, 1);
  assert.deepEqual(power.sample_values, ['550W']);
});

test('content includes published guides with the products they mention, and hides protected posts', async () => {
  const { status, body } = await authed('/feed/content', { type: 'post', per_page: '100' });
  assert.equal(status, 200, JSON.stringify(body));

  const guide = body.data.find((c) => Number(c.external_id) === fixtures.guide);
  assert.ok(guide, 'guide present');
  assert.equal(guide.title, 'איך בוחרים מקדחה');
  assert.deepEqual(guide.product_ids.map(Number).sort((a, b) => a - b), [P.bits, P.drill, P.pro].sort((a, b) => a - b));
  assert.equal(body.data.some((c) => Number(c.external_id) === fixtures.protected), false, 'password-protected post hidden');
});

test('pages are shared too, so what the shop promises can be read', async () => {
  const { status, body } = await authed('/feed/content', { type: 'page', per_page: '100' });
  assert.equal(status, 200, JSON.stringify(body));

  const terms = body.data.find((c) => Number(c.external_id) === fixtures.terms);
  assert.ok(terms, 'the terms page is shared');
  assert.equal(terms.title, 'תקנון האתר');
  assert.match(terms.text, /החזר מלא/, 'and its text comes through');
});

test('content types the merchant did not allow are refused', async () => {
  const { status, body } = await authed('/feed/content', { type: 'shop_order' });
  assert.equal(status, 400);
  assert.equal(body.code, 'let_agents_content_type_not_allowed');
});

test('meta keys show product custom fields with samples, and refuse other post types', async () => {
  const { body } = await authed('/meta-keys', { post_type: 'product' });
  const power = body.data.find((k) => k.key === 'power_watts');
  assert.deepEqual(power.samples, ['550']);
  assert.equal(power.private, false);
  assert.equal(body.data.find((k) => k.key === '_sku').private, true);
  assert.equal(power.sensitive, false);

  const cost = body.data.find((k) => k.key === 'עלות ליחידה מהספק (לא לפרסום)');
  assert.equal(cost.sensitive, true, 'a cost field is listed as sensitive');
  assert.deepEqual(cost.samples, [], 'and its values are never sampled');
  assert.equal(JSON.stringify(body).includes('internal import note'), false);

  const refused = await authed('/meta-keys', { post_type: 'shop_order' });
  assert.equal(refused.status, 400);
});

test('the manifest gives counts and a fingerprint', async () => {
  const { body } = await authed('/feed/manifest');
  assert.match(body.data.fingerprint, /^[0-9a-f]{64}$/);
  assert.ok(body.data.entities.products.published >= 4);
  assert.ok(body.data.entities['content:post'].published >= 1);

  const again = await authed('/feed/manifest');
  assert.equal(again.body.data.fingerprint, body.data.fingerprint, 'stable while nothing changes');
});

const pageHtml = async (query) => {
  const res = await fetch(`${base}/?${new URLSearchParams(query)}`, { redirect: 'follow' });
  assert.equal(res.status, 200, `page ${JSON.stringify(query)}`);
  return res.text();
};

const letAgentsContext = (html) => {
  // WordPress appends "//# sourceURL=..." to inline scripts, so match the JSON line only.
  const match = html.match(/window\.LetAgentsContext = (\{.*\});\n/);
  return match ? JSON.parse(match[1]) : null;
};

test('product pages and shared articles load the widget, with a site key derived from the token', async () => {
  const hash = crypto.createHash('sha256').update(token).digest('hex');
  const site = crypto.createHash('sha256').update(`let-agents-site|${hash}`).digest('hex').slice(0, 24);

  const html = await pageHtml({ post_type: 'product', p: String(P.drill) });
  const product = letAgentsContext(html);
  assert.ok(product, 'LetAgentsContext on a product page');
  assert.equal(product.site, site, 'the same formula as the Let Agents server');
  assert.equal(product.mode, 'preview', 'new installs start in preview');
  assert.equal(product.preview, null, 'visitors never get the preview key');
  assert.deepEqual(product.page, { type: 'product', id: String(P.drill) });
  assert.match(product.script, /^https:\/\/.+\/api\/v1\/widget\/let-agents\.js$/);
  assert.match(product.storeApi, /wc\/store\/v1\/$/);
  const tag = html.match(/<script[^>]*src="https:\/\/[^"]+\/widget\/let-agents\.js"[^>]*>/);
  assert.ok(tag, 'the widget script is loaded from Let Agents');
  assert.match(tag[0], /\sdefer[\s>=]/, 'deferred, so it never blocks the page');

  const guide = letAgentsContext(await pageHtml({ p: String(fixtures.guide) }));
  assert.deepEqual(guide.page, { type: 'content', id: String(fixtures.guide) });

  assert.equal(letAgentsContext(await pageHtml({})), null, 'not on the home page');
});

test('past orders leave the store as summaries only: paid, from the last 24 months, never the customer', () => {
  const { orders, requests, state, refs } = fixtures.history;

  assert.equal(requests.length, 1, 'one page for a small store');
  const [request] = requests;
  assert.match(request.url, /\/api\/v1\/plugin\/[a-f0-9]{24}\/orders\/history$/);
  assert.ok(request.headers.includes('X-LetAgents-Signature'), 'signed like every request to Let Agents');

  const body = request.body;
  assert.equal(body.first, true);
  assert.equal(body.last, true);
  assert.equal(body.expected, 2);
  assert.deepEqual(body.orders.map((o) => o.order_ref).sort(), [refs.recent, refs.older].sort(), 'paid orders inside the window only');

  for (const order of body.orders) {
    assert.deepEqual(Object.keys(order).sort(), ['currency', 'items', 'order_ref', 'ordered_at', 'total']);
    for (const item of order.items) {
      assert.deepEqual(Object.keys(item).sort(), ['product_id', 'quantity', 'total']);
    }
  }

  const text = JSON.stringify(body);
  for (const secret of ['ישראל', 'buyer@example.com', '0501234567', 'להשאיר ליד הדלת']) {
    assert.ok(!text.includes(secret), `"${secret}" never leaves the store`);
  }
  // The store's own order number is not the reference. Compared field by field: a short number
  // like 22 can turn up inside a date or a total by chance.
  for (const order of body.orders) {
    assert.notEqual(String(order.order_ref), String(orders.recent), 'the order number never leaves the store');
  }

  const recent = body.orders.find((o) => o.order_ref === refs.recent);
  assert.deepEqual(recent.items.map((i) => [i.product_id, i.quantity]), [[String(P.drill), 1], [String(P.bits), 2]]);
  assert.equal(state.status, 'done');
  assert.equal(state.sent, 2);
});

// Last: it locks this address out for ten minutes.
test('repeated wrong tokens lock the address out, even for the right token', async () => {
  // Earlier tests already spent some attempts, so count until the lock rather than assuming.
  let attempts = 0;
  let last;
  do {
    attempts++;
    last = await get('/status', { headers: { 'X-LetAgents-Token': `lat_wrong${attempts}` } });
  } while (last.status === 401 && attempts < 25);

  assert.equal(last.status, 429, 'locked out');
  assert.equal(last.body.code, 'let_agents_rate_limited');
  assert.ok(attempts <= 20, `locked after at most 20 wrong tokens, took ${attempts}`);

  const locked = await authed('/status');
  assert.equal(locked.status, 429);
  assert.equal(locked.body.code, 'let_agents_rate_limited');
});

test('the call to action is placed where the author asked for it', async () => {
  // Preview mode by default, and the widget only loads for the team — but the slot itself is
  // written by the plugin, so a visitor's HTML carries it either way.
  const html = await pageHtml({ p: String(fixtures.cta) });
  const slots = html.match(/class="let-agents-cta"/g) ?? [];

  assert.equal(slots.length, 1, 'one slot, where the shortcode was');
  assert.ok(html.indexOf('פסקה ראשונה') < html.indexOf('let-agents-cta'), 'after the first paragraph');
  assert.ok(html.indexOf('let-agents-cta') < html.indexOf('פסקה שנייה'), 'and before the second');
  assert.ok(!html.includes('[lets_cta]'), 'the shortcode itself is never printed');
});

test('a post with no shortcode gets nothing until a shop asks for a paragraph', async () => {
  const html = await pageHtml({ p: String(fixtures.long) });

  assert.ok(!html.includes('let-agents-cta'), 'nothing is added to a post nobody asked about');
});
// The email before checkout. Each shopper keeps their own cookies: the WooCommerce session lives in one.
const shopper = () => {
  const jar = new Map([['let_agents_vid', 'anon-testvisitor0123456789']]);
  const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
  const keep = (res) => {
    for (const line of res.headers.getSetCookie()) {
      const [pair, ...attrs] = line.split(';');
      const [name, ...rest] = pair.split('=');
      const value = rest.join('=');
      const expired = attrs.some((a) => /^\s*max-age=0/i.test(a) || (/^\s*expires=/i.test(a) && Date.parse(a.split('=')[1]) < Date.now()));
      if (expired || value === '' || value === 'deleted') jar.delete(name.trim());
      else jar.set(name.trim(), value);
    }
  };
  // Redirects are followed by hand, so cookies set on the way are kept.
  const request = async (path, init = {}, hops = 0) => {
    const target = path.startsWith('http') ? new URL(path) : new URL(path, base);
    const res = await fetch(`${base}${target.pathname}${target.search}`, { redirect: 'manual', ...init, headers: { ...init.headers, Cookie: cookie() } });
    keep(res);
    if ([301, 302, 303, 307].includes(res.status) && !init.method && hops < 5) {
      return request(res.headers.get('location'), {}, hops + 1);
    }
    const text = await res.text();
    let body;
    try {
      body = JSON.parse(text);
    } catch {
      body = text;
    }
    return { status: res.status, body, headers: res.headers };
  };
  const page = async (query = { post_type: 'product', p: String(P.drill) }) => {
    const { status, body } = await request(`/?${new URLSearchParams(query)}`);
    assert.equal(status, 200, `page ${JSON.stringify(query)}`);
    return body;
  };
  const context = async (query) => {
    const match = (await page(query)).match(/window\.LetAgentsCartContext = (\{.*\});\n/);
    return match ? JSON.parse(match[1]) : null;
  };
  const addToCart = (id, extra = {}) => request(`/?${new URLSearchParams({ 'add-to-cart': String(id), ...extra })}`);
  // nonce: undefined takes a fresh one from a page, null sends none.
  const capture = async (body, nonce) => {
    const headers = { 'Content-Type': 'application/json' };
    if (nonce !== null) headers['X-WP-Nonce'] = nonce ?? (await context()).nonce;
    return request('/?rest_route=/let-agents/v1/cart/capture', { method: 'POST', headers, body: JSON.stringify(body) });
  };
  return { request, page, context, addToCart, capture };
};

const consent = { consent: true, consent_text: 'אני מסכים/ה לקבל מייל על הסל שלי' };
const carts = async () => (await fetch(`${base}/?rest_route=/let-agents-test/v1/carts`)).json();
const address = { first_name: 'ישראל', last_name: 'ישראלי', address_1: 'הרצל 1', city: 'תל אביב', postcode: '6100000', country: 'IL', phone: '0501234567' };

test('every page but the thank-you page loads the cart script with what it needs', async () => {
  const hash = crypto.createHash('sha256').update(token).digest('hex');
  const site = crypto.createHash('sha256').update(`let-agents-site|${hash}`).digest('hex').slice(0, 24);
  const s = shopper();

  for (const query of [{}, { post_type: 'product', p: String(P.drill) }, { p: String(fixtures.protected) }]) { // A Latin slug: Playground 404s on Hebrew ones.
    const ctx = await s.context(query);
    assert.ok(ctx, `LetAgentsCartContext on ${JSON.stringify(query)}`);
    assert.equal(ctx.site, site);
    assert.match(ctx.api, /^https:\/\/.+\/api\/v1$/);
    assert.match(ctx.capture, /let-agents\/v1\/cart\/capture$/);
    assert.match(ctx.captureFallback, /\?rest_route=\/let-agents\/v1\/cart\/capture$/);
    assert.match(ctx.nonce, /^[a-f0-9]{10}$/);
    assert.ok(ctx.checkoutUrl.startsWith('http'));
    assert.equal(ctx.cartCount, 0);
    assert.equal(typeof ctx.locale, 'string');
  }

  const tag = (await s.page()).match(/<script[^>]*src="https:\/\/[^"]+\/cart\/let-agents-cart\.js"[^>]*>/);
  assert.ok(tag, 'the cart script is loaded from Let Agents');
  assert.match(tag[0], /\sdefer[\s>=]/, 'deferred, so it never blocks the page');
});

test('the capture refuses a missing nonce, a bad email, no consent and an empty cart', async () => {
  const s = shopper();

  const noNonce = await s.capture({ email: 'a@example.com', ...consent }, null);
  assert.equal(noNonce.status, 403, JSON.stringify(noNonce.body));

  const wrongNonce = await s.capture({ email: 'a@example.com', ...consent }, 'abcdef0123');
  assert.equal(wrongNonce.status, 403, JSON.stringify(wrongNonce.body));

  const badEmail = await s.capture({ email: 'not an email', ...consent });
  assert.equal(badEmail.status, 422);
  assert.deepEqual(badEmail.body, { error: 'invalid_email' });

  const noConsent = await s.capture({ email: 'a@example.com', consent: 'yes', consent_text: 'x' });
  assert.equal(noConsent.status, 422);
  assert.deepEqual(noConsent.body, { error: 'no_consent' });

  const empty = await s.capture({ email: 'a@example.com', ...consent });
  assert.equal(empty.status, 409);
  assert.deepEqual(empty.body, { error: 'empty_cart' });
});

test('the email saves the cart as an order waiting for payment, leaving it again updates it, and classic checkout finishes it', async () => {
  const s = shopper();
  // A product without stock management: Playground's SQLite cannot run WooCommerce's stock hold at checkout.
  await s.addToCart(P.pro);
  assert.equal((await s.context()).cartCount, 1, 'the product is in the cart');

  const searches = [{ q: 'מקדחה רוטטת', at: '2026-10-08T09:00:00Z' }, { q: 'x'.repeat(200), at: 'not a date' }, { q: '' }];
  const first = await s.capture({ email: 'first@example.com', ...consent, searches });
  assert.equal(first.status, 200, JSON.stringify(first.body));
  assert.deepEqual(first.body, { ok: true }, 'the answer says nothing about the order');

  let { orders } = await carts();
  let ours = orders.filter((o) => o.capture);
  assert.equal(ours.length, 1);
  const [order] = ours;
  assert.equal(order.status, 'pending');
  assert.equal(order.created_via, 'let-agents');
  assert.equal(order.email, 'first@example.com');
  assert.equal(order.consent.text, consent.consent_text);
  assert.deepEqual(order.items, [[P.pro, 0, 1]]);
  assert.equal(Number(order.total), 399);
  assert.equal(order.notes.length, 1, 'one note says where the order came from');

  await s.addToCart(P.variable, { variation_id: String(fixtures.variations.red), attribute_pa_color: 'red', quantity: '2' });
  const second = await s.capture({ email: 'second@example.com', ...consent });
  assert.equal(second.status, 200, JSON.stringify(second.body));

  ({ orders } = await carts());
  ours = orders.filter((o) => o.capture);
  assert.equal(ours.length, 1, 'the same order, not a second one');
  assert.equal(ours[0].id, order.id);
  assert.equal(ours[0].email, 'second@example.com');
  assert.deepEqual(ours[0].items, [[P.pro, 0, 1], [P.variable, fixtures.variations.red, 2]]);

  const sent = (await carts()).requests.filter((r) => r.url.endsWith('/carts'));
  assert.equal(sent.length, 2, 'Let Agents is told each time');
  assert.match(sent[1].url, /\/api\/v1\/plugin\/[a-f0-9]{24}\/carts$/);
  assert.ok(sent[1].headers.includes('X-LetAgents-Signature'), 'signed like every request to Let Agents');

  const payload = sent[1].body;
  assert.deepEqual(Object.keys(payload).sort(), ['admin_url', 'captured_at', 'consent', 'consent_text', 'currency', 'email', 'items', 'order_id', 'order_ref', 'searches', 'total', 'vid']);
  assert.equal(payload.order_id, order.id);
  assert.match(payload.order_ref, /^[a-f0-9]{64}$/);
  assert.match(payload.admin_url, new RegExp(`/wp-admin/.*[=/]${order.id}(&|$)`));
  assert.equal(payload.email, 'second@example.com');
  assert.equal(payload.consent, true);
  assert.equal(payload.consent_text, consent.consent_text);
  assert.equal(payload.vid, 'anon-testvisitor0123456789');
  assert.equal(payload.currency, 'ILS');
  assert.equal(payload.total, '997.00');
  assert.deepEqual(payload.items, [
    { product_id: String(P.pro), variation_id: null, quantity: 1, total: '399.00' },
    { product_id: String(P.variable), variation_id: String(fixtures.variations.red), quantity: 2, total: '598.00' },
  ]);
  assert.match(payload.captured_at, /^\d{4}-\d\d-\d\dT/);
  assert.deepEqual(payload.searches, []);

  const firstPayload = sent[0].body;
  assert.equal(firstPayload.searches.length, 2, 'empty searches are dropped');
  assert.deepEqual(firstPayload.searches[0], { q: 'מקדחה רוטטת', at: '2026-10-08T09:00:00+00:00' });
  assert.equal(firstPayload.searches[1].q.length, 120, 'cut to 120 characters');
  assert.equal(firstPayload.searches[1].at, null);

  // Classic checkout finishes the same order rather than making another.
  const form = String((await s.request(`/?page_id=${fixtures.classic_checkout}`)).body);
  const nonce = form.match(/name="woocommerce-process-checkout-nonce" value="([^"]+)"/);
  assert.ok(nonce, 'the classic checkout form is on the checkout page');

  const fields = Object.fromEntries(Object.entries(address).map(([k, v]) => [`billing_${k}`, v]));
  const placed = await s.request('/?wc-ajax=checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ ...fields, billing_email: 'second@example.com', payment_method: 'bacs', 'woocommerce-process-checkout-nonce': nonce[1] }),
  });
  assert.equal(placed.status, 200);
  assert.equal(placed.body.result, 'success', JSON.stringify(placed.body));

  ({ orders } = await carts());
  ours = orders.filter((o) => o.capture);
  assert.equal(ours.length, 1, 'no second order');
  assert.equal(ours[0].id, order.id, 'checkout resumed the order the email made');
  assert.equal(ours[0].status, 'on-hold', 'and finished it');
  assert.equal(ours[0].created_via, 'checkout');
  assert.equal(orders.filter((o) => o.email === 'second@example.com').length, 1, 'one order for this shopper');

  const thanks = await s.request(placed.body.redirect);
  assert.equal(thanks.status, 200);
  assert.ok(!String(thanks.body).includes('LetAgentsCartContext'), 'not on the thank-you page');

  const again = await s.capture({ email: 'second@example.com', ...consent });
  assert.equal(again.status, 409, 'checkout emptied the cart');
});

test('block checkout makes its own order, and the one the email made goes to the trash', async () => {
  const s = shopper();
  await s.addToCart(P.bits);
  assert.equal((await s.capture({ email: 'blocks@example.com', ...consent })).status, 200);

  const captured = (await carts()).orders.find((o) => o.capture && o.email === 'blocks@example.com');
  assert.equal(captured.status, 'pending');

  // The Store API nonce the widget gets on a product page.
  const widget = JSON.parse((await s.page()).match(/window\.LetAgentsContext = (\{.*\});\n/)[1]);
  const placed = await s.request('/?rest_route=/wc/store/v1/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Nonce: widget.nonce },
    body: JSON.stringify({ billing_address: { ...address, email: 'blocks@example.com' }, shipping_address: address, payment_method: 'bacs' }),
  });
  assert.equal(placed.status, 200, JSON.stringify(placed.body));
  assert.notEqual(placed.body.order_id, captured.id);

  const { orders } = await carts();
  assert.equal(orders.find((o) => o.id === captured.id).status, 'trash', 'the order the email made is in the trash');
  assert.notEqual(orders.find((o) => o.id === placed.body.order_id).status, 'trash', 'the placed order stays');
});

test('the capture takes ten tries an hour from one address', async () => {
  const s = shopper();
  let attempts = 0;
  let last;
  do {
    attempts++;
    last = await s.capture({ email: 'not an email', ...consent });
  } while (last.status !== 429 && attempts < 15);

  assert.equal(last.status, 429);
  assert.deepEqual(last.body, { error: 'rate_limited' });
  assert.ok(attempts <= 10, `limited after at most ten tries, took ${attempts}`);
});
