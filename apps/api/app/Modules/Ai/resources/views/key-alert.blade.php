{{-- A model provider whose key does not work, at the top of every operator screen (App\Modules\Ai\Support\KeyAlert). --}}
<div data-key-alert role="alert" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;margin:0 0 16px;padding:14px 18px;border-radius:12px;background:#fdecec;border:1px solid #f3b4b4;color:#7a1414;">
    <strong style="font-size:15px;">{{ __('ai::providers.alert.title') }}</strong>
    <span style="flex:1 1 320px;line-height:1.5;">
        @foreach ($providers as $p)
            <span style="display:block;"><strong>{{ $p['name'] }}</strong>: {{ $p['reason'] }}@if ($p['since']) · {{ __('ai::providers.alert.since', ['time' => $p['since']]) }}@endif</span>
        @endforeach
        <span style="display:block;margin-top:4px;">{{ __('ai::providers.alert.meanwhile') }}</span>
    </span>
    <a href="{{ $link }}" class="la-btn la-btn-sm" style="background:#b42318;color:#fff;border-color:#b42318;">{{ __('ai::providers.alert.fix') }}</a>
</div>
