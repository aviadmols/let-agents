/*!
 * Let Agents search engine: the typo-tolerant Hebrew search the storefront runs in the browser.
 *
 * The same algorithm as the server's Search/Support/HebrewSearch.php, in JavaScript: change both or
 * neither. HebrewSearchTest runs both on one fixture and requires identical ids in identical order.
 *
 * What it exports is pure: no page, no requests. In Node it is module.exports (the tests require
 * it); in a browser it is window.LA_ENGINE, unless a page already set one. lets-search.js embeds
 * this file as written (lets-search/build.js).
 *
 * This is the only browser copy of the engine.
 */
(function (root, factory) {
  'use strict';
  var engine = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = engine;
    return;
  }
  if (root && !root.LA_ENGINE) {
    root.LA_ENGINE = engine;
  }
}(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  // ---------------------------------------------------------------- engine (same as HebrewSearch.php)

  var HEBREW_WORD = /^[א-ת]+$/;
  var PREFIX_LETTER = /^[והבלמשכ]/; // ו ה ב ל מ ש כ
  var FINALS = { 'ך': 'כ', 'ם': 'מ', 'ן': 'נ', 'ף': 'פ', 'ץ': 'צ' };
  var ALIKE = { 'ט': 'ת', 'ק': 'כ', 'ח': 'כ', 'ע': 'א', 'ס': 'ש', 'ה': 'א' };
  var KEYBOARD = {
    e: 'ק', r: 'ר', t: 'א', y: 'ט', u: 'ו', i: 'נ', o: 'מ', p: 'פ',
    a: 'ש', s: 'ד', d: 'ג', f: 'כ', g: 'ע', h: 'י', j: 'ח', k: 'ל', l: 'כ', ';': 'פ',
    z: 'ז', x: 'ס', c: 'ב', v: 'ה', b: 'נ', n: 'מ', m: 'צ', ',': 'ת', '.': 'צ'
  };
  var FLOOR = 0.45;
  var RELATIVE_FLOOR = 0.8;

  function normalize(text) {
    return String(text == null ? '' : text)
      .toLowerCase()
      .replace(/[֑-ׇ]/g, '')
      .replace(/[׳״'"`]/g, '')
      .replace(/[ךםןףץ]/g, function (letter) { return FINALS[letter]; })
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim();
  }

  function stem(word) {
    if (!HEBREW_WORD.test(word) || word.length < 3) {
      return word;
    }
    var base = word.length > 4 ? word.replace(/(ימ|ות)$/, '') : word;
    // a noun and its construct form are one word ("מלחמה", "מלחמת"), and an ending written with א ("קופסא", "מקיטא")
    base = base.length >= 5 ? base.replace(/[התא]$/, '') : base;
    return base.charAt(0) + base.slice(1).replace(/[וי]/g, '');
  }

  function sound(word) {
    return stem(word).replace(/[טקחעסה]/g, function (letter) { return ALIKE[letter]; });
  }

  function addPieces(word, vector, order) {
    var padded = ' ' + word + ' ';
    for (var i = 0; i < padded.length - 2; i++) {
      var piece = padded.substr(i, 3);
      if (!Object.prototype.hasOwnProperty.call(vector, piece)) {
        vector[piece] = 0;
        order.push(piece);
      }
      vector[piece] += 1;
    }
  }

  /** @return {{pieces: string[], weights: Object}} pieces in insertion order, as PHP iterates them */
  function wordVector(word) {
    var weights = {};
    var pieces = [];
    var heard = sound(word);
    addPieces(word, weights, pieces);
    if (heard !== word) {
      addPieces('~' + heard, weights, pieces);
    }
    var length = 0;
    for (var i = 0; i < pieces.length; i++) {
      length += weights[pieces[i]] * weights[pieces[i]];
    }
    length = Math.sqrt(length) || 1;
    for (var j = 0; j < pieces.length; j++) {
      weights[pieces[j]] = weights[pieces[j]] / length;
    }
    return { pieces: pieces, weights: weights };
  }

  function fromKeyboard(query) {
    if (!/^[a-z;,. ]+$/.test(query) || !/[a-z]/.test(query)) {
      return '';
    }
    return query.replace(/[a-z;,.]/g, function (key) { return KEYBOARD[key] || key; });
  }

  /** items: [{ id, title, keywords }] */
  function build(items) {
    var records = [];
    var list = [];
    var holders = {};
    var postings = {};

    for (var at = 0; at < items.length; at++) {
      var item = items[at];
      var name = normalize(item.title);
      // [[phrase, weight], …]: each word of a tag, at the weight of the most central tag that holds it
      var tagged = {}, taggedOrder = [];
      (item.tags || []).forEach(function (pair) {
        normalize(pair[0]).split(' ').forEach(function (tw) {
          if (!tw) { return; }
          if (!Object.prototype.hasOwnProperty.call(tagged, tw)) { taggedOrder.push(tw); tagged[tw] = 0; }
          if (tagged[tw] < Number(pair[1])) { tagged[tw] = Number(pair[1]); }
        });
      });
      var hay = (normalize(item.title + ' ' + (item.keywords || '')) + ' ' + taggedOrder.join(' ')).trim();
      records.push({ id: String(item.id), title: String(item.title), name: name, named: ' ' + name + ' ', hay: hay, tagged: tagged });
      var words = hay.split(' ');
      for (var w = 0; w < words.length; w++) {
        var word = words[w];
        if (!word) {
          continue;
        }
        if (!Object.prototype.hasOwnProperty.call(holders, word)) {
          holders[word] = [];
          list.push(word);
        }
        var owners = holders[word];
        if (owners[owners.length - 1] !== at) {
          owners.push(at);
        }
      }
    }

    for (var n = 0; n < list.length; n++) {
      var vector = wordVector(list[n]);
      for (var p = 0; p < vector.pieces.length; p++) {
        var piece = vector.pieces[p];
        if (!Object.prototype.hasOwnProperty.call(postings, piece)) {
          postings[piece] = [];
        }
        postings[piece].push(n, vector.weights[piece]);
      }
    }

    return { items: records, list: list, holders: holders, postings: postings };
  }

  /** @return {{words: string[], score: Object}} close catalogue words in the order PHP keeps them */
  var NUMBER_WORDS = { 'אחד': '1', 'אחת': '1', 'שניים': '2', 'שתיים': '2', 'שני': '2', 'שתי': '2', 'שלוש': '3', 'שלושה': '3', 'ארבע': '4', 'ארבעה': '4', 'חמש': '5', 'חמישה': '5', 'שש': '6', 'שישה': '6', 'שבע': '7', 'שבעה': '7', 'שמונה': '8', 'תשע': '9', 'תשעה': '9', 'עשר': '10', 'עשרה': '10', 'שתיםעשרה': '12' };
  function closeWords(index, typed) {
    var found = {};
    var order = [];
    var variants = [typed];
    if (PREFIX_LETTER.test(typed) && typed.length > 3) {
      variants.push(typed.slice(1));
    }

    for (var position = 0; position < variants.length; position++) {
      var variant = variants[position];
      var scores = {};
      var base = stem(variant);
      var length = variant.length;
      var vector = wordVector(variant);
      var colour = colorOf(variant);
      // a number is never a typo of another: "53077" is not "53076", "54" is not "45"
      var digits = variant.replace(/\D+/g, '');

      for (var p = 0; p < vector.pieces.length; p++) {
        var piece = vector.pieces[p];
        var postings = index.postings[piece];
        if (!postings) {
          continue;
        }
        for (var k = 0; k < postings.length; k += 2) {
          scores[postings[k]] = (scores[postings[k]] || 0) + vector.weights[piece] * postings[k + 1];
        }
      }

      for (var w = 0; w < index.list.length; w++) {
        var candidate = index.list[w];
        var score = scores[w] || 0;
        // "שבע" is "7" and "7" is "שבע": a number as a word or as digits (the server's NUMBER_WORDS)
        if (NUMBER_WORDS[variant] === candidate || NUMBER_WORDS[candidate] === variant) {
          var nscore = position ? 0.9 : 0.95;
          if ((found[candidate] || 0) < nscore) { if (!Object.prototype.hasOwnProperty.call(found, candidate)) { order.push(candidate); } found[candidate] = nscore; }
          continue;
        }
        if (digits !== '' && candidate !== variant && candidate.indexOf(variant) !== 0 && String(candidate).replace(/\D+/g, '') !== digits) {
          continue;
        }
        if (candidate === variant) {
          score = 1;
        // "מק" while typing is "מקדחה"; "לב", a word the catalogue has, is a heart, never "לבן"
        } else if (length >= 2 && candidate.indexOf(variant) === 0 && (length >= 3 || !Object.prototype.hasOwnProperty.call(index.holders, variant))) {
          score = Math.max(score, 0.9 - 0.02 * (candidate.length - length));
        } else if (length >= 3 && stem(candidate) === base) {
          score = Math.max(score, 0.85);
        } else if (candidate.length > 3 && PREFIX_LETTER.test(candidate) && candidate.slice(1) === variant) {
          score = Math.max(score, 0.85);
        }
        if (colour !== null && colorOf(candidate) === colour) {
          score = Math.max(score, 0.95);
        }
        if (position) {
          score *= 0.95;
        }
        if (score >= FLOOR && (found[candidate] || 0) < score) {
          if (!Object.prototype.hasOwnProperty.call(found, candidate)) {
            order.push(candidate);
          }
          found[candidate] = score;
        }
      }
    }

    var best = 0;
    for (var i = 0; i < order.length; i++) {
      best = Math.max(best, found[order[i]]);
    }
    var floor = order.length ? Math.max(FLOOR, best * RELATIVE_FLOOR) : FLOOR;
    var kept = [];
    for (var j = 0; j < order.length; j++) {
      if (found[order[j]] >= floor) {
        kept.push(order[j]);
      }
    }
    return { words: kept, score: found };
  }

  // a word of three letters or more anywhere in the words; a shorter one only as a word of its own, maybe after a
  // prefix letter ("לב", "בלב", never "לבן"). The server's holdsToken.
  function holdsToken(hay, token) {
    if (token.length >= 3) { return hay.indexOf(token) !== -1; }
    return new RegExp('(?:^| )[והבלמשכ]?' + token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?: |$)').test(hay);
  }

  function exactTier(record, query, tokens) {
    var i;
    for (i = 0; i < tokens.length; i++) {
      if (!holdsToken(record.hay, tokens[i])) {
        return -1;
      }
    }
    // the query as whole words before the query inside a longer word: "הרבי" finds "…של הרבי מלובביץ׳"
    // before "הרביעייה הפיננסית"
    if (record.name === query) {
      return 0;
    }
    if ((record.name + ' ').indexOf(query + ' ') === 0) {
      return 1;
    }
    if ((' ' + record.name + ' ').indexOf(' ' + query + ' ') !== -1) {
      return 2;
    }
    if (record.name.indexOf(query) === 0) {
      return 3;
    }
    if ((' ' + record.name).indexOf(' ' + query) !== -1) {
      return 4;
    }
    if (record.name.indexOf(query) !== -1) {
      return 5;
    }
    for (i = 0; i < tokens.length; i++) {
      if ((' ' + record.name).indexOf(' ' + tokens[i]) === -1) {
        return 7;
      }
    }
    return 6;
  }

  // Words that are not asked of a record for it to hold the whole query (the server's FILLER list).
  var FILLER = ['איך', 'כמה', 'האם', 'למה', 'מדוע', 'מה', 'מהו', 'מהי', 'מהמ', 'איפה', 'היכנ', 'מתי', 'מי', 'איזה', 'איזו', 'אילו',
    'אפשר', 'ניתנ', 'יש', 'צריכ', 'כדאי', 'מותר', 'מתאימ', 'מתאימה', 'מתאימימ', 'הכי', 'לי', 'לנו', 'עמ', 'של', 'את', 'על', 'ליד', 'זה', 'זו',
    'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גמ', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my',
    'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should'];

  // A colour is one word whatever its gender and number ("שחורה" is "שחור"): the server's COLORS.
  var COLOR_OF = {};
  [['שחור', 'שחורה', 'שחורימ', 'שחורות'], ['לבנ', 'לבנה', 'לבנימ', 'לבנות'], ['אדומ', 'אדומה', 'אדומימ', 'אדומות'], ['כחול', 'כחולה', 'כחולימ', 'כחולות'], ['ירוק', 'ירוקה', 'ירוקימ', 'ירוקות'], ['צהוב', 'צהובה', 'צהובימ', 'צהובות'], ['אפור', 'אפורה', 'אפורימ', 'אפורות'], ['כתומ', 'כתומה', 'כתומימ', 'כתומות'], ['סגול', 'סגולה', 'סגולימ', 'סגולות'], ['ורוד', 'ורודה', 'ורודימ', 'ורודות'], ['כסופ', 'כסופה', 'כסופימ', 'כסופות'], ['זהוב', 'זהובה', 'זהובימ', 'זהובות'], ['שקופ', 'שקופה', 'שקופימ', 'שקופות']].forEach(function (forms) {
    forms.forEach(function (form) { COLOR_OF[form] = forms[0]; });
  });

  function colorOf(word) {
    return Object.prototype.hasOwnProperty.call(COLOR_OF, word) ? COLOR_OF[word] : null;
  }

  // Words that say what kind of word follows ("בצבע שחור"), normalized: the server's DESCRIBES.

  var DESCRIBES = ['בצבע', 'בגוונ', 'בגימור'];

  // Words that only say "things" ("כלים לגינה" is about the garden), normalized: the server's GENERIC.
  var GENERIC = ['כלימ', 'כלי', 'ציוד', 'מוצרימ', 'מוצר', 'אביזרימ', 'אביזר', 'פריטימ', 'פריט', 'דברימ', 'חפצימ', 'משהו', 'משהי', 'things', 'products', 'items', 'stuff', 'something', 'anything'];

  function rank(index, query) {
    var tokens = query.split(' ');
    var score = {};
    var hits = {};
    var found = [];
    // A generic word is neither counted nor asked of a record; with no other word, it is the query.
    var generic = {};
    var genericCount = 0;
    for (var g = 0; g < tokens.length; g++) {
      if (GENERIC.indexOf(tokens[g]) !== -1) {
        generic[g] = true;
        genericCount++;
      }
    }
    if (genericCount === tokens.length) {
      generic = {};
    }
    // A word that only says what follows is a colour or a finish ("בצבע שחור") is not counted.
    // a filler word ("על", "של", "עם") is no hit either (the server's rank): with no other word, the fillers are the query
    var filler = {};
    tokens.forEach(function (token, at) { if (FILLER.indexOf(token) !== -1) { filler[at] = true; } });
    if (Object.keys(filler).length === tokens.length) { filler = {}; }
    var counted = tokens.filter(function (token, at) { return DESCRIBES.indexOf(token) === -1 && !generic[at] && !filler[at]; }).length || tokens.length;
    var needed = counted <= 2 ? counted : counted - 1;
    // The words that must all be there for a record to hold the whole query ("בצבע" is not one).
    var required = [];
    for (var q = 0; q < tokens.length; q++) {
      if (FILLER.indexOf(tokens[q]) === -1 && DESCRIBES.indexOf(tokens[q]) === -1 && !generic[q]) {
        required.push(q);
      }
    }
    if (!required.length) {
      for (var q2 = 0; q2 < tokens.length; q2++) {
        required.push(q2);
      }
    }
    var matched = {};

    var bests = [];
    for (var t0 = 0; t0 < tokens.length; t0++) {
      var close = closeWords(index, tokens[t0]);
      var best = {};
      var bestOrder = [];
      for (var c = 0; c < close.words.length; c++) {
        var word = close.words[c];
        var owners = index.holders[word] || [];
        for (var o = 0; o < owners.length; o++) {
          var at = owners[o];
          var value = close.score[word] * fieldWeight(index.items[at], word);
          if (!Object.prototype.hasOwnProperty.call(best, at)) {
            bestOrder.push(at);
            best[at] = 0;
          }
          if (best[at] < value) {
            best[at] = value;
          }
        }
      }
      bests.push({ best: best, order: bestOrder });
    }
    // a rare word counts more than a common one, as a share of the search's average (the server's rarity)
    var raw = {}, rawSum = 0, rawN = 0;
    for (var t1 = 0; t1 < tokens.length; t1++) {
      if (!generic[t1] && !filler[t1] && bests[t1].order.length) {
        raw[t1] = Math.log((index.items.length + 1) / (bests[t1].order.length + 1)) + 1;
        rawSum += raw[t1]; rawN++;
      }
    }
    var rawMean = rawN ? rawSum / rawN : 1;
    for (var t = 0; t < tokens.length; t++) {
      var best = bests[t].best, bestOrder = bests[t].order;
      var rare = Object.prototype.hasOwnProperty.call(raw, t) ? raw[t] / rawMean : 1;
      for (var b = 0; b < bestOrder.length; b++) {
        // A generic word adds a little to the score of what holds it (the server's GENERIC_WEIGHT).
        score[bestOrder[b]] = (score[bestOrder[b]] || 0) + best[bestOrder[b]] * (generic[t] || filler[t] ? 0.2 : rare);
        // ...and never to what is found.
        hits[bestOrder[b]] = (hits[bestOrder[b]] || 0) + (generic[t] || filler[t] ? 0 : 1);
        if (!matched[bestOrder[b]]) {
          matched[bestOrder[b]] = {};
        }
        matched[bestOrder[b]][t] = true;
      }
    }

    for (var r = 0; r < index.items.length; r++) {
      var record = index.items[r];
      var tier = exactTier(record, query, tokens);
      if (tier < 0 && (hits[r] || 0) < needed) {
        continue;
      }
      var full = tier >= 0;
      if (!full) {
        full = true;
        for (var k = 0; k < required.length; k++) {
          if (!matched[r] || !matched[r][required[k]]) {
            full = false;
            break;
          }
        }
      }
      found.push({ id: record.id, title: record.title, score: (tier >= 0 ? 10 - tier : 0) + (score[r] || 0) / tokens.length, exact: tier >= 0, full: full });
    }

    // What holds the whole query before what holds part of it; then by score.
    found.sort(function (a, b) {
      return ((b.full ? 1 : 0) - (a.full ? 1 : 0)) || (b.score - a.score) || (a.title.length - b.title.length);
    });
    return found;
  }

  // A word in the name counts 1, one of its keywords 0.7, one of its tags between the two by how central the
  // tag is (the server's KEYWORD_WEIGHT and TAG_WEIGHT).
  function fieldWeight(record, word) {
    if (record.named.indexOf(' ' + word + ' ') !== -1) { return 1; }
    var tag = record.tagged && Object.prototype.hasOwnProperty.call(record.tagged, word) ? record.tagged[word] : 0;
    return tag > 0 ? 0.7 + 0.3 * Math.min(1, tag) : 0.7;
  }

  function search(index, raw) {
    var query = normalize(raw);
    if (!query) {
      return [];
    }
    var found = rank(index, query);
    var typed = fromKeyboard(query);
    if (typed) {
      var hebrew = rank(index, typed);
      if (hebrew.length && (!found.length || hebrew[0].score > found[0].score)) {
        found = hebrew;
      }
    }
    return found;
  }

  /** Whether a query holds a question or filler word (the server's HebrewSearch::asks). */
  function asks(raw) {
    return normalize(raw).split(' ').some(function (word) { return FILLER.indexOf(word) !== -1; });
  }

  /** A product code as compared: "DDF-485", "ddf 485" and "DDF485" are one code. */
  function code(text) {
    return normalize(text).replace(/ /g, '');
  }

  /** The products by their code (SKU): code -> ids. Codes under three characters are left out. */
  function codes(items) {
    var found = {};
    for (var i = 0; i < items.length; i++) {
      var key = items[i].t === 'product' ? code(items[i].sku || '') : '';
      if (key.length >= 3) {
        (found[key] = found[key] || []).push(String(items[i].id));
      }
    }
    return found;
  }

  /**
   * The one product a search is plainly for, or null: the product whose code (SKU) is what was
   * typed, or the only product that holds every word. Same rule as HebrewSearch::single().
   */
  function single(byCode, raw, hits) {
    var key = code(raw);
    if (!key) {
      return null;
    }
    if (Object.prototype.hasOwnProperty.call(byCode, key) && byCode[key].length === 1) {
      return { id: byCode[key][0], by: 'sku' };
    }
    var whole = hits.filter(function (hit) { return hit.full && String(hit.id).indexOf('p:') === 0; });
    return whole.length === 1 ? { id: whole[0].id, by: 'words' } : null;
  }

  /** How many products hold every word of a query. */
  function wholeCount(index, query) {
    return search(index, query).filter(function (hit) { return hit.full && String(hit.id).indexOf('p:') === 0; }).length;
  }

  /**
   * Narrower searches to offer when no product holds every word ("מקדחה בצבע שחור"): the query
   * without one of its words at a time, each with how many products hold all of it. Same as
   * HebrewSearch::focus(); change both.
   */
  function focus(index, raw, max) {
    var words = String(raw || '').trim().split(/\s+/).filter(Boolean);
    var norm = words.map(normalize);
    var content = [];
    for (var c = 0; c < norm.length; c++) {
      if (norm[c] !== '' && FILLER.indexOf(norm[c]) === -1 && DESCRIBES.indexOf(norm[c]) === -1) {
        content.push(c);
      }
    }
    if (content.length < 2 || wholeCount(index, String(raw || '')) > 0) {
      return [];
    }
    var out = [];
    for (var d = content.length - 1; d >= 0; d--) {
      var drop = content[d];
      var keep = [];
      for (var i = 0; i < words.length; i++) {
        if (i !== drop && !(i === drop - 1 && DESCRIBES.indexOf(norm[i]) !== -1) && norm[i] !== '') {
          keep.push(words[i]);
        }
      }
      var query = keep.join(' ');
      var count = wholeCount(index, query);
      if (count > 0 && !out.some(function (option) { return option.query === query; })) {
        out.push({ query: query, count: count });
      }
    }
    return out.slice(0, max || 3);
  }

  // ---------------------------------------------------------------- storefront

  /**
   * A question rather than words to look up: it ends with a question mark, or it is two words or
   * more and opens like a question. Code only; nothing is asked while the shopper types.
   */
  var QUESTION_WORDS = ['איך', 'כמה', 'האם', 'למה', 'מדוע', 'מה', 'מהו', 'מהי', 'מהם', 'איפה', 'היכן', 'מתי', 'מי', 'איזה', 'איזו', 'אילו',
    'אפשר', 'ניתן', 'יש', 'צריך', 'כדאי', 'מותר', 'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should'];

  function isQuestion(raw) {
    var text = String(raw || '').trim();
    if (/[?？]\s*$/.test(text)) {
      return text.replace(/[?？\s]/g, '').length >= 3;
    }
    var words = text.toLowerCase().split(/\s+/).filter(Boolean);
    return words.length >= 2 && QUESTION_WORDS.indexOf(words[0]) !== -1;
  }

  /** Words a question wraps around what it is about, for telling one saved question from another. */
  var ANSWER_FILLER = ['כדאי', 'צריך', 'מתאים', 'מתאימה', 'מתאימים', 'הכי', 'לי', 'לנו', 'עם', 'של', 'את', 'על', 'ליד', 'זה', 'זו', 'יש', 'אפשר',
    'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גם', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my'];
  var answerDrop = null;

  /** The words a text is about: no question or filler words, each by its stem. */
  function coreWords(text) {
    if (!answerDrop) {
      answerDrop = {};
      QUESTION_WORDS.concat(ANSWER_FILLER).forEach(function (word) { answerDrop[normalize(word)] = true; });
    }
    return normalize(text).split(' ').filter(function (word) { return word && !answerDrop[word]; }).map(stem);
  }

  /**
   * Whether a saved question is the one being typed, closely enough to show its answer: every
   * word typed is in it (a short last word still being typed waits), at least one of them has
   * three letters or more, and they cover most of what the saved question is about. A loose
   * match never shows an answer to another question.
   */
  function closeAnswer(raw, savedQuestion) {
    var typed = coreWords(raw);
    if (typed.length && typed[typed.length - 1].length < 3 && !/\s$/.test(String(raw))) {
      typed.pop();
    }
    typed = typed.filter(function (word) { return word.length >= 2; });
    var saved = coreWords(savedQuestion);
    if (!typed.length || !saved.length || !typed.some(function (word) { return word.length >= 3; })) {
      return false;
    }
    var inside = function (list, word) {
      return list.some(function (other) {
        return other === word || (word.length >= 3 && other.indexOf(word) === 0) || (other.length >= 3 && word.indexOf(other) === 0);
      });
    };
    var typedFound = typed.filter(function (word) { return inside(saved, word); }).length;
    var savedFound = saved.filter(function (word) { return inside(typed, word); }).length;
    return typedFound === typed.length && savedFound / saved.length >= 0.6;
  }

  // ---------------------------------------------------------------- query actions (same as HebrewSearch.php)

  /**
   * The phrases of a query that are actions, not words (the index's "actions", the same in every
   * shop): "טבעת יהלום הכי זולה במבצע" is "טבעת יהלום", sorted by price, on sale only. As on the
   * server (HebrewSearch::actions).
   */
  function readActions(raw, registry) {
    var text = ' ' + String(raw == null ? '' : raw).toLowerCase().replace(/[.,!?;:]+(?=\s|$)/g, ' ') + ' ';
    var found = { ids: [], sort: null, dir: null, sale: false, stock: false, near: null, within: null, min: null, max: null, band: null };
    (registry || []).forEach(function (entry) {
      var patterns = entry.patterns || [];
      for (var i = 0; i < patterns.length; i++) {
        var re;
        try {
          re = new RegExp('(^|\\s)(?:' + patterns[i] + ')(?=\\s|$)', 'iu');
        } catch (e) {
          continue; // a pattern this browser cannot read is skipped, never an error
        }
        var m = text.match(re);
        if (!m) {
          continue;
        }
        var at = text.indexOf(m[0]);
        text = text.slice(0, at) + m[1] + ' ' + text.slice(at + m[0].length);
        var act = entry['do'] || {};
        if (act.sort && found.sort === null) {
          found.sort = String(act.sort);
          found.dir = String(act.dir || 'asc');
        }
        if (act.filter === 'sale') {
          found.sale = true;
        }
        if (act.filter === 'stock') {
          found.stock = true;
        }
        if ((act.band === 'top' || act.band === 'bottom') && found.band === null) {
          found.band = act.band;
        }
        ['near', 'min', 'max'].forEach(function (bound) {
          if (act[bound] && m[2] !== undefined) {
            found[bound] = parseFloat(String(m[2]).replace(',', '.'));
            // a size one wears ("מידה 54") is that size, give or take a step; a measure ("בגודל 30") is about it
            if (bound === 'near' && typeof act.within === 'number') {
              found.within = act.within;
            }
          }
        });
        found.ids.push(String(entry.id));
        break;
      }
    });
    return { query: text.replace(/\s+/g, ' ').trim(), actions: found };
  }

  /**
   * Products as the actions ask: on sale or in stock only, near a size, sorted by price, size or
   * newness (what has the value first, in stock first, then the rest in their order). As on the
   * server (HebrewSearch::applyActions).
   */
  function applyActions(records, actions) {
    var out = records.slice();
    if (actions.sale) {
      out = out.filter(function (r) { return r.sl === 1; });
    }
    if (actions.stock) {
      out = out.filter(function (r) { return r.s === 1; });
    }
    if (actions.near !== null && out.some(function (r) { return typeof r.z === 'number'; })) {
      var near = actions.near;
      var within = typeof actions.within === 'number' ? actions.within : Math.max(near * 0.15, 0.0001);
      out = out.filter(function (r) { return typeof r.z === 'number' && Math.abs(r.z - near) <= within; });
    }
    // "מידה גדולה": the third of the sizes found that is largest (or smallest), among what has a size
    if ((actions.band === 'top' || actions.band === 'bottom') && out.some(function (r) { return typeof r.z === 'number'; })) {
      var cut = band(out.map(function (r) { return r.z; }), actions.band);
      out = out.filter(function (r) { return typeof r.z === 'number' && (actions.band === 'top' ? r.z >= cut : r.z <= cut); });
    }
    [['min', 1], ['max', -1]].forEach(function (pair) {
      var limit = actions[pair[0]];
      if (limit !== null && limit !== undefined && out.some(function (r) { return typeof r.z === 'number'; })) {
        out = out.filter(function (r) { return typeof r.z === 'number' && pair[1] * (r.z - limit) >= 0; });
      }
    });
    var field = { price: 'pr', size: 'z', 'new': 'd' }[actions.sort || ''];
    if (!field) {
      return out;
    }
    var sign = actions.dir === 'desc' ? -1 : 1;
    var at = {};
    out.forEach(function (r, i) { at[r.id] = i; });
    return out.sort(function (a, b) {
      var hasA = typeof a[field] === 'number' ? 1 : 0;
      var hasB = typeof b[field] === 'number' ? 1 : 0;
      var inA = a.s === 1 ? 1 : 0;
      var inB = b.s === 1 ? 1 : 0;
      if (hasA !== hasB) {
        return hasB - hasA;
      }
      if (inA !== inB) {
        return inB - inA;
      }
      if (hasA && hasB && a[field] !== b[field]) {
        return sign * (a[field] < b[field] ? -1 : 1);
      }
      return at[a.id] - at[b.id];
    });
  }

  /** Where the largest (top) or smallest (bottom) third of these sizes begins. As on the server (HebrewSearch::band). */
  function band(values, side) {
    var sizes = values.filter(function (v) { return typeof v === 'number'; }).sort(function (a, b) { return a - b; });
    if (!sizes.length) {
      return side === 'top' ? -Infinity : Infinity;
    }
    var third = Math.max(1, Math.round(sizes.length / 3));
    return side === 'top' ? sizes[sizes.length - third] : sizes[third - 1];
  }

  /** "80 על 150", "80X150", "80×150": one size, written one way. */
  function facetText(text) {
    return normalize(String(text).replace(/(\d)\s*(?:[x×X*✕]|על|on)\s*(\d)/g, '$1x$2'));
  }

  /**
   * The shop's filter values the words name ("כחול", "כחולה", "בכחול", "80x150", "זהב צהוב"):
   * those filters are chosen for this query, as if pressed, and shown at the top with their ×.
   * In code, from last night's index; the longest name wins ("זהב צהוב" before "זהב"). Not the
   * product's type: "שטיח" is what the words search for, not a filter on it.
   */
  function queryFacets(raw, facets) {
    var text = ' ' + facetText(raw) + ' ';
    var found = [];
    (facets || []).forEach(function (facet, at) {
      if (facet.k === 'type') {
        return;
      }
      (facet.v || []).forEach(function (value, i) {
        var name = facetText(value.l);
        var quoted = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        // "חולצה מידה M", "טבעת במידה 52": a value after the word for size is that size, however short
        var sized = name !== '' ? text.match(new RegExp('\\s((?:[ובה]?מיד(?:ה|ת)|size)\\s?:?\\s?' + quoted + ')(?=\\s)')) : null;
        if (sized) {
          found.push({ code: at * 1000 + i, said: sized[1], label: value.l, title: facet.l });
          return;
        }
        // Too short to be meant ("S", "M"), unless it is a size with numbers.
        if (name.length < 3 && !/\d/.test(name)) {
          return;
        }
        var hebrew = /[\u0590-\u05FF]$/.test(name);
        var pattern = new RegExp('\\s[ובהלמ]?(' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + (hebrew ? '(?:ה|ימ|ות)?' : '') + ')(?=\\s)');
        var m = text.match(pattern);
        if (m) {
          found.push({ code: at * 1000 + i, said: m[1], label: value.l, title: facet.l });
        }
      });
    });
    found.sort(function (a, b) { return b.said.length - a.said.length || a.code - b.code; });
    var taken = [];
    return found.filter(function (named) {
      if (taken.some(function (word) { return word.indexOf(named.said) !== -1; })) {
        return false;
      }
      taken.push(named.said);
      return true;
    });
  }

  /** The words as typed, less the ones that read as $said ("80 על 150" for "80x150"); null when not found. */
  function withoutSaid(raw, said) {
    var tokens = String(raw).split(/\s+/);
    for (var size = 1; size <= 4; size++) {
      for (var i = 0; i + size <= tokens.length; i++) {
        var piece = facetText(tokens.slice(i, i + size).join(' '));
        if (piece === said || piece.replace(/^[ובהלמ]/, '') === said) {
          return tokens.slice(0, i).concat(tokens.slice(i + size)).join(' ').replace(/\s+/g, ' ').trim();
        }
      }
    }
    return null;
  }


  /**
   * "טבעת או עגילים", "או טבעת או עגילים": each side searched on its own, its results added to the
   * other's. One element when the query names no "או". As on the server (HebrewSearch::either).
   */
  function either(raw) {
    var text = String(raw == null ? '' : raw).trim().replace(/^או\s+/u, '').trim();
    var sides = text.split(/\s+או\s+/u).map(function (side) { return side.trim(); }).filter(function (side) { return side !== ''; });
    return sides.length > 1 ? sides : [raw];
  }

  // ---------------------------------------------------------------- Shopify helpers (pure, tested in node)

  /**
   * The product handle in a Shopify product link: /products/{handle}, also under a market or
   * language prefix (/en/products/...) or a collection (/collections/x/products/...).
   */
  function handleFromUrl(url) {
    var match = /\/products\/([^\/?#]+)/.exec(String(url || ''));
    if (!match) {
      return null;
    }
    try {
      return decodeURIComponent(match[1]).replace(/\.(js|json)$/, '') || null;
    } catch (e) {
      return null;
    }
  }

  // Shopify's money placeholders: decimals, thousands separator, decimal separator.
  var MONEY_STYLES = {
    amount: [2, ',', '.'],
    amount_no_decimals: [0, ',', '.'],
    amount_with_comma_separator: [2, '.', ','],
    amount_no_decimals_with_comma_separator: [0, '.', ','],
    amount_with_apostrophe_separator: [2, "'", '.'],
    amount_no_decimals_with_space_separator: [0, ' ', '.'],
    amount_with_space_separator: [2, ' ', ','],
    amount_with_period_and_space_separator: [2, ' ', '.']
  };

  /**
   * A price in cents, the way the shop writes prices: its own money format ("₪{{amount}}",
   * "{{amount_with_comma_separator}} €"), or, without one, the browser's currency format.
   * The format may carry HTML (a <span class="money">); only its text is used.
   */
  function formatMoney(cents, format, currency, locale) {
    var value = Number(cents);
    if (cents === null || cents === undefined || cents === '' || !isFinite(value)) {
      return '';
    }
    var pattern = String(format || '')
      .replace(/<[^>]*>/g, '')
      .replace(/&#(\d+);/g, function (all, code) { return String.fromCharCode(Number(code)); })
      .replace(/&nbsp;/g, ' ')
      .replace(/&amp;/g, '&');
    var placeholder = /\{\{\s*(\w+)\s*\}\}/.exec(pattern);
    if (!placeholder) {
      try {
        return new Intl.NumberFormat(locale || undefined, { style: 'currency', currency: currency }).format(value / 100);
      } catch (e) {
        return (value / 100).toFixed(2);
      }
    }
    var style = MONEY_STYLES[placeholder[1]] || MONEY_STYLES.amount;
    var parts = (value / 100).toFixed(style[0]).split('.');
    var text = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, style[1]) + (parts[1] ? style[2] + parts[1] : '');
    return pattern.replace(placeholder[0], function () { return text; });
  }

  /**
   * A Shopify product (the JSON of /products/{handle}.js) in the shape the painting code knows from
   * the WooCommerce Store API. The variant shown and bought is the first one in stock, or, when
   * none is, the cheapest. Prices are in cents of the currency the shopper browses in.
   */
  function fromShopifyProduct(product) {
    var variants = (product && product.variants) || [];
    if (!variants.length) {
      return null;
    }
    var chosen = null;
    for (var i = 0; i < variants.length && !chosen; i++) {
      if (variants[i].available) {
        chosen = variants[i];
      }
    }
    if (!chosen) {
      chosen = variants.slice().sort(function (a, b) { return Number(a.price) - Number(b.price); })[0];
    }
    var price = Number(chosen.price);
    var compare = chosen.compare_at_price == null ? null : Number(chosen.compare_at_price);
    var sale = compare !== null && compare > price;
    // The sizes some variant has in stock, so "similar items in size 41" keeps only those that have it.
    var sizeAt = sizeOptionIndex(product.options);
    var sizes = [];
    for (var s = 0; sizeAt >= 0 && s < variants.length; s++) {
      var value = variants[s].available ? sizeValue(variants[s]['option' + (sizeAt + 1)]) : '';
      if (value && sizes.indexOf(value) === -1) {
        sizes.push(value);
      }
    }
    var summary = {
      is_in_stock: !!chosen.available,
      on_sale: sale,
      prices: { price: price, regular_price: sale ? compare : price, currency_minor_unit: 2 },
      variant: chosen.id,
      variants: variants.length
    };
    if (sizeAt >= 0) {
      summary.sizes = sizes;
    }
    return summary;
  }

  /** Which of a product's options is its size, by name (Shopify gives names, or objects with one). -1 when none. */
  function sizeOptionIndex(options) {
    for (var i = 0; options && i < options.length; i++) {
      var name = typeof options[i] === 'string' ? options[i] : (options[i] && options[i].name) || '';
      if (/מידה|מידות|גודל|size/i.test(name)) {
        return i;
      }
    }
    return -1;
  }

  /** A size as compared: "41", " 41 " and "41" are one size; "M" and "m" too. */
  function sizeValue(value) {
    return value == null ? '' : String(value).replace(/\s+/g, ' ').trim().toLowerCase();
  }

  return {
    normalize: normalize,
    stem: stem,
    sound: sound,
    fromKeyboard: fromKeyboard,
    build: build,
    closeWords: closeWords,
    search: search,
    isQuestion: isQuestion,
    codes: codes,
    single: single,
    focus: focus,
    closeAnswer: closeAnswer,
    readActions: readActions,
    queryFacets: queryFacets,
    withoutSaid: withoutSaid,
    either: either,
    applyActions: applyActions,
    band: band,
    handleFromUrl: handleFromUrl,
    formatMoney: formatMoney,
    fromShopifyProduct: fromShopifyProduct
  };
}));
