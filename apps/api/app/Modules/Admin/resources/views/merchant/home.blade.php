<x-filament-panels::page>
    {{-- Today, as the designer's redesign draws it (ShopOverview; prototype today.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::today.'.$key, $params))
    @php($n = fn (string $key, int $count, array $params = []) => trans_choice('admin::today.'.$key, $count, ['n' => number_format($count)] + $params))
    @php($mark = '<svg viewBox="0 0 225 225" fill="currentColor" aria-hidden="true"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>')
    @php($up = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>')
    @php($down = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>')
    @php($more = '<svg class="lp-d-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>')
    @php($arrow = app()->getLocale() === 'he' ? '←' : '→')
    @php($first = $this->firstDay())

    <div class="lp-page lp-d" data-home @if ($first) data-first-day @endif>
        <div class="lp-d-grid">
            @if (! $first)
                {{-- the money through search, on ink --}}
                @php($hero = $this->hero())
                @if ($hero)
                    <section class="lp-d-hero" aria-labelledby="hero-label" data-hero>
                        <div>
                            <p class="lp-d-hero-label" id="hero-label">
                                {!! $mark !!}
                                <span>{{ $t('hero.label', ['period' => $this->periodLabel()]) }}</span>
                                @if ($hero['revenue']['dir'] === 'up')
                                    <span class="lp-d-chip lp-d-chip-lime lp-d-num">{!! $up !!}{{ $hero['revenue']['pct'] }}%</span>
                                @elseif ($hero['revenue']['dir'] === 'down')
                                    <span class="lp-d-chip lp-d-chip-on-ink lp-d-num">{!! $down !!}{{ $hero['revenue']['pct'] }}%</span>
                                @elseif ($hero['revenue']['dir'] === 'new')
                                    <span class="lp-d-chip lp-d-chip-lime">{{ $t('trend.new') }}</span>
                                @endif
                            </p>
                            <p class="lp-d-hero-money lp-d-num" data-number="revenue"><bdi>{{ $hero['money'] }}</bdi></p>
                            <p class="lp-d-hero-why">
                                {!! $hero['orders']['n'] === 0 ? e($t('hero.no_orders')) : trans_choice('admin::today.hero.orders_started', $hero['orders']['n'], ['n' => '<b>'.number_format($hero['orders']['n']).'</b>']) !!}
                                @if ($hero['best_day'])
                                    @if ($hero['best_query'])
                                        {{ $t('hero.best_with', ['day' => $hero['best_day']]) }} <bdi>“{{ $hero['best_query'] }}”</bdi>.
                                    @else
                                        {{ $t('hero.best', ['day' => $hero['best_day']]) }}
                                    @endif
                                @endif
                            </p>
                            @if ($hero['line']['stroke'] !== '')
                                <svg class="lp-d-spark" viewBox="0 0 560 86" preserveAspectRatio="none" aria-hidden="true">
                                    <defs><linearGradient id="lp-d-spark-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2ff78" stop-opacity=".28"/><stop offset="1" stop-color="#e2ff78" stop-opacity="0"/></linearGradient></defs>
                                    <path d="{{ $hero['line']['area'] }}" fill="url(#lp-d-spark-fill)"/>
                                    <path d="{{ $hero['line']['stroke'] }}" fill="none" stroke="#e2ff78" stroke-width="2.2" vector-effect="non-scaling-stroke"/>
                                    @if ($hero['line']['dot'])<circle cx="{{ $hero['line']['dot']['x'] }}" cy="{{ $hero['line']['dot']['y'] }}" r="4.5" fill="#e2ff78"/>@endif
                                </svg>
                                <div class="lp-d-days" aria-hidden="true">
                                    @foreach ($hero['labels'] as $label)
                                        <span @class(['is-best' => $label['best']])>{{ $label['text'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="lp-d-hero-side">
                            @foreach (['adds' => '<path d="M3 4h2.2l2.3 11h10.8l2-8H7"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>', 'orders' => '<path d="M5 7h14l-1.2 12.1a1 1 0 0 1-1 .9H7.2a1 1 0 0 1-1-.9z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>'] as $key => $icon)
                                @php($stat = $hero[$key])
                                <div class="lp-d-stat" data-number="{{ $key }}">
                                    <span class="lp-d-stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon !!}</svg></span>
                                    <span><span class="lp-d-stat-n lp-d-num">{{ number_format($stat['n']) }}</span><span class="lp-d-stat-l">{{ $n('hero.'.$key, $stat['n']) }}</span></span>
                                    @if (in_array($stat['dir'], ['up', 'down'], true))
                                        <span class="lp-d-chip lp-d-chip-on-ink lp-d-num">{!! $stat['dir'] === 'up' ? $up : $down !!}{{ $stat['pct'] }}%</span>
                                    @elseif ($stat['dir'] === 'new')
                                        <span class="lp-d-chip lp-d-chip-on-ink">{{ $t('trend.new') }}</span>
                                    @endif
                                </div>
                            @endforeach
                            <div class="lp-d-found" data-found>
                                <div class="lp-d-found-row">
                                    <span>{{ $n('hero.asked', $hero['asked']) }}</span>
                                    @if ($hero['found_pct'] !== null)<b class="lp-d-num">{{ $t('hero.found_pct', ['pct' => $hero['found_pct']]) }}</b>@endif
                                </div>
                                <div class="lp-d-found-bar" aria-hidden="true"><i style="width: {{ $hero['found_pct'] ?? 0 }}%"></i><u style="width: {{ $hero['found_pct'] === null ? 0 : 100 - $hero['found_pct'] }}%"></u></div>
                                <div class="lp-d-found-key"><span>{{ $t('hero.found_key') }}</span><span>{{ $t('hero.waiting_key') }}</span></div>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- what waits for the owner: the three weightiest fixes --}}
                @php($fixes = $this->fixes())
                <section class="lp-d-card lp-d-todo" aria-labelledby="todo-title" data-attention>
                    <div class="lp-d-card-head">
                        <div>
                            <h2 class="lp-d-card-title" id="todo-title">{{ $t('todo.title') }}</h2>
                            <p class="lp-d-card-sub">{{ $fixes['total'] > 0 ? $t('todo.sub') : $t('todo.sub_none') }}</p>
                        </div>
                        @if ($fixes['total'] > 0)
                            <a class="lp-d-more" href="{{ $this->inboxUrl() }}"><span>{{ $n('todo.all', $fixes['total']) }}</span>{!! $more !!}</a>
                        @endif
                    </div>
                    <div class="lp-d-tasks">
                        @forelse ($fixes['items'] as $fix)
                            <article class="lp-d-task" wire:key="fix-{{ $fix->key }}" data-fix="{{ $fix->key }}">
                                <div class="lp-d-task-pics">
                                    @forelse (array_slice($fix->pictures, 0, 2) as $picture)
                                        <img src="{{ $picture }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">
                                    @empty
                                        <span class="lp-d-tile">{!! $mark !!}</span>
                                    @endforelse
                                </div>
                                <div>
                                    <div class="lp-d-task-tags">
                                        <span class="lp-d-chip lp-d-chip-{{ ['required' => 'sun', 'quick' => 'lime', 'asked' => 'sun', 'nice' => 'quiet', 'bigger' => 'ink'][$fix->chip] ?? 'quiet' }}">{{ $t('chips.'.$fix->chip) }}</span>
                                        @if ($fix->count > 0)<span class="lp-d-chip lp-d-chip-quiet lp-d-num">{{ $n('units.'.$fix->unit, $fix->count) }}</span>@endif
                                    </div>
                                    @php($title = e($fix->title))
                                    @if ($fix->struck !== null && $fix->struck !== '')
                                        @php($title = preg_replace('/'.preg_quote(e($fix->struck), '/').'/u', '<s dir="auto">'.e($fix->struck).'</s>', $title, 1))
                                    @endif
                                    <h3 class="lp-d-task-title" title="{{ $fix->title }}" dir="auto">{!! $title !!}</h3>
                                    <p class="lp-d-task-text">{{ $fix->text }}</p>
                                </div>
                                <div class="lp-d-task-do">
                                    @if ($fix->oneTap)
                                        <button type="button" class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" wire:click="approveFix(@js($fix->key))" wire:loading.attr="disabled" wire:target="approveFix">
                                            <span>{{ $fix->action }}</span>
                                        </button>
                                    @elseif ($fix->url)
                                        <a class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" href="{{ $fix->url }}"><span>{{ $fix->action }}</span></a>
                                    @endif
                                    @if ($fix->chip !== 'required')
                                        <button type="button" class="lp-d-link" wire:click="later(@js($fix->key))">{{ $t('todo.later') }}</button>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="lp-d-all-clear" role="status">{!! $mark !!}<span>{{ $t('todo.all_clear') }}</span></div>
                        @endforelse
                    </div>
                </section>

                {{-- the searches as they happen --}}
                <section class="lp-d-card lp-d-live" aria-labelledby="live-title">
                    <livewire:admin-today-feed :shop-id="$this->shopId()" :key="'today-feed-'.$this->shopId()" />
                </section>

                {{-- the words searched most --}}
                @php($top = $this->top())
                <section class="lp-d-card lp-d-want" aria-labelledby="want-title" data-top>
                    <div class="lp-d-card-head">
                        <div>
                            <h2 class="lp-d-card-title" id="want-title">{{ $t('want.title.'.($this->days === 7 ? 'week' : 'period')) }}</h2>
                            <p class="lp-d-card-sub">{{ $t('want.sub') }}</p>
                        </div>
                    </div>
                    @if ($top === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('want.none') }}</b><span>{{ $t('want.none_body') }}</span></div>
                    @else
                        @php($most = max(1, max(array_column($top, 'searches'))))
                        <ol class="lp-d-terms">
                            @foreach ($top as $i => $term)
                                @php($trend = \App\Modules\Admin\Filament\Merchant\Pages\ShopOverview::change($term['searches'], $term['before']))
                                <li class="lp-d-term">
                                    <span class="lp-d-term-rank lp-d-num">{{ $i + 1 }}</span>
                                    @if ($term['picture'])<img src="{{ $term['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                    <span class="lp-d-term-text">
                                        <span class="lp-d-term-q" dir="auto">{{ $term['query'] }}</span>
                                        <span class="lp-d-term-found" title="{{ $term['found'] }}">@if ($term['found'])<span aria-hidden="true">{{ $arrow }}</span> <bdi>{{ $term['found'] }}</bdi>@else{{ $t('want.no_click') }}@endif</span>
                                    </span>
                                    <span class="lp-d-term-bar" aria-hidden="true"><i style="width: {{ round(100 * $term['searches'] / $most) }}%"></i></span>
                                    <span class="lp-d-term-n lp-d-num">
                                        <b>{{ number_format($term['searches']) }}</b>
                                        @if ($trend['dir'] === 'new')
                                            <span class="lp-d-chip lp-d-chip-ink">{{ $t('trend.new') }}</span>
                                        @elseif ($trend['dir'] === 'up')
                                            <span class="lp-d-chip {{ $trend['pct'] >= 50 ? 'lp-d-chip-lime' : 'lp-d-chip-line' }}">{!! $up !!}{{ $trend['pct'] }}%</span>
                                        @elseif ($trend['dir'] === 'down')
                                            <span class="lp-d-chip lp-d-chip-quiet">{!! $down !!}{{ $trend['pct'] }}%</span>
                                        @else
                                            <span class="lp-d-chip lp-d-chip-quiet">0%</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                {{-- a fix approved, and what it did since --}}
                @php($wins = $this->wins())
                <section class="lp-d-card lp-d-proof" aria-labelledby="proof-title" data-wins>
                    <div class="lp-d-card-head">
                        <div>
                            <h2 class="lp-d-card-title" id="proof-title">{{ $t('proof.title') }}</h2>
                            <p class="lp-d-card-sub">{{ $t('proof.sub') }}</p>
                        </div>
                    </div>
                    @if ($wins === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('proof.none') }}</b><span>{{ $t('proof.none_body') }}</span></div>
                    @else
                        @php($win = $wins[0])
                        <article class="lp-d-fixed">
                            <div class="lp-d-fixed-img">
                                @if ($win['picture'])<img src="{{ $win['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                <span class="lp-d-chip lp-d-chip-lime">{{ $t('proof.approved', ['date' => $win['approved']]) }}</span>
                            </div>
                            <div class="lp-d-fixed-body">
                                <p class="lp-d-fixed-was"><span dir="auto">{{ $win['searched'] }}</span><svg class="lp-d-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg><b>{{ $t('proof.now_find', ['what' => $win['finds']]) }}</b></p>
                                <div class="lp-d-fixed-nums">
                                    <div><b class="lp-d-num">{{ number_format($win['found']) }}</b><span>{{ $n('proof.found', $win['found']) }}</span></div>
                                    <div><b class="lp-d-num">{{ number_format($win['adds']) }}</b><span>{{ $n('proof.adds', $win['adds']) }}</span></div>
                                    @if ($win['sales'] !== null)<div><b class="lp-d-num"><bdi>{{ $win['sales'] }}</bdi></b><span>{{ $t('proof.sales') }}</span></div>@endif
                                </div>
                            </div>
                        </article>
                        @if (count($wins) > 1)
                            <div class="lp-d-minis">
                                @foreach (array_slice($wins, 1) as $other)
                                    <div class="lp-d-minifix">
                                        @if ($other['picture'])<img src="{{ $other['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                        <span><bdi>“<b>{{ $other['searched'] }}</b>”</bdi> {{ $arrow }} <bdi>{{ $other['finds'] }}</bdi></span>
                                        <span class="lp-d-num">{{ $n('proof.found_short', $other['found']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </section>

                {{-- what went to the cart through search --}}
                @php($sold = $this->sold())
                <section class="lp-d-card lp-d-picks" aria-labelledby="picks-title" data-sold>
                    <div class="lp-d-card-head">
                        <div>
                            <h2 class="lp-d-card-title" id="picks-title">{{ $t('picks.title') }}</h2>
                            <p class="lp-d-card-sub">{{ $t('picks.sub') }}</p>
                        </div>
                    </div>
                    @if ($sold === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('picks.none') }}</b><span>{{ $t('picks.none_body') }}</span></div>
                    @else
                        <div class="lp-d-shelf">
                            @foreach ($sold as $i => $product)
                                <article class="lp-d-prod">
                                    <div class="lp-d-prod-img">
                                        @if ($product['picture'])<img src="{{ $product['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                        @if ($product['photo'])
                                            <span class="lp-d-chip lp-d-chip-ink">{{ $t('picks.from_photo') }}</span>
                                        @elseif ($i === 0)
                                            <span class="lp-d-chip lp-d-chip-lime lp-d-num">#1</span>
                                        @endif
                                    </div>
                                    <p class="lp-d-prod-name" dir="auto" title="{{ $product['title'] }}">{{ $product['title'] }}</p>
                                    <p class="lp-d-prod-via">
                                        {!! trans_choice('admin::today.picks.to_cart', $product['adds'], ['n' => '<b>'.number_format($product['adds']).'</b>']) !!}
                                        @if ($product['photo'])· {{ $t('picks.by_photo') }}@elseif ($product['phrase'])· <bdi>“{{ $product['phrase'] }}”</bdi>@endif
                                    </p>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @else
                {{-- the first day: nothing searched yet --}}
                <section class="lp-d-hero" aria-label="{{ $t('first.label') }}" data-first-steps>
                    <div>
                        <p class="lp-d-hero-label">{!! $mark !!}<span>{{ $t('first.label') }}</span></p>
                        <p class="lp-d-hero-money lp-d-hero-money-sm">{{ $t('first.title') }}</p>
                        <ul class="lp-d-setup">
                            @foreach ($this->setup() as $step)
                                <li @class(['is-next' => ! $step['done']])><i>@if ($step['done'])<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>@endif</i><span>{!! $step['html'] !!}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="lp-d-hero-side">
                        <div class="lp-d-found">
                            <div class="lp-d-found-row"><b>{{ $t('first.see') }}</b></div>
                            <p class="lp-d-found-p">{{ $t('first.see_body') }}</p>
                            @if ($this->storeUrl())
                                <a class="lp-d-btn lp-d-btn-lime lp-d-btn-sm" href="{{ $this->storeUrl() }}" target="_blank" rel="noopener" wire:click="looked">{{ $t('first.open') }}</a>
                            @endif
                        </div>
                        @if ($this->trySearchUrl())
                            <div class="lp-d-found">
                                <div class="lp-d-found-row"><b>{{ $t('first.try') }}</b></div>
                                <p class="lp-d-found-p">{{ $t('first.try_body') }}</p>
                                <a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $this->trySearchUrl() }}" data-try-search>{{ $t('first.try_button') }}</a>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="lp-d-card lp-d-todo" aria-labelledby="todo0">
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="todo0">{{ $t('todo.title') }}</h2><p class="lp-d-card-sub">{{ $t('todo.sub_first') }}</p></div></div>
                    <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('todo.none') }}</b><span>{{ $t('todo.none_body') }}</span></div>
                </section>

                <section class="lp-d-card lp-d-live" aria-labelledby="live-title">
                    <livewire:admin-today-feed :shop-id="$this->shopId()" :key="'today-feed-'.$this->shopId()" />
                </section>

                @php($learned = $this->learned())
                <section class="lp-d-card lp-d-picks" aria-labelledby="learn0" data-learned>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="learn0">{{ $t('learned.title') }}</h2><p class="lp-d-card-sub">{{ $t('learned.sub') }}</p></div></div>
                    <div class="lp-d-learned">
                        @foreach (['products', 'photos', 'tags', 'pages'] as $key)
                            <div><b class="lp-d-num">{{ number_format($learned[$key]) }}</b><span>{{ $n('learned.'.$key, $learned[$key]) }}</span></div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- the weekly email --}}
            <section class="lp-d-mail" data-weekly-mail>
                <span class="lp-d-mail-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg></span>
                <p>{!! $t('mail.text', ['when' => '<b>'.e($t('mail.when')).'</b>']) !!}</p>
                <a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ \App\Modules\Admin\Filament\Merchant\Pages\WeeklyEmailPreview::getUrl() }}" target="_blank" rel="noopener">{{ $t('mail.see') }}</a>
            </section>
        </div>
    </div>
</x-filament-panels::page>
