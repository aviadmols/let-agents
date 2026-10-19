{{-- One settings entry as the design draws it: its title, a master switch when it has one, the line under it, its rows. --}}
@props(['title', 'help' => null, 'master' => null])
<section {{ $attributes->class('lp-card lp-set-card') }}>
    <div class="lp-card-t">
        <h2>{{ $title }}</h2>
        <div class="lp-set-head">
            {{ $head ?? '' }}
            @if ($master)<x-lp::toggle :model="$master" :label="$title" />@endif
        </div>
    </div>
    @if ($help)<p class="lp-set-help">{{ $help }}</p>@endif
    {{ $slot }}
</section>
