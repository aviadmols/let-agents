<x-filament-panels::page>
    {{-- Settings · My profile (ProfileSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::shop_settings.profile.'.$key, $params))

    <x-lp::card :title="$t('title')" style="max-width: 640px" data-profile>
        <div class="lp-acct-form">
            <label>{{ $t('name') }}
                <x-lp::field model="data.name" autocomplete="name" />
                @if ($this->problem('name'))<span class="lp-set-error" role="alert">{{ $this->problem('name') }}</span>@endif
            </label>
            <label>{{ $t('email') }}
                <x-lp::field model="data.email" type="email" ltr autocomplete="email" />
                @if ($this->problem('email'))<span class="lp-set-error" role="alert">{{ $this->problem('email') }}</span>@endif
            </label>
            <div class="lp-acct-full" style="grid-column: auto"><span>{{ $t('locale') }}</span><x-lp::chips model="data.locale" :options="__('admin::panels.locale.names')" /></div>
            <div class="lp-acct-full" style="grid-column: auto"><span>{{ $t('theme') }}</span><x-lp::chips model="data.theme" :options="['system' => __('admin::panels.top.themes.system'), 'light' => __('admin::panels.top.themes.light'), 'dark' => __('admin::panels.top.themes.dark')]" /></div>
            @unless ($hosted)
                <div class="lp-acct-full" data-password>
                    <span>{{ $t('password') }}</span>
                    @if (! $changingPassword)
                        <div><button type="button" class="lp-btn lp-btn-sm" wire:click="$set('changingPassword', true)" data-change-password>{{ $t('change_password') }}</button></div>
                    @else
                        <div class="lp-acct-form" style="width: 100%">
                            <label class="lp-acct-full">{{ $t('current') }}
                                <x-lp::field model="data.current" type="password" ltr autocomplete="current-password" />
                                @if ($this->problem('current'))<span class="lp-set-error" role="alert">{{ $this->problem('current') }}</span>@endif
                            </label>
                            <label>{{ $t('new_password') }}
                                <x-lp::field model="data.password" type="password" ltr autocomplete="new-password" />
                                @if ($this->problem('password'))<span class="lp-set-error" role="alert">{{ $this->problem('password') }}</span>@endif
                            </label>
                            <label>{{ $t('password_again') }}
                                <x-lp::field model="data.password_again" type="password" ltr autocomplete="new-password" />
                                @if ($this->problem('password_again'))<span class="lp-set-error" role="alert">{{ $this->problem('password_again') }}</span>@endif
                            </label>
                        </div>
                    @endif
                </div>
            @endunless
            <div class="lp-acct-full"><x-lp::save :label="__('admin::shop_settings.save_plain')" :note="false" /></div>
        </div>
    </x-lp::card>
</x-filament-panels::page>
