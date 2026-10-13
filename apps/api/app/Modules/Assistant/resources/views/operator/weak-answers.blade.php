<x-filament-panels::page>
    @php($items = $this->items())
    @php($small = 'font-size:12px;opacity:.7')
    @php($chip = 'display:inline-block;font-size:12px;padding:2px 8px;border-radius:999px')

    @if ($items === [])
        <x-filament::section>{{ __('assistant::ui.weak.none') }}</x-filament::section>
    @else
        <div style="display:grid;gap:12px">
            @foreach ($items as $item)
                <x-filament::section compact>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                            <strong>{{ $item['shop'] }}</strong>
                            <span style="{{ $small }}" dir="ltr">{{ $item['day'] }}</span>
                            <span style="{{ $chip }};background:{{ $item['score'] <= 2 ? 'rgba(220,60,60,.15)' : 'rgba(235,170,52,.2)' }}">{{ __('assistant::ui.weak.score', ['score' => $item['score']]) }}</span>
                            @if ($item['flag'])
                                <span style="{{ $chip }};background:rgba(220,60,60,.15);font-weight:600">{{ __('assistant::ui.weak.flags.'.$item['flag']) }}</span>
                            @endif
                        </div>
                        <a href="{{ \App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions::getUrl(['shop' => $item['shop_id']]) }}" style="font-size:12px;text-decoration:underline">{{ __('assistant::ui.weak.open_shop') }}</a>
                    </div>
                    <div style="margin-top:8px"><span style="{{ $small }}">{{ __('assistant::ui.weak.question') }}</span> {{ $item['question'] }}</div>
                    <div style="margin-top:4px">
                        <span style="{{ $small }}">{{ __('assistant::ui.weak.answer') }}</span>
                        {{ $item['answer'] !== '' ? $item['answer'] : __('assistant::ui.weak.no_answer', ['outcome' => $item['outcome']]) }}
                    </div>
                    @if ($item['picks'] !== [])
                        <div style="margin-top:4px"><span style="{{ $small }}">{{ __('assistant::ui.weak.picks') }}</span> {{ implode(' · ', $item['picks']) }}</div>
                    @endif
                    @if ($item['note'] !== '')
                        <div style="margin-top:6px;{{ $small }}">{{ __('assistant::ui.weak.note') }} {{ $item['note'] }}</div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
