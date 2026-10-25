{{-- The demo's first screen: the store's address, the email, what the site is and what matters in its search (DemoController::form). --}}
@extends('demo::layout')
@php($t = fn (string $key, array $params = []) => __('demo::page.'.$key, $params))
@section('title', $t('start.title_short'))
@section('body')
    <section class="hero">
        <span class="hero__glow" aria-hidden="true"></span>
        <a class="brand" href="https://lets.co.il{{ $locale === 'he' ? '/he/' : '/' }}"><i aria-hidden="true"></i>Lets</a>
        <h1>{{ $t('start.title') }}<br><em>{{ $t('start.title_em') }}</em></h1>
        <p class="lede">{{ $t('start.lede') }}</p>
    </section>

    <form class="card" method="post" action="{{ route('demo.start') }}" data-demo-start>
        @csrf
        <input type="hidden" name="lang" value="{{ $locale }}">
        <h2>{{ $t('start.form_title') }}</h2>
        <p class="lead">{{ $t('start.form_lead') }}</p>

        @if ($error)
            <div class="alert" role="alert" data-error="{{ $error }}">{{ $t('errors.'.$error) }}</div>
        @endif

        <label class="field" for="demo-url">{{ $t('start.url') }}</label>
        <input class="input" id="demo-url" name="url" type="text" inputmode="url" dir="ltr" required placeholder="https://www.your-store.co.il" value="{{ $old['url'] ?? '' }}" autocomplete="url">

        <label class="field" for="demo-email">{{ $t('start.email') }}</label>
        <input class="input" id="demo-email" name="email" type="email" dir="ltr" required placeholder="you@your-store.co.il" value="{{ $old['email'] ?? '' }}" autocomplete="email">

        <fieldset style="border: 0; padding: 0; margin: 0">
            <legend class="field" style="padding: 0">{{ $t('start.kind') }}</legend>
            <div class="choices">
                @foreach (['store', 'content', 'both'] as $kind)
                    <label class="choice"><input type="radio" name="kind" value="{{ $kind }}" required @checked(($old['kind'] ?? 'store') === $kind)><span><b>{{ $t('start.kinds.'.$kind.'.title') }}</b><small>{{ $t('start.kinds.'.$kind.'.text') }}</small></span></label>
                @endforeach
            </div>
        </fieldset>

        <fieldset style="border: 0; padding: 0; margin: 0">
            <legend class="field" style="padding: 0">{{ $t('start.matters') }}</legend>
            <div class="choices">
                @foreach (['look', 'content', 'both'] as $matters)
                    <label class="choice"><input type="radio" name="matters" value="{{ $matters }}" required @checked(($old['matters'] ?? 'both') === $matters)><span><b>{{ $t('start.matters_options.'.$matters.'.title') }}</b><small>{{ $t('start.matters_options.'.$matters.'.text') }}</small></span></label>
                @endforeach
            </div>
        </fieldset>

        <label class="check"><input type="checkbox" name="consent" value="1" @checked($old['consent'] ?? false)><span>{{ $t('start.consent') }}</span></label>

        <button class="btn btn--block" type="submit">{{ $t('start.go') }}</button>
        <p class="note">{{ $t('start.privacy', ['days' => \App\Core\Facades\Settings::get('demo.keep_days')]) }}</p>
    </form>
    <p class="foot">© Lets · <a href="https://lets.co.il{{ $locale === 'he' ? '/he/' : '/' }}">lets.co.il</a></p>
@endsection
