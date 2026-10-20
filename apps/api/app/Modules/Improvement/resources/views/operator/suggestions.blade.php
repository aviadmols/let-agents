<x-filament-panels::page>
    @php($s = $this->suggestions())
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:3px 10px;border:1px solid #d4d4d8;border-radius:999px;background:#fff;color:#111827;cursor:pointer')
    @php($dark = $btn.';background:#111827;color:#fff;border-color:#111827')
    @php($field = 'width:100%;padding:8px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;line-height:1.6;background:transparent;color:inherit')
    @php($chip = 'display:inline-block;padding:2px 10px;border-radius:999px;background:rgba(127,127,127,.12);font-size:12px')
    @php($quota = $this->postsLeft())

    {{-- The list of recommendations and the panel each opens in: tabs by kind with their counts, filters and a
         search, a row per recommendation with its next step and its type; a click opens it beside the list with
         what is proposed, what it rests on, its content, and its impact. --}}
    <style>
        .rec { --rec-line: rgba(127,127,127,.22); --rec-soft: rgba(127,127,127,.07); --rec-card: #fff; --rec-link: #0b5f63; }
        .dark .rec { --rec-card: rgb(24 24 27); --rec-link: #5ec4c8; }
        .rec-tabs { display: flex; flex-wrap: wrap; border-bottom: 1px solid var(--rec-line); }
        .rec-tab { padding: 12px 20px; border: 1px solid var(--rec-line); border-bottom: 0; margin-bottom: -1px; background: var(--rec-soft); font-weight: 600; font-size: 14px; cursor: pointer; color: inherit; }
        .rec-tab + .rec-tab { margin-inline-start: -1px; }
        .rec-tab.is-on { background: var(--rec-card); border-bottom: 1px solid var(--rec-card); }
        .rec-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 16px 0 10px; }
        .rec-pill { padding: 6px 14px; border: 1px solid var(--rec-line); border-radius: 999px; background: transparent; color: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
        .rec-pill.is-on { background: #111827; color: #fff; border-color: #111827; }
        .dark .rec-pill.is-on { background: #fff; color: #111827; border-color: #fff; }
        .rec-select { padding: 6px 12px; border: 1px solid var(--rec-line); border-radius: 999px; background: transparent; color: inherit; font: inherit; font-size: 13px; font-weight: 600; }
        .rec-search { flex: 1 1 260px; display: flex; align-items: center; gap: 8px; padding: 8px 16px; border: 1px solid var(--rec-line); border-radius: 999px; }
        .rec-search input { flex: 1; border: 0; outline: 0; background: transparent; color: inherit; font: inherit; padding: 0; box-shadow: none; }
        .rec-table { border: 1px solid var(--rec-line); border-radius: 14px; overflow: hidden; background: var(--rec-card); }
        .rec-head, .rec-row { display: grid; grid-template-columns: minmax(0, 1fr) auto 110px 80px 150px; gap: 14px; align-items: center; padding: 12px 18px; }
        .rec-head { font-size: 13px; opacity: .65; border-bottom: 1px solid var(--rec-line); }
        .rec-row { border-bottom: 1px solid var(--rec-line); cursor: pointer; }
        .rec-row:last-of-type { border-bottom: 0; }
        .rec-row:hover { background: var(--rec-soft); }
        .rec-title { color: var(--rec-link); font-weight: 650; font-size: 15px; line-height: 1.4; }
        .rec-sub { font-size: 12.5px; opacity: .65; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rec-type { justify-self: start; padding: 3px 10px; border: 1px solid #8fd3ae; border-radius: 6px; background: #e9f7f0; color: #0b5f3a; font-size: 12.5px; white-space: nowrap; }
        .dark .rec-type { background: rgba(46,160,103,.15); color: #8fe0b7; border-color: rgba(143,211,174,.4); }
        .rec-act { padding: 5px 14px; border: 1px solid var(--rec-line); border-radius: 999px; background: transparent; color: inherit; font-size: 13px; cursor: pointer; white-space: nowrap; }
        .rec-act.is-ai { border-color: #f3a39b; background: #fff4f2; color: #7a1f14; }
        .dark .rec-act.is-ai { background: rgba(243,163,155,.12); color: #ffc2bb; }
        .rec-prio { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
        .rec-prio i { width: 8px; height: 8px; border-radius: 50%; background: #a1a1aa; }
        .rec-prio[data-p="high"] i { background: #e5484d; }
        .rec-prio[data-p="medium"] i { background: #f5a524; }
        .rec-num { font-variant-numeric: tabular-nums; font-size: 13px; opacity: .8; }
        .rec-empty { padding: 26px 18px; opacity: .7; margin: 0; }
        .rec-shade { position: fixed; inset: 0; background: rgba(0,0,0,.28); z-index: 50; }
        .rec-panel { position: fixed; top: 0; bottom: 0; inset-inline-end: 0; width: min(860px, 100vw); background: var(--rec-card); z-index: 51; display: flex; flex-direction: column; box-shadow: 0 0 40px rgba(0,0,0,.25); }
        .rec-panel__top { display: flex; align-items: flex-start; gap: 10px; padding: 18px 24px 0; }
        .rec-panel__title { flex: 1; font-size: 20px; font-weight: 700; line-height: 1.35; }
        .rec-round { width: 36px; height: 36px; flex: none; border: 1px solid var(--rec-line); border-radius: 50%; background: transparent; color: inherit; cursor: pointer; display: inline-grid; place-items: center; font-size: 16px; }
        .rec-round:disabled { opacity: .35; cursor: default; }
        .rec-panel__tabs { display: flex; flex-wrap: wrap; align-items: center; gap: 0 28px; padding: 0 24px; border-bottom: 1px solid var(--rec-line); margin-top: 14px; }
        .rec-panel__tab { padding: 12px 2px; border: 0; border-bottom: 3px solid transparent; background: transparent; color: inherit; font-weight: 600; font-size: 14px; cursor: pointer; }
        .rec-panel__tab.is-on { border-bottom-color: currentColor; }
        .rec-panel__body { flex: 1; overflow: auto; padding: 18px 24px 40px; }
        .rec-card { border: 1px solid var(--rec-line); border-radius: 14px; padding: 18px 22px; display: grid; gap: 8px; }
        .rec-card h3 { font-size: 17px; font-weight: 700; margin: 0 0 4px; }
        .rec-card h4 { font-size: 14px; font-weight: 700; margin: 8px 0 0; }
        .rec-card p { margin: 0; line-height: 1.65; }
        .rec-status { position: relative; margin-inline-start: auto; }
        .rec-status > button { display: inline-flex; align-items: center; gap: 8px; padding: 6px 4px; border: 0; background: transparent; color: inherit; font-size: 14px; cursor: pointer; }
        .rec-status i { width: 9px; height: 9px; border-radius: 50%; background: #f5a524; }
        .rec-status[data-s="applied"] i, .rec-status[data-s="approved"] i { background: #30a46c; }
        .rec-status[data-s="rejected"] i { background: #a1a1aa; }
        .rec-menu { position: absolute; inset-inline-end: 0; top: 100%; min-width: 220px; background: var(--rec-card); border: 1px solid var(--rec-line); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15); padding: 6px; display: grid; z-index: 2; }
        .rec-menu button { text-align: start; padding: 8px 10px; border: 0; background: transparent; color: inherit; border-radius: 6px; cursor: pointer; font-size: 13.5px; }
        .rec-menu button:hover { background: var(--rec-soft); }
        .rec-ev { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .rec-ev th { text-align: start; font-weight: 500; opacity: .65; padding: 8px 10px; border-bottom: 1px solid var(--rec-line); }
        .rec-ev td { padding: 10px; border-bottom: 1px solid var(--rec-line); vertical-align: top; }
        .rec-ev td:first-child { color: var(--rec-link); font-weight: 600; }
        .rec-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .rec-stat { border: 1px solid var(--rec-line); border-radius: 12px; padding: 12px 14px; }
        .rec-stat b { font-size: 22px; font-variant-numeric: tabular-nums; }
        @media (max-width: 760px) {
            .rec-head { display: none; }
            .rec-row { grid-template-columns: minmax(0, 1fr) auto; }
            .rec-row > .rec-prio, .rec-row > .rec-num { display: none; }
            .rec-panel__top, .rec-panel__tabs, .rec-panel__body { padding-inline: 16px; }
        }
    </style>

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if ($this->picksShop())
            <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <button type="button" wire:click="reviewNow" wire:loading.attr="disabled" style="{{ $btn }}">{{ __('improvement::ui.review_now') }}</button>
        <span style="{{ $small }}" data-quota>{{ __('improvement::ui.post.quota', ['used' => $quota['used'], 'cap' => $quota['cap']]) }}</span>
    </div>

    @if ($s === null)
        <x-filament::section>{{ __('improvement::ui.no_shop') }}</x-filament::section>
    @else
        @if ($s['latest'])
            <x-filament::section :heading="__('improvement::ui.latest', ['date' => $s['latest']->day->format('d.m.Y')])" collapsible collapsed>
                <pre style="white-space:pre-wrap;font:inherit;margin:0">{{ \App\Modules\Improvement\Support\Digest::forMerchant((string) $s['latest']->digest) }}</pre>
            </x-filament::section>
        @endif

        @php($rows = $this->rows($s))
        @php($kinds = \App\Modules\Improvement\Models\ImprovementProposal::KINDS)
        @php($counts = collect(['pending' => true, 'decided' => false])->map(fn (bool $open): array => ['all' => collect($rows)->where('open', $open)->count()] + collect($kinds)->mapWithKeys(fn (string $k): array => [$k => collect($rows)->where('open', $open)->filter(fn (array $r): bool => $r['p']->kind === $k)->count()])->all())->all())

        <div class="rec" x-data="{
                kind: 'all', state: 'pending', prio: '', q: '', open: null, tab: 'rec', menu: false,
                counts: @js($counts),
                shows(el) { return (this.kind === 'all' || el.dataset.kind === this.kind) && el.dataset.state === this.state && (! this.prio || el.dataset.prio === this.prio) && (! this.q || el.dataset.text.includes(this.q.trim().toLowerCase())); },
                visible() { return [...this.$root.querySelectorAll('[data-row]')].filter((r) => this.shows(r)); },
                show(id, tab) { this.open = id; this.tab = tab || 'rec'; this.menu = false; },
                go(step) { const rows = this.visible(), at = rows.findIndex((r) => r.dataset.id === this.open), next = rows[at + step]; if (next) { this.show(next.dataset.id, this.tab); } },
                edge(step) { const rows = this.visible(), at = rows.findIndex((r) => r.dataset.id === this.open); return at < 0 || ! rows[at + step]; },
            }" @keydown.escape.window="open = null">

            <div class="rec-tabs" role="tablist">
                <button type="button" class="rec-tab" :class="kind === 'all' && 'is-on'" @click="kind = 'all'">{{ __('improvement::ui.list.all') }} (<span x-text="counts[state].all">{{ $counts['pending']['all'] }}</span>)</button>
                @foreach ($kinds as $k)
                    <button type="button" class="rec-tab" :class="kind === '{{ $k }}' && 'is-on'" @click="kind = '{{ $k }}'">{{ __("improvement::ui.list.tabs.{$k}") }} (<span x-text="counts[state]['{{ $k }}']">{{ $counts['pending'][$k] }}</span>)</button>
                @endforeach
            </div>

            <div class="rec-filters">
                <button type="button" class="rec-pill" :class="state === 'pending' && 'is-on'" @click="state = 'pending'">{{ __('improvement::ui.pending') }} ({{ $counts['pending']['all'] }})</button>
                <button type="button" class="rec-pill" :class="state === 'decided' && 'is-on'" @click="state = 'decided'">{{ __('improvement::ui.decided') }} ({{ $counts['decided']['all'] }})</button>
                <select class="rec-select" x-model="prio" aria-label="{{ __('improvement::ui.list.priority') }}">
                    <option value="">{{ __('improvement::ui.list.priority') }}</option>
                    @foreach (['high', 'medium', 'low'] as $level)
                        <option value="{{ $level }}">{{ __("improvement::ui.list.priorities.{$level}") }}</option>
                    @endforeach
                </select>
                <label class="rec-search">
                    <input type="search" x-model="q" placeholder="{{ __('improvement::ui.list.search') }}" aria-label="{{ __('improvement::ui.list.search') }}">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" style="width:18px;height:18px;opacity:.6" />
                </label>
            </div>
            <p style="{{ $small }};margin:0 0 12px">{{ __('improvement::ui.pending_about') }}</p>

            <div class="rec-table">
                <div class="rec-head">
                    <span>{{ __('improvement::ui.list.cols.title') }}</span>
                    <span></span>
                    <span>{{ __('improvement::ui.list.cols.priority') }}</span>
                    <span>{{ __('improvement::ui.list.cols.demand') }}</span>
                    <span>{{ __('improvement::ui.list.cols.type') }}</span>
                </div>
                @foreach ($rows as $r)
                    @php($p = $r['p'])
                    <div class="rec-row" data-row data-id="{{ $p->id }}" data-proposal="{{ $p->kind }}" data-kind="{{ $p->kind }}" data-state="{{ $r['open'] ? 'pending' : 'decided' }}" data-prio="{{ $r['priority'] }}"
                        data-text="{{ mb_strtolower($p->title.' '.$r['summary'].' '.implode(' ', array_column($r['evidence'], 'text'))) }}"
                        x-show="shows($el)" @if (! $r['open']) style="display:none" @endif @click="show('{{ $p->id }}')" wire:key="row-{{ $p->id }}">
                        <div style="min-width:0">
                            <div class="rec-title">{{ $p->title }}</div>
                            @if ($r['summary'] !== '' && $r['summary'] !== $p->title)<div class="rec-sub">{{ $r['summary'] }}</div>@endif
                        </div>
                        <div>
                            @if ($r['open'] && $this->drafting($p->id))
                                <span style="{{ $small }}" wire:poll.5s>{{ __('improvement::ui.list.writing') }}</span>
                            @elseif ($r['open'] && $p->kind === 'content_gap' && $p->post === null && $quota['left'] > 0)
                                <button type="button" class="rec-act is-ai" @click.stop wire:click="draftPost('{{ $p->id }}')">✦ {{ __('improvement::ui.post.write') }}</button>
                            @elseif ($r['open'] && $p->kind === 'faq' && ! isset($p->detail['ai']))
                                <button type="button" class="rec-act is-ai" @click.stop wire:click="draftAnswer('{{ $p->id }}')">✦ {{ __('improvement::ui.answer.write') }}</button>
                            @elseif ($r['open'])
                                <button type="button" class="rec-act" @click.stop="show('{{ $p->id }}', 'content')">{{ __('improvement::ui.list.review') }}</button>
                            @elseif ($p->status === 'applied')
                                <button type="button" class="rec-act" @click.stop="show('{{ $p->id }}', 'impact')">{{ __('improvement::ui.list.track') }}</button>
                            @else
                                <span style="{{ $small }}">{{ __("improvement::ui.statuses.{$p->status}") }}</span>
                            @endif
                        </div>
                        <span class="rec-prio" data-p="{{ $r['priority'] }}"><i></i>{{ __("improvement::ui.list.priorities.{$r['priority']}") }}</span>
                        <span class="rec-num">{{ $r['demand'] > 0 ? '×'.number_format($r['demand']) : '—' }}</span>
                        <span class="rec-type">{{ __("improvement::ui.list.types.{$p->kind}") }}</span>
                    </div>
                @endforeach
                @if ($s['pending']->isEmpty())
                    <p class="rec-empty" x-show="state === 'pending'">{{ __('improvement::ui.none_pending') }}</p>
                @endif
                @if ($s['decided']->isEmpty())
                    <p class="rec-empty" x-show="state === 'decided'" style="display:none">{{ __('improvement::ui.none_decided') }}</p>
                @endif
            </div>

            {{-- the panel each recommendation opens in --}}
            <div class="rec-shade" x-show="open" x-cloak x-transition.opacity @click="open = null"></div>
            @foreach ($rows as $r)
                @php($p = $r['p'])
                <aside class="rec-panel" x-show="open === '{{ $p->id }}'" x-cloak role="dialog" aria-label="{{ $p->title }}" wire:key="panel-{{ $p->id }}" data-panel="{{ $p->id }}">
                    <div class="rec-panel__top">
                        <div class="rec-panel__title">{{ $p->title }}</div>
                        <button type="button" class="rec-round" @click="go(-1)" :disabled="edge(-1)" aria-label="{{ __('improvement::ui.list.previous') }}">‹</button>
                        <button type="button" class="rec-round" @click="go(1)" :disabled="edge(1)" aria-label="{{ __('improvement::ui.list.next') }}">›</button>
                        <button type="button" class="rec-round" @click="open = null" aria-label="{{ __('improvement::ui.list.close') }}">✕</button>
                    </div>
                    <div class="rec-panel__tabs">
                        @foreach (['rec', 'content', 'impact'] as $tab)
                            <button type="button" class="rec-panel__tab" :class="tab === '{{ $tab }}' && 'is-on'" @click="tab = '{{ $tab }}'">{{ __("improvement::ui.list.panel_tabs.{$tab}") }}</button>
                        @endforeach
                        <div class="rec-status" data-s="{{ $p->status }}" @click.outside="menu = false">
                            <button type="button" @click="menu = ! menu"><i></i>{{ __("improvement::ui.statuses.{$p->status}") }} ▾</button>
                            <div class="rec-menu" x-show="menu" x-cloak>
                                @if ($r['open'])
                                    @if (! ($p->kind === 'content_gap' && $p->post === null))
                                        <button type="button" wire:click="approve('{{ $p->id }}')" @click="menu = false">{{ __("improvement::ui.approve.{$p->kind}") }}</button>
                                    @else
                                        <button type="button" @click="tab = 'content'; menu = false">{{ __('improvement::ui.post.write') }}</button>
                                    @endif
                                    <button type="button" wire:click="reject('{{ $p->id }}')" @click="menu = false">{{ __('improvement::ui.reject') }}</button>
                                @elseif ($p->status === 'applied' && $p->kind !== 'synonym')
                                    <button type="button" wire:click="reject('{{ $p->id }}')" @click="menu = false">{{ __('improvement::ui.take_down') }}</button>
                                @else
                                    <span style="{{ $small }};padding:8px 10px">{{ $p->decided_at?->format('d.m.Y') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="rec-panel__body">
                        {{-- the recommendation: what is proposed, and what it rests on --}}
                        <div x-show="tab === 'rec'"><div style="display:grid;gap:18px">
                            <section class="rec-card">
                                <h3>{{ __('improvement::ui.list.proposed') }}</h3>
                                <h4>{{ __('improvement::ui.list.cols.title') }}</h4>
                                <p>{{ $p->title }}</p>
                                @if ($r['summary'] !== '')
                                    <h4>{{ __('improvement::ui.list.summary') }}</h4>
                                    <p>{{ $r['summary'] }}</p>
                                @endif
                                <h4>{{ __('improvement::ui.list.changes') }}</h4>
                                <p>{{ __("improvement::ui.list.effects.{$p->kind}", ['term' => (string) ($p->detail['term'] ?? ''), 'means' => (string) ($p->detail['means'] ?? '')]) }}</p>
                                <p style="{{ $small }}">{{ __("improvement::ui.kinds.{$p->kind}") }} · {{ __('improvement::ui.list.priority') }}: {{ __("improvement::ui.list.priorities.{$r['priority']}") }}</p>
                            </section>
                            <section class="rec-card" data-driving>
                                <h3>{{ __('improvement::ui.list.driving') }}</h3>
                                <p>{{ __('improvement::ui.list.driving_about') }}</p>
                                @if ($r['evidence'] === [])
                                    <p style="{{ $small }}">{{ __('improvement::ui.list.no_evidence') }}</p>
                                @else
                                    <div style="overflow-x:auto">
                                        <table class="rec-ev">
                                            <thead><tr>
                                                <th>{{ __('improvement::ui.list.ev.text') }}</th>
                                                <th>{{ __('improvement::ui.list.ev.count') }}</th>
                                                <th>{{ __('improvement::ui.list.ev.what') }}</th>
                                            </tr></thead>
                                            <tbody>
                                                @foreach ($r['evidence'] as $e)
                                                    <tr>
                                                        <td>{{ $e['text'] }}</td>
                                                        <td style="font-variant-numeric:tabular-nums">{{ number_format($e['count']) }}</td>
                                                        <td>{{ __("improvement::ui.list.ev.kinds.{$e['kind']}", ['n' => number_format((int) $e['results'])]) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                @if ($p->audit_reason)
                                    <p style="{{ $small }}">{{ __('improvement::ui.checked') }} {{ $p->audit_reason }}</p>
                                @endif
                            </section>
                        </div></div>

                        {{-- the content: what will be published, the team's to edit, and the steps --}}
                        <div x-show="tab === 'content'" x-cloak><div style="display:grid;gap:10px">
                            @if ($p->kind === 'synonym')
                                <p>{{ __('improvement::ui.list.effects.synonym', ['term' => (string) ($p->detail['term'] ?? ''), 'means' => (string) ($p->detail['means'] ?? '')]) }}</p>
                            @elseif ($p->kind === 'faq' && $r['open'])
                                {{-- The drafted answer, the team's to finish: what is published is what is in the box. --}}
                                <label style="{{ $small }}">{{ __('improvement::ui.answer.label') }}</label>
                                @if ($this->drafting($p->id))
                                    <div style="{{ $small }}" wire:poll.4s>{{ __('improvement::ui.answer.writing_now') }}</div>
                                @else
                                    <textarea wire:model="drafts.{{ $p->id }}" rows="7" style="{{ $field }}"></textarea>
                                    @php($ai = $p->detail['ai'] ?? null)
                                    @if ($ai)
                                        @if (($ai['problems'] ?? []) !== [])
                                            <div style="padding:8px 10px;border-radius:8px;background:rgba(235,104,52,.1);font-size:13px" data-problems>
                                                <div style="font-weight:600">{{ __('improvement::ui.answer.problems') }}</div>
                                                <ul style="margin:4px 0 0;padding-inline-start:18px">
                                                    @foreach ($ai['problems'] as $problem)
                                                        <li>{{ $problem }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @else
                                            <div style="{{ $small }}">{{ __('improvement::ui.answer.checked_ok') }}</div>
                                        @endif
                                        @if (($ai['sources'] ?? []) !== [])
                                            <div style="{{ $small }}">{{ __('improvement::ui.answer.sources') }} {{ implode(' · ', $ai['sources']) }}</div>
                                        @endif
                                    @else
                                        <div style="{{ $small }}">{{ __('improvement::ui.answer.about') }}</div>
                                    @endif
                                @endif
                            @elseif ($p->kind === 'faq')
                                <p>{{ (string) ($p->detail['answer'] ?? '') }}</p>
                                @if ($p->status === 'applied')<span style="{{ $chip }};justify-self:start">{{ __('improvement::ui.answer.live') }}</span>@endif
                            @elseif ($p->kind === 'content_gap')
                                <div>{{ $p->detail['why'] ?? '' }}</div>
                                @if ($p->post === null)
                                    @if ($this->drafting($p->id))
                                        <div style="{{ $small }}" wire:poll.5s>{{ __('improvement::ui.post.writing_now') }}</div>
                                    @elseif (! $r['open'])
                                        <p style="{{ $small }}">{{ __('improvement::ui.list.no_post') }}</p>
                                    @endif
                                @elseif ($r['open'])
                                    <label style="{{ $small }}">{{ __('improvement::ui.post.title') }}</label>
                                    <input type="text" wire:model="titles.{{ $p->id }}" style="{{ $field }}">
                                    <label style="{{ $small }}">{{ __('improvement::ui.post.body') }}</label>
                                    <textarea wire:model="drafts.{{ $p->id }}" rows="16" style="{{ $field }};font-family:inherit"></textarea>
                                    <details style="border:1px solid var(--rec-line);border-radius:8px;padding:8px 10px" open>
                                        <summary style="cursor:pointer;font-weight:600;font-size:13px">{{ __('improvement::ui.post.seo') }}</summary>
                                        <div style="display:grid;gap:6px;margin-top:8px">
                                            <label style="{{ $small }}">{{ __('improvement::ui.post.seo_title') }}</label>
                                            <input type="text" maxlength="70" wire:model="seo.{{ $p->id }}.seo_title" style="{{ $field }}">
                                            <label style="{{ $small }}">{{ __('improvement::ui.post.meta_description') }}</label>
                                            <textarea rows="2" maxlength="170" wire:model="seo.{{ $p->id }}.meta_description" style="{{ $field }}"></textarea>
                                            @if (! empty($p->post->seo['focus_keyword']))
                                                <div style="{{ $small }}">{{ __('improvement::ui.post.focus_keyword') }} <b>{{ $p->post->seo['focus_keyword'] }}</b></div>
                                            @endif
                                            @if (! empty($p->post->seo['tags']))
                                                <div>@foreach ($p->post->seo['tags'] as $tag)<span style="{{ $chip }};margin-inline-end:4px">{{ $tag }}</span>@endforeach</div>
                                            @endif
                                            @if (! empty($p->post->seo['faq']))
                                                <div style="font-size:13px" data-faq>
                                                    <div style="font-weight:600">{{ __('improvement::ui.post.faq') }}</div>
                                                    @foreach ($p->post->seo['faq'] as $item)
                                                        <div style="margin-top:6px"><b>{{ $item['q'] }}</b><br>{{ $item['a'] }}</div>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <div style="{{ $small }}">{{ __('improvement::ui.post.store_note') }}</div>
                                        </div>
                                    </details>
                                    @if ($p->post->products !== [])
                                        <div style="{{ $small }}">{{ __('improvement::ui.post.products', ['count' => count($p->post->products)]) }}</div>
                                    @endif
                                    @if ($p->post->problems !== [])
                                        <div style="padding:8px 10px;border-radius:8px;background:rgba(235,104,52,.1);font-size:13px" data-problems>
                                            <div style="font-weight:600">{{ __('improvement::ui.post.problems') }}</div>
                                            <ul style="margin:4px 0 0;padding-inline-start:18px">
                                                @foreach ($p->post->problems as $problem)
                                                    <li>{{ $problem }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @else
                                        <div style="{{ $small }}">{{ __('improvement::ui.post.checked_ok') }}</div>
                                    @endif
                                @else
                                    <div style="font-weight:600">{{ $p->post->title }}</div>
                                    @if ($p->status === 'applied' && $p->post->status === 'published')
                                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                                            <a href="{{ $this->guideUrl($p->post) }}" target="_blank" rel="noopener" style="{{ $chip }}">{{ __('improvement::ui.post.open') }} ↗</a>
                                            @php($remote = $p->post->remote ?? [])
                                            @if ($this->sending($p->post))
                                                <span style="{{ $chip }}" wire:poll.5s>{{ __('improvement::ui.post.sending') }}</span>
                                            @elseif (($remote['status'] ?? null) === 'draft')
                                                <a href="{{ $remote['admin_url'] ?? $remote['url'] }}" target="_blank" rel="noopener" style="{{ $chip }}" data-remote>{{ __('improvement::ui.post.on_store') }} ↗</a>
                                                <button type="button" wire:click="resendPost('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.post.resend') }}</button>
                                            @elseif (($remote['status'] ?? null) === 'failed')
                                                <span style="{{ $chip }};background:rgba(235,104,52,.15)" data-remote-failed>{{ __('connections::runs.failures.'.($remote['error'] ?? 'http_error'), ['status' => 0]) }}</span>
                                                <button type="button" wire:click="resendPost('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.post.retry') }}</button>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            @endif

                            @if ($r['open'])
                                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                                    @if ($p->kind === 'content_gap' && $p->post === null)
                                        @unless ($this->drafting($p->id))
                                            @if ($quota['left'] > 0)
                                                <button type="button" wire:click="draftPost('{{ $p->id }}')" style="{{ $dark }}">{{ __('improvement::ui.post.write') }}</button>
                                            @else
                                                <span style="{{ $small }}">{{ __('improvement::ui.post.cap_reached') }}</span>
                                            @endif
                                        @endunless
                                    @else
                                        <button type="button" wire:click="approve('{{ $p->id }}')" style="{{ $dark }}">{{ __("improvement::ui.approve.{$p->kind}") }}</button>
                                        @if ($p->kind === 'faq' && ! $this->drafting($p->id))
                                            <button type="button" wire:click="draftAnswer('{{ $p->id }}')" style="{{ $btn }}">{{ __(isset($p->detail['ai']) ? 'improvement::ui.answer.rewrite' : 'improvement::ui.answer.write') }}</button>
                                        @endif
                                        @if ($p->kind === 'faq' && ! isset($p->detail['post_proposal_id']) && $quota['left'] > 0)
                                            <button type="button" wire:click="postFromQuestion('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.post.from_question') }}</button>
                                        @endif
                                        @if ($p->kind === 'content_gap' && ! $this->drafting($p->id) && $quota['left'] > 0)
                                            <button type="button" wire:click="draftPost('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.post.rewrite') }}</button>
                                        @endif
                                    @endif
                                    <button type="button" wire:click="reject('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.reject') }}</button>
                                </div>
                            @elseif ($p->status === 'applied' && $p->kind !== 'synonym')
                                <div><button type="button" wire:click="reject('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.take_down') }}</button></div>
                            @endif
                        </div></div>

                        {{-- the impact: the searches behind it, before the decision and since --}}
                        <div x-show="tab === 'impact'" x-cloak data-impact><div style="display:grid;gap:14px">
                            @if ($r['open'])
                                <p>{{ __('improvement::ui.list.impact.pending') }}</p>
                            @elseif ($r['impact'] === null)
                                <p>{{ __('improvement::ui.list.impact.none') }}</p>
                            @else
                                <p>{{ __('improvement::ui.list.impact.about', ['days' => $r['impact']['days'], 'date' => $p->decided_at?->format('d.m.Y')]) }}</p>
                                <div class="rec-stats">
                                    @foreach (['searches', 'empty', 'clicks'] as $m)
                                        <div class="rec-stat">
                                            <div style="{{ $small }}">{{ __("improvement::ui.list.impact.{$m}") }}</div>
                                            <div><b>{{ number_format($r['impact']['after'][$m]) }}</b> <span style="{{ $small }}">{{ __('improvement::ui.list.impact.was', ['n' => number_format($r['impact']['before'][$m])]) }}</span></div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div></div>
                    </div>
                </aside>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
