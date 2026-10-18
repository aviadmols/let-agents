<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Settings;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a shop says about itself, kept as entries the assistant reads before anything else:
 *
 *   - what the store prints on most product pages (refunds, warranty, layaway, shipping), read by
 *     code from the pages themselves, the most repeated first;
 *   - what the team wrote about the store, in their own words;
 *   - the answers the team gave to shoppers' questions;
 *   - what the store's posts and articles say about the store (read by ReadPostKnowledge).
 *
 * Each is a content row of the shop, so it is also cut into passages and found by meaning. No
 * model is called here. Inside the shop's tenant.
 */
final class BuildShopKnowledge
{
    public const REPEATED = CatalogContent::OWN_PREFIX.'knowledge-repeated';

    public const OWN = CatalogContent::OWN_PREFIX.'knowledge-own';

    public const TEAM = CatalogContent::OWN_PREFIX.'knowledge-team';

    public const POSTS = CatalogContent::OWN_PREFIX.'knowledge-posts';

    /** The pause between two pages, and before the one try again after "too many requests". */
    private const PAUSE_MS = 1000;

    private const RETRY_MS = 4000;

    /** How much of each entry is kept. */
    private const MAX_BODY = 12000;

    /** One line the team adds. */
    public const MAX_OWN = 600;

    /**
     * Reads the shop's product pages and keeps what most of them repeat.
     *
     * @return array{tried: int, pages: int, sentences: int}
     */
    public function repeated(string $shopId): array
    {
        $urls = $this->sample((int) Settings::get('assistant.knowledge_pages', $shopId));
        $pages = [];

        foreach ($urls as $at => $url) {
            // A page at a time, a moment apart: stores (Shopify above all) refuse a burst from one address.
            if ($at > 0) {
                usleep(app()->runningUnitTests() ? 0 : self::PAUSE_MS * 1000);
            }

            if (($html = $this->page($url)) !== null) {
                $pages[] = RepeatedText::sentences($html);
            }
        }

        if (count($pages) < 3) {
            return ['tried' => count($urls), 'pages' => count($pages), 'sentences' => 0];
        }

        $row = $this->row(self::REPEATED);
        $hidden = (array) ($row?->terms['hidden'] ?? []);
        $found = array_values(array_filter(
            RepeatedText::repeated($pages, (float) Settings::get('assistant.knowledge_share')),
            fn (array $s): bool => ! in_array(RepeatedText::key($s['text']), $hidden, true),
        ));

        $this->put(self::REPEATED, 'repeated', implode("\n", array_map(fn (array $s): string => '- '.$s['text'], $found)), [
            'hidden' => $hidden,
            'pages' => count($pages),
            // On how many of the pages read each sentence was, and how many products there were:
            // the screen tells "on about N product pages" from these, with no reading of its own.
            'counts' => array_combine(array_map(fn (array $s): string => RepeatedText::key($s['text']), $found), array_column($found, 'pages')) ?: [],
            'products' => CatalogProduct::query()->active()->count(),
            'read_at' => now()->toIso8601String(),
        ]);

        return ['tried' => count($urls), 'pages' => count($pages), 'sentences' => count($found)];
    }

    /** A sentence the team does not want read as the store's: left out now and at every later reading. */
    public function hide(string $sentence): void
    {
        $row = $this->row(self::REPEATED);

        if ($row === null) {
            return;
        }

        $key = RepeatedText::key($sentence);
        $terms = (array) $row->terms;
        $terms['hidden'] = array_values(array_unique([...(array) ($terms['hidden'] ?? []), $key]));
        $lines = array_filter(explode("\n", (string) $row->body), fn (string $line): bool => RepeatedText::key($line) !== $key);

        $this->put(self::REPEATED, 'repeated', implode("\n", $lines), $terms);
    }

    /** What the team wrote about the store, as one text: a row a line. Empty removes it all. */
    public function own(string $text): void
    {
        $rows = [];

        foreach (preg_split('/\n+/u', trim($text)) ?: [] as $line) {
            $line = trim((string) preg_replace('/^[-•*]\s*/u', '', $line));

            if ($line !== '') {
                $rows[] = ['id' => (string) Str::ulid(), 'text' => mb_substr($line, 0, self::MAX_OWN), 'at' => now()->toIso8601String(), 'check' => null];
            }
        }

        $this->putOwn($rows);
    }

