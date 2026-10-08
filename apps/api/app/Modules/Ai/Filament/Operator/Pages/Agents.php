<?php

namespace App\Modules\Ai\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Modules\Admin\Contracts\ChosenShop;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\AgentCatalog;
use App\Modules\Runs\Models\Run;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Every agent that calls a model, in one place: what it does and when, the switch that turns it
 * on, which provider and model each of its roles calls and at what price, its prompts with every
 * released version, and what it did this week. A writer and a checker from the same family are
 * flagged here, before a run fails on it. Prompts are read-only: a prompt changes through a new
 * versioned file, so every run can say exactly what it sent.
 */
final class Agents extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'agents';

    protected string $view = 'ai::operator.agents';

    /** @var array<string, string|float> setting key => what the form holds */
    public array $values = [];

    public static function getNavigationLabel(): string
    {
        return __('ai::agents_screen.title');
    }

    public function getTitle(): string
    {
        return __('ai::agents_screen.title');
    }

    public function getSubheading(): ?string
    {
        return __('ai::agents_screen.subheading');
    }

    public function mount(): void
    {
        foreach (AgentCatalog::all() as $agent) {
            foreach ($agent['roles'] as $role) {
                foreach ([$role['provider'], $role['model'], ...$role['prices']] as $key) {
                    $this->values[self::field($key)] = Settings::get($key);
                }
            }
        }
    }

    /** Livewire field names cannot hold dots. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return list<array<string, mixed>> */
    public function agents(): array
    {
        $week = $this->week();

        return array_map(function (array $agent) use ($week): array {
            $families = [];

            foreach ($agent['roles'] as $role) {
                $provider = AiProviderName::tryFrom((string) ($this->values[self::field($role['provider'])] ?? ''));
                $families[] = $provider?->family();
            }

            return $agent + [
                'on' => $agent['features'] === [] ? null : array_map(fn (string $f): bool => Features::enabled($f), array_combine($agent['features'], $agent['features'])),
                'same_family' => count($agent['roles']) > 1 && count(array_unique($families)) < count($families),
                'week' => $week[$agent['key']] ?? ['runs' => 0, 'failed' => 0, 'cost' => 0.0],
            ];
        }, AgentCatalog::all());
    }

    /** @return array<string, list<string>> provider => the models its key can call, from the last check */
    public function models(): array
    {
        $out = [];

        foreach (AiProvider::query()->get() as $provider) {
            $out[$provider->provider->value] = array_values(array_map(fn (array $m): string => (string) $m['id'], (array) $provider->models));
        }

        return $out;
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_map(fn (AiProviderName $p): string => $p->value, AiProviderName::cases());
    }

    public function promptText(string $path, int $version): string
    {
        return AgentCatalog::text($path, $version);
    }

    public function save(string $agentKey): void
    {
        $agent = collect(AgentCatalog::all())->firstWhere('key', $agentKey);

        if ($agent === null) {
            return;
        }

        try {
            DB::transaction(function () use ($agent): void {
                foreach ($agent['roles'] as $role) {
                    foreach ([$role['provider'], $role['model'], ...$role['prices']] as $key) {
                        $value = $this->values[self::field($key)] ?? null;

                        if ($value !== null && $value !== '' && (string) $value !== (string) Settings::get($key)) {
                            Settings::set($key, in_array($key, $role['prices'], true) ? (float) $value : trim((string) $value));
                        }
                    }
                }
            });
        } catch (InvalidSettingValue $e) {
            Notification::make()->danger()->title(__('ai::agents_screen.invalid'))->body($e->translated())->send();

            return;
        }

        Notification::make()->success()->title(__('ai::agents_screen.saved'))->send();
    }

    /** The shop chosen in the top bar, which "run now" runs for; null while every shop is shown. */
    /** @return array{id: string, slug: string, name: string}|null */
    public function chosenShop(): ?array
    {
        return app(ChosenShop::class)->get();
    }

    /**
     * Runs one agent now for the chosen shop instead of waiting for its hour: its own command,
     * on the queue, so a long run (hundreds of pictures) never holds the screen.
     */
    public function runNow(string $agentKey): void
    {
        $agent = collect(AgentCatalog::all())->firstWhere('key', $agentKey);
        $shop = $this->chosenShop();

        if ($agent === null || ($agent['run'] ?? null) === null) {
            return;
        }

        if ($shop === null) {
            Notification::make()->warning()->title(__('ai::agents_screen.choose_shop'))->send();

            return;
        }

        $run = $agent['run'];
        $arguments = isset($run['step']) ? ['step' => $run['step']] : [];
        $arguments[$run['shop_argument'] ?? 'target'] = $shop['slug'];
        Artisan::queue((string) $run['command'], $arguments);

        Notification::make()->success()->title(__('ai::agents_screen.run_started', ['shop' => $shop['name']]))->body(__('ai::agents_screen.run_started_body'))->send();
    }

    /** On or off for every shop; a shop can still be set apart in its own settings. */
    public function toggle(string $feature, bool $on): void
    {
        $known = collect(AgentCatalog::all())->pluck('features')->flatten()->all();

        abort_unless(in_array($feature, $known, true), 404);

        Features::override($feature, $on);
        Notification::make()->success()->title(__('ai::agents_screen.'.($on ? 'turned_on' : 'turned_off')))->send();
    }

    /** @return array<string, array{runs: int, failed: int, cost: float}> */
    private function week(): array
    {
        $rows = Run::query()
            ->where('started_at', '>=', now()->subDays(7))
            ->select('agent')
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost')
            ->groupBy('agent')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->agent] = ['runs' => (int) $row->runs, 'failed' => (int) $row->failed, 'cost' => round((float) $row->cost, 4)];
        }

        return $out;
    }
}
