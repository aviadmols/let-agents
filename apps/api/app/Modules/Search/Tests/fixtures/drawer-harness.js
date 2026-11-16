// Runs the drawer's filter, search and answer parts in Node, with the few page objects they touch stubbed, and prints
// the JSON value of an expression over them (DrawerFiltersTest). Usage: node drawer-harness.js <resources/search> '<expr>'
// The expression sees T: { S, C, filtered, wouldCounts, facetChips, niceLabel, plainlySays, sized, handsOver, t }.
'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const base = process.argv[2];
const expression = process.argv[3];
const drawer = path.join(base, 'lets-search', 'drawer');
const engine = fs.readFileSync(path.join(base, 'hebrew-search-engine.js'), 'utf8');
const parts = ['02-words.js', '03-helpers.js', '09-open-render.js', '10-typing.js', '11-filters.js', '12-search.js', '13-ask.js', '15-about.js'].map((f) => fs.readFileSync(path.join(drawer, f), 'utf8')).join('\n');

const stub = `
  var SHOPIFY = false, win = window, doc = document, CFG = { assistant: 'AI' }, LANG = 'en', DIR = 'ltr', API = '', SITE = '', INPUT = { value: '' };
  var FIELD = { classList: { toggle: function () {}, remove: function () {} } }, BODY = {}, FLOW = {}, D = {}, SHORT = null, LOOK = {}, PAGE = null, LEARN = {}, fresh = {};
  var SUG = { classList: { contains: function () { return false; } } }, root = { querySelectorAll: function () { return []; } };
  var S = { engine: window.LA_ENGINE, facets: [], records: {}, index: {}, config: {}, turns: [], saved: [], products: {}, live: null };
  function blankC() { return { f: {}, sort: '', stock: false, sale: false, no: {}, auto: {}, at: {}, words: {}, suggest: {}, picked: {} }; }
  function rec(id) { id = String(id); return S.records[id.indexOf(':') > 0 ? id : 'p:' + id]; }
  function pid(id) { return String(id).replace(/^p:/, ''); }
  function livePrice() { return null; } function regular() { return null; }
  function icon() { return ''; } function mark() { return ''; } function off() { return false; } function isPhone() { return false; }
  function render() {} function persist() {} function hours() { return {}; }
  window.LANG_SET = function (l) { LANG = l; };
  function isQuestion(q) { return !!(window.LA_ENGINE && window.LA_ENGINE.isQuestion && window.LA_ENGINE.isQuestion(String(q || ''))); }
`;
const ctx = { console, navigator: { language: 'en' }, location: { search: '', pathname: '/' } };
ctx.document = { documentElement: { lang: 'en' }, createElement: () => ({ set innerHTML(v) { this.value = String(v); } }) };
ctx.window = { document: ctx.document, navigator: ctx.navigator, location: ctx.location, addEventListener: () => {} };
ctx.window.window = ctx.window;
vm.createContext(ctx);
vm.runInContext(engine, ctx);
vm.runInContext('(function () {' + stub + parts + '\n;window.T = { S: S, C: C, filtered: filtered, wouldCounts: wouldCounts, facetChips: facetChips, niceLabel: niceLabel, plainlySays: plainlySays, sized: sized, handsOver: handsOver, noteHtml: noteHtml, setCounted: setCounted, resultsHtml: resultsHtml, toldOnScreen: toldOnScreen, conversationGoingOn: conversationGoingOn, money: money, priceLike: priceLike, capital: capital, similarLink: similarLink, activeRow: activeRow, peopleAsk: peopleAsk, miniRow: miniRow, unsaid: unsaid, t: t };\n})();', ctx);
ctx.T = ctx.window.T;
ctx.LANG_SET = ctx.window.LANG_SET;
process.stdout.write(JSON.stringify(vm.runInContext(expression, ctx)));
