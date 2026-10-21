/*!
 * Lets Search — the drawer (studio build).
 *
 * One script tag. It finds the store's search field and header, adds the smart-magnifier launcher,
 * and runs every part of the search inside one drawer: instant suggestions, results with filters,
 * answers with reasoned picks, photo search, similar items, "about this product", saved items and
 * compare, the cart, and the way to the store's team when there's no answer.
 *
 * Talks to the same endpoints as the product scripts (Let Agents API + the store's Store API).
 * Config: window.LetsSearchConfig (see DEFAULTS). Demo switches by URL: ?lsTheme=store, ?lsLang=en,
 * ?lsLauncher=float, ?lsOpen=1, ?lsPreview=1, ?lsIcon=mark|spark|…, ?lsNudge=1 (the invitation on every page).
 */
(function (win, doc) {
  'use strict';
  if (win.LetsSearch) { return; }

