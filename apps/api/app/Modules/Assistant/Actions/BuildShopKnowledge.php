<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Settings;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What a shop says about itself, kept as three entries the assistant reads before anything else:
 *
 *   - what the store prints on most product pages (refunds, warranty, layaway, shipping), read by
 *     code from the pages themselves, the most repeated first;
 *   - what the team wrote about the store, in their own words;
 *   - the answers the team gave to shoppers' questions.
 *
 * Each is a content row of the shop, so it is also cut into passages and found by meaning. No
 * model is called here. Inside the shop's tenant.
 */
final class BuildShopKnowledge
{
    public const REPEATED = CatalogContent::OWN_PREFIX.'knowledge-repeated';

    public const OWN = CatalogContent::OWN_PREFIX.'knowledge-own';

    public const TEAM = CatalogContent::OWN_PREFIX.'knowledge-team';

    /** How much of each entry is kept. */
    private const MAX_BODY = 12000;

    /**
     * Reads the shop's product pages and keeps what most of them repeat.
     *
     * @return array{tried: int, pages: int, sentences: int}
     */
    public function repeated(string $shopId): array
    {
        $urls = $this->sample((int) Settings::get('assistant.knowledge_pages', $shopId));
        $pages = [];

        foreach ($urls as $url) {
            try {
                $response = Http::withUserAgent('Mozilla/5.0 (compatible; LetsSearch/1.0)')->timeout(15)->get($url);

                if ($response->successful()) {
                    $pages[] = RepeatedText::sentences($response->body());
                }
            } catch (Throwable) {
                // One page that does not answer does not stop the others.
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

    /** What the team wrote about the store. Empty removes it. */
    public function own(string $text): void
    {
        $this->put(self::OWN, 'own', trim($text), []);
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

    /** @return array<string, CatalogContent> the shop's three entries that exist, by their id */
    public function entries(): array
    {
        return CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)
            ->whereIn('external_id', [self::REPEATED, self::OWN, self::TEAM])
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

    private function row(string $id): ?CatalogContent
    {
        return CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', $id)->first();
    }

    /** Writes an entry only when it changed, so the time it changed means something; empty removes it. */
    private function put(string $id, string $kind, string $body, array $terms): void
    {
        $row = $this->row($id);
        $body = mb_substr($body, 0, self::MAX_BODY);

        if ($body === '') {
            $row?->forceFill(['removed_at' => now(), 'body' => '', 'terms' => $terms])->save();

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
