<x-filament-panels::page>
    {{-- Settings · Store connection (ConnectionSettings), as the designer's redesign draws it (prototype settings.html, 11.2026):
         whether the store is connected and how, when it was last read, where the search shows, and a WordPress store's
         plugin and token. The plan and its allowance are on Plan and billing (PlanSettings). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::shop_settings.connection.'.$key, $params))
    @php($d = $this->details())
    @php($see = \Illuminate\Support\Facades\Route::has('filament.merchant.pages.see') ? route('filament.merchant.pages.see', ['tenant' => \Filament\Facades\Filament::getTenant()]) : null)

    <div class="lp-page lp-d" data-connection>
        <section class="lp-d-panel">
            <div class="lp-d-conn">
                <span class="lp-d-conn-logo">{{ $d['woo'] ? 'WordPress' : 'Shopify' }}</span>
                <div><b>{{ $d['platform'] }}</b><span><bdi dir="ltr">{{ $d['domain'] }}</bdi> · {{ $t('synced') }} {{ $d['synced'] }}</span></div>
                <span @class(['lp-d-chip', 'lp-d-chip-line' => $d['connected'], 'lp-d-chip-sun' => ! $d['connected']])>{{ $t($d['connected'] ? 'connected' : 'not_connected') }}</span>
            </div>
            <div class="lp-d-set"><div><b>{{ __('admin::plan.sync') }}</b><span>{{ __('admin::plan.sync_text') }}</span></div><span></span></div>
            @if ($see)<div class="lp-d-set"><div><b>{{ __('admin::plan.where') }}</b><span>{{ __('admin::plan.where_text') }}</span></div><a class="lp-d-more" href="{{ $see }}">{{ __('widget::see.nav') }}</a></div>@endif
            @if ($d['woo'] && $this->canEdit())
                <div class="lp-d-set"><div><b>{{ __('admin::plan.check') }}</b><span>{{ $t('check_sub') }}</span></div><button type="button" class="lp-d-btn lp-d-btn-line lp-d-btn-sm" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection" data-check>{{ $t('check') }}</button></div>
            @endif
        </section>

        @if ($d['woo'])
            @php($package = $this->package())
            <section class="lp-d-panel" data-plugin>
                <h2 class="lp-d-panel-title">{{ $t('plugin_title') }}</h2>
                <p class="lp-d-card-sub">{{ $t('plugin_help') }}</p>
                <div class="lp-d-set">
                    <div><b>{{ $t('download') }}</b><span>{{ $package ? $t('download_sub', ['version' => $package['version']]) : $t('no_package') }}</span></div>
                    @if ($package && $this->canEdit())<button type="button" class="lp-d-btn lp-d-btn-line lp-d-btn-sm" wire:click="downloadPlugin" wire:loading.attr="disabled" wire:target="downloadPlugin" data-download>{{ $t('download') }}</button>@else<span></span>@endif
                </div>
                <div class="lp-d-set">
                    <div><b>{{ $t('token') }}</b><span>{{ $d['has_token'] ? $t('token_replaces') : $t('token_sub') }}</span></div>
                    @if ($this->canConnect())
                        <button type="button" class="lp-d-btn lp-d-btn-line lp-d-btn-sm" wire:click="createToken" wire:loading.attr="disabled" wire:target="createToken" @if ($d['has_token']) wire:confirm="{{ $t('token_confirm') }}" @endif data-create-token>{{ $t('token') }}</button>
                    @else
                        <span class="lp-d-note">{{ __('admin::shop_settings.owners_only') }}</span>
                    @endif
                </div>
                @if ($token)
                    <div class="lp-d-token" data-token x-data="{ copied: false }">
                        <b>{{ $t('token_once') }}</b>
                        <div class="lp-d-set-field">
                            <input type="text" readonly dir="ltr" value="{{ $token }}" x-ref="token" x-on:focus="$event.target.select()" aria-label="{{ $t('token') }}">
                            <button type="button" class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" x-on:click="navigator.clipboard?.writeText($refs.token.value).then(() => copied = true).catch(() => { $refs.token.select(); document.execCommand('copy'); copied = true })">
                                <span x-show="! copied">{{ $t('copy') }}</span><span x-show="copied" x-cloak>{{ $t('copied') }}</span>
                            </button>
                        </div>
                        <ol><li>{{ $t('step_install') }}</li><li>{{ $t('step_paste') }}</li><li>{{ $t('step_check') }}</li></ol>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
