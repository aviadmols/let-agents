<x-filament-panels::page>
    {{-- The knowledge centre, as the designer's panel draws it: add a line, then three tabs (general
         information, posts, what was found on the site). Every row is a possible answer. --}}
    @php($t = fn (string $key, array $params = []) => __('assistant::ui.centre.'.$key, $params))
    @php($counts = $this->counts())
    @php($tz = 'Asia/Jerusalem')

    <div class="lp-page" data-knowledge-centre>
        <section class="lp-card" data-add-info>
            <div class="lp-card-t"><h2>{{ $t('add.title') }}</h2><span class="lp-note">{{ $t('add.note') }}</span></div>
            <form wire:submit="addInfo" class="lp-stack">
                <textarea class="lp-textarea" wire:model="newInfo" rows="3" maxlength="600" placeholder="{{ $t('add.placeholder') }}" aria-label="{{ $t('add.title') }}"></textarea>
                <div class="lp-actions">
                    <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary" wire:loading.attr="disabled" wire:target="addInfo"><svg class="lp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>{{ $t('add.button') }}</button>
                    <span class="lp-note">{{ $t('add.checked') }}</span>
                </div>
            </form>
        </section>

        <nav class="lp-tabs" role="tablist" aria-label="{{ __('assistant::ui.knowledge.title') }}">
            @foreach (['general' => $t('tabs.general_count', ['n' => $counts['general']]), 'posts' => (string) $counts['posts'], 'found' => (string) $counts['found']] as $key => $count)
                <button type="button" role="tab" wire:click="show('{{ $key }}')" @class(['is-on' => $this->tab === $key]) aria-selected="{{ $this->tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}">{{ $t('tabs.'.$key) }} <span class="lp-count">{{ $count }}</span></button>
            @endforeach
        </nav>

        @if ($this->tab === 'general')
            @php($own = $this->ownRows())
            @php($pages = $this->pages())
            @php($limit = $this->limits['general'])
            @php($checking = collect($own)->contains(fn (array $r): bool => $r["check"] === null && $r["at"] !== null && \Illuminate\Support\Carbon::parse($r["at"])->gt(now()->subMinutes(10))))
            <section class="lp-card" data-pane="general" @if ($checking) wire:poll.5s @endif>
                @foreach ($own as $row)
                    <div class="lp-row" data-own="{{ $row['id'] }}">
                        @php($check = $row['check'])
                        <span class="lp-pill" data-tone="{{ ($check['status'] ?? null) === 'conflict' ? 'bad' : 'good' }}"><i></i>{{ $t('own.pill') }}</span>
                        <div class="lp-grow">
                            @if ($this->editing === $row['id'])
                                <form wire:submit="saveEdit" class="lp-stack">
                                    <textarea class="lp-textarea" wire:model="editText" rows="2" maxlength="600"></textarea>
                                    <div class="lp-actions">
                                        <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary">{{ $t('own.save') }}</button>
                                        <button type="button" class="lp-link" wire:click="cancelEdit">{{ $t('own.cancel') }}</button>
                                    </div>
                                </form>
                            @else
                                <span class="lp-row-t">{{ $row['text'] }}</span>
                                <span class="lp-row-s">
                                    @if ($row['at']){{ $t('own.added', ['date' => \Illuminate\Support\Carbon::parse($row['at'])->timezone($tz)->format('j.n')]) }} · @endif
                                    @switch($check['status'] ?? null)
                                        @case('ok') {{ $t('own.check_ok') }} @break
                                        @case('nothing') {{ $t('own.check_nothing') }} @break
                                        @case('conflict') <b>{{ $t('own.check_conflict') }}</b> @break
                                        @case('later') {{ $t('own.check_later') }} @break
                                        @case('failed') {{ $t('own.check_failed') }} @break
                                        @default {{ $t('own.checking') }}
                                    @endswitch
                                </span>
                                @if (($check['status'] ?? null) === 'conflict')
                                    <ul class="lp-notes">
                                        @foreach ($check['notes'] as $note)<li><b>{{ $note['title'] }}:</b> {{ $note['why'] }}</li>@endforeach
                                    </ul>
                                @endif
                            @endif
                        </div>
                        @if ($this->editing !== $row['id'])
                            <button type="button" class="lp-link" wire:click="startEdit('{{ $row['id'] }}')">{{ $t('own.edit') }}</button>
                            <button type="button" class="lp-link" wire:click="removeInfo('{{ $row['id'] }}')" wire:confirm="{{ $t('own.remove_confirm') }}">{{ $t('own.remove') }}</button>
                        @endif
                    </div>
                @endforeach

                @forelse (array_slice($pages, 0, $limit) as $page)
                    <div class="lp-row" data-page="{{ $page['id'] }}">
                        <span class="lp-thumb" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7z M14 3v5h5 M10 13h6 M10 17h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg></span>
                        <div class="lp-grow">
                            <span class="lp-row-t">@if ($page['url'])<a href="{{ $page['url'] }}" target="_blank" rel="noopener">{{ $page['title'] }}</a>@else{{ $page['title'] }}@endif</span>
                            <span class="lp-row-s">{{ $page['summary'] }}@if ($page['answered'] > 0) · {{ $t('page.answered', ['n' => number_format($page['answered'])]) }}@endif</span>
                        </div>
                        <span class="lp-pill" data-tone="{{ $page['read'] ? 'good' : '' }}"><i></i>{{ $page['read'] ? $t('page.read') : $t('page.waiting') }}</span>
                    </div>
                @empty
                    @if ($own === [])<p class="lp-empty">{{ $t('page.none') }}</p>@endif
                @endforelse
                @if (count($pages) > $limit)
                    <button type="button" class="lp-link" wire:click="more('general')">{{ $t('more', ['n' => count($pages) - $limit]) }}</button>
                @endif
            </section>
        @elseif ($this->tab === 'posts')
            @php($posts = $this->postRows())
            @php($limit = $this->limits['posts'])
            <section class="lp-card" data-pane="posts">
                <div class="lp-fact"><b>{{ $t('posts.auto_title') }}</b> {{ $t('posts.auto_body') }}</div>
                <form wire:submit="addPost" class="lp-inline">
                    <input class="lp-input" type="url" wire:model="postUrl" dir="ltr" placeholder="{{ $t('posts.url_placeholder') }}" aria-label="{{ $t('posts.url_label') }}">
                    <button type="submit" class="lp-btn lp-btn-primary">{{ $t('posts.add') }}</button>
                </form>
                @forelse (array_slice($posts, 0, $limit) as $post)
                    <div class="lp-row lp-row-wrap" data-post="{{ $post['id'] }}">
                        <span class="lp-thumb" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7z M14 3v5h5 M10 13h6 M10 17h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg></span>
                        <div class="lp-grow">
                            <span class="lp-row-t">@if ($post['url'])<a href="{{ $post['url'] }}" target="_blank" rel="noopener">{{ $post['title'] }}</a>@else{{ $post['title'] }}@endif</span>
                            <span class="lp-row-s">
                                @if ($post['read'])
                                    {{ $t('posts.facts', ['n' => count($post['facts'])]) }}@if ($post['facts'] !== []) · "{{ \Illuminate\Support\Str::limit($post['facts'][0], 80) }}"@endif
                                    @if ($post['read_at']) · {{ $t('posts.read_at', ['date' => \Illuminate\Support\Carbon::parse($post['read_at'])->timezone($tz)->format('j.n')]) }}@endif
                                @elseif ($post['waiting'])
                                    {{ $t('posts.waiting') }}
                                @else
                                    {{ $t('posts.not_read') }}
                                @endif
                            </span>
                        </div>
                        @if ($post['facts'] !== [])
                            <button type="button" class="lp-link" wire:click="togglePost('{{ $post['id'] }}')" aria-expanded="{{ $this->openPost === $post['id'] ? 'true' : 'false' }}">{{ $this->openPost === $post['id'] ? $t('posts.close') : $t('posts.to_facts') }}</button>
                        @endif
                        @if ($this->openPost === $post['id'])
                            <ul class="lp-facts">
                                @foreach ($post['facts'] as $fact)
                                    <li><span>{{ $fact }}</span><button type="button" class="lp-link" wire:click="removePostFact(@js($fact))" wire:confirm="{{ __('assistant::ui.knowledge.posts.remove_confirm') }}">{{ $t('remove') }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @empty
                    <p class="lp-empty">{{ $t('posts.none') }}</p>
                @endforelse
                @if (count($posts) > $limit)
                    <button type="button" class="lp-link" wire:click="more('posts')">{{ $t('more', ['n' => count($posts) - $limit]) }}</button>
                @endif
            </section>
        @else
            @php($found = $this->found())
            @php($limit = $this->limits['found'])
            <section class="lp-card" data-pane="found">
                <p class="lp-note" style="margin: 0 0 6px">{{ $t('found.help') }}</p>
                @forelse (array_slice($found, 0, $limit) as $row)
                    <div class="lp-row" data-found="{{ $row['kind'] }}">
                        <div class="lp-grow">
                            <span class="lp-row-t">{{ $row['text'] }}</span>
                            <span class="lp-row-s">{{ $row['from'] }}</span>
                        </div>
                        <button type="button" class="lp-link" wire:click="removeFound('{{ $row['kind'] }}', @js($row['key']))" wire:confirm="{{ $t('found.remove_confirm') }}">{{ $t('remove') }}</button>
                    </div>
                @empty
                    <p class="lp-empty">{{ $t('found.none') }}</p>
                @endforelse
                @if (count($found) > $limit)
                    <button type="button" class="lp-link" wire:click="more('found')">{{ $t('more', ['n' => count($found) - $limit]) }}</button>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
