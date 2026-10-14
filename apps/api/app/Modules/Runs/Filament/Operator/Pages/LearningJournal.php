<?php

namespace App\Modules\Runs\Filament\Operator\Pages;

use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use App\Modules\Runs\Support\LearningAgents;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What the system learned, in one place: every agent that learns or checks (LearningAgents), what it
 * does, how often it ran and what it found last; and the timeline of their findings, newest first,
 * each opening its prompts and replies (ProcessLog). All of it is kept forever. The operator's only.
 */
final class LearningJournal extends Page
{
    public const LATEST = 60;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'learning';

    protected string $view = 'runs::operator.learning';

    /** One agent's findings only, or every learning agent's. */
    #[Url]
    public string $agent = '';

    public static function getNavigationLabel(): string
    {
        return __('runs::learning.title');
    }

    public function getTitle(): string
    {
        return __('runs::learning.title');
    }

    public function getSubheading(): ?string
    {
        return __('runs::learning.subheading');
    }

    public function focus(string $key): void
    {
        $this->agent = $this->agent === $key ? '' : $key;
    }

    /**
     * Every learning agent with its numbers: runs, failed ones, model calls kept, the last run.
     *
     * @return list<array<string, mixed>>
     */
    public function agents(): array
    {
        $agents = LearningAgents::all();
        $actions = array_column($agents, 'action');
        $runs = Run::query()->whereIn('action', $actions)
            ->selectRaw('action, count(*) as runs, max(started_at) as last')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [RunStatus::Failed->value])
            ->groupBy('action')->get()->keyBy('action');
        $calls = RunCall::query()->whereIn('action', $actions)->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');

        return array_map(function (array $agent) use ($runs, $calls): array {
            $seen = $runs->get($agent['action']);
            $last = $seen === null ? null : Run::query()->where('action', $agent['action'])->latest('started_at')->first();

            return $agent + [
                'label' => Run::labelFor('agents', $agent['key']),
                'about' => LearningAgents::about($agent),
                'runs' => (int) ($seen->runs ?? 0),
                'failed' => (int) ($seen->failed ?? 0),
                'calls' => (int) ($calls[$agent['action']] ?? 0),
                'last' => $last,
            ];
        }, $agents);
    }

    /** The newest findings, of every learning agent or the one chosen. @return Collection<int, Run> */
    public function findings(): Collection
    {
        $actions = $this->agent === ''
            ? LearningAgents::actions()
            : array_column(array_filter(LearningAgents::all(), fn (array $a): bool => $a['key'] === $this->agent), 'action');

        return Run::query()->with('shop:id,name')->withCount('calls')
            ->whereIn('action', $actions)
            ->latest('started_at')->limit(self::LATEST)->get();
    }
}
