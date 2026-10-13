<?php

namespace App\Modules\Improvement\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementPostWrite;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\Tenancy\Models\Shop;

/**
 * A draft post for something shoppers look for and the store has no page about: the team
 * presses a button, a model writes it, a model of the other family checks it, and the draft
 * waits on the suggestions screen for the team to edit and publish. Nothing reaches shoppers
 * before the team says so.
 *
 * Code before model: code picks the store's products the topic may be about (by its words in
 * their titles and categories) and hands them with ids; the writer may name only those, and
 * code keeps only ids from that list. The checker's findings are shown to the team, not acted
 * on: a draft with problems is still the team's to fix.
 *
 * A post is written for the store's own blog: with its SEO title and description, focus keyword,
 * excerpt, tags and questions with answers (prompt v2). Links to the products and their pictures
 * are added by code (PostDocument), never by the model. Each store writes at most the number of
 * posts a month the operator set (improvement.posts_per_month), rewrites included.
 */
final class DraftPost
{
    public const AGENT = 'improvement.post_writer';

    public const ACTION = 'improvement.draft_post';

    private const PROMPT_VERSION = 'v2';

    private const PROMPTS = __DIR__.'/../Prompts/';

    private const CANDIDATES = 12;

    private const MAX_TITLE = 120;

    private const MAX_BODY = 20000;

    private const MAX_FAQ = 6;

    private const MAX_TAGS = 8;

    /** Facts of each candidate product the writer gets, so it describes them as the store does. */
    private const MAX_FACTS = 8;

