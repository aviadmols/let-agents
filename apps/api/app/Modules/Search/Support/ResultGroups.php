<?php

namespace App\Modules\Search\Support;

/**
 * Which groups of results a shop shows, and in what order: products, categories, guides. Saved as
 * one setting, "product,category,content"; a group left out of it is not shown.
 */
final class ResultGroups
{
    public const ALL = ['product', 'category', 'content'];

    /** @return list<string> the shop's groups in its order; every group when nothing valid is saved */
    public static function parse(?string $value): array
    {
        $groups = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $value)),
            fn (string $group): bool => in_array($group, self::ALL, true),
        )));

        return $groups === [] ? self::ALL : $groups;
    }

    /** @param  list<string>  $groups */
    public static function compose(array $groups): string
    {
        return implode(',', self::parse(implode(',', $groups)));
    }
}
