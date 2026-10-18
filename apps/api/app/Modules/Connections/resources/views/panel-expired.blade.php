<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('connections::panel.expired_title') }}</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: "Google Sans", "Heebo", system-ui, sans-serif; background: #f6f6f7; color: #0b0b0c; }
  main { max-width: 420px; padding: 32px; text-align: center; }
  h1 { font-size: 20px; margin: 0 0 8px; }
  p { margin: 0; color: #64646c; line-height: 1.5; }
</style>
</head>
<body>
<main>
  <h1>{{ __('connections::panel.expired_title') }}</h1>
  <p>{{ __('connections::panel.expired_body') }}</p>
</main>
</body>
</html>
