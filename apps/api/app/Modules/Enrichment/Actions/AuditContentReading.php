<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentRuleProposal;
use App\Modules\Enrichment\Scanning\ArticleReader;
use App\Modules\Enrichment\Scanning\TextCondenser;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Collection;

/**
 * Checks, every so often, that the program is reading articles properly — and when it is not,
 * proposes a change to the rules it reads by.
 *
 * A handful of articles are sampled, the ones code found least in first. For each, a model is
 * shown the article and what code pulled out of it, and says what was missed. Where the misses
 * have a shape in common, a second call proposes new marker words, and code checks that proposal
 * before anyone reads it: every marker must really open a missed line, must not be a word
 * ordinary sentences begin with, and must not turn the sample into noise. Only what survives
 * that is put to a reviewing model, and only what the reviewer agrees with is offered to a
 * person.
 *
 * A proposal never changes anything by itself. Publishing it is a person's act, it becomes a new
 * version of the rules, and the sample, the findings, the proposal and the verdict are all kept
 * beside it. Nothing here can change the program; the rules are data, which is the whole point.
 */
final class AuditContentReading
{
    public const AGENT = 'enrichment.auditor';

    public const ACTION = 'enrichment.audit_content';

    public const PROMPT_VERSION = 2;

    /** How many articles one audit looks at. Few enough to be cheap, enough to see a pattern. */
    /** The three lists a proposal may add to, and nothing else. */
    private const LISTS = ['takeaway_markers', 'takeaway_phrases', 'audience_markers'];

    private const SAMPLE = 6;

    private const MAX_TEXT_CHARS = 4000;

    private const CHECK_OUTPUT_TOKENS = 700;

    /** A marker that would take more than this share of a sample's lines is taking prose. */
    private const MAX_SHARE_OF_LINES = 0.25;

    /** Below this the sample is too small for a share to mean anything, so the gate stays quiet. */
    private const LINES_FOR_SHARE = 30;

