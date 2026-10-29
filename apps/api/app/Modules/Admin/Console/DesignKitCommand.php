<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The kit a designer works from (Aviad, 29.10): the merchant panel's stylesheet to change, every screen's Blade view
 * as it is now, the shared pieces they use, and SCREENS.md, read from the code itself: each screen's address, its
 * view, the actions its buttons call and the fields its forms bind. With the brief (README.md) and the contract for
 * the designer's own Claude (CLAUDE.md), from resources/design-kit. The designer opens the shop's panel with a link
 * of a day (DesignLinks) and loads this stylesheet from their computer instead of the panel's.
 */
final class DesignKitCommand extends Command
{
    protected $signature = 'design:kit {--out= : the folder the kit is written to}';

    protected $description = 'Write the kit a designer works on the merchant panel from';

    public function handle(): int
    {
        $out = rtrim((string) ($this->option('out') ?: storage_path('app/design-kit')), '/\\');
        File::deleteDirectory($out);
        File::ensureDirectoryExists($out.'/views');

        $admin = dirname(__DIR__);
        File::copy($admin.'/resources/css/merchant.css', $out.'/merchant.css');
        File::ensureDirectoryExists($out.'/reference');
        File::copy($admin.'/resources/css/panel.css', $out.'/reference/panel.css');
        File::copyDirectory($admin.'/resources/design-kit', $out);
        // the shared pieces every screen is built of: the shell, the top bar, the cards and fields
        File::copyDirectory($admin.'/resources/views/panel', $out.'/views/admin/panel');
        File::copyDirectory($admin.'/resources/views/components', $out.'/views/admin/components');

        $screens = [];
        foreach (Filament::getPanel(User::MERCHANT_PANEL)->getPages() as $class) {
            $screen = $this->screen($class);
            if ($screen === null) {
                continue;
            }
            if ($screen['view_file'] !== null) {
                $target = $out.'/views/'.$screen['view_copy'];
                File::ensureDirectoryExists(dirname($target));
                File::copy($screen['view_file'], $target);
            }
            $screens[] = $screen;
        }
        usort($screens, fn (array $a, array $b): int => [$a['group'], $a['slug']] <=> [$b['group'], $b['slug']]);

        File::put($out.'/SCREENS.md', $this->screensMd($screens));
        $this->info('kit: '.$out.' ('.count($screens).' screens)');

        return self::SUCCESS;
    }

    /**
     * One screen, read from its class and its view.
     *
     * @param  class-string  $class
     * @return array<string, mixed>|null
     */
    private function screen(string $class): ?array
    {
        if (! is_subclass_of($class, Page::class)) {
            return null;
        }
        $ref = new ReflectionClass($class);
        $defaults = $ref->getDefaultProperties();
        $view = is_string($defaults['view'] ?? null) ? $defaults['view'] : null;
        $file = null;
        try {
            $file = $view === null ? null : view()->getFinder()->find($view);
        } catch (\Throwable) {
            $file = null;
        }
        $module = preg_match('/^App\\\\Modules\\\\(\w+)\\\\/', $class, $m) === 1 ? $m[1] : 'App';
        $copy = $view === null ? null : str_replace(['::', '.'], ['/', '/'], $view).'.blade.php';
        $html = $file !== null ? (string) file_get_contents($file) : '';

        // the actions its buttons call: its own public methods, and those of the pages it extends in the modules
        $actions = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $owner = $method->getDeclaringClass()->getName();
            if ($method->isStatic() || $method->isConstructor() || ! str_starts_with($owner, 'App\\Modules\\') || str_starts_with($method->getName(), '__')
                || in_array($method->getName(), ['getTitle', 'getSubheading', 'getHeading', 'mount', 'boot', 'render', 'getViewData'], true)) {
                continue;
            }
            $params = array_map(function (\ReflectionParameter $p): string {
                $type = $p->getType();

                return ($type instanceof ReflectionNamedType ? $type->getName().' ' : '').'$'.$p->getName();
            }, $method->getParameters());
            $doc = (string) $method->getDocComment();
            $about = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/^\s*\/?\*+\/?|\*\/$|@\w+.*$/m', '', $doc)));
            $name = preg_quote($method->getName(), '/');
            // pressed: a wire: attribute or Alpine's $wire calls it; read: the view calls it for what it shows
            $pressed = preg_match('/wire:[a-z.]+="\s*'.$name.'\b|\$wire\.'.$name.'\(/', $html) === 1;
            $read = ! $pressed && preg_match('/\$this->'.$name.'\(/', $html) === 1;
            $actions[] = ['name' => $method->getName(), 'params' => $params, 'about' => mb_substr($about, 0, 240), 'pressed' => $pressed, 'read' => $read];
        }
        // what its forms bind, and the hooks tests and scripts find it by
        preg_match_all('/wire:model(?:\.[\w.]+)?="([^"]+)"/', $html, $models);
        preg_match_all('/\b(data-[a-z][a-z0-9-]*)/', $html, $hooks);
        preg_match_all('/wire:poll(?:\.[\w.]+)?/', $html, $polls);

