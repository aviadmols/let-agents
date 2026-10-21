#!/usr/bin/env node
/*
 * Builds the one file a store loads: resources/search/lets-search.js (served at
 * /api/v1/search/lets-search.js). The built file is committed and shipped as written; there is no
 * build step on deploy. After editing anything here or in hebrew-search-engine.js, run
 *
 *   node apps/api/app/Modules/Search/resources/search/lets-search/build.js      (or: npm run build:lets-search)
 *
 * LetsSearchBuildTest rebuilds into a temporary file and fails if the committed file differs.
 *
 * The bundle holds, in this order:
 *   1. ../hebrew-search-engine.js, as written. It sets window.LA_ENGINE (unless a page already set
 *      one). It must stay identical to HebrewSearch.php, which is why it is never copied here.
 *   2. the drawer: drawer/*.js joined in their numbered order, with the three stylesheets (tokens,
 *      components, drawer) inlined into its CSS placeholder: no extra requests, no flash.
 *      The drawer is one function, its parts sharing one scope: 00-open.js opens it and 99-close.js
 *      closes it; every part between holds whole statements of that function, by subject (the
 *      filters, the search, the cart…), and each must parse on its own. A new part takes a number
 *      between its neighbours; what one part names, any other may use.
 *
 * Options: --out <file> writes elsewhere (the test uses it). The output depends only on the sources,
 * never on the date or the machine, so the same sources always build the same bytes.
 *
 * The result is checked before it is written: the engine runs, in Node and as a browser would
 * load it, and finds a product in a sample index; the bundle parses.
 */
'use strict';
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const HERE = __dirname;
const ENGINE = path.join(HERE, '..', 'hebrew-search-engine.js');
const STYLESHEETS = ['tokens.css', 'components.css', 'drawer.css'];
const REQUIRED = ['normalize', 'build', 'search', 'isQuestion', 'focus', 'closeAnswer', 'readActions', 'applyActions',
  'handleFromUrl', 'formatMoney', 'fromShopifyProduct'];

const args = process.argv.slice(2);
const outAt = args.indexOf('--out');
const OUT = path.resolve(outAt >= 0 && args[outAt + 1] ? args[outAt + 1] : path.join(HERE, '..', 'lets-search.js'));

function fail(message) {
  console.error('build: ' + message);
  process.exit(1);
}

// Line endings never change the output, whatever the checkout does.
function read(file) {
  return fs.readFileSync(file, 'utf8').replace(/\r\n?/g, '\n');
}

// ---------------------------------------------------------------- 1. the engine
const engine = read(ENGINE);
// In the bundle `module` is shadowed, so a page that happens to define a global `module` still
// gets window.LA_ENGINE instead of the engine disappearing into that object.
const engineForBrowser = '(function (module) {\n' + engine + '})();\n';
let names = [];

try {
  const sample = [{ id: 'p:1', title: 'אימפקט נטען 18V', keywords: 'מברגה' }, { id: 'p:2', title: 'שמן לעץ', keywords: 'שמן' }];
  const check = (e, how) => {
    const hit = e.search(e.build(sample), 'אימפקט');
    if (!hit.length || String(hit[0].id) !== 'p:1') { fail('engine sample search failed (' + how + ')'); }
    if (!e.isQuestion('איזה אימפקט מתאים לפרגולה?')) { fail('engine isQuestion failed (' + how + ')'); }
  };

  const node = { exports: {} };
  vm.runInNewContext(engine, { module: node });
  names = Object.keys(node.exports);
  const missing = REQUIRED.filter((name) => typeof node.exports[name] !== 'function');
  if (missing.length) { fail('the engine does not export ' + missing.join(', ')); }
  check(node.exports, 'node');

  const win = {};
  win.window = win;
  vm.runInNewContext(engineForBrowser, win);
  if (!win.LA_ENGINE) { fail('the engine did not set window.LA_ENGINE'); }
  check(win.LA_ENGINE, 'browser');
} catch (err) {
  fail('the engine does not run: ' + err.message);
}

// ---------------------------------------------------------------- 2. the drawer and its stylesheets
const css = {};
STYLESHEETS.forEach((file) => {
  // comments out, whitespace folded: the rules stay exactly as written
  css[file] = read(path.join(HERE, file)).replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s*\n\s*/g, '\n').trim();
});

const PARTS = path.join(HERE, 'drawer');
const parts = fs.readdirSync(PARTS).filter((file) => /^\d{2}-[a-z0-9-]+\.js$/.test(file)).sort();
if (parts[0] !== '00-open.js' || parts[parts.length - 1] !== '99-close.js') { fail('drawer/ must begin with 00-open.js and end with 99-close.js'); }
parts.slice(1, -1).forEach((file) => {
  try { new vm.Script(read(path.join(PARTS, file)), { filename: file }); } catch (err) { fail('drawer/' + file + ' does not parse on its own: ' + err.message); }
});
let drawer = parts.map((file) => read(path.join(PARTS, file))).join('');
if (drawer.indexOf('/*@@CSS@@*/') < 0) { fail('the drawer has no CSS placeholder'); }
drawer = drawer.replace('/*@@CSS@@*/', () => 'CSS = ' + JSON.stringify(css) + ';');

// ---------------------------------------------------------------- the bundle
const body = engineForBrowser + drawer;
const version = crypto.createHash('sha256').update(body).digest('hex').slice(0, 12);
const bundle = '/*! Lets Search ' + version + ' · built by lets-search/build.js from hebrew-search-engine.js (' +
  names.length + ' functions) and lets-search/ — do not edit this file, edit those and rebuild */\n' + body;

try { new vm.Script(bundle); } catch (err) { fail('the bundle does not parse: ' + err.message); }

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, bundle);
console.log('build: ' + path.relative(process.cwd(), OUT) + ' · ' + Math.round(Buffer.byteLength(bundle) / 1024) + ' KB · ' + version +
  ' · engine: ' + names.join(', '));
