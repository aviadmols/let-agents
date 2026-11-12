<x-filament-panels::page>
    {{-- Settings · Store report (ReportSettings): how often, when the next goes out, who gets it, one now, an example. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::report.settings.'.$key, $params))
    @php($frequency = $this->frequency())
    @php($owner = $this->owner())
    @php($next = $this->next())

    <div style="display: grid; gap: 16px; max-width: 720px" data-report-settings>
        <x-lp::card :title="$t('title')" :help="$t('lede')">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between">
                <div style="display: grid; gap: 4px">
                    <b style="font-weight: 600">{{ $t('how_often') }}</b>
                    <span class="lp-note" data-report-next>
                        @if ($next){{ $t('next', ['when' => $next->locale(app()->getLocale())->translatedFormat('l, j.n.Y, H:i')]) }} · {{ $t('when.'.$frequency) }}@else{{ $t('none') }}@endif
                    </span>
                </div>
                <div role="group" aria-label="{{ $t('how_often') }}" style="display: flex; flex-wrap: wrap; gap: 6px" data-report-frequency>
                    @foreach (\App\Modules\Admin\Support\StoreReport::FREQUENCIES as $option)
                        <button type="button" class="lp-btn lp-btn-sm {{ $frequency === $option ? 'lp-btn-primary' : '' }}" aria-pressed="{{ $frequency === $option ? 'true' : 'false' }}"
                            @if ($owner) wire:click="choose(@js($option))" @else disabled @endif data-frequency="{{ $option }}">{{ $t('options.'.$option) }}</button>
                    @endforeach
                </div>
            </div>
            @unless ($owner)<p class="lp-note" style="margin: 10px 0 0">{{ $t('owners_only') }}</p>@endunless

            @if ($frequency === 'off')
                <p class="lp-set-help" style="margin: 14px 0 0" data-report-off>{{ $t('off_note') }}</p>
            @else
                <div style="display: grid; gap: 4px; margin-top: 18px" data-report-who>
                    <b style="font-weight: 600">{{ $t('who') }}</b>
                    <span class="lp-note">{{ $t('who_note') }}: <span dir="ltr">{{ implode(', ', $this->recipients()) }}</span></span>
                </div>
            @endif

            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-top: 18px">
                <div style="display: grid; gap: 4px">
                    <b style="font-weight: 600">{{ $t('send') }}</b>
                    <span class="lp-note">{{ $t('send_note') }}</span>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button type="button" class="lp-btn lp-btn-sm" wire:click="sendNow" wire:loading.attr="disabled" data-report-send>{{ $t('send_button') }}</button>
                    <a class="lp-btn lp-btn-sm" href="{{ $this->exampleUrl() }}" target="_blank" rel="noopener" data-report-example>{{ $t('example') }}</a>
                </div>
            </div>

            <p class="lp-note" style="margin: 16px 0 0">{{ $t('fine') }}</p>
        </x-lp::card>
    </div>
</x-filament-panels::page>
