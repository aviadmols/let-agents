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
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
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
 */
final class DraftPost
{
    public const AGENT = 'improvement.post_writer';

    public const ACTION = 'improvement.draft_post';

    private const PROMPT_VERSION = 'v1';

    private const PROMPTS = __DIR__.'/../Prompts/';

    private const CANDIDATES = 12;

    private const MAX_TITLE = 120;

    private const MAX_BODY = 12000;

    private const MAX_PROBLEMS = 6;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
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
                    'status' => ImprovementPost::DRAFT,
                    'checked' => $verdict['ok'],
                    'problems' => $verdict['problems'],
                    'written_by' => $writer['name'].' '.$writer['model'],
                    'checked_by' => $checker['name'].' '.$checker['model'],
                    'run_id' => $run->runId,
                    'published_at' => null,
                ]));

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
            'products' => $this->candidates($topic),
            'categories' => CatalogCategory::query()->where('product_count', '>', 0)->orderByDesc('product_count')->limit(40)->pluck('name')->map(fn ($n): string => (string) $n)->all(),
        ];
    }

    /**
     * The store's products the topic's words name, in their titles or their categories, the ones
     * naming more of the words first.
     *
     * @return list<array{id: string, title: string, category: string, about: string}>
     */
    private function candidates(string $topic): array
    {
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
        $products = CatalogProduct::query()->with('categories')->whereIn('id', array_slice(array_keys($scores), 0, self::CANDIDATES))->get();

        return array_values(array_map(fn (CatalogProduct $p): array => [
            'id' => (string) $p->external_id,
            'title' => (string) $p->title,
            'category' => (string) ($p->categories->first()?->name ?? ''),
            'about' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($p->description()))), 0, 200),
        ], array_values(array_filter(
            array_map(fn (string $id) => $products->firstWhere('id', $id), array_slice(array_keys($scores), 0, self::CANDIDATES)),
        ))));
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $writer
     * @param  array<string, mixed>  $input
     * @return array{title: string, body: string, products: list<string>}|null
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

        return $title === '' || $body === '' ? null : ['title' => $title, 'body' => $body, 'products' => $products];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $checker
     * @param  array<string, mixed>  $input
     * @param  array{title: string, body: string, products: list<string>}  $draft
     * @return array{ok: bool, problems: list<string>}
     */
    private function check(RunContext $run, array $checker, array $input, array $draft): array
    {
        $data = $this->call($run, $checker, 'auditor', $this->prompt('post_check'), ['topic' => $input['topic'], 'draft' => $draft, 'products' => $input['products']]);
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
