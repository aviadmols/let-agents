{{-- Why a question got the answer it got, and how the team changes how the store answers (merchant ShopQuestions):
     what it was written from, how it was checked, the store's tone and rules. A change is checked before it is kept
     (CheckAnswerChange): code, a reading, and a few questions answered again with it; then the answer as it comes out. --}}
@php($w = fn (string $key, array $params = []) => __('assistant::ui.inbox.why.'.$key, $params))
@php($why = $this->whyFor($row['id']))
@php($may = $this->mayLink())
@php($state = $this->checkState())
<div class="lp-stack" data-why-panel style="margin-top: 12px; padding: 16px; border-radius: 14px; background: var(--la-sunk); gap: 16px">
    {{-- what it was written from --}}
    <div style="display: grid; gap: 6px" data-why-from="{{ $why['from'] }}">
        <b style="font-size: 13px; font-weight: 600">{{ $w('from_title') }}</b>
        <p style="margin: 0" dir="auto">{{ $w('from.'.$why['from']) }}</p>
        @if ($why['sources'] !== [])
            {{-- each source a link: a page of the site opens in a new tab; what the store knows without a page of its
                 own (what the posts say, the team's information) opens the store's knowledge, where it is kept --}}
            <div style="display: grid; gap: 10px" data-why-sources>
                @foreach ($why['sources'] as $source)
                    @php($href = $source['url'] ?: \App\Modules\Assistant\Filament\Merchant\Pages\AboutStore::getUrl())
                    <div style="display: grid; gap: 6px; justify-items: start">
                        <a href="{{ $href }}" @if ($source['url']) target="_blank" rel="noopener" @endif dir="auto" data-why-source="{{ $source['url'] ? 'page' : 'knowledge' }}"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: var(--la-paper); box-shadow: inset 0 0 0 1px rgb(0 0 0 / 12%); color: var(--la-ink); font-size: 13.5px; text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px">
                            @if ($source['url'])
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex: none"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex: none"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 21V5M8 7h7"/></svg>
                            @endif
                            <span>{{ $source['title'] }}</span>
                        </a>
                        {{-- what it says that the answer came from: as kept with the answer, or the nearest lines of it now --}}
                        @if (($source['quote'] ?? '') !== '')
                            <figure style="margin: 0; padding: 8px 12px; border-inline-start: 3px solid var(--la-ink); background: var(--la-paper); border-radius: 0 10px 10px 0; display: grid; gap: 4px" data-why-quote="{{ $source['quote_kind'] }}">
                                <figcaption class="lp-note">{{ $w('quote_'.$source['quote_kind']) }}</figcaption>
                                <blockquote dir="auto" style="margin: 0; white-space: pre-line; font-size: 13.5px; line-height: 1.5">{{ $source['quote'] }}</blockquote>
                            </figure>
                        @endif
                        {{-- the line it is written in, fixed there --}}
                        @include('assistant::merchant.source-lines', ['lines' => $this->linesOf($source, ($source['quote'] ?? '') !== '' ? $source['quote'] : $why['answer']), 'may' => $may])
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($why['checked'])
        <div style="display: grid; gap: 6px" data-why-checked="{{ $why['checked'] }}">
            <b style="font-size: 13px; font-weight: 600">{{ $w('checked_title') }}</b>
            <p style="margin: 0">{{ $w('checked.'.$why['checked']) }}</p>
        </div>
    @endif

    {{-- after a change was kept: the answer as it comes out with it --}}
    @if (isset($this->updated[$row['id']]))
        <div style="display: grid; gap: 6px; padding: 12px; border-radius: 12px; background: var(--la-paper)" data-why-updated>
            <b style="font-size: 13px; font-weight: 600">{{ $w('updated') }}</b>
            <p style="margin: 0; white-space: pre-line" dir="auto">{{ $this->updated[$row['id']] }}</p>
            <span class="lp-note">{{ $why['rules_live'] || $why['rules'] === [] ? $w('updated_note') : $w('updated_note_soon') }}</span>
        </div>
    @endif

    @if ($why['search'])
        {{-- how the store's assistant speaks: its tone and its rules --}}
        <div style="display: grid; gap: 6px" data-why-tone>
            <b style="font-size: 13px; font-weight: 600">{{ $w('voice_title') }}</b>
            <p style="margin: 0">{{ $w('tone_now', ['tone' => $why['tone']]) }}@if ($why['note'] !== '') · <span dir="auto">{{ $w('note_now', ['note' => $why['note']]) }}</span>@endif</p>
        </div>

        <div style="display: grid; gap: 6px" data-why-rules>
            <b style="font-size: 13px; font-weight: 600">{{ $w('rules_title') }}</b>
            @forelse ($why['rules'] as $rule)
                <div style="display: flex; gap: 10px; align-items: baseline" data-rule>
                    <span dir="auto" style="flex: 1">· {{ $rule['text'] }}</span>
                    @if ($may)
                        <button type="button" class="lp-link" wire:click="startRule(@js($rule['id']))">{{ $w('rule_edit') }}</button>
                        <button type="button" class="lp-link" wire:click="removeRule(@js($rule['id']))" wire:confirm="{{ $w('rule_remove_confirm') }}">{{ $w('rule_remove') }}</button>
                    @endif
                </div>
            @empty
                <p class="lp-note" style="margin: 0">{{ $w('rules_none') }}</p>
            @endforelse
            @if (! $why['rules_live'])
                <p class="lp-note" style="margin: 0" data-rules-soon>{{ $w('rules_soon') }}</p>
            @endif
        </div>
    @else
        <p class="lp-note" style="margin: 0">{{ $w('only_search') }}</p>
    @endif

    @if ($may && $this->changing === null)
        <div class="lp-actions">
            @if ($row['state'] !== 'off')
                <button type="button" class="lp-btn lp-btn-sm lp-btn-primary" wire:click="writeOwn(@js($row['id']))">{{ $w('fix_answer') }}</button>
            @endif
            @if ($why['search'])
                <button type="button" class="lp-btn lp-btn-sm" wire:click="startRule" data-add-rule>{{ $w('rule_add') }}</button>
                <button type="button" class="lp-btn lp-btn-sm" wire:click="startTone" data-change-tone>{{ $w('tone_change') }}</button>
            @endif
        </div>
    @endif

    {{-- the change being written, checked, and kept --}}
    @if ($may && $this->changing !== null)
        <div class="lp-stack" style="gap: 10px; padding: 14px; border-radius: 12px; background: var(--la-paper)" data-change="{{ $this->changing }}">
            @if ($this->changing === \App\Modules\Assistant\Actions\CheckAnswerChange::RULE)
                <b style="font-size: 13px; font-weight: 600">{{ $this->change['replaces'] ? $w('rule_editing') : $w('rule_add') }}</b>
                <textarea class="lp-textarea" wire:model="change.text" rows="2" maxlength="{{ (int) \App\Core\Facades\Settings::get('assistant.answer_rule_chars') }}" placeholder="{{ $w('rule_placeholder') }}" @disabled($state && $state['status'] === 'checking')></textarea>
                <span class="lp-note">{{ $w('rule_help') }}</span>
            @else
                <b style="font-size: 13px; font-weight: 600">{{ $w('tone_change') }}</b>
                <div class="lp-chips">
                    @foreach (\App\Modules\Assistant\Filament\Merchant\Pages\AssistantSettings::TONES as $style)
                        <button type="button" wire:click="$set('change.style', @js($style))" @class(['lp-chip', 'is-on' => $this->change['style'] === $style])>{{ __('assistant::shop_settings.tone_options.'.$style) }}</button>
                    @endforeach
                </div>
                <textarea class="lp-textarea" wire:model="change.note" rows="2" maxlength="{{ \App\Modules\Assistant\Filament\Merchant\Pages\AssistantSettings::TONE_NOTE_CHARS }}" placeholder="{{ __('assistant::shop_settings.tone_note_placeholder') }}"></textarea>
                <span class="lp-note">{{ __('assistant::shop_settings.tone_note_sub') }}</span>
            @endif

            @if ($state === null || $state['status'] === 'failed' || $state['status'] === 'applied')
                <div class="lp-actions">
                    <button type="button" class="lp-btn lp-btn-sm lp-btn-primary" wire:click="checkChange" wire:loading.attr="disabled" wire:target="checkChange" data-check-change>{{ $w('check') }}</button>
                    <button type="button" class="lp-link" wire:click="cancelChange">{{ $w('cancel') }}</button>
                </div>
                @if ($state && $state['status'] === 'failed')
                    <p class="lp-note" style="margin: 0; color: var(--la-bad)" data-check-failed>{{ $w('refused.later') }}</p>
                @endif
            @elseif ($state['status'] === 'checking')
                {{-- asked again every two seconds until the check is done --}}
                <p class="lp-note" style="margin: 0" wire:poll.2s data-checking>{{ $w('checking', ['n' => 1 + (int) \App\Core\Facades\Settings::get('assistant.answer_change_questions', $this->shop)]) }}</p>
            @else
                <div class="lp-stack" style="gap: 10px" data-check-result="{{ $state['passed'] ? 'passed' : 'stopped' }}">
                    @if (($state['verdict'] ?? 'ok') !== 'ok')
                        <p style="margin: 0"><span class="lp-pill" data-tone="bad"><i></i>{{ $w('verdicts.'.$state['verdict']) }}</span></p>
                        @if (($state['why'] ?? '') !== '')<p style="margin: 0" dir="auto">{{ $state['why'] }}</p>@endif
                        @foreach ((array) ($state['conflicts'] ?? []) as $conflict)<p class="lp-note" style="margin: 0" dir="auto">· {{ $w('conflict_page', ['title' => $conflict['title']]) }}</p>@endforeach
                        @if (($state['suggestion'] ?? '') !== '')<p style="margin: 0" dir="auto"><b style="font-weight: 600">{{ $w('suggestion') }}</b> {{ $state['suggestion'] }}</p>@endif
                        @if ($state['verdict'] === 'fact')
                            <a class="lp-link" href="{{ \App\Modules\Assistant\Filament\Merchant\Pages\AboutStore::getUrl() }}">{{ $w('add_info') }}</a>
                        @endif
                    @else
                        <p style="margin: 0"><span class="lp-pill" data-tone="{{ $state['passed'] ? 'good' : 'bad' }}"><i></i>{{ $state['passed'] ? $w('passed') : $w('not_passed') }}</span></p>
                        @if (($state['none'] ?? false))<p class="lp-note" style="margin: 0">{{ $w('no_questions') }}</p>@endif
                        @foreach ((array) $state['items'] as $item)
                            <div style="display: grid; gap: 6px; padding: 10px 12px; border-radius: 10px; background: var(--la-sunk); {{ $item['ok'] ? '' : 'box-shadow: inset 0 0 0 1.5px var(--la-bad);' }}" data-tried="{{ $item['ok'] ? 'ok' : 'broke' }}">
                                <b dir="auto" style="font-weight: 600">{{ $item['question'] }}@if ($item['mine']) <span class="lp-note">· {{ $w('this_question') }}</span>@endif</b>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px">
                                    <div><span class="lp-note">{{ $w('before') }}</span><p style="margin: 2px 0 0; white-space: pre-line" dir="auto">{{ $item['before'] !== '' ? $item['before'] : $w('no_answer') }}</p></div>
                                    <div><span class="lp-note">{{ $w('after') }}</span><p style="margin: 2px 0 0; white-space: pre-line" dir="auto">{{ $item['after'] !== '' ? $item['after'] : $w('no_answer') }}</p></div>
                                </div>
                                @if (! $item['ok'])<span class="lp-note" style="color: var(--la-bad)">{{ $w('broke') }}</span>@endif
                            </div>
                        @endforeach
                    @endif
                    <div class="lp-actions">
                        @if ($state['passed'])
                            <button type="button" class="lp-btn lp-btn-sm lp-btn-primary" wire:click="saveChange" wire:loading.attr="disabled" wire:target="saveChange" data-save-change>{{ $w('save') }}</button>
                        @else
                            <button type="button" class="lp-btn lp-btn-sm" wire:click="$set('checkId', null)">{{ $w('edit_again') }}</button>
                        @endif
                        <button type="button" class="lp-link" wire:click="cancelChange">{{ $w('cancel') }}</button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
