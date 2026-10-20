<?php

namespace App\Modules\Runs\Support;

use App\Core\Modules\ModuleRepository;

/**
 * The agents that answer a shopper now ("when": "live" in their module.json: the chat's answerers,
 * the sets of products, a search by photo, a query's vector). Every other agent is work in the
 * background: reading the catalog, tagging, learning, the day's conclusions. Read from the modules,
 * so a new agent falls on its side by what it declares.
 */
final class LiveAgents
{
    /** @var list<string>|null */
    private static ?array $keys = null;

    /** "assistant.site_answerer", as the ledger names an agent. @return list<string> */
    public static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $out = [];

        foreach (app(ModuleRepository::class)->enabled() as $module) {
            $data = json_decode((string) @file_get_contents($module->path('module.json')), true);

            foreach ((array) ($data['agents'] ?? []) as $agent) {
                if ((string) ($agent['when'] ?? 'live') === 'live') {
                    $out[] = $module->slug.'.'.$agent['name'];
                }
            }
        }

        return self::$keys = array_values(array_unique($out));
    }

    public static function is(?string $agent): bool
    {
        return in_array((string) $agent, self::keys(), true);
    }

    /** For tests: the modules read again. */
    public static function forget(): void
    {
        self::$keys = null;
    }
}
