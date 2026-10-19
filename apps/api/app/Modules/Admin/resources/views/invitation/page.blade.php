{{--
    The page an invitation link opens (InvitationController): set a name and a password and come in, or what
    happened to the link. Outside the panels, so it carries its own small look, the panel's colours.
--}}
@php($t = fn (string $key, array $params = []) => __('admin::invitations.page.'.$key, $params))
@php($locale = app()->getLocale())
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $shop ? $t('title', ['shop' => $shop->name]) : 'Lets' }}</title>
    <link rel="stylesheet" href="{{ \App\Modules\Admin\Panels\PanelTheme::FONT_URL }}">
    <style>
        :root { color-scheme: light dark; --paper: #fff; --plane: #f4f3ef; --ink: #111; --muted: #6b6b6b; --line: #e4e2dc; --bad: #d23c3c; }
        @media (prefers-color-scheme: dark) { :root { --paper: #1b1b1b; --plane: #111; --ink: #f2f2f2; --muted: #a3a3a3; --line: #333; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--plane); color: var(--ink); font-family: Heebo, system-ui, sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--paper); border-radius: 22px; padding: 28px; }
        .brand { font-size: 13px; color: var(--muted); margin: 0 0 6px; }
        h1 { margin: 0 0 8px; font-size: 21px; font-weight: 600; }
        p { margin: 0 0 16px; font-size: 14.5px; line-height: 1.6; color: var(--muted); }
        label { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; font-size: 13px; color: var(--muted); }
        input { min-height: 42px; padding: 0 16px; border: 0; border-radius: 999px; background: var(--paper); box-shadow: inset 0 0 0 1.5px var(--line); font: inherit; font-size: 15px; color: var(--ink); }
        input:focus { outline: 0; box-shadow: inset 0 0 0 2px var(--ink); }
        .error { color: var(--bad); font-size: 12.5px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 22px; border: 0; border-radius: 999px; background: var(--ink); color: var(--paper); font: inherit; font-weight: 500; text-decoration: none; cursor: pointer; }
        .email { direction: ltr; unicode-bidi: plaintext; color: var(--ink); }
    </style>
</head>
<body>
<main>
    <p class="brand">Lets</p>
    @if ($state === 'accept')
        <h1>{{ $t('title', ['shop' => $shop?->name]) }}</h1>
        <p>{{ $t('lead', ['role' => $invitation->role->label(), 'what' => $invitation->role->description()]) }}</p>
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <label>{{ $t('email') }}<span class="email">{{ $invitation->email }}</span></label>
            <label>{{ $t('name') }}
                <input type="text" name="name" value="{{ old('name', $invitation->name) }}" required autocomplete="name">
                @error('name')<span class="error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label>{{ $t('password') }}
                <input type="password" name="password" required minlength="{{ \App\Modules\Admin\Models\User::MIN_PASSWORD_LENGTH }}" autocomplete="new-password" dir="ltr">
                @error('password')<span class="error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label>{{ $t('password_again') }}
                <input type="password" name="password_confirmation" required autocomplete="new-password" dir="ltr">
            </label>
            <button type="submit" class="btn">{{ $t('submit') }}</button>
        </form>
    @elseif ($state === 'joined')
        <h1>{{ $t('joined_title', ['shop' => $shop?->name]) }}</h1>
        <p>{{ $t('joined', ['role' => $invitation->role->label()]) }}</p>
        <a class="btn" href="{{ $login }}">{{ $t('sign_in') }}</a>
    @else
        <h1>{{ $t('closed_title') }}</h1>
        <p>{{ $t('closed.'.($reason ?? 'gone')) }}</p>
        <a class="btn" href="{{ $login }}">{{ $t('sign_in') }}</a>
    @endif
</main>
</body>
</html>
