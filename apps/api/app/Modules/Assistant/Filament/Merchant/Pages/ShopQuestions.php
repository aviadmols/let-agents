<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\WritesPosts;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions as OperatorShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What shoppers asked, as the designer's panel lays it out: every question with the answer it got and
 * the score the daily review gave it, filtered by how it ended. A question without an answer can be
 * answered by the team (with a draft from a model, or in their own words), turned into a post, or set
 * aside as not relevant; once the team answered or set it aside, it leaves the list. The shop is fixed.
 */
final class ShopQuestions extends OperatorShopQuestions
{
    use LocksShopToPanelTenant;

    private const DAYS = 30;

    private const PAGE = 30;

    protected static string|UnitEnum|null $navigationGroup = 'inbox';

    protected static ?int $navigationSort = 10;

    protected string $view = 'assistant::merchant.questions';

    /** all, open (no answer), answered, off (not about the store) */
    #[Url]
    public string $show = 'all';

    #[Url(as: 'find')]
    public string $find = '';

    /** The row being answered by hand. */
    public ?string $writing = null;

    public int $shown = self::PAGE;

    public function filter(string $show): void
    {
        $this->show = in_array($show, ['all', 'open', 'answered', 'off'], true) ? $show : 'all';
        $this->shown = self::PAGE;
    }

    public function more(): void
    {
        $this->shown += self::PAGE;
    }

