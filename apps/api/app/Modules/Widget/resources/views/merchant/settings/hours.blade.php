<x-filament-panels::page>
    {{-- Settings · Answering hours (OpeningHours), as the designer's panel lays it out, with times that can be edited. --}}
    @php($t = fn (string $key, array $params = []) => __('widget::shop_settings.hours.'.$key, $params))

    <x-lp::card :title="$t('title')" style="max-width: 640px">
        <x-slot:head><span class="lp-sample">{{ $t('sample') }}</span><span class="lp-tip" tabindex="0" data-tip="{{ $t('tip') }}" aria-label="{{ $t('tip') }}">i</span></x-slot:head>

        <div class="lp-hours">
            @foreach (\App\Modules\Widget\Support\OpeningHours::DAYS as $day)
                @php($name = __("widget::ui.hours.days.{$day}"))
                <span>{{ $name }}</span>
                <x-lp::toggle :model="'data.days.'.$day.'.open'" :label="$name" />
                <span class="lp-time" x-data="{ on: $wire.entangle('data.days.{{ $day }}.open') }" x-bind:class="{ 'is-off': ! on }">
                    <template x-if="on">
                        <span style="display: contents">
                            <input type="text" inputmode="numeric" maxlength="5" placeholder="HH:MM" wire:model="data.days.{{ $day }}.from" dir="ltr" aria-label="{{ $name }} · {{ $t('from') }}">
                            <span aria-hidden="true">–</span>
                            <input type="text" inputmode="numeric" maxlength="5" placeholder="HH:MM" wire:model="data.days.{{ $day }}.until" dir="ltr" aria-label="{{ $name }} · {{ $t('until') }}">
                        </span>
                    </template>
                    <template x-if="! on"><span>—</span></template>
                    @if ($this->problem("days.{$day}"))<span class="lp-set-error" role="alert">{{ $this->problem("days.{$day}") }}</span>@endif
                </span>
            @endforeach
        </div>
        <p class="lp-sample" style="margin: 14px 0 0" dir="auto">{{ $t('clock', ['zone' => $this->data['timezone'] ?? '']) }}</p>

        <x-lp::save :label="$t('save')" :note="false" />
    </x-lp::card>
</x-filament-panels::page>
