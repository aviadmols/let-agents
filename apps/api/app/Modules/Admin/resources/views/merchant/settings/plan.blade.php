<x-filament-panels::page>
    {{-- Settings · Plan and billing, as the designer's redesign draws it (PlanSettings; prototype settings.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::plan.'.$key, $params))
    @php($c = fn (string $key, array $params = []) => __('admin::shop_settings.connection.'.$key, $params))
    @php($d = $this->details())
    @php($now = $this->now())
    @php($first = $d['allowance'][0] ?? null)

    <div class="lp-page lp-d" data-plan-page>
        <section class="lp-d-plan-now">
            <div>
                @if ($now['trial'] !== null)<span class="lp-d-chip lp-d-chip-lime">{{ trans_choice('admin::shop_settings.connection.plan_trial_left', $now['trial'], ['count' => $now['trial']]) }}</span>@endif
                <h2>{{ $now['name'] }}</h2>
                <p>
                    @if ($now['ending']){{ $c('plan_ending', ['date' => $now['ending']]) }}
                    @elseif ($now['waiting']){{ $c('plan_waiting') }}
                    @elseif ($now['trial'] !== null){{ $t('trial_text') }}
                    @elseif ($now['free']){{ $t('free_text') }}
                    @elseif ($now['renews']){{ $t('renews', ['date' => $now['renews']]) }}
                    @else{{ $t('cancel_any') }}@endif
                </p>
                @if ($first !== null)
                    <div class="lp-d-usage">
                        <div><span>{{ $first['label'] }}</span><b class="lp-d-num">{{ number_format($first['used']) }} / {{ number_format($first['limit']) }}</b></div>
                        <i><u style="width: {{ max(2, min(100, $first['percent'])) }}%"></u></i>
                    </div>
                    @foreach (array_slice($d['allowance'], 1) as $row)
                        <div class="lp-d-usage lp-d-usage-sm"><div><span>{{ $row['label'] }}</span><b class="lp-d-num">{{ number_format($row['used']) }} / {{ number_format($row['limit']) }}</b></div><i><u style="width: {{ max(2, min(100, $row['percent'])) }}%"></u></i></div>
                    @endforeach
                @elseif ($d['usage'] !== null)
                    <div class="lp-d-usage"><div><span>{{ $c('usage') }}</span><b class="lp-d-num">{{ min(100, $d['usage']['percent']) }}%</b></div><i><u style="width: {{ max(2, min(100, $d['usage']['percent'])) }}%"></u></i></div>
                @endif
            </div>
            @if ($d['plans'] !== [])<a class="lp-d-btn lp-d-btn-lime" href="#plans">{{ $t('choose') }}</a>@endif
        </section>

        @if ($d['plans'] !== [])
            <div class="lp-d-plans" id="plans" data-plans>
                @foreach ($d['plans'] as $plan)
                    <div @class(['lp-d-planc', 'is-mine' => $plan['current']]) data-plan="{{ $plan['code'] }}">
                        <b>{{ $plan['name'] }} @if ($plan['current'])<span class="lp-d-chip lp-d-chip-ink">{{ $c('current_plan') }}</span>@endif</b>
                        <span class="lp-d-price lp-d-num">{{ $plan['price'] }}<small> / {{ $c('per_month') }}</small></span>
                        @if ($plan['lines'] !== [])<ul>@foreach (array_slice($plan['lines'], 0, 4) as $line)<li>{{ $line }}</li>@endforeach</ul>@endif
                        @if ($plan['current'])
                            <span class="lp-d-btn lp-d-btn-ink lp-d-btn-sm is-static">{{ $t('keep', ['plan' => $plan['name']]) }}</span>
                        @elseif ($plan['move'] !== null && $this->canConnect())
                            @php($label = $plan['move'] === 'upgrade' ? $c('upgrade') : ($plan['move'] === 'downgrade' ? $t('switch') : $c('choose')))
                            @if ($plan['url'] !== null)
                                {{-- in the same window: inside the Shopify admin the next step has App Bridge, which takes the window to Shopify's approval --}}
                                <a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $plan['url'] }}" data-plan-move="{{ $plan['move'] }}">{{ $label }}</a>
                            @else
                                <button type="button" class="lp-d-btn lp-d-btn-line lp-d-btn-sm" wire:click="askPlan(@js($plan['code']))" wire:loading.attr="disabled" data-plan-move="{{ $plan['move'] }}">{{ $label }}</button>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <section class="lp-d-panel">
            {{-- the full comparison lives on lets.co.il: a Shopify store pays only through Shopify, so it is never sent there --}}
            @if ($d['woo'])
            <div class="lp-d-set"><div><b>{{ $t('compare') }}</b><span>{{ $t('compare_text') }}</span></div><a class="lp-d-more" href="{{ \App\Modules\Admin\Filament\Merchant\Pages\PlanSettings::PRICING_URL }}" target="_blank" rel="noopener">{{ $t('pricing') }}</a></div>
            @endif
            <div class="lp-d-set"><div><b>{{ $t('billing') }}</b><span>{{ $d['woo'] ? $t('billing_team') : $t('billing_shopify') }}</span></div>@if ($d['plans_url'])<a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $d['plans_url'] }}" data-plans-view>{{ $t('view') }}</a>@endif</div>
            {{-- stopping the payments: Privacy and account cancels the plan with the platform that bills it, in one press --}}
            <div class="lp-d-set" data-plan-cancel><div><b>{{ $t('cancel') }}</b><span>{{ $t('cancel_text') }}</span></div><a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ \App\Modules\Admin\Filament\Merchant\Pages\PrivacySettings::getUrl() }}">{{ $t('cancel_go') }}</a></div>
        </section>
    </div>
</x-filament-panels::page>
