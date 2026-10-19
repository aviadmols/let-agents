<x-filament-panels::page>
    {{-- Settings · The heart and the wishlist (HeartSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('shortlist::shop_settings.heart.'.$key, $params))
    @php($platform = $this->platform())
    @php($active = $this->activeSelector())

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.wishlist">
        <div class="lp-set-grid">
            <div>
                <x-lp::row :title="$t('on_product')" :sub="$t('on_product_sub')"><x-lp::toggle model="data.heart_on_product" :label="$t('on_product')" /></x-lp::row>
                <x-lp::row :title="$t('on_cards')" :sub="$t('on_cards_sub')"><x-lp::toggle model="data.heart_on_cards" :label="$t('on_cards')" /></x-lp::row>
                <x-lp::row :title="$t('in_results')"><x-lp::toggle model="data.heart_in_results" :label="$t('in_results')" /></x-lp::row>
                <x-lp::row :title="$t('place')"><x-lp::chips model="data.heart_place" :options="__('shortlist::shop_settings.heart.places')" sm :label="$t('place')" /></x-lp::row>
                <x-lp::row :title="$t('click')"><x-lp::chips model="data.heart_click" :options="__('shortlist::shop_settings.heart.clicks')" sm :label="$t('click')" /></x-lp::row>
                <x-lp::row :title="$t('phone')" :sub="$t('phone_sub')" :tip="$t('phone_tip')"><x-lp::toggle model="data.wishlist_phone" :label="$t('phone')" /></x-lp::row>
            </div>

            <div>
                <div class="lp-set-label">{{ $t('detect_label') }}</div>
                <p style="font-size: 14px; color: var(--la-ink-2); margin: 0 0 10px; line-height: 1.55">{{ $t('detect_text') }}</p>
                <form wire:submit="detect" style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px">
                    <div class="lp-set-field lp-set-wide" style="flex: 1">
                        <input type="url" wire:model="detectUrl" dir="ltr" placeholder="{{ $this->placeholder() }}" aria-label="{{ $t('detect_label') }}">
                    </div>
                    <button type="submit" class="lp-btn lp-btn-primary lp-btn-sm" wire:loading.attr="disabled" wire:target="detect">{{ $t('detect') }}</button>
                </form>
                @if ($detectError)<p class="lp-set-error" role="alert" style="margin: 0 0 10px">{{ $detectError }}</p>@endif

                @if ($detected)
                    <div class="lp-fact" style="margin-bottom: 10px" role="status">
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" style="flex: none"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><b style="display: block">{{ $t('detected', ['count' => $detected['count']]) }}</b>{{ __('shortlist::shop_settings.heart.detected_where.'.($data['heart_place'] ?? 'corner')) }}</span>
                    </div>
                    @if (($detected['sample']['image'] ?? null) || ($detected['sample']['title'] ?? null))
                        <div class="lp-mini" style="padding: 12px; gap: 12px; margin: 0">
                            <span style="position: relative; width: 56px; height: 56px; flex: none; border-radius: 10px; overflow: hidden; background: var(--la-sunk)">
                                @if ($detected['sample']['image'] ?? null)<img src="{{ $detected['sample']['image'] }}" alt="" referrerpolicy="no-referrer" style="width: 100%; height: 100%; object-fit: cover">@endif
                                <span style="position: absolute; top: 4px; inset-inline-end: 4px; width: 18px; height: 18px; border-radius: 50%; background: #fff; display: grid; place-items: center; box-shadow: 0 1px 3px rgb(0 0 0 / .2)"><svg width="10" height="10" viewBox="0 0 24 24" aria-hidden="true" style="color: #0b0b0c"><path d="M12 19.5s-7.5-4.6-7.5-10.2A4.1 4.1 0 0 1 12 7.1a4.1 4.1 0 0 1 7.5 2.2c0 5.6-7.5 10.2-7.5 10.2z" fill="currentColor"/></svg></span>
                            </span>
                            <span style="font-size: 13px; color: var(--la-ink-2); min-width: 0">{{ $detected['sample']['title'] ?? '' }}<code class="lp-snip" style="display: block; margin-top: 6px; width: fit-content">{{ $detected['selector'] }}</code></span>
                        </div>
                    @endif
                    <div style="display: flex; gap: 8px; align-items: center; margin-top: 10px">
                        <button type="button" class="lp-btn lp-btn-primary lp-btn-sm" wire:click="activate">{{ $t('activate') }}</button>
                        <button type="button" class="lp-link" wire:click="reject">{{ $t('not_this') }}</button>
                    </div>
                @elseif ($active !== '')
                    <div class="lp-fact" style="margin-bottom: 10px">
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" style="flex: none"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><b style="display: block">{{ $t('active') }}</b>{{ $t('active_text') }} <code class="lp-snip" style="margin-top: 6px">{{ $active }}</code></span>
                    </div>
                    <button type="button" class="lp-link" wire:click="reject">{{ $t('not_this') }}</button>
                @endif

                <div class="lp-set-label">{{ $t('reach_label') }}</div>
                <div class="lp-fact">
                    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" style="flex: none"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    @if ($platform === 'shopify')
                        <span><b style="display: block">Shopify</b>{{ $t('reach.shopify') }}</span>
                    @else
                        <span><b style="display: block">WooCommerce</b>{!! str_replace(['%CODE%', '%HASH%'], ['<code dir="ltr">[let_agents_wishlist]</code>', '<code dir="ltr">#wishlist</code>'], e($t('reach.woocommerce', ['code' => '%CODE%', 'hash' => '%HASH%']))) !!}</span>
                    @endif
                </div>
                <div style="margin-top: 12px">
                    <a class="lp-link" href="{{ \App\Modules\Shortlist\Filament\Merchant\Pages\Wishlist::getUrl() }}" wire:navigate>{{ $t('lists') }}</a>
                </div>
            </div>
        </div>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
