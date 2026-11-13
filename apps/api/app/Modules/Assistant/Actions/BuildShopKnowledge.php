<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Settings;
use App\Core\Http\OutboundUrl;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantIgnoredSource;
use App\Modules\Assistant\Support\Dated;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Contracts\StorefrontLock;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\RefreshesVectors;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
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
 *
 * A storefront closed to visitors (Shopify's password page, a maintenance page: StorefrontLock)
 * shows the same lock page at every address; its words are never the store's. The reading stops at
 * the first, says so on a run, and clears what an earlier reading took from lock pages.
 */
final class BuildShopKnowledge
{
    public const AGENT = 'assistant.page_reader';

    public const ACTION = 'assistant.read_repeated';

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

    /** A long text the team adds, each an entry of its own, found by meaning only (addText). */
    public const TEXT_PREFIX = CatalogContent::OWN_PREFIX.'knowledge-text-';

    /** How much of one long text is kept, and of its title. */
    public const MAX_TEXT = 30000;

    public const MAX_TEXT_TITLE = 120;

    /** The lock the last page answered with (StorefrontLock), or null. */
    private ?string $lock = null;

    public function __construct(private readonly RecordsRuns $runs) {}

    /**
     * Reads the shop's product pages and keeps what most of them repeat.
     *
     * @return array{tried: int, pages: int, sentences: int, locked?: string}
     */
    public function repeated(string $shopId): array
    {
        $urls = $this->sample((int) Settings::get('assistant.knowledge_pages', $shopId));
        $pages = [];
        $locked = null;

        foreach ($urls as $at => $url) {
            // A page at a time, a moment apart: stores (Shopify above all) refuse a burst from one address.
            if ($at > 0) {
                usleep(app()->runningUnitTests() ? 0 : self::PAUSE_MS * 1000);
            }

            $html = $this->page($url);

            // Every address of a locked storefront is the same lock page: nothing more to read.
            if (StorefrontLock::wide($this->lock)) {
                $locked = $this->lock;

                break;
            }

            if ($html !== null) {
                $pages[] = RepeatedText::sentences($html);
            }
        }

        if ($locked !== null) {
            return $this->locked($shopId, $locked, count($urls));
        }

        if ($pages !== []) {
            StorefrontLock::open($shopId);
        }

        if (count($pages) < 3) {
            $this->clearLockReading();

            return ['tried' => count($urls), 'pages' => count($pages), 'sentences' => 0];
        }

        $row = $this->row(self::REPEATED);
        $hidden = (array) ($row?->terms['hidden'] ?? []);
        // a banner's line of what is still to come on a day already gone ("המשלוח הבא יוצא ב־15.9") is
        // no longer the store's word: left out, as the team would hide it
        $found = array_values(array_filter(
            RepeatedText::repeated($pages, (float) Settings::get('assistant.knowledge_share')),
            // and only what tells of the store's service: never a heading, a button or a line of its screens
            fn (array $s): bool => ! in_array(RepeatedText::key($s['text']), $hidden, true) && ! Dated::past($s['text']) && ! StorefrontLock::text($s['text']) && RepeatedText::fact($s['text']),
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

    /**
     * The storefront is locked: nothing read, an earlier reading of its lock pages cleared, and a run
     * that says so (the reading is otherwise quiet).
     *
     * @return array{tried: int, pages: int, sentences: int, locked: string}
     */
    private function locked(string $shopId, string $kind, int $tried): array
    {
        StorefrontLock::seen($shopId, $kind);
        $cleared = $this->clearLockReading();
        // The model casts the platform to its enum: compared to the plain string, every Shopify store read as WooCommerce.
        $platform = Shop::query()->whereKey($shopId)->first(['id', 'platform'])?->platform ?? ShopPlatform::WooCommerce;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::System,
            work: fn (RunContext $run) => $run->output(['tried' => $tried, 'locked' => $kind, 'cleared' => $cleared])
                ->summary('assistant::runs.knowledge_locked', ['platform' => $platform->label(), 'cleared' => (string) $cleared]),
        );

        return ['tried' => $tried, 'pages' => 0, 'sentences' => 0, 'locked' => $kind];
    }

    /**
     * What an earlier reading took from lock pages ("Enter store password", repeated on every page of a
     * locked store) removed: the whole reading, since every page it read was the lock page. The team's
     * hidden sentences stay hidden. A reading of the open store is left as it is. Returns the lines removed.
     */
    private function clearLockReading(): int
    {
        $row = $this->row(self::REPEATED);

        if ($row === null || $row->removed_at !== null) {
            return 0;
        }

        $lines = array_values(array_filter(explode("\n", (string) $row->body), fn (string $line): bool => trim($line) !== ''));

        if (array_filter($lines, fn (string $line): bool => StorefrontLock::text($line)) === []) {
            return 0;
        }

        $this->put(self::REPEATED, 'repeated', '', ['hidden' => (array) ($row->terms['hidden'] ?? [])]);

        return count($lines);
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
     * A long text the team wrote (a guide, a policy, the story of the store): an entry of its own, never read whole
     * before an answer as the team's lines are, but cut into passages, given vectors and found by meaning like a page
     * of the site, its pieces that speak of a question brought to the writer (as Aviad asked, 11.11). The vectors are
     * made a few minutes later (RefreshesVectors). Null when there is no text.
     */
    public function addText(string $title, string $body): ?string
    {
        $id = self::TEXT_PREFIX.Str::lower((string) Str::ulid());

        return $this->putText($id, $title, $body) ? $id : null;
    }

    /** A long text the team changed: cut and given vectors again. Empty removes it. */
    public function editText(string $id, string $title, string $body): void
    {
        if (! str_starts_with($id, self::TEXT_PREFIX) || $this->row($id) === null) {
            return;
        }

        if (trim($body) === '') {
            $this->removeText($id);

            return;
        }

        $this->putText($id, $title, $body);
    }

    /** A long text taken away: its passages leave with it at the next refresh. */
    public function removeText(string $id): void
    {
        $row = str_starts_with($id, self::TEXT_PREFIX) ? $this->row($id) : null;

        if ($row !== null && $row->removed_at === null) {
            $row->forceFill(['removed_at' => now(), 'body' => '', 'hash' => hash('sha256', '')])->save();
            app(RefreshesVectors::class)->soon((string) app(TenantContext::class)->id());
        }
    }

    /**
     * The team's long texts, newest first.
     *
     * @return list<array{id: string, content_id: string, title: string, body: string, at: string|null, changed: string|null}>
     */
    public function texts(): array
    {
        return CatalogContent::query()->active()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', 'like', self::TEXT_PREFIX.'%')
            ->orderByDesc('created_at')->orderByDesc('external_id')->get(['id', 'external_id', 'title', 'body', 'created_at', 'updated_at'])
            ->map(fn (CatalogContent $row): array => [
                'id' => (string) $row->external_id,
                'content_id' => (string) $row->id,
                'title' => (string) $row->title,
                'body' => (string) $row->body,
                'at' => $row->created_at?->toIso8601String(),
                'changed' => $row->updated_at?->toIso8601String(),
            ])->values()->all();
    }

    private function putText(string $id, string $title, string $body): bool
    {
        // the text as written, its paragraphs kept; a title of its own, or its first words
        $body = trim(mb_substr(str_replace(["\r\n", "\r"], "\n", $body), 0, self::MAX_TEXT));
        $body = (string) preg_replace("/\n{3,}/u", "\n\n", $body);

        if ($body === '') {
            return false;
        }

        $title = trim(mb_substr(trim($title), 0, self::MAX_TEXT_TITLE));
        $title = $title !== '' ? $title : Str::limit((string) preg_replace('/\s+/u', ' ', $body), self::MAX_TEXT_TITLE - 10, '…');
        $hash = hash('sha256', $title."\n".$body);
        $row = $this->row($id);

        if ($row === null) {
            CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => $id, 'title' => $title, 'body' => $body, 'terms' => [], 'hash' => $hash, 'synced_at' => now()]);
        } elseif ($row->hash !== $hash || $row->removed_at !== null) {
            $row->forceFill(['title' => $title, 'body' => $body, 'hash' => $hash, 'removed_at' => null, 'synced_at' => now()])->save();
        } else {
            return true;
        }

        app(RefreshesVectors::class)->soon((string) app(TenantContext::class)->id());

        return true;
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
        $ignored = AssistantIgnoredSource::contentIds();
        $lines = [];

        foreach ($read as $contentId => $post) {
            // a page or post the team stopped: none of what was read from it
            if (in_array((string) $contentId, $ignored, true)) {
                continue;
            }
            foreach ($post['facts'] as $fact) {
                if (! in_array(RepeatedText::key($fact), $hidden, true) && RepeatedText::useful($fact)) {
                    $lines[] = '- '.$fact.' ('.$post['title'].')';
                }
            }
        }

        // Asked for by the team (a post's link on the knowledge screen): waits until it is read.
        $wanted = array_values(array_diff(array_map('strval', (array) ($this->row(self::POSTS)?->terms['wanted'] ?? [])), array_map('strval', array_keys($read))));

        // each fact once, whichever pages said it (the terms and the payment page both tell the 30 days to exchange)
        $this->put(self::POSTS, 'posts', implode("\n", RepeatedText::distinct(array_values(array_unique($lines)))), ['read' => $read, 'hidden' => $hidden, 'wanted' => $wanted, 'read_at' => now()->toIso8601String()]);
    }

    /**
     * A page or post of the store's site the team stopped (the "why" of an answer, Sella 12.11: a 2024 post on the book
     * week's offers): never a passage again, and the facts read from it leave what the posts say. Kept until brought back.
     */
    public function ignore(string $contentId, ?int $by = null): void
    {
        $content = CatalogContent::query()->whereKey($contentId)->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])->first();
        if ($content === null) {
            return;
        }

        AssistantIgnoredSource::query()->updateOrCreate(['content_id' => (string) $content->id],
            ['title' => mb_substr(trim((string) $content->title), 0, 300), 'url' => $content->url === null ? null : mb_substr((string) $content->url, 0, 1000), 'created_by' => $by]);
        $this->rereadPosts();
    }

    /** A page or post the team stopped, brought back: a passage again, and what was read from it with the posts. */
    public function unignore(string $contentId): void
    {
        AssistantIgnoredSource::query()->where('content_id', $contentId)->delete();
        $this->rereadPosts();
    }

    /** What the posts say, made again from what was read, as it stands now. */
    private function rereadPosts(): void
    {
        $row = $this->row(self::POSTS);
        if ($row !== null) {
            $this->posts((array) ($row->terms['read'] ?? []));
        }
    }

    /** A page or post read again even though it did not change: what it taught stays until the new reading replaces it. */
    public function forget(string $contentId): void
    {
        $row = $this->row(self::POSTS);
        $terms = (array) ($row?->terms ?? []);

        if ($row === null || ! isset($terms['read'][$contentId])) {
            return;
        }

        $terms['read'][$contentId]['fingerprint'] = '';
        $row->forceFill(['terms' => $terms])->save();
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
        $this->lock = null;

        foreach ([0, self::RETRY_MS] as $wait) {
            usleep(app()->runningUnitTests() ? 0 : $wait * 1000);

            try {
                $response = OutboundUrl::request($url)?->timeout(15)->connectTimeout(5)->withHeaders(['Accept' => 'text/html'])->get($url);
            } catch (Throwable) {
                return null;
            }
            if ($response === null) {
                return null;
            }

            // Shopify's password page, a maintenance page (a 503 too): no page of the store.
            if (($this->lock = StorefrontLock::of($response)) !== null) {
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
