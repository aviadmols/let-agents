<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('assistant::ui.knowledge.'.$key, $with))
    @php($k = $this->knowledge())
    @php($small = 'font-size:13px;opacity:.7')
    @php($row = 'display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid rgba(127,127,127,.18)')

    @if ($this->picksShop())
        <div>
            <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($k === null)
        <x-filament::section>{{ $t('no_shop') }}</x-filament::section>
    @else
        <x-filament::section :heading="$t('repeated.title')" :description="$t('repeated.help')">
            <x-slot name="afterHeader">
                <x-filament::button size="sm" color="gray" wire:click="readAgain">{{ $t('repeated.read_again') }}</x-filament::button>
            </x-slot>
            @if ($k['repeated'] === [])
                <p style="{{ $small }}">{{ $t('repeated.none') }}</p>
            @else
                <p style="{{ $small }};margin-bottom:6px">{{ $t('repeated.read', ['pages' => $k['pages'], 'when' => $k['read_at'] ? \Illuminate\Support\Carbon::parse($k['read_at'])->diffForHumans() : '']) }}</p>
                @foreach ($k['repeated'] as $sentence)
                    <div style="{{ $row }}" wire:key="r-{{ md5($sentence) }}">
                        <span style="font-size:14px">{{ $sentence }}</span>
                        <x-filament::link tag="button" size="sm" color="gray" wire:click="remove(@js($sentence))" wire:confirm="{{ $t('repeated.remove_confirm') }}">{{ $t('repeated.remove') }}</x-filament::link>
                    </div>
                @endforeach
            @endif
        </x-filament::section>

        <x-filament::section :heading="$t('policies.title')" :description="$t('policies.help')">
            @forelse ($k['policies'] as $page)
                <div style="{{ $row }}">
                    <span style="font-size:14px">{{ $page['title'] }}</span>
                    @if ($page['url'])
                        <x-filament::link :href="$page['url']" target="_blank" size="sm">{{ $t('policies.open') }}</x-filament::link>
                    @endif
                </div>
            @empty
                <p style="{{ $small }}">{{ $t('policies.none') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="$t('own.title')" :description="$t('own.help')">
            <form wire:submit="saveOwn" style="display:grid;gap:10px">
                <textarea wire:model="own" rows="7" maxlength="6000" aria-label="{{ $t('own.title') }}" placeholder="{{ $t('own.placeholder') }}"
                    style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d4d4d8;border-radius:10px;font:inherit;font-size:14px;line-height:1.6;background:transparent;color:inherit"></textarea>
                <div><x-filament::button type="submit">{{ $t('own.save') }}</x-filament::button></div>
            </form>
        </x-filament::section>

        <x-filament::section :heading="$t('team.title')" :description="$t('team.help')">
            @forelse ($k['team'] as $pair)
                <div style="padding:10px 0;border-top:1px solid rgba(127,127,127,.18)">
                    <div style="font-weight:600;font-size:14px">{{ $pair['question'] }}</div>
                    <div style="font-size:14px;margin-top:2px">{{ $pair['answer'] }}</div>
                </div>
            @empty
                <p style="{{ $small }}">{{ $t('team.none') }}</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
