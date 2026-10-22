<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Tenancy\Models\Shop;

/**
 * What the shop says about itself: its name, whether it is a store, a content site or both, its languages
 * (the first is the main one, the language its content is read in) and what it sells, in its own words.
 * Read and saved the same way wherever the manager edits it (Settings · General, the first-run screens).
 */
final class SaveShopProfile
{
    public const KINDS = ['store', 'content', 'both'];

    /** The languages a store can add, in their own names. */
    public const LANGUAGES = ['he' => 'עברית', 'en' => 'English', 'ar' => 'العربية', 'ru' => 'Русский', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch'];

    private const MAX_SELLS = 12;

    /** @return array{name: string, kind: string, languages: list<string>, sells: list<string>} the form, as the shop has it */
    public function load(Shop $shop): array
    {
        $profile = (array) ($shop->profile ?? []);

        return [
            'name' => (string) $shop->name,
            'kind' => in_array($profile['kind'] ?? null, self::KINDS, true) ? $profile['kind'] : 'store',
            'languages' => array_values(array_unique([(string) $shop->content_locale, ...array_map('strval', (array) ($profile['languages'] ?? []))])),
            'sells' => array_values(array_map('strval', (array) ($profile['sells'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string> field => what is wrong with it
     */
    public function problems(array $data): array
    {
        return mb_strlen(trim((string) ($data['name'] ?? ''))) < 2 ? ['name' => __('admin::shop_settings.general.name_short')] : [];
    }

    /** @param  array<string, mixed>  $data  checked first (problems()) */
    public function handle(Shop $shop, array $data): void
    {
        $languages = array_values(array_unique(array_filter((array) ($data['languages'] ?? []), fn ($l): bool => is_string($l) && isset(self::LANGUAGES[$l]))));
        $languages = $languages === [] ? [(string) $shop->content_locale] : $languages;
        $sells = array_values(array_unique(array_filter(array_map(fn ($s): string => mb_substr((string) preg_replace('/^\s+|\s+$/u', '', (string) $s), 0, 60), (array) ($data['sells'] ?? [])), fn (string $s): bool => $s !== '')));

        $shop->forceFill([
            'name' => mb_substr(trim((string) $data['name']), 0, 120),
            'content_locale' => $languages[0],
            // anything else the shop keeps there stays
            'profile' => [
                ...(array) ($shop->profile ?? []),
                'kind' => in_array($data['kind'] ?? null, self::KINDS, true) ? $data['kind'] : 'store',
                'languages' => array_slice($languages, 1),
                'sells' => array_slice($sells, 0, self::MAX_SELLS),
            ],
        ])->save();
    }
}
