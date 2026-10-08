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
use App\Modules\Search\Support\PhotoLook;
use App\Modules\Search\Support\PhotoPlacer;
use Illuminate\Support\Facades\Cache;

/**
 * What a shopper's photo shows, in the shop's own words: a tap gives "ברזים" and "ברזי מטבח",
 * each a tag that searches the shop for it.
 *
 * Code before model, in two parts. The reader only says what it sees ("ברז מטבח", other names
 * for it, what is around it, how sure it is); it never chooses from the shop's list. Code then
 * places those words among the shop's categories and products (PhotoPlacer). When that leaves
 * doubt (nothing placed, the reader unsure, or the nearest product pictures all of another kind)
 * a stronger model takes a second look, with the photo and a short list of the categories that
 * might hold the object, and may correct the reading. Nothing a model writes reaches the shopper
 * unless the shop has it.
 *
 * Never asked twice: the same photo under the same prompt and model is answered from cache, each
 * look on its own.
 */
final class ReadPhotoTags
{
    public const AGENT = 'search.photo_reader';

    public const ACTION = 'search.read_photo';

    public const SECOND_ACTION = 'search.second_look';

    public const PROMPT_VERSION = 3;

    public const SECOND_VERSION = 1;

    private const PROMPT = __DIR__.'/../Prompts/photo_tags.v'.self::PROMPT_VERSION.'.md';

    private const SECOND_PROMPT = __DIR__.'/../Prompts/photo_second_look.v'.self::SECOND_VERSION.'.md';

    private const MAX_TAGS = 6;

    private const MAX_CANDIDATES = 15;

    private const MAX_WORD = 40;

    /** A photo of about 1000px is about this many input tokens, for the spend estimate. */
    private const PHOTO_TOKENS = 1600;

    private const CACHE_DAYS = 30;

    /** The nearest pictures speak against the reader when this many of the first NEAR share a category and none of the object's own is among the first NEAR_ANY. */
    private const NEAR = 10;

    private const NEAR_AGREE = 6;

    private const NEAR_ANY = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly VisionModel $vision,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @param  list<array{external_id: string, similarity: float}>  $hits  the shop's pictures nearest the photo, nearest first
     */
    public function handle(string $shopId, string $mime, string $bytes, array $hits = []): PhotoLook
    {
        $index = LoadedIndex::for($shopId);

        if ($index === null || ! Features::enabled('search.photo_tags', $shopId)) {
            return PhotoLook::unread();
        }

        $reader = $this->role('photo_tags');

        if ($reader === null) {
            return PhotoLook::unread();
        }

        $hash = hash('sha256', $bytes);
        $first = $this->remembered('look', $shopId, $hash, [self::PROMPT_VERSION, $reader['model']],
            fn (): ?array => $this->firstLook($shopId, $reader, $mime, $bytes));

        if ($first === null) {
            return PhotoLook::unread();
        }

        if (! $first['sellable']) {
            return PhotoLook::nothing($first['object'], $first['sure']);
        }

        $placer = new PhotoPlacer($index);
        $placed = $placer->place($first['object'], $first['also']);
        $doubt = $this->doubt($placer, $placed, $first['sure'], $hits);
        $look = new PhotoLook(null, $first['object'], $first['around'], $first['sure']);

        if ($doubt === null || ! Features::enabled('search.photo_second_look', $shopId) || ($second = $this->role('photo_second')) === null) {
            return $look->with($this->tags($placer, $placed['main'], $first['around']), $doubt);
        }

        $candidates = $placer->candidates($placed, $hits, $first['also'], self::MAX_CANDIDATES);
        $answer = $this->remembered('second', $shopId, $hash, [self::SECOND_VERSION, $second['model'], $first['object'], array_column($candidates, 'title')],
            fn (): ?array => $this->secondLook($shopId, $second, $mime, $bytes, $first, $doubt, $candidates));

        if ($answer === null) {
            return $look->with($this->tags($placer, $placed['main'], $first['around']), $doubt);
        }

        if (! $answer['sellable']) {
            return PhotoLook::nothing($answer['object'] ?? $first['object'], $answer['sure'], true, $doubt);
        }

        if ($answer['main'] !== null) {
            $placed['main'] = $placer->categoryTag($placer->record(PhotoPlacer::CATEGORY.$candidates[$answer['main'] - 1]['id']), true) ?? $placed['main'];
        } elseif ($answer['object'] !== null && $answer['object'] !== $first['object']) {
            $placed = $placer->place($answer['object'], [$first['object'], ...$first['also']]);
        }

        $look = new PhotoLook(null, $answer['object'] ?? $first['object'], $first['around'], $answer['sure']);

        return $look->with($this->tags($placer, $placed['main'], $first['around']), $doubt, true);
    }

