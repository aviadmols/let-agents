<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('shortlist::ui.'.$key, $with))
    @php($n = $this->numbers())

    <style>
        .lsl{--lsl-line:#e5e7eb;--lsl-soft:#f3f4f6;--lsl-card:#fff;--lsl-muted:#6b7280;--lsl-on:#0f766e}
        .dark .lsl{--lsl-line:#3f3f46;--lsl-soft:#27272a;--lsl-card:#18181b;--lsl-muted:#a1a1aa;--lsl-on:#14b8a6}
        .lsl{display:grid;gap:18px}
        .lsl-card{background:var(--lsl-card);border:1px solid var(--lsl-line);border-radius:14px;padding:20px 22px}
        .lsl-card h2{font-size:17px;font-weight:700;margin:0 0 4px}
        .lsl-help{color:var(--lsl-muted);font-size:13.5px;margin:0}
        .lsl-row{display:flex;align-items:flex-start;gap:14px;padding:14px 0;border-top:1px solid var(--lsl-line)}
        .lsl-row:first-of-type{border-top:0}
        .lsl-rt{flex:1;min-width:0}
        .lsl-rt b{display:block;font-weight:600;font-size:14.5px}
        .lsl-rt small{display:block;color:var(--lsl-muted);font-size:13px;margin-top:2px;line-height:1.45}
        .lsl-sw{position:relative;display:inline-block;width:44px;height:24px;flex:none;margin-top:4px}
        .lsl-sw input{position:absolute;inset:0;opacity:0;cursor:pointer;margin:0;z-index:1}
        .lsl-sw span{position:absolute;inset:0;border-radius:999px;background:#cbd0d6;transition:background .2s}
        .lsl-sw span:after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:inset-inline-start .2s}
        .lsl-sw input:checked+span{background:var(--lsl-on)}
        .lsl-sw input:checked+span:after{inset-inline-start:23px}
        .lsl-field{width:100%;box-sizing:border-box;border:1px solid var(--lsl-line);border-radius:10px;padding:9px 12px;font:inherit;font-size:14px;background:var(--lsl-card);color:inherit;margin-top:8px}
        .lsl-field.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;direction:ltr;text-align:left}
        .lsl-field.num{width:110px}
        .lsl-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:14px}
        .lsl-stat{border:1px solid var(--lsl-line);border-radius:12px;padding:12px 14px}
        .lsl-stat b{display:block;font-size:24px;font-weight:700}
        .lsl-stat span{color:var(--lsl-muted);font-size:13px}
        .lsl-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}
        .lsl-item{display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--lsl-line)}
        .lsl-item:first-of-type{border-top:0}
        .lsl-item img,.lsl-item .ph{width:44px;height:44px;border-radius:8px;object-fit:cover;background:var(--lsl-soft);flex:none}
        .lsl-item a{flex:1;min-width:0;color:inherit;text-decoration:none;font-size:14px}
        .lsl-item em{font-style:normal;font-weight:700}
        .lsl-save{border:0;border-radius:999px;padding:9px 20px;font:inherit;font-weight:600;cursor:pointer;background:#111827;color:#fff}
    </style>

    <div class="lsl">
        @if ($this->picksShop())
            <div>
                <select wire:model.live="shop" class="lsl-field" style="max-width:320px">
                    @foreach ($this->shops() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($n !== null)
            <div class="lsl-card">
                <h2>{{ $t('settings') }}</h2>
                <p class="lsl-help">{{ $t('settings_help') }}</p>

                <div class="lsl-row" style="margin-top:8px">
                    <div class="lsl-rt"><b>{{ __('shortlist::features.compare.label') }}</b><small>{{ __('shortlist::features.compare.description') }}</small></div>
                    <label class="lsl-sw"><input type="checkbox" wire:model.live="data.compare" data-switch="compare"><span></span></label>
                </div>
                @if ($data['compare'] ?? false)
                    <div class="lsl-row">
                        <div class="lsl-rt"><b>{{ __('shortlist::settings.compare_max.label') }}</b><small>{{ $t('range', ['min' => 2, 'max' => 5]) }}</small></div>
                        <input type="number" min="2" max="5" wire:model="data.compare_max" class="lsl-field num">
                    </div>
                @endif

                <div class="lsl-row">
                    <div class="lsl-rt"><b>{{ __('shortlist::features.wishlist.label') }}</b><small>{{ __('shortlist::features.wishlist.description') }}</small></div>
                    <label class="lsl-sw"><input type="checkbox" wire:model.live="data.wishlist" data-switch="wishlist"><span></span></label>
                </div>
                @if ($data['wishlist'] ?? false)
                    <div class="lsl-row">
                        <div class="lsl-rt"><b>{{ __('shortlist::settings.wishlist_max.label') }}</b><small>{{ __('shortlist::settings.wishlist_max.description') }}</small></div>
                        <input type="number" min="10" max="200" wire:model="data.wishlist_max" class="lsl-field num">
                    </div>
                @endif

                @if (($data['compare'] ?? false) || ($data['wishlist'] ?? false))
                    <div class="lsl-row">
                        <div class="lsl-rt">
                            <b>{{ __('shortlist::settings.card_selector.label') }}</b>
                            <small>{{ __('shortlist::settings.card_selector.description') }}</small>
                            <input type="text" wire:model="data.card_selector" class="lsl-field code" placeholder=".product-card, li.product">
                        </div>
                    </div>
                    <div class="lsl-row">
                        <div class="lsl-rt">
                            <b>{{ __('shortlist::settings.product_selector.label') }}</b>
                            <small>{{ __('shortlist::settings.product_selector.description') }}</small>
                            <input type="text" wire:model="data.product_selector" class="lsl-field code">
                        </div>
                    </div>
                @endif

                <div style="margin-top:14px">
                    <button type="button" class="lsl-save" wire:click="save" wire:loading.attr="disabled">{{ $t('save') }}</button>
                </div>
            </div>

            <div class="lsl-card">
                <h2>{{ $t('numbers', ['days' => \App\Modules\Shortlist\Filament\Operator\Pages\Shortlists::DAYS]) }}</h2>
                <div class="lsl-stats">
                    <div class="lsl-stat" data-stat="lists"><b>{{ number_format($n['lists']) }}</b><span>{{ $t('stat_lists') }}</span></div>
                    <div class="lsl-stat" data-stat="accounts"><b>{{ number_format($n['accounts']) }}</b><span>{{ $t('stat_accounts') }}</span></div>
                    <div class="lsl-stat" data-stat="saved"><b>{{ number_format($n['saved']) }}</b><span>{{ $t('stat_saved') }}</span></div>
                    <div class="lsl-stat" data-stat="compares"><b>{{ number_format($n['compares']) }}</b><span>{{ $t('stat_compares') }}</span></div>
                </div>
            </div>

            <div class="lsl-cols">
                @foreach (['wished' => 'top_wished', 'compared' => 'top_compared'] as $key => $heading)
                    <div class="lsl-card" data-top="{{ $key }}">
                        <h2>{{ $t($heading) }}</h2>
                        @forelse ($n[$key] as $product)
                            <div class="lsl-item">
                                @if ($product['image'])
                                    <img src="{{ $product['image'] }}" alt="" loading="lazy">
                                @else
                                    <div class="ph"></div>
                                @endif
                                <a href="{{ $product['url'] ?? '#' }}" target="_blank" rel="noopener">{{ $product['title'] }}</a>
                                <em>{{ $product['n'] }}</em>
                            </div>
                        @empty
                            <p class="lsl-help">{{ $t('none_yet') }}</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
