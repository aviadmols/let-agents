{{--
    The design bar, at the foot of every screen while a designer's pass is open (PanelTheme): the shop, the time left,
    the stylesheet loaded from the designer's own computer (set, or back to the panel's own), and this screen saved as
    an HTML file with its real content, to work on and send back. Never part of the design: it is left out of the file.
--}}
@php($t = fn (string $key, array $params = []) => __('admin::design.bar.'.$key, $params))
<div data-design-bar dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}" style="position:fixed;inset-inline:12px;bottom:12px;z-index:2147483000;display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;padding:8px 12px;border-radius:14px;background:#111;color:#fff;font:500 12.5px/1.4 Heebo,system-ui,sans-serif;box-shadow:0 12px 32px -12px rgba(0,0,0,.5)">
    <strong>{{ $t('title') }}</strong>
    <span style="opacity:.7">{{ $shop }} · {{ $t('left', ['time' => $left]) }}</span>
    <form method="post" action="{{ route('admin.design.css') }}" style="display:flex;gap:6px;align-items:center;margin:0">
        @csrf
        <input name="url" value="{{ $css }}" placeholder="http://localhost:5500/merchant.css" aria-label="{{ $t('css') }}" dir="ltr"
            style="width:260px;max-width:52vw;height:30px;padding:0 10px;border:0;border-radius:8px;background:#2a2a2a;color:#fff;font:inherit">
        <button style="height:30px;padding:0 10px;border:0;border-radius:8px;background:#e2ff78;color:#111;font:inherit;cursor:pointer">{{ $css ? $t('css_reload') : $t('css_set') }}</button>
    </form>
    @if ($css)
        <form method="post" action="{{ route('admin.design.css') }}" style="margin:0">@csrf<input type="hidden" name="url" value=""><button style="height:30px;padding:0 10px;border:0;border-radius:8px;background:transparent;color:#fff;text-decoration:underline;font:inherit;cursor:pointer">{{ $t('css_clear') }}</button></form>
        <span style="opacity:.7">{{ $t('css_on') }}</span>
    @endif
    <button type="button" onclick="lsDesignSnapshot()" style="margin-inline-start:auto;height:30px;padding:0 12px;border:0;border-radius:8px;background:#fff;color:#111;font:inherit;cursor:pointer">{{ $t('save') }}</button>
</div>
<script>
    // this screen as it is drawn now, with its real content, as an HTML file: the bar and the scripts left out, the
    // addresses kept whole so it opens on the designer's computer as it looks here
    function lsDesignSnapshot() {
        const doc = document.documentElement.cloneNode(true);
        doc.querySelectorAll('[data-design-bar], script').forEach((n) => n.remove());
        const base = document.createElement('base');
        base.href = location.origin + '/';
        doc.querySelector('head').prepend(base);
        const name = 'screen-' + (location.pathname.split('/').filter(Boolean).slice(-1)[0] || 'home') + '.html';
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['<!doctype html>\n' + doc.outerHTML], { type: 'text/html' }));
        a.download = name;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }
</script>
