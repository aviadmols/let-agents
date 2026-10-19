<?php

namespace App\Modules\Retrieval\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Actions\MatchProducts;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Build a shop's index, match its products, or both in that order.
 *
 *   retrieval index gueta-avigdor
 *   retrieval images gueta-avigdor   the pictures, when retrieval.image_index is on
 *   retrieval pictures gueta-avigdor every picture and how it looks, part after part (the first scan)
 *   retrieval match gueta-avigdor
 *   retrieval nightly --all          what the schedule runs
 */
final class RetrievalCommand extends Command
{
    /** How long the pictures step goes on; what is left, the night and the scan button finish. */
    private const PICTURE_MINUTES = 50;

    /** The last shop's pictures step did nothing: both its parts were held by another scan. */
    private bool $idle = false;

    protected $signature = 'retrieval
        {step : index, images, pictures, match or nightly}
        {target? : shop slug or ID}
        {--all : every active shop}
        {--minutes= : the pictures step\'s time, at most (the scan keeps it under the queue\'s retry_after)}';

    protected $description = 'Index what a shop says, and match its products with a model.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'index' => $this->each(fn (Shop $shop): array => [app(BuildIndex::class)->handle($shop->id)]),
            'images' => $this->each(fn (Shop $shop): array => [app(BuildImageIndex::class)->handle($shop->id)]),
            'pictures' => $this->each(fn (Shop $shop): array => $this->pictures($shop)),
            'match' => $this->each(fn (Shop $shop): array => [app(MatchProducts::class)->handle($shop->id)]),
            'nightly' => $this->each(fn (Shop $shop): array => array_values(array_filter([
                Features::enabled('retrieval.index', $shop->id) ? app(BuildIndex::class)->handle($shop->id) : null,
                Features::enabled('retrieval.image_index', $shop->id) ? app(BuildImageIndex::class)->handle($shop->id, RunTrigger::Schedule) : null,
                Features::enabled('retrieval.ai_matching', $shop->id) ? app(MatchProducts::class)->handle($shop->id) : null,
            ]))),
            default => $this->failWith('Unknown step.'),
        });
    }

    /**
     * Every picture of the shop and what it shows, in one go: the picture scan part after part
     * until none is left, then the descriptions the same way, within the step's time. Off where
     * the shop has no picture index (it needs the picture model's key).
     *
     * @return list<Run>
     */
    private function pictures(Shop $shop): array
    {
        if (! Features::enabled('retrieval.image_index', $shop->id)) {
            $this->line("{$shop->slug}: pictures off");

            return [];
        }

        $until = now()->addMinutes($this->option('minutes') !== null ? max(1, (int) $this->option('minutes')) : self::PICTURE_MINUTES);
        $runs = [];

        $busy = 0;

        foreach ([fn (): Run => app(BuildImageIndex::class)->handle($shop->id), fn (): Run => app(CaptionImages::class)->handle($shop->id)] as $part) {
            do {
                $run = $part();
                $runs[] = $run;
            } while (in_array($run->output['stopped'] ?? null, ['more', 'time_budget'], true) && now()->lt($until));

            $stopped = $run->output['stopped'] ?? null;

            // Another scan holds the pictures (or a restart left its lock): the descriptions, which
            // need only the pictures already seen, go on all the same.
            if ($stopped === 'already_running') {
                $busy++;

                continue;
            }

            // Descriptions only once every picture has its look.
            if ($stopped !== null) {
                break;
            }
        }

        // Nothing done because both parts were held: not a step that ended, one to try again.
        $this->idle = $busy === 2;

        return $runs;
    }

    /** @param callable(Shop): list<Run> $step */
    private function each(callable $step): int
    {
        $shops = $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            foreach ($step($shop) as $run) {
                $this->line("{$shop->slug}: ".$run->summary());
                $failed += $run->status->value === 'succeeded' ? 0 : 1;
            }
            $failed += $this->idle ? 1 : 0;
            $this->idle = false;
        }

        // A step that did nothing is never shown as done (the store's scan card reads this).
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function shop(): ?Shop
    {
        $key = (string) $this->argument('target');

        return Shop::query()->where('slug', $key)->orWhere('id', $key)->first();
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