    /**
     * Every question of the last weeks, the same one once: from the search window (grouped by its words)
     * and from product and article pages, with its answer, score and how many asked. What the team
     * already answered or set aside is not here.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $since = now()->subDays(self::DAYS);
            $handled = AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id')
                ->where(fn ($q) => $q->where('source', self::TEAM)->orWhere('status', AssistantAnswer::HIDDEN))
                ->pluck('question_key')->flip();
            $scores = [];

            foreach (AssistantAskReview::query()->where('day', '>=', $since->toDateString())->orderBy('day')->get(['items']) as $review) {
                foreach ((array) $review->items as $item) {
                    $scores[(string) $item['ask_id']] = $item;
                }
            }

            $rows = [];

            // The search window: each question once, with its latest answer and score.
            $asks = AssistantSearchAsk::query()->with('answer:id,answer,source,status')->where('created_at', '>=', $since)->latest('created_at')->orderByDesc('id')->limit(2000)->get();
            foreach ($asks->groupBy(fn (AssistantSearchAsk $a): string => Question::key((string) $a->question)) as $key => $group) {
                if (isset($handled[$key])) {
                    continue;
                }

                $latest = $group->first();
                $score = $group->map(fn (AssistantSearchAsk $a) => $scores[(string) $a->id] ?? null)->filter()->first();
                $rows[] = [
                    'id' => 's:'.$key,
                    'question' => (string) $latest->question,
                    'where' => __('assistant::ui.inbox.in_search'),
                    'asked' => $group->count(),
                    'last' => $latest->created_at,
                    'state' => self::state((string) $latest->outcome),
                    // A saved answer (the relation), else what was shown that time (the column of the same name).
                    'answer' => trim((string) ($latest->getRelation('answer')?->getAttribute('answer') ?? $latest->getAttributes()['answer'] ?? '')),
                    'score' => $score === null ? null : (int) $score['score'],
                    'note' => $score['note'] ?? null,
                ];
            }

            // Product and article pages.
            $answers = AssistantAnswer::query()->with(['product:id,title', 'content:id,title'])
                ->where(fn ($q) => $q->whereNotNull('product_id')->orWhereNotNull('content_id'))
                ->where('last_asked_at', '>=', $since)->where('status', AssistantAnswer::SHOWN)->where('source', '!=', self::TEAM)
                ->orderByDesc('last_asked_at')->limit(1000)->get();
            foreach ($answers as $answer) {
                $rows[] = [
                    'id' => 'a:'.$answer->id,
                    'question' => (string) $answer->question,
                    'where' => __('assistant::ui.inbox.on_page', ['page' => (string) ($answer->product?->title ?? $answer->content?->title ?? '')]),
                    'asked' => (int) $answer->asked_count,
                    'last' => $answer->last_asked_at,
                    'state' => self::state((string) $answer->outcome),
                    'answer' => trim((string) $answer->answer),
                    'score' => null,
                    'note' => null,
                ];
            }

            usort($rows, fn (array $a, array $b): int => [$b['state'] === 'open', $b['asked'], $b['last']?->getTimestamp() ?? 0] <=> [$a['state'] === 'open', $a['asked'], $a['last']?->getTimestamp() ?? 0]);

            return $rows;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{all: int, open: int, answered: int, off: int}
     */
    public function counts(array $rows): array
    {
        $by = array_count_values(array_column($rows, 'state'));

        return ['all' => count($rows), 'open' => $by['open'] ?? 0, 'answered' => $by['answered'] ?? 0, 'off' => $by['off'] ?? 0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function visible(array $rows): array
    {
        $words = mb_strtolower(trim($this->find));

        return array_values(array_filter($rows, fn (array $r): bool => ($this->show === 'all' || $r['state'] === $this->show)
            && ($words === '' || str_contains(mb_strtolower($r['question'].' '.$r['answer']), $words))));
    }

    /** A draft from a model, from the store's own text: the team reads it, fixes it and publishes. */
    public function draftFor(string $row): void
    {
        $id = $this->answerFor($row);

        if ($id !== null) {
            $this->writing = $row;
            $this->draft($id);
            $this->drafts[$row] = $this->drafts[$id] ?? '';
            $this->draftChecks[$row] = $this->draftChecks[$id] ?? [];
        }
    }

    public function writeOwn(string $row): void
    {
        $this->writing = $row;
        $this->drafts[$row] ??= '';
    }

    /** The team's answer: every next shopper gets it, and it joins the knowledge; the row leaves the list. */
    public function publish(string $row): void
    {
        $id = $this->answerFor($row);

        if ($id === null) {
            return;
        }

        $this->drafts[$id] = (string) ($this->drafts[$row] ?? '');
        $this->answer($id);
        unset($this->drafts[$row], $this->draftChecks[$row]);
        $this->writing = null;
    }

    /** Not relevant: not suggested again, asked again it stays aside; the row leaves the list. */
    public function setAside(string $row): void
    {
        $id = $this->answerFor($row);

        if ($id !== null) {
            $this->hide($id);
        }
    }

    /** A dedicated post on the question, drafted now and waiting for approval in the suggestions. */
    public function post(string $row): void
    {
        $question = collect($this->rows())->firstWhere('id', $row)['question'] ?? null;

        if ($question === null || $this->shop === null || ! app()->bound(WritesPosts::class)) {
            return;
        }

        $said = app(WritesPosts::class)->postFromQuestion($this->shop, (string) $question);
        Notification::make()->{$said === 'writing' ? 'success' : 'warning'}()->title(__('assistant::ui.inbox.post_'.$said))->send();
    }

    public function canPost(): bool
    {
        return app()->bound(WritesPosts::class);
    }

    public static function ago(?Carbon $at): string
    {
        return $at === null ? '' : $at->locale(app()->getLocale())->diffForHumans();
    }

    /**
     * The saved answer a row's actions work on: the page's own, or, for a question from the search
     * window, one made for it (no page, the question's key), so the team's answer is found again.
     */
    private function answerFor(string $row): ?string
    {
        if ($this->shop === null) {
            return null;
        }

        if (str_starts_with($row, 'a:')) {
            return substr($row, 2);
        }

        $key = substr($row, 2);
        $question = collect($this->rows())->firstWhere('id', $row);

        if ($question === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, fn (): string => (string) AssistantAnswer::query()->firstOrCreate(
            ['question_key' => $key, 'product_id' => null, 'content_id' => null],
            [
                'shop_id' => $this->shop, 'question' => mb_substr((string) $question['question'], 0, 500), 'outcome' => AssistantAnswer::NO_INFO,
                'source' => 'store', 'status' => AssistantAnswer::SHOWN, 'asked_count' => (int) $question['asked'],
                'last_asked_at' => $question['last'] ?? now(), 'prompt_version' => 1,
            ],
        )->id);
    }

    private static function state(string $outcome): string
    {
        return match ($outcome) {
            AssistantAnswer::ANSWERED => 'answered',
            AssistantAnswer::OUT_OF_SCOPE => 'off',
            default => 'open',
        };
    }
}
