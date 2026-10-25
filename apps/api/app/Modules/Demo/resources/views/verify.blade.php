{{-- The code sent to the email, typed back (DemoController::show, pending). --}}
@extends('demo::layout')
@php($t = fn (string $key, array $params = []) => __('demo::page.'.$key, $params))
@section('title', $t('verify.title'))
@section('body')
    <section class="hero">
        <span class="hero__glow" aria-hidden="true"></span>
        <span class="brand"><i aria-hidden="true"></i>Lets</span>
        <h1>{{ $t('verify.title') }}</h1>
        <p class="lede">{{ $t('verify.lede', ['email' => $site->email]) }}</p>
    </section>

    <form class="card" method="post" action="{{ route('demo.verify', $site->token) }}" data-demo-verify>
        @csrf
        @if ($result)
            <div @class(['alert', 'alert--ok' => $result === 'sent']) role="alert" data-result="{{ $result }}">{{ $t('verify.results.'.$result) }}</div>
        @endif
        <label class="field" for="demo-code">{{ $t('verify.code') }}</label>
        <input class="input code" id="demo-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        <button class="btn btn--block" type="submit">{{ $t('verify.go') }}</button>
    </form>
    <form method="post" action="{{ route('demo.code', $site->token) }}" class="note">
        @csrf
        {{ $t('verify.none') }} <button class="btn btn--quiet" type="submit">{{ $t('verify.again') }}</button>
    </form>
@endsection
