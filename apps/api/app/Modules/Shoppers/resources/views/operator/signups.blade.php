<x-filament-panels::page>

    @if ($this->waiting() !== [])
        <x-filament::section :heading="__('shoppers::ui.waiting.title')" :description="__('shoppers::ui.waiting.help')">
            <div style="display:flex;flex-direction:column;gap:7px">
                @foreach ($this->waiting() as $row)
                    <div style="display:flex;align-items:flex-start;gap:10px;padding:9px 11px;border-radius:10px;border:1px solid #ececee">
                        <div style="flex-grow:1;min-width:0">
                            <div style="font-size:13px;line-height:1.4">{{ $row['question'] }}</div>
                            <div style="margin-top:2px;font-size:11.5px;color:#9ca3af">
                                @if ($row['name'] ?? null)<strong style="color:#374151">{{ $row['name'] }}</strong> · @endif{{ $row['contact'] }} · {{ $row['at']?->diffForHumans() }} · #{{ $row['page'] }}
                            </div>
                        </div>
                        <x-filament::button size="xs" color="gray" wire:click="answered({{ $row['id'] }})">
                            {{ __('shoppers::ui.waiting.done') }}
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
    @php($data = $this->signUps())
    @php($small = 'font-size:12px;opacity:.7')

    <div>
        @if ($this->picksShop())
        <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;min-width:220px">
            @foreach ($this->shops() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        @endif
    </div>

    @if ($data === null)
        <x-filament::section>{{ __('shoppers::ui.signups.empty') }}</x-filament::section>
    @else
        @if (! $data['on'])
            <x-filament::section compact>
                <p style="{{ $small }}">{{ __('shoppers::ui.signups.off') }}</p>
            </x-filament::section>
        @endif

        @if (! $data['can_verify']['email'] || ! $data['can_verify']['phone'])
            <x-filament::section compact>
                @unless ($data['can_verify']['phone'])
                    <p style="{{ $small }}">{{ __('shoppers::ui.signups.no_sms') }}</p>
                @endunless
                @unless ($data['can_verify']['email'])
                    <p style="{{ $small }}">{{ __('shoppers::ui.signups.no_mail') }}</p>
                @endunless
            </x-filament::section>
        @endif

        @if ($data['people'] === [])
            <x-filament::section>{{ __('shoppers::ui.signups.empty') }}</x-filament::section>
        @else
            <x-filament::section :heading="trans_choice('shoppers::ui.signups.total', count($data['people']), ['count' => count($data['people'])])">
                <div style="display:grid;gap:12px">
                    @foreach ($data['people'] as $person)
                        <div style="padding:12px 14px;border:1px solid #e4e4e7;border-radius:10px;background:#fff">
                            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                                <strong dir="ltr">{{ $person['contact'] }}</strong>
                                <x-filament::badge color="gray">{{ __('shoppers::ui.signups.channel.'.$person['channel']) }}</x-filament::badge>
                                <x-filament::badge :color="$person['verified'] ? 'success' : 'warning'">
                                    {{ __($person['verified'] ? 'shoppers::ui.signups.verified' : 'shoppers::ui.signups.not_verified') }}
                                </x-filament::badge>
                                <span style="{{ $small }}">{{ trans_choice('shoppers::ui.signups.devices', $person['devices'], ['count' => $person['devices']]) }}</span>
                                <button
                                    type="button"
                                    wire:click="forget({{ $person['id'] }})"
                                    wire:confirm="{{ __('shoppers::ui.signups.forget_confirm') }}"
                                    style="margin-inline-start:auto;font-size:12px;padding:2px 10px;border:1px solid #d4d4d8;border-radius:999px;background:#fff"
                                >{{ __('shoppers::ui.signups.forget') }}</button>
                            </div>

                            <p style="margin-top:6px;{{ $small }}">
                                {{ __('shoppers::ui.signups.signed_up_at', ['date' => $person['signed_up_at']?->format('d/m/Y H:i')]) }}
                                @if ($person['last_seen_at'])
                                    · {{ __('shoppers::ui.signups.last_seen', ['date' => $person['last_seen_at']->format('d/m/Y H:i')]) }}
                                @endif
                                · {{ $person['consent_version']
                                    ? __('shoppers::ui.signups.consent', ['version' => $person['consent_version']])
                                    : __('shoppers::ui.signups.no_consent') }}
                            </p>

                            <p style="margin-top:8px;font-weight:600">{{ __('shoppers::ui.signups.viewed') }}</p>
                            @if ($person['viewed'] === [])
                                <p style="{{ $small }}">{{ __('shoppers::ui.signups.nothing_viewed') }}</p>
                            @else
                                <ul style="margin-top:4px;display:grid;gap:2px">
                                    @foreach ($person['viewed'] as $product)
                                        <li>{{ $product['title'] }}
                                            <span style="{{ $small }}">#{{ $product['id'] }} · {{ trans_choice('shoppers::ui.signups.views', $product['views'], ['count' => $product['views']]) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