    private const MAX_PROBLEMS = 6;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
        private readonly ProductsForQuery $search,
    ) {}

    /** The draft, or null when no model could write one; the run says why. */
    public function handle(ImprovementProposal $proposal, RunTrigger $trigger = RunTrigger::Manual): ?ImprovementPost
    {
        $post = null;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $proposal->shop_id,
            trigger: $trigger,
            work: function (RunContext $run) use ($proposal, &$post): void {
                $writer = $this->role('analyst');
                $checker = $this->role('auditor');

                if ($writer['provider'] === null || $checker['provider'] === null) {
                    $run->fail('improvement::runs.post_no_provider');

                    return;
                }

                if ($writer['provider']->family() === $checker['provider']->family()) {
                    $run->fail('improvement::runs.same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                $cap = (int) Settings::get('improvement.posts_per_month', $proposal->shop_id);

                if ($this->tenant->run($proposal->shop_id, fn (): int => ImprovementPostWrite::thisMonth()) >= $cap) {
                    $run->fail('improvement::runs.post_cap', ['cap' => (string) $cap]);

                    return;
                }

                $input = $this->tenant->run($proposal->shop_id, fn (): array => $this->input($proposal));

                try {
                    $draft = $this->write($run, $writer, $input);

                    if ($draft === null) {
                        $run->fail('improvement::runs.post_empty');

                        return;
                    }

                    $verdict = $this->check($run, $checker, $input, $draft);
                } catch (SpendCapReached) {
                    $run->fail('improvement::runs.post_spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('improvement::runs.post_failed', ['reason' => $e->reason]);

                    return;
                }

                $post = $this->tenant->run($proposal->shop_id, fn (): ImprovementPost => ImprovementPost::query()->updateOrCreate(['proposal_id' => $proposal->id], [
                    'shop_id' => $proposal->shop_id,
                    'title' => $draft['title'],
                    'body' => $draft['body'],
                    'products' => $draft['products'],
                    'seo' => $draft['seo'],
                    'status' => ImprovementPost::DRAFT,
                    'checked' => $verdict['ok'],
                    'problems' => $verdict['problems'],
                    'written_by' => $writer['name'].' '.$writer['model'],
                    'checked_by' => $checker['name'].' '.$checker['model'],
                    'run_id' => $run->runId,
                    'published_at' => null,
                ]));
                $this->tenant->run($proposal->shop_id, fn () => ImprovementPostWrite::query()->create(['shop_id' => $proposal->shop_id, 'proposal_id' => $proposal->id, 'run_id' => $run->runId, 'created_at' => now()]));

                $run->output(['title' => $draft['title'], 'products' => count($draft['products']), 'problems' => $verdict['problems']])
                    ->summary('improvement::runs.post_drafted', ['title' => $draft['title'], 'problems' => (string) count($verdict['problems'])]);
            },
        );

        return $post;
    }

    /**
     * What the writer gets: the topic, what shoppers typed, and the store's products that may
     * belong in the post, with ids. Inside the shop's tenant.
     *
     * @return array<string, mixed>
     */
    private function input(ImprovementProposal $proposal): array
    {
        $topic = (string) ($proposal->detail['topic'] ?? $proposal->title);

        return [
            'shop' => (string) (Shop::query()->find($proposal->shop_id)?->name ?? ''),
            'topic' => $topic,
            'why' => (string) ($proposal->detail['why'] ?? ''),
            'searches' => array_values(array_filter(array_map(
                fn (array $e): string => trim((string) ($e['query'] ?? $e['question'] ?? '')).' ×'.(int) ($e['searches'] ?? $e['asked'] ?? 0),
                $proposal->evidence,
            ), fn (string $s): bool => ! str_starts_with($s, ' ×'))),
            'products' => $this->candidates($topic, $proposal->shop_id),
            'categories' => CatalogCategory::query()->where('product_count', '>', 0)->orderByDesc('product_count')->limit(40)->pluck('name')->map(fn ($n): string => (string) $n)->all(),
        ];
    }

    /**
     * The products the store's own search finds for the topic ("איזה עץ הכי כדאי לפרגולה" finds
     * the pergola wood, as a shopper would), then those the topic's words name in their titles or
     * their categories, the ones naming more of the words first.
     *
     * @return list<array{id: string, title: string, category: string, about: string}>
     */
    private function candidates(string $topic, string $shopId): array
    {
        $searched = $this->search->find($shopId, $topic, self::CANDIDATES);
        $byExternal = CatalogProduct::query()->whereIn('external_id', $searched)->pluck('id', 'external_id');
        $first = array_values(array_filter(array_map(fn (string $id): ?string => isset($byExternal[$id]) ? (string) $byExternal[$id] : null, $searched)));
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));
        $scores = [];

        foreach ($words as $word) {
            $found = CatalogProduct::query()->active()
                ->where(fn ($q) => $q->where('title', 'like', "%{$word}%")->orWhereHas('categories', fn ($c) => $c->where('name', 'like', "%{$word}%")))
                ->limit(200)->pluck('id');

            foreach ($found as $id) {
                $scores[(string) $id] = ($scores[(string) $id] ?? 0) + 1;
            }
        }

        arsort($scores);
        $ids = array_slice(array_values(array_unique([...$first, ...array_map('strval', array_keys($scores))])), 0, self::CANDIDATES);
        $products = CatalogProduct::query()->with('categories')->whereIn('id', $ids)->get();

        $facts = EnrichmentFact::query()->with('vocabulary')->whereIn('product_id', $products->pluck('id'))->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Brand, FactKind::Choice, FactKind::Spec, FactKind::Flag])->orderBy('id')->get()->groupBy('product_id');

        return array_values(array_map(fn (CatalogProduct $p): array => [
            'id' => (string) $p->external_id,
            'title' => (string) $p->title,
            'category' => (string) ($p->categories->first()?->name ?? ''),
            'facts' => $facts->get($p->id, collect())->take(self::MAX_FACTS)->map(fn (EnrichmentFact $f): string => trim($f->keyLabel()).': '.trim($f->valueLabel()))->values()->all(),
            'about' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($p->description()))), 0, 200),
        ], array_values(array_filter(
            array_map(fn (string $id) => $products->first(fn (CatalogProduct $p): bool => (string) $p->id === $id), $ids),
        ))));
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $writer
     * @param  array<string, mixed>  $input
     * @return array{title: string, body: string, products: list<string>, seo: array<string, mixed>}|null
     */
    private function write(RunContext $run, array $writer, array $input): ?array
    {
        $data = $this->call($run, $writer, 'analyst', $this->prompt('post'), $input);
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, self::MAX_TITLE);
        $body = mb_substr(trim((string) ($data['body'] ?? '')), 0, self::MAX_BODY);
        $allowed = array_column($input['products'], 'id');
        $products = [];

        foreach ((array) ($data['products'] ?? []) as $id) {
            if (in_array((string) $id, $allowed, true) && ! in_array((string) $id, $products, true)) {
                $products[] = (string) $id;
            }
        }

        if ($title === '' || $body === '') {
            return null;
        }

        $line = fn (string $key, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($data[$key] ?? ''))), 0, $max);
        $faq = [];
        foreach ((array) ($data['faq'] ?? []) as $item) {
            $q = is_array($item) ? mb_substr(trim((string) ($item['q'] ?? '')), 0, 200) : '';
            $a = is_array($item) ? mb_substr(trim((string) ($item['a'] ?? '')), 0, 700) : '';
            if ($q !== '' && $a !== '') {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }
        $tags = array_values(array_unique(array_filter(array_map(fn ($t): string => mb_substr(trim((string) $t), 0, 40), (array) ($data['tags'] ?? [])))));

        return ['title' => $title, 'body' => $body, 'products' => $products, 'seo' => [
            'seo_title' => $line('seo_title', 70),
            'meta_description' => $line('meta_description', 170),
            'focus_keyword' => $line('focus_keyword', 80),
            'excerpt' => $line('excerpt', 240),
            'tags' => array_slice($tags, 0, self::MAX_TAGS),
            'faq' => array_slice($faq, 0, self::MAX_FAQ),
        ]];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $checker
     * @param  array<string, mixed>  $input
     * @param  array{title: string, body: string, products: list<string>, seo: array<string, mixed>}  $draft
     * @return array{ok: bool, problems: list<string>}
     */
    private function check(RunContext $run, array $checker, array $input, array $draft): array
    {
        $data = $this->call($run, $checker, 'auditor', $this->prompt('post_check'), ['topic' => $input['topic'], 'draft' => ['title' => $draft['title'], 'seo_title' => $draft['seo']['seo_title'], 'meta_description' => $draft['seo']['meta_description'], 'body' => $draft['body'], 'faq' => $draft['seo']['faq'], 'products' => $draft['products']], 'products' => $input['products']]);
        $problems = [];

        foreach ((array) ($data['problems'] ?? []) as $problem) {
            if (is_string($problem) && trim($problem) !== '') {
                $problems[] = mb_substr(trim($problem), 0, 300);
            }
        }

        $problems = array_slice($problems, 0, self::MAX_PROBLEMS);

        return ['ok' => ($data['ok'] ?? false) === true && $problems === [], 'problems' => $problems];
    }

    /**
     * One model call, its spend checked first and accounted after.
     *
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(RunContext $run, array $role, string $prices, string $system, array $input): array
    {
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get("improvement.{$prices}_input_usd_per_million");
        $out = (float) Settings::get("improvement.{$prices}_output_usd_per_million");
        $maxOutput = (int) Settings::get("improvement.{$prices}_max_output_tokens");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
        $reply = $this->chat->json($role['provider'], $role['model'], $system, $user, $maxOutput);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply->data;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("improvement.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("improvement.{$name}_model"))];
    }

    private function prompt(string $name): string
    {
        return (string) file_get_contents(self::PROMPTS.$name.'.'.self::PROMPT_VERSION.'.md');
    }
}
