<x-filament-panels::page>
    {{-- Searches, as the designer's redesign draws it (Searches page; prototype searches.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::searches.'.$key, $params))
    @php($n = fn (string $key, int $count, array $params = []) => trans_choice('admin::searches.'.$key, $count, ['n' => number_format($count)] + $params))
    @php($mark = '<svg viewBox="0 0 225 225" fill="currentColor" aria-hidden="true"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>')
    @php($up = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>')
    @php($down = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>')
    @php($more = '<svg class="lp-d-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>')
    @php($arrow = app()->getLocale() === 'he' ? '←' : '→')
    @php($change = fn (int $now, int $before): array => \App\Modules\Admin\Filament\Merchant\Pages\ShopOverview::change($now, $before))
    @php($first = $this->firstDay())
    {{-- a timestamp as a date and hour on the store's clock ("Nov 8, 14:32"), the year only when it isn't this one --}}
    @php($tz = (string) (\Filament\Facades\Filament::getTenant()?->timezone ?: 'Asia/Jerusalem'))
    @php($when = function (int $ts) use ($tz): string {
        $local = \Illuminate\Support\Carbon::createFromTimestamp($ts)->timezone($tz)->locale(app()->getLocale());
        return $local->translatedFormat(__($local->year === now($tz)->year ? 'admin::shoppers.when' : 'admin::shoppers.when_year'));
    })

    <div class="lp-page lp-d" data-searches>
        <div class="lp-d-grid">
            @if (! $first)
                {{-- four numbers --}}
                @php($k = $this->kpis())
                @php($fixes = $this->fixesWaiting())
                <div class="lp-d-kpis" data-kpis>
                    @php($c = $change($k['searches'] ?? 0, $k['searches_before'] ?? 0))
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.searches') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ number_format($k['searches'] ?? 0) }}</span>
                        <span class="lp-d-kpi-l">
                            @if (in_array($c['dir'], ['up', 'down'], true))<span class="lp-d-chip {{ $c['dir'] === 'up' ? 'lp-d-chip-line' : 'lp-d-chip-quiet' }} lp-d-num">{!! $c['dir'] === 'up' ? $up : $down !!}{{ $c['pct'] }}%</span> {{ $t('kpi.vs.'.($this->days === 7 ? 'week' : ($this->days === 30 ? 'month' : 'period'))) }}@elseif ($c['dir'] === 'new')<span class="lp-d-chip lp-d-chip-ink">{{ __('admin::today.trend.new') }}</span>@endif
                        </span>
                    </div>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.questions') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24"><path d="M12 4a8 8 0 0 0-6.8 12.2L4 20l3.9-1.2A8 8 0 1 0 12 4z"/></svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ number_format($k['questions'] ?? 0) }}</span>
                        <span class="lp-d-kpi-l">@if (($k['questions'] ?? 0) > 0){{ $t('kpi.answered', ['pct' => (int) round(100 * $k['answered'] / $k['questions'])]) }}@endif</span>
                    </div>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.photos') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><circle cx="12" cy="12.5" r="3.2"/><path d="M8.5 6l1.5-2h4l1.5 2"/></svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ number_format($k['photos'] ?? 0) }}</span>
                        <span class="lp-d-kpi-l">@if (($k['photos'] ?? 0) > 0){{ $n('kpi.photo_adds', (int) ($k['photo_adds'] ?? 0)) }}@endif</span>
                    </div>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.found') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ ($k['asked'] ?? 0) > 0 ? (int) round(100 * $k['found'] / $k['asked']).'%' : '—' }}</span>
                        <span class="lp-d-kpi-l">@if ($fixes > 0)<a class="lp-d-more" href="{{ $this->improvementsUrl() }}"><span>{{ $n('kpi.fixes', $fixes) }}</span>{!! $more !!}</a>@endif</span>
                    </div>
                </div>

                {{-- the days --}}
                @php($chart = $this->chart())
                <section class="lp-d-card lp-d-chart" aria-labelledby="chart-title" data-chart>
                    <div class="lp-d-card-head">
                        <div><h2 class="lp-d-card-title" id="chart-title">{{ $t('chart.title') }}</h2><p class="lp-d-card-sub">{{ $t('chart.sub') }}</p></div>
                        <div class="lp-tabs" role="group" aria-label="{{ $t('chart.measure') }}">
                            @foreach (['searches', 'adds'] as $m)
                                <button type="button" wire:click="measure('{{ $m }}')" @class(['is-on' => $this->measure === $m]) aria-pressed="{{ $this->measure === $m ? 'true' : 'false' }}">{{ $t('chart.'.$m) }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="lp-d-bars">
                        @foreach ($chart['bars'] as $bar)
                            <div @class(['lp-d-bar', 'is-peak' => $bar['peak']])>
                                <span class="lp-d-bar-n lp-d-num">{{ $bar['n'] > 0 && ($bar['peak'] || count($chart['bars']) <= 14) ? number_format($bar['n']) : '' }}</span>
                                <span class="lp-d-bar-col" style="height: {{ max($bar['n'] > 0 ? 3 : 0, $bar['h']) }}%"></span>
                                <span class="lp-d-bar-d">{{ $bar['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($chart['peak'])
                        <p class="lp-d-chart-note">
                            {!! trans_choice('admin::searches.chart.peak_'.$this->measure, $chart['peak']['n'], ['day' => '<b>'.e($chart['peak']['day']).'</b>', 'n' => number_format($chart['peak']['n'])]) !!}
                            @if ($chart['peak']['query']) {{ $t('chart.peak_query') }} <bdi>“{{ $chart['peak']['query'] }}”</bdi>.@endif
                        </p>
                    @endif
                </section>

                {{-- rising --}}
                @php($rising = $this->rising())
                <section class="lp-d-card lp-d-rising" aria-labelledby="rise-title" data-rising>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="rise-title">{{ $t('rising.title.'.($this->days === 7 ? 'week' : 'period')) }}</h2><p class="lp-d-card-sub">{{ $t('rising.sub') }}</p></div></div>
                    @if ($rising === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('rising.none') }}</b><span>{{ $t('rising.none_body') }}</span></div>
                    @else
                        <ul class="lp-d-rise">
                            @foreach ($rising as $r)
                                @php($c = $change($r['searches'], $r['before']))
                                <li>
                                    @if ($r['picture'])<img src="{{ $r['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                    <span><b dir="auto">{{ $r['query'] }}</b><span class="lp-d-num">{{ $n('searches_n', $r['searches']) }}</span></span>
                                    @if ($c['dir'] === 'new')<span class="lp-d-chip lp-d-chip-on-ink">{{ __('admin::today.trend.new') }}</span>@else<span class="lp-d-chip {{ $c['pct'] >= 40 ? 'lp-d-chip-lime' : 'lp-d-chip-on-ink' }} lp-d-num">{!! $up !!}{{ $c['pct'] }}%</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- the top searches --}}
                @php($rows = $this->rows())
                <section class="lp-d-card lp-d-top-list" aria-labelledby="top-title" data-top-searches>
                    <div class="lp-d-card-head">
                        <div><h2 class="lp-d-card-title" id="top-title">{{ $t('top.title') }}</h2><p class="lp-d-card-sub">{{ $t('top.sub') }}</p></div>
                        <div class="lp-tabs" role="group" aria-label="{{ $t('top.sort') }}">
                            @foreach (['searched', 'sales', 'fix'] as $s)
                                <button type="button" wire:click="sortBy('{{ $s }}')" @class(['is-on' => $this->sort === $s]) aria-pressed="{{ $this->sort === $s ? 'true' : 'false' }}">{{ $t('top.sorts.'.$s) }}</button>
                            @endforeach
                        </div>
                    </div>
                    @if ($rows === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('top.none.'.$this->sort) }}</b><span>{{ $t('top.none_body.'.$this->sort) }}</span></div>
                    @else
                        <div class="lp-d-scroll-x">
                            <table class="lp-d-tbl">
                                <thead><tr><th>{{ $t('top.cols.search') }}</th><th class="r">{{ $t('top.cols.searches') }}</th><th>{{ $t('top.cols.clicked') }}</th><th class="r">{{ $t('top.cols.adds') }}</th><th class="r">{{ $t('top.cols.sales') }}</th><th class="r">{{ $t('top.cols.trend') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($rows as $r)
                                        @php($c = $change($r['searches'], $r['before']))
                                        <tr>
                                            <td><span class="lp-d-t">
                                                @if ($r['picture'])<img src="{{ $r['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                                <span><b dir="auto">@if ($r['empty'])<s>{{ $r['query'] }}</s>@else{{ $r['query'] }}@endif</b><span>{{ $arrow }} @if ($r['empty']){{ $t('top.nothing') }}@elseif ($r['found'])<bdi>{{ $r['found'] }}</bdi>@else{{ $t('top.no_click') }}@endif</span></span>
                                            </span></td>
                                            <td class="r lp-d-num">{{ number_format($r['searches']) }}</td>
                                            <td><span class="lp-d-meter lp-d-num"><i><u style="width: {{ (int) ($r['clicked'] ?? 0) }}%"></u></i>{{ $r['clicked'] === null ? '—' : $r['clicked'].'%' }}</span></td>
                                            <td class="r lp-d-num">{{ number_format($r['adds']) }}</td>
                                            <td class="r lp-d-num"><bdi>{{ $this->money($r['sales']) }}</bdi></td>
                                            <td class="r">
                                                @if ($r['fix'])
                                                    <a class="lp-d-chip lp-d-chip-sun" href="{{ $this->improvementsUrl() }}">{{ $t('top.fix') }}</a>
                                                @elseif ($c['dir'] === 'new')
                                                    <span class="lp-d-chip lp-d-chip-ink">{{ __('admin::today.trend.new') }}</span>
                                                @elseif ($c['dir'] === 'up')
                                                    <span class="lp-d-chip {{ $c['pct'] >= 50 ? 'lp-d-chip-lime' : 'lp-d-chip-line' }} lp-d-num">{!! $up !!}{{ $c['pct'] }}%</span>
                                                @elseif ($c['dir'] === 'down')
                                                    <span class="lp-d-chip lp-d-chip-quiet lp-d-num">{!! $down !!}{{ $c['pct'] }}%</span>
                                                @else
                                                    <span class="lp-d-chip lp-d-chip-quiet lp-d-num">0%</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- what they ask --}}
                @php($questions = $this->questions())
                <section class="lp-d-card lp-d-questions" aria-labelledby="q-title" data-questions>
                    <div class="lp-d-card-head">
                        <div><h2 class="lp-d-card-title" id="q-title">{{ $t('questions.title') }}</h2><p class="lp-d-card-sub">{{ $t('questions.sub') }}</p></div>
                        <div class="lp-d-head-links">
                            @if ($url = $this->questionsUrl())<a class="lp-d-more" href="{{ $url }}" data-all-questions><span>{{ $t('questions.all') }}</span>{!! $more !!}</a>@endif
                            <a class="lp-d-more" href="{{ $this->improvementsUrl() }}"><span>{{ $t('questions.unanswered') }}</span>{!! $more !!}</a>
                        </div>
                    </div>
                    @if ($questions === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('questions.none') }}</b><span>{{ $t('questions.none_body') }}</span></div>
                    @else
                        <ul class="lp-d-qs">
                            @foreach ($questions as $q)
                                {{-- a question opens to the answer the shopper got, and when it was asked --}}
                                <li class="lp-d-q-item" wire:key="q-{{ $loop->index }}-{{ md5($q['question']) }}">
                                    <details class="lp-d-q-open">
                                        <summary class="lp-d-q">
                                            <span class="lp-d-q-icon"><svg viewBox="0 0 24 24"><path d="M12 4a8 8 0 0 0-6.8 12.2L4 20l3.9-1.2A8 8 0 1 0 12 4z"/></svg></span>
                                            <span><b dir="auto">{{ $q['question'] }}</b><span class="lp-d-num">{{ $n('questions.times', $q['times']) }} · {{ $q['from'] === 'page' ? $t('questions.from.page', ['page' => $q['where']]) : $t('questions.from.'.$q['from']) }}</span>
                                                {{-- the page it was asked on: what the question is about --}}
                                                @if (! empty($q['asked_on']['title']))
                                                    <span class="lp-d-lead-page lp-d-q-page">
                                                        @if (! empty($q['asked_on']['image']))<img src="{{ $q['asked_on']['image'] }}" alt="" loading="lazy" onerror="this.remove()">@endif
                                                        <span>{{ __('admin::shoppers.waiting.asked_on.'.($q['asked_on']['kind'] === 'product' ? 'product' : 'content')) }}</span>
                                                        @if (! empty($q['asked_on']['url']))<a href="{{ $q['asked_on']['url'] }}" target="_blank" rel="noopener" dir="auto" onclick="event.stopPropagation()">{{ $q['asked_on']['title'] }}</a>@else<b dir="auto">{{ $q['asked_on']['title'] }}</b>@endif
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="lp-d-q-end">
                                                @if ($q['answered'])<span class="lp-d-chip lp-d-chip-line">{{ $t('questions.answered') }}</span>@else<span class="lp-d-chip lp-d-chip-sun">{{ $t('questions.open') }}</span>@endif
                                                <svg class="lp-d-q-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                            </span>
                                        </summary>
                                        <div class="lp-d-q-answer" data-answer>
                                            <p class="lp-d-q-when">
                                                @if (($q['last'] ?? 0) > 0)
                                                    @if ($q['times'] > 1 && ($q['first'] ?? 0) > 0 && $when($q['first']) !== $when($q['last']))
                                                        {{ $t('questions.asked_last') }} <bdi>{{ $when($q['last']) }}</bdi> · {{ $t('questions.asked_first') }} <bdi>{{ $when($q['first']) }}</bdi>
                                                    @else
                                                        {{ $t('questions.asked') }} <bdi>{{ $when($q['last']) }}</bdi>
                                                    @endif
                                                @endif
                                            </p>
                                            @if (($q['answer'] ?? null) !== null)
                                                <span class="lp-d-q-label">{{ $t('questions.they_got') }}</span>
                                                <p class="lp-d-q-text" dir="auto">{!! nl2br(e($q['answer'])) !!}</p>
                                                {{-- the products the answer showed, those the shopper opened or added marked --}}
                                                @if (($q['shown'] ?? []) !== [])
                                                    <span class="lp-d-q-label">{{ $t('questions.shown') }}</span>
                                                    <div class="lp-d-q-shown" data-answer-products>
                                                        @foreach ($q['shown'] as $p)
                                                            <a class="lp-d-q-prod{{ $p['opened'] ? ' is-opened' : '' }}" href="{{ $p['url'] ?: '#' }}" target="_blank" rel="noopener" data-answer-product>
                                                                <span class="lp-d-q-prod-pic">@if ($p['picture'])<img src="{{ $p['picture'] }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
                                                                <span class="lp-d-q-prod-title" dir="auto">{{ $p['title'] }}</span>
                                                                @if ($p['price'] || $p['opened'])<span class="lp-d-q-prod-meta"><bdi>{{ $p['price'] }}</bdi>@if ($p['price'] && $p['opened']) · @endif @if ($p['opened'])<b>{{ $t('questions.opened') }}</b>@endif</span>@endif
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @else
                                                <p class="lp-d-q-text lp-d-q-none">{{ $t('questions.no_answer') }}</p>
                                            @endif
                                            @unless ($q['answered'])<a class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" href="{{ $this->improvementsUrl() }}">{{ $t('questions.answer_it') }}</a>@endunless
                                            {{-- "i": why this answer, its sources, and how to change how the store answers, on the questions screen --}}
                                            @if ($why = $this->whyUrl($q['question']))
                                                <a class="lp-d-btn lp-d-btn-sm" href="{{ $why }}" data-why-link style="display: inline-flex; align-items: center; gap: 8px">
                                                    <span aria-hidden="true" style="width: 18px; height: 18px; border-radius: 999px; box-shadow: inset 0 0 0 1.5px currentColor; display: inline-grid; place-items: center; font: italic 600 11px/1 Georgia, serif">i</span>{{ $t('questions.why') }}
                                                </a>
                                            @endif
                                        </div>
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- photo searches --}}
                @php($photos = $this->photos())
                <section class="lp-d-card lp-d-photos" aria-labelledby="ph-title" data-photos>
                    @php($allPhotos = $this->photosUrl())
                    <div class="lp-d-card-head">
                        <div><h2 class="lp-d-card-title" id="ph-title">{{ $t('photos.title') }}</h2><p class="lp-d-card-sub">{{ $t('photos.sub') }}</p></div>
                        @if ($allPhotos)<a class="lp-d-more" href="{{ $allPhotos }}" data-all-photos><span>{{ $t('photos.all') }}</span>{!! $more !!}</a>@endif
                    </div>
                    @if ($photos === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('photos.none') }}</b><span>{{ $t('photos.none_body') }}</span></div>
                    @else
                        <div class="lp-d-ph">
                            @foreach ($photos as $p)
                                {{-- the shopper's photo, and the first few products it showed them --}}
                                @php($found = array_values(array_filter($p['found'] ?? [['title' => $p['title'], 'picture' => $p['picture']]], fn ($f) => ($f['picture'] ?? null) !== null)))
                                <div class="lp-d-ph-item">
                                    @if ($p['photo'])<img src="{{ $p['photo'] }}" alt="{{ $t('photos.theirs') }}">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                    @if ($found === [])
                                        <span class="lp-d-tile">{!! $mark !!}</span>
                                    @else
                                        <span @class(['lp-d-ph-found', 'is-'.min(count($found), 4)])>
                                            @foreach (array_slice($found, 0, 4) as $f)
                                                <img src="{{ $f['picture'] }}" alt="{{ $f['title'] }}" title="{{ $f['title'] }}" loading="lazy" onerror="this.classList.add('is-missing')">
                                            @endforeach
                                        </span>
                                    @endif
                                    <span class="lp-d-ph-arrow"><svg class="lp-d-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <p><b dir="auto">{{ $p['title'] }}</b></p>
                                    @if (isset($p['total']) || isset($p['at']))
                                        <p class="lp-d-ph-meta">@isset($p['total']){{ $n('photos.found', (int) $p['total']) }}@endisset @if (($p['at'] ?? 0) > 0)· <bdi>{{ $when($p['at']) }}</bdi>@endif</p>
                                    @endif
                                    @if ($allPhotos)<a class="lp-d-ph-link" href="{{ $allPhotos }}" aria-label="{{ $t('photos.all') }}"></a>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- in their own words --}}
                @php($words = $this->words())
                <section class="lp-d-card lp-d-words" aria-labelledby="w-title" data-words>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="w-title">{{ $t('words.title') }}</h2><p class="lp-d-card-sub">{{ $t('words.sub') }}</p></div></div>
                    @if ($words === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('words.none') }}</b><span>{{ $t('words.none_body') }}</span></div>
                    @else
                        @php($top = $words[0]['n'])
                        <div class="lp-d-cloud">
                            @foreach ($words as $w)
                                <span @class(['xl' => $w['n'] >= $top * 0.8, 'lg' => $w['n'] < $top * 0.8 && $w['n'] >= $top * 0.5]) dir="auto">{{ $w['phrase'] }} <b class="lp-d-num">{{ number_format($w['n']) }}</b></span>
                            @endforeach
                        </div>
                        <p class="lp-d-words-insight">{!! trans_choice('admin::searches.words.insight', $words[0]['n'], ['phrase' => '<b><bdi>“'.e($words[0]['phrase']).'”</bdi></b>', 'n' => number_format($words[0]['n'])]) !!}</p>
                    @endif
                </section>
            @else
                <section class="lp-d-card" style="grid-column: 1 / -1">
                    <div class="lp-d-zero"><span class="lp-d-zero-mark lp-d-waiting">{!! $mark !!}</span><b>{{ $t('first.title') }}</b><span>{{ $t('first.body') }}</span></div>
                </section>
            @endif

            {{-- try a search --}}
            <section class="lp-d-card lp-d-try" aria-labelledby="try-title" id="try" data-try>
                <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="try-title">{{ $t('try.title') }}</h2><p class="lp-d-card-sub">{{ $t('try.sub') }}</p></div></div>
                <form class="lp-d-try-field" role="search" wire:submit="tryIt">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
                    <label class="sr-only" for="try-q">{{ $t('try.label') }}</label>
                    <input id="try-q" wire:model="tryQ" autocomplete="off" placeholder="{{ $t('try.placeholder') }}" dir="auto">
                    <button class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" type="submit" wire:loading.attr="disabled" wire:target="tryIt">{{ $t('try.button') }}</button>
                </form>
                @if (! $first && ($rows ?? []) !== [])
                    <div class="lp-d-try-chips">
                        @foreach (array_slice($rows, 0, 4) as $r)
                            <button type="button" wire:click="tryIt(@js($r['query']))" dir="auto">{{ $r['query'] }}</button>
                        @endforeach
                    </div>
                @endif
                <div class="lp-d-try-out" aria-live="polite">
                    @if ($this->tried !== null)
                        <p class="lp-d-try-read">{{ $this->tried['total'] > 0 ? $n('try.found', $this->tried['total']) : $t('try.none') }}</p>
                        @if ($this->tried['products'] !== [])
                            <div class="lp-d-try-res">
                                @foreach ($this->tried['products'] as $p)
                                    <a class="lp-d-pv" @if ($p['url']) href="{{ $p['url'] }}" target="_blank" rel="noopener" @endif>
                                        @if ($p['picture'])<img src="{{ $p['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                        <b dir="auto">{{ $p['title'] }}</b>
                                        @if ($p['price'])<span class="lp-d-num"><bdi>{{ $p['price'] }}</bdi></span>@endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
