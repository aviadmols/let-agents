<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Throwable;

/**
 * Everything code can learn about a shop, read again, every night.
 *
 * Until now a product that arrived today was known about only once somebody remembered to type a
 * command. Every reader here costs nothing to run and skips what has not changed — a product
 * whose text is the same as last night is recognised by its hash and left alone — so the honest
 * default is to run all of them nightly and stop asking anyone.
 *
 * They run in the order they depend on each other: products are read before they can be ranked,
 * and ranked before relations can use the ranking. A step that throws does not take the rest of
 * the night with it; it is recorded and the next one runs, because a shop with four readings out
 * of five done is in a better state than a shop with one.
 */
final class RunNightlyReading
{
    public const AGENT = 'enrichment.nightly';

    public const ACTION = 'enrichment.nightly';

    /**
     * The readers, in the order they depend on each other.
     *
     * @var array<string, class-string>
     */
    public const STEPS = [
        'code' => ReadProductsInCode::class,
        'promises' => ReadPromisesInCode::class,
        'content' => ReadContentInCode::class,
        'articles' => MatchProductsToArticles::class,
        'rankings' => ComputeRankings::class,
        'relations' => ComputeProductRelations::class,
        // Last, and the only one that costs anything: what code could not work out on its own.
        'model' => RunNightlyModelReading::class,
    ];

    public function __construct(private readonly RecordsRuns $runs) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId): void {
                $done = [];
                $failed = [];

                foreach (self::STEPS as $name => $action) {
                    // Relations only where the shop still has them; elsewhere the indexes fill those sections.
                    if ($name === 'relations' && ! Features::enabled('enrichment.relations', $shopId)) {
                        continue;
                    }
                    try {
                        $step = app($action)->handle($shopId);
                        $done[$name] = $step->status->value;
                    } catch (Throwable $e) {
                        // One reader failing is not a reason to leave the rest of the night
                        // unread. What went wrong is kept, and the shop still improves.
                        $failed[$name] = mb_substr($e->getMessage(), 0, 200);
                    }
                }

                $run->output(['steps' => $done, 'failed' => $failed])
                    ->summary('enrichment::runs.nightly', [
                        'done' => (string) count($done),
                        'total' => (string) count(self::STEPS),
                        'failed' => (string) count($failed),
                    ]);
            },
        );
    }
}
