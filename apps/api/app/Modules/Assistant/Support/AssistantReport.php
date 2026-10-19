<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Assistant\Actions\TeamAnswers;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The questions' part of the shop's analytics screen: how many were asked in the period (in the search
 * window and on product pages) and how they ended, and the questions asked most, each with whether the
 * store's own information answered it. Counts only.
 */
final class AssistantReport implements ShopReport
{
    private const ANSWERED = ['answered'];

    private const UNANSWERED = ['no_info', 'no_match'];

    private const OFF = ['out_of_scope'];

    /** The most asked questions a list reads, before what the team handled is taken out. */
    private const MOST = 2000;

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $search = AssistantSearchAsk::query()->where('day', '>=', $since->toDateString())->where('day', '<', $until->copy()->subSecond()->addDay()->toDateString())
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n): int => (int) $n);
        $pages = AssistantAnswer::query()->where(fn ($q) => $q->whereNotNull('product_id')->orWhereNotNull('content_id'))
            ->where('last_asked_at', '>=', $since)->where('last_asked_at', '<', $until)
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n): int => (int) $n);
        $sum = fn (array $outcomes): int => (int) collect($outcomes)->sum(fn (string $o): int => ($search[$o] ?? 0) + ($pages[$o] ?? 0));

        return ['questions' => [
            'all' => (int) ($search->sum() + $pages->sum()),
            'answered' => $sum(self::ANSWERED),
            'unanswered' => $sum(self::UNANSWERED),
            'off' => $sum(self::OFF),
        ], 'series' => ['questions' => $this->series($since, $until)]];
    }

    /**
     * Questions asked in the search box through the period, at the chart's scale (ReportSeries).
     *
     * @return array<string, int>
     */
    private function series(CarbonInterface $since, CarbonInterface $until): array
    {
        $format = ReportSeries::scale($since, $until)['format'];

        return AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)->get(['created_at'])
            ->countBy(fn (AssistantSearchAsk $ask): string => ReportSeries::key($ask->created_at, $format))->all();
    }

    public function lists(): array
    {
        return ['questions'];
    }

    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array
    {
        $asks = AssistantSearchAsk::query()->where('day', '>=', $since->toDateString())->where('day', '<', $until->copy()->subSecond()->addDay()->toDateString())
            ->select('question')->selectRaw('count(*) as n')->groupBy('question');

        if (($words = trim($search)) !== '') {
            $asks->where('question', 'like', '%'.$words.'%');
        }

        $sort === 'name' ? $asks->orderBy('question') : $asks->orderByDesc(DB::raw('count(*)'))->orderBy('question');

        // What the team already answered or set aside leaves the list.
        $handled = app(TeamAnswers::class)->handledKeys();
        $asked = $asks->limit(self::MOST)->get()->reject(fn ($q): bool => isset($handled[Question::key((string) $q->question)]))->values();
        $shown = $asked->take($limit);

        // Each question with the answer it got last time: the saved one, else what was shown then.
        $latest = AssistantSearchAsk::query()->with('answer:id,answer')->whereIn('question', $shown->pluck('question')->all())
            ->latest('created_at')->orderByDesc('id')->get(['id', 'question', 'outcome', 'answer', 'answer_id'])->unique('question')->keyBy('question');

        $rows = $shown->map(function ($q) use ($latest): ReportRow {
            $last = $latest[(string) $q->question] ?? null;
            $outcome = (string) $last?->outcome;
            [$pill, $tone] = match (true) {
                in_array($outcome, self::ANSWERED, true) => [__('assistant::report.answered'), 'good'],
                in_array($outcome, self::UNANSWERED, true) => [__('assistant::report.unanswered'), 'bad'],
                in_array($outcome, self::OFF, true) => [__('assistant::report.off'), ''],
                default => [null, ''],
            };
            $answer = trim((string) ($last?->getRelation('answer')?->getAttribute('answer') ?? $last?->getAttributes()['answer'] ?? ''));

            return new ReportRow(
                key: (string) $q->question, title: (string) $q->question, value: (int) $q->n, pill: $pill, tone: $tone,
                actions: in_array($outcome, self::OFF, true) ? ['answer'] : ['answer', 'aside'],
                detail: $answer,
                detailLabel: $answer === '' ? '' : __(in_array($outcome, self::ANSWERED, true) ? 'assistant::report.answer_given' : 'assistant::report.shown_instead'),
            );
        })->all();

        return ['rows' => $rows, 'total' => $asked->count()];
    }

    /** The team answers a question in their own words, or sets it aside; either way it leaves the list. */
    public function act(Shop $shop, string $list, string $key, string $action, string $value = ''): ?string
    {
        if ($list !== 'questions' || ! in_array($action, ['answer', 'aside'], true)) {
            return null;
        }

        $last = AssistantSearchAsk::query()->where('question', $key)->latest('created_at')->first(['question', 'created_at']);

        if ($last === null) {
            return null;
        }

        $team = app(TeamAnswers::class);
        $answer = $team->forSearchQuestion((string) $last->question, AssistantSearchAsk::query()->where('question', $key)->count(), $last->created_at);

        if ($action === 'aside') {
            $team->setAside($answer);

            return __('assistant::report.set_aside');
        }

        return match ($team->answer($answer, $value)) {
            'saved' => __('assistant::report.answer_saved'),
            'marked' => __('assistant::ui.questions.fill_marked'),
            default => null,
        };
    }
}
