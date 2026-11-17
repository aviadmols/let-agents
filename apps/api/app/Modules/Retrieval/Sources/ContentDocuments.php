<?php

namespace App\Modules\Retrieval\Sources;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SourceDocument;
use App\Modules\Retrieval\Enums\ChunkSource;

/** Every page and post the store shares and still publishes: title, terms, excerpt, body and its takeaways. */
final class ContentDocuments implements DocumentSource
{
    public function key(): string
    {
        return ChunkSource::Content->value;
    }

    public function documents(string $shopId): iterable
    {
        // content its owner reads line by line itself is never found by meaning (CatalogContent::QUIET_PREFIX)
        foreach (CatalogContent::query()->active()->where('external_id', 'not like', CatalogContent::QUIET_PREFIX.'%')->orderBy('id')->lazy(100)->chunk(100) as $batch) {
            // What the reading took from each post (its takeaways), after its own text.
            $notes = ProductDocuments::notes(ChunkSource::Content, $batch->map(fn (CatalogContent $c): string => (string) $c->id)->values()->all());

            foreach ($batch as $content) {
                yield $this->document($content, $notes[(string) $content->id] ?? []);
            }
        }
    }

    /** @param  list<string>  $notes */
    private function document(CatalogContent $content, array $notes): SourceDocument
    {
        $lines = [];

        // A page's or a post's categories and tags; what the store knows about itself keeps its reading's own state in
        // `terms` ("Read: …", "Read_at: …"), never words of the store: they were found by meaning as if they were (16.11)
        foreach ($content->type === CatalogContent::KNOWLEDGE ? [] : (array) $content->terms as $taxonomy => $terms) {
            // A term as a word, or as the store sends it whole ({id, name}): its name.
            $terms = array_filter(array_map(fn ($term): string => is_array($term) ? (string) ($term['name'] ?? $term['title'] ?? '') : (is_scalar($term) ? (string) $term : ''), (array) $terms));

            if ($terms !== []) {
                $lines[] = ucfirst((string) $taxonomy).': '.implode(', ', $terms);
            }
        }

        // the excerpt only when it says something the body does not: an excerpt made of the body's first words, cut short
        // ("…"), stood before the body in the same piece, the same text twice (Rocks, 16.11: 13 of 72 pieces of its posts)
        if (! self::repeatsBody((string) $content->excerpt, (string) $content->body)) {
            $lines[] = (string) $content->excerpt;
        }
        $lines[] = (string) $content->body;
        // a takeaway that is a sentence of the body word for word ("Highlight: …") says nothing the body does not, and
        // stood twice in the same piece (Miss Nori, 16.11: four of its returns page's six). Measured 17.11: recall the same, 5% less text, the needed pieces further above the rest.
        array_push($lines, ...(config('retrieval.notes_once') !== false ? array_values(array_filter($notes, fn (string $note): bool => ! self::inBody($note, (string) $content->body))) : $notes));

        return new SourceDocument(
            $content->id,
            $content->external_id,
            $content->title,
            implode("\n", array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '')),
        );
    }

    /** Whether a takeaway ("Label: text") is, less its label, a sentence the body already holds whole. */
    public static function inBody(string $note, string $body): bool
    {
        $plain = fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5)));
        $text = $plain((string) preg_replace('/^[\p{L} ]{1,30}:\s*/u', '', $note));

        return mb_strlen($text) >= 20 && mb_strpos($plain($body), $text) !== false;
    }

    /** Whether an excerpt is only the body's opening words: its text, less a closing "…" or "[…]", is in the body. */
    public static function repeatsBody(string $excerpt, string $body): bool
    {
        $plain = fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5)));
        $excerpt = trim((string) preg_replace('/\s*(?:\[\s*(?:…|\.\.\.)\s*\]|…|\.\.\.)\s*$/u', '', $plain($excerpt)));
        if ($excerpt === '') {
            return true;
        }
        // its first words are enough: an excerpt cut in a word holds a last word the body has whole
        $head = mb_substr($excerpt, 0, min(120, max(20, mb_strlen($excerpt) - 15)));

        return mb_strlen($head) >= 20 && mb_strpos($plain($body), $head) !== false;
    }
}
