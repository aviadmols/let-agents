{{-- The home's heading row, as the designer's panel draws it: the greeting, and the period beside it. --}}
@php($t = fn (string $key, array $params = []) => __('admin::home.'.$key, $params))
<header class="lp-head" data-home-head>
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subheading }}</p>
    </div>
    <div class="lp-period" x-data="{ open: false, from: @js($this->from), to: @js($this->to) }" @keydown.escape.window="open = false" @click.outside="open = false">
        <div class="lp-tabs" role="group" aria-label="{{ $t('period') }}">
            @foreach (\App\Modules\Admin\Filament\Merchant\Pages\ShopOverview::PERIODS as $days)
                <button type="button" wire:click="setDays({{ $days }})" @class(['is-on' => $this->days === $days]) aria-pressed="{{ $this->days === $days ? 'true' : 'false' }}">{{ $t('days', ['n' => $days]) }}</button>
            @endforeach
            <button type="button" @click="open = !open" @class(['is-on' => $this->days === 0]) aria-expanded="false" :aria-expanded="open.toString()" data-period-custom>
                {{ $this->days === 0 && $this->from !== '' ? collect([$this->from, $this->to])->unique()->map(fn (string $d): string => \Illuminate\Support\Carbon::parse($d)->format('j.n'))->implode('–') : $t('dates') }}
            </button>
        </div>
        <div class="lp-pop" x-show="open" x-cloak x-transition.opacity>
            <div class="lp-chips">
                @foreach (['today' => [0, 0], 'yesterday' => [1, 1], 'week' => [now()->dayOfWeek, 0], 'month' => [now()->day - 1, 0]] as $preset => [$back, $end])
                    <button type="button" class="lp-chip lp-chip-sm" @click="from = @js(now()->subDays($back)->toDateString()); to = @js(now()->subDays($end)->toDateString())">{{ $t('presets.'.$preset) }}</button>
                @endforeach
                <button type="button" class="lp-chip lp-chip-sm" @click="from = @js(now()->subMonthNoOverflow()->startOfMonth()->toDateString()); to = @js(now()->subMonthNoOverflow()->endOfMonth()->toDateString())">{{ $t('presets.last_month') }}</button>
                <button type="button" class="lp-chip lp-chip-sm" @click="from = @js(now()->startOfYear()->toDateString()); to = @js(now()->toDateString())">{{ $t('presets.year') }}</button>
            </div>
            <div class="lp-dates">
                <label>{{ $t('from') }}<input type="date" x-model="from" max="{{ now()->toDateString() }}"></label>
                <label>{{ $t('to') }}<input type="date" x-model="to" max="{{ now()->toDateString() }}"></label>
            </div>
            <div class="lp-pop-foot">
                <button type="button" class="lp-btn lp-btn-sm" @click="open = false">{{ $t('cancel') }}</button>
                <button type="button" class="lp-btn lp-btn-sm lp-btn-primary" :disabled="!from || !to || to < from" @click="$wire.setRange(from, to); open = false">{{ $t('show') }}</button>
            </div>
        </div>
    </div>
</header>
