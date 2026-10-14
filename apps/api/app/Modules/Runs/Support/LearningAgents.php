<?php

namespace App\Modules\Runs\Support;

use App\Core\Modules\ModuleRepository;
use Illuminate\Support\Facades\Lang;

/**
 * The agents whose work is the system's learning and checking, kept forever with every model call
 * they made (prompt, what was sent, what came back, the checker's verdict): read from the agents each
 * module declares in its module.json. An agent that does not answer shoppers live (it runs at night,
 * weekly, on request or by hand: the analyst, the reviewers, the auditors, the readers) is one; a
 * module can say so of any agent with "keep": true, or keep one out with "keep": false.
 *
 * A live agent (answering a shopper now) keeps its prompts runs.call_log_days; what it answered and
 * what its checker decided stay in its own tables.
 */
final class LearningAgents
{
    /** @var list<array{key: string, slug: string, name: string, action: string, when: string, roles: list<string>}>|null */
    private static ?array $agents = null;

    /** @return list<array{key: string, slug: string, name: string, action: string, when: string, roles: list<string>}> */
    public static function all(): array
    {
        if (self::$agents !== null) {
            return self::$agents;
        }

        $out = [];

        foreach (app(ModuleRepository::class)->enabled() as $module) {
            $data = json_decode((string) @file_get_contents($module->path('module.json')), true);

            foreach ((array) ($data['agents'] ?? []) as $agent) {
                $when = (string) ($agent['when'] ?? 'live');
                $keep = $agent['keep'] ?? ($when !== 'live');

                if ($keep !== true) {
                    continue;
                }

                $out[] = [
                    'key' => $module->slug.'.'.$agent['name'],
                    'slug' => $module->slug,
                    'name' => (string) $agent['name'],
                    'action' => $module->slug.'.'.$agent['action'],
                    'when' => $when,
                    'roles' => array_values(array_map(fn (array $role): string => (string) $role['role'], (array) ($agent['roles'] ?? []))),
                ];
            }
        }

        return self::$agents = $out;
    }

    /** The actions whose runs and model calls are never pruned. @return list<string> */
    public static function actions(): array
    {
        return array_values(array_unique(array_column(self::all(), 'action')));
    }

    public static function about(array $agent): ?string
    {
        $key = $agent['slug'].'::agents_about.'.$agent['name'];

        return Lang::has($key) ? (string) __($key) : null;
    }

    /** For tests: the registry read again. */
    public static function forget(): void
    {
        self::$agents = null;
    }
}
