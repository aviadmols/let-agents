<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('shopify::app.declined_title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.6 system-ui, Heebo, sans-serif; background: #f7f7f8; color: #111; }
        main { max-width: 440px; padding: 32px; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,.08); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        a { display: inline-block; margin-top: 16px; padding: 10px 18px; border-radius: 999px; background: #111; color: #fff; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('shopify::app.declined_title') }}</h1>
    <p>{{ __('shopify::app.declined_text') }}</p>
    <a href="{{ 'https://'.$shop.'/admin/apps/'.config('services.shopify.handle') }}">{{ __('shopify::app.declined_again') }}</a>
</main>
</body>
</html>