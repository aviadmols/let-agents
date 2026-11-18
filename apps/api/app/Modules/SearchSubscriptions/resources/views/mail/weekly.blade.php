{{-- Email B: the week's new arrivals for an address's searches in one store (WeeklyNewArrivalsMail). The same layout in
     every store, always aligned left. --}}
@php
    $body = view('search_subscriptions::mail.parts.weekly-body', ['store' => $store, 'searches' => $searches, 'storeUrl' => $storeUrl])->render();
@endphp
@include('search_subscriptions::mail.frame', [
    'headline' => '<span style="color: #e2ff78;">'.$total.' new</span> for your searches.',
    'lead' => 'Everything here arrived this week and is in stock now.',
    'body' => $body,
    'foot' => '<a href="'.e($manageUrl).'" style="color: #e2ff78; text-decoration: none;">Manage your searches</a> · <a href="'.e($stopAllUrl).'" style="color: #a9a9b2;">Unsubscribe from all</a>',
    'pixel' => $pixel,
])
