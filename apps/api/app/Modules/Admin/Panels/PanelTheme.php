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
    /** Outfit carries Latin and digits, Rubik the Hebrew (Outfit has no Hebrew glyphs). */
    public const FONT_URL = 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Rubik:wght@400;500;600;700&display=swap';

    private static ?string $css = null;

    public static function styles(): HtmlString
    {
        self::$css ??= (string) file_get_contents(dirname(__DIR__).'/resources/css/panel.css');

        return new HtmlString('<style data-lets-theme>'.self::$css.'</style>');
    }
}
