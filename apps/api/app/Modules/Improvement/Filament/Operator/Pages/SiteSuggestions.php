<?php

namespace App\Modules\Improvement\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Improvement\Actions\DecideProposal;
use App\Modules\Improvement\Actions\RunDailyReview;
use App\Modules\Improvement\Jobs\DraftAnswerJob;
use App\Modules\Improvement\Jobs\DraftPostJob;
use App\Modules\Improvement\Jobs\SendPostJob;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementPostWrite;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What the daily review proposes for the site, waiting for the team: words shoppers use that the
 * store calls otherwise, questions worth answering on the site, and things shoppers look for that
 * the store does not have. Each shows the searches and questions behind it. A question comes with
 * a drafted answer the team edits and publishes; a missing topic gets a post written on request,
 * which the team edits and publishes as a guide of the store's own.
 */
class SiteSuggestions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'improvement/suggestions';

    protected string $view = 'improvement::operator.suggestions';

    #[Url]
    public ?string $shop = null;

    /** @var array<string, string> the answer or the post body as the team leaves it, by proposal */
    public array $drafts = [];

    /** @var array<string, string> the post's title as the team leaves it, by proposal */
    public array $titles = [];

    /** @var array<string, array{seo_title: string, meta_description: string}> the post's search-result title and description as the team leaves them */
    public array $seo = [];

    public static function getNavigationLabel(): string
    {
        return __('improvement::ui.title');
    }

    public function getTitle(): string
    {
        return __('improvement::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('improvement::ui.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array{pending: Collection<int, ImprovementProposal>, decided: Collection<int, ImprovementProposal>, latest: ImprovementReview|null}|null */
    public function suggestions(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        $s = app(TenantContext::class)->run($this->shop, fn (): array => [
            'pending' => ImprovementProposal::query()->with('post')->where('status', ImprovementProposal::PENDING)->latest()->orderBy('id')->get(),
            'decided' => ImprovementProposal::query()->with('post')->whereIn('status', [ImprovementProposal::APPLIED, ImprovementProposal::APPROVED, ImprovementProposal::REJECTED])->latest('updated_at')->limit(30)->get(),
            'latest' => ImprovementReview::query()->latest('day')->first(),
        ]);

        // The drafts as written, until the team edits them.
        foreach ($s['pending'] as $p) {
            if ($p->kind === ImprovementProposal::FAQ) {
                // While a model writes it, the box waits for the new answer rather than keeping the old one.
                if (! $this->drafting($p->id)) {
                    $this->drafts[$p->id] ??= (string) ($p->detail['answer'] ?? '');
                }
            } elseif ($p->post !== null) {
                $this->drafts[$p->id] ??= (string) $p->post->body;
                $this->titles[$p->id] ??= (string) $p->post->title;
                $this->seo[$p->id] ??= ['seo_title' => (string) ($p->post->seo['seo_title'] ?? ''), 'meta_description' => (string) ($p->post->seo['meta_description'] ?? '')];
            }
        }

        return $s;
    }

    /** The days before a decision and after it that its searches are compared over. */
    public const IMPACT_DAYS = 7;

    /**
     * Every proposal as a row of the list, waiting ones first: its priority among them (the third with
     * the most searches and questions behind it is high), what it rests on, and its impact once decided.
     *
     * @param  array{pending: Collection<int, ImprovementProposal>, decided: Collection<int, ImprovementProposal>, latest: ImprovementReview|null}  $s
     * @return list<array{p: ImprovementProposal, open: bool, demand: int, priority: string, summary: string, evidence: list<array{text: string, kind: string, count: int, results: int|null}>, impact: array<string, mixed>|null}>
     */
    public function rows(array $s): array
    {
        $rows = [];

        foreach ([[$s['pending'], true], [$s['decided'], false]] as [$list, $open]) {
            foreach ($list as $p) {
                $evidence = array_map(fn (array $e): array => [
                    'text' => (string) ($e['query'] ?? $e['question'] ?? ''),
                    'kind' => isset($e['question']) ? 'question' : (array_key_exists('results', $e) ? 'unclicked' : 'empty'),
                    'count' => (int) ($e['searches'] ?? $e['asked'] ?? 0),
                    'results' => isset($e['results']) ? (int) $e['results'] : (isset($e['question']) ? null : 0),
                ], array_values(array_filter((array) $p->evidence, 'is_array')));
                $rows[] = ['p' => $p, 'open' => $open, 'demand' => array_sum(array_column($evidence, 'count')), 'priority' => 'low', 'summary' => self::summaryOf($p), 'evidence' => $evidence,
                    'impact' => $open ? null : $this->impact($p, $evidence)];
            }
        }

        $demands = array_column($rows, 'demand');
        rsort($demands);
        $third = max(1, (int) ceil(count($demands) / 3));
        $high = $demands[$third - 1] ?? 0;
        $medium = $demands[min(count($demands), 2 * $third) - 1] ?? 0;

        foreach ($rows as &$row) {
            $row['priority'] = $row['demand'] >= $high && $row['demand'] > 0 ? 'high' : ($row['demand'] >= $medium && $row['demand'] > 0 ? 'medium' : 'low');
        }

        return $rows;
    }

    /** What the proposal says, in a line: the word and what it means, the question, why a topic is missing. */
    private static function summaryOf(ImprovementProposal $p): string
    {
        $d = (array) $p->detail;

        return trim(match ($p->kind) {
            ImprovementProposal::SYNONYM => __('improvement::ui.list.synonym_summary', ['term' => (string) ($d['term'] ?? ''), 'means' => (string) ($d['means'] ?? '')]),
            ImprovementProposal::FAQ => (string) ($d['question'] ?? $p->title),
            default => (string) ($d['why'] ?? $d['topic'] ?? ''),
        });
    }

    /**
     * What happened to the searches behind a decided proposal: the IMPACT_DAYS before it against the days
     * since (as many, at most), how often they were searched, found nothing, and were clicked. Null for a
     * proposal resting on questions only, or one decided today.
     *
     * @param  list<array{text: string, kind: string, count: int, results: int|null}>  $evidence
     * @return array{days: int, before: array{searches: int, empty: int, clicks: int}, after: array{searches: int, empty: int, clicks: int}}|null
     */
    private function impact(ImprovementProposal $p, array $evidence): ?array
    {
        $queries = array_values(array_unique(array_column(array_filter($evidence, fn (array $e): bool => $e['kind'] !== 'question' && $e['text'] !== ''), 'text')));
        if ($queries === [] || $p->decided_at === null || $this->shop === null) {
            return null;
        }

        $decided = $p->decided_at->copy()->startOfDay();
        $days = min(self::IMPACT_DAYS, (int) $decided->diffInDays(now()->startOfDay()));
        if ($days < 1) {
            return null;
        }

        $sum = fn (string $from, string $to): array => (array) app(TenantContext::class)->run($this->shop, fn () => SearchTerm::query()->whereIn('query', $queries)
            ->where('day', '>=', $from)->where('day', '<', $to)
            ->selectRaw('coalesce(sum(searches), 0) as searches, coalesce(sum(empty), 0) as empty, coalesce(sum(clicks), 0) as clicks')->first()?->toArray());

        return [
            'days' => $days,
            'before' => array_map('intval', $sum($decided->copy()->subDays($days)->toDateString(), $decided->toDateString()) + ['searches' => 0, 'empty' => 0, 'clicks' => 0]),
            'after' => array_map('intval', $sum($decided->toDateString(), $decided->copy()->addDays($days)->toDateString()) + ['searches' => 0, 'empty' => 0, 'clicks' => 0]),
        ];
    }

    /** Whether a post is being written for this proposal right now. */
    public function drafting(string $id): bool
    {
        return Cache::has(DraftPostJob::WRITING.$id) || Cache::has(DraftAnswerJob::WRITING.$id);
    }

    /** Asks a model for the answer to a question, from the store's own text, written in the background. */
    public function draftAnswer(string $id): void
    {
        if ($this->shop === null || $this->drafting($id)) {
            return;
        }

        $proposal = app(TenantContext::class)->run($this->shop, fn (): ?ImprovementProposal => ImprovementProposal::query()->whereKey($id)->where('kind', ImprovementProposal::FAQ)->first());

        if ($proposal === null) {
            return;
        }

        unset($this->drafts[$id]);
        Cache::put(DraftAnswerJob::WRITING.$id, now()->timestamp, now()->addSeconds(DraftAnswerJob::WRITING_SECONDS));
        DraftAnswerJob::dispatch($this->shop, $id);
        Notification::make()->success()->title(__('improvement::ui.answer.writing'))->send();
    }

    /** Asks for a post on a missing topic, written in the background; the screen shows it when it is there. */
    public function draftPost(string $id): void
    {
        if ($this->shop === null || $this->drafting($id)) {
            return;
        }

        $proposal = app(TenantContext::class)->run($this->shop, fn (): ?ImprovementProposal => ImprovementProposal::query()->whereKey($id)->where('kind', ImprovementProposal::CONTENT_GAP)->first());

        if ($proposal === null) {
            return;
        }

        if ($this->postsLeft()['left'] <= 0) {
            Notification::make()->warning()->title(__('improvement::ui.post.cap_reached'))->send();

            return;
        }

        unset($this->drafts[$id], $this->titles[$id], $this->seo[$id]);
        Cache::put(DraftPostJob::WRITING.$id, now()->timestamp, now()->addSeconds(DraftPostJob::WRITING_SECONDS));
        DraftPostJob::dispatch($this->shop, $id);
        Notification::make()->success()->title(__('improvement::ui.post.writing'))->send();
    }

    /**
     * A post that answers a question shoppers keep asking, with links to the store's products: a
     * new suggestion for a post, made from the question and written in the background. The question
     * stays, to be answered on the site too.
     */
    public function postFromQuestion(string $id): void
    {
        if ($this->shop === null) {
            return;
        }

        if ($this->postsLeft()['left'] <= 0) {
            Notification::make()->warning()->title(__('improvement::ui.post.cap_reached'))->send();

            return;
        }

        $post = app(TenantContext::class)->run($this->shop, function () use ($id): ?ImprovementProposal {
            $question = ImprovementProposal::query()->whereKey($id)->where('kind', ImprovementProposal::FAQ)->first();

            if ($question === null || isset($question->detail['post_proposal_id'])) {
                return null;
            }

            $text = trim((string) ($question->detail['question'] ?? $question->title));
            $post = ImprovementProposal::query()->create([
                'shop_id' => $question->shop_id,
                'review_id' => $question->review_id,
                'kind' => ImprovementProposal::CONTENT_GAP,
                'title' => mb_substr($text, 0, 300),
                'detail' => ['topic' => $text, 'why' => (string) __('improvement::ui.post.question_why'), 'question_id' => $question->id],
                'evidence' => $question->evidence,
                'fingerprint' => hash('sha256', ImprovementProposal::CONTENT_GAP.'|question:'.$question->id),
                'status' => ImprovementProposal::PENDING,
                'proposed_by' => 'team',
            ]);
            $question->detail = array_replace($question->detail, ['post_proposal_id' => $post->id]);
            $question->save();

            return $post;
        });

        if ($post === null) {
            return;
        }

        Cache::put(DraftPostJob::WRITING.$post->id, now()->timestamp, now()->addSeconds(DraftPostJob::WRITING_SECONDS));
        DraftPostJob::dispatch($this->shop, $post->id);
        Notification::make()->success()->title(__('improvement::ui.post.from_question_writing'))->send();
    }

    public function approve(string $id): void
    {
        $this->decide($id, true);
    }

    public function reject(string $id): void
    {
        $this->decide($id, false);
    }

    /** Runs today's review now, without waiting for the night. */
    public function reviewNow(): void
    {
        if ($this->shop === null) {
            return;
        }

        $run = app(RunDailyReview::class)->handle($this->shop, RunTrigger::Manual);

        $run->status->value === 'succeeded'
            ? Notification::make()->success()->title(__('improvement::ui.reviewed'))->body($run->summary())->send()
            : Notification::make()->danger()->title(__('improvement::ui.review_failed'))->send();
    }

    private function decide(string $id, bool $approve): void
    {
        if ($this->shop === null) {
            return;
        }

        $problem = app(TenantContext::class)->run($this->shop, function () use ($id, $approve): ?string {
            $proposal = ImprovementProposal::query()->with('post')->find($id);

            if ($proposal === null) {
                return null;
            }

            if (! $approve) {
                app(DecideProposal::class)->reject($proposal, auth()->id());

                return null;
            }

            return app(DecideProposal::class)->approve($proposal, auth()->id(), $this->drafts[$id] ?? null, $this->titles[$id] ?? null, $this->seo[$id] ?? null);
        });

        if ($problem !== null) {
            Notification::make()->warning()->title(__($problem))->send();

            return;
        }

        unset($this->drafts[$id], $this->titles[$id], $this->seo[$id]);
        Notification::make()->success()->title(__($approve ? 'improvement::ui.approved' : 'improvement::ui.rejected'))->send();
    }

    /**
     * The posts a model wrote for this shop this month, and how many more the operator allows.
     *
     * @return array{used: int, cap: int, left: int}
     */
    public function postsLeft(): array
    {
        if ($this->shop === null) {
            return ['used' => 0, 'cap' => 0, 'left' => 0];
        }

        $cap = (int) Settings::get('improvement.posts_per_month', $this->shop);
        $used = app(TenantContext::class)->run($this->shop, fn (): int => ImprovementPostWrite::thisMonth());

        return ['used' => $used, 'cap' => $cap, 'left' => max(0, $cap - $used)];
    }

    /** Whether a published post is on its way to the store's blog right now. */
    public function sending(ImprovementPost $post): bool
    {
        return Cache::has(SendPostJob::SENDING.$post->id);
    }

    /** Sends a published post to the store's blog again: after a failure, or to update its draft there. */
    public function resendPost(string $proposalId): void
    {
        if ($this->shop === null) {
            return;
        }

        $post = app(TenantContext::class)->run($this->shop, fn (): ?ImprovementPost => ImprovementPost::query()->where('proposal_id', $proposalId)->where('status', ImprovementPost::PUBLISHED)->first());

        if ($post !== null && ! $this->sending($post)) {
            DecideProposal::send($post->shop_id, $post->id);
            Notification::make()->success()->title(__('improvement::ui.post.sending'))->send();
        }
    }

    /** Where a published post is read. */
    public function guideUrl(ImprovementPost $post): string
    {
        return route('improvement.guide', ['shop' => Shop::query()->whereKey($post->shop_id)->value('slug'), 'post' => $post->id]);
    }
}
