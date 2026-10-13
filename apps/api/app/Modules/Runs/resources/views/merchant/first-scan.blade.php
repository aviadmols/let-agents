<x-filament-widgets::widget>
    @if ($shop)
        @php($running = $state !== null && ! $state['finished'])
        @php($first = $isNew && $state === null)
        <section class="la-card" data-first-scan @if ($running) wire:poll.10s @endif
            @if ($first) style="box-shadow: 0 0 0 2px var(--la-accent), var(--la-shadow)" @endif>
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
                        <h2 style="margin: 0; font-size: 18px; font-weight: 700">{{ __($running ? 'runs::first_scan.title_running' : ($first ? 'runs::first_scan.title_new' : 'runs::first_scan.title')) }}</h2>
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
                        <div class="la-actions">
                            <button type="button" wire:click="start" wire:loading.attr="disabled" @disabled(! $canStart && ! $first)
                                class="la-btn {{ $first ? 'la-btn-primary' : '' }}" style="{{ $first ? 'height: 44px; padding: 0 22px; font-size: 15px' : '' }}" data-first-scan-start>
                                {{ __($first ? 'runs::first_scan.start' : 'runs::first_scan.changes') }}
                            </button>
                            @if ($operator && ! $first)
                                <button type="button" wire:click="startFull" wire:loading.attr="disabled" wire:confirm="{{ __('runs::first_scan.full_confirm') }}" class="la-btn la-btn-quiet" data-first-scan-full>{{ __('runs::first_scan.full') }}</button>
                            @endif
                        </div>
                    @endunless
                </div>

                @if ($state || $isNew)
                    <p class="la-note" style="margin: 0">{{ __('runs::first_scan.click_step') }}</p>
                    <ol style="display: grid; gap: 0; margin: 0; padding: 0; list-style: none; border: 1px solid var(--la-line); border-radius: 10px; overflow: hidden">
                        @foreach ($steps as $step)
                            @php($at = $state['steps'][$step] ?? null)
                            @php($isOpen = $open === $step)
                            <li style="{{ $loop->first ? '' : 'border-block-start: 1px solid var(--la-line-2);' }}" data-step="{{ $step }}" data-state="{{ $at ?? 'idle' }}">
                                <button type="button" wire:click="toggle('{{ $step }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                    style="all: unset; box-sizing: border-box; cursor: pointer; width: 100%; display: grid; grid-template-columns: 18px minmax(0, 1fr) auto 14px; gap: 4px 10px; align-items: center; padding: 10px 14px; font-size: 14px; {{ $isOpen ? 'background: var(--la-sunk);' : '' }}">
                                    <span class="la-dot" data-tone="{{ ['done' => 'good', 'running' => 'info', 'failed' => 'bad'][$at] ?? '' }}" style="justify-self: center"></span>
                                    <span style="font-weight: 600">{{ __("runs::first_scan.steps.{$step}") }}</span>
                                    <small style="color: var(--la-muted)">{{ $at && $at !== 'waiting' ? __("runs::first_scan.states.{$at}") : '' }}</small>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" style="width: 14px; height: 14px; fill: none; stroke: var(--la-muted); stroke-width: 2.2; stroke-linecap: round; {{ $isOpen ? 'transform: rotate(180deg)' : '' }}"><path d="M6 9l6 6 6-6"></path></svg>
                                    @foreach ($notes[$step] ?? [] as $note)
                                        <span></span>
                                        <span style="grid-column: 2 / -1; font-size: 13px; color: var(--la-ink-2)" data-step-note>{{ $note }}</span>
                                    @endforeach
                                    @if ($at === 'done' && array_key_exists($step, $notes) && $notes[$step] === [])
                                        <span></span>
                                        <span style="grid-column: 2 / -1; font-size: 13px; color: var(--la-muted)">{{ __('runs::first_scan.nothing_new') }}</span>
                                    @endif
                                </button>
                                @if ($isOpen)
                                    <div style="display: grid; gap: 12px; padding: 4px 42px 14px; background: var(--la-sunk)" data-step-details>
                                        @forelse ($details as $section)
                                            <div style="display: grid; gap: 4px">
                                                <p class="la-eyebrow" style="font-size: 12px; color: var(--la-ink)">{{ $section['title'] }}</p>
                                                @if ($section['rows'] !== [])
                                                    <table class="la-table" style="background: var(--la-paper); border-radius: 8px; overflow: hidden">
                                                        <tbody>
                                                            @foreach ($section['rows'] as [$label, $value])
                                                                <tr><td class="la-strong" style="width: 40%">{{ $label }}</td><td>{{ $value }}</td></tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                @endif
                                            </div>
                                        @empty
                                            <p class="la-note" style="margin: 0">{{ __('runs::first_scan.no_details') }}</p>
                                        @endforelse
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </section>
    @endif
</x-filament-widgets::widget>
