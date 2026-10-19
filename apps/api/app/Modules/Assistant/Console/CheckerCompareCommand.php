<?php

namespace App\Modules\Assistant\Console;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The answer check before and after, on fixed cases with known outcomes (resources/evals/checker-cases.json):
 * each case's answer goes to the checker with each prompt version, its reply is applied as the search
 * box applies it (applyCheck, then code's gate on a fix), and what is left is set against what should be.
 *
 *   assistant:checker-compare sellameir                       versions 4 and 6, once each
 *   assistant:checker-compare sellameir --versions=6 --repeat=3
 *
 * Counted per version: correct, false rejections (a good answer, or a fitting pick, thrown away),
 * missed errors (a wrong claim, an unfit pick or an answer to refuse let through), time and cost. Real
 * model calls, accounted on one run of the shop given.
 */
final class CheckerCompareCommand extends Command
{
    public const AGENT = 'assistant.site_answerer';

    public const ACTION = 'assistant.checker_compare';

    protected $signature = 'assistant:checker-compare {shop : the shop the calls are accounted to} {--versions=4,6 : checker prompt versions} {--repeat=1 : runs of each case} {--file= : cases file}';

    protected $description = 'Compare checker prompt versions on fixed cases: false rejections, missed errors, time.';

    public function handle(ChatModel $models, SpendGuard $spend, RecordsRuns $runs, TenantContext $tenant): int
    {
        $shop = $tenant->runUnscoped(fn () => Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->first());
        $file = (string) ($this->option('file') ?: __DIR__.'/../resources/evals/checker-cases.json');
        $cases = (array) (json_decode((string) @file_get_contents($file), true)['cases'] ?? []);
        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('assistant.site_check_provider'))));
        $model = (string) Settings::get('assistant.site_check_model');

        if ($shop === null || $cases === [] || $provider === null) {
            $this->error($shop === null ? 'Shop not found.' : ($cases === [] ? 'No cases.' : 'No checker provider.'));

            return self::FAILURE;
        }

        $prices = [(float) Settings::get('assistant.site_check_input_usd_per_million'), (float) Settings::get('assistant.site_check_output_usd_per_million')];
        $max = (int) Settings::get('assistant.site_check_max_output_tokens');
        $versions = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('versions')))));
        $repeat = max(1, (int) $this->option('repeat'));
        $rows = [];
        $totals = [];

        $runs->track(agent: self::AGENT, action: self::ACTION, shopId: $shop->id, trigger: RunTrigger::Manual, work: function (RunContext $run) use ($models, $spend, $cases, $provider, $model, $prices, $max, $versions, $repeat, &$rows, &$totals): void {
            foreach ($versions as $version) {
                $prompt = (string) @file_get_contents(__DIR__.'/../Prompts/verify_site.v'.$version.'.md');
                $totals[$version] = ['correct' => 0, 'false_reject' => 0, 'missed' => 0, 'failed' => 0, 'ms' => [], 'cost' => 0.0];

                for ($round = 0; $round < $repeat; $round++) {
                    foreach ($cases as $case) {
                        $input = self::caseInput($case);
                        $spend->assertCanSpend(((int) ceil(mb_strlen($input) / 2) + 600) * $prices[0] / 1e6 + $max * $prices[1] / 1e6);
                        $started = hrtime(true);

                        try {
                            $reply = $models->json($provider, $model, $prompt, $input, $max, null);
                        } catch (ModelCallFailed $e) {
                            $totals[$version]['failed']++;
                            $rows[] = [$version, $case['id'], (string) $case['expect'], 'failed: '.$e->reason, ''];

                            continue;
                        }

                        $ms = (int) round((hrtime(true) - $started) / 1e6);
                        $cost = $reply->costUsd(...$prices);
                        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
                        [$outcome, $why, $left] = self::judge($case, $reply->data);
                        $totals[$version][$outcome]++;
                        $totals[$version]['ms'][] = $ms;
                        $totals[$version]['cost'] += $cost;
                        $rows[] = [$version, $case['id'], (string) $case['expect'], $outcome.($why === '' ? '' : ': '.$why), mb_substr($left, 0, 70)];
                    }
                }
            }

            $run->output(['versions' => array_map(fn (array $t): array => array_diff_key($t, ['ms' => 0]) + ['avg_ms' => self::avg($t['ms']), 'p95_ms' => self::p95($t['ms'])], $totals)])
                ->summary('assistant::runs.checker_compared', ['cases' => (string) count($cases)]);
        });

        $this->table(['v', 'case', 'expect', 'came out', 'answer left'], $rows);
        $this->table(['version', 'correct', 'false rejections', 'missed errors', 'failed calls', 'avg ms', 'p95 ms', 'cost $'], array_map(fn (int $v, array $t): array => [
            $v, $t['correct'], $t['false_reject'], $t['missed'], $t['failed'], self::avg($t['ms']), self::p95($t['ms']), round($t['cost'], 4),
        ], array_keys($totals), $totals));

        return self::SUCCESS;
    }

    /** The checker's input, as the search box writes it (AnswerSiteQuestion::checkInput). */
    private static function caseInput(array $case): string
    {
        return (string) json_encode([
            'question' => (string) $case['question'],
            'answer' => (string) $case['answer'],
            'passages' => array_map(fn (array $p): array => ['title' => (string) $p['title'], 'text' => (string) $p['text']], (array) ($case['passages'] ?? [])),
            'picks' => array_map(fn (array $p): array => ['ref' => (string) $p['ref'], 'title' => (string) $p['title'], 'about' => (string) $p['about'], 'why' => (string) $p['why'],
                'in_stock' => (bool) ($p['in_stock'] ?? true), 'on_sale' => (bool) ($p['on_sale'] ?? false)] + (isset($p['price_rank']) ? ['price_rank' => (int) $p['price_rank']] : []), (array) ($case['picks'] ?? [])),
            'analysis' => (array) ($case['analysis'] ?? ['asks' => null]),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * What the reply leaves of the case, against what should: correct, a false rejection or a missed error.
     *
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $reply
     * @return array{0: string, 1: string, 2: string}
     */
    public static function judge(array $case, array $reply): array
    {
        $picks = array_map(fn (array $p): array => ['ref' => (string) $p['ref'], 'external_id' => (string) $p['ref'], 'title' => (string) $p['title'], 'why' => (string) $p['why']], (array) ($case['picks'] ?? []));
        $applied = AnswerSiteQuestion::applyCheck($reply, (string) $case['answer'], $picks);
        $known = implode("\n", [...array_map(fn (array $p): string => $p['title']."\n".$p['text'], (array) ($case['passages'] ?? [])), ...array_map(fn (array $p): string => $p['title'].' '.$p['about'], (array) ($case['picks'] ?? [])), (string) $case['question']]);
        $answer = $applied['answer'];
        if (($applied['revised'] ?? false) && $answer !== '') {
            [$answer] = AnswerSiteQuestion::gate($answer, $known, '');
        }
        $left = array_column($applied['picks'], 'ref');
        $expect = (string) $case['expect'];

        $lost = array_diff((array) ($case['keep_picks'] ?? []), $left);
        $stayed = array_intersect((array) ($case['drop'] ?? []), $left);

        return match (true) {
            in_array($expect, ['keep', 'fix'], true) && $answer === '' => ['false_reject', 'the answer was thrown away', ''],
            $lost !== [] => ['false_reject', 'lost '.implode(',', $lost), $answer],
            $expect === 'reject' && $answer !== '' => ['missed', 'let through', $answer],
            $expect === 'fix' && isset($case['gone']) && mb_stripos($answer, (string) $case['gone']) !== false => ['missed', '"'.$case['gone'].'" stayed', $answer],
            $stayed !== [] => ['missed', 'kept '.implode(',', $stayed), $answer],
            default => ['correct', '', $answer],
        };
    }

    /** @param list<int> $ms */
    private static function avg(array $ms): int
    {
        return $ms === [] ? 0 : (int) round(array_sum($ms) / count($ms));
    }

    /** @param list<int> $ms */
    private static function p95(array $ms): int
    {
        sort($ms);

        return $ms === [] ? 0 : $ms[(int) floor(0.95 * (count($ms) - 1))];
    }
}
