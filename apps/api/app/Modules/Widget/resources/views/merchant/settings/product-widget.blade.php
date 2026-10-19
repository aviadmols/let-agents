<x-filament-panels::page>
    {{-- Settings · The product page block (ProductWidgetSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('widget::shop_settings.widget.'.$key, $params))
    @php([$min, $max] = $this->maxProductsBounds())

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.on">
        <x-lp::row :title="$t('on_products')"><x-lp::toggle model="data.on_products" :label="$t('on_products')" /></x-lp::row>
        <x-lp::row :title="$t('on_content')" :sub="$t('on_content_sub')"><x-lp::toggle model="data.on_content" :label="$t('on_content')" /></x-lp::row>
        <x-lp::row :title="$t('max_products')"><x-lp::num model="data.max_products" :min="$min" :max="$max" :label="$t('max_products')" /></x-lp::row>
        <x-lp::row :title="$t('popularity')" :sub="$t('popularity_sub')" :tip="$t('popularity_tip')"><x-lp::toggle model="data.popularity" :label="$t('popularity')" /></x-lp::row>
        <x-lp::row :title="$t('promises')" :sub="$t('promises_sub')" :tip="$t('promises_tip')"><x-lp::toggle model="data.promises" :label="$t('promises')" /></x-lp::row>

        <div class="lp-set-label">{{ $t('parts') }}<span class="lp-tip" tabindex="0" data-tip="{{ $t('parts_tip') }}" aria-label="{{ $t('parts_tip') }}">i</span></div>
        <x-lp::chips model="data.parts" :options="__('widget::shop_settings.widget.part_names')" multiple :label="$t('parts')" />

        <div style="margin-top: 14px">
            <a class="lp-link" href="{{ \App\Modules\Widget\Filament\Merchant\Pages\ProductPage::getUrl() }}" wire:navigate>{{ $t('by_page') }}</a>
        </div>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
