<?php

namespace App\Modules\Ai\Support;

use App\Core\Modules\ModuleRepository;

/**
 * Every agent that calls a model, as each enabled module declares it under "agents" in its
 * module.json: its runs, when it works, the flags that turn it on, and each role's provider,
 * model, prices and prompts (with every released version). A new agent is a few lines there.
 */
final class AgentCatalog
{
    /**
     * @return list<array{
     *     key: string, module: string, slug: string, name: string, action: string, when: string,
     *     features: list<string>,
     *     roles: list<array{role: string, provider: string, model: string, prices: list<string>,
     *         prompts: list<array{name: string, versions: list<int>, latest: int, path: string}>}>
     * }>
     */
    public static function all(): array
    {
        $out = [];

        foreach (app(ModuleRepository::class)->enabled() as $module) {
            $data = json_decode((string) file_get_contents($module->path('module.json')), true);

            foreach ((array) ($data['agents'] ?? []) as $agent) {
                $slug = $module->slug;
                $out[] = [
                    'key' => $slug.'.'.$agent['name'],
                    'module' => $module->name,
                    'slug' => $slug,
                    'name' => (string) $agent['name'],
                    'action' => $slug.'.'.$agent['action'],
                    'when' => (string) ($agent['when'] ?? 'live'),
                    // The command that runs it for one shop now, when it has one.
                    'run' => is_array($agent['run'] ?? null) ? $agent['run'] : null,
                    'features' => array_map(fn (string $f): string => $slug.'.'.$f, (array) ($agent['features'] ?? [])),
                    'roles' => array_map(fn (array $role): array => [
                        'role' => (string) $role['role'],
                        'provider' => $slug.'.'.$role['provider'],
                        'model' => $slug.'.'.$role['model'],
                        'prices' => array_map(fn (string $p): string => $slug.'.'.$p, (array) ($role['prices'] ?? [])),
                        'prompts' => array_values(array_filter(array_map(
                            fn (string $name): ?array => self::prompt($module->path('Prompts'), $name),
                            (array) ($role['prompts'] ?? []),
                        ))),
                    ], (array) ($agent['roles'] ?? [])),
                ];
            }
        }

        return $out;
    }

    /** @return array{name: string, versions: list<int>, latest: int, path: string}|null */
    private static function prompt(string $directory, string $name): ?array
    {
        $versions = [];

        foreach (glob($directory.DIRECTORY_SEPARATOR.$name.'.v*.md') ?: [] as $file) {
            if (preg_match('/\.v(\d+)\.md$/', $file, $m) === 1) {
                $versions[] = (int) $m[1];
            }
        }

        if ($versions === []) {
            return null;
        }

        sort($versions);
        $latest = end($versions);

        return ['name' => $name, 'versions' => $versions, 'latest' => $latest, 'path' => $directory.DIRECTORY_SEPARATOR.$name];
    }

    /** One released version of a prompt, exactly as it is sent. */
    public static function text(string $path, int $version): string
    {
        $file = $path.'.v'.$version.'.md';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
