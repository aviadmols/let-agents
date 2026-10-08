<x-filament-panels::page>
    @php($agents = $this->agents())
    @php($models = $this->models())
    @php($providers = $this->providers())
    @php($chosen = $this->chosenShop())
    @php($small = 'font-size:12px;opacity:.7')
    @php($input = 'width:100%;padding:6px 10px;border:1px solid rgba(127,127,127,.35);border-radius:10px;font:inherit;background:transparent;color:inherit;min-width:0')
    @php($pill = 'display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600')

    @foreach ($models as $provider => $list)
        <datalist id="agent-models-{{ $provider }}">
            @foreach ($list as $model)
                <option value="{{ $model }}"></option>
            @endforeach
        </datalist>
    @endforeach

    <div style="display:grid;gap:16px">
        @foreach ($agents as $agent)
            <x-filament::section collapsible :collapsed="! $loop->first">
                <x-slot name="heading">
                    <span style="display:inline-flex;flex-wrap:wrap;gap:8px;align-items:center">
                        {{ __($agent['slug'].'::agents.'.$agent['name']) }}
                        @if ($agent['on'] !== null)
                            @php($allOn = ! in_array(false, $agent['on'], true))
                            <span style="{{ $pill }};{{ $allOn ? 'background:#e2ff78;color:#111' : 'background:rgba(127,127,127,.15)' }}">{{ __('ai::agents_screen.'.($allOn ? 'on' : 'off')) }}</span>
                        @endif
                    </span>
                </x-slot>
                <x-slot name="description">
                    {{ __($agent['slug'].'::agents_about.'.$agent['name']) }}
                    <br>
                    <span style="{{ $small }}">
                        {{ __('ai::agents_screen.module') }}: {{ __($agent['slug'].'::module.name') }}
                        · {{ $agent['when'] === 'live' || $agent['when'] === 'weekly' ? __('ai::agents_screen.when.'.$agent['when']) : __('ai::agents_screen.when.at', ['time' => $agent['when']]) }}
                        · {{ __('ai::agents_screen.week', ['runs' => number_format($agent['week']['runs']), 'failed' => number_format($agent['week']['failed']), 'cost' => number_format($agent['week']['cost'], 4)]) }}
                    </span>
                </x-slot>

                <div style="display:grid;gap:18px">
                    {{-- The switch. --}}
                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                        <strong>{{ __('ai::agents_screen.switch') }}</strong>
                        @if ($agent['on'] === null)
                            <span style="{{ $small }}">{{ __('ai::agents_screen.always_on') }}</span>
                        @else
                            @foreach ($agent['on'] as $feature => $on)
                                <span style="display:inline-flex;gap:6px;align-items:center">
                                    <span>{{ __(\Illuminate\Support\Str::before($feature, '.').'::features.'.\Illuminate\Support\Str::after($feature, '.').'.label') }}</span>
                                    <x-filament::button size="xs" :color="$on ? 'gray' : 'primary'" wire:click="toggle('{{ $feature }}', {{ $on ? 'false' : 'true' }})">
                                        {{ __('ai::agents_screen.'.($on ? 'turn_off' : 'turn_on')) }}
                                    </x-filament::button>
                                </span>
                            @endforeach
                            <span style="{{ $small }}">{{ __('ai::agents_screen.switch_note') }}</span>
                        @endif
                    </div>

                    @if ($agent['same_family'])
                        <div style="padding:10px 14px;border-radius:14px;background:rgba(235,104,52,.12);color:inherit">⚠ {{ __('ai::agents_screen.same_family') }}</div>
                    @endif

                    {{-- Each role: provider, model, prices, prompts. --}}
                    @foreach ($agent['roles'] as $role)
                        @php($providerField = \App\Modules\Ai\Filament\Operator\Pages\Agents::field($role['provider']))
                        <div style="display:grid;gap:10px;padding:14px;border:1px solid rgba(127,127,127,.2);border-radius:16px">
                            <strong>{{ __('ai::agents_screen.roles.'.$role['role']) }}</strong>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
                                <label style="display:grid;gap:4px">
                                    <span style="{{ $small }}">{{ __('ai::agents_screen.provider') }}</span>
                                    <select wire:model.live="values.{{ $providerField }}" style="{{ $input }}">
                                        @foreach ($providers as $provider)
                                            <option value="{{ $provider }}">{{ \App\Modules\Ai\Enums\AiProviderName::from($provider)->label() }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label style="display:grid;gap:4px">
                                    <span style="{{ $small }}">{{ __('ai::agents_screen.model') }}</span>
                                    <input type="text" dir="ltr" wire:model="values.{{ \App\Modules\Ai\Filament\Operator\Pages\Agents::field($role['model']) }}" list="agent-models-{{ $this->values[$providerField] ?? '' }}" title="{{ __('ai::agents_screen.models_hint') }}" style="{{ $input }}">
                                </label>
                                @foreach ($role['prices'] as $price)
                                    <label style="display:grid;gap:4px">
                                        <span style="{{ $small }}">{{ __(\Illuminate\Support\Str::before($price, '.').'::settings.'.\Illuminate\Support\Str::after($price, '.').'.label') }}</span>
                                        <input type="number" step="any" min="0" dir="ltr" wire:model="values.{{ \App\Modules\Ai\Filament\Operator\Pages\Agents::field($price) }}" style="{{ $input }}">
                                    </label>
                                @endforeach
                            </div>

                            <div style="display:grid;gap:6px">
                                <span style="{{ $small }}">{{ __('ai::agents_screen.prompts') }}</span>
                                @forelse ($role['prompts'] as $prompt)
                                    <details style="border:1px solid rgba(127,127,127,.2);border-radius:12px;padding:8px 12px">
                                        <summary style="cursor:pointer"><code dir="ltr">{{ $prompt['name'] }}</code> · {{ __('ai::agents_screen.version', ['version' => $prompt['latest']]) }}</summary>
                                        <pre dir="ltr" style="white-space:pre-wrap;font-size:12.5px;line-height:1.55;margin:10px 0 0">{{ $this->promptText($prompt['path'], $prompt['latest']) }}</pre>
                                        @if (count($prompt['versions']) > 1)
                                            <details style="margin-top:8px">
                                                <summary style="cursor:pointer;{{ $small }}">{{ __('ai::agents_screen.older') }}</summary>
                                                @foreach (array_reverse(array_slice($prompt['versions'], 0, -1)) as $version)
                                                    <details style="margin:6px 0 0 0">
                                                        <summary style="cursor:pointer">{{ __('ai::agents_screen.version', ['version' => $version]) }}</summary>
                                                        <pre dir="ltr" style="white-space:pre-wrap;font-size:12px;line-height:1.5;opacity:.85">{{ $this->promptText($prompt['path'], $version) }}</pre>
                                                    </details>
                                                @endforeach
                                            </details>
                                        @endif
                                    </details>
                                @empty
                                    <span style="{{ $small }}">{{ __('ai::agents_screen.no_prompts') }}</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach

                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                        <x-filament::button wire:click="save('{{ $agent['key'] }}')">{{ __('ai::agents_screen.save') }}</x-filament::button>
                        @if ($agent['run'])
                            @if ($chosen)
                                <x-filament::button color="gray" icon="heroicon-o-play" wire:click="runNow('{{ $agent['key'] }}')" wire:loading.attr="disabled" data-run-now="{{ $agent['key'] }}">{{ __('ai::agents_screen.run_now', ['shop' => $chosen['name']]) }}</x-filament::button>
                            @else
                                <span style="{{ $small }}">{{ __('ai::agents_screen.run_needs_shop') }}</span>
                            @endif
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