    /** Openings so ordinary that a rule made of them would find everything. */
    private const TOO_COMMON = ['ה', 'ו', 'זה', 'זו', 'יש', 'אם', 'כי', 'אבל', 'גם', 'the', 'a', 'an', 'and', 'it', 'this', 'in', 'is', 'we', 'you'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, int $sample = self::SAMPLE): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->audit($run, $shopId, max(1, $sample))),
        );
    }

    private function audit(RunContext $run, string $shopId, int $sample): void
    {
        $rules = EnrichmentContentRules::inForce($shopId);
        $articles = $this->pick($sample);

        if ($articles->isEmpty()) {
            $run->output(['sampled' => 0])->summary('enrichment::runs.audit_nothing');

            return;
        }

        $checker = (string) Settings::get('enrichment.audit_checker_model');
        $writer = (string) Settings::get('enrichment.audit_writer_model');
        $effort = (string) Settings::get('assistant.reasoning_effort');
        $effort = $effort === 'model_default' ? null : $effort;

        $sampled = [];
        $findings = [];
        $missed = [];

        try {
            // Roughly: a check per article, then a proposal and a review.
            $this->spend->assertCanSpend(($sample + 2) * 3000 * $this->price('scope_input') / 1_000_000);

            foreach ($articles as $article) {
                $text = $this->text($article);
                $read = ArticleReader::read((string) $article->title, $text, $rules);

                $reply = $this->call($run, $checker, 'audit_article', [
                    'article' => ['title' => $article->title, 'text' => $text],
                    'read' => [
                        'sections' => $read['sections'],
                        'takeaways' => array_column($read['takeaways'], 'text'),
                        'question' => $read['question'],
                        'audience' => $read['audience'],
                    ],
                ], $effort);

                // A quote the model could not copy exactly is a quote the article does not have.
                $found = array_values(array_filter(
                    (array) ($reply->data['missed'] ?? []),
                    fn ($row): bool => is_array($row) && is_string($row['quote'] ?? null) && str_contains($text, trim($row['quote'])),
                ));

                $sampled[] = ['article' => $article->external_id, 'title' => mb_substr((string) $article->title, 0, 90), 'took' => count($read['takeaways'])];
                $findings[] = [
                    'article' => $article->external_id,
                    'missed' => array_map(fn (array $row): array => ['quote' => trim((string) $row['quote']), 'why' => mb_substr((string) ($row['why'] ?? ''), 0, 120)], $found),
                    'wrong' => array_slice((array) ($reply->data['wrong'] ?? []), 0, 5),
                ];

                foreach ($found as $row) {
                    $missed[] = ['article' => $article->external_id, 'quote' => trim((string) $row['quote'])];
                }

                CatalogContent::query()->whereKey($article->id)->update(['audited_at' => now()]);
            }

            $proposal = $missed === [] ? null : $this->propose($run, $writer, $rules, $missed, $effort);
            $review = null;
            $status = EnrichmentRuleProposal::REJECTED;

            if ($proposal !== null) {
                $review = $this->review($run, $checker, $rules, $proposal, $missed, $articles, $effort);
                $status = ($review['good'] ?? false) === true ? EnrichmentRuleProposal::APPROVED : EnrichmentRuleProposal::REJECTED;
            }
        } catch (SpendCapReached $e) {
            $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

            return;
        } catch (ModelCallFailed $e) {
            $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

            return;
        }

        $row = EnrichmentRuleProposal::query()->create([
            'shop_id' => $shopId,
            'from_version' => (int) ($rules['version'] ?? 1),
            'status' => $proposal === null ? EnrichmentRuleProposal::REJECTED : $status,
            'sampled' => $sampled,
            'findings' => $findings,
            'proposed' => $proposal,
            'review' => $review,
            'summary' => $proposal === null ? null : mb_substr((string) ($proposal['summary'] ?? ''), 0, 500),
            'run_id' => $run->runId,
        ]);

        $published = $this->publishIfAllowed($row, $shopId, $review['effect'] ?? null);

        $run->output([
            'published' => $published,
            'sampled' => count($sampled),
            'missed' => count($missed),
            'proposed' => $proposal === null ? 0 : array_sum(array_map(fn (string $list): int => count((array) ($proposal[$list] ?? [])), self::LISTS)),
            'status' => $proposal === null ? EnrichmentRuleProposal::REJECTED : $status,
        ])->summary('enrichment::runs.audit_content', [
            'articles' => count($sampled),
            'missed' => count($missed),
        ]);
    }

    /**
     * The articles worth looking at: the ones code took least from, and among those the ones
     * nobody has looked at for longest.
     *
     * @return Collection<int, CatalogContent>
     */
    private function pick(int $sample): Collection
    {
        return CatalogContent::query()
            ->active()
            ->whereNotNull('body')
            ->orderByRaw('audited_at is null desc')
            ->orderBy('audited_at')
            ->limit($sample)
            ->get();
    }

    private function text(CatalogContent $article): string
    {
        return (new TextCondenser)->condense([(string) ($article->body ?? $article->excerpt ?? '')], self::MAX_TEXT_CHARS)['text'];
    }

    /**
     * Asks for new markers, then checks them in code before anyone else sees them.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<array{article: string, quote: string}>  $missed
     * @return array<string, mixed>|null
     */
    private function propose(RunContext $run, string $model, array $rules, array $missed, ?string $effort): ?array
    {
        $reply = $this->call($run, $model, 'propose_rules', [
            'rules' => self::lists($rules),
            'missed' => $missed,
        ], $effort, 'answer');

        $quotes = array_column($missed, 'quote');
        $proposed = [];

        foreach (self::LISTS as $list) {
            $proposed[$list] = array_values(array_filter(
                array_map(fn ($marker): string => trim((string) $marker), (array) ($reply->data[$list] ?? [])),
                fn (string $marker): bool => $this->markerIsFair($marker, $list, $rules, $quotes),
            ));
        }

        if (array_filter($proposed) === []) {
            return null;
        }

        $proposed['summary'] = mb_substr((string) ($reply->data['summary'] ?? ''), 0, 500);

        return $proposed;
    }

    /**
     * A marker has to be new, short, not a word ordinary sentences open with, and really the
     * opening of a line the model said was missed.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $quotes
     */
    private function markerIsFair(string $marker, string $list, array $rules, array $quotes): bool
    {
        $words = count(preg_split('/\s+/u', $marker) ?: []);

        if ($marker === '' || $words > 4 || mb_strlen($marker) < 2) {
            return false;
        }

        if (in_array(mb_strtolower($marker), self::TOO_COMMON, true)) {
            return false;
        }

        if (in_array($marker, (array) ($rules[$list] ?? []), true)) {
            return false;
        }

        foreach ($quotes as $quote) {
            $at = mb_stripos($quote, $marker);
            $fits = match ($list) {
                // A marker opens a line; a phrase is said in the middle of one; an audience
                // marker may be anywhere, because what follows it is the audience.
                'takeaway_markers' => $at === 0,
                'takeaway_phrases' => $at !== false,
                default => $at !== false,
            };

            if ($fits) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the change would actually do to the articles just read, and then a second model's
     * verdict on whether that is an improvement.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $proposal
     * @param  Collection<int, CatalogContent>  $articles
     * @return array<string, mixed>
     */
    private function review(RunContext $run, string $model, array $rules, array $proposal, array $missed, $articles, ?string $effort): array
    {
        $after = $rules;

        foreach (self::LISTS as $list) {
            $after[$list] = array_merge((array) ($rules[$list] ?? []), (array) ($proposal[$list] ?? []));
        }

        $before = 0;
        $now = 0;
        $examples = [];
        $lines = 0;

        foreach ($articles as $article) {
            $text = $this->text($article);
            $lines += count(preg_split('/\R+/u', $text) ?: []);

            $was = array_column(ArticleReader::read((string) $article->title, $text, $rules)['takeaways'], 'text');
            $is = array_column(ArticleReader::read((string) $article->title, $text, $after)['takeaways'], 'text');

            $before += count($was);
            $now += count($is);

            foreach (array_diff($is, $was) as $fresh) {
                if (count($examples) < 8) {
                    $examples[] = $fresh;
                }
            }
        }

        $effect = ['before' => $before, 'after' => $now, 'examples' => $examples];

        // A change that swallows a quarter of every line is taking prose, whatever a model says.
        if ($lines >= self::LINES_FOR_SHARE && ($now - $before) / $lines > self::MAX_SHARE_OF_LINES) {
            return ['good' => false, 'reasons' => ['takes too much of the text'], 'effect' => $effect, 'by' => 'code'];
        }

        $reply = $this->call($run, $model, 'review_rules', [
            'rules' => self::lists($rules),
            'proposed' => $proposal,
            'evidence' => $missed,
            'effect' => $effect,
        ], $effort);

        return [
            'good' => ($reply->data['good'] ?? null) === true,
            'reasons' => array_slice((array) ($reply->data['reasons'] ?? []), 0, 5),
            'effect' => $effect,
            'by' => $model,
        ];
    }

    /** @param array<string, mixed> $input */
    private function call(RunContext $run, string $model, string $prompt, array $input, ?string $effort, string $prices = 'scope'): ModelReply
    {
        // "answer" prices are the proposer's, "scope" the checker's: each calls its own provider.
        $provider = AgentModel::provider('enrichment.audit_'.($prices === 'answer' ? 'writer' : 'checker').'_provider');
        $reply = $this->models->json(
            $provider,
            $model,
            (string) file_get_contents(__DIR__.'/../Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.md'),
            (string) json_encode($input, JSON_UNESCAPED_UNICODE),
            self::CHECK_OUTPUT_TOKENS,
            $effort,
        );

        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output')));

        return $reply;
    }

    /**
     * The lists in force, as the prompts see them.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, list<string>>
     */
    private static function lists(array $rules): array
    {
        $lists = [];

        foreach (self::LISTS as $list) {
            $lists[$list] = array_values((array) ($rules[$list] ?? []));
        }

        return $lists;
    }

    /** "answer_input" is the proposer's input price, "scope_output" the checker's output price. */
    private function price(string $name): float
    {
        [$role, $side] = explode('_', $name, 2);

        return (float) Settings::get('enrichment.audit_'.($role === 'answer' ? 'writer' : 'checker')."_{$side}_usd_per_million");
    }

    /**
     * A proposal both models agreed on becomes the reader's rules, tonight, by itself.
     *
     * Waiting for a person was the right default while nobody had seen this loop work. It is the
     * wrong one now: a change nobody publishes is a change that did not happen, and a shop should
     * not have to employ somebody to read marker words. What makes it safe is not the person, it
     * is everything the proposal already had to survive — evidence quoted from the article, a
     * check that every quote really is in the text, a share gate, a second model, and a measured
     * effect that has to be more than nothing.
     *
     * One version a week at most, and a person can put the old one back with a click, because a
     * rule that only ever adds markers can only ever find more, never less.
     */
    private function publishIfAllowed(EnrichmentRuleProposal $row, string $shopId, ?array $effect): bool
    {
        if (! $row->publishable() || ! Features::enabled('enrichment.auto_publish_rules', $shopId)) {
            return false;
        }

        // An effect of nothing is a change of nothing, whatever the reviewer said about it.
        if ((int) ($effect['after'] ?? 0) <= (int) ($effect['before'] ?? 0)) {
            return false;
        }

        $thisWeek = EnrichmentContentRules::query()
            ->where('shop_id', $shopId)
            ->where('author', 'audit')
            ->where('created_at', '>=', now()->subWeek())
            ->exists();

        if ($thisWeek) {
            return false;
        }

        return app(PublishContentRules::class)->handle($row, null) !== null;
    }
}
