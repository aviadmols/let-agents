<x-filament-panels::page>
    {{-- Settings · How it looks (LookSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('search::shop_settings.look.'.$key, $params))

    <x-lp::card :title="$t('title')" :help="$t('help')">
        <div class="lp-set-grid">
            <div>
                <div class="lp-set-label">{{ $t('theme') }}<span class="lp-tip" tabindex="0" data-tip="{{ $t('theme_tip') }}" aria-label="{{ $t('theme_tip') }}">i</span></div>
                <x-lp::opts model="data.theme" :options="[
                    'lets' => [...$t('themes')['lets'], 'mini' => 'lets'],
                    'store' => [...$t('themes')['store'], 'mini' => 'site'],
                ]" />
            </div>
            <div>
                <div class="lp-set-label">{{ $t('images') }}<span class="lp-tip" tabindex="0" data-tip="{{ $t('images_tip') }}" aria-label="{{ $t('images_tip') }}">i</span></div>
                <x-lp::opts model="data.images" :options="[
                    'cover' => [...$t('fits')['cover'], 'mini' => 'cover'],
                    'frame' => [...$t('fits')['frame'], 'mini' => 'frame'],
                ]" />
            </div>
        </div>

        <x-lp::kv style="margin-top: 18px">
            <x-lp::kv-row :label="$t('name')" :tip="$t('name_tip')" :error="$this->problem('assistant')">
                <x-lp::field model="data.assistant" :width="360" maxlength="60" />
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('language')">
                <x-lp::chips model="data.language" :options="$t('languages')" />
            </x-lp::kv-row>
        </x-lp::kv>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
