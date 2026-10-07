<?php

namespace App\Modules\Knowledge\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsPrior;
use App\Modules\Enrichment\Models\EnrichmentContentTemplate;
use App\Modules\Knowledge\Models\KnowledgeSnapshot;
use App\Modules\Knowledge\Models\LearningMeasurement;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * How the system gets better, what it learned this week, and whether any of it helped.
 *
 * This is the screen where the machinery is the subject, so it may be explained at length. Every
 * stage says what it does, why, whether it is running, and — when it is not — the exact condition
 * it is waiting for, so "not yet" is never a mystery.
 */
final class Learning extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'learning';

    protected string $view = 'knowledge::operator.learning';

    public static function getNavigationLabel(): string
    {
        return __('knowledge::learning.title');
    }

    public function getTitle(): string
    {
        return __('knowledge::learning.title');
    }

    public function getSubheading(): ?string
    {
        return __('knowledge::learning.help');
    }

    /**
     * The stages, each with whether it is running and what it waits for.
     *
     * @return list<array{key: string, state: string, detail: ?string}>
     */
    public function stages(): array
    {
        $shops = $this->shops();
        $withTrade = $shops->filter(fn (Shop $s): bool => $s->vertical !== null);
        $tradeSizes = $withTrade->groupBy(fn (Shop $s): string => $s->vertical->value)->map->count();
        $biggest = $tradeSizes->max() ?? 0;
        $promoteNeeds = (int) Settings::get('knowledge.promote_min_shops');
        $priorNeeds = (int) Settings::get('analytics.prior_min_shops');
        $measured = $this->measurements();

        return [
            $this->stage('reading', true, __('knowledge::learning.states.every_night')),
            $this->stage('model_reading', true, __('knowledge::learning.states.every_night')),
            $this->stage('audit', true, __('knowledge::learning.states.every_week')),
            $this->stage(
                'holdout',
                $shops->contains(fn (Shop $s): bool => (int) Settings::get('analytics.holdout_percent', $s->id) > 0),
                __('knowledge::learning.states.share', ['n' => (int) Settings::get('analytics.holdout_percent')]),
            ),
            $this->stage(
                'measuring',
                $measured->contains(fn (LearningMeasurement $m): bool => in_array($m->verdict, [LearningMeasurement::HELPED, LearningMeasurement::HURT, LearningMeasurement::NO_DIFFERENCE], true)),
                __('knowledge::learning.states.needs_traffic', ['n' => number_format((int) Settings::get('analytics.measure_min_exposures'))]),
            ),
            $this->stage(
                'promotion',
                $biggest >= $promoteNeeds && Features::enabled('knowledge.promote'),
                __('knowledge::learning.states.needs_shops', ['n' => $promoteNeeds, 'have' => $biggest]),
            ),
            $this->stage(
                'priors',
                AnalyticsPrior::query()->exists(),
                __('knowledge::learning.states.needs_shops', ['n' => $priorNeeds, 'have' => $biggest]),
            ),
            $this->stage('questions', false, __('knowledge::learning.states.planned')),
            $this->stage('session', false, __('knowledge::learning.states.planned')),
        ];
    }

    /** @return Collection<int, Shop> */
    public function shops()
    {
        return app(TenantContext::class)->runUnscoped(fn () => Shop::query()->orderBy('slug')->get());
    }

    /** @return Collection<int, LearningMeasurement> */
    public function measurements()
    {
        return app(TenantContext::class)->runUnscoped(fn () => LearningMeasurement::query()
            ->latest('taken_on')
            ->limit(20)
            ->get());
    }

    /**
     * What changed in each shop's knowledge over the week, from the snapshots.
     *
     * @return list<array<string, mixed>>
     */
    public function thisWeek(): array
    {
        $weeks = [];

        foreach ($this->shops() as $shop) {
            [$now, $before] = app(TenantContext::class)->runUnscoped(fn (): array => KnowledgeSnapshot::latestAnd($shop->id, 6));

            if ($now === null) {
                continue;
            }

            $weeks[] = [
                'shop' => $shop,
                'known_share' => (int) ($now->coverage['known_share'] ?? 0),
                'moved' => $before === null ? null : (int) ($now->coverage['known_share'] ?? 0) - (int) ($before->coverage['known_share'] ?? 0),
                'products' => (int) ($now->coverage['catalog']['products'] ?? 0),
                'gained' => $before === null ? null : [
                    'read_in_code' => (int) ($now->coverage['read_in_code']['products'] ?? 0) - (int) ($before->coverage['read_in_code']['products'] ?? 0),
                    'read_by_model' => (int) ($now->coverage['read_by_model']['products'] ?? 0) - (int) ($before->coverage['read_by_model']['products'] ?? 0),
                    'articles' => (int) ($now->coverage['articles_with_points']['articles'] ?? 0) - (int) ($before->coverage['articles_with_points']['articles'] ?? 0),
                ],
                'optimising_for' => (string) ($now->signals['optimising_for'] ?? 'none'),
                'gaps' => count($now->gaps),
            ];
        }

        return $weeks;
    }

    /** What each trade has learned, and what a new shop of it would inherit. */
    public function trades(): array
    {
        $trades = [];

        foreach (Vertical::all() as $vertical) {
            $rules = EnrichmentContentTemplate::inForce($vertical->value);
            $priors = AnalyticsPrior::forVertical($vertical->value);

            $trades[] = [
                'vertical' => $vertical,
                'shops' => $this->shops()->filter(fn (Shop $s): bool => $s->vertical === $vertical)->count(),
                'words' => $rules === null ? 0 : count($rules['takeaway_markers'] ?? []) + count($rules['takeaway_phrases'] ?? []) + count($rules['audience_markers'] ?? []),
                'version' => $rules['version'] ?? null,
                'priors' => $priors,
            ];
        }

        return $trades;
    }

    /** @return array{key: string, state: string, detail: ?string} */
    private function stage(string $key, bool $running, ?string $waitingFor): array
    {
        return [
            'key' => $key,
            'state' => $running ? 'running' : ($waitingFor === __('knowledge::learning.states.planned') ? 'planned' : 'waiting'),
            'detail' => $waitingFor,
        ];
    }
}
