{{-- What it was understood to be about, tag by tag, and what each rests on: the store's words, its picture, or what is known of the work. --}}
@php($t = fn (string $key, array $params = []) => __('enrichment::scan.'.$key, $params))
@php($shown = array_values(array_filter($meaning, fn (array $m): bool => $m['status'] !== 'rejected')))
@php($out = array_values(array_filter($meaning, fn (array $m): bool => $m['status'] === 'rejected')))
<div class="lp-card-t lp-sub-t" data-meaning>
    <h2>{{ $t('meaning') }}</h2>
    <span class="lp-note">{{ $shown === [] ? $t('meaning_none') : $t('meaning_help') }}</span>
</div>
@if ($shown !== [])
    <div class="lp-schema">
        @foreach ($shown as $m)
            <div data-meaning-tag="{{ $m['label'] }}">
                <span>{{ $m['group'] }}</span>
                <span>
                    <strong>{{ $m['label'] }}</strong>
                    <span class="lp-pill" data-tone="{{ $m['weight'] >= 0.7 ? 'good' : '' }}">{{ $t('meaning_weight.'.($m['weight'] >= 0.7 ? 'central' : ($m['weight'] >= 0.4 ? 'part' : 'background'))) }}</span>
                    @if ($m['status'] === 'review')<span class="lp-pill" data-tone="warn">{{ $t('meaning_review') }}</span>@endif
                    @if ($m['team'])<span class="lp-pill" data-tone="good">{{ $t('meaning_team') }}</span>@endif
                    <span class="lp-note">· {{ $t('meaning_sources.'.$m['source']) }}{{ $m['explicit'] ? ' · '.$t('meaning_explicit') : '' }}</span>
                    @if ($m['evidence'])
                        <span class="lp-note" style="display: block; margin-top: 2px">{{ $t('meaning_based') }}: «{{ $m['evidence'] }}»</span>
                    @endif
                    <span style="display: block; margin-top: 4px">
                        @if ($m['status'] === 'review')<button type="button" class="lp-link" wire:click="decideMeaning('{{ $m['id'] }}', true)">{{ $t('meaning_keep') }}</button> · @endif
                        <button type="button" class="lp-link" wire:click="decideMeaning('{{ $m['id'] }}', false)" data-meaning-drop>{{ $t('meaning_drop') }}</button>
                    </span>
                </span>
            </div>
        @endforeach
    </div>
@endif
@if ($out !== [])
    <details style="margin-top: 8px" data-meaning-out>
        <summary class="lp-link">{{ trans_choice('enrichment::scan.meaning_out', count($out), ['n' => count($out)]) }}</summary>
        <div class="lp-schema">
            @foreach ($out as $m)
                <div>
                    <span>{{ $m['group'] }}</span>
                    <span>
                        <s>{{ $m['label'] }}</s>
                        @if ($m['evidence'])<span class="lp-note"> · {{ $t('meaning_based') }}: «{{ $m['evidence'] }}»</span>@endif
                        @if ($m['note'])<span class="lp-note" style="display: block; margin-top: 2px">{{ $t('meaning_why_out') }}: {{ $m['note'] }}</span>@endif
                        <button type="button" class="lp-link" style="display: block; margin-top: 4px" wire:click="decideMeaning('{{ $m['id'] }}', true)" data-meaning-restore>{{ $t('meaning_restore') }}</button>
                    </span>
                </div>
            @endforeach
        </div>
    </details>
@endif
