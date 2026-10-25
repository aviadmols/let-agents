<x-filament-panels::page>
    {{-- Settings · The assistant (AssistantSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('assistant::shop_settings.'.$key, $params))
    @php($visitor = $this->bounds('assistant.questions_per_visitor_per_day'))
    @php($shop = $this->bounds('assistant.questions_per_shop_per_day'))

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.on">
        <div class="lp-set-state" style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: var(--la-muted); margin-bottom: 12px">
            @if ($status['on'])
                <span class="lp-pill" data-tone="good"><i></i>{{ $t('on') }}</span>
            @else
                <span class="lp-pill"><i></i>{{ $t('off') }}</span>
            @endif
            <span>{{ $t('state', ['asked' => number_format($status['asked']), 'answered' => number_format($status['answered']), 'waiting' => number_format($status['waiting'])]) }}</span>
        </div>

        {{-- With the assistant off, the places wait as they were set, faded. --}}
        <div x-data x-bind:style="$wire.data.on ? '' : 'opacity: .5'">
            <div class="lp-set-label">{{ $t('where') }}</div>
            <x-lp::row :title="$t('on_products')" :sub="$t('on_products_sub')"><x-lp::toggle model="data.on_products" :label="$t('on_products')" /></x-lp::row>
            <x-lp::row :title="$t('on_content')" :sub="$t('on_content_sub')"><x-lp::toggle model="data.on_content" :label="$t('on_content')" /></x-lp::row>
            <x-lp::row :title="$t('on_search')" :sub="$t('on_search_sub', ['example' => $t('on_search_example.'.(\Filament\Facades\Filament::getTenant()?->inIsrael() ? 'israel' : 'abroad'))])"><x-lp::toggle model="data.on_search" :label="$t('on_search')" /></x-lp::row>
        </div>

        <div class="lp-set-label">{{ $t('limits') }}</div>
        <x-lp::row :title="$t('visitor_limit')" :sub="$t('visitor_limit_sub')"><x-lp::toggle model="data.visitor_limit" :label="$t('visitor_limit')" /></x-lp::row>
        {{-- the number per shopper only while the limit is on --}}
        <div x-data x-show="$wire.data.visitor_limit">
        <x-lp::row :title="$t('per_visitor')" :sub="$t('per_visitor_sub')" :tip="$t('per_visitor_tip')">
            <x-lp::num model="data.per_visitor" :min="$visitor['min']" :max="$visitor['max']" :label="$t('per_visitor')" />
        </x-lp::row>
        </div>
        @if ($this->problem('per_visitor'))<span class="lp-set-error" role="alert">{{ $this->problem('per_visitor') }}</span>@endif
        <x-lp::row :title="$t('per_shop')" :sub="$busiest > 0 ? $t('per_shop_sub').'. '.$t('per_shop_busiest', ['n' => number_format($busiest)]) : $t('per_shop_sub')" :tip="$t('per_shop_tip')">
            <x-lp::num model="data.per_shop" :min="$shop['min']" :max="$shop['max']" :step="10" :label="$t('per_shop')" />
        </x-lp::row>
        @if ($this->problem('per_shop'))<span class="lp-set-error" role="alert">{{ $this->problem('per_shop') }}</span>@endif
        <x-lp::row :title="$t('no_answer')" :sub="$t('no_answer_sub')">
            <x-lp::chips model="data.no_answer" :options="__('assistant::shop_settings.no_answer_options')" :sm="true" :label="$t('no_answer')" />
        </x-lp::row>
        <x-lp::row :title="$t('not_sure')" :sub="$t('not_sure_sub')">
            <x-lp::chips model="data.not_sure" :options="__('assistant::shop_settings.not_sure_options')" :sm="true" :label="$t('not_sure')" />
        </x-lp::row>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
