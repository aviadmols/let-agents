<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Assistant\Models\AssistantAnswer;
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
        ]];
    }

    public function lists(): array
    {
        return ['questions'];
    }

    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array
    {
        $asks = AssistantSearchAsk::query()->where('day', '>=', $since->toDateString())->where('day', '<', $until->copy()->subSecond()->addDay()->toDateString())
            ->select('question')->selectRaw('count(*) as n, max(outcome) as outcome')->groupBy('question');

        if (($words = trim($search)) !== '') {
            $asks->where('question', 'like', '%'.$words.'%');
        }

        $total = DB::query()->fromSub(clone $asks, 'q')->count();
        $sort === 'name' ? $asks->orderBy('question') : $asks->orderByDesc(DB::raw('count(*)'))->orderBy('question');

        $rows = $asks->limit($limit)->get()->map(function ($q): ReportRow {
            $outcome = (string) $q->outcome;
            [$pill, $tone] = match (true) {
                in_array($outcome, self::ANSWERED, true) => [__('assistant::report.answered'), 'good'],
                in_array($outcome, self::UNANSWERED, true) => [__('assistant::report.unanswered'), 'bad'],
                in_array($outcome, self::OFF, true) => [__('assistant::report.off'), ''],
                default => [null, ''],
            };

            return new ReportRow(key: (string) $q->question, title: (string) $q->question, value: (int) $q->n, pill: $pill, tone: $tone);
        })->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function act(Shop $shop, string $list, string $key, string $action, string $value = ''): ?string
    {
        return null;
    }
}
