<x-filament-panels::page>
    @php($rows = $this->rows())
    <div class="la-page">
        <section class="la-card">
            @if ($rows === [])
                <p class="la-empty">{{ __('ai::budget.screen.none') }}</p>
            @else
                <div class="la-table-wrap">
                    <table class="la-table" data-budgets>
                        <thead>
                            <tr>
                                <th>{{ __('ai::budget.screen.columns.shop') }}</th>
                                <th style="width: 24%">{{ __('ai::budget.screen.columns.used') }}</th>
                                <th class="la-num">{{ __('ai::budget.screen.columns.spent') }}</th>
                                <th class="la-num">{{ __('ai::budget.screen.columns.cap') }}</th>
                                <th class="la-num">{{ __('ai::budget.screen.columns.calls') }}</th>
                                <th>{{ __('ai::budget.screen.columns.top') }}</th>
                                <th>{{ __('ai::budget.screen.columns.state') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr wire:key="budget-{{ $row['id'] }}" data-shop="{{ $row['id'] }}">
                                    <td>
                                        <span class="la-strong">{{ $row['name'] }}</span>
                                        <small>{{ $row['platform'] }}</small>
                                    </td>
                                    <td>
                                        <span class="la-meter">
                                            <span class="la-bar" style="flex: 1; width: auto"><i style="width: {{ min($row['percent'], 100) }}%" @if ($row['tone'] !== '') data-tone="{{ $row['tone'] }}" @endif></i></span>
                                            <span class="la-strong">{{ $row['percent'] }}%</span>
                                        </span>
                                    </td>
                                    <td class="la-num la-strong">${{ number_format($row['spent'], 2) }}</td>
                                    <td class="la-num">
                                        ${{ \App\Modules\Ai\Filament\Operator\Pages\ShopBudgets::money($row['cap']) }}
                                        @if ($row['own'])
                                            <span class="la-badge" data-tone="info">{{ __('ai::budget.screen.own') }}</span>
                                        @endif
                                    </td>
                                    <td class="la-num">{{ number_format($row['calls']) }}</td>
                                    <td><small>{{ $row['top'] ?? '—' }}</small></td>
                                    <td>
                                        <span class="la-badge" data-tone="{{ ['ok' => 'good', 'near' => 'warn', 'over' => 'bad', 'idle' => ''][$row['state']] }}">{{ __('ai::budget.screen.state.'.$row['state']) }}</span>
                                    </td>
                                    <td class="la-num">
                                        <button type="button" class="la-link" style="background: none; border: 0; cursor: pointer" wire:click="edit('{{ $row['id'] }}')">{{ __('ai::budget.screen.edit') }}</button>
                                    </td>
                                </tr>
                                @if ($editing === $row['id'])
                                    <tr wire:key="budget-edit-{{ $row['id'] }}">
                                        <td colspan="8" style="background: var(--la-sunk)">
                                            <form wire:submit="save" class="la-stack" style="max-width: 560px; padding-block: 6px" data-budget-form>
                                                <p class="la-eyebrow">{{ __('ai::budget.screen.form.title', ['shop' => $row['name']]) }}</p>
                                                <div class="la-toolbar">
                                                    <label class="la-chip" data-on>
                                                        $ <input type="number" min="0" max="10000" step="1" wire:model="cap" aria-label="{{ __('ai::budget.screen.form.cap') }}" style="width: 90px">
                                                    </label>
                                                    @foreach ([30, 45, 60, 100] as $preset)
                                                        <button type="button" class="la-btn la-btn-sm" wire:click="$set('cap', '{{ $preset }}')">${{ $preset }}</button>
                                                    @endforeach
                                                </div>
                                                @error('cap')
                                                    <p class="la-note" style="color: var(--la-bad)">{{ $message }}</p>
                                                @enderror
                                                <label class="la-switch"><input type="checkbox" wire:model="stops"> {{ __('ai::budget.screen.form.stops') }}</label>
                                                <p class="la-note">{{ __('ai::budget.screen.form.note') }}</p>
                                                <div class="la-actions">
                                                    <button type="submit" class="la-btn la-btn-primary">{{ __('ai::budget.screen.form.save') }}</button>
                                                    <button type="button" class="la-btn la-btn-quiet" wire:click="cancel">{{ __('ai::budget.screen.form.cancel') }}</button>
                                                    @if ($row['own'])
                                                        <button type="button" class="la-btn la-btn-quiet" wire:click="useDefault">{{ __('ai::budget.screen.form.reset', ['default' => \App\Modules\Ai\Filament\Operator\Pages\ShopBudgets::money(\App\Modules\Ai\Filament\Operator\Pages\ShopBudgets::defaultCap())]) }}</button>
                                                    @endif
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="8">{{ __('ai::budget.screen.totals', [
                                    'spent' => number_format(array_sum(array_column($rows, 'spent')), 2),
                                    'caps' => number_format(array_sum(array_column($rows, 'cap')), 2),
                                    'calls' => number_format(array_sum(array_column($rows, 'calls'))),
                                ]) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
