<?php

namespace App\Modules\Admin\Panels;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The shop's sidebar as the designer's panel lays it out: one flat list of screens (home, analytics,
 * sessions, timeline, notices, knowledge centre, scan summary, settings). A screen with several pages
 * is one item that opens its first page; the others are its tabs (MerchantSections), or, in settings,
 * its side menu. A module's page joins a screen by naming its key in `$navigationGroup` and its place
 * by `$navigationSort`, so no module imports another.
 */
final class MerchantNavigation
{
    /** The screens, top to bottom, after the home. A screen with no page yet is left out. */
    public const SECTIONS = ['analytics', 'sessions', 'timeline', 'inbox', 'knowledge', 'scan', 'settings'];

    /**
     * Screens the redesign (11.2026) takes off the sidebar, their pages still open by their address and keep their tabs:
     * analytics, which the Searches screen holds now, the timeline, which is the operator's (the system's log), and the
     * visits log, which the Shoppers screen tells as stories and links to ("Every visit"), the knowledge centre, which is
     * Store knowledge now (its word families still open by their address), the notices and insights, which Improvements,
     * Shoppers and Searches hold now, and the scan summary, which Store knowledge shows and opens ("Something wrong?").
     */
    public const OFF_SIDEBAR = ['analytics', 'timeline', 'sessions', 'knowledge', 'inbox', 'scan'];

    /** Shown as a side menu instead of tabs, like Shopify's settings. */
    public const SIDE = ['settings'];

    /**
     * Side-menu groups the redesign (11.2026) moved to What shoppers see: their pages keep their address and open from
     * its "More options" and "Change", but the settings menu is the store, its people and the account.
     */
    public const SIDE_ELSEWHERE = ['shoppers', 'team'];

    /** Each screen's icon in the sidebar (the redesign, 11.2026: thin strokes, round caps). */
    public const ICONS = [
        'analytics' => Heroicon::OutlinedMagnifyingGlass,
        'sessions' => Heroicon::OutlinedUsers,
        'timeline' => Heroicon::OutlinedClock,
        'inbox' => Heroicon::OutlinedSparkles,
        'knowledge' => Heroicon::OutlinedBookOpen,
        'scan' => Heroicon::OutlinedPhoto,
        'settings' => Heroicon::OutlinedCog6Tooth,
    ];

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $items = [];
        $sort = 0;

        foreach (self::loose() as $page) {
            $items[] = self::item($page, [$page], $page::getNavigationLabel(), ++$sort, $page::getNavigationIcon());
        }

        foreach (array_diff(self::SECTIONS, self::OFF_SIDEBAR) as $key) {
            $pages = self::pages($key);

            if ($pages !== []) {
                // a screen opens on its first page, or the one that says it opens the screen (settings: the plan)
                $first = collect($pages)->first(fn (string $page): bool => property_exists($page, 'opensSection') && $page::$opensSection === true) ?? $pages[0];
                $items[] = self::item($first, $pages, __("admin::panels.screens.{$key}"), ++$sort, self::ICONS[$key] ?? null);
            }
        }

        return $builder->items($items);
    }

    /**
     * The pages of one screen, in their order, that this person may open.
     *
     * @return list<class-string<Page>>
     */
    public static function pages(?string $key): array
    {
        if ($key === null) {
            return [];
        }

        return array_values(array_filter(self::visible(), fn (string $page): bool => $page::getNavigationGroup() === $key));
    }

    /** The screen a page belongs to, when it is one of several. */
    public static function sectionOf(string $page): ?string
    {
        $key = is_subclass_of($page, Page::class) ? $page::getNavigationGroup() : null;

        return is_string($key) && in_array($key, self::SECTIONS, true) ? $key : null;
    }

    /** @return list<class-string<Page>> pages outside every screen: the home */
    private static function loose(): array
    {
        return array_values(array_filter(self::visible(), fn (string $page): bool => $page::getNavigationGroup() === null));
    }

    /** @return list<class-string<Page>> */
    private static function visible(): array
    {
        $pages = array_values(array_filter(
            Filament::getCurrentPanel()?->getPages() ?? [],
            fn (string $page): bool => is_subclass_of($page, Page::class) && $page::shouldRegisterNavigation() && $page::canAccess(),
        ));
        usort($pages, fn (string $a, string $b): int => [$a::getNavigationSort() ?? 0, $a] <=> [$b::getNavigationSort() ?? 0, $b]);

        return $pages;
    }

    /** @param list<class-string<Page>> $pages */
    private static function item(string $first, array $pages, string $label, int $sort, string|BackedEnum|null $icon = null): NavigationItem
    {
        $badge = null;
        foreach ($pages as $page) {
            $badge ??= $page::getNavigationBadge();
        }

        return NavigationItem::make($label)
            ->url(fn (): string => $first::getUrl())
            ->isActiveWhen(fn (): bool => collect($pages)->contains(fn (string $page): bool => request()->routeIs($page::getNavigationItemActiveRoutePattern())))
            ->badge($badge)
            ->icon($icon)
            ->sort($sort);
    }

    /**
     * The tabs or side menu of the screen the current page belongs to, under its heading.
     *
     * @param  list<string>  $scopes  the page being drawn (Filament's render-hook scopes)
     */
    public static function section(array $scopes): HtmlString
    {
        $current = collect($scopes)->first(fn (string $scope): bool => is_subclass_of($scope, Page::class));
        $key = $current === null ? null : self::sectionOf($current);
        $pages = array_values(array_filter(self::pages($key), fn (string $page): bool => ! (property_exists($page, 'sideGroup') && in_array($page::$sideGroup, self::SIDE_ELSEWHERE, true))));

        if ($key === null) {
            return new HtmlString('');
        }

        // The screen's hint, unless the page names its own, or none ($sectionHint): "approve here" only where something is approved.
        $hint = property_exists($current, 'sectionHint') ? $current::$sectionHint : $key;

        return new HtmlString(view('admin::panel.merchant-section', [
            'key' => $key,
            'hint' => $hint === null ? null : __("admin::panels.hints.{$hint}"),
            'hintKey' => $hint,
            'tabs' => count($pages) > 1,
            'side' => in_array($key, self::SIDE, true),
            'label' => __("admin::panels.screens.{$key}"),
            'pages' => array_map(fn (string $page): array => [
                'label' => $page::getNavigationLabel(),
                'url' => $page::getUrl(),
                'badge' => $page::getNavigationBadge(),
                // a page its own item, or the one it extends when it has none (Plan and billing extends the connection's)
                'current' => $page === $current || (is_subclass_of($current, $page) && ! in_array($current, $pages, true)),
                // In a side menu, the group a page stands under ("the store", "what shoppers see"), when it says one.
                'group' => property_exists($page, 'sideGroup') ? __("admin::panels.side_groups.{$page::$sideGroup}") : null,
            ], $pages),
        ])->render());
    }
}
