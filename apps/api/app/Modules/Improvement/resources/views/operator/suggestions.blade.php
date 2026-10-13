<x-filament-panels::page>
    @php($s = $this->suggestions())
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:3px 10px;border:1px solid #d4d4d8;border-radius:999px;background:#fff;cursor:pointer')
    @php($dark = $btn.';background:#111827;color:#fff;border-color:#111827')
    @php($box = 'padding:12px 14px;border:1px solid #e4e4e7;border-radius:10px;background:#fff;display:grid;gap:8px')
    @php($field = 'width:100%;padding:8px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;line-height:1.6')
    @php($chip = 'display:inline-block;padding:2px 10px;border-radius:999px;background:rgba(127,127,127,.12);font-size:12px')

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

        <x-filament::section :heading="__('improvement::ui.pending')" :description="__('improvement::ui.pending_about')">
            @if ($s['pending']->isEmpty())
                <p style="opacity:.7">{{ __('improvement::ui.none_pending') }}</p>
            @else
                <div style="display:grid;gap:12px">
                    @foreach ($s['pending'] as $p)
                        <div style="{{ $box }}" data-proposal="{{ $p->kind }}">
                            <div><span style="{{ $small }}">{{ __("improvement::ui.kinds.{$p->kind}") }}</span></div>
                            <div style="font-weight:600">{{ $p->title }}</div>

                            @if ($p->kind === 'faq')
                                {{-- The drafted answer, the team's to finish: what is published is what is in the box. --}}
                                <label style="{{ $small }}">{{ __('improvement::ui.answer.label') }}</label>
                                @if ($this->drafting($p->id))
                                    <div style="{{ $small }}" wire:poll.4s>{{ __('improvement::ui.answer.writing_now') }}</div>
                                @else
                                    <textarea wire:model="drafts.{{ $p->id }}" rows="5" style="{{ $field }}"></textarea>
                                    @php($ai = $p->detail['ai'] ?? null)
                                    @if ($ai)
                                        @if (($ai['problems'] ?? []) !== [])
                                            <div style="padding:8px 10px;border-radius:8px;background:rgba(235,104,52,.1);font-size:13px" data-problems>
                                                <div style="font-weight:600">{{ __('improvement::ui.answer.problems') }}</div>
                                                <ul style="margin:4px 0 0;padding-inline-start:18px">
                                                    @foreach ($ai['problems'] as $problem)
                                                        <li>{{ $problem }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @else
                                            <div style="{{ $small }}">{{ __('improvement::ui.answer.checked_ok') }}</div>
                                        @endif
                                        @if (($ai['sources'] ?? []) !== [])
                                            <div style="{{ $small }}">{{ __('improvement::ui.answer.sources') }} {{ implode(' · ', $ai['sources']) }}</div>
                                        @endif
                                    @else
                                        <div style="{{ $small }}">{{ __('improvement::ui.answer.about') }}</div>
                                    @endif
                                @endif
                            @elseif ($p->kind === 'content_gap')
                                <div>{{ $p->detail['why'] ?? '' }}</div>
                                @if ($p->post === null)
                                    @if ($this->drafting($p->id))
                                        <div style="{{ $small }}" wire:poll.5s>{{ __('improvement::ui.post.writing_now') }}</div>
                                    @endif
                                @else
                                    <label style="{{ $small }}">{{ __('improvement::ui.post.title') }}</label>
                                    <input type="text" wire:model="titles.{{ $p->id }}" style="{{ $field }}">
                                    <label style="{{ $small }}">{{ __('improvement::ui.post.body') }}</label>
                                    <textarea wire:model="drafts.{{ $p->id }}" rows="14" style="{{ $field }};font-family:inherit"></textarea>
                                    @if ($p->post->products !== [])
                                        <div style="{{ $small }}">{{ __('improvement::ui.post.products', ['count' => count($p->post->products)]) }}</div>
                                    @endif
                                    @if ($p->post->problems !== [])
                                        <div style="padding:8px 10px;border-radius:8px;background:rgba(235,104,52,.1);font-size:13px" data-problems>
                                            <div style="font-weight:600">{{ __('improvement::ui.post.problems') }}</div>
                                            <ul style="margin:4px 0 0;padding-inline-start:18px">
                                                @foreach ($p->post->problems as $problem)
                                                    <li>{{ $problem }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @else
                                        <div style="{{ $small }}">{{ __('improvement::ui.post.checked_ok') }}</div>
                                    @endif
                                @endif
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

                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                @if ($p->kind === 'content_gap' && $p->post === null)
                                    @unless ($this->drafting($p->id))
                                        <button type="button" wire:click="draftPost('{{ $p->id }}')" style="{{ $dark }}">{{ __('improvement::ui.post.write') }}</button>
                                    @endunless
                                @else
                                    <button type="button" wire:click="approve('{{ $p->id }}')" style="{{ $dark }}">{{ __("improvement::ui.approve.{$p->kind}") }}</button>
                                    @if ($p->kind === 'faq' && ! $this->drafting($p->id))
                                        <button type="button" wire:click="draftAnswer('{{ $p->id }}')" style="{{ $btn }}">{{ __(isset($p->detail['ai']) ? 'improvement::ui.answer.rewrite' : 'improvement::ui.answer.write') }}</button>
                                    @endif
                                    @if ($p->kind === 'content_gap' && ! $this->drafting($p->id))
                                        <button type="button" wire:click="draftPost('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.post.rewrite') }}</button>
                                    @endif
                                @endif
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
                            @if ($p->status === 'applied' && $p->kind === 'content_gap' && $p->post?->status === 'published')
                                <a href="{{ $this->guideUrl($p->post) }}" target="_blank" rel="noopener" style="{{ $chip }}">{{ __('improvement::ui.post.open') }} ↗</a>
                            @elseif ($p->status === 'applied' && $p->kind === 'faq')
                                <span style="{{ $chip }}">{{ __('improvement::ui.answer.live') }}</span>
                            @endif
                            @if ($p->status === 'applied' && $p->kind !== 'synonym')
                                <button type="button" wire:click="reject('{{ $p->id }}')" style="{{ $btn }}">{{ __('improvement::ui.take_down') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
