<x-filament-panels::page>
    {{-- Settings · The search window (SearchWindowSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('search::shop_settings.search.'.$key, $params))
    @php($bounds = $this->perGroupBounds())

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.storefront">
        <x-lp::row :title="$t('field')" :sub="$t('field_sub')" :tip="$t('field_tip')"><x-lp::toggle model="data.take_over" :label="$t('field')" /></x-lp::row>
        <x-lp::row :title="$t('nudge')" :sub="$t('nudge_sub')" :tip="$t('nudge_tip')"><x-lp::toggle model="data.nudge" :label="$t('nudge')" /></x-lp::row>
        <x-lp::row :title="$t('nudge_at')" :sub="$t('nudge_at_sub')" :tip="$t('nudge_at_tip')" x-data x-show="$wire.data.nudge">
            <x-lp::field model="data.nudge_at" :ltr="true" :width="240" placeholder="header__search-link" :aria-label="$t('nudge_at')" />
            @if ($this->problem('nudge_at'))<span class="lp-set-error" role="alert">{{ $this->problem('nudge_at') }}</span>@endif
        </x-lp::row>
        <x-lp::row :title="$t('photos')" :tip="$t('photos_tip')">
            {{-- the pictures' state once they matter: ready, or still scanning while photo search is on --}}
            @php($ready = $this->picturesReady())
            <x-slot:below>@if ($ready){{ $t('photos_sub') }}<span class="lp-pill" data-tone="good">{{ $t('photos_ready') }}</span>@elseif ($this->data['photos'] ?? false){{ $t('photos_sub') }}<span class="lp-pill" data-tone="warn">{{ $t('photos_scanning') }}</span>@else{{ preg_replace('/[\s·]+$/u', '', $t('photos_sub')) }}@endif</x-slot:below>
            <x-lp::toggle model="data.photos" :label="$t('photos')" />
        </x-lp::row>
        <x-lp::row :title="$t('ask')" :sub="$t('ask_sub')"><x-lp::toggle model="data.ask" :label="$t('ask')" /></x-lp::row>
        <x-lp::row :title="$t('similar')" :tip="$t('similar_tip')"><x-lp::toggle model="data.similar" :label="$t('similar')" /></x-lp::row>
        <x-lp::row :title="$t('similar_by')" :sub="$t('similar_by_sub')"><x-lp::chips model="data.similar_by" :options="$t('similar_by_options')" :sm="true" :label="$t('similar_by')" /></x-lp::row>
        <x-lp::row :title="$t('price')"><x-lp::toggle model="data.price" :label="$t('price')" /></x-lp::row>
        <x-lp::row :title="$t('cart')" :sub="$t('cart_sub')"><x-lp::toggle model="data.cart" :label="$t('cart')" /></x-lp::row>
        <x-lp::row :title="$t('per_group')" :tip="$t('per_group_tip')"><x-lp::num model="data.per_group" :min="$bounds['min']" :max="$bounds['max']" :label="$t('per_group')" /></x-lp::row>
        <x-lp::row :title="$t('wishlist')" :sub="$t('wishlist_sub')"><x-lp::toggle model="data.wishlist" :label="$t('wishlist')" /></x-lp::row>
        <x-lp::row :title="$t('compare')"><x-lp::toggle model="data.compare" :label="$t('compare')" /></x-lp::row>
        <x-lp::row :title="$t('rate')" :sub="$t('rate_sub')" :tip="$t('rate_tip')"><x-lp::toggle model="data.rate" :label="$t('rate')" /></x-lp::row>
        <x-lp::row :title="$t('oos')" :sub="$t('oos_sub')"><x-lp::toggle model="data.out_of_stock" :label="$t('oos')" /></x-lp::row>
        <x-lp::row :title="$t('lets_posts')" :sub="$t('lets_posts_sub')" :tip="$t('lets_posts_tip')"><x-lp::toggle model="data.lets_posts" :label="$t('lets_posts')" /></x-lp::row>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
