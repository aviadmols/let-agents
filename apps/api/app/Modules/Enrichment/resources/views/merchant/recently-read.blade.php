<x-filament-widgets::widget>
    @php($t = fn (string $key, array $params = []) => __('enrichment::scan.recent.'.$key, $params))
    @if ($rows !== [])
        <section class="la-card" data-recently-read>
            <div class="la-card-body" style="display: grid; gap: 10px">
                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap">
                    <h2 style="margin: 0; font-size: 17px; font-weight: 700">{{ $t('title') }}</h2>
                    <span style="font-size: 12.5px; color: var(--la-muted)">{{ $t('help') }}</span>
                </div>
                <div style="display: grid; gap: 2px">
                    @foreach ($rows as $r)
                        <a href="{{ $r['url'] }}" style="display: flex; align-items: center; gap: 12px; padding: 8px 6px; border-radius: 10px; color: inherit; text-decoration: none" onmouseover="this.style.background='rgba(127,127,127,.08)'" onmouseout="this.style.background=''">
                            <span style="width: 40px; height: 40px; flex: none; border-radius: 8px; overflow: hidden; background: rgba(127,127,127,.12)">@if ($r['thumb'])<img src="{{ $r['thumb'] }}" alt="" loading="lazy" style="width: 100%; height: 100%; object-fit: cover">@endif</span>
                            <span style="flex: 1; min-width: 0">
                                <span style="display: block; font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" dir="auto">{{ $r['title'] }}</span>
                                <span style="font-size: 12.5px; color: var(--la-muted)">{{ $t('kinds.'.$r['kind']) }} · {{ $t($r['new'] ? 'came' : 'changed') }} {{ $r['at'] }}</span>
                            </span>
                            <span style="font-size: 12.5px; color: var(--la-muted); white-space: nowrap">{{ $t('open') }} ›</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-filament-widgets::widget>
