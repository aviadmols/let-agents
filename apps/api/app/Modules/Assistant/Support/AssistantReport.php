<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Assistant\Actions\TeamAnswers;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The questions' part of the shop's analytics screen: how many were asked in the period (in the search
 * window and on product pages, the home's count: QuestionCounts) and how they ended, and the questions
 * asked most in the search window, each with whether the store's own information answered it. Counts only.
 */
final class AssistantReport implements ShopReport
{
    private const ANSWERED = QuestionCounts::ANSWERED;

    private const UNANSWERED = QuestionCounts::UNANSWERED;

    private const OFF = QuestionCounts::OFF;

    /** The most asked questions a list reads, before what the team handled is taken out. */
    private const MOST = 2000;

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        return ['questions' => QuestionCounts::between($since, $until), 'series' => ['questions' => $this->series($since, $until)]];
    }

    /**
     * Questions asked through the period, at the chart's scale (ReportSeries), counted as the figure is
     * (QuestionCounts): each in the search window when it was asked, each on a page when it was last asked.
     *
     * @return array<string, int>
     */
    private function series(CarbonInterface $since, CarbonInterface $until): array
    {
        $format = ReportSeries::scale($since, $until)['format'];
        $search = AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)->pluck('created_at');
        $pages = AssistantAnswer::query()->where(fn ($q) => $q->whereNotNull('product_id')->orWhereNotNull('content_id'))
            ->where('last_asked_at', '>=', $since)->where('last_asked_at', '<', $until)->pluck('last_asked_at');

        return $search->concat($pages)->countBy(fn ($at): string => ReportSeries::key($at, $format))->all();
    }

    public function lists(): array
    {
        return ['questions', 'asked'];
    }

    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array
    {
        if ($list === 'asked') {
            return $this->asked($since, $until, $limit, $search);
        }

        // the same period, by the same clock, as the count above (QuestionCounts)
        $asks = AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->select('question')->selectRaw('count(*) as n, max(created_at) as last_at')->groupBy('question');

        if (($words = trim($search)) !== '') {
            $asks->where('question', 'like', '%'.$words.'%');
        }

        // by count, by name, or by when it was last asked, the latest first
        match ($sort) {
            'name' => $asks->orderBy('question'),
            'date' => $asks->orderByDesc(DB::raw('max(created_at)'))->orderBy('question'),
            default => $asks->orderByDesc(DB::raw('count(*)'))->orderBy('question'),
        };

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

            $at = $q->last_at === null ? null : Carbon::parse((string) $q->last_at)->timezone(config('app.display_timezone', 'Asia/Jerusalem'));

            return new ReportRow(
                key: (string) $q->question, title: (string) $q->question, value: (int) $q->n, pill: $pill, tone: $tone,
                // when it was last asked: what the list is ordered by, when ordered by date
                sub: $at === null ? '' : __('assistant::report.last_asked', ['date' => $at->format('d.m.Y H:i')]),
                actions: in_array($outcome, self::OFF, true) ? ['answer'] : ['answer', 'aside'],
                detail: $answer,
                detailLabel: $answer === '' ? '' : __(in_array($outcome, self::ANSWERED, true) ? 'assistant::report.answer_given' : 'assistant::report.shown_instead'),
            );
        })->all();

        return ['rows' => $rows, 'total' => $asked->count()];
    }

    /**
     * Every question asked in the search window through the period, the latest first, under the day it was asked: each
     * with its hour, how it ended, the answer the shopper read, and the products they were shown, those they opened or
     * added marked; WhatsApp when it was offered. What the shopper got, as they got it.
     *
     * @return array{rows: list<ReportRow>, total: int}
     */
    private function asked(CarbonInterface $since, CarbonInterface $until, int $limit, string $search): array
    {
        $asks = AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->when(trim($search) !== '', fn ($q) => $q->where('question', 'like', '%'.trim($search).'%'));
        $total = (clone $asks)->count();
        $shown = $asks->with('answer:id,answer')->latest('created_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'question', 'outcome', 'answer', 'answer_id', 'products', 'picks', 'picked', 'whatsapp_shown', 'whatsapp_clicked', 'created_at']);

        // the products shown by their names, as the store has them now
        $ids = $shown->flatMap(fn (AssistantSearchAsk $a): array => array_map('strval', (array) $a->products))->unique()->values()->all();
        $titles = $ids === [] ? collect() : CatalogProduct::query()->whereIn('external_id', $ids)->pluck('title', 'external_id');
        $zone = (string) config('app.display_timezone', 'Asia/Jerusalem');

        $rows = $shown->map(function (AssistantSearchAsk $a) use ($titles, $zone): ReportRow {
            $outcome = (string) $a->outcome;
            [$pill, $tone] = match (true) {
                in_array($outcome, self::ANSWERED, true) => [__('assistant::report.answered'), 'good'],
                in_array($outcome, self::UNANSWERED, true) => [__('assistant::report.unanswered'), 'bad'],
                in_array($outcome, self::OFF, true) => [__('assistant::report.off'), ''],
                default => [null, ''],
            };
            $answer = trim((string) ($a->getRelation('answer')?->getAttribute('answer') ?? $a->getAttributes()['answer'] ?? ''));
            $picked = array_flip(array_map('strval', (array) $a->picked));
            $picks = array_values(array_filter((array) $a->picks, 'is_array'));
            $items = $picks !== []
                ? array_map(fn (array $p): array => ['title' => trim((string) ($p['title'] ?? $titles[(string) ($p['external_id'] ?? '')] ?? ''))] + (isset($picked[(string) ($p['external_id'] ?? '')]) ? ['note' => __('assistant::report.opened')] : []), $picks)
                : array_map(fn (string $id): array => ['title' => (string) ($titles[$id] ?? '')] + (isset($picked[$id]) ? ['note' => __('assistant::report.opened')] : []), array_map('strval', (array) $a->products));
            $at = Carbon::parse($a->created_at)->timezone($zone);

            return new ReportRow(
                key: (string) $a->id, title: (string) $a->question, value: 0, pill: $pill, tone: $tone,
                sub: implode(' · ', array_filter([
                    $at->format('H:i'),
                    $a->whatsapp_shown ? __($a->whatsapp_clicked ? 'assistant::report.whatsapp_clicked' : 'assistant::report.whatsapp_shown') : null,
                ])),
                detail: $answer !== '' ? $answer : __('assistant::report.got_no_answer'),
                detailLabel: $answer === '' ? '' : __(in_array($outcome, self::ANSWERED, true) ? 'assistant::report.answer_given' : 'assistant::report.shown_instead'),
                group: $at->locale(app()->getLocale())->translatedFormat('l, j.n.Y'),
                items: array_values(array_filter($items, fn (array $i): bool => $i['title'] !== '')),
            );
        })->all();

        return ['rows' => $rows, 'total' => $total];
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
