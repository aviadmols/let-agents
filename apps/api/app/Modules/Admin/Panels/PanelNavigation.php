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
    public const OPERATOR = ['overview', 'shops', 'catalog', 'scan', 'discovery', 'shoppers', 'try_on', 'analytics', 'system'];

    /** Merchant panel, under the loose screens at the top (home, analytics): what a shop owner does, in order. */
    public const MERCHANT = ['search', 'shoppers', 'on_page', 'settings'];

    /**
     * @param  list<string>  $keys
     * @return array<string, NavigationGroup>
     */
    public static function groups(array $keys, bool $foldable = false): array
    {
        $groups = [];

        foreach ($keys as $key) {
            // A shop manager's few groups are plain headings that stay open; the operator's many fold,
            // and Filament remembers which the operator folded.
            $groups[$key] = NavigationGroup::make(fn (): string => __("admin::panels.nav.{$key}"))
                ->collapsible($foldable);
        }

        return $groups;
    }
}