    /**
     * What the team added, a row each, newest first: its text, when, and what the check against
     * the site found (null while it waits, or when it was never checked).
     *
     * @return list<array{id: string, text: string, at: string|null, check: array{status: string, notes: list<array{title: string, why: string}>, at: string}|null}>
     */
    public function ownRows(): array
    {
        $row = $this->row(self::OWN);

        if ($row === null || $row->removed_at !== null) {
            return [];
        }

        $rows = (array) ($row->terms['rows'] ?? []);

        // Written before rows: the whole text is one row.
        if ($rows === [] && trim((string) $row->body) !== '') {
            $rows = [['id' => 'own-'.substr(hash('sha256', (string) $row->body), 0, 12), 'text' => trim((string) $row->body), 'at' => $row->updated_at?->toIso8601String(), 'check' => null]];
        }

        return array_values(array_map(fn (array $r): array => [
            'id' => (string) $r['id'], 'text' => (string) $r['text'], 'at' => $r['at'] ?? null, 'check' => $r['check'] ?? null,
        ], $rows));
    }

    /** A line the team adds, the newest first. Returns its id. */
    public function addOwn(string $text): ?string
    {
        $text = trim(mb_substr(trim($text), 0, self::MAX_OWN));

        if ($text === '') {
            return null;
        }

        $id = (string) Str::ulid();
        $this->putOwn([['id' => $id, 'text' => $text, 'at' => now()->toIso8601String(), 'check' => null], ...$this->ownRows()]);

        return $id;
    }

    /** A line the team changed: checked again against the site. Empty removes it. */
    public function editOwn(string $id, string $text): void
    {
        $text = trim(mb_substr(trim($text), 0, self::MAX_OWN));

        if ($text === '') {
            $this->removeOwn($id);

            return;
        }

        $this->putOwn(array_map(fn (array $r): array => $r['id'] === $id && $r['text'] !== $text ? [...$r, 'text' => $text, 'check' => null] : $r, $this->ownRows()));
    }

    public function removeOwn(string $id): void
    {
        $this->putOwn(array_values(array_filter($this->ownRows(), fn (array $r): bool => $r['id'] !== $id)));
    }

    /**
     * What the check against the site found for one line, kept only while the line is the one checked.
     *
     * @param  array{status: string, notes: list<array{title: string, why: string}>}  $check
     */
    public function checked(string $id, string $text, array $check): void
    {
        $this->putOwn(array_map(fn (array $r): array => $r['id'] === $id && $r['text'] === $text ? [...$r, 'check' => [...$check, 'at' => now()->toIso8601String()]] : $r, $this->ownRows()));
    }

    /** @param list<array<string, mixed>> $rows */
    private function putOwn(array $rows): void
    {
        // The assistant reads the body: a line each, as the team wrote them.
        $this->put(self::OWN, 'own', implode("\n", array_map(fn (array $r): string => '- '.$r['text'], $rows)), ['rows' => array_values($rows)]);
    }

    /**
     * What the store's posts say about the store, a line each with the post it came from; the
     * ones the team removed are left out, now and at every later reading.
     *
     * @param  array<string, array{fingerprint: string, title: string, facts: list<string>}>  $read  by content id
     */
    public function posts(array $read): void
    {
        $hidden = (array) ($this->row(self::POSTS)?->terms['hidden'] ?? []);
        $lines = [];

        foreach ($read as $post) {
            foreach ($post['facts'] as $fact) {
                if (! in_array(RepeatedText::key($fact), $hidden, true)) {
                    $lines[] = '- '.$fact.' ('.$post['title'].')';
                }
            }
        }

        // Asked for by the team (a post's link on the knowledge screen): waits until it is read.
        $wanted = array_values(array_diff(array_map('strval', (array) ($this->row(self::POSTS)?->terms['wanted'] ?? [])), array_map('strval', array_keys($read))));

        $this->put(self::POSTS, 'posts', implode("\n", array_values(array_unique($lines))), ['read' => $read, 'hidden' => $hidden, 'wanted' => $wanted, 'read_at' => now()->toIso8601String()]);
    }

    /** A page or post the team wants read: first at the next reading, whatever its age. */
    public function want(string $contentId): void
    {
        $row = $this->row(self::POSTS);
        $terms = (array) ($row?->terms ?? []);
        $terms['wanted'] = array_values(array_unique([...array_map('strval', (array) ($terms['wanted'] ?? [])), $contentId]));

        if ($row === null) {
            CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => self::POSTS, 'title' => '', 'body' => '', 'terms' => $terms, 'hash' => hash('sha256', ''), 'removed_at' => now(), 'synced_at' => now()]);

