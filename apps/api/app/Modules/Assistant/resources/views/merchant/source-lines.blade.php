{{-- Where exactly a source says it (SourceLines), each line fixed where it is written (EditsSourceLines): the team's line
     or answer changed or removed, a repeated sentence or a post's fact not used again, a page opened or edited on the
     store's own site. $lines; $may: whether this person fixes lines. --}}
@php($w = fn (string $key, array $params = []) => __('assistant::ui.inbox.why.'.$key, $params))
@if ($lines !== [])
    <div style="display: grid; gap: 8px" data-source-lines>
        @foreach ($lines as $line)
            @php($editing = $this->lineEditing === $line['kind'].'|'.$line['key'])
            <div style="display: grid; gap: 6px; padding: 10px 12px; border-radius: 10px; background: var(--la-paper); box-shadow: inset 0 0 0 1px rgb(0 0 0 / 8%)" data-source-line="{{ $line['kind'] }}" wire:key="line-{{ md5($line['kind'].$line['key']) }}">
                <span class="lp-note">{{ $line['label'] !== null ? $w('lines.'.$line['kind'], ['label' => $line['label']]) : $w('lines.'.$line['kind'], ['label' => '']) }}</span>
                @if ($editing)
                    <form class="lp-stack" style="gap: 8px" wire:submit="saveLine">
                        <textarea class="lp-textarea" wire:model="lineText" rows="3" maxlength="1200" dir="auto"></textarea>
                        <div class="lp-actions">
                            <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary" data-save-line>{{ $w('line_save') }}</button>
                            <button type="button" class="lp-link" wire:click="cancelLine">{{ $w('cancel') }}</button>
                        </div>
                    </form>
                @else
                    @if ($line['kind'] !== 'page')<p dir="auto" style="margin: 0; white-space: pre-line">{{ $line['text'] }}</p>@else<b dir="auto" style="font-weight: 600">{{ $line['text'] }}</b>@endif
                    <div class="lp-actions" style="gap: 6px 14px">
                        @if ($may && in_array($line['kind'], ['own', 'team'], true))
                            <button type="button" class="lp-link" wire:click="editLine(@js($line['kind']), @js($line['key']), @js($line['text']))" data-edit-line>{{ $w('line_edit') }}</button>
                        @endif
                        @if ($may && $line['kind'] !== 'page')
                            <button type="button" class="lp-link" wire:click="removeLine(@js($line['kind']), @js($line['key']))" wire:confirm="{{ $w('line_remove_confirm') }}" data-remove-line>{{ in_array($line['kind'], ['own', 'team'], true) ? $w('line_remove') : $w('line_stop') }}</button>
                        @endif
                        @if ($line['url'])
                            <a class="lp-link" href="{{ $line['url'] }}" target="_blank" rel="noopener">{{ $line['kind'] === 'post_fact' ? $w('line_open_post') : $w('line_open_page') }} ↗</a>
                        @endif
                        @if ($line['edit_url'])
                            <a class="lp-link" href="{{ $line['edit_url'] }}" target="_blank" rel="noopener" data-edit-on-store>{{ $w('line_edit_on_store') }} ↗</a>
                        @endif
                    </div>
                    @if ($line['kind'] === 'repeated')<span class="lp-note">{{ $w('line_repeated_note') }}</span>@endif
                @endif
            </div>
        @endforeach
    </div>
@endif
