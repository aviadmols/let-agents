<x-filament-panels::page>
    {{-- Shoppers, as the designer's redesign draws it (Shoppers page; prototype shoppers.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::shoppers.'.$key, $params))
    @php($n = fn (string $key, int $count, array $params = []) => trans_choice('admin::shoppers.'.$key, $count, ['n' => number_format($count)] + $params))
    @php($locale = app()->getLocale())
    @php($ago = fn ($at) => $at === null ? '' : \Illuminate\Support\Carbon::instance($at)->locale($locale)->diffForHumans())
    @php($mark = '<svg viewBox="0 0 225 225" fill="currentColor" aria-hidden="true"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>')
    @php($chev = '<svg class="lp-d-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>')
    @php($icons = [
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'photo' => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><circle cx="12" cy="12.5" r="3.2"/>',
        'ask' => '<path d="M12 4a8 8 0 0 0-6.8 12.2L4 20l3.9-1.2A8 8 0 1 0 12 4z"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.4 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>',
        'cart' => '<path d="M3 4h2.2l2.3 11h10.8l2-8H7"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>',
        'order' => '<path d="M5 7h14l-1.2 12.1a1 1 0 0 1-1 .9H7.2a1 1 0 0 1-1-.9z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        'lead' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
        'browse' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'view' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    ])
    @php($first = $this->firstDay())

    <div class="lp-page lp-d" data-shoppers>
        <div class="lp-d-grid">
            @if ($first)
                <section class="lp-d-card lp-d-leads" data-zero-leads>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title">{{ $t('waiting.title') }}</h2></div></div>
                    <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('waiting.zero') }}</b><span>{{ $t('waiting.zero_text') }}</span>
                        @if ($url = $this->whatsappUrl())<a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $url }}">{{ $t('waiting.set_whatsapp') }}</a>@endif
                    </div>
                </section>
                <section class="lp-d-card lp-d-sources" data-zero-visits>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title">{{ $t('kpi.visits') }}</h2></div></div>
                    <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('visits.zero') }}</b><span>{{ $t('visits.zero_text') }}</span></div>
                </section>
            @else
                {{-- four numbers --}}
                @php($k = $this->numbers())
                <div class="lp-d-kpis" data-kpis>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.visits') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $icons['browse'] !!}</svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ number_format($k['visits']) }}</span>
                        <span class="lp-d-kpi-l">{{ $n('kpi.searched', $k['searched']) }}</span>
                    </div>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.waiting') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $icons['ask'] !!}</svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num" data-waiting-n>{{ number_format($k['waiting']) }}</span>
                        <span class="lp-d-kpi-l">{{ $k['oldest'] !== null ? $t('kpi.oldest', ['when' => $ago($k['oldest'])]) : $t('kpi.none_waiting') }}</span>
                    </div>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.signups') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $icons['lead'] !!}</svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ number_format($k['signups']) }}</span>
                        <span class="lp-d-kpi-l">{{ $t('kpi.signups_l') }}</span>
                    </div>
                    <div class="lp-d-kpi">
                        <div class="lp-d-kpi-top"><span>{{ $t('kpi.lists') }}</span><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $icons['heart'] !!}</svg></span></div>
                        <span class="lp-d-kpi-n lp-d-num">{{ number_format($k['lists']) }}</span>
                        <span class="lp-d-kpi-l">{{ $n('kpi.products', $k['products']) }}</span>
                    </div>
                </div>

                {{-- waiting for you --}}
                @php($waiting = $this->waiting())
                <section class="lp-d-card lp-d-leads" aria-labelledby="leads-title" data-leads>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="leads-title">{{ $t('waiting.title') }}</h2>@if ($waiting !== [])<p class="lp-d-card-sub">{{ $t('waiting.sub') }}</p>@endif</div></div>
                    @forelse ($waiting as $lead)
                        <article class="lp-d-lead" wire:key="lead-{{ $lead['key'] }}">
                            <span class="lp-d-avatar" aria-hidden="true">@if ($lead['name'] !== null){{ mb_strtoupper(mb_substr($lead['name'], 0, 1)) }}@else<svg viewBox="0 0 24 24">{!! $icons['ask'] !!}</svg>@endif</span>
                            <div class="lp-d-lead-body">
                                <b dir="auto">{{ $lead['name'] ?? $t('waiting.someone') }}</b>
                                <span class="lp-d-lead-meta">· {{ $t('waiting.'.($lead['channel'] === 'email' ? 'email' : 'whatsapp')) }} · {{ $ago($lead['at']) }}</span>
                                <p class="lp-d-lead-q" dir="auto">{{ $lead['question'] }}</p>
                            </div>
                            <div class="lp-d-lead-do">
                                <button class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" type="button" wire:click="replyTo(@js($lead['key']))">{{ $t('waiting.'.($lead['channel'] === 'email' ? 'reply_email' : 'reply_whatsapp')) }}</button>
                                <button class="lp-d-ghost" type="button" wire:click="answered(@js($lead['key']))">{{ $t('waiting.answered') }}</button>
                            </div>
                        </article>
                    @empty
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('waiting.zero') }}</b><span>{{ $t('waiting.zero_text') }}</span>
                            @if ($url = $this->whatsappUrl())<a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $url }}">{{ $t('waiting.set_whatsapp') }}</a>@endif
                        </div>
                    @endforelse
                    @if ($k['waiting'] > count($waiting))<p class="lp-d-note">{{ $n('waiting.more', $k['waiting'] - count($waiting)) }}</p>@endif
                </section>

                {{-- where they come from --}}
                @php($src = $this->sources())
                <section class="lp-d-card lp-d-sources" aria-labelledby="src-title" data-sources>
                    <div class="lp-d-card-head"><div><h2 class="lp-d-card-title" id="src-title">{{ $t('sources.title') }}</h2><p class="lp-d-card-sub">{{ $t('sources.sub') }}</p></div></div>
                    @if ($src['rows'] === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('sources.zero') }}</b><span>{{ $t('sources.zero_text') }}</span></div>
                    @else
                        @php($top = max(1, ...array_column($src['rows'], 'n')))
                        @foreach ($src['rows'] as $row)
                            <div class="lp-d-src"><span>{{ $t('sources.names.'.$row['source']) }}</span><i><u style="width: {{ (int) round(100 * $row['n'] / $top) }}%"></u></i><span class="lp-d-num">{{ $row['pct'] }}%</span></div>
                        @endforeach
                        @if ($src['searchiest'] !== null || $src['campaign'] !== null)
                            <p class="lp-d-src-note">
                                @if ($src['searchiest'] !== null){!! __('admin::shoppers.sources.searchiest', ['source' => e($t('sources.names.'.$src['searchiest']))]) !!}@endif
                                @if ($src['campaign'] !== null){{ trans_choice('admin::shoppers.sources.campaign', $src['campaign']['visits'], ['name' => $src['campaign']['name'], 'n' => number_format($src['campaign']['visits'])]) }}@endif
                            </p>
                        @endif
                    @endif
                </section>

                {{-- recent visits --}}
                @php($stories = $this->stories())
                <section class="lp-d-card lp-d-visits" aria-labelledby="v-title" data-visits>
                    <div class="lp-d-card-head">
                        <div><h2 class="lp-d-card-title" id="v-title">{{ $t('visits.title') }}</h2><p class="lp-d-card-sub">{{ $t('visits.sub') }}</p></div>
                        <div class="lp-d-visits-tools">
                            <label class="lp-d-toggle"><input type="checkbox" wire:model.live="plain"><span>{{ $t('visits.plain') }}</span></label>
                            @if ($url = $this->visitsLogUrl())<a class="lp-d-more" href="{{ $url }}"><span>{{ $t('visits.log') }}</span>{!! $chev !!}</a>@endif
                        </div>
                    </div>
                    @forelse ($stories as $visit)
                        <div class="lp-d-visit">
                            <div class="lp-d-visit-who">
                                <b>{{ match ($visit['who']) { 'returning', 'direct', 'other' => $t('visits.who.'.$visit['who']), default => $t('visits.who.from', ['source' => $t('sources.names.'.$visit['who'])]) } }}</b>
                                <span>{{ $t('visits.device.'.$visit['device']) }} · {{ $t('visits.minutes', ['n' => $visit['minutes']]) }} · {{ $ago(\Illuminate\Support\Carbon::createFromTimestamp($visit['at'])) }}</span>
                            </div>
                            <div class="lp-d-journey">
                                @foreach ($visit['steps'] as $i => $step)
                                    @if ($i > 0){!! $chev !!}@endif
                                    <span @class(['lp-d-step', 'lp-d-step-win' => in_array($step['kind'], ['cart', 'order'], true)])>
                                        @if ($step['picture'] && in_array($step['kind'], ['view', 'views'], true))
                                            <img src="{{ $step['picture'] }}" alt="" loading="lazy">
                                        @else
                                            <span class="lp-d-step-ic"><svg viewBox="0 0 24 24">{!! $icons[$step['kind']] ?? $icons['browse'] !!}</svg></span>
                                        @endif
                                        {{-- a product's title in its own direction, so "צפה ב" and an English name don't trade places --}}
                                        @if ($step['kind'] === 'view')<span>{!! __('admin::shoppers.visits.step.view', ['title' => '<bdi>'.e(\Illuminate\Support\Str::limit((string) $step['text'], 28)).'</bdi>']) !!}</span>@else<span dir="auto">{{ match ($step['kind']) {
                                            'search', 'ask' => \Illuminate\Support\Str::limit((string) $step['text'], 40),
                                            'views' => $t('visits.step.views', ['n' => $step['n']]),
                                            'heart', 'cart', 'browse' => $n('visits.step.'.$step['kind'], (int) $step['n']),
                                            default => $t('visits.step.'.$step['kind']),
                                        } }}</span>@endif
                                    </span>
                                @endforeach
                            </div>
                            <div class="lp-d-visit-end">
                                @if ($visit['end'] !== null)<b class="lp-d-num">{{ $visit['end']['value'] }}</b><span>{{ $t('visits.end.'.$visit['end']['kind']) }}</span>@else<b>—</b>@endif
                            </div>
                        </div>
                    @empty
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('visits.zero') }}</b><span>{{ $t('visits.zero_text') }}</span></div>
                    @endforelse
                </section>

                {{-- what they save for later --}}
                @php($saved = $this->savedProducts())
                <section class="lp-d-card lp-d-saved" aria-labelledby="sv-title" data-saved>
                    <div class="lp-d-card-head">
                        <div><h2 class="lp-d-card-title" id="sv-title">{{ $t('saved.title') }}</h2><p class="lp-d-card-sub">{{ $t('saved.sub') }}</p></div>
                        @if ($saved !== [])<button class="lp-d-btn lp-d-btn-line lp-d-btn-sm" type="button" wire:click="exportSaved">{{ $t('saved.export') }}</button>@endif
                    </div>
                    @if ($saved === [])
                        <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('saved.zero') }}</b><span>{{ $t('saved.zero_text') }}</span></div>
                    @else
                        <div class="lp-d-shelf">
                            @foreach ($saved as $i => $p)
                                <article class="lp-d-prod">
                                    <div class="lp-d-prod-img">
                                        @if ($p['picture'])<img src="{{ $p['picture'] }}" alt="" loading="lazy">@else<span class="lp-d-tile"></span>@endif
                                        @if ($i === 0)<span class="lp-d-chip lp-d-chip-lime lp-d-num">#1</span>@endif
                                    </div>
                                    <p class="lp-d-prod-name" dir="auto">{{ $p['title'] }}</p>
                                    <p class="lp-d-prod-via">{!! __('admin::shoppers.saved.via', ['saved' => number_format($p['saved']), 'bought' => number_format($p['bought'])]) !!}</p>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
