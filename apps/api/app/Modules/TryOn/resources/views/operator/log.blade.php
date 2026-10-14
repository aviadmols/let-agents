<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('try_on::try_on.log.'.$key, $params))
    @php($muted = 'opacity:.65;font-size:12px')
    @php($cell = 'padding:6px 8px;border-top:1px solid rgba(127,127,127,.2);text-align:start;vertical-align:top')
    @php($colors = ['queued' => 'gray', 'working' => 'warning', 'ready' => 'success', 'failed' => 'danger', 'passed' => 'success', 'done' => 'success', 'refused' => 'warning'])
    @php($month = $this->month())
    @php($jobs = $this->jobs() ?? collect())

    @php($link = $this->testLink())
    @if ($link)
        <x-filament::section :heading="$t('test_title')" :description="$t('test_help')" collapsible data-try-on-test>
            <p style="font-size:13px">{{ $t('test_add') }}</p>
            <code dir="ltr" style="display:block;padding:8px 10px;border-radius:8px;background:rgba(127,127,127,.1);font-size:12.5px;word-break:break-all">?{{ $link['params'] }}</code>
            <p style="{{ $muted }};margin-top:8px">{{ $t('test_class') }}</p>
            <p style="{{ $muted }}">{{ $t('test_off') }}</p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <p data-try-on-month>{{ $t('total', ['count' => $month['count'], 'ready' => $month['ready'], 'usd' => $month['usd']]) }}</p>
    </x-filament::section>

    <x-filament::section>
        @if ($jobs->isEmpty())
            <p style="{{ $muted }}">{{ $t('none') }}</p>
        @else
            <table style="width:100%;font-size:13px;border-collapse:collapse">
                <thead><tr style="opacity:.7">
                    <th style="{{ $cell }}">{{ $t('when') }}</th><th style="{{ $cell }}">{{ $t('product') }}</th><th style="{{ $cell }}">{{ $t('status') }}</th>
                    <th style="{{ $cell }}">{{ $t('attempts') }}</th><th style="{{ $cell }}">{{ $t('cost') }}</th><th style="{{ $cell }}"></th>
                </tr></thead>
                <tbody>
                @foreach ($jobs as $job)
                    <tr data-try-on-job="{{ $job->id }}">
                        <td style="{{ $cell }}">{{ $job->created_at?->format('d/m H:i') }}</td>
                        <td style="{{ $cell }}">{{ $job->productTitle() }} <span style="{{ $muted }}" dir="ltr">{{ $job->tryon_class }}</span></td>
                        <td style="{{ $cell }}"><x-filament::badge :color="$colors[$job->status] ?? 'gray'">{{ $t('statuses.'.$job->status) }}</x-filament::badge> @if ($job->failure)<span style="{{ $muted }}" dir="ltr">{{ $job->failure }}</span>@endif</td>
                        <td style="{{ $cell }}">{{ $job->attempts }}</td>
                        <td style="{{ $cell }}" dir="ltr">${{ number_format((float) $job->cost_usd, 4) }}</td>
                        <td style="{{ $cell }}"><x-filament::button size="xs" color="gray" wire:click="open('{{ $job->id }}')">{{ $t('open') }}</x-filament::button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    @php($c = $this->current())
    @if ($c)
        <x-filament::section :heading="$c['job']->productTitle()" data-try-on-detail>
            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px">
                @if ($c['photo'])<img src="{{ $c['photo'] }}" alt="" style="height:180px;border-radius:8px">@endif
                @if ($c['job']->productImage())<img src="{{ $c['job']->productImage() }}" alt="" style="height:180px;border-radius:8px">@endif
                @if ($c['result'])<img src="{{ $c['result'] }}" alt="" style="height:180px;border-radius:8px" data-try-on-result>@endif
            </div>
            @if ($c['job']->answers)<p style="font-size:13px"><strong>{{ $t('answers') }}:</strong> <span dir="ltr">{{ json_encode($c['job']->answers, JSON_UNESCAPED_UNICODE) }}</span></p>@endif

            <h3 style="font-weight:600;margin:12px 0 6px">{{ $t('steps') }}</h3>
            @foreach ($c['steps'] as $step)
                <details style="border:1px solid rgba(127,127,127,.25);border-radius:8px;padding:8px 10px;margin-bottom:8px" data-try-on-step="{{ $step['step'] }}">
                    <summary style="cursor:pointer;font-size:13px">
                        <strong dir="ltr">{{ $step['step'] }}</strong> #{{ $step['attempt'] }}
                        <x-filament::badge size="sm" :color="$colors[$step['status']] ?? 'gray'">{{ $step['status'] }}</x-filament::badge>
                        <span style="{{ $muted }}" dir="ltr">{{ $step['provider'] }} {{ $step['model'] }} · {{ $step['tokens'] }} · {{ $step['seconds'] }}s · ${{ number_format($step['cost'], 5) }}</span>
                    </summary>
                    @if ($step['images'] !== [])
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">
                            @foreach ($step['images'] as $role => $url)
                                @if ($url)<figure style="margin:0"><img src="{{ $url }}" alt="" style="height:120px;border-radius:6px"><figcaption style="{{ $muted }}" dir="ltr">{{ $role }}</figcaption></figure>@endif
                            @endforeach
                        </div>
                    @endif
                    @if ($step['prompt'])<pre style="white-space:pre-wrap;font-size:12px;background:rgba(127,127,127,.08);padding:8px;border-radius:6px" dir="ltr">{{ $step['prompt'] }}</pre>@endif
                    @if ($step['input'])<pre style="white-space:pre-wrap;font-size:12px" dir="ltr">{{ json_encode($step['input'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>@endif
                    @if ($step['output'])<pre style="white-space:pre-wrap;font-size:12px" dir="ltr">{{ json_encode($step['output'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>@endif
                    @if ($step['error'])<p style="color:#b91c1c;font-size:12px" dir="ltr">{{ $step['error'] }}</p>@endif
                </details>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
