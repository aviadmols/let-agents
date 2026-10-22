{{-- The first-run screens' heading row, as the designer's panel draws it: the title and its line, the four tabs beside them. --}}
@php($t = fn (string $key, array $params = []) => __('admin::onboarding.'.$key, $params))
<header class="lp-head" data-onboarding-head>
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $t('subheading') }}</p>
    </div>
    <div class="lp-tabs" role="tablist">
        @foreach (\App\Modules\Admin\Filament\Merchant\Pages\Onboarding::STEPS as $n => $key)
            @continue($n < $first)
            <button type="button" role="tab" @class(['is-on' => $step === $n]) aria-selected="{{ $step === $n ? 'true' : 'false' }}"
                wire:click="$set('step', {{ $n }})" data-tab="{{ $key }}">{{ $t('tabs.'.$key) }}</button>
        @endforeach
    </div>
</header>
