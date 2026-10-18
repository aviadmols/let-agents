<x-filament-panels::page>
    {{-- The shop's home, as the designer's panel lays it out (ShopOverview). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::home.'.$key, $params))
    @php($home = \App\Modules\Admin\Filament\Merchant\Pages\ShopOverview::class)
    @php($steps = $this->steps())
    @php($done = collect($steps)->where('done', true)->count())
    @php($cards = $this->cards())
    @php($numbers = $this->numbers())
    @php($attention = $this->attention())

    <div class="lp-page" data-home>
        {{-- the five first steps, until they are done; then one closing line, until hidden --}}
        @if ($steps !== [] && ! ($done === count($steps) && $this->stepsHidden()))
            <section class="lp-card" data-first-steps>
                @if ($done === count($steps))
                    <div class="lp-wins-done">
                        <span class="lp-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14a7 7 0 0 1-14 0zM5 21h14a7 7 0 0 0-14 0z" fill="currentColor"/></svg></span>
                        <div style="flex: 1"><b>{{ $t('steps.all_done') }}</b><div class="lp-note">{{ $t('steps.all_done_body') }}</div></div>
                        <button type="button" class="lp-link" wire:click="hideSteps">{{ $t('steps.hide') }}</button>
                    </div>
                @else
                    @php($next = collect($steps)->firstWhere('done', false)?->key)
                    <div class="lp-card-t" style="margin-block-end: 8px">
                        <h2>{{ $t('steps.title', ['n' => count($steps)]) }}</h2>
                        <span class="lp-note">{{ $t('steps.progress', ['done' => $done, 'all' => count($steps)]) }}</span>
                    </div>
                    <div class="lp-bar"><i style="width: {{ (int) round(100 * $done / max(1, count($steps))) }}%"></i></div>
                    <ol class="lp-wins">
                        @foreach ($steps as $i => $step)
                            <li @class(['lp-win', 'is-done' => $step->done, 'is-next' => $step->key === $next]) data-step="{{ $step->key }}" data-done="{{ $step->done ? '1' : '0' }}">
                                <span class="lp-win-n">@if ($step->done)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="sr-only">{{ $t('steps.done') }}</span>@else{{ $i + 1 }}@endif</span>
                                <div>
                                    <div class="lp-win-t">{{ $step->title }}</div>
                                    <div class="lp-win-s">{{ $step->body }}</div>
                                </div>
                                @if (! $step->done && $step->url)
                                    <a href="{{ $step->url }}" @class(['lp-btn lp-btn-sm', 'lp-btn-primary' => $step->key === $next])
                                       @if ($step->external) target="_blank" rel="noopener" @if ($step->key === 'look') wire:click="looked" @endif @endif>{{ $step->button }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif

        {{-- four cards: what shoppers asked, the month's AI use, the products and the pages read --}}
        @if ($cards !== [])
            <section class="lp-grid-4" data-cards>
                @foreach ($cards as $card)
                    <div class="lp-stat" data-card="{{ $card->key }}" @if ($card->tone) data-tone="{{ $card->tone }}" @endif>
                        <span class="lp-stat-l">{{ $card->label }}</span>
                        <span class="lp-stat-n"><bdi>{{ $card->value }}</bdi>@if ($card->of) <small>{{ $card->of }}</small>@endif</span>
                        @if ($card->bar !== null)<div class="lp-bar" @if ($card->tone) data-tone="{{ $card->tone }}" @endif><i style="width: {{ $card->bar }}%"></i></div>@endif
                        <span class="lp-stat-d">
                            @if ($card->change)@php($d = $home::delta($card->change[0], $card->change[1]))<span class="lp-delta" data-tone="{{ $d['tone'] }}">{{ $d['text'] }}</span>@endif
                            @if ($card->pill)<span class="lp-pill" data-tone="{{ $card->tone === '' ? 'good' : $card->tone }}"><i></i>{{ $card->pill }}</span>@endif
                            @if ($card->note)<span>{{ $card->note }}</span>@endif
                        </span>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- the period's numbers against the period before --}}
        @if ($numbers)
            <section class="lp-grid-4" aria-label="{{ $t('numbers.title') }}" data-numbers>
                @foreach ([
                    'visitors' => [$numbers['visitors'], false],
                    'adds' => [$numbers['adds'], false],
                    'orders' => [$numbers['orders'], false],
                    'revenue' => [$numbers['revenue'], true],
                ] as $key => [$pair, $money])
                    @php($d = $home::delta($pair['now'], $pair['before']))
                    <div class="lp-stat" data-number="{{ $key }}">
                        <span class="lp-stat-l">{{ $t('numbers.'.$key) }}</span>
                        <span class="lp-stat-n"><bdi>{{ $money ? $home::money($pair['now'], $numbers['currency']) : number_format($pair['now']) }}</bdi></span>
                        <span class="lp-stat-d"><span class="lp-delta" data-tone="{{ $d['tone'] }}">{{ $d['text'] }}</span></span>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- what needs the manager --}}
        <section class="lp-card" data-attention>
            <div class="lp-card-t">
                <h2>{{ $t('attention.title') }}</h2>
                <a class="lp-link" href="{{ $this->inboxUrl() }}">{{ $t('attention.all') }}</a>
            </div>
            @forelse ($attention as $notice)
                <a @if ($notice->url) href="{{ $notice->url }}" @endif class="lp-row" data-notice="{{ $notice->key }}">
                    <span class="lp-pill" data-tone="{{ match ($notice->tone) { 'bad' => 'bad', 'warn' => 'warn', default => 'good' } }}"><i></i>{{ $notice->count !== null ? number_format($notice->count) : __('admin::home.kinds.'.$notice->kind) }}</span>
                    <span class="lp-grow">
                        <span class="lp-row-t">{{ $notice->title }}</span>
                        @if ($notice->body)<span class="lp-row-s">{{ $notice->body }}</span>@endif
                    </span>
                </a>
            @empty
                <p class="lp-empty">{{ $t('attention.none') }}</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
