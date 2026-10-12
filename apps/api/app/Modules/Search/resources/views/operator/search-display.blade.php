<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('search::ui.display.'.$key, $with))
    @php($d = $this->data)
    @php($status = $this->status())
    @php($products = $this->previewProducts())
    @php($mode = $d['results'] ?? 'panel')
    @php($accent = ($d['own_color'] ?? false) ? ($d['color'] ?? '#111827') : '#111827')
    @php($hours = rtrim((string) \Filament\Facades\Filament::getUrl(), '/').'/opening-hours')

    <style>
        .lad{--lad-line:#e5e7eb;--lad-soft:#f3f4f6;--lad-card:#fff;--lad-muted:#6b7280;--lad-on:#0f766e;--lad-on-soft:#e6f4f1;--lad-on-ink:#0f5f58;--lad-ink:#111827}
        .dark .lad{--lad-line:#3f3f46;--lad-soft:#27272a;--lad-card:#18181b;--lad-muted:#a1a1aa;--lad-on:#14b8a6;--lad-on-soft:#134e4a;--lad-on-ink:#99f6e4;--lad-ink:#f4f4f5}
        .lad-cols{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start}
        .lad-form{flex:999 1 520px;min-width:0;display:grid;gap:18px}
        .lad-prev{flex:1 1 400px;max-width:560px;min-width:0;position:sticky;top:80px}
        .lad-card{background:var(--lad-card);border:1px solid var(--lad-line);border-radius:14px;padding:20px 22px}
        .lad-card h2{font-size:17px;font-weight:700;margin:0}
        .lad-help{color:var(--lad-muted);font-size:13.5px;margin:4px 0 0}
        .lad-row{display:flex;align-items:flex-start;gap:14px;padding:14px 0;border-top:1px solid var(--lad-line)}
        .lad-row:first-of-type{border-top:0}
        .lad-rt{flex:1;min-width:0}
        .lad-rt b{display:block;font-weight:600;font-size:14.5px}
        .lad-rt small{display:block;color:var(--lad-muted);font-size:13px;margin-top:2px;line-height:1.45}
        .lad-chip{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:2px 9px;border-radius:999px;background:var(--lad-soft);margin-top:8px;margin-inline-end:6px}
        .lad-chip.ok{background:var(--lad-on-soft);color:var(--lad-on-ink)}
        .lad-chip a{text-decoration:underline}
        .lad-sw{position:relative;display:inline-block;width:44px;height:24px;flex:none;margin-top:4px}
        .lad-sw input{position:absolute;inset:0;opacity:0;cursor:pointer;margin:0;z-index:1}
        .lad-sw span{position:absolute;inset:0;border-radius:999px;background:#cbd0d6;transition:background .2s}
        .lad-sw span:after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:inset-inline-start .2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
        .lad-sw input:checked+span{background:var(--lad-on)}
        .lad-sw input:checked+span:after{inset-inline-start:23px}
        .lad-sw input:focus-visible+span{outline:2px solid var(--lad-on);outline-offset:2px}
        .lad-modes{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:14px}
        .lad-mode{border:1.5px solid var(--lad-line);border-radius:12px;padding:12px;background:var(--lad-card);text-align:start;cursor:pointer;display:flex;flex-direction:column;gap:8px;font:inherit;color:inherit}
        .lad-mode[aria-checked="true"]{border-color:var(--lad-on);box-shadow:0 0 0 1px var(--lad-on) inset}
        .lad-mode b{font-weight:600;font-size:14.5px}
        .lad-mode small{color:var(--lad-muted);font-size:12.5px;line-height:1.4}
        .lad-thumb{height:70px;border-radius:8px;background:var(--lad-soft);position:relative;overflow:hidden}
        .lad-thumb i{position:absolute;display:block}
        .lad-seg{display:inline-flex;border:1px solid var(--lad-line);border-radius:10px;overflow:hidden}
        .lad-seg button{border:0;background:var(--lad-card);color:inherit;padding:8px 14px;font:inherit;font-size:13.5px;cursor:pointer}
        .lad-seg button[aria-checked="true"]{background:var(--lad-ink);color:var(--lad-card)}
        .lad-opt{display:flex;flex-wrap:wrap;gap:24px;margin-top:16px;padding-top:16px;border-top:1px solid var(--lad-line)}
        .lad-lbl{display:block;font-size:13.5px;font-weight:600;margin-bottom:8px}
        .lad-range{display:flex;align-items:center;gap:10px}
        .lad-range input{accent-color:var(--lad-on);width:220px;max-width:100%}
        .lad-grp{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--lad-line);border-radius:10px;margin-top:8px}
        .lad-grp span.n{flex:1}
        .lad-arrow{border:1px solid var(--lad-line);background:var(--lad-card);color:inherit;border-radius:8px;width:30px;height:30px;cursor:pointer;font-size:14px;line-height:1}
        .lad-arrow[disabled]{opacity:.35;cursor:default}
        .lad-chk{display:flex;align-items:center;gap:10px;font-size:14px;min-height:32px;cursor:pointer}
        .lad-chk input{width:17px;height:17px;accent-color:var(--lad-on);margin:0}
        .lad-field{width:100%;box-sizing:border-box;border:1px solid var(--lad-line);border-radius:10px;padding:9px 12px;font:inherit;font-size:14px;background:var(--lad-card);color:inherit}
        .lad-field.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;direction:ltr;text-align:left}
        .lad-pv{background:var(--lad-card);border:1px solid var(--lad-line);border-radius:14px;overflow:hidden}
        .lad-pvh{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--lad-line);font-size:13px;color:var(--lad-muted)}
        .lad-pvh .lad-seg button{padding:4px 10px;font-size:12.5px}
        .lad-store{background:#fff;color:#111827;min-height:560px;position:relative;font-size:12.5px;overflow:hidden;margin:0 auto}
        .lad-store.phone{max-width:340px;border-inline:1px solid #e5e7eb}
        .lad-shdr{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #eee;font-weight:800}
        .lad-sin{display:flex;align-items:center;gap:8px;border:1.5px solid #111827;border-radius:10px;padding:8px 12px;margin:12px 16px 0;color:#6b7280}
        .lad-sin span{flex:1}
        .lad-ph{margin:12px 16px;height:90px;border-radius:10px;background:#f3f4f6}
        .lad-pop{position:absolute;inset-inline:16px;top:96px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 24px 44px -22px rgba(0,0,0,.3);z-index:2;overflow:hidden}
        .lad-dim{position:absolute;inset:0;background:rgba(17,24,39,.38);z-index:3}
        .lad-drawer{position:absolute;top:0;bottom:0;width:68%;background:#fff;z-index:4;display:flex;flex-direction:column;box-shadow:0 0 40px rgba(0,0,0,.25);inset-inline-start:0}
        .lad-drawer.end{inset-inline-start:auto;inset-inline-end:0}
        .lad-store.phone .lad-drawer{width:100%}
        .lad-dh{display:flex;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #f0f1f3;font-weight:600}
        .lad-din{margin:8px 10px;border:1.5px solid #111827;border-radius:10px;padding:7px 10px;color:#6b7280}
        .lad-sec{padding:10px 12px}
        .lad-sec h4{margin:0 0 6px;font-size:11px;color:#6b7280;font-weight:600}
        .lad-grid{display:grid;gap:10px}
        .lad-pc{display:flex;flex-direction:column;gap:5px;min-width:0}
        .lad-pi{aspect-ratio:1;border-radius:8px;background:#ece6dc;object-fit:cover;width:100%;display:block}
        .lad-pt{font-size:11.5px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .lad-pp{font-size:11.5px;font-weight:700}
        .lad-pb{display:flex;gap:5px}
        .lad-buy{flex:1;border-radius:7px;border:1px solid var(--lad-accent);background:var(--lad-accent);color:var(--lad-accent-fg);font-size:10.5px;padding:5px;text-align:center}
        .lad-sim{border-radius:7px;border:1px solid var(--lad-accent);color:var(--lad-accent);font-size:10.5px;padding:5px 7px;background:#fff}
        .lad-chips{display:flex;gap:6px;flex-wrap:wrap}
        .lad-chips span{border:1px solid #e5e7eb;border-radius:999px;padding:2px 8px;font-size:11.5px}
        .lad-line{padding:5px 0;border-top:1px solid #f0f1f3}
        .lad-foot{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-top:1px solid #f0f1f3}
        .lad-wa{padding:7px 12px;background:#ecfdf3;color:#166534;border-top:1px solid #dcfce7}
        .lad-note{font-size:12.5px;color:var(--lad-muted);padding:10px 14px;border-top:1px solid var(--lad-line)}
    </style>

    @if ($this->picksShop())
        <div>
            <select wire:model.live="shop" class="lad-field" style="max-width:280px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($this->shop === null)
        <x-filament::section>{{ __('search::ui.no_shop') }}</x-filament::section>
    @else
    <div class="lad" style="--lad-accent:{{ $accent }};--lad-accent-fg:{{ \App\Modules\Search\Support\ReadableInk::on($accent) }}">
    <form wire:submit="save" class="lad-cols">
        <div class="lad-form">

            {{-- What works in the search box --}}
            <section class="lad-card">
                <h2>{{ $t('works.title') }}</h2>
                <p class="lad-help">{{ $t('works.help') }}</p>
                <div style="margin-top:6px">
                    @foreach (['storefront', 'photos', 'ask', 'whatsapp'] as $key)
                        <div class="lad-row">
                            <div class="lad-rt">
                                <b id="lad-{{ $key }}">{{ $t('works.'.$key.'.label') }}</b>
                                <small>{{ $t('works.'.$key.'.help') }}</small>
                                @if ($key === 'photos')
                                    @if ($status['pictures'])
                                        <span class="lad-chip ok">{{ $t('works.photos.ready') }}</span>
                                    @else
                                        <span class="lad-chip">{{ $t('works.photos.not_ready') }}</span>
                                    @endif
                                @elseif ($key === 'whatsapp')
                                    @if ($status['number'] !== null)
                                        <span class="lad-chip ok" dir="ltr">+{{ $status['number'] }}</span>
                                    @else
                                        <span class="lad-chip">{{ $t('works.whatsapp.no_number') }}</span>
                                    @endif
                                    <span class="lad-chip"><a href="{{ $hours }}">{{ $t('works.whatsapp.hours') }}</a></span>
                                    <label class="lad-lbl" for="lad-wa-msg" style="margin-top:12px">{{ $t('works.whatsapp.message') }}</label>
                                    <input id="lad-wa-msg" class="lad-field" type="text" maxlength="300" wire:model.blur="data.whatsapp_message" placeholder="{{ __('search::storefront.wa_message') }}">
                                    <small>{{ $t('works.whatsapp.message_help') }}</small>
                                @endif
                            </div>
                            <label class="lad-sw">
                                <input type="checkbox" role="switch" aria-labelledby="lad-{{ $key }}" wire:model.live="data.{{ $key }}">
                                <span></span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Where all the results open --}}
            <section class="lad-card">
                <h2>{{ $t('where.title') }}</h2>
                <p class="lad-help">{{ $t('where.help') }}</p>
                <div class="lad-modes" role="radiogroup" aria-label="{{ $t('where.title') }}">
                    @foreach (['panel', 'drawer', 'page'] as $option)
                        <button type="button" role="radio" aria-checked="{{ $mode === $option ? 'true' : 'false' }}" class="lad-mode" wire:click="pickResults('{{ $option }}')">
                            <span class="lad-thumb" aria-hidden="true">
                                <i style="top:8px;inset-inline:10px;height:8px;border-radius:3px;background:#fff;border:1px solid #c7ccd3"></i>
                                @if ($option === 'panel')
                                    <i style="top:20px;inset-inline:10px;bottom:8px;border-radius:5px;background:#fff;border:1px solid #d6dae0"></i>
                                @elseif ($option === 'drawer')
                                    <i style="inset:0;background:rgba(17,24,39,.25)"></i>
                                    <i style="top:0;bottom:0;inset-inline-start:0;width:55%;background:#fff"></i>
                                @else
                                    @for ($n = 0; $n < 8; $n++)
                                        <i style="top:{{ $n < 4 ? 24 : 46 }}px;inset-inline-start:{{ 12 + ($n % 4) * 22 }}px;width:16px;height:16px;border-radius:3px;background:#d9cfbf"></i>
                                    @endfor
                                @endif
                            </span>
                            <b>{{ $t('where.'.$option.'.label') }}</b>
                            <small>{{ $t('where.'.$option.'.help') }}</small>
                        </button>
                    @endforeach
                </div>

                @if ($mode === 'drawer')
                    <div class="lad-opt">
                        <div>
                            <span class="lad-lbl">{{ $t('where.side') }}</span>
                            <div class="lad-seg" role="radiogroup" aria-label="{{ $t('where.side') }}">
                                @foreach (['start', 'end'] as $side)
                                    <button type="button" role="radio" aria-checked="{{ ($d['drawer_side'] ?? 'start') === $side ? 'true' : 'false' }}" wire:click="$set('data.drawer_side', '{{ $side }}')">{{ $t('where.sides.'.$side) }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <span class="lad-lbl">{{ $t('where.columns') }}</span>
                            <div class="lad-seg" role="radiogroup" aria-label="{{ $t('where.columns') }}">
                                @foreach (['2', '1'] as $columns)
                                    <button type="button" role="radio" aria-checked="{{ (string) ($d['drawer_columns'] ?? '2') === $columns ? 'true' : 'false' }}" wire:click="$set('data.drawer_columns', '{{ $columns }}')">{{ $t('where.columns_'.$columns) }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <div class="lad-row" style="margin-top:16px;border-top:1px solid var(--lad-line)">
                    <div class="lad-rt">
                        <b id="lad-mode-choice">{{ $t('where.choice.label') }}</b>
                        <small>{{ $t('where.choice.help') }}</small>
                    </div>
                    <label class="lad-sw">
                        <input type="checkbox" role="switch" aria-labelledby="lad-mode-choice" wire:model.live="data.mode_choice">
                        <span></span>
                    </label>
                </div>
            </section>

            {{-- How the results look --}}
            <section class="lad-card">
                <h2>{{ $t('look.title') }}</h2>
                <p class="lad-help">{{ $t('look.help') }}</p>

                <div class="lad-opt" style="border-top:0;padding-top:0">
                    <div>
                        <label class="lad-lbl" for="lad-sugg">{{ $t('look.suggestions') }}</label>
                        <div class="lad-range"><input id="lad-sugg" type="range" min="1" max="12" wire:model.live="data.suggestions"><strong>{{ $d['suggestions'] ?? 6 }}</strong></div>
                    </div>
                    <div>
                        <label class="lad-lbl" for="lad-per">{{ $t('look.per_group') }}</label>
                        <div class="lad-range"><input id="lad-per" type="range" min="4" max="48" step="4" wire:model.live="data.per_group"><strong>{{ $d['per_group'] ?? 12 }}</strong></div>
                    </div>
                </div>

                <div class="lad-opt">
                    <div style="flex:1 1 260px">
                        <span class="lad-lbl">{{ $t('look.groups') }}</span>
                        @foreach ($this->groups as $at => $group)
                            <div class="lad-grp" wire:key="group-{{ $group['key'] }}">
                                <span class="n" id="lad-g-{{ $group['key'] }}">{{ $t('groups.'.$group['key']) }}</span>
                                <button type="button" class="lad-arrow" wire:click="moveGroup({{ $at }}, -1)" @disabled($at === 0) aria-label="{{ $t('look.up', ['group' => $t('groups.'.$group['key'])]) }}">↑</button>
                                <button type="button" class="lad-arrow" wire:click="moveGroup({{ $at }}, 1)" @disabled($at === count($this->groups) - 1) aria-label="{{ $t('look.down', ['group' => $t('groups.'.$group['key'])]) }}">↓</button>
                                <label class="lad-sw" style="margin-top:0">
                                    <input type="checkbox" role="switch" aria-labelledby="lad-g-{{ $group['key'] }}" wire:model.live="groups.{{ $at }}.on">
                                    <span></span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                    <div style="flex:1 1 240px">
                        <span class="lad-lbl">{{ $t('look.on_product') }}</span>
                        @foreach (['price', 'cart', 'similar', 'hover'] as $key)
                            <label class="lad-chk"><input type="checkbox" wire:model.live="data.{{ $key }}">{{ $t('look.'.$key) }}</label>
                        @endforeach
                    </div>
                </div>

                <div class="lad-opt">
                    <div>
                        <span class="lad-lbl">{{ $t('look.color') }}</span>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <div class="lad-seg" role="radiogroup" aria-label="{{ $t('look.color') }}">
                                <button type="button" role="radio" aria-checked="{{ ($d['own_color'] ?? false) ? 'false' : 'true' }}" wire:click="$set('data.own_color', false)">{{ $t('look.color_theme') }}</button>
                                <button type="button" role="radio" aria-checked="{{ ($d['own_color'] ?? false) ? 'true' : 'false' }}" wire:click="$set('data.own_color', true)">{{ $t('look.color_own') }}</button>
                            </div>
                            @if ($d['own_color'] ?? false)
                                <input type="color" wire:model.live="data.color" aria-label="{{ $t('look.color_own') }}" style="width:40px;height:36px;border:1px solid var(--lad-line);border-radius:8px;padding:2px;background:var(--lad-card)">
                                <small class="lad-help" style="margin:0">{{ $t('look.color_help') }}</small>
                            @endif
                        </div>
                    </div>
                </div>

                <details style="margin-top:16px">
                    <summary style="cursor:pointer;font-weight:600">{{ $t('look.advanced') }}</summary>
                    <p class="lad-help">{{ $t('look.selector_help') }}</p>
                    <input class="lad-field code" type="text" maxlength="300" wire:model.blur="data.selector" aria-label="{{ $t('look.selector') }}" style="margin-top:8px">
                </details>
            </section>

            <div>
                <x-filament::button type="submit">{{ $t('save') }}</x-filament::button>
            </div>
        </div>

        {{-- The preview, with the shop's own products --}}
        @php($shown = array_values(array_map(fn ($g) => $g['key'], array_filter($this->groups, fn ($g) => $g['on']))))
        @php($phone = $this->device === 'phone')
        @php($cols = $mode === 'drawer' ? ((string) ($d['drawer_columns'] ?? '2') === '1' ? 1 : 2) : ($mode === 'page' ? ($phone ? 2 : 3) : ($phone ? 2 : 3)))
        @php($count = $mode === 'drawer' ? 4 : min(6, (int) ($d['suggestions'] ?? 6)))
        <aside class="lad-prev" aria-label="{{ $t('preview.title') }}">
            <div class="lad-pv">
                <div class="lad-pvh">
                    <span>{{ $t('preview.title') }}</span>
                    <div class="lad-seg" role="radiogroup" aria-label="{{ $t('preview.device') }}">
                        @foreach (['desktop', 'phone'] as $device)
                            <button type="button" role="radio" aria-checked="{{ $this->device === $device ? 'true' : 'false' }}" wire:click="pickDevice('{{ $device }}')">{{ $t('preview.'.$device) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="lad-store {{ $phone ? 'phone' : '' }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
                    <div class="lad-shdr"><span>{{ $t('preview.store') }}</span></div>
                    @if ($d['storefront'] ?? false)
                        <div class="lad-sin">
                            <span>{{ $t('preview.query') }}</span>
                            @if (($d['photos'] ?? false) && $status['pictures'])
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.2"/></svg>
                            @endif
                        </div>
                    @else
                        <div class="lad-sin"><span>{{ $t('preview.off') }}</span></div>
                    @endif
                    <div class="lad-ph"></div>
                    <div class="lad-ph" style="height:140px"></div>

                    @if ($d['storefront'] ?? false)
                        @php($cards = function (int $n, int $columns) use ($products, $d) {
                            return view('search::operator.search-display-cards', ['products' => array_slice($products, 0, $n), 'columns' => $columns, 'd' => $d])->render();
                        })
                        @php($groups = [
                            'product' => '<div class="lad-sec"><h4>'.e($t('groups.product')).'</h4>'.$cards($count, $cols).'</div>',
                            'category' => '<div class="lad-sec"><h4>'.e($t('groups.category')).'</h4><div class="lad-chips"><span>'.e($t('preview.category_a')).'</span><span>'.e($t('preview.category_b')).'</span></div></div>',
                            'content' => '<div class="lad-sec"><h4>'.e($t('groups.content')).'</h4><div class="lad-line">'.e($t('preview.guide_a')).'</div><div class="lad-line">'.e($t('preview.guide_b')).'</div></div>',
                        ])

                        @if ($mode === 'drawer')
                            <div class="lad-dim"></div>
                            <div class="lad-drawer {{ ($d['drawer_side'] ?? 'start') === 'end' ? 'end' : '' }}">
                                <div class="lad-dh"><span>{{ $t('preview.results_for') }}</span><span aria-hidden="true">✕</span></div>
                                <div style="flex:1;overflow:hidden">
                                    @foreach ($shown as $key)
                                        {!! $groups[$key] !!}
                                    @endforeach
                                </div>
                                @if (($d['whatsapp'] ?? false) && $status['number'] !== null)
                                    <div class="lad-wa">{{ $t('preview.whatsapp') }}</div>
                                @endif
                                <div class="lad-din">{{ ($d['ask'] ?? false) ? $t('preview.search_or_ask') : $t('preview.search') }}</div>
                            </div>
                        @elseif ($mode === 'panel')
                            <div class="lad-pop">
                                @foreach ($shown as $key)
                                    {!! $groups[$key] !!}
                                @endforeach
                                <div class="lad-foot"><span>{{ $t('preview.all') }}</span>@if ($d['ask'] ?? false)<span style="font-weight:600">{{ $t('preview.ask') }}</span>@endif</div>
                                @if (($d['whatsapp'] ?? false) && $status['number'] !== null)
                                    <div class="lad-wa">{{ $t('preview.whatsapp') }}</div>
                                @endif
                            </div>
                        @else
                            <div class="lad-pop" style="top:96px">
                                <div class="lad-sec"><h4>{{ $t('preview.suggestions') }}</h4>{!! $cards(min(3, $count), 3) !!}</div>
                            </div>
                        @endif
                    @endif
                </div>
                <div class="lad-note">
                    @if (! ($d['storefront'] ?? false))
                        {{ $t('preview.note_off') }}
                    @elseif ($mode === 'drawer')
                        {{ $t('preview.note_drawer', ['side' => $t('where.sides.'.($d['drawer_side'] ?? 'start')), 'columns' => $t('where.columns_'.(($d['drawer_columns'] ?? '2') === '1' ? '1' : '2'))]) }}
                    @elseif ($mode === 'panel')
                        {{ $t('preview.note_panel') }}
                    @else
                        {{ $t('preview.note_page') }}
                    @endif
                    @if ($products === [])
                        {{ $t('preview.no_products') }}
                    @endif
                </div>
            </div>
        </aside>
    </form>
    </div>
    @endif
</x-filament-panels::page>
