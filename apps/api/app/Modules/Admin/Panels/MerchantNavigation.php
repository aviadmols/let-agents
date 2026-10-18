<?php

namespace App\Modules\Admin\Panels;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
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

    /** Shown as a side menu instead of tabs, like Shopify's settings. */
    public const SIDE = ['settings'];

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $items = [];
        $sort = 0;

        foreach (self::loose() as $page) {
            $items[] = self::item($page, [$page], $page::getNavigationLabel(), ++$sort);
        }

        foreach (self::SECTIONS as $key) {
            $pages = self::pages($key);

            if ($pages !== []) {
                $items[] = self::item($pages[0], $pages, __("admin::panels.screens.{$key}"), ++$sort);
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
    private static function item(string $first, array $pages, string $label, int $sort): NavigationItem
    {
        $badge = null;
        foreach ($pages as $page) {
            $badge ??= $page::getNavigationBadge();
        }

        return NavigationItem::make($label)
            ->url(fn (): string => $first::getUrl())
            ->isActiveWhen(fn (): bool => collect($pages)->contains(fn (string $page): bool => request()->routeIs($page::getNavigationItemActiveRoutePattern())))
            ->badge($badge)
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
        $pages = self::pages($key);

        if ($key === null || count($pages) < 2) {
            return new HtmlString('');
        }

        return new HtmlString(view('admin::panel.merchant-section', [
            'key' => $key,
            'side' => in_array($key, self::SIDE, true),
            'label' => __("admin::panels.screens.{$key}"),
            'pages' => array_map(fn (string $page): array => [
                'label' => $page::getNavigationLabel(),
                'url' => $page::getUrl(),
                'badge' => $page::getNavigationBadge(),
                'current' => $page === $current || is_subclass_of($current, $page),
            ], $pages),
        ])->render());
    }
}
