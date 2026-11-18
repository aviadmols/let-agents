{{-- Email C: still interested? (StillInterestedMail), some days before a subscription ends. --}}
@php
    $body = view('search_subscriptions::mail.parts.remind-body', compact('query', 'subscribed', 'ends', 'keepDays', 'keepUrl'))->render();
@endphp
@include('search_subscriptions::mail.frame', [
    'headline' => 'Still <span style="color: #e2ff78;">interested</span>?',
    'lead' => 'Your subscription to this search ends in '.$days.' '.($days === 1 ? 'day' : 'days').'.',
    'body' => $body,
    'foot' => '<a href="'.e($manageUrl).'" style="color: #e2ff78; text-decoration: none;">Manage your searches</a> · <a href="'.e($stopUrl).'" style="color: #a9a9b2;">Stop now</a>',
])
