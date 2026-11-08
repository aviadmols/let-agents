<x-filament-panels::page>
    {{-- Store knowledge, as the designer's redesign draws it (AboutStore; prototype knowledge.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('assistant::store_knowledge.'.$key, $params))
    @php($n = fn (string $key, int $count, array $params = []) => trans_choice('assistant::store_knowledge.'.$key, $count, ['n' => number_format($count)] + $params))
    @php($locale = app()->getLocale())
    @php($ago = fn ($at) => $at === null ? '' : \Illuminate\Support\Carbon::parse($at)->locale($locale)->diffForHumans())
    @php($mark = '<svg viewBox="0 0 225 225" fill="currentColor" aria-hidden="true"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>')
    @php($pageIcon = '<svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7z M14 3v5h5 M10 13h6 M10 17h6"/></svg>')
    @php($penIcon = '<svg viewBox="0 0 24 24"><path d="M5 19.5h4l10-10-4-4-10 10z"/></svg>')
    @php($own = $this->ownRows())
    @php($pages = $this->pages())
    @php($rules = $this->rules())
    @php($knows = $this->knows())

    <div class="lp-page lp-d" data-store-knowledge>
        <div class="lp-d-grid">
            {{-- teach Lets something --}}
            <section class="lp-d-teach" aria-labelledby="teach-title" x-data>
                <div><h2 id="teach-title">{{ $t('teach.title') }}</h2><p>{{ $t('teach.text') }}</p></div>
                <div>
                    <form class="lp-d-teach-box" wire:submit="addInfo">
                        <label class="sr-only" for="teach-in">{{ $t('teach.label') }}</label>
                        <input id="teach-in" x-ref="teach" autocomplete="off" maxlength="600" wire:model="newInfo" placeholder="{{ $t('teach.placeholder') }}" dir="auto">
                        <button class="lp-d-btn lp-d-btn-lime lp-d-btn-sm" type="submit" wire:loading.attr="disabled" wire:target="addInfo">{{ $t('teach.add') }}</button>
                    </form>
                    <div class="lp-d-teach-ex">
                        @foreach (__('assistant::store_knowledge.teach.examples') as $example)
                            <button type="button" @click="$refs.teach.value = @js($example); $refs.teach.dispatchEvent(new Event('input')); $refs.teach.focus()">{{ $example }}</button>
                        @endforeach
                    </div>
                </div>
            </section>

            <div class="lp-d-know">
                <div>
                    <div class="lp-d-ktabs" role="tablist" aria-label="{{ $t('tabs.label') }}">
                        @foreach (['pages' => count($pages), 'products' => $knows['products'], 'notes' => count($own), 'rules' => count($rules)] as $key => $count)
                            <button type="button" role="tab" wire:click="show(@js($key))" aria-selected="{{ $this->tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}"><span>{{ $t('tabs.'.$key) }}</span><span class="lp-d-ktabs-n lp-d-num">{{ number_format($count) }}</span></button>
                        @endforeach
                    </div>

                    @if ($this->tab === 'pages')
                        {{-- the pages and policies, then the posts, then what product pages repeat and the team answered --}}
                        <section class="lp-d-card" data-pane="pages">
                            @forelse (array_slice($pages, 0, $this->limits['pages']) as $page)
                                <div class="lp-d-srow" data-page="{{ $page['id'] }}">
                                    <span class="lp-d-srow-ic">{!! $pageIcon !!}</span>
                                    <span class="lp-d-srow-main"><b dir="auto">@if ($page['url'])<a href="{{ $page['url'] }}" target="_blank" rel="noopener">{{ $page['title'] }}</a>@else{{ $page['title'] }}@endif</b><span class="lp-d-srow-l" dir="auto">{{ $page['summary'] }}</span></span>
                                    <span class="lp-d-srow-used">{!! trans_choice('assistant::store_knowledge.pages.answers', $page['answered'], ['n' => number_format($page['answered'])]) !!}</span>
                                    <span @class(['lp-d-chip', 'lp-d-chip-line' => $page['read'], 'lp-d-chip-quiet' => ! $page['read']])>{{ $page['read'] ? $t('pages.read') : $t('pages.not_read') }}</span>
                                </div>
                            @empty
                                <p class="lp-d-note">{{ $t('pages.none') }}</p>
                            @endforelse
                            @if (count($pages) > $this->limits['pages'])
                                <button type="button" class="lp-d-ghost lp-d-srow-more" wire:click="more('pages')">{{ $n('pages.more', count($pages) - $this->limits['pages']) }}</button>
                            @endif

                            @php($posts = $this->postRows())
                            <h3 class="lp-d-ksub">{{ $t('pages.posts') }}</h3>
                            @foreach (array_slice($posts, 0, $this->limits['posts']) as $post)
                                <div class="lp-d-srow" data-post="{{ $post['id'] }}">
                                    <span class="lp-d-srow-ic">{!! $pageIcon !!}</span>
                                    <span class="lp-d-srow-main"><b dir="auto">@if ($post['url'])<a href="{{ $post['url'] }}" target="_blank" rel="noopener">{{ $post['title'] }}</a>@else{{ $post['title'] }}@endif</b>
                                        <span class="lp-d-srow-l">{{ $post['read'] ? $n('pages.post_facts', count($post['facts'])) : '' }}</span></span>
                                    @if ($post['facts'] !== [])
                                        <button type="button" class="lp-d-ghost" wire:click="togglePost(@js($post['id']))" aria-expanded="{{ $this->openPost === $post['id'] ? 'true' : 'false' }}">{{ $this->openPost === $post['id'] ? $t('pages.facts_close') : $t('pages.facts_open') }}</button>
                                    @else<span></span>@endif
                                    <span @class(['lp-d-chip', 'lp-d-chip-line' => $post['read'], 'lp-d-chip-sun' => ! $post['read'] && $post['waiting'], 'lp-d-chip-quiet' => ! $post['read'] && ! $post['waiting']])>{{ $post['read'] ? $t('pages.read') : ($post['waiting'] ? $t('pages.waiting') : $t('pages.not_read')) }}</span>
                                    @if ($this->openPost === $post['id'])
                                        <ul class="lp-d-kfacts">
                                            @foreach ($post['facts'] as $fact)
                                                <li><span dir="auto">{{ $fact }}</span><button type="button" class="lp-d-ghost" wire:click="removePostFact(@js($fact))" wire:confirm="{{ __('assistant::ui.knowledge.posts.remove_confirm') }}">{{ $t('pages.remove') }}</button></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                            @if (count($posts) > $this->limits['posts'])
                                <button type="button" class="lp-d-ghost lp-d-srow-more" wire:click="more('posts')">{{ $n('pages.more_posts', count($posts) - $this->limits['posts']) }}</button>
                            @endif
                            <form class="lp-d-kline" wire:submit="addPost">
                                <input type="url" wire:model="postUrl" dir="ltr" placeholder="{{ $t('pages.post_link') }}" aria-label="{{ $t('pages.post_link') }}">
                                <button type="submit" class="lp-d-btn lp-d-btn-line lp-d-btn-sm">{{ $t('pages.post_add') }}</button>
                            </form>

                            @php($found = $this->found())
                            @if ($found !== [])
                                <h3 class="lp-d-ksub">{{ $t('pages.found') }}</h3>
                                @foreach (array_slice($found, 0, $this->limits['found']) as $row)
                                    <div class="lp-d-srow" data-found="{{ $row['kind'] }}">
                                        <span class="lp-d-srow-ic">{!! $penIcon !!}</span>
                                        <span class="lp-d-srow-main"><b dir="auto">{{ $row['text'] }}</b><span class="lp-d-srow-l" dir="auto">{{ $row['from'] }}</span></span>
                                        <span></span>
                                        <button type="button" class="lp-d-ghost" wire:click="removeFound(@js($row['kind']), @js($row['key']))" wire:confirm="{{ __('assistant::ui.centre.found.remove_confirm') }}">{{ $t('pages.remove') }}</button>
                                    </div>
                                @endforeach
                                @if (count($found) > $this->limits['found'])
                                    <button type="button" class="lp-d-ghost lp-d-srow-more" wire:click="more('found')">{{ $n('pages.more_found', count($found) - $this->limits['found']) }}</button>
                                @endif
                            @endif
                        </section>
                    @elseif ($this->tab === 'products')
                        {{-- a row of products, the ones missing a detail marked, and what was understood of the open one --}}
                        @php($strip = $this->strip())
                        @php($open = $this->openProduct())
                        <section class="lp-d-card" data-pane="products">
                            <div class="lp-d-kline lp-d-kline-top">
                                <input type="search" wire:model.live.debounce.400ms="productSearch" placeholder="{{ $t('products.find') }}" aria-label="{{ $t('products.find') }}" dir="auto">
                            </div>
                            @if ($strip === [])
                                <p class="lp-d-note">{{ $t('products.none') }}</p>
                            @else
                                <div class="lp-d-plist" role="group" aria-label="{{ $t('tabs.products') }}">
                                    @foreach ($strip as $p)
                                        <button type="button" wire:click="pick(@js($p['id']))" aria-pressed="{{ ($open['id'] ?? null) === $p['id'] ? 'true' : 'false' }}" title="{{ $p['title'] }}">
                                            @if ($p['picture'])<img src="{{ $p['picture'] }}" alt="{{ $p['title'] }}" loading="lazy">@else<span class="lp-d-tile"></span>@endif
                                            @if ($p['missing'])<span class="lp-d-plist-dot" title="{{ $t('products.missing_dot') }}"></span>@endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            @if ($open !== null)
                                <div class="lp-d-popen" data-product="{{ $open['id'] }}">
                                    @if ($open['picture'])<img src="{{ $open['picture'] }}" alt="">@else<span class="lp-d-tile"></span>@endif
                                    <div>
                                        <h3 dir="auto">{{ $open['title'] }}</h3>
                                        @if ($open['price'])<span class="lp-d-popen-price lp-d-num">{{ $open['price'] }}</span>@endif
                                        <div class="lp-d-kv">
                                            <div>
                                                <h4>{{ $t('products.understood') }}</h4>
                                                @if ($open['tags'] === [] && $open['missing'] === [])
                                                    <p class="lp-d-note">{{ $t('products.nothing') }}</p>
                                                @else
                                                    <div class="lp-d-tags">
                                                        @foreach ($open['tags'] as $tag)<span class="lp-d-tag"><bdi>{{ $tag['label'] }}</bdi> <i>{{ $t('products.from.'.$tag['from']) }}</i></span>@endforeach
                                                        @foreach ($open['missing'] as $missing)<span class="lp-d-tag lp-d-tag-miss"><bdi>{{ $missing }}</bdi> <i>{{ $t('products.missing') }}</i></span>@endforeach
                                                    </div>
                                                @endif
                                            </div>
                                            @if ($open['saw'])
                                                <div><h4>{{ $t('products.saw') }}</h4><p class="lp-d-saw" dir="auto">{{ $open['saw'] }}</p></div>
                                            @endif
                                            <div class="lp-d-popen-do">
                                                @if ($open['url'])<a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $open['url'] }}" target="_blank" rel="noopener">{{ $t('products.see') }}</a>@endif
                                                @if ($open['fix'])<a class="lp-d-ghost" href="{{ $open['fix'] }}">{{ $t('products.wrong') }}</a>@endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </section>
                    @elseif ($this->tab === 'notes')
                        {{-- what the team added, each checked against the site --}}
                        @php($checking = collect($own)->contains(fn (array $r): bool => $r['check'] === null && $r['at'] !== null && \Illuminate\Support\Carbon::parse($r['at'])->gt(now()->subMinutes(10))))
                        <section class="lp-d-card" data-pane="notes" @if ($checking) wire:poll.5s @endif>
                            @forelse (array_slice($own, 0, $this->limits['notes']) as $row)
                                @php($check = $row['check'])
                                <div class="lp-d-srow" data-own="{{ $row['id'] }}">
                                    <span @class(['lp-d-srow-ic', 'is-new' => $row['at'] !== null && \Illuminate\Support\Carbon::parse($row['at'])->gt(now()->subMinutes(10))])>{!! $penIcon !!}</span>
                                    <span class="lp-d-srow-main">
                                        @if ($this->editing === $row['id'])
                                            <form class="lp-d-kline" wire:submit="saveEdit">
                                                <input wire:model="editText" maxlength="600" dir="auto" aria-label="{{ $t('notes.edit') }}">
                                                <button type="submit" class="lp-d-btn lp-d-btn-ink lp-d-btn-sm">{{ $t('notes.save') }}</button>
                                                <button type="button" class="lp-d-ghost" wire:click="cancelEdit">{{ $t('notes.cancel') }}</button>
                                            </form>
                                        @else
                                            <b dir="auto">“{{ $row['text'] }}”</b>
                                            <span class="lp-d-srow-l lp-d-srow-wrap">
                                                @if ($row['at']){{ $t('notes.added', ['when' => $ago($row['at'])]) }} · @endif
                                                @switch($check['status'] ?? null)
                                                    @case('ok') {{ __('assistant::ui.centre.own.check_ok') }} @break
                                                    @case('nothing') {{ __('assistant::ui.centre.own.check_nothing') }} @break
                                                    @case('conflict') <b>{{ __('assistant::ui.centre.own.check_conflict') }}</b> @break
                                                    @case('later') {{ __('assistant::ui.centre.own.check_later') }} @break
                                                    @case('failed') {{ __('assistant::ui.centre.own.check_failed') }} @break
                                                    @default {{ __('assistant::ui.centre.own.checking') }}
                                                @endswitch
                                            </span>
                                            @if (($check['status'] ?? null) === 'conflict')
                                                <ul class="lp-d-kfacts">@foreach ($check['notes'] as $note)<li><span dir="auto"><b>{{ $note['title'] }}:</b> {{ $note['why'] }}</span></li>@endforeach</ul>
                                            @endif
                                        @endif
                                    </span>
                                    @if ($this->editing !== $row['id'])
                                        <button type="button" class="lp-d-ghost" wire:click="startEdit(@js($row['id']))">{{ $t('notes.edit') }}</button>
                                        <button type="button" class="lp-d-ghost" wire:click="removeInfo(@js($row['id']))" wire:confirm="{{ $t('notes.remove_confirm') }}">{{ $t('notes.remove') }}</button>
                                    @endif
                                </div>
                            @empty
                                <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('notes.zero') }}</b><span>{{ $t('notes.zero_text') }}</span></div>
                            @endforelse
                        </section>
                    @else
                        {{-- the rules of the store's products: written in its words, read into a plan, approved before anything changes --}}
                        @php($reading = collect($rules)->contains(fn (array $r): bool => $r['status'] === 'reading'))
                        <section class="lp-d-card" data-pane="rules" @if ($reading) wire:poll.5s @endif>
                            <p class="lp-d-card-sub lp-d-rules-sub">{{ $t('rules.sub') }}</p>
                            @include('assistant::merchant.rule-form')
                            @forelse (array_slice($rules, 0, $this->limits['rules']) as $rule)
                                @php($shown = e($rule['text']))
                                @foreach ($rule['mentions'] ?? [] as $named)@php($shown = str_replace(e('@'.$named), '<code>'.e('@'.$named).'</code>', $shown))@endforeach
                                <div class="lp-d-rule" data-rule="{{ $rule['id'] }}" data-status="{{ $rule['status'] }}">
                                    <div>
                                        <p dir="auto">{!! $shown !!}</p>
                                        <span>
                                            @if (in_array($rule['status'], ['unclear', 'failed'], true) && $rule['note']){{ $rule['note'] }}
                                            @else{{ $n('rules.applies', count($rule['changes'] ?? [])) }}@if ($rule['note'] && $rule['status'] !== 'reading') · {{ __('assistant::ui.centre.rules.understood') }} {{ $rule['note'] }}@endif
                                            @endif
                                        </span>
                                        @if (($rule['link'] ?? null))<span class="lp-d-rule-l" dir="auto"><b>{{ __('assistant::ui.centre.rules.link') }}</b> {{ $rule['link'] }}</span>@endif
                                        @if (($rule['changes'] ?? []) !== [])
                                            <details class="lp-d-rule-changes" @if ($rule['status'] === 'proposed') open @endif>
                                                <summary>{{ $t('rules.changes') }}</summary>
                                                <ul>
                                                    @foreach ($rule['changes'] as $change)
                                                        <li dir="auto">
                                                            @if ($change['image'])<img src="{{ $change['image'] }}" alt="">@endif
                                                            <span><b>{{ $change['title'] }}</b>
                                                                @if ($change['add'] !== [])<br>{{ __('assistant::ui.centre.rules.add') }} {{ implode(', ', $change['add']) }}@endif
                                                                @if ($change['remove'] !== [])<br>{{ __('assistant::ui.centre.rules.remove') }} {{ implode(', ', $change['remove']) }}@endif
                                                                @if ($change['apart'] !== [])<br>{{ __('assistant::ui.centre.rules.apart') }} {{ implode(', ', $change['apart']) }}@endif
                                                                @if ($change['alike'] !== [])<br>{{ __('assistant::ui.centre.rules.alike') }} {{ implode(', ', $change['alike']) }}@endif
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </div>
                                    <div class="lp-d-rule-do">
                                        <span @class(['lp-d-chip', 'lp-d-chip-line' => $rule['status'] === 'applied', 'lp-d-chip-sun' => in_array($rule['status'], ['proposed', 'unclear', 'failed'], true), 'lp-d-chip-quiet' => in_array($rule['status'], ['reading', 'rejected'], true)])>{{ $t('rules.status.'.$rule['status']) }}</span>
                                        @if ($rule['status'] === 'proposed')
                                            <button type="button" class="lp-d-btn lp-d-btn-ink lp-d-btn-sm" wire:click="decideRule(@js($rule['id']), true)" wire:loading.attr="disabled" wire:target="decideRule">{{ __('assistant::ui.centre.rules.approve') }}</button>
                                            <button type="button" class="lp-d-ghost" wire:click="decideRule(@js($rule['id']), false)">{{ __('assistant::ui.centre.rules.reject') }}</button>
                                        @elseif (in_array($rule['status'], ['failed', 'unclear'], true))
                                            <button type="button" class="lp-d-btn lp-d-btn-line lp-d-btn-sm" wire:click="retryRule(@js($rule['id']))" wire:loading.attr="disabled" wire:target="retryRule">{{ __('assistant::ui.centre.rules.retry') }}</button>
                                        @endif
                                        @if ($rule['status'] !== 'reading')
                                            <button type="button" class="lp-d-ghost" wire:click="removeRule(@js($rule['id']))" wire:confirm="{{ __('assistant::ui.centre.rules.remove_confirm') }}">{{ __('assistant::ui.centre.rules.remove_rule') }}</button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('rules.zero') }}</b><span>{{ $t('rules.zero_text') }}</span></div>
                            @endforelse
                            @if (count($rules) > $this->limits['rules'])
                                <button type="button" class="lp-d-ghost lp-d-srow-more" wire:click="more('rules')">{{ __('assistant::ui.centre.more', ['n' => count($rules) - $this->limits['rules']]) }}</button>
                            @endif
                        </section>
                    @endif
                </div>

                <aside class="lp-d-imp-aside">
                    <section class="lp-d-goal lp-d-knows">
                        <h2 class="lp-d-card-title">{{ $t('knows.title') }}</h2>
                        <div class="lp-d-knows-grid">
                            <div><span>{{ $t('knows.products') }}</span><b class="lp-d-num">{{ number_format($knows['products']) }}</b></div>
                            <div><span>{{ $t('knows.photos') }}</span><b class="lp-d-num">{{ number_format($knows['photos']) }}</b></div>
                            <div><span>{{ $t('knows.pages') }}</span><b class="lp-d-num">{{ number_format($knows['pages'] + $knows['posts']) }}</b></div>
                            <div><span>{{ $t('knows.added') }}</span><b class="lp-d-num">{{ number_format(count($own)) }}</b></div>
                        </div>
                        <p>{!! $knows['read_at'] ? __('assistant::store_knowledge.knows.read', ['when' => e($ago($knows['read_at']))]) : e($t('knows.never')) !!}</p>
                    </section>
                    <section class="lp-d-tip"><b>{!! $mark !!}<span>{{ $t('tip.title') }}</span></b><span>{{ $t('tip.text') }}</span></section>
                </aside>
            </div>
        </div>
    </div>
</x-filament-panels::page>
