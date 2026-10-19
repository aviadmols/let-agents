<x-filament-panels::page>
    {{-- Settings · Connection and plan (ConnectionSettings), as the designer's panel lays it out, and the plugin of a WordPress store. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::shop_settings.connection.'.$key, $params))
    @php($d = $this->details())

    <x-lp::card :title="$t('title')" style="max-width: 640px" data-connection>
        <x-slot:head>
            <span class="lp-pill" data-tone="{{ $d['connected'] ? 'good' : 'bad' }}"><i></i>{{ $t($d['connected'] ? 'connected' : 'not_connected') }}</span>
        </x-slot:head>
        <x-lp::kv>
            <x-lp::kv-row :label="$t('platform')">{{ $d['platform'] }}</x-lp::kv-row>
            <x-lp::kv-row :label="$t('store')"><span dir="ltr">{{ $d['domain'] }}</span></x-lp::kv-row>
            <x-lp::kv-row :label="$t('synced')">{{ $d['synced'] }}</x-lp::kv-row>
            <x-lp::kv-row :label="$t('plan')">{{ $d['plan'] }}</x-lp::kv-row>
            <x-lp::kv-row :label="$t('usage')" :tip="$t('usage_tip')">
                @if ($d['usage'] !== null)
                    <span style="display: inline-flex; align-items: center; gap: 8px">
                        <span class="lp-bar" @if ($d['usage']['tone'] !== '') data-tone="{{ $d['usage']['tone'] }}" @endif><i style="width: {{ min(100, $d['usage']['percent']) }}%"></i></span>
                        <span dir="ltr">{{ min(100, $d['usage']['percent']) }}%</span>
                    </span>
                @else
                    —
                @endif
            </x-lp::kv-row>
        </x-lp::kv>
    </x-lp::card>

    @if ($d['woo'])
        @php($package = $this->package())
        <x-lp::card :title="$t('plugin_title')" :help="$t('plugin_help')" style="max-width: 640px" data-plugin>
            <x-lp::row :title="$t('download')" :sub="$package ? $t('download_sub', ['version' => $package['version']]) : $t('no_package')">
                @if ($package && $this->canEdit())
                    <button type="button" class="lp-btn lp-btn-sm" wire:click="downloadPlugin" wire:loading.attr="disabled" wire:target="downloadPlugin" data-download>{{ $t('download') }}</button>
                @endif
            </x-lp::row>
            <x-lp::row :title="$t('token')" :sub="$d['has_token'] ? $t('token_replaces') : $t('token_sub')">
                @if ($this->canConnect())
                    <button type="button" class="lp-btn lp-btn-sm" wire:click="createToken" wire:loading.attr="disabled" wire:target="createToken"
                        @if ($d['has_token']) wire:confirm="{{ $t('token_confirm') }}" @endif data-create-token>{{ $t('token') }}</button>
                @else
                    <span class="lp-set-note">{{ __('admin::shop_settings.owners_only') }}</span>
                @endif
            </x-lp::row>

            @if ($token)
                <div class="lp-fact" style="flex-direction: column; margin-top: 12px" data-token x-data="{ copied: false }">
                    <b>{{ $t('token_once') }}</b>
                    <span style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; width: 100%">
                        <span class="lp-set-field lp-set-wide" style="flex: 1; min-width: 220px"><input type="text" readonly dir="ltr" value="{{ $token }}" x-ref="token" x-on:focus="$event.target.select()" aria-label="{{ $t('token') }}"></span>
                        <button type="button" class="lp-btn lp-btn-primary lp-btn-sm"
                            x-on:click="navigator.clipboard?.writeText($refs.token.value).then(() => copied = true).catch(() => { $refs.token.select(); document.execCommand('copy'); copied = true })">
                            <span x-show="! copied">{{ $t('copy') }}</span><span x-show="copied" x-cloak>{{ $t('copied') }}</span>
                        </button>
                    </span>
                    <ol style="margin: 4px 0 0; padding-inline-start: 18px; display: flex; flex-direction: column; gap: 4px">
                        <li>{{ $t('step_install') }}</li>
                        <li>{{ $t('step_paste') }}</li>
                        <li>{{ $t('step_check') }}</li>
                    </ol>
                </div>
            @endif

            @if ($this->canEdit())
                <x-lp::row :title="$t('check')" :sub="$t('check_sub')">
                    <button type="button" class="lp-btn lp-btn-sm" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection" data-check>{{ $t('check') }}</button>
                </x-lp::row>
            @endif
        </x-lp::card>
    @endif
</x-filament-panels::page>
