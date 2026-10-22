<?php

namespace App\Modules\Retrieval\Console;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Actions\SwitchEmbedder;
use App\Modules\Retrieval\Support\EmbeddingChoice;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Move every shop's search by meaning to another embedding model (SwitchEmbedder): the model and the
 * thresholds that depend on it become the default, the old vectors are deleted, and every active shop is
 * embedded again, shop after shop. Until a shop is done its search finds by spelling alone.
 *
 *   retrieval:switch-embedder --price=0.12 --thresholds=semantic=0.23,meaning_only=0.47,content=0.38,assistant_site=0.26,min_alternative=0.47
 *       --thresholds=rocks-and-gold:semantic=0.22,... --dry-run
 *
 * Thresholds: key=value pairs, comma separated, by setting key or short name; "slug:" in front makes them
 * that shop's own. Once the model is the default, running it again without --thresholds only embeds what is
 * left (a shop stopped by the spending cap or a provider error); what has a vector already costs nothing.
 */
final class SwitchEmbedderCommand extends Command
{
    private const SHORT = [
        'semantic' => 'search.semantic_min_similarity',
        'meaning_only' => 'search.meaning_only_min_similarity',
        'content' => 'search.content_min_similarity',
        'assistant_site' => 'assistant.site_min_similarity',
        'min_alternative' => 'retrieval.min_alternative_similarity',
    ];

