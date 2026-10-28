# Measuring a change to the search box answers

Every change to how the search box answers (a prompt, a model's effort, how many pieces the search sends,
a new step) is measured on real questions before any store gets it. The baseline is built from what shoppers
asked, kept with the data the writer was given then, and asked again after a change: better, worse, faster,
or only more expensive. Building calls no model; every case run is a real, paid model call (about $0.015 a case).

## Build the baseline, once

```sh
php artisan assistant:quality-baseline build --per-shop=30
php artisan assistant:quality-cases rocks-and-gold --import=app/Modules/Assistant/resources/evals/rocks-conversations.json
```

`build` takes each shop's most asked questions of the last 90 days, once each: answered ones, unanswered ones and
questions about other things, in that mix. A reply in a conversation ("יש משהו עם זהב צהוב?") is kept with the
conversation it was asked in (logged since 2026-10-28), and asked again on today's pages: what it stands for is
written by the topic check each time, so there is nothing of it to freeze.

`quality-cases --import` adds hand-written cases with what is expected of each, checked in code, never by a model:
`outcome`, `contains`, `absent`, `picks`, `sources`, `supported`, and against the catalogue `picks_max_price`,
`picks_min_price` (every pick's price within: a reply that keeps the conversation's budget), `picks_title_any`,
`picks_say_any` (title or description: "זהב צהוב" is in a ring's description), `picks_max`.

## Run it as it is, then with one change at a time

```sh
php artisan assistant:quality-baseline run --label=as-is
php artisan assistant:quality-baseline run --label=checker-low --set=assistant.site_check_effort=low
php artisan assistant:quality-baseline run --label=scope-v9 --scope-prompt=9
php artisan assistant:quality-baseline run --label=nearer --live --set=assistant.site_similarity_drop=0.12
php artisan assistant:quality-baseline compare --runs=as-is,checker-low
```

- `--set key=value` sets a setting for this run only and puts it back after; `--prompt`, `--scope-prompt` and
  `--check-prompt` ask with another version of the writer's, the topic check's or the checker's prompt.
- A prompt or a model change runs on each case's frozen data (the default): the same pieces and products, so the
  change is the only difference. A change to the search itself (the floor, the drop, how many pieces) needs `--live`.
- Every run prints its numbers against the run before it: answered, supported (the checker kept it), unanswered,
  cost, p50 and p90 time, the writer's and the checker's time, pieces and characters sent, and how near the nearest
  and the least near piece were. `compare` prints two runs side by side, then every case whose outcome, verdict or
  pass changed, or whose time moved by half.

## What is a candidate and what is live

- `assistant.site_check_effort` (default: the model's own, medium on Haiku 5.5) and `assistant.site_similarity_drop`
  (default 0, off) change nothing until set. A candidate prompt version listed in `AnswerSiteQuestion::CANDIDATES`
  is never served; it is asked with `--scope-prompt` or `--check-prompt`, and released by taking it off that list.
- A change is released when the run with it passes no fewer cases, keeps `supported` and `answered` within a point,
  and is faster or cheaper; or when it fixes cases that failed and breaks none. The run's label and numbers go in
  the commit message.

## What the first runs taught (Rocks, 2026-10-28)

- **Measure the noise first.** Two identical `as-is` runs on 27 real questions differed on 3 cases, 15 points of
  `supported` and a second of p50: the writer and the checker are not deterministic. A change that moves fewer
  cases than that proved nothing. Run `as-is` twice, then each candidate twice, and read the medians.
- **The deterministic signal is in the cases with expectations.** "ומה עם עד 5000?" after a budget question fails
  as-is (no products, the reply alone means nothing) and passes with scope_site v9 on every run; that is a mechanism,
  not noise. An expectation must fit the store's words: a ring "משובצת יהלומים" says "יהלום" (final letters are
  folded), but a ring whose page never says its colour cannot be expected to say "צהוב".
- **Run `--live` runs detached.** A `--set` is put back when the run ends; a run killed by a dropped ssh session
  never ends, and the setting stays on the store. A live run of thirty cases is long: start it so it survives the
  session, and check `Settings::overrideFor` afterwards.
- `--kind=regression` runs only the cases with expectations (fast, deterministic criteria); `--kind=all` both.
