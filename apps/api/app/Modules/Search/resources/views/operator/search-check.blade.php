<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('search::check.'.$key, $with))
    @php($small = 'font-size:12px;opacity:.7')
    @php($input = 'padding:8px 12px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;min-width:0')
    @php($list = 'margin:0;padding-inline-start:18px;display:grid;gap:4px;font-size:14px')
    @php($deep = $this->deep())
    @php($linkTo = fn (?string $link): ?string => match ($link) { 'synonyms' => $this->synonymsUrl(), 'knowledge' => $this->knowledgeUrl(), default => null })

    <form wire:submit="check" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if ($this->picksShop())
            <select wire:model.live="shop" style="{{ $input }};min-width:200px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <input type="text" wire:model="q" maxlength="300" dir="auto" placeholder="{{ $t('placeholder') }}" style="{{ $input }};flex:1 1 320px" data-check-query>
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ $t('search') }}</x-filament::button>
        <x-filament::button type="button" color="gray" wire:click="ask" wire:loading.attr="disabled" data-check-ask>{{ $t('ask') }}</x-filament::button>
        <span wire:loading style="{{ $small }}">{{ $t('working') }}</span>
    </form>
    <p style="{{ $small }};margin-top:-8px">{{ $t('note', ['n' => \App\Core\Facades\Settings::get('search.check_questions_per_day', $shop)]) }}</p>

    @if ($limit !== null)
        <x-filament::section><p>{{ $t('limit', ['n' => $limit]) }}</p></x-filament::section>
    @endif

    @if ($answer !== null)
        <x-filament::section :heading="$t('sections.question')" data-check-answer>
            @if ($answer['text'] !== '')
                <p style="white-space:pre-line;font-size:15px" dir="auto">{{ $answer['text'] }}</p>
            @else
                <p style="{{ $small }}">{{ $t('answer.none') }}</p>
            @endif
            @if ($answer['picks'] !== [])
                <div style="margin-top:10px;font-weight:600;font-size:13px">{{ $t('answer.picks') }}</div>
                <ul style="{{ $list }}">
                    @foreach ($answer['picks'] as $pick)
                        <li><span style="font-weight:600">{{ $pick['title'] }}</span>@if ($pick['why'] !== '') · {{ $pick['why'] }}@endif</li>
                    @endforeach
                </ul>
            @endif
            @if ($answer['sources'] !== [])
                <div style="margin-top:10px;font-weight:600;font-size:13px">{{ $t('answer.sources') }}</div>
                <ul style="{{ $list }}">
                    @foreach ($answer['sources'] as $source)
                        <li>@if ($source['url'])<x-filament::link :href="$source['url']" target="_blank">{{ $source['title'] }}</x-filament::link>@else{{ $source['title'] }}@endif</li>
                    @endforeach
                </ul>
            @endif

            <div style="margin-top:14px;font-weight:600;font-size:13px">{{ $t('sections.steps') }}</div>
            <ol style="{{ $list }}">
                @foreach ($answer['steps'] as $step)
                    <li>{{ $t('steps.'.$step['key'], $step['params']) }}</li>
                @endforeach
            </ol>

            @if ($answer['suggestions'] !== [])
                <div style="margin-top:14px;font-weight:600;font-size:13px">{{ $t('sections.suggestions') }}</div>
                <ul style="{{ $list }}">
                    @foreach ($answer['suggestions'] as $suggestion)
                        <li>{{ $t('suggest.'.$suggestion['key'], $suggestion['params']) }}
                            @if (($url = $linkTo($suggestion['link'] ?? null)) !== null)
                                <x-filament::link :href="$url" size="sm">{{ $t('suggest.'.$suggestion['link'].'_link') }}</x-filament::link>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($deep)
                @foreach ($answer['looks'] as $look)
                    <details style="margin-top:12px">
                        <summary style="cursor:pointer;font-size:13px;font-weight:600">{{ $t('sections.given') }} · {{ $t('answer.chars', ['n' => $look['chars'] ?? 0]) }}</summary>
                        <div style="display:grid;gap:8px;margin-top:6px;font-size:13px">
                            <div><span style="{{ $small }}">{{ $t('answer.pages') }}:</span>
                                {{ collect($look['pages'])->map(fn ($p) => $p['title'].' ('.implode(', ', array_filter([$p['source'] ?? null, $p['kind'] ?? null, $p['from'] ?? null])).')')->implode(' · ') ?: '—' }}</div>
                            <div><span style="{{ $small }}">{{ $t('answer.products') }}:</span> {{ implode(' · ', $look['products']) ?: '—' }}</div>
                        </div>
                    </details>
                @endforeach
                @if ($answer['tech'] !== [])
                    <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12px" dir="auto">
                        @foreach ($answer['tech'] as $key => $value)
                            <span><span style="opacity:.6">{{ $t('tech.'.$key) }}:</span> {{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</span>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-filament::section>
    @endif

    @if ($explained === null)
        @if ($answer === null && $limit === null)
            <p style="{{ $small }}">{{ $t('empty') }}</p>
        @endif
    @else
        <x-filament::section :heading="$t('sections.read')">
            <ul style="{{ $list }}">
                @forelse ($explained['read'] as $line)
                    <li>{{ $t('read.'.$line['key'], $line['params']) }}</li>
                @empty
                    <li>{{ $t('read.plain') }}</li>
                @endforelse
            </ul>
        </x-filament::section>

        <x-filament::section :heading="$t('sections.suggestions')" data-check-suggestions>
            <ul style="{{ $list }}">
                @foreach ($explained['suggestions'] as $suggestion)
                    <li>{{ $t('suggest.'.$suggestion['key'], $suggestion['params']) }}
                        @if (($url = $linkTo($suggestion['link'] ?? null)) !== null)
                            <x-filament::link :href="$url" size="sm">{{ $t('suggest.'.$suggestion['link'].'_link') }}</x-filament::link>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::section :heading="$t('sections.results')">
            <p style="font-size:14px;margin-bottom:10px">
                {{ $explained['results'] === [] ? $t('results.none') : $t('results.count', ['count' => $explained['counts']['product'] ?? count($explained['results']), 'held' => $explained['held']]) }}
                @if (($explained['counts']['content'] ?? 0) > 0) {{ $t('results.content', ['count' => $explained['counts']['content']]) }} @endif
            </p>
            <div style="display:grid;gap:10px">
                @foreach ($explained['results'] as $at => $result)
                    <div style="display:flex;gap:12px;align-items:flex-start;padding:10px;border:1px solid #f1f1f4;border-radius:10px" data-check-result>
                        <span style="{{ $small }};min-width:18px">{{ $at + 1 }}</span>
                        @if ($result['image'])
                            <img src="{{ $result['image'] }}" alt="" style="width:52px;height:52px;object-fit:contain;border-radius:6px;background:#fafafa" loading="lazy">
                        @endif
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600" dir="auto">
                                @if ($result['url'])<x-filament::link :href="$result['url']" target="_blank">{{ $result['title'] }}</x-filament::link>@else{{ $result['title'] }}@endif
                                <span style="{{ $small }};font-weight:400">
                                    @if ($result['price'] !== null) · {{ $result['price'] }} @endif
                                    · {{ $result['in_stock'] ? $t('results.in_stock') : $t('results.out_of_stock') }}
                                </span>
                            </div>
                            <ul style="{{ $list }};margin-top:4px">
                                @foreach ($result['ways'] as $way)
                                    <li>{{ $t('ways.'.$way['key'], $way['params']) }}
                                        @if ($deep && ($way['detail'] ?? '') !== '') <span style="{{ $small }}">({{ $t('ways.similarity', ['value' => $way['detail']]) }})</span> @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($explained['left'] !== [])
            <x-filament::section :heading="$t('sections.left')" collapsible collapsed>
                <ul style="{{ $list }}">
                    @foreach ($explained['left'] as $left)
                        <li><span dir="auto">{{ $left['title'] }}</span> · <span style="{{ $small }}">{{ $t('left.'.$left['key']) }}</span></li>
                    @endforeach
                </ul>
                @if ($explained['left_count'] > count($explained['left']))
                    <p style="{{ $small }};margin-top:6px">{{ $t('left.more', ['count' => $explained['left_count'] - count($explained['left'])]) }}</p>
                @endif
            </x-filament::section>
        @endif

        @if ($deep)
            @php($steps = $explained['steps'])
            <x-filament::section :heading="$t('sections.mechanics')" collapsible collapsed>
                <ul style="{{ $list }}">
                    @if ($steps['meaning']['off'] ?? false)
                        <li>{{ $t('mech.meaning_off') }}</li>
                    @elseif (isset($steps['meaning']['best']))
                        <li>{{ $t('mech.meaning', ['best' => $steps['meaning']['best'], 'floor' => $steps['meaning']['floor'], 'strict' => $steps['meaning']['strict']]) }}</li>
                        @if ($steps['meaning']['strict_used'] ?? false)<li>{{ $t('mech.strict_used') }}</li>@endif
                        @if (($steps['meaning']['below'] ?? []) !== [])
                            <li>{{ $t('mech.below') }}
                                @foreach ($steps['meaning']['below'] as $row)
                                    <div style="{{ $small }}" dir="auto">{{ $t('mech.below_row', ['title' => $row['title'], 'similarity' => $row['similarity'], 'floor' => $row['floor']]) }}</div>
                                @endforeach
                            </li>
                        @endif
                    @endif
                    @if (isset($steps['pictures']))
                        <li>{{ ($steps['pictures']['ran'] ?? false) ? $t('mech.pictures_ran') : $t('mech.pictures_skipped', ['count' => $steps['pictures']['words_found'] ?? 0]) }}</li>
                    @endif
                    @if (isset($steps['subject']['wide']))
                        <li>{{ $t('mech.subject_wide', ['category' => implode(', ', $steps['subject']['wide']), 'count' => $steps['subject']['members'] ?? 0]) }}</li>
                    @endif
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
