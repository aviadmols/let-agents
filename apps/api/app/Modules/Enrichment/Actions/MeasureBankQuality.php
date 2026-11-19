<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Scanning\BankEvidence;
use App\Modules\Enrichment\Scanning\BankQuality;
use App\Modules\Enrichment\Scanning\CatalogueFacts;
use App\Modules\Enrichment\Scanning\PictureText;
use App\Modules\Enrichment\Support\ReplySchema;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * How good a stored bank's types are, beyond the share they cover (Aviad, 19.11): by department and how specific they are,
 * in code (BankQuality); and how often a type is right, judged on a sample by a model of the other family than the bank's
 * writer (bank_audit, as the vocabulary's completion asks it): products spread over the types, each "is this product a …?".
 * Kept on the bank's measurement (`quality`); nothing is activated or changed. Any store.
 */
final class MeasureBankQuality
{
    public const AGENT = 'enrichment.bank_quality';

    public const ACTION = 'enrichment.measure_bank_quality';

    /** Products judged, spread over the types. */
    public const SAMPLE = 40;

    /** Whether each sampled product's type is right, and whether a shopper would name it by a finer kind. */
    private const PROMPT = 'bank_type_check';

    private const PROMPT_VERSION = 1;

    private const TOKENS = 4000;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, string $bankId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['bank' => $bankId],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->measure($run, $shopId, $bankId)),
        );
    }

    private function measure(RunContext $run, string $shopId, string $bankId): void
    {
        $stored = EnrichmentExpertBank::query()->whereKey($bankId)->firstOrFail();
        $bank = (array) $stored->bank;
        $bank['types'] = array_values((array) ($bank['types'] ?? []));
        ['texts' => $texts] = CatalogueFacts::of(CatalogProduct::query()->active()->orderBy('id')->lazy(300), 0, (string) Shop::query()->whereKey($shopId)->value('content_locale'));

        $sets = (new BankEvidence($texts))->typeSets($bank);
        $typeOf = BankQuality::typeOf($bank, $sets);
        $departments = BankQuality::departments($texts, $typeOf);
        $specificity = BankQuality::specificity($texts, $typeOf, $bank);
        $accuracy = $this->accuracy($run, $bank, $texts, $typeOf);

        $quality = [
            'measured_at' => now()->toIso8601String(),
            'type_share' => $texts === [] ? 0.0 : round(count($typeOf) / count($texts), 3),
            'accuracy' => $accuracy,
            // what names open with under each type: a hint only (a store whose names open with a model's name looks broad)
            'openings' => $specificity,
            'departments' => $departments,
        ];
        $stored->forceFill(['measurement' => (array) $stored->measurement + ['quality' => $quality]])->save();

        $weak = array_values(array_filter($departments, fn (array $d): bool => $d['weak']));
        $run->output(['bank' => $bankId, 'quality' => $quality])->summary('enrichment::runs.bank_quality', [
            'typed' => (int) round($quality['type_share'] * 100),
            'accuracy' => $accuracy['share'] === null ? '-' : (string) (int) round($accuracy['share'] * 100),
            'judged' => (string) $accuracy['sure'],
            'specific' => $accuracy['specific'] === null ? '-' : (string) (int) round($accuracy['specific'] * 100),
            'weak' => $weak === [] ? '-' : implode(', ', array_map(fn (array $d): string => $d['department'].' '.(int) round($d['share'] * 100).'%', array_slice($weak, 0, 4))),
        ]);
    }

    /**
     * @param  list<array{id: string, title: string}>  $texts
     * @param  array<int, string>  $typeOf
     * @return array{judged: int, sure: int, correct: int, incorrect: int, share: float|null, wrong: list<array{title: string, type: string}>}
     */
    private function accuracy(RunContext $run, array $bank, array $texts, array $typeOf): array
    {
        $none = ['judged' => 0, 'sure' => 0, 'correct' => 0, 'incorrect' => 0, 'share' => null, 'wrong' => []];
        if ($typeOf === []) {
            return $none;
        }
        $labels = [];
        foreach ($bank['types'] as $type) {
            $label = $type['label'] ?? $type['key'];
            $labels[(string) $type['key']] = is_array($label) ? (string) (reset($label) ?: $type['key']) : (string) $label;
        }

        // spread over the types, round by round, the same products each time (by a hash of their ids)
        $byType = [];
        foreach ($typeOf as $index => $key) {
            $byType[$key][] = $index;
        }
        foreach ($byType as &$indexes) {
            usort($indexes, fn (int $a, int $b): int => strcmp(md5((string) $texts[$a]['id']), md5((string) $texts[$b]['id'])));
        }
        unset($indexes);
        ksort($byType, SORT_STRING);
        $sample = [];
        for ($round = 0; count($sample) < self::SAMPLE; $round++) {
            $added = false;
            foreach ($byType as $key => $indexes) {
                if (isset($indexes[$round]) && count($sample) < self::SAMPLE) {
                    $sample[] = [$indexes[$round], $key];
                    $added = true;
                }
            }
            if (! $added) {
                break;
            }
        }

        $products = CatalogProduct::query()->whereIn('id', array_map(fn (array $s): string => (string) $texts[$s[0]]['id'], $sample))->get()->keyBy(fn (CatalogProduct $p): string => (string) $p->id);
        $pictures = PictureText::for();
        $items = [];
        foreach ($sample as $n => [$index, $key]) {
            $id = (string) $texts[$index]['id'];
            $product = $products->get($id);
            if ($product !== null) {
                $items[] = ['id' => (string) $n, 'product' => CompleteVocabulary::described($product, $pictures[$id] ?? null), 'type' => $labels[$key] ?? $key];
            }
        }

        $auditor = AgentModel::from('enrichment.expert_bank_checker_provider', 'enrichment.expert_bank_checker_model');
        $system = (string) file_get_contents(dirname(__DIR__).'/Prompts/'.self::PROMPT.'.v'.self::PROMPT_VERSION.'.md');
        $schema = ReplySchema::load(self::PROMPT, self::PROMPT_VERSION);
        $user = (string) json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get('enrichment.expert_bank_checker_input_usd_per_million');
        $out = (float) Settings::get('enrichment.expert_bank_checker_output_usd_per_million');
        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 3 * $in + self::TOKENS * $out) / 1_000_000);
        $reply = $this->models instanceof StructuredChatModel
            ? $this->models->structured($auditor->provider, $auditor->model, $system, $user, $schema, self::TOKENS, 'low')
            : $this->models->json($auditor->provider, $auditor->model, $system, $user, self::TOKENS, 'low');
        $run->usage($auditor->provider->value, $auditor->model, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->costUsd($in, $out));

        $verdicts = [];
        foreach ((array) ($reply->data['verdicts'] ?? []) as $v) {
            $verdicts[(string) ($v['id'] ?? '')] = ['verdict' => (string) ($v['verdict'] ?? 'unsure'), 'finer' => trim((string) ($v['finer'] ?? ''))];
        }
        $correct = 0;
        $incorrect = 0;
        $wrong = [];
        $coarse = [];
        foreach ($items as $item) {
            $verdict = $verdicts[$item['id']]['verdict'] ?? 'unsure';
            $title = mb_substr((string) $item['product']['title'], 0, 80);
            if ($verdict === 'correct') {
                $correct++;
                // right, but a shopper would ask for it by a finer kind: the bank settled for a department
                if (($finer = $verdicts[$item['id']]['finer'] ?? '') !== '') {
                    $coarse[] = ['title' => $title, 'type' => (string) $item['type'], 'finer' => mb_substr($finer, 0, 40)];
                }
            }
            if ($verdict === 'incorrect') {
                $incorrect++;
                $wrong[] = ['title' => $title, 'type' => (string) $item['type']];
            }
        }
        $sure = $correct + $incorrect;

        return ['judged' => count($items), 'sure' => $sure, 'correct' => $correct, 'incorrect' => $incorrect, 'share' => $sure === 0 ? null : round($correct / $sure, 3), 'wrong' => $wrong,
            // of those right, the share given the kind a shopper names them by
            'specific' => $correct === 0 ? null : round(($correct - count($coarse)) / $correct, 3), 'coarse' => $coarse];
    }
}
