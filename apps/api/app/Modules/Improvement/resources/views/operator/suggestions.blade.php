<x-filament-panels::page>
    @php($s = $this->suggestions())
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:3px 10px;border:1px solid #d4d4d8;border-radius:999px;background:#fff;cursor:pointer')
    @php($box = 'padding:12px 14px;border:1px solid #e4e4e7;border-radius:10px;background:#fff;display:grid;gap:8px')

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if ($this->picksShop())
            <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <button type="button" wire:click="reviewNow" wire:loading.attr="disabled" style="{{ $btn }}">{{ __('improvement::ui.review_now') }}</button>
    </div>

    @if ($s === null)
        <x-filament::section>{{ __('improvement::ui.no_shop') }}</x-filament::section>
    @else
        @if ($s['latest'])
            <x-filament::section :heading="__('improvement::ui.latest', ['date' => $s['latest']->day->format('d.m.Y')])" collapsible collapsed>
                <pre style="white-space:pre-wrap;font:inherit;margin:0">{{ $s['latest']->digest }}</pre>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('improvement::ui.pending')">
            @if ($s['pending']->isEmpty())
                <p style="opacity:.7">{{ __('improvement::ui.none_pending') }}</p>
            @else
                <div style="display:grid;gap:12px">
                    @foreach ($s['pending'] as $p)
                        <div style="{{ $box }}">
                            <div><span style="{{ $small }}">{{ __("improvement::ui.kinds.{$p->kind}") }}</span></div>
                            <div style="font-weight:600">{{ $p->title }}</div>
                            @if ($p->kind === 'faq')
                                <div style="white-space:pre-wrap">{{ $p->detail['answer'] ?? '' }}</div>
                            @elseif ($p->kind === 'content_gap')
                                <div>{{ $p->detail['why'] ?? '' }}</div>
                            @endif
                            <div style="{{ $small }}">
                                {{ __('improvement::ui.because') }}
                                @foreach ($p->evidence as $e)
                                    <span>"{{ $e['query'] ?? $e['question'] ?? '' }}" ×{{ $e['searches'] ?? $e['asked'] ?? 0 }}</span>@if (! $loop->last), @endif
                                @endforeach
                            </div>
                            @if ($p->audit_reason)
                                <div style="{{ $small }}">{{ __('improvement::ui.checked') }} {{ $p->audit_reason }}</div>
                            @endif
                            <div style="display:flex;gap:8px">
                                <button type="button" wire:click="approve('{{ $p->id }}')" style="{{ $btn }};background:#111827;color:#fff;border-color:#111827">{{ __("improvement::ui.approve.{$p->kind}") }}</button>
                                <button type="button" wire:click="reject('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.reject') }}</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('improvement::ui.decided')" collapsible collapsed>
            @if ($s['decided']->isEmpty())
                <p style="opacity:.7">{{ __('improvement::ui.none_decided') }}</p>
            @else
                <div style="display:grid;gap:6px">
                    @foreach ($s['decided'] as $p)
                        <div>
                            <span style="{{ $small }}">{{ __("improvement::ui.statuses.{$p->status}") }} · {{ __("improvement::ui.kinds.{$p->kind}") }}</span>
                            {{ $p->title }}
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
