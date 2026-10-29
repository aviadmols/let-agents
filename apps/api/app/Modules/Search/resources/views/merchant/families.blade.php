<x-filament-panels::page>
    {{-- Word families (WordFamilies): a new family, then three tabs: waiting for the team, the team's, searched with no family. --}}
    @php($t = fn (string $key, array $params = []) => __('search::families.'.$key, $params))
    @php($waiting = $this->waitingRows())
    @php($approved = $this->approvedRows())
    @php($open = $this->openRows())
    @php($word = fn (string $w): string => \App\Modules\Search\Support\HebrewSearch::withFinals($w))

    <div class="lp-page" data-word-families>
        <section class="lp-card" data-new-family>
            <div class="lp-card-t"><h2>{{ $t('new.title') }}</h2><span class="lp-note">{{ $t('new.note') }}</span></div>
            <form wire:submit="create" class="lp-inline">
                <input class="lp-input" wire:model="newFamily" maxlength="400" placeholder="{{ $t('new.placeholder') }}" aria-label="{{ $t('new.title') }}">
                <button type="submit" class="lp-btn lp-btn-primary" wire:loading.attr="disabled" wire:target="create">{{ $t('new.button') }}</button>
            </form>
        </section>

        <nav class="lp-tabs" role="tablist" aria-label="{{ $t('title') }}">
            @foreach (['waiting' => count($waiting), 'approved' => count($approved), 'open' => count($open)] as $key => $count)
                <button type="button" role="tab" wire:click="show('{{ $key }}')" @class(['is-on' => $this->tab === $key]) aria-selected="{{ $this->tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}">{{ $t('tabs.'.$key) }} <span class="lp-count">{{ $count }}</span></button>
            @endforeach
        </nav>

        @if ($this->tab === 'open')
            <section class="lp-card" data-pane="open">
                <p class="lp-set-help">{{ $t('open.help', ['days' => $this->openDays()]) }}</p>
                @php($options = $this->familyOptions())
                @forelse ($open as $i => $row)
                    <div class="lp-row lp-row-wrap" data-open-word="{{ $row['word'] }}" wire:key="open-{{ $row['word'] }}">
                        <div class="lp-grow">
                            <span class="lp-row-t">{{ $row['word'] }}</span>
                            <span class="lp-row-s">{{ trans_choice('search::families.open.searches', $row['searches'], ['n' => $row['searches']]) }}@if ($row['empty'] > 0) · <b>{{ trans_choice('search::families.open.empty', $row['empty'], ['n' => $row['empty']]) }}</b>@endif</span>
                            @if ($row['near'] !== [])
                                <div class="lp-chips" style="margin-top: 8px">
                                    <span class="lp-note">{{ $t('open.near') }}</span>
                                    @foreach ($row['near'] as $near)
                                        <button type="button" class="lp-chip lp-chip-sm lp-chip-plus" wire:click="pair(@js($row['word']), @js($near))" data-near="{{ $near }}">+ {{ $near }}</button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        @if ($options !== [])
                            <form class="lp-inline" style="margin: 0" wire:submit="join(@js($row['word']), {{ $i }})">
                                <select class="lp-input lp-input-sm" wire:model="joining.{{ $i }}" aria-label="{{ $t('open.join_label', ['word' => $row['word']]) }}">
                                    <option value="">{{ $t('open.join_pick') }}</option>
                                    @foreach ($options as $ref => $label)<option value="{{ $ref }}">{{ \Illuminate\Support\Str::limit($label, 60) }}</option>@endforeach
                                </select>
                                <button type="submit" class="lp-btn lp-btn-sm">{{ $t('open.join') }}</button>
                            </form>
                        @endif
                        <button type="button" class="lp-link" wire:click="hide(@js($row['word']))">{{ $t('open.hide') }}</button>
                    </div>
                @empty
                    <p class="lp-empty">{{ $t('open.none') }}</p>
                @endforelse
            </section>
        @else
            @php($rows = $this->tab === 'approved' ? $approved : $waiting)
            <section class="lp-card" data-pane="{{ $this->tab }}">
                <p class="lp-set-help">{{ $t($this->tab.'.help') }}</p>
                @if ($this->tab === 'waiting' && $rows !== [])
                    <div class="lp-actions" style="margin-bottom: 8px"><button type="button" class="lp-btn lp-btn-sm" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow" data-check-now>{{ $t('check_now') }}</button></div>
                @endif
                @forelse ($rows as $row)
                    <div class="lp-row lp-row-wrap" data-family="{{ $row['ref'] }}" wire:key="{{ $row['ref'] }}">
                        <div class="lp-grow lp-chips">
                            @foreach ($row['words'] as $w)
                                <span class="lp-chip lp-chip-plus" data-word="{{ $w }}">{{ $word($w) }}<button type="button" class="lp-chip-x" wire:click="dropWord('{{ $row['ref'] }}', @js($w))" aria-label="{{ $t('drop', ['word' => $word($w)]) }}" title="{{ $t('drop', ['word' => $word($w)]) }}">✕</button></span>
                            @endforeach
                            <form class="lp-chip lp-chip-quiet lp-tag-add" x-data="{ adding: false }" wire:submit="addWord('{{ $row['ref'] }}')" @click.outside="adding = false">
                                <button type="button" x-show="! adding" @click="adding = true; $nextTick(() => $refs.word.focus())">+ {{ $t('add') }}</button>
                                <input x-show="adding" x-cloak x-ref="word" wire:model="adding.{{ $row['ref'] }}" maxlength="120" placeholder="{{ $t('add_placeholder') }}" aria-label="{{ $t('add_placeholder') }}">
                            </form>
                        </div>
                        <div class="lp-actions">
                            @if ($this->tab === 'waiting')
                                <button type="button" class="lp-btn lp-btn-sm lp-btn-primary" wire:click="approve('{{ $row['ref'] }}')">{{ $t('approve') }}</button>
                                <button type="button" class="lp-btn lp-btn-sm" wire:click="reject('{{ $row['ref'] }}')">{{ $t('reject') }}</button>
                            @else
                                @if ($row['auto'] ?? false)<span class="lp-note" data-auto>{{ $t('auto') }}</span>@endif
                                <button type="button" class="lp-link" wire:click="reject('{{ $row['ref'] }}')" wire:confirm="{{ $t('remove_confirm') }}">{{ $t('remove') }}</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="lp-empty">{{ $t($this->tab.'.none') }}</p>
                @endforelse
            </section>
        @endif
    </div>
</x-filament-panels::page>
