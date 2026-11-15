<?php

namespace App\Core\Filament;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * A link from one panel screen to another, only when this user and this shop may open it: the target
 * screen's own canAccess() decides (its plan feature, its role), so a link never leads to a 403
 * (Take Me Home, 15.11: "Every visit" on the shoppers screen while the plan had no visits log).
 */
final class PageLink
{
    /** @param  array<string, mixed>  $parameters */
    public static function to(string $route, array $parameters = []): ?string
    {
        $target = Route::getRoutes()->getByName($route);

        if ($target === null) {
            return null;
        }

        $page = Str::before((string) ($target->getAction('uses') ?? ''), '@');

        if ($page !== '' && class_exists($page) && method_exists($page, 'canAccess') && ! $page::canAccess()) {
            return null;
        }

        return route($route, $parameters);
    }
}
