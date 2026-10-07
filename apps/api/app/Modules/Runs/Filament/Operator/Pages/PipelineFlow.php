<?php

namespace App\Modules\Runs\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Support\Pipeline;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What runs on this platform, in the order it runs, and which model each step uses.
 *
 * The activity log answers "what happened"; this answers "what is the machine". Every step the
 * platform has is drawn whether or not it has ever run, so a stage nobody set up is visible by
 * being empty rather than by being absent. Each step carries when it last ran, how long it took,
 * how often it has run lately and what it cost, and says plainly whether a model is involved —
 * most of them are code and cost nothing, which is the point worth being able to see.
 *
 * This is the whole platform, not one shop. Runs belong to shops, so the numbers are read with
 * the tenant scope deliberately lifted, and each run in the list names the shop it was for.
 */
final class PipelineFlow extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'pipeline';

    protected string $view = 'runs::operator.pipeline';

    /** How far back the counts and costs look. */
    #[Url]
    public int $days = 30;

    /** @var list<int> */
    public const WINDOWS = [7, 30, 90];

    public static function getNavigationLabel(): string
    {
        return __('runs::pipeline.title');
    }

    public function getTitle(): string
    {
        return __('runs::pipeline.title');
    }

    public function getSubheading(): ?string
    {
        return __('runs::pipeline.help');
    }

    public function setDays(int $days): void
    {
        $this->days = in_array($days, self::WINDOWS, true) ? $days : 30;
    }

    /**
     * Every stage with its steps, each step measured from the runs it actually produced.
     *
     * @return list<array{key: string, steps: list<array<string, mixed>>}>
     */
    public function stages(): array
    {
        $runs = $this->summaries();
        $stages = [];

        foreach (Pipeline::STAGES as $key => $actions) {
            $steps = [];

            foreach ($actions as $action => $step) {
                $seen = $runs->get($action);

                $steps[] = [
                    'action' => $action,
                    'label' => self::name($action),
                    'model' => $this->modelName($step, $seen['models'] ?? []),
                    'outside' => (bool) ($step['outside'] ?? false),
                    'clock' => Pipeline::CLOCK[$action] ?? null,
                    'runs' => (int) ($seen['runs'] ?? 0),
                    'failed' => (int) ($seen['failed'] ?? 0),
                    'last' => $seen['last'] ?? null,
                    'took' => isset($seen['ms']) ? $this->readableMs((int) $seen['ms']) : null,
                    'cost' => (float) ($seen['cost'] ?? 0),
                ];
            }

            $stages[] = ['key' => $key, 'steps' => $steps];
        }

        return $stages;
    }

    /** The last runs across every shop, newest first. @return list<array<string, mixed>> */
    public function recent(): array
    {
        return $this->unscoped(fn (): array => Run::query()
            ->with('shop:id,name')
            ->whereIn('action', Pipeline::actions())
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (Run $run): array => [
                'id' => $run->id,
                'label' => self::name((string) $run->action),
                'shop' => $run->shop?->name,
                'model' => $run->model,
                'status' => $run->status->value,
                'at' => $run->started_at,
                'took' => $run->duration_ms === null ? null : $this->readableMs($run->duration_ms),
                'cost' => (float) $run->cost_usd,
            ])
            ->all());
    }

    /** What the platform has spent this month against the cap that stops it. */
    public function spend(): array
    {
        $spent = $this->unscoped(fn (): float => (float) Run::query()
            ->where('started_at', '>=', now()->startOfMonth())
            ->sum('cost_usd'));

        $cap = (float) Settings::get('ai.monthly_spend_cap_usd');

        return [
            'spent' => $spent,
            'cap' => $cap,
            'share' => $cap > 0 ? min(100, (int) round($spent / $cap * 100)) : 0,
        ];
    }

    /** One row per action in the window: how many, how many failed, the last one, cost, models. */
    private function summaries(): Collection
    {
        return $this->unscoped(fn (): Collection => Run::query()
            ->selectRaw('action, count(*) as runs, max(started_at) as last, coalesce(sum(cost_usd), 0) as cost')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [RunStatus::Failed->value])
            ->selectRaw('max(duration_ms) as ms')
            ->where('started_at', '>=', now()->subDays($this->days))
            ->groupBy('action')
            ->get()
            ->keyBy('action')
            ->map(fn ($row): array => [
                'runs' => $row->runs,
                'failed' => $row->failed,
                'last' => $row->last,
                'cost' => $row->cost,
                'ms' => $row->ms,
                'models' => $this->modelsFor((string) $row->action),
            ]));
    }

    /** @return list<string> */
    private function modelsFor(string $action): array
    {
        return $this->unscoped(fn (): array => Run::query()
            ->where('action', $action)
            ->whereNotNull('model')
            ->where('started_at', '>=', now()->subDays($this->days))
            ->distinct()
            ->pluck('model')
            ->all());
    }

    /**
     * Which model a step uses: the one an operator chose, the ones the runs really used, or
     * nothing at all, which is most of them.
     *
     * @param  array{model: string|null, outside?: bool}  $step
     * @param  list<string>  $seen
     */
    private function modelName(array $step, array $seen): ?string
    {
        if ($seen !== []) {
            return implode(' · ', $seen);
        }

        if ($step['model'] === null) {
            return null;
        }

        return $step['model'] === Pipeline::FROM_SETTING
            ? __('runs::pipeline.model_of_the_run')
            : (string) Settings::get($step['model']);
    }

    private function readableMs(int $ms): string
    {
        return $ms < 1000
            ? __('runs::pipeline.ms', ['n' => $ms])
            : ($ms < 60000
                ? __('runs::pipeline.seconds', ['n' => round($ms / 1000, 1)])
                : __('runs::pipeline.minutes', ['n' => round($ms / 60000, 1)]));
    }

    /** @template T */
    private function unscoped(callable $read): mixed
    {
        return app(TenantContext::class)->runUnscoped($read);
    }

    /**
     * What an action is called, in words.
     *
     * An action is named 'enrichment.read_in_code', and a translation key cannot hold a dot:
     * __() reads each one as a step down into the array, finds nothing, and prints the
     * identifier. So the dots come out before the name is looked up, and an action nobody has
     * named yet shows as itself rather than as a missing key.
     */
    private static function name(string $action): string
    {
        $key = 'runs::pipeline.actions.'.str_replace('.', '_', $action);
        $name = __($key);

        return is_string($name) && $name !== $key ? $name : $action;
    }
}
