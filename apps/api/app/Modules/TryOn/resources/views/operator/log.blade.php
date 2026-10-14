<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('try_on::try_on.log.'.$key, $params))
    @php($h = fn (string $key, array $params = []) => __('try_on::hub.'.$key, $params))
    @php($page = \App\Modules\TryOn\Filament\Operator\Pages\TryOnLog::class)
    @php($muted = 'opacity:.65;font-size:12px')
    @php($cell = 'padding:6px 8px;border-top:1px solid rgba(127,127,127,.2);text-align:start;vertical-align:top')
    @php($card = 'padding:14px 16px;border:1px solid rgba(127,127,127,.2);border-radius:14px')
    @php($colors = ['queued' => 'gray', 'working' => 'warning', 'ready' => 'success', 'failed' => 'danger', 'ok' => 'success', 'rejected' => 'danger'])

    <x-filament::tabs>
        @foreach ($page::TABS as $name)
            <x-filament::tabs.item :active="$tab === $name" wire:click="show('{{ $name }}')" data-try-on-tab="{{ $name }}">{{ $h('tabs.'.$name) }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{-- ------------------------------------------------------------ overview --}}
    @if ($tab === 'overview')
        @php($status = $this->status())
        @php($month = $this->month())

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
            <div style="{{ $card }}" data-try-on-status>
                <div style="{{ $muted }}">{{ $h('status.this_shop') }}</div>
                <div style="display:flex;align-items:center;gap:8px;margin:6px 0 10px">
                    <x-filament::badge :color="$status['on'] ? 'success' : 'gray'">{{ $status['on'] ? $h('status.on') : $h('status.off') }}</x-filament::badge>
                    <span style="{{ $muted }}">{{ $h('status.source.'.$status['source']) }}</span>
                </div>
                @if ($status['shop'])
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        <x-filament::button size="xs" color="success" wire:click="setShop(true)">{{ $h('status.turn_on') }}</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="setShop(false)">{{ $h('status.turn_off') }}</x-filament::button>
                        @if ($status['source'] === 'shop')
                            <x-filament::button size="xs" color="gray" outlined wire:click="setShop(null)">{{ $h('status.inherit') }}</x-filament::button>
                        @endif
                    </div>
                @endif
            </div>

            <div style="{{ $card }}">
                <div style="{{ $muted }}">{{ $h('status.all_shops') }}</div>
                <div style="margin:6px 0 10px"><x-filament::badge :color="$status['global'] ? 'success' : 'gray'">{{ $status['global'] ? $h('status.on') : $h('status.off') }}</x-filament::badge></div>
                <div style="display:flex;gap:6px">
                    <x-filament::button size="xs" color="gray" wire:click="setGlobal(true)">{{ $h('status.turn_on') }}</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="setGlobal(false)">{{ $h('status.turn_off') }}</x-filament::button>
                </div>
            </div>

            <div style="{{ $card }}" data-try-on-month>
                <div style="{{ $muted }}">{{ $h('month.title') }}</div>
                <p style="margin:6px 0 4px">{{ $t('total', $this->monthLine()) }}</p>
                <div style="{{ $muted }}">{{ $h('month.detail', ['failed' => $month['failed'], 'photos' => $month['photos'], 'refused' => $month['refused']]) }}</div>
                <div style="margin-top:10px;height:8px;border-radius:999px;background:rgba(127,127,127,.15);overflow:hidden">
                    <div style="width:{{ $month['share'] }}%;height:100%;background:linear-gradient(90deg,#4285f4,#8b5cf6 60%,#ec4899)"></div>
                </div>
                <div style="{{ $muted }};margin-top:4px" dir="ltr">${{ $month['usd_text'] }} / ${{ number_format($month['cap'], 2) }}</div>
            </div>
        </div>

        @php($link = $this->testLink())
        @if ($link)
            <x-filament::section :heading="$t('test_title')" :description="$t('test_help')" collapsible data-try-on-test>
                <p style="font-size:13px">{{ $t('test_add') }}</p>
                <code dir="ltr" style="display:block;padding:8px 10px;border-radius:8px;background:rgba(127,127,127,.1);font-size:12.5px;word-break:break-all">?{{ $link['params'] }}</code>
                <p style="{{ $muted }};margin-top:8px">{{ $t('test_class') }}</p>
                <p style="{{ $muted }}">{{ $t('test_off') }}</p>
            </x-filament::section>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px">
            <x-filament::section :heading="$h('recent')">
                @forelse ($this->jobs(6) as $job)
                    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-top:1px solid rgba(127,127,127,.15)" data-try-on-job="{{ $job->id }}">
                        <x-filament::badge size="sm" :color="$colors[$job->status] ?? 'gray'">{{ $t('statuses.'.$job->status) }}</x-filament::badge>
                        <span style="flex:1;font-size:13px">{{ $job->productTitle() }} <span style="{{ $muted }}" dir="ltr">{{ $job->tryon_class }}</span>
                            @if ($why = $this->failureWhy($job))<span style="display:block;font-size:12px;color:#b91c1c" data-try-on-why>{{ $why }}</span>@endif
                        </span>
                        <x-filament::button size="xs" color="gray" wire:click="open('{{ $job->id }}')">{{ $t('open') }}</x-filament::button>
                    </div>
                @empty
                    <p style="{{ $muted }}">{{ $t('none') }}</p>
                @endforelse
            </x-filament::section>

            <x-filament::section :heading="$h('failed_tries')">
                @forelse ($this->failures() as $code => $n)
                    <div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;font-size:13px"><span>{{ $page::failureName($code) }}</span><strong>{{ $n }}</strong></div>
                @empty
                    <p style="{{ $muted }}">{{ $h('no_failed_tries') }}</p>
                @endforelse
            </x-filament::section>

            <x-filament::section :heading="$h('refusals')">
                @forelse ($this->refusals() as $code => $n)
                    <div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;font-size:13px"><span>{{ $page::refusal($code) }}</span><strong>{{ $n }}</strong></div>
                @empty
                    <p style="{{ $muted }}">{{ $h('no_refusals') }}</p>
                @endforelse
            </x-filament::section>

            <x-filament::section :heading="$h('actions')">
                <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start">
                    <x-filament::button size="sm" color="gray" wire:click="show('settings')">{{ $h('go_settings') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="show('products')">{{ $h('go_products') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="pruneNow" wire:confirm="{{ $h('prune_confirm') }}">{{ $h('prune') }}</x-filament::button>
                    <p style="{{ $muted }}">{{ $h('prune_help') }}</p>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{-- ------------------------------------------------------------ tries --}}
    @if ($tab === 'tries')
        @php($jobs = $this->jobs())
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
                            <td style="{{ $cell }}" dir="ltr">{{ $job->created_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}</td>
                            <td style="{{ $cell }}">{{ $job->productTitle() }} <span style="{{ $muted }}" dir="ltr">{{ $job->tryon_class }}</span>@if ($job->product_id === null) <x-filament::badge size="sm" color="info">{{ $h('page_product') }}</x-filament::badge>@endif</td>
                            <td style="{{ $cell }}"><x-filament::badge :color="$colors[$job->status] ?? 'gray'">{{ $t('statuses.'.$job->status) }}</x-filament::badge>
                                @if ($why = $this->failureWhy($job))<div style="font-size:12px;color:#b91c1c;margin-top:3px" data-try-on-why>{{ $why }}</div>@endif
                            </td>
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
                @include('try_on::operator.partials.steps', ['steps' => $c['steps']])
            </x-filament::section>
        @endif
    @endif

    {{-- ------------------------------------------------------------ photos --}}
    @if ($tab === 'photos')
        @php($photos = $this->photos())
        <x-filament::section :description="$h('photos_help')">
            @if ($photos->isEmpty())
                <p style="{{ $muted }}">{{ $h('no_photos') }}</p>
            @else
                <table style="width:100%;font-size:13px;border-collapse:collapse">
                    <thead><tr style="opacity:.7">
                        <th style="{{ $cell }}">{{ $t('when') }}</th><th style="{{ $cell }}">{{ $t('status') }}</th><th style="{{ $cell }}">{{ $h('photo.seen') }}</th>
                        <th style="{{ $cell }}">{{ $h('photo.serves') }}</th><th style="{{ $cell }}">{{ $t('cost') }}</th><th style="{{ $cell }}"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($photos as $photo)
                        <tr data-try-on-photo="{{ $photo->id }}">
                            <td style="{{ $cell }}" dir="ltr">{{ $photo->created_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}</td>
                            <td style="{{ $cell }}">
                                <x-filament::badge size="sm" :color="$colors[$photo->status] ?? 'gray'">{{ $h('photo.statuses.'.$photo->status) }}</x-filament::badge>
                                @if ($photo->reject_code)<div style="{{ $muted }}">{{ $page::refusal($photo->reject_code) }}</div>@endif
                            </td>
                            <td style="{{ $cell }}" dir="ltr">{{ $photo->gate['subject'] ?? '' }}@if (! empty($photo->gate['parts_visible'])) · {{ implode(', ', $photo->gate['parts_visible']) }}@endif</td>
                            <td style="{{ $cell }}" dir="ltr">{{ implode(', ', (array) ($photo->usable ?? [])) }}</td>
                            <td style="{{ $cell }}" dir="ltr">${{ number_format((float) $photo->cost_usd, 5) }}</td>
                            <td style="{{ $cell }}"><x-filament::button size="xs" color="gray" wire:click="openPhoto('{{ $photo->id }}')">{{ $t('open') }}</x-filament::button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        @php($p = $this->currentPhoto())
        @if ($p)
            <x-filament::section :heading="$t('photo_check')" data-try-on-photo-detail>
                @if ($p['url'])<img src="{{ $p['url'] }}" alt="" style="height:220px;border-radius:8px;margin-bottom:10px">@endif
                @include('try_on::operator.partials.steps', ['steps' => $p['steps']])
            </x-filament::section>
        @endif
    @endif

    {{-- ------------------------------------------------------------ products --}}
    @if ($tab === 'products')
        @php($counts = $this->classCounts())
        <x-filament::section :description="$h('products_help')">
            @if ($counts === [])
                <p style="{{ $muted }}">{{ $h('no_products') }}</p>
            @else
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
                    @foreach ($counts as $class => $n)
                        <x-filament::button size="xs" :color="$productClass === $class ? 'primary' : 'gray'" wire:click="filterClass('{{ $class }}')" data-try-on-class="{{ $class }}">
                            {{ $page::className($class) }} · {{ $n }}
                        </x-filament::button>
                    @endforeach
                </div>
                <table style="width:100%;font-size:13px;border-collapse:collapse">
                    <tbody>
                    @foreach ($this->markedProducts() as $fact)
                        <tr>
                            <td style="{{ $cell }};width:56px">@if ($fact->product?->image_url)<img src="{{ $fact->product->image_url }}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px">@endif</td>
                            <td style="{{ $cell }}">@if ($fact->product?->url)<a href="{{ $fact->product->url }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $fact->product->title }}</a>@else{{ $fact->product?->title }}@endif</td>
                            <td style="{{ $cell }}">{{ $page::className((string) $fact->value_text) }}</td>
                            <td style="{{ $cell }}" dir="ltr"><span style="{{ $muted }}">#{{ $fact->product?->external_id }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------------ settings --}}
    @if ($tab === 'settings')
        @php($definitions = $this->definitions())
        <form wire:submit="save" style="display:grid;gap:16px" data-try-on-settings>
            @foreach (['shop', 'global'] as $scope)
                <x-filament::section :heading="$h('settings.'.$scope)" :description="$h('settings.'.$scope.'_help')">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
                        @foreach ($definitions[$scope] as $definition)
                            @php($key = $definition->name)
                            @php($unit = $page::unit($definition))
                            @php($inherited = $this->inherited($definition))
                            <label style="display:grid;gap:4px;align-content:start" data-setting="{{ $key }}">
                                <span style="font-size:13px;font-weight:600">{{ __($definition->labelKey()) }}</span>
                                @if ($page::isBool($definition))
                                    <input type="checkbox" wire:model="values.{{ $key }}">
                                @elseif ($definition->options !== [])
                                    @php($options = $page::options($definition))
                                    <select wire:model="values.{{ $key }}" style="padding:6px 10px;border:1px solid rgba(127,127,127,.35);border-radius:8px;background:transparent;color:inherit">
                                        <option value="">{{ $h('settings.inherit', ['value' => $options[$inherited] ?? $inherited]) }}</option>
                                        @foreach ($options as $value => $words)
                                            <option value="{{ $value }}">{{ $words }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span style="display:flex;align-items:center;gap:6px">
                                        <input type="text" wire:model="values.{{ $key }}" placeholder="{{ $inherited }}" dir="ltr"
                                               style="flex:1;min-width:0;padding:6px 10px;border:1px solid rgba(127,127,127,.35);border-radius:8px;background:transparent;color:inherit">
                                        @if ($unit)<span style="{{ $muted }}">{{ $unit }}</span>@endif
                                    </span>
                                @endif
                                <span style="{{ $muted }}">{{ __($definition->descriptionKey()) }}</span>
                                @error('values.'.$key)<span style="color:#dc2626;font-size:12px">{{ $message }}</span>@enderror
                            </label>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
            <div><x-filament::button type="submit">{{ $h('settings.save') }}</x-filament::button></div>
        </form>
    @endif
</x-filament-panels::page>
