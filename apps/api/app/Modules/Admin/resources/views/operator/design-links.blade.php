<x-filament-panels::page>
    {{-- Links for a designer (DesignLinks): one shop's panel for a day; the link shown once; the open ones closed at will. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::design.'.$key, $params))
    @php($small = 'font-size:12.5px;opacity:.7')
    <x-filament::section :heading="$t('make')">
        <p style="margin:0 0 12px;{{ $small }}">{{ $t('make_note') }}</p>
        <form wire:submit="makeLink" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <select wire:model="shop" style="padding:6px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.3);background:transparent">
                <option value="">{{ $t('pick_shop') }}</option>
                @foreach ($this->shops() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <x-filament::button type="submit">{{ $t('make_button') }}</x-filament::button>
        </form>
        @if ($made)
            <div data-design-link style="margin-top:14px;padding:12px;border-radius:12px;background:rgba(127,127,127,.1)">
                <p style="margin:0 0 6px;font-weight:600">{{ $t('made') }}</p>
                <input readonly value="{{ $made }}" dir="ltr" onclick="this.select()" style="width:100%;padding:8px 10px;border-radius:8px;border:1px solid rgba(127,127,127,.3);background:transparent;font-family:monospace">
                <p style="margin:6px 0 0;{{ $small }}">{{ $t('made_once') }}</p>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="$t('open')">
        @forelse ($this->open() as $pass)
            <div style="display:flex;gap:12px;align-items:center;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.15)" data-pass="{{ $pass['id'] }}">
                <strong style="flex:1">{{ $pass['shop'] }}</strong>
                <span style="{{ $small }}">{{ $t('until', ['time' => $pass['expires']]) }}</span>
                <span style="{{ $small }}">{{ $pass['used'] ? $t('used', ['time' => $pass['used']]) : $t('unused') }}</span>
                <x-filament::button color="danger" size="sm" wire:click="revoke('{{ $pass['id'] }}')" wire:confirm="{{ $t('revoke_confirm') }}">{{ $t('revoke') }}</x-filament::button>
            </div>
        @empty
            <p style="margin:0;opacity:.75">{{ $t('none') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
