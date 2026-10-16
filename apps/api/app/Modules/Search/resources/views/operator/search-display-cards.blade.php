<div class="lad-grid" style="grid-template-columns:repeat({{ $columns }},minmax(0,1fr))">
    @forelse ($products as $product)
        <div class="lad-pc">
            @if ($product['image'])
                <img class="lad-pi" src="{{ $product['image'] }}" alt="" loading="lazy">
            @else
                <span class="lad-pi"></span>
            @endif
            <span class="lad-pt">{{ $product['title'] }}</span>
            @if (($d['price'] ?? true) && $product['price'] !== null)
                <span class="lad-pp">{{ $product['price'] }}</span>
            @endif
            @if (($d['cart'] ?? true) || ($d['similar'] ?? true))
                <span class="lad-pb">
                    @if ($d['cart'] ?? true)
                        <span class="lad-buy">{{ __('search::storefront.add') }}</span>
                    @endif
                    @if ($d['similar'] ?? true)
                        <span class="lad-sim">{{ __('search::storefront.similar') }}</span>
                    @endif
                </span>
            @endif
        </div>
    @empty
        @for ($n = 0; $n < $columns * 2; $n++)
            <div class="lad-pc"><span class="lad-pi"></span><span class="lad-pt">&nbsp;</span></div>
        @endfor
    @endforelse
</div>
