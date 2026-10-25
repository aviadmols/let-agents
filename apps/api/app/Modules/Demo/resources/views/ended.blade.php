{{-- A demo that ended, failed or is no longer shown (DemoController::show). --}}
@extends('demo::layout')
@php($t = fn (string $key, array $params = []) => __('demo::page.'.$key, $params))
@section('title', $t('ended.title'))
@section('body')
    <section class="hero">
        <span class="hero__glow" aria-hidden="true"></span>
        <span class="brand"><i aria-hidden="true"></i>Lets</span>
        <h1>{{ $site->status === \App\Modules\Demo\Models\DemoSite::FAILED ? $t('ended.failed_title') : $t('ended.title') }}</h1>
        <p class="lede">{{ $site->status === \App\Modules\Demo\Models\DemoSite::FAILED ? $t('ended.failed.'.($site->error ?? 'other')) : $t('ended.lede', ['site' => $site->title ?? $site->host]) }}</p>
    </section>
    <div class="card" style="text-align: center" data-demo-ended="{{ $site->status }}">
        <a class="btn" href="{{ $t('view.cta_url') }}">{{ $t('view.cta') }}</a>
        <p class="note"><a href="{{ route('demo.form', ['lang' => app()->getLocale()]) }}">{{ $t('ended.again') }}</a></p>
    </div>
@endsection
