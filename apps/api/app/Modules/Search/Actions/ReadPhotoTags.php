<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use Illuminate\Support\Facades\Cache;

/**
 * What a shopper's photo shows, in the shop's own words: a deck under a pine pergola gives
 * "פרגולות", "עץ אורן" and "דקים", each a tag that searches the shop for it.
 *
 * Code before model: the candidates are the shop's categories, sent as numbers. The model looks
 * at the photo, picks numbers from that list and names a few things it sees; code keeps only
 * real numbers and only seen words that find products in the shop. Nothing it writes reaches the
 * shopper unless the shop has it, so no second model checks it.
 *
 * Never asked twice: the same photo with the same categories and prompt is answered from cache.
 */
final class ReadPhotoTags
{
    public const AGENT = 'search.photo_reader';

    public const ACTION = 'search.read_photo';

    public const PROMPT_VERSION = 1;

    private const PROMPT = __DIR__.'/../Prompts/photo_tags.v1.md';

    private const MAX_CANDIDATES = 300;

    private const MAX_TAGS = 6;

    /** A photo of about 1000px is about this many input tokens, for the spend estimate. */
    private const PHOTO_TOKENS = 1600;

    private const CACHE_DAYS = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly VisionModel $vision,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return list<array{title: string, kind: string, url?: string, id?: string}> category tags first, then words
     */
    public function handle(string $shopId, string $mime, string $bytes): array
    {
        $index = LoadedIndex::for($shopId);

        if ($index === null || ! Features::enabled('search.photo_tags', $shopId)) {
            return [];
        }

        $candidates = $this->candidates($index['records']);
        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('search.photo_tags_provider'))));
        $model = trim((string) Settings::get('search.photo_tags_model'));

        if ($provider === null || $model === '') {
            return [];
        }

        $key = 'search:photo-tags:'.$shopId.':'.hash('sha256', implode('|', [
            hash('sha256', $bytes), self::PROMPT_VERSION, $model, hash('sha256', implode("\n", array_column($candidates, 'title'))),
        ]));

        $picked = Cache::get($key);

        if (! is_array($picked)) {
            $picked = $this->ask($shopId, $provider, $model, $mime, $bytes, $candidates);

            if ($picked === null) {
                return [];
            }

            Cache::put($key, $picked, now()->addDays(self::CACHE_DAYS));
        }

        return $this->tags($index, $candidates, $picked);
    }

    /**
     * The shop's categories with products, in a fixed order so the same shop asks the same way.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<array{id: string, title: string, url: string|null}>
     */
    private function candidates(array $records): array
    {
        $out = [];

        foreach ($records as $record) {
            if (($record['t'] ?? null) === 'category' && (int) ($record['n'] ?? 0) > 0) {
                $out[] = ['id' => (string) $record['id'], 'title' => (string) $record['title'], 'url' => $record['url'] ?? null, 'n' => (int) $record['n']];
            }
        }

        // The biggest categories when a shop has more than the list holds; then by name.
        usort($out, fn (array $a, array $b): int => $b['n'] <=> $a['n'] ?: strcmp($a['title'], $b['title']));
        $out = array_slice($out, 0, self::MAX_CANDIDATES);
        usort($out, fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return array_map(fn (array $c): array => ['id' => $c['id'], 'title' => $c['title'], 'url' => $c['url']], $out);
    }

    /**
     * @param  list<array{id: string, title: string, url: string|null}>  $candidates
     * @return array{picks: list<int>, seen: list<string>}|null null when the model could not be asked
     */
    private function ask(string $shopId, AiProviderName $provider, string $model, string $mime, string $bytes, array $candidates): ?array
    {
        $list = [];

        foreach ($candidates as $i => $candidate) {
            $list[] = ($i + 1).'. '.$candidate['title'];
        }

        $system = (string) file_get_contents(self::PROMPT);
        $user = "Categories:\n".implode("\n", $list);
        $maxOutput = (int) Settings::get('search.photo_tags_max_output_tokens');
        $in = (float) Settings::get('search.photo_tags_input_usd_per_million');
        $out = (float) Settings::get('search.photo_tags_output_usd_per_million');
        $answer = null;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            work: function (RunContext $run) use (&$answer, $provider, $model, $mime, $bytes, $system, $user, $maxOutput, $in, $out, $candidates): void {
                try {
                    // Hebrew runs at about two characters a token; estimating high is the safe side.
                    $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 + self::PHOTO_TOKENS) * $in / 1_000_000 + $maxOutput * $out / 1_000_000);
                    $reply = $this->vision->jsonWithImage($provider, $model, $system, $user, $mime, $bytes, $maxOutput, 'low');
                } catch (SpendCapReached) {
                    $run->fail('search::runs.photo_tags_spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('search::runs.photo_tags_failed', ['reason' => $e->reason]);

                    return;
                }

                $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

                $picks = [];
                foreach ((array) ($reply->data['picks'] ?? []) as $number) {
                    if (is_numeric($number) && isset($candidates[(int) $number - 1]) && ! in_array((int) $number, $picks, true)) {
                        $picks[] = (int) $number;
                    }
                }

                $seen = [];
                foreach ((array) ($reply->data['seen'] ?? []) as $word) {
                    $word = trim(mb_substr(is_string($word) ? $word : '', 0, 40));
                    if ($word !== '' && ! in_array($word, $seen, true)) {
                        $seen[] = $word;
                    }
                }

                $answer = ['picks' => array_slice($picks, 0, self::MAX_TAGS), 'seen' => array_slice($seen, 0, self::MAX_TAGS)];
                $run->output($answer)->summary('search::runs.photo_tags', ['picks' => (string) count($answer['picks']), 'seen' => (string) count($answer['seen'])]);
            },
        );

        return $answer;
    }

    /**
     * The categories picked, then each seen word that finds products in the shop and is not one
     * of those categories already. At most MAX_TAGS.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<array{id: string, title: string, url: string|null}>  $candidates
     * @param  array{picks: list<int>, seen: list<string>}  $picked
     * @return list<array{title: string, kind: string, url?: string, id?: string}>
     */
    private function tags(array $index, array $candidates, array $picked): array
    {
        $tags = [];
        $titles = [];

        foreach ($picked['picks'] as $number) {
            $candidate = $candidates[$number - 1] ?? null;

            if ($candidate !== null) {
                $tags[] = array_filter(['title' => $candidate['title'], 'kind' => 'category', 'id' => $candidate['id'], 'url' => $candidate['url']], fn ($v): bool => $v !== null);
                $titles[] = HebrewSearch::normalize($candidate['title']);
            }
        }

        foreach ($picked['seen'] as $word) {
            if (in_array(HebrewSearch::normalize($word), $titles, true)) {
                continue;
            }

            foreach (HebrewSearch::search($index['engine'], $word) as $hit) {
                if (($index['records'][$hit['id']]['t'] ?? null) === 'product') {
                    $tags[] = ['title' => $word, 'kind' => 'words'];
                    $titles[] = HebrewSearch::normalize($word);
                    break;
                }
            }
        }

        return array_slice($tags, 0, self::MAX_TAGS);
    }
}
