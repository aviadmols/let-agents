<?php

namespace App\Modules\Admin\Panels;

use Filament\Navigation\NavigationGroup;

/**
 * The sidebar of each panel, grouped by topic and in a fixed order.
 *
 * A screen joins a group by naming its key, a plain string, in its own `$navigationGroup`
 * (`protected static string|UnitEnum|null $navigationGroup = 'shoppers';`), so a module needs no
 * import from this one. The labels live in admin::panels.nav and are read on every request,
 * because the panel is built once, before the visitor's language is known.
 */
final class PanelNavigation
{
    /** Operator panel, top to bottom. */
    public const OPERATOR = ['overview', 'shops', 'content', 'discovery', 'shoppers', 'analytics', 'system'];

    /** Merchant panel, top to bottom: what a shop owner looks at first comes first. */
    public const MERCHANT = ['overview', 'analytics', 'search', 'on_page', 'shoppers', 'settings'];

    /**
     * @param  list<string>  $keys
     * @return array<string, NavigationGroup>
     */
    public static function groups(array $keys): array
    {
        $groups = [];

        foreach ($keys as $key) {
            $groups[$key] = NavigationGroup::make(fn (): string => __("admin::panels.nav.{$key}"))
                ->collapsible();
        }

        return $groups;
    }
}
