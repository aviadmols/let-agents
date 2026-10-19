<x-filament-panels::page>
    {{-- Settings · Users (UsersSettings), as the designer's panel lays it out: who signs in, and inviting someone. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::shop_settings.users.'.$key, $params))
    @php($people = $this->people())
    @php($manage = $this->canManage())

    <div class="lp-set-grid">
        <x-lp::card :title="$t('who')" data-people>
            <x-slot:head><span class="lp-sample">{{ trans_choice('admin::shop_settings.users.count', count($people), ['count' => count($people)]) }}</span></x-slot:head>
            <div>
                @foreach ($people as $person)
                    <div class="lp-row" wire:key="{{ $person['key'] }}" data-person="{{ $person['kind'] }}">
                        <span @class(['lp-top-av', 'lp-top-av-ink' => $person['owner']]) aria-hidden="true">{{ $person['initial'] }}</span>
                        <div class="lp-grow" style="min-width: 0">
                            <div class="lp-row-t" @if ($person['kind'] === 'invitation') dir="ltr" style="text-align: start; unicode-bidi: plaintext" @endif>{{ $person['name'] }}</div>
                            <div class="lp-row-s">{{ $person['line'] }}</div>
                        </div>
                        @if ($person['kind'] === 'invitation')
                            <span class="lp-pill" data-tone="warn">{{ $t($person['expired'] ? 'expired_pill' : 'waiting') }}</span>
                            @if ($manage)
                                <button type="button" class="lp-link" wire:click="resend('{{ $person['id'] }}')" wire:loading.attr="disabled">{{ $t('resend') }}</button>
                            @endif
                        @elseif (! $person['owner'])
                            <span class="lp-chip lp-chip-sm lp-chip-quiet">{{ $person['role']->label() }}</span>
                        @endif
                        @if ($manage && $person['removable'])
                            <button type="button" class="lp-link" wire:click="remove({{ $person['id'] }})" wire:confirm="{{ $t('remove_confirm', ['name' => $person['name']]) }}">{{ $t('remove') }}</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-lp::card>

        <x-lp::card :title="$t('invite_title')" data-invite>
            @if ($manage)
                <div class="lp-acct-form">
                    <label>{{ $t('name') }}<x-lp::field model="data.name" :placeholder="$t('name_placeholder')" /></label>
                    <label>{{ $t('email') }}
                        <x-lp::field model="data.email" type="email" ltr :placeholder="'name@'.\Filament\Facades\Filament::getTenant()?->domain" />
                        @if ($this->problem('email'))<span class="lp-set-error" role="alert">{{ $this->problem('email') }}</span>@endif
                    </label>
                    <div class="lp-acct-full"><span class="lp-acct-label">{{ $t('role') }}</span><x-lp::opts model="data.role" :options="$this->roles()" :columns="3" /></div>
                    <div class="lp-acct-full"><span class="lp-acct-label">{{ $t('locale') }}</span><x-lp::chips model="data.locale" :options="__('admin::panels.locale.names')" /></div>
                    <div class="lp-acct-full"><x-lp::save :label="$t('send')" :note="$t('send_note')" action="invite" /></div>
                </div>
            @else
                <p class="lp-set-help" style="margin: 0">{{ __('admin::shop_settings.owners_only') }}</p>
            @endif
        </x-lp::card>
    </div>
</x-filament-panels::page>
