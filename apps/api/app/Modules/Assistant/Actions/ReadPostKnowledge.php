<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * What the store's posts and articles say about the store itself (a service, a tool on the site,
 * how to order), kept with what the store says about itself for the assistant to answer from.
 *
 * 1. Code takes the store's latest posts. A post whose text and prompt are unchanged since it was
 *    read keeps what was read from it: never asked twice.
 * 2. One family writes down, from each new or changed post, the few things it states about the
 *    store; a model of the other family keeps only those the post states plainly.
 * 3. Code drops what holds a number with a currency, a phone, an email or a link, and saves the
 *    rest as one entry, each line with the post it came from. The team removes a line it does not
 *    want told, for good.
 *
 * At night, and when the team asks. Every call asks SpendGuard first and is accounted on the run.
 */
final class ReadPostKnowledge
{
    public const AGENT = 'assistant.post_reader';

    public const ACTION = 'assistant.read_posts';

    // v2: pages too, the store's policies first; a period ("14 days") is kept, a date is not.
    public const PROMPT_VERSION = 2;

    private const MAX_TEXT = 8000;

    private const MAX_FACTS = 6;

    private const MAX_FACT = 300;

    /** What a fact told to shoppers never holds: a sum, a phone, an email, a link. */
    private const NOT_TOLD = '/₪|\$|€|ש["״]ח|\bnis\b|\+?\d(?:[\s-]?\d){8,}|@|https?:\/\/|www\./iu';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
        private readonly BuildShopKnowledge $knowledge,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $shopId)),
        );
    }

    private function read(RunContext $run, string $shopId): void
    {
        if (! Features::enabled('assistant.knowledge', $shopId)) {
            $run->output(['skipped' => 'off'])->summary('assistant::runs.posts_off');

            return;
        }

        $writer = AgentModel::from('assistant.post_reader_provider', 'assistant.post_reader_model');
        $checker = AgentModel::from('assistant.post_check_provider', 'assistant.post_check_model');

        if ($writer->provider->family() === $checker->provider->family()) {
            $run->fail('assistant::runs.posts_same_family', ['family' => $writer->provider->label()]);

            return;
        }

        $before = (array) ($this->knowledge->entries()[BuildShopKnowledge::POSTS]->terms['read'] ?? []);
        // The store's policies first, then its other pages, then its newest posts: a night that
        // reads only a few reads what a shopper most needs to be told right.
        $pages = CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->orderBy('external_id')
            ->limit((int) Settings::get('assistant.post_knowledge_pages', $shopId))
            ->get(['id', 'type', 'external_id', 'title', 'body'])
            ->sortBy(fn (CatalogContent $page): array => [$page->importance(), (string) $page->external_id]);
        $posts = $pages->concat(CatalogContent::query()->active()->where('type', CatalogContent::POST)
            ->orderByDesc('updated_at')->orderBy('id')
            ->limit((int) Settings::get('assistant.post_knowledge_posts', $shopId))
            ->get(['id', 'type', 'external_id', 'title', 'body']))->values();
        $budget = (int) Settings::get('assistant.post_knowledge_per_run', $shopId);
        $shop = (string) (Shop::query()->whereKey($shopId)->value('name') ?? '');
        $read = [];
        $counts = ['posts' => $posts->count(), 'read' => 0, 'unchanged' => 0, 'waiting' => 0, 'facts' => 0];

        foreach ($posts as $post) {
            $id = (string) $post->id;
            $title = trim((string) $post->title);
            $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $post->body))), 0, self::MAX_TEXT);
            $fingerprint = hash('sha256', self::PROMPT_VERSION."\n".$title."\n".$text);

            if (($before[$id]['fingerprint'] ?? null) === $fingerprint) {
                $read[$id] = $before[$id];
                $counts['unchanged']++;
                $counts['facts'] += count($before[$id]['facts']);

                continue;
            }

            // A few posts a night; the rest wait, keeping what was read from them before.
            if ($counts['read'] >= $budget || $text === '') {
                if (isset($before[$id])) {
                    $read[$id] = $before[$id];
                }
                $counts['waiting'] += $text === '' ? 0 : 1;

                continue;
            }

            try {
                $facts = $this->facts($run, $writer, $checker, $shop, $post->isPolicy() ? 'policy' : (string) $post->type, $title, $text);
            } catch (SpendCapReached $e) {
                $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                return;
            } catch (ModelCallFailed $e) {
                $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                return;
            }

            $read[$id] = ['fingerprint' => $fingerprint, 'title' => $title, 'facts' => $facts];
            $counts['read']++;
            $counts['facts'] += count($facts);
        }

        $this->knowledge->posts($read);
        $run->output($counts)->summary('assistant::runs.posts_read', ['read' => (string) $counts['read'], 'posts' => (string) $counts['posts'], 'facts' => (string) $counts['facts']]);
    }

    /** @return list<string> what the post states about the store, as the checker kept it */
    private function facts(RunContext $run, AgentModel $writer, AgentModel $checker, string $shop, string $kind, string $title, string $text): array
    {
        $written = $this->call($run, $writer, 'post_reader', 'post_facts', ['shop' => $shop, 'kind' => $kind, 'title' => $title, 'text' => $text]);
        $facts = [];

        foreach (array_slice((array) ($written['facts'] ?? []), 0, self::MAX_FACTS) as $fact) {
            $fact = is_string($fact) ? trim((string) preg_replace('/\s+/u', ' ', $fact)) : '';

            // Code first: what holds a sum, a phone, an email or a link is never told.
            if (mb_strlen($fact) >= 8 && mb_strlen($fact) <= self::MAX_FACT && preg_match(self::NOT_TOLD, $fact) !== 1) {
                $facts['f'.(count($facts) + 1)] = $fact;
            }
        }

        if ($facts === []) {
            return [];
        }

        $checked = $this->call($run, $checker, 'post_check', 'post_facts_check', [
            'kind' => $kind,
            'title' => $title,
            'text' => $text,
            'facts' => array_map(fn (string $id, string $fact): array => ['id' => $id, 'text' => $fact], array_keys($facts), $facts),
        ]);
        $keep = array_map('strval', array_filter((array) ($checked['keep'] ?? []), 'is_scalar'));

        return array_values(array_intersect_key($facts, array_flip($keep)));
    }

    /**
     * One model call, its spend checked first and accounted after.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(RunContext $run, AgentModel $role, string $settings, string $prompt, array $input): array
    {
        $system = (string) file_get_contents(__DIR__.'/../Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.md');
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get("assistant.{$settings}_input_usd_per_million");
        $out = (float) Settings::get("assistant.{$settings}_output_usd_per_million");
        $maxOutput = (int) Settings::get("assistant.{$settings}_max_output_tokens");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
        $reply = $this->models->json($role->provider, $role->model, $system, $user, $maxOutput);
        $run->usage($role->provider->value, $role->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply->data;
    }
}
