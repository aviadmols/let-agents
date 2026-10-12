<?php

namespace App\Modules\Knowledge\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Knowledge\Models\LearningMeasurement;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Whether the learning helped, asked of the shoppers who were kept away from it.
 *
 * Both sides are counted the same way over the same window: how often the widget was opened after
 * being seen, clicked after being opened, and so on down to an order. The difference between the
 * two rates is the whole claim, and it is only made when there is enough on both sides to make it
 * — a rate from forty exposures is a rumour.
 *
 * The verdict is allowed to be "too early", and for a young shop it usually should be. A system
 * that reports an improvement every week it is asked is not measuring anything.
 */
final class MeasureLearning
{
    public const AGENT = 'knowledge.measurer';

    public const ACTION = 'knowledge.measure';

    /** The window both sides are counted over. */
    private const DAYS = 28;

    /** What one side is compared on, in the order of how much each is worth knowing. */
    private const STEPS = [
        'open' => 'exposure',
        'click' => 'open',
        'add_to_cart' => 'open',
    ];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId): void {
                $measurement = $this->tenant->run($shopId, fn (): LearningMeasurement => $this->measure($shopId));

                $run->output([
                    'verdict' => $measurement->verdict,
                    'learned' => $measurement->learned,
                    'control' => $measurement->control,
                ])->summary('knowledge::runs.measured', [
                    'verdict' => __('knowledge::knowledge.verdict_lines.'.$measurement->verdict),
                ]);
            },
        );
    }

    private function measure(string $shopId): LearningMeasurement
    {
        $learned = $this->counts(false);
        $control = $this->counts(true);
        $minimum = (int) Settings::get('analytics.measure_min_exposures', $shopId);

        $effect = [];

        foreach (self::STEPS as $step => $of) {
            $effect[$step] = [
                'learned' => self::rate($learned[$step], $learned[$of]),
                'control' => self::rate($control[$step], $control[$of]),
                'enough' => $learned[$of] >= $minimum && $control[$of] >= $minimum,
            ];
            $effect[$step]['difference'] = $effect[$step]['learned'] === null || $effect[$step]['control'] === null
                ? null
                : round($effect[$step]['learned'] - $effect[$step]['control'], 4);
        }

        return LearningMeasurement::query()->updateOrCreate(
            ['shop_id' => $shopId, 'taken_on' => today()],
            [
                'window_days' => self::DAYS,
                'learned' => $learned,
                'control' => $control,
                'effect' => $effect,
                'verdict' => $this->verdict($control, $effect),
            ],
        );
    }

    /**
     * What one side of the measurement did.
     *
     * @return array<string, int>
     */
    private function counts(bool $holdout): array
    {
        $counts = AnalyticsEvent::query()
            ->where('occurred_at', '>=', now()->subDays(self::DAYS))
            ->where('preview', false)
            ->where('holdout', $holdout)
            // Adds from the store's search are not part of the widget's holdout: both sides search.
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', AnalyticsEvent::SOURCE_SEARCH))
            ->select('type', DB::raw('count(*) as c'))
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        return [
            'exposure' => (int) ($counts['exposure'] ?? 0),
            'open' => (int) ($counts['open'] ?? 0),
            'click' => (int) ($counts['click'] ?? 0),
            'add_to_cart' => (int) ($counts['add_to_cart'] ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $control
     * @param  array<string, mixed>  $effect
     */
    private function verdict(array $control, array $effect): string
    {
        if ($control['exposure'] === 0) {
            // Nobody is being held out, so there is nothing to compare the learning against.
            return LearningMeasurement::NO_CONTROL;
        }

        $decided = array_filter($effect, fn (array $row): bool => $row['enough'] === true && $row['difference'] !== null);

        if ($decided === []) {
            return LearningMeasurement::TOO_EARLY;
        }

        // The sharpest measure that has enough behind it decides, and a difference under a
        // percentage point is not a difference.
        $first = reset($decided);
        $difference = (float) $first['difference'];

        return match (true) {
            $difference > 0.01 => LearningMeasurement::HELPED,
            $difference < -0.01 => LearningMeasurement::HURT,
            default => LearningMeasurement::NO_DIFFERENCE,
        };
    }

    private static function rate(int $part, int $of): ?float
    {
        return $of === 0 ? null : round($part / $of, 4);
    }
}
