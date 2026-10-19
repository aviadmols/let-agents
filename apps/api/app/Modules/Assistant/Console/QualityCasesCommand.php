<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\RunQualityCases;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantQualityRun;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * A shop's regression cases: imported from a file (questions with what is expected of each, kept with
 * the data the writer is given now), or asked again before a change goes out with the writer prompt at
 * a chosen version: fails (exit 1) when a case that was fixed broke. Every case run is a real, paid
 * model call; importing calls none.
 */
final class QualityCasesCommand extends Command
{
    protected $signature = 'assistant:quality-cases
        {shop : the shop, by slug or ID}
        {--import= : a JSON file of [{question, products?, expect, note?}] to add as cases}
        {--prompt= : the answer_site prompt version, the current one when left out}
        {--live : on today\'s catalogue and pages instead of each case\'s frozen data}';

    protected $description = 'Import a shop\'s regression cases, or ask them again and check each answer in code against what is expected.';

    public function handle(RunQualityCases $cases, AnswerSiteQuestion $answers, TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');
        $shop = Shop::query()->where('slug', $key)->orWhere('id', $key)->first();

        if ($shop === null) {
            $this->error("No shop {$key}.");

            return self::FAILURE;
        }

        if (($file = $this->option('import')) !== null) {
            $rows = json_decode((string) @file_get_contents((string) $file), true);

            if (! is_array($rows)) {
                $this->error("{$file} is not a JSON list.");

                return self::FAILURE;
            }

            $added = 0;
            foreach ($rows as $row) {
                if (! is_array($row) || trim((string) ($row['question'] ?? '')) === '') {
                    continue;
                }

                $products = array_values(array_map('strval', (array) ($row['products'] ?? [])));
                $snapshot = $answers->snapshot((string) $shop->id, (string) $row['question'], $products);
                $tenant->run((string) $shop->id, fn () => AssistantQualityCase::query()->updateOrCreate(
                    ['shop_id' => $shop->id, 'question' => mb_substr((string) $row['question'], 0, 500)],
                    ['kind' => AssistantQualityCase::REGRESSION, 'products' => $products, 'expect' => (array) ($row['expect'] ?? []),
                        'note' => isset($row['note']) ? mb_substr((string) $row['note'], 0, 300) : null, 'snapshot' => $snapshot, 'snapshot_at' => now()],
                ));
                $added++;
            }
            $this->line("{$added} cases imported for {$shop->slug}.");

            return self::SUCCESS;
        }

        $run = $cases->handle((string) $shop->id, null, $this->option('prompt') === null ? null : (int) $this->option('prompt'),
            $this->option('live') ? AssistantQualityRun::LIVE : AssistantQualityRun::FROZEN, AssistantQualityCase::REGRESSION);
        $t = (array) $run->totals;
        $failed = (int) ($t['expected'] ?? 0) - (int) ($t['passed'] ?? 0);

        $this->line(sprintf('%d of %d passed, %d failed, $%.4f%s', $t['passed'] ?? 0, $t['expected'] ?? 0, $failed, (float) ($t['cost_usd'] ?? 0), ($t['stopped'] ?? null) !== null ? ' (stopped: '.$t['stopped'].')' : ''));

        return $failed === 0 && ($t['stopped'] ?? null) === null ? self::SUCCESS : self::FAILURE;
    }
}
