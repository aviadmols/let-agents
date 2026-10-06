<?php

namespace App\Modules\Retrieval\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Actions\MatchProducts;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Build a shop's index, match its products, or both in that order.
 *
 *   retrieval index gueta-avigdor
 *   retrieval match gueta-avigdor
 *   retrieval nightly --all          what the schedule runs
 */
final class RetrievalCommand extends Command
{
    protected $signature = 'retrieval
        {step : index, match or nightly}
        {target? : shop slug or ID}
        {--all : every active shop}';

    protected $description = 'Index what a shop says, and match its products with a model.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'index' => $this->each(fn (Shop $shop): array => [app(BuildIndex::class)->handle($shop->id)]),
            'match' => $this->each(fn (Shop $shop): array => [app(MatchProducts::class)->handle($shop->id)]),
            'nightly' => $this->each(fn (Shop $shop): array => array_values(array_filter([
                Features::enabled('retrieval.index', $shop->id) ? app(BuildIndex::class)->handle($shop->id) : null,
                Features::enabled('retrieval.ai_matching', $shop->id) ? app(MatchProducts::class)->handle($shop->id) : null,
            ]))),
            default => $this->failWith('Unknown step.'),
        });
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
        }

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
