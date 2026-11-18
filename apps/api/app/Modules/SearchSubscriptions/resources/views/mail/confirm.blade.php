{{-- Email A: confirm your subscription (ConfirmSubscriptionMail). --}}
@php
    $body = view('search_subscriptions::mail.parts.confirm-body', ['store' => $store, 'query' => $query, 'confirmUrl' => $confirmUrl])->render();
@endphp
@include('search_subscriptions::mail.frame', [
    'headline' => 'Confirm your <span style="color: #e2ff78;">subscription</span>.',
    'lead' => 'One tap, and we’ll email you new arrivals for this search.',
    'body' => $body,
    'foot' => 'Sent for '.e($store).' by Let’s, the store’s search.',
])