    /**
     * The main tag first, then one tag for each other thing in the photo the shop has.
     *
     * @param  list<string>  $around
     * @return list<array<string, mixed>>
     */
    private function tags(PhotoPlacer $placer, ?array $main, array $around): array
    {
        $tags = $main === null ? [] : [$main];
        $seen = $main === null ? [] : [HebrewSearch::normalize($main['title']), $main['id'] ?? ''];

        foreach ($around as $word) {
            $tag = $placer->tagFor($word);

            if ($tag === null || in_array(HebrewSearch::normalize($tag['title']), $seen, true) || in_array($tag['id'] ?? '-', $seen, true)) {
                continue;
            }

            $tags[] = $tag;
            array_push($seen, HebrewSearch::normalize($tag['title']), $tag['id'] ?? '');

            if (count($tags) >= self::MAX_TAGS) {
                break;
            }
        }

        return $tags;
    }

    /**
     * Why a second look is wanted, or null when the first stands: the object could not be placed,
     * the reader was not sure, or the nearest product pictures are all of another kind while none
     * of the object's own kind is near.
     *
     * @param  array{main: array<string, mixed>|null, vote: list<string>}  $placed
     * @param  list<array{external_id: string, similarity: float}>  $hits
     */
    private function doubt(PhotoPlacer $placer, array $placed, float $sure, array $hits): ?string
    {
        if ($placed['main'] === null) {
            return PhotoLook::UNPLACED;
        }

        if ($sure < (float) Settings::get('search.photo_sure_min')) {
            return PhotoLook::UNSURE;
        }

        if (($placed['main']['kind'] ?? '') !== 'category' || count($hits) < self::NEAR_AGREE) {
            return null;
        }

        $near = array_map('strval', array_slice(array_column($hits, 'external_id'), 0, self::NEAR_ANY));
        $categories = $placer->categoriesOf($near);
        $own = substr((string) $placed['main']['id'], 2);

        foreach ($near as $id) {
            if (in_array($own, $categories[$id] ?? [], true)) {
                return null;
            }
        }

        $counts = [];

        foreach (array_slice($near, 0, self::NEAR) as $id) {
            foreach ($categories[$id] ?? [] as $category) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }

        return $counts !== [] && max($counts) >= self::NEAR_AGREE ? PhotoLook::PICTURES_DISAGREE : null;
    }

    /**
     * @param  array{provider: AiProviderName, model: string, in: float, out: float, max: int}  $role
     * @return array{object: string|null, also: list<string>, around: list<string>, sure: float, sellable: bool}|null
     */
    private function firstLook(string $shopId, array $role, string $mime, string $bytes): ?array
    {
        $answer = null;

        $this->call($shopId, self::ACTION, $role, $mime, $bytes, (string) file_get_contents(self::PROMPT), "The shopper's photo is attached.", 'low',
            function (RunContext $run, array $data) use (&$answer): void {
                $object = self::word($data['object'] ?? null);
                $answer = [
                    'object' => $object,
                    'also' => self::words($data['also'] ?? [], 3, [$object]),
                    'around' => self::words($data['around'] ?? [], 4, [$object]),
                    'sure' => self::sure($data['sure'] ?? null),
                    'sellable' => $object !== null && ($data['sellable'] ?? true) !== false,
                ];
                $run->output($answer)->summary('search::runs.photo_look', ['object' => $object ?? '-', 'sure' => (string) round($answer['sure'] * 100)]);
            });

        return $answer;
    }

