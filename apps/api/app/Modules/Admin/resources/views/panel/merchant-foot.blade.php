{{--
    The foot of the sidebar (the redesign, 11.2026): the plan, with the trial's days left and the way to choose one, and the
    language switch, always in sight (EN / עב), remembered on the person (SwitchLocaleController).
--}}
@php($t = fn (string $key, array $params = []) => __('admin::panels.top.'.$key, $params))
<div class="lp-side-foot">
    @if ($plan)
        <div class="lp-plan" data-plan>
            <b>{{ $plan['trial'] ? $t('plan_trial', ['plan' => $plan['name']]) : $plan['name'] }}</b>
            @if ($plan['trial'])
                <span>{{ trans_choice('admin::panels.top.trial_left', $plan['days'], ['n' => $plan['days']]) }}</span>
                <div class="lp-plan-bar" aria-hidden="true"><i style="width: {{ $plan['used'] }}%"></i></div>
            @endif
            @if ($plan['url'])<a href="{{ $plan['url'] }}">{{ $t($plan['trial'] ? 'choose_plan' : 'see_plan') }}</a>@endif
        </div>
    @endif
    <nav class="lp-side-lang" aria-label="{{ __('admin::panels.locale.label') }}">
        @foreach ($locales as $locale)
            @if ($locale === $current)
                <span aria-current="true" lang="{{ $locale }}">{{ __("admin::panels.locale.short.{$locale}", [], $locale) }}</span>
            @else
                <a href="{{ route('admin.locale', ['locale' => $locale]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}">{{ __("admin::panels.locale.short.{$locale}", [], $locale) }}</a>
            @endif
        @endforeach
    </nav>
</div>
