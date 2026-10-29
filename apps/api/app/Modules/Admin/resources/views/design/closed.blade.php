{{-- A designer's link that is used up, revoked or never was (DesignPassController). Outside the panels. --}}
@php($locale = app()->getLocale())
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Lets</title>
    <link rel="stylesheet" href="{{ \App\Modules\Admin\Panels\PanelTheme::FONT_URL }}">
    <style>
        :root { color-scheme: light dark; --paper: #fff; --plane: #f4f3ef; --ink: #111; --muted: #6b6b6b; }
        @media (prefers-color-scheme: dark) { :root { --paper: #1b1b1b; --plane: #111; --ink: #f2f2f2; --muted: #a3a3a3; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--plane); color: var(--ink); font-family: Heebo, system-ui, sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--paper); border-radius: 22px; padding: 28px; }
        h1 { margin: 0 0 8px; font-size: 21px; font-weight: 600; }
        p { margin: 0; font-size: 14.5px; line-height: 1.6; color: var(--muted); }
    </style>
</head>
<body>
    <main data-design-closed>
        <h1>{{ __('admin::design.closed.title') }}</h1>
        <p>{{ __('admin::design.closed.body') }}</p>
    </main>
</body>
</html>
