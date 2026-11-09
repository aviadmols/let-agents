<x-filament-panels::page>
    {{-- What shoppers see, as the designer's redesign draws it (WhatShoppersSee; prototype see.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('widget::see.'.$key, $params))
    @php($on = $this->switches())
    @php($pick = $this->choices())
    @php($held = $this->held())
    @php($p = $this->preview())
    @php($i = [
        'look' => '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.6-.8 1.6-1.6 0-.5-.2-.8-.4-1.1-.3-.3-.4-.6-.4-1 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5c0-4.1-4-7.7-9-7.7z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10.5" cy="7.5" r="1.2"/><circle cx="15" cy="7.5" r="1.2"/>',
        'window' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'save' => '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.4 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>',
        'whatsapp' => '<path d="M12 4a8 8 0 0 0-6.8 12.2L4 20l3.9-1.2A8 8 0 1 0 12 4z"/>',
    ])
    {{-- a control the plan holds: shown as it is, and beside it the way to the plan that opens it --}}
    @php($upgrade = $this->more('plan'))
    @php($withUpgrade = function (string $name, string $control) use ($held, $upgrade, $t): string {
        if (! in_array($name, $held, true)) {
            return $control;
        }
        $mark = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>'.e($t('upgrade'));
        $pill = $upgrade !== null
            ? '<a class="lp-d-up" href="'.e($upgrade).'" title="'.e($t('upgrade_text')).'">'.$mark.'</a>'
            : '<span class="lp-d-up" title="'.e($t('upgrade_text')).'">'.$mark.'</span>';

        return '<div class="lp-d-set-ctl">'.$pill.$control.'</div>';
    })
    @php($toggle = function (string $name) use ($on, $held, $t, $withUpgrade): string {
        $label = e($t('held'));

        return $withUpgrade($name, '<label class="lp-d-toggle lp-d-toggle-lg"'.(in_array($name, $held, true) ? ' title="'.$label.'"' : '').'><input type="checkbox" wire:click.prevent="turn(\''.$name.'\')" wire:loading.attr="disabled"'.($on[$name] ? ' checked' : '').(in_array($name, $held, true) ? ' disabled' : '').'><span class="sr-only">'.e($on[$name] ? 'On' : 'Off').'</span></label>');
    })
    @php($seg = function (string $name, array $options) use ($pick, $held, $t, $withUpgrade): string {
        $out = '<div class="lp-d-seg" role="group">';
        foreach ($options as $value => $label) {
            $out .= '<button type="button" wire:click="choose(\''.$name.'\', \''.$value.'\')" aria-pressed="'.($pick[$name] === $value ? 'true' : 'false').'"'.(in_array($name, $held, true) ? ' disabled title="'.e($t('held')).'"' : '').'>'.e($label).'</button>';
        }

        return $withUpgrade($name, $out.'</div>');
    })

    <div class="lp-page lp-d" data-see>
        <div class="lp-d-see">
            {{-- the preview: the store, with the search window as the choices below draw it --}}
            <section class="lp-d-stage" aria-label="{{ $t('preview.label') }}" x-data="{ phone: false }">
                <div class="lp-d-stage-bar">
                    <b>{{ $t('preview.live', ['domain' => $p['domain']]) }}</b>
                    <div class="lp-d-seg lp-d-seg-ink" role="group" aria-label="{{ $t('preview.device') }}">
                        <button type="button" @click="phone = false" :aria-pressed="(! phone).toString()">{{ $t('preview.desktop') }}</button>
                        <button type="button" @click="phone = true" :aria-pressed="phone.toString()">{{ $t('preview.phone') }}</button>
                    </div>
                </div>
                <div @class(['lp-d-shop', 'is-store' => $pick['look'] === 'store', 'is-fit' => $pick['fit'] === 'all']) :class="{ 'is-phone': phone }" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}" data-preview>
                    <div class="lp-d-shop-head"><span class="lp-d-shop-logo">{{ mb_strtoupper($p['name']) }}</span><span class="lp-d-shop-nav">@foreach (__('widget::see.preview.nav') as $item)<span>{{ $item }}</span>@endforeach</span></div>
                    <div class="lp-d-shop-hero" @if ($p['hero']) style="background-image: url('{{ $p['hero'] }}')" @endif></div>
                    <div class="lp-d-drawer">
                        <div class="lp-d-dfield">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
                            <span>{{ $t('preview.field') }}</span>
                            @if ($on['photos'])<span class="lp-d-dcam" data-show="photo"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2.5"/><circle cx="12" cy="12.5" r="3.2"/></svg></span>@endif
                        </div>
                        @if ($on['suggest'] && $p['chips'] !== [])
                            <div class="lp-d-dchips" data-show="suggest">@foreach ($p['chips'] as $chip)<span dir="auto">{{ $chip }}</span>@endforeach</div>
                        @endif
                        @if ($on['answers'])<div class="lp-d-danswer" data-show="answer">{!! __('widget::see.preview.answer') !!}</div>@endif
                        @if ($p['products'] !== [])
                            <div class="lp-d-dprods">
                                @foreach ($p['products'] as $product)
                                    <div class="lp-d-dprod">
                                        <img src="{{ $product['picture'] }}" alt="">
                                        @if ($on['heart'])<svg class="lp-d-dheart" viewBox="0 0 24 24" aria-hidden="true">{!! $i['save'] !!}</svg>@endif
                                        <span dir="auto">{{ $product['title'] }}</span>
                                        @if ($on['price'] && $product['price'])<br><span class="lp-d-dprice" data-show="price">{{ $product['price'] }}</span>@endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if ($on['whatsapp'])<div class="lp-d-dhand" data-show="whatsapp"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $i['whatsapp'] !!}</svg>{{ $t('preview.hand') }}</div>@endif
                    </div>
                </div>
            </section>

            <div>
                <section class="lp-d-setgroup">
                    <h2><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $i['look'] !!}</svg></span><span>{{ $t('look.title') }}</span></h2>
                    <div class="lp-d-set"><div><b>{{ $t('look.style') }}</b><span>{{ $t('look.style_text') }}</span></div>{!! $seg('look', ['lets' => $t('look.lets'), 'store' => $t('look.store')]) !!}</div>
                    <div class="lp-d-set"><div><b>{{ $t('look.photos') }}</b><span>{{ $t('look.photos_text') }}</span></div>{!! $seg('fit', ['fill' => $t('look.fill'), 'all' => $t('look.all')]) !!}</div>
                    @if ($url = $this->more('look'))<a class="lp-d-more lp-d-set-more" href="{{ $url }}">{{ $t('more') }}</a>@endif
                </section>
                <section class="lp-d-setgroup">
                    <h2><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $i['window'] !!}</svg></span><span>{{ $t('window.title') }}</span></h2>
                    <div class="lp-d-set"><div><b>{{ $t('window.suggest') }}</b><span>{{ $t('window.suggest_text') }}</span></div>{!! $toggle('suggest') !!}</div>
                    <div class="lp-d-set"><div><b>{{ $t('window.answers') }}</b><span>{{ $t('window.answers_text') }}</span></div>{!! $toggle('answers') !!}</div>
                    @php($read = $this->photosRead())
                    <div class="lp-d-set"><div><b>{{ $t('window.photos') }}</b><span>{{ trans_choice('widget::see.window.photos_text', $read, ['n' => number_format($read)]) }}</span></div>{!! $toggle('photos') !!}</div>
                    <div class="lp-d-set"><div><b>{{ $t('window.price') }}</b><span>{{ $t('window.price_text') }}</span></div>{!! $toggle('price') !!}</div>
                    @if ($url = $this->more('search'))<a class="lp-d-more lp-d-set-more" href="{{ $url }}">{{ $t('more') }}</a>@endif
                </section>
                <section class="lp-d-setgroup">
                    <h2><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $i['save'] !!}</svg></span><span>{{ $t('save.title') }}</span></h2>
                    <div class="lp-d-set"><div><b>{{ $t('save.heart') }}</b><span>{{ $t('save.heart_text') }}</span></div>{!! $toggle('heart') !!}</div>
                    <div class="lp-d-set"><div><b>{{ $t('save.compare') }}</b><span>{{ $t('save.compare_text') }}</span></div>{!! $toggle('compare') !!}</div>
                    @if ($url = $this->more('heart'))<a class="lp-d-more lp-d-set-more" href="{{ $url }}">{{ $t('more') }}</a>@endif
                </section>
                <section class="lp-d-setgroup">
                    <h2><span class="lp-d-kpi-icon"><svg viewBox="0 0 24 24">{!! $i['whatsapp'] !!}</svg></span><span>{{ $t('whatsapp.title') }}</span></h2>
                    <div class="lp-d-set"><div><b>{{ $t('whatsapp.hand') }}</b><span>{{ $t('whatsapp.hand_text') }}</span></div>{!! $toggle('whatsapp') !!}</div>
                    <div class="lp-d-set">
                        <div><b>{{ $t('whatsapp.number') }}</b><span>{{ $t('whatsapp.number_text') }}</span></div>
                        <form class="lp-d-set-field" wire:submit="saveNumber">
                            <input type="tel" wire:model="number" dir="ltr" inputmode="tel" autocomplete="tel" maxlength="24" placeholder="+972 50 000 0000" aria-label="{{ $t('whatsapp.number') }}">
                            <button type="submit" class="lp-d-btn lp-d-btn-line lp-d-btn-sm">{{ $t('whatsapp.number_save') }}</button>
                        </form>
                    </div>
                    <div class="lp-d-set"><div><b>{{ $t('whatsapp.hours') }}</b><span dir="auto">{{ $this->hours() }}</span></div><a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $this->hoursUrl() }}">{{ $t('whatsapp.change') }}</a></div>
                    @if ($url = $this->more('whatsapp'))<a class="lp-d-more lp-d-set-more" href="{{ $url }}">{{ $t('more') }}</a>@endif
                </section>
            </div>
        </div>
    </div>
</x-filament-panels::page>
