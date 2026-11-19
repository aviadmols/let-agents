<x-filament-panels::page>
    {{-- Settings · Products that go together (GoesWithSettings): what the assistant offers to go with a product, and how the store focuses it. --}}
    @php($t = fn (string $key, array $params = []) => __('assistant::goes_with.'.$key, $params))
    @php($products = $this->bounds('assistant.goes_with_products'))
    @php($picks = $this->bounds('assistant.goes_with_max_picks'))

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.on">
        <div x-data x-bind:style="$wire.data.on ? '' : 'opacity: .5'">
            <div class="lp-set-label">{{ $t('sources') }}</div>
            <x-lp::row :title="$t('use_relations')" :sub="$t('use_relations_sub')"><x-lp::toggle model="data.use_relations" :label="$t('use_relations')" /></x-lp::row>
            <x-lp::row :title="$t('use_map')" :sub="$t('use_map_sub')"><x-lp::toggle model="data.use_map" :label="$t('use_map')" /></x-lp::row>
            <x-lp::row :title="$t('use_look')" :sub="$t('use_look_sub')"><x-lp::toggle model="data.use_look" :label="$t('use_look')" /></x-lp::row>

            <div class="lp-set-label">{{ $t('how_many') }}</div>
            <x-lp::row :title="$t('products')" :sub="$t('products_sub')">
                <x-lp::num model="data.products" :min="$products['min']" :max="$products['max']" :label="$t('products')" />
            </x-lp::row>
            @if ($this->problem('products'))<span class="lp-set-error" role="alert">{{ $this->problem('products') }}</span>@endif
            <x-lp::row :title="$t('max_picks')" :sub="$t('max_picks_sub')">
                <x-lp::num model="data.max_picks" :min="$picks['min']" :max="$picks['max']" :label="$t('max_picks')" />
            </x-lp::row>

            <div class="lp-set-label">{{ $t('pairs') }}</div>
            <p class="lp-note" style="margin: 0 0 8px">{{ $t('pairs_sub') }}</p>
            @if ($choices['types'] === [])
                <p class="lp-note">{{ $t('no_types') }}</p>
            @else
                @foreach ($data['pairs'] ?? [] as $i => $pair)
                    <div class="lp-set-row" wire:key="pair-{{ $i }}" style="gap: 8px; flex-wrap: wrap">
                        <select class="lp-select" wire:model="data.pairs.{{ $i }}.a" aria-label="{{ $t('pair_a') }}">
                            <option value="">{{ $t('pick_kind') }}</option>
                            @foreach ($choices['types'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <x-lp::chips model="data.pairs.{{ $i }}.allow" :options="[1 => $t('allow'), 0 => $t('deny')]" :sm="true" :label="$t('allow_or_deny')" />
                        <select class="lp-select" wire:model="data.pairs.{{ $i }}.b" aria-label="{{ $t('pair_b') }}">
                            <option value="">{{ $t('pick_kind') }}</option>
                            @foreach ($choices['types'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" class="lp-btn lp-btn-sm" wire:click="removePair({{ $i }})" aria-label="{{ $t('remove') }}">×</button>
                    </div>
                @endforeach
                <button type="button" class="lp-btn lp-btn-sm" wire:click="addPair">{{ $t('add_pair') }}</button>
            @endif
            @if ($this->problem('pairs'))<span class="lp-set-error" role="alert">{{ $this->problem('pairs') }}</span>@endif

            <div class="lp-set-label">{{ $t('never') }}</div>
            <p class="lp-note" style="margin: 0 0 8px">{{ $t('never_sub') }}</p>
            @foreach ($data['never'] ?? [] as $i => $rule)
                <div class="lp-set-row" wire:key="never-{{ $i }}" style="gap: 8px; flex-wrap: wrap">
                    <select class="lp-select" wire:model.live="data.never.{{ $i }}.scope" aria-label="{{ $t('scope') }}">
                        @foreach (\App\Modules\Assistant\Models\AssistantGoesWithRules::SCOPES as $scope)<option value="{{ $scope }}">{{ $t('scopes.'.$scope) }}</option>@endforeach
                    </select>
                    @php($scope = (string) ($rule['scope'] ?? 'type'))
                    @php($options = $scope === 'type' ? $choices['types'] : ($choices[$scope] ?? []))
                    @if ($options !== [])
                        <select class="lp-select" wire:model="data.never.{{ $i }}.value" aria-label="{{ $t('value') }}">
                            <option value="">{{ $t('pick_value') }}</option>
                            @foreach ($options as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    @else
                        <input type="text" class="lp-input" wire:model="data.never.{{ $i }}.value" maxlength="120" placeholder="{{ $t('pick_value') }}" aria-label="{{ $t('value') }}" />
                    @endif
                    <button type="button" class="lp-btn lp-btn-sm" wire:click="removeNever({{ $i }})" aria-label="{{ $t('remove') }}">×</button>
                </div>
            @endforeach
            <button type="button" class="lp-btn lp-btn-sm" wire:click="addNever">{{ $t('add_never') }}</button>
            @if ($this->problem('never'))<span class="lp-set-error" role="alert">{{ $this->problem('never') }}</span>@endif
            @if ($this->problem('rules'))<span class="lp-set-error" role="alert">{{ $this->problem('rules') }}</span>@endif
        </div>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
