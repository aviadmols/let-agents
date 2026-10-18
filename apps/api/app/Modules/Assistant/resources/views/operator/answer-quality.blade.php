<x-filament-panels::page>
    {{-- Answer quality (AnswerQuality): why answers failed, with how each was answered and what it cost. --}}
    @php($t = fn (string $key, array $params = []) => __('assistant::ui.quality.'.$key, $params))
    @php($counts = $this->counts())
    @php($list = $this->rows())
    @php($small = 'font-size:12px;opacity:.7')
    @php($chip = 'display:inline-flex;gap:6px;align-items:center;font-size:12px;padding:3px 10px;border-radius:999px;border:1px solid rgba(127,127,127,.3);background:transparent;cursor:pointer')
    @php($bad = 'background:rgba(220,60,60,.15);border-color:transparent')
    @php($reasons = \App\Modules\Assistant\Support\AnswerFailure::REASONS)

    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <select wire:model.live="shop" style="font-size:13px;border-radius:8px;padding:4px 8px" aria-label="{{ $t('shop') }}">
            <option value="">{{ $t('all_shops') }}</option>
            @foreach ($this->shops() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        <select wire:model.live="days" style="font-size:13px;border-radius:8px;padding:4px 8px" aria-label="{{ $t('period') }}">
            @foreach ([1, 7, 30, 90] as $d)<option value="{{ $d }}">{{ $t('days', ['n' => $d]) }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.400ms="find" placeholder="{{ $t('find') }}" style="font-size:13px;border-radius:8px;padding:4px 8px;min-width:200px">
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
        @foreach ([
            'asks' => number_format($counts['asks']),
            'model' => number_format($counts['model']),
            'failed' => number_format($counts['failed']),
            'cost' => '$'.number_format($counts['cost'], 4),
            'per_answer' => $counts['per_answer'] === null ? '—' : '$'.number_format($counts['per_answer'], 5),
            'ms' => $counts['ms'] === null ? '—' : number_format($counts['ms']).' ms',
        ] as $key => $value)
            <x-filament::section compact><div style="{{ $small }}">{{ $t('stats.'.$key) }}</div><div style="font-size:20px;font-weight:700" dir="ltr">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:6px">
        <button type="button" wire:click="pick('failed')" style="{{ $chip }};{{ $this->failure === 'failed' ? 'font-weight:700;border-color:currentColor' : '' }}">{{ $t('filters.failed') }} <span dir="ltr">{{ $counts['failed'] }}</span></button>
        @foreach ($reasons as $reason)
            @if (($counts['by'][$reason] ?? 0) > 0 || $this->failure === $reason)
                <button type="button" wire:click="pick('{{ $reason }}')" title="{{ $t('reasons_help.'.$reason) }}" style="{{ $chip }};{{ $this->failure === $reason ? 'font-weight:700;border-color:currentColor' : '' }}">{{ $t('reasons.'.$reason) }} <span dir="ltr">{{ $counts['by'][$reason] ?? 0 }}</span></button>
            @endif
        @endforeach
        <button type="button" wire:click="pick('')" style="{{ $chip }};{{ $this->failure === '' ? 'font-weight:700;border-color:currentColor' : '' }}">{{ $t('filters.all') }}</button>
    </div>

    @if ($list['rows'] === [])
        <x-filament::section>{{ $t('none') }}</x-filament::section>
    @else
        <div style="display:grid;gap:10px">
            @foreach ($list['rows'] as $row)
                @php($reason = $row['failure_team'] ?? $row['failure'])
                <x-filament::section compact wire:key="aq-{{ $row['id'] }}">
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                            <strong>{{ $row['shop'] }}</strong>
                            <span style="{{ $small }}" dir="ltr">{{ $row['at'] }}</span>
                            <span style="{{ $small }}">{{ $row['outcome'] }} · {{ $row['from'] }}@if ($row['reason']) · {{ $row['reason'] }}@endif</span>
                            @if ($reason && $reason !== 'fine')<span style="{{ $chip }};{{ $bad }};cursor:default" title="{{ $t('reasons_help.'.$reason) }}">{{ $t('reasons.'.$reason) }}@if ($row['failure_team']) · {{ $t('by_team') }}@endif</span>@endif
                            @if ($row['score'] !== null)<span style="{{ $chip }};cursor:default;{{ $row['score'] <= 2 ? $bad : '' }}">{{ $t('score', ['n' => $row['score']]) }}</span>@endif
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;{{ $small }}" dir="ltr">
                            @if ($row['prompt'])<span>answer_site v{{ $row['prompt'] }}</span>@endif
                            @if ($row['cost'] !== null)<span>${{ number_format((float) $row['cost'], 5) }}</span>@endif
                            @if ($row['ms'])<span>{{ number_format($row['ms']) }} ms</span>@endif
                            <button type="button" wire:click="toggle('{{ $row['id'] }}')" style="text-decoration:underline">{{ $this->open === $row['id'] ? $t('less') : $t('more_details') }}</button>
                        </div>
                    </div>
                    <div style="margin-top:8px"><span style="{{ $small }}">{{ $t('question') }}</span> {{ $row['question'] }}</div>
                    <div style="margin-top:4px"><span style="{{ $small }}">{{ $t('answer') }}</span> {{ $row['answer'] !== '' ? $row['answer'] : $t('no_answer') }}</div>
                    @if ($row['note'])<div style="margin-top:6px;{{ $small }}">{{ $t('review_note') }} {{ $row['note'] }}</div>@endif

                    @if ($this->open === $row['id'])
                        @php($context = (array) ($row['trace']['context'] ?? []))
                        <dl style="margin-top:10px;display:grid;grid-template-columns:max-content 1fr;gap:4px 14px;font-size:13px">
                            <dt style="opacity:.7">{{ $t('trace.models') }}</dt><dd dir="ltr" style="text-align:start">{{ $row['writer'] ?? '—' }} → {{ $row['checker'] ?? '—' }}</dd>
                            <dt style="opacity:.7">{{ $t('trace.verdict') }}</dt><dd dir="ltr" style="text-align:start">{{ $row['verdict'] ?? '—' }}@if (isset($row['trace']['refused'])) · {{ $row['trace']['refused'] }}@endif @if (isset($row['trace']['failed'])) · {{ $row['trace']['failed'] }}@endif</dd>
                            <dt style="opacity:.7">{{ $t('trace.sent') }}</dt><dd>{{ $t('trace.sent_line', ['found' => $context['passages_found'] ?? 0, 'passages' => $context['passages_sent'] ?? 0, 'products' => $context['products_sent'] ?? 0, 'chars' => number_format((int) ($context['chars'] ?? 0))]) }}@if (isset($context['type'])) · {{ $context['type'] }}@endif @if (isset($context['mode'])) · {{ $context['mode'] }}@endif</dd>
                            <dt style="opacity:.7">{{ $t('trace.time') }}</dt><dd dir="ltr" style="text-align:start">{{ $row['trace']['writer_ms'] ?? '—' }} + {{ $row['trace']['check_ms'] ?? '—' }} ms</dd>
                            @if ($row['picks'] !== [])<dt style="opacity:.7">{{ $t('trace.picks') }}</dt><dd>{{ implode(' · ', $row['picks']) }}</dd>@endif
                            @if ($row['run'])<dt style="opacity:.7">{{ $t('trace.run') }}</dt><dd dir="ltr" style="text-align:start"><code>{{ $row['run'] }}</code></dd>@endif
                        </dl>
                        <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                            <span style="{{ $small }}">{{ $t('team_reason') }}</span>
                            @foreach ([...$reasons, 'fine'] as $choice)
                                <button type="button" wire:click="setFailure('{{ $row['id'] }}', '{{ $row['failure_team'] === $choice ? '' : $choice }}')" style="{{ $chip }};{{ $row['failure_team'] === $choice ? 'font-weight:700;border-color:currentColor' : '' }}">{{ $t('reasons.'.$choice) }}</button>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
            @if ($list['more'])<button type="button" wire:click="more" style="text-decoration:underline;justify-self:start">{{ $t('more') }}</button>@endif
        </div>
    @endif
</x-filament-panels::page>
