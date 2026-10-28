<x-filament-panels::page>
    {{-- What shoppers asked, as the designer's panel lays it out (merchant ShopQuestions): every question with
         its answer and score, filtered; what has no answer can be answered, turned into a post, or set aside. --}}
    @php($t = fn (string $key, array $params = []) => __('assistant::ui.inbox.'.$key, $params))
    @php($page = \App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions::class)
    @php($all = $this->rows())
    @php($counts = $this->counts($all))
    @php($rows = $this->visible($all))

    <div class="lp-page" data-questions>
        {{-- the daily check of the questions: what went well and what to improve --}}
        @php($review = $this->searchAsks()['latest'] ?? null)
        @php($said = $review ? \App\Modules\Assistant\Support\ReviewWords::forMerchant($review->summaryIn(app()->getLocale())) : null)
        @php($better = $review ? \App\Modules\Assistant\Support\ReviewWords::improvements($review->improvementsIn(app()->getLocale())) : [])
        @if ($review && ($said || $better))
            <section class="lp-card" data-review>
                <div class="lp-card-t"><h2>{{ $t('review_title') }}</h2><span class="lp-note">{{ $review->day->format('j.n') }}</span></div>
                @if ($said)<p dir="auto" style="margin: 0; color: var(--la-ink)">{{ $said }}</p>@endif
                @if ($better)
                    <ul class="lp-notes">@foreach ($better as $improvement)<li dir="auto">{{ $improvement }}</li>@endforeach</ul>
                @endif
            </section>
        @endif

        <div class="lp-list-tools">
            <div class="lp-tabs lp-tabs-sm" role="tablist">
                @foreach (['all', 'open', 'answered', 'off'] as $key)
                    <button type="button" role="tab" wire:click="filter('{{ $key }}')" @class(['is-on' => $this->show === $key])>{{ $t('filters.'.$key) }} <span class="lp-count">{{ number_format($counts[$key]) }}</span></button>
                @endforeach
            </div>
            <span class="lp-field"><svg aria-hidden="true"><use href="#lp-i-search"/></svg><input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="find" placeholder="{{ $t('find') }}" aria-label="{{ $t('find') }}"></span>
            {{-- the order: what needs an answer first, or the latest asked first --}}
            <div class="lp-tabs lp-tabs-sm" role="group" aria-label="{{ $t('order') }}" data-order>
                <button type="button" wire:click="orderBy('need')" @class(['is-on' => $this->order !== 'date'])>{{ $t('order_need') }}</button>
                <button type="button" wire:click="orderBy('date')" @class(['is-on' => $this->order === 'date'])>{{ $t('order_date') }}</button>
            </div>
        </div>

        @forelse (array_slice($rows, 0, $this->shown) as $row)
            <article class="lp-card lp-ask" wire:key="q-{{ $row['id'] }}" data-question="{{ $row['state'] }}">
                <span class="lp-mark lp-mark-ask" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14a7 7 0 0 1-14 0zM5 21h14a7 7 0 0 0-14 0z" fill="currentColor"/></svg></span>
                <div class="lp-grow">
                    <div class="lp-ask-q">{{ $row['question'] }}</div>
                    <div class="lp-ask-meta">
                        <span class="lp-pill" data-tone="{{ ['open' => 'bad', 'answered' => 'good', 'off' => ''][$row['state']] }}"><i></i>{{ $t('states.'.$row['state']) }}</span>
                        <span>{{ trans_choice('assistant::ui.inbox.asked', $row['asked'], ['n' => number_format($row['asked'])]) }}</span>
                        @if ($row['last'])<span>· {{ $t('last_at', ['at' => $page::at($row['last']), 'when' => $page::ago($row['last'])]) }}</span>@endif
                        <span>· {{ $row['where'] }}</span>
                        @if ($row['score'] !== null)<span class="lp-pill" data-tone="{{ $row['score'] >= 80 ? 'good' : ($row['score'] >= 50 ? 'warn' : 'bad') }}">{{ $t('score', ['n' => $row['score']]) }}</span>@endif
                    </div>

                    @if ($row['answer'] !== '' && $row['state'] !== 'open')
                        <div class="lp-ask-answer"><span class="lp-note">{{ $t('answer_given') }}</span><p>{{ $row['answer'] }}</p></div>
                    @elseif ($row['answer'] !== '')
                        <div class="lp-ask-answer"><span class="lp-note">{{ $t('shown_instead') }}</span><p>{{ $row['answer'] }}</p></div>
                    @endif

                    {{-- the products shown with the answer, those the shopper opened or added marked --}}
                    @if (($row['products'] ?? []) !== [])
                        <div style="display: grid; gap: 6px; margin-top: 10px" data-answer-products>
                            <span class="lp-note">{{ $t('products_shown') }}</span>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px">
                                @foreach ($row['products'] as $product)
                                    <a href="{{ $product['url'] ?: '#' }}" target="_blank" rel="noopener" style="display: grid; gap: 6px; color: inherit; text-decoration: none" data-answer-product>
                                        <span style="display: block; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: var(--la-sunk); {{ $product['opened'] ? 'box-shadow: 0 0 0 2px var(--la-ink);' : '' }}">
                                            @if ($product['image'])<img src="{{ $product['image'] }}" alt="" loading="lazy" style="width: 100%; height: 100%; object-fit: cover">@endif
                                        </span>
                                        <span dir="auto" style="font-size: 13px; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden">{{ $product['title'] }}</span>
                                        @if ($product['price'] || $product['opened'])
                                            <span style="font-size: 12.5px; color: var(--la-muted)">{{ $product['price'] }}@if ($product['price'] && $product['opened']) · @endif @if ($product['opened'])<b style="color: var(--la-ink); font-weight: 600">{{ $t('opened') }}</b>@endif</span>
                                        @endif
                                        @if ($product['why'])<span dir="auto" style="font-size: 12px; color: var(--la-muted); line-height: 1.4">{{ $product['why'] }}</span>@endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($this->writing === $row['id'])
                        <form class="lp-stack" style="margin-top: 12px" wire:submit="publish(@js($row['id']))">
                            <textarea class="lp-textarea" wire:model="drafts.{{ $row['id'] }}" rows="4" maxlength="1200" placeholder="{{ $t('write_placeholder') }}"></textarea>
                            @foreach ($this->draftChecks[$row['id']] ?? [] as $check)<p class="lp-note">· {{ $check }}</p>@endforeach
                            <div class="lp-actions">
                                <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary">{{ $t('publish') }}</button>
                                <button type="button" class="lp-link" wire:click="$set('writing', null)">{{ $t('cancel') }}</button>
                            </div>
                        </form>
                    @elseif ($row['state'] !== 'off')
                        <div class="lp-actions" style="margin-top: 12px">
                            @if ($this->teamDrafts())
                                <button type="button" class="lp-btn lp-btn-sm lp-btn-primary" wire:click="draftFor(@js($row['id']))" wire:loading.attr="disabled" wire:target="draftFor">{{ $row['state'] === 'open' ? $t('draft') : $t('improve') }}</button>
                            @endif
                            @if ($row['state'] === 'open' && $this->canPost())
                                <button type="button" class="lp-btn lp-btn-sm" wire:click="post(@js($row['id']))">{{ $t('post') }}</button>
                            @endif
                            <button type="button" class="lp-btn lp-btn-sm" wire:click="writeOwn(@js($row['id']))">{{ $row['state'] === 'open' ? $t('own') : $t('own_instead') }}</button>
                            <button type="button" class="lp-link" wire:click="setAside(@js($row['id']))" wire:confirm="{{ $t('aside_confirm') }}">{{ $t('aside') }}</button>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <p class="lp-empty">{{ $this->show === 'open' ? $t('none_open') : $t('none') }}</p>
        @endforelse

        @if (count($rows) > $this->shown)
            <div x-data x-intersect="$wire.more()" class="lp-more"><button type="button" class="lp-link" wire:click="more">{{ $t('more') }}</button></div>
        @endif
    </div>
</x-filament-panels::page>
