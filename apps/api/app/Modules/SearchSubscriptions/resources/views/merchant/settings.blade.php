<x-filament-panels::page>
    {{-- Settings · Search Subscriptions (SearchSubscriptionsSettings): the switch and its choices, then "Searches people follow". --}}
    @php($t = fn (string $key, array $params = []) => __('search_subscriptions::merchant.'.$key, $params))
    @php($n = $this->numbers())
    @php($top = $this->followed())

    <div style="display: grid; gap: 16px" data-search-subscriptions>
        <x-lp::card :title="$t('title')" :help="$t('help')" master="data.enabled">
            <x-lp::row :title="$t('placement')" :sub="$t('placement_sub')">
                <x-lp::chips model="data.placement" :options="$t('placements')" :sm="true" :label="$t('placement')" />
            </x-lp::row>
            <x-lp::row :title="$t('send_day')" :sub="$t('send_day_sub', ['hour' => $this->sendHour()])">
                <x-lp::chips model="data.send_day" :options="$t('days')" :sm="true" :label="$t('send_day')" />
            </x-lp::row>
            <x-lp::row :title="$t('logo')" :sub="$t('logo_sub')">
                <x-lp::field model="data.logo_url" :ltr="true" :width="320" type="url" placeholder="https://…/logo.png" :aria-label="$t('logo')" />
                @if ($this->problem('logo_url'))<span class="lp-set-error" role="alert">{{ $this->problem('logo_url') }}</span>@endif
            </x-lp::row>
            <x-lp::row :title="$t('reply_to')" :sub="$t('reply_to_sub')">
                <x-lp::field model="data.reply_to" :ltr="true" :width="320" type="email" placeholder="hello@store.com" :aria-label="$t('reply_to')" />
                @if ($this->problem('reply_to'))<span class="lp-set-error" role="alert">{{ $this->problem('reply_to') }}</span>@endif
            </x-lp::row>
        </x-lp::card>

        <section class="lp-card" data-followed>
            <div class="lp-card-t"><h2>{{ $t('followed.title') }}</h2></div>
            <p class="lp-set-help">{{ $t('followed.help', ['days' => \App\Modules\SearchSubscriptions\Filament\Merchant\Pages\SearchSubscriptionsSettings::DAYS]) }}</p>
            <div class="lp-grid-3">
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('followed.following') }}</span><span class="lp-stat-n">{{ number_format($n['following']) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('followed.signups') }}</span><span class="lp-stat-n">{{ number_format($n['signups']) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('followed.sent') }}</span><span class="lp-stat-n">{{ number_format($n['sent']) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('followed.opened') }}</span><span class="lp-stat-n">{{ number_format($n['opened']) }}</span>@if ($n['sent'] > 0)<span class="lp-stat-d">{{ $t('followed.of_sent', ['n' => round(100 * $n['opened'] / $n['sent'])]) }}</span>@endif</div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('followed.clicked') }}</span><span class="lp-stat-n">{{ number_format($n['clicked']) }}</span>@if ($n['sent'] > 0)<span class="lp-stat-d">{{ $t('followed.of_sent', ['n' => round(100 * $n['clicked'] / $n['sent'])]) }}</span>@endif</div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('followed.revenue') }}</span><span class="lp-stat-n">{{ $this->money($n['revenue']) }}</span></div>
            </div>

            @if ($top === [])
                <p class="lp-note" style="margin: 16px 0 0" data-followed-none>{{ $t('followed.none') }}</p>
            @else
                <div style="margin-top: 16px" data-followed-list>
                    @foreach ($top as $row)
                        <div class="lp-row">
                            <span style="flex: 1; min-width: 0">{!! str_replace('%Q%', '<bdi dir="auto" style="font-weight: 600">'.e($row['query']).'</bdi>', e(trans_choice('search_subscriptions::merchant.followed.row', $row['people'], ['count' => number_format($row['people']), 'query' => '%Q%']))) !!}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <x-lp::save />
</x-filament-panels::page>
