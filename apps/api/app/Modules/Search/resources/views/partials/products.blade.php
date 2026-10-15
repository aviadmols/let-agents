{{-- Products as a shopper's AI (and a person) reads them: details, options with price, stock and a buy link each. --}}
@foreach ($products as $p)
    <article>
        @if (! empty($p['image']))<img src="{{ $p['image'] }}" alt="{{ $p['title'] }}" loading="lazy">@else<span></span>@endif
        <div>
            <h3>{{ $p['title'] }}</h3>
            @isset($p['price'])
                <p><span class="price">{{ $money($p['price'], $p['currency'] ?? 'ILS') }}</span>@isset($p['regular_price'])<span class="was">{{ $t('was', ['amount' => $money($p['regular_price'], $p['currency'] ?? 'ILS')]) }}</span>@endisset
                    · {{ ($p['in_stock'] ?? false) ? $t('in_stock') : $t('out_of_stock') }}</p>
            @endisset
            <dl>
                <dt>{{ $t('id') }}</dt><dd>{{ $p['id'] }}</dd>
                @isset($p['cart_id'])<dt>{{ $t('option_id') }}</dt><dd>{{ $p['cart_id'] }}</dd>@endisset
                @isset($p['brand'])<dt>{{ $t('brand') }}</dt><dd>{{ $p['brand'] }}</dd>@endisset
                @isset($p['sku'])<dt>{{ $t('sku') }}</dt><dd>{{ $p['sku'] }}</dd>@endisset
                @foreach ($p['details'] ?? [] as $name => $values)
                    <dt>{{ $name }}</dt><dd>{{ implode(', ', $values) }}</dd>
                @endforeach
            </dl>
            @if (! empty($p['variants']))
                <ul class="options">
                    @foreach ($p['variants'] as $v)
                        <li>{{ $v['title'] }}@isset($v['price']) – {{ $money($v['price'], $p['currency'] ?? 'ILS') }}@endisset
                            @if ($v['in_stock'] ?? false)
                                @isset($v['buy_url']) · <a class="buy" href="{{ $v['buy_url'] }}" rel="nofollow noopener">{{ $t('buy') }}</a>@endisset
                                @isset($v['id'])<span class="muted"> ({{ $t('option_id') }} {{ $v['id'] }})</span>@endisset
                            @else
                                <span class="muted">({{ $t('out_of_stock') }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if (! empty($p['key_points']))
                <ul class="options">@foreach ($p['key_points'] as $point)<li>{{ $point }}</li>@endforeach</ul>
            @endif
            @isset($p['looks'])<p class="muted">{{ $t('looks') }}: {{ $p['looks'] }}</p>@endisset
            @isset($p['description'])<p class="muted">{{ $p['description'] }}</p>@endisset
            <p>
                @isset($p['buy_url'])<a class="buy" href="{{ $p['buy_url'] }}" rel="nofollow noopener">{{ $t('buy') }}</a> · @endisset
                @isset($p['url'])<a href="{{ $p['url'] }}" rel="noopener">{{ $t('to_product') }}</a>@endisset
            </p>
        </div>
    </article>
@endforeach
