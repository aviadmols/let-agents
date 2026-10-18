<x-filament-panels::page>
    {{-- The scan summary, as the designer's panel lays it out (ScanSummary): the numbers, a list, and the chosen item beside it. --}}
    @php($t = fn (string $key, array $params = []) => __('enrichment::scan.'.$key, $params))
    @php($n = $this->numbers())

    <div class="lp-page" data-scan-summary>
        @if ($this->tab === 'products')
            <section class="lp-grid-4">
                <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.products') }}</span><span class="lp-stat-n">{{ number_format($n['products']) }}</span><span class="lp-stat-d">{{ $t('stats.of', ['n' => number_format($n['total'])]) }}</span></div>
                <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.images') }}</span><span class="lp-stat-n">{{ number_format($n['images']) }}</span><span class="lp-stat-d">{{ $t('stats.images_note', ['waiting' => number_format($n['waiting']), 'unreadable' => number_format($n['unreadable'])]) }}</span></div>
                <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.tags') }}</span><span class="lp-stat-n">{{ number_format($n['tags']) }}</span><span class="lp-stat-d">{{ $t('stats.per', ['n' => $n['per']]) }}</span></div>
                <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.missing') }}</span><span class="lp-stat-n">{{ number_format($n['missing']) }}</span><span class="lp-stat-d"><a class="lp-link" href="{{ \App\Modules\Enrichment\Filament\Merchant\Pages\MissingInfo::getUrl() }}">{{ $t('stats.to_missing') }}</a></span></div>
            </section>

            @php($list = $this->products())
            @php($p = $this->product())
            <div class="lp-split">
                <section class="lp-card lp-split-list">
                    <span class="lp-field lp-field-wide"><svg aria-hidden="true"><use href="#lp-i-search"/></svg><input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $t('find_product') }}" aria-label="{{ $t('find_product') }}"></span>
                    <div class="lp-chips" style="margin: 10px 0">
                        @foreach (['all', 'missing', 'image', 'manual'] as $f)
                            <button type="button" wire:click="pick('{{ $f }}')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->filter === $f])>{{ $t('filters.'.$f) }}</button>
                        @endforeach
                    </div>
                    <div class="lp-plist">
                        @forelse ($list['rows'] as $row)
                            <button type="button" @class(['lp-prow', 'is-on' => $p !== null && $p['id'] === $row['id']]) wire:click="select('{{ $row['id'] }}')">
                                <span class="lp-flag" data-tone="{{ $row['flag'] }}"></span>
                                <span class="lp-thumb">@if ($row['thumb'])<img src="{{ $row['thumb'] }}" alt="" loading="lazy">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
                                <span class="lp-grow"><span class="lp-row-t lp-clip">{{ $row['title'] }}</span><span class="lp-row-s">{{ $row['line'] }}</span></span>
                            </button>
                        @empty
                            <p class="lp-empty">{{ $t('none') }}</p>
                        @endforelse
                        @if ($list['more'])<button type="button" class="lp-link lp-all" wire:click="more">{{ $t('more') }}</button>@endif
                    </div>
                </section>

                <section class="lp-card" data-product>
                    @if ($p === null)
                        <p class="lp-empty">{{ $t('none') }}</p>
                    @else
                        <div class="lp-detail-head">
                            <span class="lp-thumb lp-thumb-lg">@if ($p['thumb'])<img src="{{ $p['thumb'] }}" alt="">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
                            <div class="lp-grow">
                                <h2>{{ $p['title'] }}</h2>
                                <span class="lp-row-s">{{ collect([
                                    $p['sku'] ? $t('sku', ['sku' => $p['sku']]) : null,
                                    $p['price'] !== null ? '₪'.number_format((float) $p['price'], (float) $p['price'] >= 100 ? 0 : 2) : null,
                                    $p['in_stock'] ? $t('in_stock') : $t('out_of_stock'),
                                    $p['variants'] > 0 ? $t('variants', ['n' => $p['variants']]) : null,
                                ])->filter()->implode(' · ') }}@if ($p['url']) · <a class="lp-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $t('to_store') }}</a>@endif</span>
                            </div>
                            <div class="lp-actions">
                                <button type="button" class="lp-btn lp-btn-sm" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan">{{ $t('rescan') }}</button>
                                @if ($p['url'])<a class="lp-btn lp-btn-sm" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $t('as_shopper') }}</a>@endif
                            </div>
                        </div>

                        <div class="lp-card-t lp-sub-t"><h2>{{ $t('tags') }}</h2><span class="lp-note">{{ $t('tags_help') }}</span></div>
                        <div class="lp-chips">
                            @foreach ($p['tags'] as $tag)
                                <span class="lp-chip lp-tag" x-data="{ menu: false }" @click.outside="menu = false">
                                    <button type="button" class="lp-tag-btn" @click="menu = ! menu">{{ $tag['label'] }} <span class="lp-src">{{ $tag['source'] }}</span></button>
                                    <span class="lp-tag-menu" x-show="menu" x-cloak><button type="button" class="lp-link" wire:click="removeTag('{{ $tag['id'] }}')">{{ $t('remove_tag') }}</button></span>
                                </span>
                            @endforeach
                            <form class="lp-chip lp-chip-quiet lp-tag-add" x-data="{ adding: false }" wire:submit="addTag" @click.outside="adding = false">
                                <button type="button" x-show="! adding" @click="adding = true; $nextTick(() => $refs.tag.focus())">+ {{ $t('tag') }}</button>
                                <input x-show="adding" x-cloak x-ref="tag" wire:model="newTag" maxlength="80" placeholder="{{ $t('new_tag') }}" aria-label="{{ $t('new_tag') }}">
                            </form>
                        </div>

                        <div class="lp-card-t lp-sub-t"><h2>{{ $t('spec') }}</h2><span class="lp-note">{{ $t('spec_help') }}</span></div>
                        <div class="lp-schema">
                            @forelse ($p['spec'] as $row)
                                <div>
                                    <span>{{ $row['label'] }}</span>
                                    @if ($this->editing === $row['key'])
                                        <form class="lp-inline" style="margin: 0" wire:submit="saveDetail('{{ $row['vocabulary'] }}')">
                                            <input class="lp-input lp-input-sm" wire:model="editValue" placeholder="{{ $row['values'] ?: $row['label'] }}" autofocus>
                                            <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary">{{ $t('save') }}</button>
                                        </form>
                                    @elseif ($row['missing'])
                                        <span><span class="lp-pill" data-tone="warn">{{ $t('missing') }}</span> · <button type="button" class="lp-link" wire:click="edit('{{ $row['key'] }}')">{{ $t('add') }}</button></span>
                                    @else
                                        <span><button type="button" class="lp-value" wire:click="edit('{{ $row['key'] }}')" title="{{ $t('edit') }}">{{ $row['values'] }}</button>@if ($row['manual']) <span class="lp-note">· {{ $t('manual') }}</span>@endif</span>
                                    @endif
                                </div>
                            @empty
                                <p class="lp-empty">{{ $t('no_spec') }}</p>
                            @endforelse
                        </div>

                        <div class="lp-card-t lp-sub-t">
                            <h2>{{ $t('image') }}</h2>
                            @if ($p['image'])
                                <span class="lp-pill" data-tone="{{ ['read' => 'good', 'waiting' => '', 'unread' => 'warn', 'error' => 'bad'][$p['image']['state']] }}"><i></i>{{ $p['image']['state'] === 'read' ? $t('image_read', ['date' => $p['image']['read_at']]) : $t('image_states.'.$p['image']['state']) }}</span>
                            @endif
                        </div>
                        @if ($p['image'])
                            <div class="lp-img-box">
                                <span class="lp-thumb lp-thumb-xl"><img src="{{ $p['image']['url'] }}" alt="" loading="lazy"></span>
                                <div class="lp-grow">
                                    <p>{{ $p['image']['caption'] ?? $t('image_none') }}</p>
                                    @if ($p['image']['words'] !== [])
                                        <div class="lp-chips" style="margin: 8px 0">@foreach ($p['image']['words'] as $word)<span class="lp-chip lp-chip-sm lp-chip-quiet">{{ $word }}</span>@endforeach</div>
                                    @endif
                                    <div class="lp-actions">
                                        <button type="button" class="lp-btn lp-btn-sm" wire:click="readImageAgain">{{ $t('read_again') }}</button>
                                        @if ($p['image']['caption'])<button type="button" class="lp-link" wire:click="readImageAgain(true)" wire:confirm="{{ $t('wrong_confirm') }}">{{ $t('wrong') }}</button>@endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <p class="lp-empty">{{ $t('no_image') }}</p>
                        @endif
                    @endif
                </section>
            </div>
        @else
            @php($list = $this->items())
            @php($c = $this->openItem())
            <section class="lp-grid-4">
                <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.contents') }}</span><span class="lp-stat-n">{{ number_format($n['pages'] + $n['posts']) }}</span><span class="lp-stat-d">{{ $t('stats.contents_note', ['pages' => number_format($n['pages']), 'posts' => number_format($n['posts'])]) }}</span></div>
            </section>
            <div class="lp-split">
                <section class="lp-card lp-split-list">
                    <span class="lp-field lp-field-wide"><svg aria-hidden="true"><use href="#lp-i-search"/></svg><input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $t('find_page') }}" aria-label="{{ $t('find_page') }}"></span>
                    <div class="lp-chips" style="margin: 10px 0">
                        @foreach (['all', 'pages', 'posts', 'unread'] as $f)
                            <button type="button" wire:click="pick('{{ $f }}')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->filter === $f])>{{ $t('page_filters.'.$f) }}</button>
                        @endforeach
                    </div>
                    <div class="lp-plist">
                        @forelse ($list['rows'] as $row)
                            <button type="button" @class(['lp-prow', 'is-on' => $c !== null && $c['id'] === $row['id']]) wire:click="select('{{ $row['id'] }}')">
                                <span class="lp-flag" data-tone="{{ $row['flag'] }}"></span>
                                <span class="lp-thumb">@if ($row['thumb'])<img src="{{ $row['thumb'] }}" alt="" loading="lazy">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
                                <span class="lp-grow"><span class="lp-row-t lp-clip">{{ $row['title'] }}</span><span class="lp-row-s">{{ $row['line'] }}</span></span>
                            </button>
                        @empty
                            <p class="lp-empty">{{ $t('none') }}</p>
                        @endforelse
                        @if ($list['more'])<button type="button" class="lp-link lp-all" wire:click="more">{{ $t('more') }}</button>@endif
                    </div>
                </section>
                <section class="lp-card" data-content>
                    @if ($c === null)
                        <p class="lp-empty">{{ $t('none') }}</p>
                    @else
                        <div class="lp-detail-head">
                            <span class="lp-thumb lp-thumb-lg">@if ($c['thumb'])<img src="{{ $c['thumb'] }}" alt="">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
                            <div class="lp-grow">
                                <h2>{{ $c['title'] }}</h2>
                                <span class="lp-row-s">{{ collect([
                                    $t('kinds.'.$c['type']),
                                    $c['published'] ? $t('published', ['date' => $c['published']]) : null,
                                    $c['read_at'] ? $t('read_on', ['date' => $c['read_at']]) : $t('not_read'),
                                    $t('words', ['n' => number_format($c['words'])]),
                                ])->filter()->implode(' · ') }}@if ($c['url']) · <a class="lp-link" href="{{ $c['url'] }}" target="_blank" rel="noopener">{{ $t('to_site') }}</a>@endif</span>
                            </div>
                            <button type="button" class="lp-btn lp-btn-sm" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan">{{ $t('rescan') }}</button>
                        </div>
                        <div class="lp-card-t lp-sub-t"><h2>{{ $t('content_facts') }}</h2><span class="lp-note">{{ $t('content_facts_help') }}</span></div>
                        @forelse ($c['facts'] as $fact)
                            <div class="lp-row"><div class="lp-grow"><span class="lp-row-t">{{ $fact }}</span></div><span class="lp-pill" data-tone="good">{{ $t('in_knowledge') }}</span></div>
                        @empty
                            <p class="lp-empty">{{ $c['read_at'] ? $t('no_facts') : $t('not_read_yet') }}</p>
                        @endforelse
                        <div class="lp-card-t lp-sub-t"><h2>{{ $t('content_products') }}</h2><span class="lp-note">{{ $t('content_products_help') }}</span></div>
                        @forelse ($c['products'] as $product)
                            <div class="lp-row">
                                <span class="lp-thumb">@if ($product['thumb'])<img src="{{ $product['thumb'] }}" alt="" loading="lazy">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
                                <div class="lp-grow"><span class="lp-row-t">{{ $product['title'] }}</span><span class="lp-row-s">{{ $product['mentioned'] ? $t('mentioned') : $t('matched') }}</span></div>
                                <span class="lp-pill" data-tone="good">{{ $t('linked') }}</span>
                            </div>
                        @empty
                            <p class="lp-empty">{{ $t('no_products') }}</p>
                        @endforelse
                        @if ($c['unfound'] > 0)<p class="lp-note">{{ $t('unfound', ['n' => $c['unfound']]) }}</p>@endif
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
