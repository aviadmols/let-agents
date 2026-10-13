<x-filament-widgets::widget>
    @if ($shop)
        @php($running = $state !== null && ! $state['finished'])
        <section class="la-card" data-first-scan @if ($running) wire:poll.10s @endif
            @if ($isNew && $state === null) style="box-shadow: 0 0 0 2px var(--la-accent), var(--la-shadow)" @endif>
            <div class="la-card-body" style="display: grid; gap: 14px">
                <div style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px">
                    <div style="display: grid; gap: 6px; max-width: 640px; justify-items: start">
                        @if ($running)
                            <span class="la-badge" data-tone="info"><i></i>{{ __('runs::first_scan.badge_running') }}</span>
                        @elseif ($state && $state['finished'])
                            <span class="la-badge" data-tone="good"><i></i>{{ __('runs::first_scan.badge_done') }}</span>
                        @elseif ($isNew)
                            <span class="la-badge" data-tone="warn"><i></i>{{ __('runs::first_scan.badge_new') }}</span>
                        @endif
                        <h2 style="margin: 0; font-size: 18px; font-weight: 700">{{ __($running ? 'runs::first_scan.title_running' : ($isNew && $state === null ? 'runs::first_scan.title_new' : 'runs::first_scan.title')) }}</h2>
                        <p style="margin: 0; color: var(--la-muted); font-size: 14px">
                            @if ($running)
                                {{ __('runs::first_scan.body_running', ['time' => $state['started']->timezone(config('app.display_timezone', 'Asia/Jerusalem'))->format('H:i')]) }}
                            @elseif ($state && $state['finished'])
                                {{ __('runs::first_scan.body_done', ['time' => $state['started']->timezone(config('app.display_timezone', 'Asia/Jerusalem'))->format('d.m H:i')]) }}
                            @else
                                {{ __($isNew ? 'runs::first_scan.body_new' : 'runs::first_scan.body') }}
                            @endif
                        </p>
                    </div>
                    @unless ($running)
                        <button type="button" wire:click="start" wire:loading.attr="disabled" @disabled(! $canStart)
                            class="la-btn {{ $isNew && $state === null ? 'la-btn-primary' : '' }}" style="{{ $isNew && $state === null ? 'height: 44px; padding: 0 22px; font-size: 15px' : '' }}" data-first-scan-start>
                            {{ __($state ? 'runs::first_scan.again' : 'runs::first_scan.start') }}
                        </button>
                    @endunless
                </div>

                @if ($state || $isNew)
                    <ol style="display: grid; gap: 0; margin: 0; padding: 0; list-style: none; border: 1px solid var(--la-line); border-radius: 10px; overflow: hidden">
                        @foreach ($steps as $step)
                            @php($at = $state['steps'][$step] ?? null)
                            <li style="display: grid; grid-template-columns: 18px minmax(0, 1fr) auto; gap: 4px 10px; align-items: center; padding: 10px 14px; font-size: 14px; {{ $loop->first ? '' : 'border-block-start: 1px solid var(--la-line-2);' }}" data-step="{{ $step }}" data-state="{{ $at ?? 'idle' }}">
                                <span class="la-dot" data-tone="{{ ['done' => 'good', 'running' => 'info', 'failed' => 'bad'][$at] ?? '' }}" style="justify-self: center"></span>
                                <span style="font-weight: 600">{{ __("runs::first_scan.steps.{$step}") }}</span>
                                <small style="color: var(--la-muted)">{{ $at && $at !== 'waiting' ? __("runs::first_scan.states.{$at}") : '' }}</small>
                                @foreach ($notes[$step] ?? [] as $note)
                                    <span></span>
                                    <span style="grid-column: 2 / -1; font-size: 13px; color: var(--la-ink-2)" data-step-note>{{ $note }}</span>
                                @endforeach
                                @if ($at === 'done' && array_key_exists($step, $notes) && $notes[$step] === [])
                                    <span></span>
                                    <span style="grid-column: 2 / -1; font-size: 13px; color: var(--la-muted)">{{ __('runs::first_scan.nothing_new') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </section>
    @endif
</x-filament-widgets::widget>
