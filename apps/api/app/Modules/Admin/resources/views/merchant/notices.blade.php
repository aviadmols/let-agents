<x-filament-panels::page>
    @php($all = $this->all())
    @php($done = $this->done())
    @php($shown = match ($kind) { 'all' => $all, 'done' => $done, default => array_values(array_filter($all, fn ($n) => $n->kind === $kind)) })
    @php($icons = ['todo' => 'M12 3l10 18H2z M12 10v4M12 17h.01', 'setup' => 'M12 6v6l4 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'insight' => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.7.6 1 1.3 1 2.5h6c0-1.2.3-1.9 1-2.5A6 6 0 0 0 12 3z', 'report' => 'M5 12l5 5L20 7'])
    @php($tones = ['todo' => 'warn', 'setup' => 'warn', 'insight' => 'tip', 'report' => 'good'])

    <div class="la-page" data-notices-screen>
        <nav class="la-tabs" style="padding: 0" aria-label="{{ __('admin::home.notices.title') }}">
            @foreach (['all', ...\App\Modules\Admin\Contracts\ShopNotice::KINDS, 'done'] as $tab)
                @php($count = match ($tab) { 'all' => count($all), 'done' => count($done), default => count(array_filter($all, fn ($n) => $n->kind === $tab)) })
                <button type="button" wire:click="show('{{ $tab }}')" aria-selected="{{ $kind === $tab ? 'true' : 'false' }}">
                    {{ __('admin::home.notices.tabs.'.$tab) }}
                    @if ($count > 0)<span class="la-badge" data-tone="{{ in_array($tab, ['todo', 'setup'], true) ? 'warn' : '' }}" style="margin-inline-start: 4px; padding: 0 8px">{{ $count }}</span>@endif
                </button>
            @endforeach
        </nav>

        @forelse ($shown as $notice)
            @php($tone = $notice->tone !== '' ? $notice->tone : $tones[$notice->kind])
            <article class="la-notice" data-tone="{{ $tone }}" data-notice="{{ $notice->key }}">
                <span class="la-notice-icon"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icons[$notice->kind] }}"></path></svg></span>
                <div class="la-notice-body">
                    <div class="la-notice-meta">
                        <span class="la-badge" data-tone="{{ $tone === 'bad' ? 'bad' : $tones[$notice->kind] }}">{{ __('admin::home.kinds.'.$notice->kind) }}</span>
                        <span>{{ $notice->source }}@if ($notice->at) · {{ $notice->at->diffForHumans() }}@endif</span>
                    </div>
                    <h3>{{ $notice->title }}</h3>
                    @if ($notice->body)<p>{{ $notice->body }}</p>@endif
                    <div class="la-actions">
                        @if ($notice->url && $kind !== 'done')
                            <a href="{{ $notice->url }}" class="la-btn {{ in_array($notice->kind, ['todo', 'setup'], true) ? 'la-btn-primary' : '' }}">{{ $notice->action ?? __('admin::home.open') }}</a>
                        @endif
                        @if ($kind === 'done')
                            <button type="button" class="la-btn la-btn-quiet" wire:click="undo(@js($notice->key))" data-notice-undo>{{ __('admin::home.notices.undo') }}</button>
                        @else
                            <button type="button" class="la-btn la-btn-quiet" wire:click="markDone(@js($notice->key))" data-notice-done>{{ __('admin::home.notices.mark_done') }}</button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <section class="la-card"><p class="la-empty">{{ __($kind === 'done' ? 'admin::home.notices.none_done' : 'admin::home.notices.none') }}</p></section>
        @endforelse
    </div>
</x-filament-panels::page>