    /**
     * @param  array{provider: AiProviderName, model: string, in: float, out: float, max: int}  $role
     * @param  array{object: string|null, also: list<string>, around: list<string>, sure: float, sellable: bool}  $first
     * @param  list<array{id: string, title: string, n: int}>  $candidates
     * @return array{main: int|null, object: string|null, sure: float, sellable: bool}|null
     */
    private function secondLook(string $shopId, array $role, string $mime, string $bytes, array $first, string $doubt, array $candidates): ?array
    {
        $list = [];

        foreach ($candidates as $i => $candidate) {
            $list[] = ($i + 1).'. '.$candidate['title'].' ('.$candidate['n'].')';
        }

        $user = 'First reading: '.($first['object'] ?? '-').' (sure '.round($first['sure'], 2).'). Why the second look: '.$doubt.".\n"
            ."Categories:\n".($list === [] ? '(none)' : implode("\n", $list));
        $answer = null;

        $this->call($shopId, self::SECOND_ACTION, $role, $mime, $bytes, (string) file_get_contents(self::SECOND_PROMPT), $user, 'medium',
            function (RunContext $run, array $data) use (&$answer, $candidates, $doubt): void {
                $main = is_numeric($data['main'] ?? null) && isset($candidates[(int) $data['main'] - 1]) ? (int) $data['main'] : null;
                $answer = [
                    'main' => $main,
                    'object' => self::word($data['object'] ?? null),
                    'sure' => self::sure($data['sure'] ?? null),
                    'sellable' => ($data['sellable'] ?? true) !== false,
                ];
                $run->output($answer + ['doubt' => $doubt])->summary('search::runs.photo_second', [
                    'reason' => __('search::runs.photo_doubt.'.$doubt),
                    'outcome' => ! $answer['sellable'] ? __('search::runs.photo_nothing') : ($main !== null ? $candidates[$main - 1]['title'] : ($answer['object'] ?? '-')),
                ]);
            });

        return $answer;
    }

    /**
     * One model call with the photo, accounted in a run of its own: the spend checked first, the
     * tokens and cost written after, the answer handed to $read.
     *
     * @param  array{provider: AiProviderName, model: string, in: float, out: float, max: int}  $role
     * @param  callable(RunContext, array<string, mixed>): void  $read
     */
    private function call(string $shopId, string $action, array $role, string $mime, string $bytes, string $system, string $user, string $effort, callable $read): void
    {
        $this->runs->track(
            agent: self::AGENT,
            action: $action,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            work: function (RunContext $run) use ($role, $mime, $bytes, $system, $user, $effort, $read): void {
                try {
                    // Hebrew runs at about two characters a token; estimating high is the safe side.
                    $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 + self::PHOTO_TOKENS) * $role['in'] / 1_000_000 + $role['max'] * $role['out'] / 1_000_000);
                    $reply = $this->vision->jsonWithImage($role['provider'], $role['model'], $system, $user, $mime, $bytes, $role['max'], $effort);
                } catch (SpendCapReached) {
                    $run->fail('search::runs.photo_tags_spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('search::runs.photo_tags_failed', ['reason' => $e->reason]);

                    return;
                }

                $run->usage($role['provider']->value, $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($role['in'], $role['out']));
                $read($run, $reply->data);
            },
        );
    }

    /**
     * The same question under the same prompt, model and list is answered from cache.
     *
     * @param  list<mixed>  $parts  what the answer depends on besides the photo
     * @param  callable(): ?array  $ask
     */
    private function remembered(string $step, string $shopId, string $hash, array $parts, callable $ask): ?array
    {
        $key = 'search:photo-'.$step.':'.$shopId.':'.hash('sha256', $hash.'|'.json_encode($parts, JSON_UNESCAPED_UNICODE));
        $answer = Cache::get($key);

        if (is_array($answer)) {
            return $answer;
        }

        $answer = $ask();

        if ($answer !== null) {
            Cache::put($key, $answer, now()->addDays(self::CACHE_DAYS));
        }

        return $answer;
    }

    /** @return array{provider: AiProviderName, model: string, in: float, out: float, max: int}|null */
    private function role(string $prefix): ?array
    {
        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get("search.{$prefix}_provider"))));
        $model = trim((string) Settings::get("search.{$prefix}_model"));

        if ($provider === null || $model === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'in' => (float) Settings::get("search.{$prefix}_input_usd_per_million"),
            'out' => (float) Settings::get("search.{$prefix}_output_usd_per_million"),
            'max' => (int) Settings::get("search.{$prefix}_max_output_tokens"),
        ];
    }

    private static function word(mixed $value): ?string
    {
        $word = is_string($value) ? trim(mb_substr($value, 0, self::MAX_WORD)) : '';

        return $word === '' ? null : $word;
    }

    /**
     * @param  list<string|null>  $except
     * @return list<string>
     */
    private static function words(mixed $values, int $limit, array $except): array
    {
        $out = [];
        $taken = array_map(fn (?string $w): string => HebrewSearch::normalize($w), $except);

        foreach ((array) $values as $value) {
            $word = self::word($value);

            if ($word !== null && ! in_array(HebrewSearch::normalize($word), $taken, true)) {
                $out[] = $word;
                $taken[] = HebrewSearch::normalize($word);
            }
        }

        return array_slice($out, 0, $limit);
    }

    private static function sure(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.5;
    }
}
