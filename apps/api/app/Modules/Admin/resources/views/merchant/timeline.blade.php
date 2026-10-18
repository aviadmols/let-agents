<x-filament-panels::page>
    {{-- The shop's timeline, as the designer's panel draws it (ShopTimeline). No cost, no model, no mechanism. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::timeline.'.$key, $params))
    @php($tz = 'Asia/Jerusalem')
    @php($all = $this->all())
    @php($counts = \App\Modules\Admin\Support\Timeline::counts($all))
    @php($links = collect(\App\Modules\Admin\Filament\Merchant\Pages\ShopTimeline::KINDS)->mapWithKeys(fn (string $k): array => [$k => $this->link($k)]))

    <div class="lp-page" data-shop-timeline>
        <div class="lp-chips" role="group" aria-label="{{ $t('filter') }}">
            <button type="button" wire:click="pick('')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->kind === ''])>{{ $t('all') }}</button>
            @foreach (\App\Modules\Admin\Filament\Merchant\Pages\ShopTimeline::KINDS as $k)
                @if ($counts[$k] > 0)
                    <button type="button" wire:click="pick('{{ $k }}')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->kind === $k]) data-kind="{{ $k }}">{{ $t('kinds.'.$k) }}</button>
                @endif
            @endforeach
        </div>

        <div class="lp-tl">
            @forelse ($this->byDay() as $day => $entries)
                @php($date = \Illuminate\Support\Carbon::parse($day, $tz))
                <div class="lp-tl-day">{{ $date->isToday() ? $t('today') : ($date->isYesterday() ? $t('yesterday') : '') }} {{ $date->translatedFormat('l j.n') }}</div>
                @foreach ($entries as $e)
                    @php($more = $e->lines !== [] || $links[$e->kind])
                    <div class="lp-tl-item" data-kind="{{ $e->kind }}" data-timeline-entry="{{ $e->key }}" @if ($more) x-data="{ open: false }" @endif>
                        <div class="lp-tl-head" @if ($more) role="button" tabindex="0" @click="open = !open" @keydown.enter="open = !open" :aria-expanded="open.toString()" @endif>
                            <span class="lp-tl-time" dir="ltr">{{ $e->at->copy()->timezone($tz)->format('H:i') }}</span>
                            <span class="lp-tl-t">{{ $e->shopTitle ?? $e->title }}</span>
                            @if ($e->who)<span class="lp-tl-who">{{ $e->who }}</span>@endif
                        </div>
                        @if ($more)
                            <div class="lp-tl-more" x-show="open" x-cloak>
                                @if ($e->lines !== [])
                                    <ul>@foreach ($e->lines as $line)<li>{{ $line }}</li>@endforeach</ul>
                                @endif
                                @if ($links[$e->kind])<a class="lp-link" href="{{ $links[$e->kind] }}">{{ $t('go.'.$e->kind) }}</a>@endif
                            </div>
                        @endif
                    </div>
                @endforeach
            @empty
                <p class="lp-empty">{{ $t('none') }}</p>
            @endforelse
        </div>

        @if ($this->days < 365)
            <div><button type="button" class="lp-link" wire:click="more">{{ $t('more') }}</button></div>
        @endif
    </div>
</x-filament-panels::page>
