@if ($checks !== [])
    <div style="margin-top:6px;padding:8px 10px;border-radius:8px;background:rgba(245,158,11,.10);font-size:12.5px" data-draft-checks>
        <div style="font-weight:600;margin-bottom:2px">{{ __('assistant::ui.questions.draft_check') }}</div>
        <ul style="margin:0;padding-inline-start:18px;list-style:disc">
            @foreach ($checks as $check)
                <li>{{ $check }}</li>
            @endforeach
        </ul>
    </div>
@endif
