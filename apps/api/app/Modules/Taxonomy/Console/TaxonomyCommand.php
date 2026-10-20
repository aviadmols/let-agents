<?php

namespace App\Modules\Taxonomy\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Taxonomy\Actions\AssignTags;
use App\Modules\Taxonomy\Actions\BuildTagBank;
use App\Modules\Taxonomy\Actions\MergeTagProposals;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Support\Bank;
use App\Modules\Taxonomy\Support\Evaluation;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The tag bank's pilot, by hand:
 *
 *   taxonomy bank sellameir [--fresh]        the bank, from a sample of the store
 *   taxonomy assign sellameir [--limit=40]   tags what changed since it was tagged [--all: everything]
 *   taxonomy merge sellameir                 the proposals made often enough: add, merge or reject
 *   taxonomy eval sellameir [--sample=15]    against the reviewed cases (resources/evals/{slug}.json), and the cost
 *   taxonomy show sellameir                  the bank
 */
final class TaxonomyCommand extends Command
{
    protected $signature = 'taxonomy {action : bank|assign|merge|eval|show} {shop : the shop\'s slug or id}
        {--fresh : bank: start the bank afresh, taking its tags off every product}
        {--limit= : assign: at most this many products and posts}
        {--all : assign: every one, not only what changed}
        {--no-posts : assign: products only}
        {--sample=15 : eval: products shown with their tags for a person to look at}
        {--file= : eval: the reviewed cases}';

    protected $description = 'The store tag bank pilot: build the bank, tag products, merge proposals, measure.';

    public function handle(TenantContext $tenant): int
    {
        $shop = Shop::query()->where('slug', $this->argument('shop'))->orWhere('id', $this->argument('shop'))->first();

        if ($shop === null) {
            $this->error('No such shop.');

            return self::FAILURE;
        }

        $run = match ((string) $this->argument('action')) {
            'bank' => app(BuildTagBank::class)->handle($shop->id, (bool) $this->option('fresh')),
            'assign' => app(AssignTags::class)->handle($shop->id, $this->option('limit') === null ? null : (int) $this->option('limit'), (bool) $this->option('all'), ! $this->option('no-posts')),
            'merge' => app(MergeTagProposals::class)->handle($shop->id),
            default => null,
        };

        if ($run !== null) {
            $this->line(json_encode(['status' => $run->status->value, 'cost_usd' => $run->cost_usd, 'output' => $run->output, 'error' => $run->error], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
        }

        return $tenant->run($shop->id, function () use ($shop): int {
            if ($this->argument('action') === 'show') {
                foreach (Bank::groups() as $group) {
                    /** @var TaxonomyGroup $group */
                    $this->line(sprintf('%s · %s [%s%s%s%s]%s', $group->label_he, $group->key, $group->kind, $group->searchable ? ' search' : '', $group->filter ? ' filter' : '', $group->recommend ? ' recommend' : '', $group->scope ? ' scope: '.implode(', ', $group->scope) : ''));
                    $this->line('   '.$group->tags->map(fn ($t): string => $t->label_he)->implode(' | '));
                }

                return self::SUCCESS;
            }

            $file = (string) ($this->option('file') ?: __DIR__.'/../resources/evals/'.$shop->slug.'.json');
            $gold = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
            $this->line(json_encode(Evaluation::of($shop->id, $gold), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            foreach (Evaluation::sample((int) $this->option('sample')) as $item) {
                $this->line('— '.$item['title']);
                foreach ($item['tags'] as $tag) {
                    $this->line('    '.$tag);
                }
            }

            return self::SUCCESS;
        });
    }
}