            return;
        }

        $row->forceFill(['terms' => $terms])->save();
    }

    /** @return list<string> the pages and posts the team asked to read, not read yet */
    public function wanted(): array
    {
        return array_values(array_map('strval', (array) ($this->row(self::POSTS)?->terms['wanted'] ?? [])));
    }

    /** A fact from a post the team does not want told: left out now and at every later reading. */
    public function hidePostFact(string $fact): void
    {
        $row = $this->row(self::POSTS);

        if ($row === null) {
            return;
        }

        $terms = (array) $row->terms;
        $terms['hidden'] = array_values(array_unique([...(array) ($terms['hidden'] ?? []), RepeatedText::key($fact)]));
        $row->forceFill(['terms' => $terms])->save();
        $this->posts((array) ($terms['read'] ?? []));
    }

    /** The team's answers to shoppers, one question and answer after another. */
    public function team(): void
    {
        $pairs = AssistantAnswer::query()
            ->whereNull('product_id')->whereNull('content_id')
            ->where('source', 'team')->where('status', AssistantAnswer::SHOWN)->whereNotNull('answer')
            ->orderByDesc('asked_count')->orderBy('id')
            ->get(['question', 'answer'])
            ->map(fn (AssistantAnswer $a): string => __('assistant::ui.knowledge.pair', ['question' => trim((string) $a->question), 'answer' => trim((string) $a->answer)], self::locale((string) $a->question)))
            ->all();

        $this->put(self::TEAM, 'team', implode("\n\n", $pairs), []);
    }

    /** @return array<string, CatalogContent> the shop's entries that exist, by their id */
    public function entries(): array
    {
        return CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)
            ->whereIn('external_id', [self::REPEATED, self::OWN, self::TEAM, self::POSTS])
            ->get()->keyBy('external_id')->all();
    }

    /**
     * Product pages spread over the catalogue, all on the shop's own address.
     *
     * @return list<string>
     */
    private function sample(int $wanted): array
    {
        $urls = CatalogProduct::query()->active()->whereNotNull('url')->orderBy('id')->pluck('url')
            ->filter(fn ($url): bool => is_string($url) && preg_match('#^https?://#i', $url) === 1)
            ->values();

        if ($urls->isEmpty()) {
            return [];
        }

        // The address most products share is the store's; a product linking elsewhere is not read.
        $host = $urls->map(fn (string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)))->countBy()->sortDesc()->keys()->first();

        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $urls = $urls->filter(fn (string $url): bool => strtolower((string) parse_url($url, PHP_URL_HOST)) === $host)->values();
        $step = max(1, intdiv($urls->count(), max(1, $wanted)));

        return $urls->filter(fn (string $url, int $at): bool => $at % $step === 0)->take($wanted)->values()->all();
    }

    /** A product page as the store shows it, or null when it does not answer. */
    private function page(string $url): ?string
    {
        foreach ([0, self::RETRY_MS] as $wait) {
            usleep(app()->runningUnitTests() ? 0 : $wait * 1000);

            try {
                $response = Http::timeout(15)->connectTimeout(5)->withHeaders(['Accept' => 'text/html'])->get($url);
            } catch (Throwable) {
                return null;
            }

            if ($response->successful()) {
                return $response->body();
            }

            if (! in_array($response->status(), [429, 430, 503], true)) {
                return null;
            }
        }

        return null;
    }

    private function row(string $id): ?CatalogContent
    {
        return CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', $id)->first();
    }

    /**
     * Writes an entry only when it changed, so the time it changed means something; empty removes
     * it, and keeps its terms: what was read and said nothing is not read and paid for again.
     */
    private function put(string $id, string $kind, string $body, array $terms): void
    {
        $row = $this->row($id);
        $body = mb_substr($body, 0, self::MAX_BODY);

        if ($body === '') {
            if ($row !== null) {
                $row->forceFill(['removed_at' => now(), 'body' => '', 'terms' => $terms])->save();
            } elseif (($terms['read'] ?? []) !== []) {
                // Pages read that said nothing yet: kept, removed, so they are not read again tomorrow.
                CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => $id, 'title' => '', 'body' => '', 'terms' => $terms, 'hash' => hash('sha256', ''), 'removed_at' => now(), 'synced_at' => now()]);
            }

            return;
        }

        $hash = hash('sha256', $body);
        $values = [
            'title' => (string) __('assistant::ui.knowledge.titles.'.$kind, [], self::locale($body)),
            'body' => $body,
            'terms' => $terms,
            'hash' => $hash,
            'removed_at' => null,
            'synced_at' => now(),
        ];

        if ($row === null) {
            CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => $id, ...$values]);

            return;
        }

        $changed = $row->hash !== $hash || $row->removed_at !== null;
        $row->forceFill($values);
        // An unchanged entry keeps its time, so answers that waited for news are not asked again.
        $row->timestamps = $changed;
        $row->save();
    }

    /** Hebrew when the text is, so the entry's title reads like the store. */
    private static function locale(string $text): string
    {
        return preg_match('/\p{Hebrew}/u', $text) === 1 ? 'he' : 'en';
    }
}