    protected $signature = 'retrieval:switch-embedder
        {--provider=voyage : the new model\'s provider}
        {--model=voyage-4-large : the new model}
        {--dimensions=1024 : its vector size (0: the model\'s own)}
        {--price= : its USD per million tokens (needed unless it is the default model already)}
        {--thresholds=* : key=value,... the new defaults; slug:key=value,... one shop\'s own}
        {--max-rounds=100 : index runs per shop at most}
        {--dry-run : say what would change and what embedding costs; nothing is sent or written}';

    protected $description = 'Move search by meaning to another embedding model: the default changes, old vectors go, every shop is embedded again.';

    public function handle(TenantContext $tenant, SwitchEmbedder $switch): int
    {
        try {
            $target = $this->target();
            [$defaults, $own] = $this->thresholds($tenant);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $chosen = Settings::get('retrieval.embedding_model') === $target->model() && Settings::get('retrieval.embedding_provider') === $target->choice->providerName;
        $choose = ! $chosen || $defaults !== [] || $own !== [];
        $missing = array_diff(SwitchEmbedder::THRESHOLDS, array_keys($defaults));

        if ($choose && $missing !== []) {
            $this->error('Every threshold read against the words\' model needs its new default: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $shops = $tenant->runUnscoped(fn () => Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get());
        $this->info('To '.$target->choice->label().($target->dimensions ? " ({$target->dimensions} dimensions)" : '').', $'.$target->usdPerMillion.' a million tokens; '.$shops->count().' active shops.');
        $this->line($choose
            ? 'The default: '.self::listed($defaults).'. Own values: '.($own === [] ? 'none' : implode('; ', array_map(fn (string $id, array $v): string => $shops->firstWhere('id', $id)?->slug.' '.self::listed($v), array_keys($own), $own))).'.'
            : 'The model is the default already: only what is left is embedded.');

        if ($this->option('dry-run')) {
            return $this->dryRun($switch, $target, $shops);
        }

        if ($choose) {
            try {
                $chose = $switch->choose($target, $defaults, $own);
            } catch (Throwable $e) {
                $this->error('Nothing changed: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->info($chose['run']->summary());
            $this->line('Deleted: '.$chose['forgotten']['pieces'].' pieces\' vectors, '.$chose['forgotten']['wordings'].' wordings, '.$chose['forgotten']['captions'].' descriptions\' vectors.');
            foreach ($chose['cleared'] as $line) {
                $this->line('  a shop\'s own value made for the old model, dropped: '.$line);
            }
        }

        $failed = [];
        $cost = 0.0;

        foreach ($shops as $shop) {
            $done = $switch->embed($shop->id, (int) $this->option('max-rounds'));
            $cost += $done['cost_usd'];
            $last = end($done['runs']);
            $this->line("{$shop->slug}: ".($last ? $last->summary() : ''));

            if (! $done['done']) {
                $failed[$shop->slug] = (string) $done['stopped'];
                $this->warn("{$shop->slug}: stopped ({$done['stopped']}); search by spelling until it is run again.");
            }
        }

        $this->line(sprintf('Done: %d shops. Stopped: %d. Embedding cost: $%s.', $shops->count() - count($failed), count($failed), number_format($cost, 4)));
        if ($failed !== []) {
            $this->line('Run it again (without --thresholds) to go on: '.implode(', ', array_map(fn (string $slug, string $why): string => "{$slug} ({$why})", array_keys($failed), $failed)).'.');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  Collection<int, Shop>  $shops */
    private function dryRun(SwitchEmbedder $switch, EmbeddingChoice $target, $shops): int
    {
        $rows = [];
        $total = ['pieces' => 0, 'asks' => 0, 'captions' => 0, 'tokens' => 0, 'usd' => 0.0];

        foreach ($shops as $shop) {
            $left = $switch->estimate($shop->id, $target);
            foreach (array_keys($total) as $key) {
                $total[$key] += $left[$key];
            }
            $rows[] = [$shop->slug, $left['pieces'], $left['asks'], $left['captions'], number_format($left['tokens']), '$'.number_format($left['usd'], 4)];
        }

        $rows[] = ['all', $total['pieces'], $total['asks'], $total['captions'], number_format($total['tokens']), '$'.number_format($total['usd'], 4)];
        $this->table(['shop', 'pieces', 'questions', 'descriptions', '~tokens', '~cost'], $rows);
        $this->line('Tokens at two characters a token, as the index estimates; the provider counts its own. Wordings shoppers type are embedded as they come.');
        $this->line('Dry run: nothing was sent or written.');

        return self::SUCCESS;
    }

    private function target(): EmbeddingChoice
    {
        $provider = strtolower(trim((string) $this->option('provider')));
        $model = trim((string) $this->option('model'));
        Settings::normalize('retrieval.embedding_provider', $provider);
        Settings::normalize('retrieval.embedding_model', $model);
        $dimensions = (int) Settings::normalize('retrieval.embedding_dimensions', (int) $this->option('dimensions'));
        $price = $this->option('price');

        if ($price === null || $price === '') {
            if (Settings::get('retrieval.embedding_model') !== $model || Settings::get('retrieval.embedding_provider') !== $provider) {
                throw new InvalidArgumentException('--price is needed: the USD a million tokens of '.$model.' costs (every call is accounted with it).');
            }
            $price = Settings::get('retrieval.embedding_usd_per_million');
        }

        $target = EmbeddingChoice::of($provider, $model, $dimensions, (float) Settings::normalize('retrieval.embedding_usd_per_million', $price));

        if ($target->choice->provider === null) {
            throw new InvalidArgumentException("No provider {$provider}.");
        }

        return $target;
    }

    /**
     * The thresholds given: the new defaults, and each shop's own, by shop id.
     *
     * @return array{0: array<string, float>, 1: array<string, array<string, float>>}
     */
    private function thresholds(TenantContext $tenant): array
    {
        $defaults = [];
        $own = [];

        foreach ((array) $this->option('thresholds') as $given) {
            $given = trim((string) $given);
            $shopId = null;

            if (preg_match('/^([^:=,]+):(.+)$/u', $given, $m) === 1) {
                $shop = $tenant->runUnscoped(fn () => Shop::query()->where('slug', trim($m[1]))->orWhere('id', trim($m[1]))->first());
                if ($shop === null) {
                    throw new InvalidArgumentException("No shop {$m[1]} (in --thresholds).");
                }
                [$shopId, $given] = [$shop->id, $m[2]];
            }

            foreach (array_filter(array_map('trim', explode(',', $given))) as $pair) {
                [$key, $value] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
                $key = self::SHORT[$key] ?? $key;

                if (! in_array($key, SwitchEmbedder::THRESHOLDS, true) || ! is_numeric($value)) {
                    throw new InvalidArgumentException("Not a threshold of the words' model: {$pair}. Known: ".implode(', ', [...array_keys(self::SHORT), ...SwitchEmbedder::THRESHOLDS]).'.');
                }

                $value = (float) Settings::normalize($key, (float) $value);
                if ($shopId === null) {
                    $defaults[$key] = $value;
                } else {
                    $own[$shopId][$key] = $value;
                }
            }
        }

        return [$defaults, $own];
    }

    /** @param  array<string, float>  $values */
    private static function listed(array $values): string
    {
        $short = array_flip(self::SHORT);

        return implode(', ', array_map(fn (string $key, float $value): string => ($short[$key] ?? $key).' '.$value, array_keys($values), $values));
    }
}
