<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Connections\Contracts\StoreEditLinks;
use App\Modules\Connections\Models\StoreConnection;

/**
 * Where exactly a source says what it says, so the team fixes it where it is written: the line the team added, the
 * answer the team gave, the sentence the product pages repeat, what a post was read as saying, or the page itself,
 * with where the store owner edits it on their own site. A source is a page an answer was written from, or a passage
 * the check of a line found saying otherwise; $near is what it says (the words kept with the answer, the passage) or,
 * with nothing kept, the answer. Code only: the lines nearest in their words. Inside the shop's tenant.
 */
final class SourceLines
{
    public const OWN = 'own';

    public const TEAM = 'team';

    public const REPEATED = 'repeated';

    public const POST_FACT = 'post_fact';

    public const PAGE = 'page';

    /** Lines shown for one source, at most. */
    private const MAX_LINES = 3;

    /** The share of a line's words that must be in what the source says, for a line not said whole. */
    private const NEAR_SHARE = 0.6;

    /**
     * @param  array{title: string, url?: string|null}  $source
     * @param  list<string>  $exceptOwn  the team's lines left out (the one being checked: it never contradicts itself)
     * @return list<array{kind: string, key: string, text: string, label: string|null, url: string|null, edit_url: string|null}>
     */
    public static function of(string $shopId, array $source, string $near, array $exceptOwn = []): array
    {
        $title = trim($source['title']);
        $url = isset($source['url']) && is_string($source['url']) && $source['url'] !== '' ? $source['url'] : null;
        $entries = app(BuildShopKnowledge::class)->entries();
        $entry = null;

        foreach ($entries as $id => $content) {
            if ($url === null && trim((string) $content->title) === $title) {
                $entry = $id;
            }
        }

        $lines = match ($entry) {
            BuildShopKnowledge::OWN => self::own($near, $exceptOwn),
            BuildShopKnowledge::TEAM => self::team($near),
            BuildShopKnowledge::REPEATED => self::repeated((string) $entries[$entry]->body, $near),
            BuildShopKnowledge::POSTS => self::postFacts($shopId, (array) ($entries[$entry]->terms ?? []), $near),
            default => [],
        };

        if ($entry !== null) {
            return $lines;
        }

        $page = CatalogContent::query()->active()->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])
            ->where(fn ($q) => $url !== null ? $q->where('url', $url) : $q->where('title', $title))
            ->first(['id', 'type', 'external_id', 'title', 'url']);

        return $page === null ? [] : [self::line(self::PAGE, (string) $page->id, trim((string) $page->title), null, $page->url, self::editUrl($shopId, $page))];
    }

    /**
     * Where the store owner edits a page or a post on their own site, or null.
     */
    public static function editUrl(string $shopId, CatalogContent $content): ?string
    {
        $connection = StoreConnection::query()->where('shop_id', $shopId)->first();

        if ($connection === null || ! app()->bound(StoreEditLinks::class)) {
            return null;
        }

        $type = str_starts_with((string) $content->external_id, 'policy-') ? 'policy' : (string) $content->type;

        return app(StoreEditLinks::class)->editUrl($connection, $type, (string) $content->external_id);
    }

    /**
     * @param  list<string>  $except
     * @return list<array<string, mixed>>
     */
    private static function own(string $near, array $except): array
    {
        $rows = array_values(array_filter(app(BuildShopKnowledge::class)->ownRows(), fn (array $r): bool => ! in_array($r['id'], $except, true)));

        return array_map(fn (array $r): array => self::line(self::OWN, $r['id'], $r['text']), self::nearest($rows, $near, fn (array $r): string => $r['text']));
    }

    /** @return list<array<string, mixed>> */
    private static function team(string $near): array
    {
        $answers = AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id')->where('source', 'team')
            ->where('status', AssistantAnswer::SHOWN)->whereNotNull('answer')->orderByDesc('asked_count')->orderBy('id')
            ->get(['id', 'question', 'answer'])->all();

        return array_map(fn (AssistantAnswer $a): array => self::line(self::TEAM, (string) $a->id, trim((string) $a->answer), trim((string) $a->question)),
            self::nearest($answers, $near, fn (AssistantAnswer $a): string => (string) $a->answer));
    }

    /** @return list<array<string, mixed>> */
    private static function repeated(string $body, string $near): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body))));

        return array_map(fn (string $s): array => self::line(self::REPEATED, $s, $s), self::nearest($lines, $near, fn (string $s): string => $s));
    }

    /**
     * What the posts were read as saying, each with its post: a fact the team removes is never told again.
     *
     * @param  array<string, mixed>  $terms
     * @return list<array<string, mixed>>
     */
    private static function postFacts(string $shopId, array $terms, string $near): array
    {
        $hidden = (array) ($terms['hidden'] ?? []);
        $facts = [];

        foreach ((array) ($terms['read'] ?? []) as $contentId => $post) {
            foreach ((array) ($post['facts'] ?? []) as $fact) {
                if (! in_array(RepeatedText::key((string) $fact), $hidden, true)) {
                    $facts[] = ['post' => (string) $contentId, 'title' => (string) ($post['title'] ?? ''), 'fact' => (string) $fact];
                }
            }
        }

        $found = self::nearest($facts, $near, fn (array $f): string => $f['fact']);
        $posts = CatalogContent::query()->whereIn('id', array_column($found, 'post'))->get(['id', 'type', 'external_id', 'title', 'url'])->keyBy('id');

        return array_map(function (array $f) use ($posts, $shopId): array {
            $post = $posts[$f['post']] ?? null;

            return self::line(self::POST_FACT, $f['fact'], $f['fact'], $f['title'], $post?->url, $post === null ? null : self::editUrl($shopId, $post));
        }, $found);
    }

    /**
     * The items whose words what the source says holds: whole first, then by the share of their words it holds, at least
     * NEAR_SHARE; at most MAX_LINES, in the order they came.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $text
     * @return list<T>
     */
    private static function nearest(array $items, string $near, callable $text): array
    {
        $hay = self::plain($near);

        if ($hay === '') {
            return [];
        }

        $scored = [];
        foreach ($items as $i => $item) {
            $line = self::plain($text($item));
            $words = array_values(array_unique(array_filter(explode(' ', $line), fn (string $w): bool => mb_strlen($w) >= 3 || preg_match('/\d/', $w) === 1)));

            if ($line === '' || $words === []) {
                continue;
            }

            $share = mb_strlen($line) >= 8 && str_contains($hay, $line) ? 2.0
                : count(array_filter($words, fn (string $w): bool => str_contains($hay, $w))) / count($words);

            if ($share >= self::NEAR_SHARE) {
                $scored[$i] = $share;
            }
        }

        arsort($scored);
        $best = array_slice(array_keys($scored), 0, self::MAX_LINES);
        sort($best);

        return array_map(fn (int $i) => $items[$i], $best);
    }

    /** Lower case, no list mark, final letters as the others, one space. */
    private static function plain(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);
        $text = (string) preg_replace('/^\s*[-•*]\s*/mu', '', $text);

        return trim((string) preg_replace('/[^\p{L}\p{N}%.:]+/u', ' ', $text));
    }

    /** @return array{kind: string, key: string, text: string, label: string|null, url: string|null, edit_url: string|null} */
    private static function line(string $kind, string $key, string $text, ?string $label = null, ?string $url = null, ?string $editUrl = null): array
    {
        return ['kind' => $kind, 'key' => $key, 'text' => $text, 'label' => $label, 'url' => $url, 'edit_url' => $editUrl];
    }
}
