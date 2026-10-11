<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Support\RankSheet;
use Illuminate\Support\Facades\Cache;

/**
 * A photo search's first products, put in order by a model that sees them: one sheet with the
 * shopper's photo above the candidates' numbered pictures, one call, an order back. The vectors
 * and the reader choose the candidates; this only orders the few at the top.
 *
 * Off unless the shop turns it on (search.photo_rank), or the team's check asks for it to compare.
 * The same photo with the same candidates is ordered once.
 */
final class RankPhotoResults
{
    public const ACTION = 'search.rank_photo';

    public const PROMPT_VERSION = 1;

    private const PROMPT = __DIR__.'/../Prompts/photo_rank.v'.self::PROMPT_VERSION.'.md';

    /** The sheet is about this many input tokens, for the spend estimate. */
    private const SHEET_TOKENS = 1700;

    private const CACHE_DAYS = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly VisionModel $vision,
        private readonly SpendGuard $spend,
    ) {}

    /** Whether a model to order with is set at all. */
    public static function available(): bool
    {
        return AiProviderName::tryFrom(strtolower(trim((string) Settings::get('search.photo_rank_provider')))) !== null
            && trim((string) Settings::get('search.photo_rank_model')) !== '';
    }

    /**
     * The products in the order the model gave to the first search.photo_rank_candidates, the rest
     * after them as they were. A product the model calls the very same item is marked `same`.
     * Unchanged when there is no model, the sheet cannot be drawn, or the call fails.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $box
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public function handle(string $shopId, string $bytes, ?array $box, array $products, string $described = ''): array
    {
        $count = (int) Settings::get('search.photo_rank_candidates', $shopId);
        // The shop's own picture uploaded again is already known: it keeps its place, the rest are ordered.
        $kept = isset($products[0]['same']) && $products[0]['same'] === true ? [array_shift($products)] : [];
        $top = array_slice($products, 0, $count);

        if (! self::available() || count($top) < 2) {
            return [...$kept, ...$products];
        }

        $provider = AiProviderName::from(strtolower(trim((string) Settings::get('search.photo_rank_provider'))));
        $model = trim((string) Settings::get('search.photo_rank_model'));
        $key = 'search:photo-rank:'.$shopId.':'.hash('sha256', implode('|', [hash('sha256', $bytes), json_encode($box), implode(',', array_column($top, 'external_id')), self::PROMPT_VERSION, $model, $described]));
        $answer = Cache::get($key);

        if (! is_array($answer)) {
            $answer = $this->ask($shopId, $provider, $model, $bytes, $box, $top, $described);

            if ($answer !== null) {
                Cache::put($key, $answer, now()->addDays(self::CACHE_DAYS));
            }
        }

        if ($answer === null || $answer['order'] === []) {
            return [...$kept, ...$products];
        }

        $byId = [];
        foreach ($top as $product) {
            $byId[(string) $product['external_id']] = $product;
        }

        $ordered = [];
        foreach ($answer['order'] as $at => $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id] + ['ranked' => $at + 1] + ($answer['same'] === $id ? ['same' => true] : []);
                unset($byId[$id]);
            }
        }

        return [...$kept, ...$ordered, ...array_values($byId), ...array_slice($products, $count)];
    }

    /**
     * @param  list<array<string, mixed>>  $top
     * @return array{order: list<string>, same: string|null}|null
     */
    private function ask(string $shopId, AiProviderName $provider, string $model, string $bytes, ?array $box, array $top, string $described = ''): ?array
    {
        $sheet = RankSheet::build($bytes, $box, $top);

        if ($sheet === null) {
            return null;
        }

        $answer = null;
        $in = (float) Settings::get('search.photo_rank_input_usd_per_million');
        $out = (float) Settings::get('search.photo_rank_output_usd_per_million');
        $max = (int) Settings::get('search.photo_rank_max_output_tokens');
        $system = (string) file_get_contents(self::PROMPT);
        $user = 'The sheet holds '.count($sheet['numbered']).' products, numbered 1 to '.count($sheet['numbered']).'.'.($described === '' ? '' : "\n".$described);

        $this->runs->track(
            agent: ReadPhotoTags::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            work: function (RunContext $run) use (&$answer, $provider, $model, $system, $user, $sheet, $in, $out, $max): void {
                try {
                    $this->spend->assertCanSpend((self::SHEET_TOKENS + mb_strlen($system) / 2) * $in / 1_000_000 + $max * $out / 1_000_000);
                    $reply = $this->vision->jsonWithImage($provider, $model, $system, $user, 'image/jpeg', $sheet['jpeg'], $max, 'low');
                } catch (SpendCapReached) {
                    $run->fail('search::runs.photo_rank_spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('search::runs.photo_rank_failed', ['reason' => $e->reason]);

                    return;
                }

                $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
                $answer = self::read($reply->data, $sheet['numbered']);
                $run->output($answer + ['candidates' => count($sheet['numbered'])])->summary('search::runs.photo_rank', ['count' => (string) count($sheet['numbered'])]);
            },
        );

        return $answer;
    }

    /**
     * The model's numbers as external ids: each once, only those on the sheet.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $numbered
     * @return array{order: list<string>, same: string|null}
     */
    private static function read(array $data, array $numbered): array
    {
        $order = [];

        foreach ((array) ($data['order'] ?? []) as $number) {
            $id = is_numeric($number) ? ($numbered[(int) $number - 1] ?? null) : null;

            if ($id !== null && ! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $same = is_numeric($data['same'] ?? null) ? ($numbered[(int) $data['same'] - 1] ?? null) : null;

        return ['order' => $order, 'same' => $same];
    }
}
