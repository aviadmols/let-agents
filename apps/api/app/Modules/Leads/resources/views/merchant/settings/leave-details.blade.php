<x-filament-panels::page>
    {{-- Settings · Leaving details (LeaveDetails), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('leads::shop_settings.'.$key, $params))

    {{-- The field bank, as the design draws it (.bank in the designer's panel); here until merchant.css has it. --}}

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.on">
        <x-lp::kv>
            <x-lp::kv-row :label="$t('fields')" :tip="$t('fields_tip')">
                {{-- The chosen fields in the order they are asked (drag to reorder); the first, a way back, is locked. --}}
                <div class="lp-bank" x-data="{
                    v: $wire.entangle('data.fields'),
                    bank: @js($this->bank()),
                    word: '',
                    from: null,
                    has(key) { return (this.v || []).some((f) => f.key === key) },
                    add(b) { this.v = [...(this.v || []), { key: b.key, type: b.type, label: b.label, required: false }] },
                    own() {
                        const label = this.word.trim().slice(0, 60);
                        if (! label) { this.$refs.own.focus(); return }
                        this.v = [...(this.v || []), { key: 'own_' + Date.now().toString(36), type: 'text', label: label, required: false }];
                        this.word = '';
                    },
                    drop(i) { if (i > 0) { this.v = this.v.filter((f, j) => j !== i) } },
                    req(i) { if (i > 0) { this.v = this.v.map((f, j) => j === i ? { ...f, required: ! f.required } : f) } },
                    move(to) {
                        const from = this.from;
                        this.from = null;
                        if (from === null || from === 0 || to === 0 || from === to) { return }
                        const list = [...this.v];
                        const [f] = list.splice(from, 1);
                        list.splice(to, 0, f);
                        this.v = list;
                    },
                }">
                    <div class="lp-bank-chosen">
                        <template x-for="(f, i) in (v || [])" :key="f.key">
                            <span class="lp-bank-tag" x-bind:class="{ 'is-locked': i === 0 }" x-bind:draggable="i > 0"
                                x-on:dragstart="from = i" x-on:dragover.prevent x-on:drop.prevent="move(i)">
                                <span x-text="f.label"></span>
                                <button type="button" class="lp-bank-req" x-bind:class="{ 'is-off': ! f.required }" x-on:click="req(i)" x-bind:disabled="i === 0"
                                    title="{{ $t('required_tip') }}" x-bind:aria-pressed="f.required ? 'true' : 'false'">{{ $t('required') }}</button>
                                <button type="button" class="lp-bank-x" x-show="i > 0" x-on:click="drop(i)" aria-label="{{ $t('remove') }}">✕</button>
                            </span>
                        </template>
                        <span class="lp-bank-ph" x-show="! (v || []).length">{{ $t('fields_empty') }}</span>
                    </div>
                    <div class="lp-sample">{{ $t('fields_note') }}</div>
                    <div class="lp-bank-avail">
                        <template x-for="b in bank" :key="b.key">
                            <button type="button" class="lp-chip" x-show="! has(b.key)" x-on:click="add(b)">+ <span x-text="b.label"></span></button>
                        </template>
                    </div>
                    <div class="lp-bank-add">
                        <span class="lp-set-field"><input type="text" x-ref="own" x-model="word" maxlength="60" placeholder="{{ $t('own') }}" x-on:keydown.enter.prevent="own()"></span>
                        <button type="button" class="lp-btn lp-btn-sm" x-on:click="own()">{{ $t('add') }}</button>
                    </div>
                </div>
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('form_title')" :error="$this->problem('title')"><x-lp::field model="data.title" maxlength="120" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('promise')" :tip="$t('promise_tip')" :error="$this->problem('promise')"><x-lp::field model="data.promise" maxlength="120" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('recent')" :tip="$t('recent_tip')"><x-lp::toggle model="data.recent" :label="$t('recent')" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('email_team')" :tip="$t('email_team_tip')"><x-lp::toggle model="data.email_team" :label="$t('email_team')" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('notify_email')" :tip="$t('notify_email_tip')" :error="$this->problem('notify_email')"><x-lp::field model="data.notify_email" maxlength="190" dir="ltr" /></x-lp::kv-row>
        </x-lp::kv>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
