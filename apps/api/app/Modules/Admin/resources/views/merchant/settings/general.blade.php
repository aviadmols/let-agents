<x-filament-panels::page>
    {{-- Settings · General (GeneralSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::shop_settings.general.'.$key, $params))
    @php($languages = \App\Modules\Admin\Filament\Merchant\Pages\GeneralSettings::LANGUAGES)

    <x-lp::card :title="$t('title')" :help="$t('help')">
        <x-lp::kv>
            <x-lp::kv-row :label="$t('name')" :error="$this->problem('name')"><x-lp::field model="data.name" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('kind')" :tip="$t('kind_tip')">
                <x-lp::chips model="data.kind" :options="__('admin::shop_settings.general.kinds')" />
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('matters')" :tip="$t('matters_tip')">
                <x-lp::chips model="data.matters" :options="__('admin::shop_settings.general.matters_kinds')" />
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('languages')">
                {{-- the first is the main one: the language the store's content is read in --}}
                <div class="lp-chips" x-data="{ v: $wire.entangle('data.languages'), adding: false, names: @js($languages) }">
                    <template x-for="(code, i) in v" :key="code">
                        <span class="lp-chip lp-chip-plus">
                            <span x-text="names[code] + (i === 0 ? ' · ' + @js($t('main')) : '')"></span>
                            <button type="button" x-show="i > 0" x-on:click="v = v.filter((c) => c !== code)" class="lp-chip-x" aria-label="✕">✕</button>
                        </span>
                    </template>
                    <span class="lp-chip lp-chip-plus" x-show="! adding" x-on:click="adding = true" role="button" tabindex="0">+ {{ $t('language') }}</span>
                    <template x-for="(name, code) in names" :key="'add-' + code">
                        <button type="button" class="lp-chip" x-show="adding && ! v.includes(code)" x-on:click="v = [...v, code]; adding = false" x-text="name"></button>
                    </template>
                </div>
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('sells')">
                <div class="lp-chips" x-data="{ v: $wire.entangle('data.sells'), word: '' }">
                    <template x-for="s in v" :key="s">
                        <span class="lp-chip lp-chip-plus"><span x-text="s"></span><button type="button" x-on:click="v = v.filter((x) => x !== s)" class="lp-chip-x" aria-label="✕">✕</button></span>
                    </template>
                    <span class="lp-set-field" style="max-width: 200px; min-height: 34px">
                        <input type="text" x-model="word" placeholder="+ {{ $t('field') }}" x-on:keydown.enter.prevent="if (word.trim() && ! v.includes(word.trim())) { v = [...v, word.trim()]; } word = ''">
                    </span>
                </div>
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('zone')"><span dir="ltr">{{ $this->data['zone'] }}</span></x-lp::kv-row>
        </x-lp::kv>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
