# Contract for redesigning the Lets merchant panel

You are helping a designer restyle the merchant panel of Lets, the panel a shop owner uses. The work happens on a
live shop with real data. The designer returns files, and an engineer ports them into the system. This file states
what you may change and what you must keep, so that every screen still works after the port.

## The quickest way: the local dashboard (no server, no link)

`examples/index.html` opens a local copy of Sela Meir's panel. Open it straight from the folder (file://).

- It holds all 39 screens and every tab of each, captured from the live panel with their real content.
- The sidebar, the screens' links and the tabs work.
- Buttons that act (approve, save, delete) do nothing here, and nothing reaches the live shop.
- Ticking "לעצב לפי merchant.css שבתיקייה" in the bottom bar styles every screen with this folder's `merchant.css`
  instead of the captured one. Edit `merchant.css`, save, refresh.
- `examples/sela/*.html` are the raw captures. `examples/sela/*.png` are their pictures. `examples/gallery.html` shows
  them side by side.
- Restyle against this first. Use the live panel below to check a state that only the live panel shows: something that
  updates while you watch, or what an action leaves behind.

## Setting it up live (do this when asked to set up, run or "deploy" the kit against the live panel)

Nothing is installed or deployed to a server. The panel runs on Lets' server. This kit's folder is served from the
designer's computer, and the panel loads `merchant.css` from there.

1. **The link.** The designer received a design link from Lets: `https://agents.lets.co.il/design/<token>`.
   - It works for 24 hours, for one shop.
   - Opening it signs the browser into that shop's real panel. A black "מצב עיצוב" (design mode) bar shows at the
     foot of every screen.
   - Without a link, or with an expired one, ask the designer to get a new one from Lets. You cannot make one.
2. **Serve this folder** on port 5500, from the folder this file is in:
   - `node serve.mjs` (Node 18+, no packages; serves with no caching and the header Chrome needs for local network
     access);
   - otherwise `python -m http.server 5500`;
   - check: `curl -I http://localhost:5500/merchant.css` answers `200`.
   - Leave it running in the background while the designer works.
3. **Load it in the panel.** In the design bar, paste `http://localhost:5500/merchant.css` and press "לטעון" (Load).
   - Chrome may ask whether the site may access devices on the local network. The designer must allow it.
   - The bar then shows the file loaded ("העיצוב נטען מהמחשב שלך"), or that it did not load.
   - Only `http(s)://localhost` or `127.0.0.1` addresses ending in `.css` are accepted.
4. **Work.** Edit `merchant.css`, save, and refresh the panel (F5): the change shows on the shop's real screens.
   - "חזרה לעיצוב של האתר" (back to the site's look) switches back to the live stylesheet.
   - "שמירת המסך כקובץ HTML" saves the open screen, with its real content, as a file to propose structure in.
5. **If it does not load:**
   - the server is not running, or the port differs from the address in the bar;
   - local network access was refused: allow it in the site's settings in Chrome, by the address bar;
   - the file path is not `merchant.css` at the folder's root.
6. **Hand back** as described at the end of this file. No git, no deploy: the engineer at Lets ports and ships.

## How the panel is built

- The panel is rendered on the server: Laravel + Filament 5 + Livewire 3 + Alpine.js. There is no SPA, no REST API
  for the screens, and no asset build.
- Every page loads three stylesheets, in order: Filament's own, `reference/panel.css` (shared with the operator
  panel; do not edit), then `merchant.css` (the one you edit).
- Everything in `merchant.css` is scoped under `.fi-panel-merchant`, the body class Filament gives this panel.
- The look is driven by tokens at the top of `merchant.css`:
  - `--la-*` for ground, paper, ink, muted, lines, radii and shadows;
  - `--lp-lime`, `--lp-coral`, `--lp-sun` for status.
  Change tokens before changing rules: `panel.css` reads the same `--la-*` tokens, so Filament's own components
  follow.
- The screens' own markup uses `lp-*` classes (`lp-page`, `lp-card`, `lp-row`, `lp-pill`, `lp-btn`, `lp-tabs`,
  `lp-chip`, …). Filament's chrome uses `fi-*` classes.
- Hebrew is the main language. Every screen must work RTL and LTR. Use logical properties (`inset-inline`,
  `margin-inline-start`, `padding-inline-end`, `text-align: start`), never left/right.

## What "endpoints" means here

The screens have no HTTP API. Each button calls a server action on its screen (a Livewire method), and each form
field is bound to a server property. `SCREENS.md` lists, per screen:

- the address;
- the Blade view;
- the actions the screen calls, with their arguments;
- the bound fields;
- the `data-*` hooks.

**That list is the contract.** A redesign may move, restyle or regroup any of these things, but must keep each one
working.

## You may

- Change anything in `merchant.css`: tokens, layout, typography, spacing, colours, states, motion.
- In a Blade view: change element types, wrappers, order, classes and inline styles; split or merge sections;
  add icons (inline SVG); add purely visual elements.
- Propose new structure in an HTML file saved from the panel ("שמירת המסך כקובץ HTML"), with notes on what each
  part is.

## You must keep (in every Blade view)

1. **Every `wire:*` attribute, unchanged:** `wire:click="approve('{{ $row['ref'] }}')"`, `wire:submit`,
   `wire:model…`, `wire:confirm`, `wire:loading…`, `wire:target`, `wire:poll…`, `wire:key`, and the same method
   names and arguments. Moving the element is fine. Renaming or dropping the attribute breaks the screen.
2. **Every Alpine attribute and its logic:** `x-data`, `x-show`, `x-ref`, `x-model`, `@click`, `@input`,
   `@keydown`, `x-cloak`. The rule box's "@" search on the knowledge screen is one example.
3. **Form fields' `name`, and every `@csrf`.**
4. **The Blade logic:** `@if`, `@foreach`, `@forelse`, `@php` and the variables they use. Wrap them differently, but
   keep what they decide and loop over.
5. **No hard-coded text.** Every visible word comes from a translation call (`$t('key')`, `__('module::file.key')`).
   - A new label needs a key in Hebrew and in English: write both in `CHANGES.md`.
   - Product names, shoppers' questions and answers are data: keep `dir="auto"` on them.
6. **Every `data-*` attribute.** Tests and scripts find elements by them.
7. **Accessibility:**
   - keep `aria-*` and `role` attributes;
   - a button stays a `<button>`, and a link that navigates stays an `<a>`;
   - focus must stay visible.
8. **Merchant screens speak to a shop owner.** No model names, no costs, no internal mechanics on these screens.

## Avoid

- New fonts beyond Google Fonts, external scripts, CSS frameworks or a build step: there is no bundler.
- Selectors that depend on Filament internals more than one level deep. Prefer the `lp-*` classes and `fi-*`
  component roots.
- `!important`, except to override a Filament inline style. Say so in `CHANGES.md` when you use it.
- Removing a state: empty, loading (`wire:loading`), error or disabled. Every list has an empty message; keep it
  visible.

## Real data, real actions

The link signs in as a member of the shop's team for 24 hours. Pressing "אישור", "לא נכון", "למחוק", "לאשר ולהחיל"
or a setting's save changes the live shop. To see a state, open it; do not trigger it.

## What to hand back

- `merchant.css`;
- the changed files under `views/`, at the same paths;
- any saved HTML screens with proposed structure;
- `CHANGES.md`, listing per screen what changed, every new or renamed class, and every new text with its Hebrew and
  English wording.

The engineer ports the views back into the system, runs the test suite (every screen's actions are covered), and
checks each screen against your files.