        return [
            'class' => $class,
            'module' => $module,
            'group' => (string) ($defaults['navigationGroup'] ?? ''),
            'slug' => (string) ($defaults['slug'] ?? '') ?: Str::kebab(class_basename($class)),
            'view' => $view,
            'view_file' => $file,
            'view_copy' => $copy,
            'actions' => $actions,
            'models' => array_values(array_unique($models[1])),
            'hooks' => array_values(array_unique($hooks[1])),
            'polls' => array_values(array_unique($polls[0])),
        ];
    }

    /** @param  list<array<string, mixed>>  $screens */
    private function screensMd(array $screens): string
    {
        $md = "# Screens of the merchant panel\n\nRead from the code by `php artisan design:kit`. Each screen: its address in the panel "
            .'(`/merchant/{shop}/<slug>`), its Blade view (copied under `views/`), the server actions its buttons call (Livewire '
            .'methods: keep every `wire:click`, `wire:submit` and its arguments), the fields its forms bind (`wire:model`: keep the '
            ."name), and the `data-*` hooks the tests and scripts find it by (keep them).\n\n";
        foreach ($screens as $s) {
            $md .= '## '.$s['slug']."\n\n";
            $md .= '- Address: `/merchant/{shop}/'.$s['slug']."`\n";
            $md .= '- Module: '.$s['module'].' · Class: `'.$s['class']."`\n";
            $md .= '- View: '.($s['view'] !== null ? '`'.$s['view'].'` → `views/'.$s['view_copy'].'`' : 'Filament\'s own form (settings fields, no view of its own)')."\n";
            if ($s['polls'] !== []) {
                $md .= '- Refreshes itself: `'.implode('`, `', $s['polls'])."`\n";
            }
            if ($s['models'] !== []) {
                $md .= '- Fields bound (wire:model): `'.implode('`, `', $s['models'])."`\n";
            }
            if ($s['hooks'] !== []) {
                $md .= '- Hooks (data-*): `'.implode('`, `', $s['hooks'])."`\n";
            }
            $line = fn (array $a): string => '  - `'.$a['name'].'('.implode(', ', $a['params']).')`'.($a['about'] !== '' ? ' — '.$a['about'] : '')."\n";
            $pressed = array_values(array_filter($s['actions'], fn (array $a): bool => $a['pressed']));
            if ($pressed !== []) {
                $md .= "- Actions (buttons and forms call these on the server; keep each call and its arguments):\n".implode('', array_map($line, $pressed));
            }
            $read = array_values(array_filter($s['actions'], fn (array $a): bool => $a['read']));
            if ($read !== []) {
                $md .= "- Data the view reads (`\$this->…()`; the content to lay out, never to change):\n".implode('', array_map($line, $read));
            }
            $md .= "\n";
        }

        return $md;
    }
}
