{{-- Writing a rule of the store's products, with "@" to name products, categories, posts and pages (Enrichment\Contracts\ShopRules). --}}
@php($t = fn (string $key, array $params = []) => __('assistant::ui.centre.'.$key, $params))
    <form wire:submit="addRule" class="lp-stack">
        {{-- "@" and some words: the store's products, categories, posts and pages to choose from, as its search finds them --}}
        <div style="position: relative" data-mention
            x-data="{ open: false, items: [], at: -1, active: 0, timer: null, picked: [],
                look() {
                    const el = $refs.box, before = el.value.slice(0, el.selectionStart), m = before.match(/@([^@\n]{0,40})$/);
                    if (! m || this.picked.some(l => m[1].startsWith(l))) { this.open = false; return; }
                    this.at = before.length - m[0].length;
                    const q = m[1];
                    clearTimeout(this.timer);
                    if (q.trim() === '') { this.items = []; this.open = true; return; }
                    this.timer = setTimeout(() => $wire.mentionSearch(q).then(r => { this.items = r || []; this.active = 0; this.open = true; }), 200);
                },
                key(e) {
                    if (! this.open) { return; }
                    if (e.key === 'Escape') { this.open = false; return; }
                    if (! this.items.length) { return; }
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.active = (this.active + 1) % this.items.length; }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = (this.active + this.items.length - 1) % this.items.length; }
                    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); this.pick(this.active); }
                },
                pick(i) {
                    const it = this.items[i], el = $refs.box;
                    if (! it) { return; }
                    const end = el.selectionStart;
                    el.value = el.value.slice(0, this.at) + '@' + it.label + ' ' + el.value.slice(end);
                    const pos = this.at + it.label.length + 2;
                    this.picked.push(it.label);
                    this.open = false;
                    el.setSelectionRange(pos, pos);
                    el.dispatchEvent(new Event('input'));
                    $wire.addMention(it.type, it.id, it.label);
                    el.focus();
                } }"
            @click.outside="open = false">
            <textarea class="lp-textarea" x-ref="box" wire:model="newRule" rows="3" maxlength="600" placeholder="{{ $t('rules.placeholder') }}" aria-label="{{ $t('rules.title') }}"
                @input="look()" @click="look()" @keydown="key($event)" aria-autocomplete="list"></textarea>
            <div x-show="open" x-cloak role="listbox" style="position: absolute; inset-inline: 0; top: 100%; z-index: 30; margin-top: 4px; max-height: 300px; overflow: auto; background: #fff; border-radius: 12px; box-shadow: 0 12px 32px -12px rgb(11 11 12 / .35), 0 0 0 1px rgb(11 11 12 / .08); padding: 4px">
                <template x-if="! items.length"><p class="lp-note" style="margin: 8px 10px">{{ $t('rules.mention_hint') }}</p></template>
                <template x-for="(it, i) in items" :key="it.type + it.id">
                    <button type="button" role="option" :aria-selected="i === active" @mousedown.prevent="pick(i)" @mouseenter="active = i"
                        :style="'display: flex; width: 100%; gap: 10px; align-items: center; padding: 6px 8px; border: 0; border-radius: 8px; text-align: start; cursor: pointer; background: ' + (i === active ? '#f2f2f3' : 'transparent')">
                        <template x-if="it.image"><img :src="it.image" alt="" style="width: 32px; height: 32px; object-fit: cover; border-radius: 6px; flex: none"></template>
                        <template x-if="! it.image"><span style="width: 32px; height: 32px; border-radius: 6px; background: #f2f2f3; flex: none"></span></template>
                        <span style="flex: 1; min-width: 0" dir="auto"><span x-text="it.label" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap"></span></span>
                        <span class="lp-note" x-text="it.kind" style="flex: none"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="lp-actions">
            <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary" wire:loading.attr="disabled" wire:target="addRule">{{ $t('rules.button') }}</button>
            <span class="lp-note">{{ $t('rules.checked') }} {{ $t('rules.mention_hint') }}</span>
        </div>
    </form>
