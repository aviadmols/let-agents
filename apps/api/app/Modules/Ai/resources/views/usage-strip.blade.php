{{-- The month's model allowance, at the top of every admin screen (App\Modules\Ai\Support\UsageStrip). --}}
<div class="la-usage" @if ($tone !== '') data-tone="{{ $tone }}" @endif data-usage-strip role="status">
    <span class="la-usage-title">{{ $title }}</span>
    <span class="la-bar" aria-hidden="true"><i style="width: {{ min($percent, 100) }}%" @if ($tone !== '') data-tone="{{ $tone }}" @endif></i></span>
    <span><strong>{{ $main }}</strong>@if ($side) · {{ $side }}@endif</span>
    @if ($note || $link)
        <span class="la-usage-end">
            @if ($note)
                <span>{{ $note }}</span>
            @endif
            @if ($link)
                <a href="{{ $link }}" class="la-btn la-btn-sm">{{ $linkLabel }}</a>
            @endif
        </span>
    @endif
</div>
