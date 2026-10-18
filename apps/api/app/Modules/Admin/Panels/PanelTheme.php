<?php

namespace App\Modules\Admin\Panels;

use Illuminate\Support\HtmlString;

/**
 * The LETS look for both panels, on top of Filament's own stylesheet.
 *
 * There is no asset build in this app, so the stylesheet is a plain CSS file in the Admin module,
 * read once per worker and inlined into the page head. It targets Filament's fi-* classes and its
 * color variables, so every screen, including ones written later, gets the same look.
 */
final class PanelTheme
{
    /** Heebo carries Hebrew and Latin in one family, as on app.lets.co.il. */
    public const FONT_URL = 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700;900&display=swap';

    /** The drawer's face (Google Sans) for the merchant panel, Heebo still under it for Hebrew. */
    public const MERCHANT_FONT_URL = 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap';

    private static ?string $css = null;

    private static ?string $merchant = null;

    public static function styles(): HtmlString
    {
        self::$css ??= (string) file_get_contents(dirname(__DIR__).'/resources/css/panel.css');

        return new HtmlString('<style data-lets-theme>'.self::$css.'</style>');
    }

    /** The merchant panel's own layer, after the shared one: the Lets Search language (merchant.css). */
    public static function merchant(): HtmlString
    {
        self::$merchant ??= (string) file_get_contents(dirname(__DIR__).'/resources/css/merchant.css');

        return new HtmlString('<link rel="stylesheet" href="'.self::MERCHANT_FONT_URL.'"><style data-lets-merchant>'.self::$merchant.'</style>');
    }
}
