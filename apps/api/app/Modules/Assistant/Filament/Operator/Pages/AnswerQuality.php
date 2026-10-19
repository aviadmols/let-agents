<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\RunQualityCases;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantQualityRun;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerFailure;
use App\Modules\Assistant\Support\QualityExpect;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Answer quality, for the operator: every question a model answered (or failed to), in every store,
 * with how it was answered (the prompt version, the writer and the checker, the checker's verdict,
 * what was sent, the cost and the time), what the nightly review scored and said, and why it failed:
 * as code reads it (AnswerFailure) and as the team decides. The place to see where the system needs
 * work: the knowledge, the retrieval, the prompt or the models. Reads only; no model is called.
 */
class AnswerQuality extends Page
{
    private const PAGE = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'assistant/answer-quality';

    protected string $view = 'assistant::operator.answer-quality';

    #[Url]
    public string $shop = '';

    #[Url]
    public int $days = 7;

    /** '' every ask, 'failed' any failure, or one reason (AnswerFailure::REASONS). */
    #[Url]
    public string $failure = 'failed';

    #[Url(as: 'q')]
    public string $find = '';

    public int $shown = self::PAGE;

    public ?string $open = null;

    /** asks: what was asked; cases: the regression cases. */
    #[Url]
    public string $tab = 'asks';

    /** @var array<string, int|string> ask id => the writer prompt version to replay with ('' the current) */
    public array $replayVersion = [];

    /** @var array<string, array<string, mixed>> ask id => what its replay came to */
    public array $replays = [];

    /** @var array<string, array{outcome?: string, contains?: string, absent?: string, note?: string}> ask id => the case being written */
    public array $caseForm = [];

    /** The writer prompt version to run the cases with ('' the current). */
    public string $casesVersion = '';

    /** frozen: each case's own data, to compare a prompt with another; live: today's catalogue and pages. */
    public string $casesMode = AssistantQualityRun::FROZEN;

    /** regression, baseline, or '' for both: which cases a run asks and the list shows. */
    #[Url(as: 'kind')]
    public string $casesKind = '';

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.quality.title');
    }

    public function getTitle(): string
    {
        return __('assistant::ui.quality.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.quality.subheading');
    }

    public function pick(string $failure): void
    {
        $this->failure = $failure === '' || $failure === 'failed' || in_array($failure, AnswerFailure::REASONS, true) ? $failure : 'failed';
        $this->shown = self::PAGE;
    }

    public function more(): void
    {
        $this->shown += self::PAGE;
    }

    public function toggle(string $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    /** The team's reading of why it failed; '' clears it, and code's reading shows again. */
    public function setFailure(string $id, string $reason): void
    {
        $reason = in_array($reason, AnswerFailure::REASONS, true) || $reason === 'fine' ? $reason : null;

        app(TenantContext::class)->runUnscoped(fn () => AssistantSearchAsk::query()->whereKey($id)->update(['failure_team' => $reason]));
        Notification::make()->success()->title(__('assistant::ui.quality.saved'))->send();
    }

    /** @return list<int> the writer prompt's versions there are, newest first */
    public function versions(): array
    {
        return AnswerSiteQuestion::promptVersions('answer_site');
    }

    /**
     * The question asked again as its shopper asked it, with the chosen writer prompt: a real model call,
     * recorded as an evaluation, nothing saved for shoppers. Shown beside what it got the first time.
     */
    public function replay(string $id): void
    {
        $ask = app(TenantContext::class)->runUnscoped(fn () => AssistantSearchAsk::query()->find($id));

        if ($ask === null) {
            return;
        }

        $version = (string) ($this->replayVersion[$id] ?? '') === '' ? null : (int) $this->replayVersion[$id];
        $out = app(AnswerSiteQuestion::class)->replay((string) $ask->shop_id, (string) $ask->question, (array) $ask->products, $version);

        $this->replays[$id] = [
            'outcome' => (string) ($out['result']['outcome'] ?? ''),
            'answer' => (string) ($out['result']['answer'] ?? ''),
            'picks' => array_map(fn ($p): string => (string) (is_array($p) ? ($p['title'] ?? '') : $p), (array) ($out['result']['picks'] ?? [])),
            'prompt' => $out['answered']['prompt_version'] ?? null,
            'verdict' => $out['answered']['verdict'] ?? null,
            'cost' => $out['answered']['cost_usd'] ?? null,
            'ms' => $out['answered']['duration_ms'] ?? null,
            'failure' => AnswerFailure::classify((string) ($out['result']['outcome'] ?? ''), $out['result']['reason'] ?? null, $out['answered']['verdict'] ?? null, (array) ($out['answered']['trace'] ?? [])),
        ];
    }

    /**
     * The question kept as a regression case, with what the team expects of its answer: an outcome, words
     * it says and never says, and beyond the words, the products it offered being right, the pages it
     * rested on being right, and the checker of the other family finding it supported by the store's data.
     * It keeps what the writer is given now, so a prompt is compared with another on the same data.
     */
    public function makeCase(string $id): void
    {
        $form = (array) ($this->caseForm[$id] ?? []);
        $ask = app(TenantContext::class)->runUnscoped(fn () => AssistantSearchAsk::query()->with('answer:id,sources')->find($id));

        if ($ask === null) {
            return;
        }

        $expect = array_filter([
            'outcome' => in_array($form['outcome'] ?? '', [AssistantAnswer::ANSWERED, AssistantAnswer::NO_INFO, AssistantAnswer::OUT_OF_SCOPE, 'no_match'], true) ? $form['outcome'] : null,
            'contains' => QualityExpect::split((string) ($form['contains'] ?? '')),
            'absent' => QualityExpect::split((string) ($form['absent'] ?? '')),
            'picks' => ! empty($form['picks_right']) ? array_values(array_filter(array_map(fn ($p): string => (string) ($p['external_id'] ?? ''), (array) $ask->picks))) : [],
            'sources' => ! empty($form['sources_right']) ? array_values(array_filter(array_map(fn ($s): string => (string) ($s['url'] ?? ''), (array) ($ask->getRelation('answer')?->getAttribute('sources') ?? [])))) : [],
            'supported' => ! empty($form['supported']) ? true : null,
        ], fn ($v): bool => $v !== null && $v !== []);

        if ($expect === []) {
            Notification::make()->warning()->title(__('assistant::ui.quality.case_empty'))->send();

            return;
        }

        $snapshot = app(AnswerSiteQuestion::class)->snapshot((string) $ask->shop_id, (string) $ask->question, (array) $ask->products);

        app(TenantContext::class)->run((string) $ask->shop_id, fn () => AssistantQualityCase::query()->updateOrCreate(['ask_id' => $ask->id], [
            'shop_id' => $ask->shop_id, 'kind' => AssistantQualityCase::REGRESSION, 'question' => mb_substr((string) $ask->question, 0, 500), 'products' => (array) $ask->products,
            'expect' => $expect, 'note' => mb_substr((string) ($form['note'] ?? ''), 0, 300) ?: null, 'created_by' => auth()->id(),
            'snapshot' => $snapshot, 'snapshot_at' => now(),
        ]));

        unset($this->caseForm[$id]);
        Notification::make()->success()->title(__('assistant::ui.quality.case_saved'))->send();
    }

    /**
     * The regression cases, of the chosen shop or every shop, the failing first.
     *
     * @return list<array<string, mixed>>
     */
    public function cases(): array
    {
        return app(TenantContext::class)->runUnscoped(function (): array {
            $cases = AssistantQualityCase::query()->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop))
                ->when($this->casesKind !== '', fn (Builder $q) => $q->where('kind', $this->casesKind))
                ->orderByRaw('case when last_passed = 0 then 0 when last_passed is null then 1 else 2 end')->latest('created_at')->limit(200)->get();
            $shops = Shop::query()->whereIn('id', $cases->pluck('shop_id')->unique()->values())->pluck('name', 'id');

            return $cases->map(fn (AssistantQualityCase $c): array => [
                'id' => (string) $c->id, 'shop' => (string) ($shops[$c->shop_id] ?? ''), 'kind' => $c->kind, 'frozen' => $c->snapshot_at?->timezone('Asia/Jerusalem')->format('j.n'),
                'question' => $c->question, 'expect' => (array) $c->expect, 'note' => $c->note,
                'passed' => $c->last_passed, 'problems' => (array) $c->last_problems, 'answer' => $c->last_answer, 'outcome' => $c->last_outcome,
                'prompt' => $c->last_prompt_version, 'cost' => $c->last_cost_usd, 'at' => $c->last_run_at?->timezone('Asia/Jerusalem')->format('j.n H:i'),
            ])->all();
        });
    }

    /**
     * The cases asked again (the chosen shop's, or every shop's), with the chosen prompt version, on their
     * frozen data or today's, each checked in code; the run is kept with its numbers.
     */
    public function runCases(): void
    {
        $run = app(RunQualityCases::class)->handle($this->shop === '' ? null : $this->shop, null, $this->casesVersion === '' ? null : (int) $this->casesVersion,
            $this->casesMode, $this->casesKind === '' ? null : $this->casesKind, null, auth()->id());
        $t = (array) $run->totals;

        Notification::make()->{((int) ($t['expected'] ?? 0)) === ((int) ($t['passed'] ?? 0)) && ($t['stopped'] ?? null) === null ? 'success' : 'warning'}()
            ->title(__('assistant::ui.quality.cases_done', ['passed' => (int) ($t['passed'] ?? 0), 'ran' => (int) ($t['expected'] ?? 0)]))
            ->body(trim((($t['stopped'] ?? null) !== null ? __('assistant::ui.quality.cases_stopped.'.$t['stopped']).' ' : '').'$'.number_format((float) ($t['cost_usd'] ?? 0), 4)))
            ->send();
    }

    /**
     * The last runs, of the chosen shop or every shop, each with the one before it of the same kind and
     * mode, to say whether a change made answers better, worse, or only more expensive.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(): array
    {
        $runs = AssistantQualityRun::query()->whereNotNull('finished_at')
            ->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop))
            ->when($this->casesKind !== '', fn (Builder $q) => $q->where('kind', $this->casesKind))
            ->orderByDesc('id')->limit(12)->get();
        $shops = app(TenantContext::class)->runUnscoped(fn () => Shop::query()->whereIn('id', $runs->pluck('shop_id')->filter()->unique()->values())->pluck('name', 'id'));

        return $runs->map(function (AssistantQualityRun $run) use ($shops): array {
            // ULIDs grow with time: the run before is the one with the id before, of the same kind and data.
            $before = AssistantQualityRun::query()->whereNotNull('finished_at')->where('id', '<', $run->id)
                ->where('mode', $run->mode)->where('kind', $run->kind)->where('shop_id', $run->shop_id)->orderByDesc('id')->first();

            return [
                'id' => $run->id, 'label' => $run->label, 'shop' => $run->shop_id === null ? null : (string) ($shops[$run->shop_id] ?? ''),
                'mode' => $run->mode, 'kind' => $run->kind, 'prompt' => $run->prompt_version, 'at' => $run->finished_at?->timezone('Asia/Jerusalem')->format('j.n H:i'),
                'now' => (array) $run->totals, 'before' => $before === null ? null : (array) $before->totals + ['prompt' => $before->prompt_version],
            ];
        })->all();
    }

    public function deleteCase(string $id): void
    {
        app(TenantContext::class)->runUnscoped(fn () => AssistantQualityCase::query()->whereKey($id)->delete());
    }

    /** @return array<string, string> shop id => name */
    public function shops(): array
    {
        return app(TenantContext::class)->runUnscoped(fn (): array => Shop::query()->orderBy('name')->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all());
    }

    /**
     * How many of each reason, the answers and what they cost, for the period and shop.
     *
     * @return array{asks: int, model: int, failed: int, by: array<string, int>, cost: float, per_answer: float|null, ms: int|null}
     */
    public function counts(): array
    {
        return app(TenantContext::class)->runUnscoped(function (): array {
            $base = $this->scope(AssistantSearchAsk::query());
            $by = (clone $base)->whereNotNull('failure')->selectRaw('coalesce(failure_team, failure) as reason, count(*) as n')->groupBy('reason')->pluck('n', 'reason')->map(fn ($n): int => (int) $n)->all();
            $team = (clone $base)->whereNull('failure')->whereNotNull('failure_team')->where('failure_team', '!=', 'fine')->selectRaw('failure_team as reason, count(*) as n')->groupBy('failure_team')->pluck('n', 'reason');
            foreach ($team as $reason => $n) {
                $by[$reason] = ($by[$reason] ?? 0) + (int) $n;
            }
            unset($by['fine']);
            $model = (clone $base)->whereNotNull('run_id');
            $answered = (clone $model)->where('outcome', AssistantAnswer::ANSWERED)->count();
            $cost = (float) (clone $model)->sum('cost_usd');

            return [
                'asks' => (clone $base)->count(),
                'model' => (clone $model)->count(),
                'failed' => array_sum($by),
                'by' => $by,
                'cost' => round($cost, 4),
                'per_answer' => $answered > 0 ? round($cost / $answered, 5) : null,
                'ms' => ($ms = (clone $model)->avg('duration_ms')) === null ? null : (int) round((float) $ms),
            ];
        });
    }

    /**
     * The four numbers that say whether answers are getting better, for this period and the one before
     * it (the same length): answers without a failure, of those a model wrote; what an answer cost; the
     * questions left without an answer; and the answers the nightly review found inventing, of those it
     * reviewed (the same sample every night: every question of the day, or the shop's cap of them).
     *
     * @return array<string, array{now: float|null, before: float|null}>
     */
    public function headline(): array
    {
        $days = max(1, min(90, $this->days));
        $now = $this->measure(now()->subDays($days), now());
        $before = $this->measure(now()->subDays(2 * $days), now()->subDays($days));

        return collect($now)->map(fn ($value, string $key): array => ['now' => $value, 'before' => $before[$key]])->all();
    }

    /** @return array{fine: float|null, cost: float|null, unanswered: float|null, invented: float|null} */
    private function measure(CarbonInterface $from, CarbonInterface $until): array
    {
        return app(TenantContext::class)->runUnscoped(function () use ($from, $until): array {
            $asks = AssistantSearchAsk::query()->where('created_at', '>=', $from)->where('created_at', '<', $until)
                ->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop));
            $all = (clone $asks)->count();
            $model = (clone $asks)->whereNotNull('run_id');
            $written = (clone $model)->count();
            $failed = (clone $model)->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNotNull('failure')->where(fn (Builder $q) => $q->whereNull('failure_team')->orWhere('failure_team', '!=', 'fine')))
                ->orWhere(fn (Builder $q) => $q->whereNotNull('failure_team')->where('failure_team', '!=', 'fine')))->count();
            $answered = (clone $model)->where('outcome', AssistantAnswer::ANSWERED)->count();
            $unanswered = (clone $asks)->whereIn('outcome', [AssistantAnswer::NO_INFO, 'no_match'])->count();

            $reviewed = 0;
            $invented = 0;
            $reviews = AssistantAskReview::query()->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<', $until->toDateString())
                ->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop))->get(['items']);
            foreach ($reviews as $review) {
                foreach ((array) $review->items as $item) {
                    $reviewed++;
                    $invented += is_array($item) && ($item['flag'] ?? null) === 'invented' ? 1 : 0;
                }
            }

            return [
                'fine' => $written > 0 ? round(100 * ($written - $failed) / $written, 1) : null,
                'cost' => $answered > 0 ? round((float) (clone $model)->sum('cost_usd') / $answered, 5) : null,
                'unanswered' => $all > 0 ? round(100 * $unanswered / $all, 1) : null,
                'invented' => $reviewed > 0 ? round(100 * $invented / $reviewed, 1) : null,
            ];
        });
    }

    /**
     * The asks to look at, newest first, each with the review's score and note when it had one.
     *
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function rows(): array
    {
        return app(TenantContext::class)->runUnscoped(function (): array {
            $query = $this->scope(AssistantSearchAsk::query());

            if ($this->failure === 'failed') {
                $query->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNotNull('failure')->where(fn (Builder $q) => $q->whereNull('failure_team')->orWhere('failure_team', '!=', 'fine')))
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('failure_team')->where('failure_team', '!=', 'fine')));
            } elseif ($this->failure !== '') {
                $query->where(fn (Builder $q) => $q->where('failure_team', $this->failure)->orWhere(fn (Builder $q) => $q->whereNull('failure_team')->where('failure', $this->failure)));
            }

            if (($words = trim($this->find)) !== '') {
                $query->where('question', 'like', '%'.addcslashes($words, '%_\\').'%');
            }

            $asks = $query->with('answer:id,answer')->latest('created_at')->orderByDesc('id')->limit($this->shown + 1)->get();
            $shops = Shop::query()->whereIn('id', $asks->pluck('shop_id')->unique()->values())->pluck('name', 'id');
            $reviews = $this->reviews($asks->pluck('shop_id')->unique()->values()->all());
            $rows = [];

            foreach ($asks->take($this->shown) as $ask) {
                /** @var AssistantSearchAsk $ask */
                $review = $reviews[(string) $ask->id] ?? null;
                $rows[] = [
                    'id' => (string) $ask->id,
                    'shop' => (string) ($shops[$ask->shop_id] ?? ''),
                    'shop_id' => (string) $ask->shop_id,
                    'at' => $ask->created_at?->timezone('Asia/Jerusalem')->format('j.n H:i'),
                    'question' => (string) $ask->question,
                    'answer' => trim((string) ($ask->getRelation('answer')?->getAttribute('answer') ?? $ask->getAttributes()['answer'] ?? '')),
                    'outcome' => (string) $ask->outcome,
                    'from' => (string) $ask->from,
                    'reason' => $ask->reason,
                    'failure' => $ask->failure,
                    'failure_team' => $ask->failure_team,
                    'score' => $review['score'] ?? null,
                    'note' => $review['note'] ?? null,
                    'flag' => $review['flag'] ?? null,
                    'prompt' => $ask->prompt_version,
                    'writer' => $ask->writer_model,
                    'checker' => $ask->checker_model,
                    'verdict' => $ask->verdict,
                    'trace' => (array) $ask->trace,
                    'picks' => array_map(fn (array $p): string => (string) ($p['title'] ?? ''), (array) $ask->picks),
                    'cost' => $ask->cost_usd,
                    'ms' => $ask->duration_ms,
                    'run' => $ask->run_id,
                ];
            }

            return ['rows' => $rows, 'more' => $asks->count() > $this->shown];
        });
    }

    /**
     * @param  Builder<AssistantSearchAsk>  $query
     * @return Builder<AssistantSearchAsk>
     */
    private function scope(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDays(max(1, min(90, $this->days))))
            ->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop));
    }

    /**
     * The nightly review's word on each ask of these shops in the period, by ask id.
     *
     * @param  list<string>  $shopIds
     * @return array<string, array{score?: int, note?: string, flag?: string}>
     */
    private function reviews(array $shopIds): array
    {
        $out = [];

        foreach (AssistantAskReview::query()->whereIn('shop_id', $shopIds)->whereDate('day', '>=', now()->subDays($this->days + 1)->toDateString())->get(['items']) as $review) {
            foreach ((array) $review->items as $item) {
                if (is_array($item) && isset($item['ask_id'])) {
                    $out[(string) $item['ask_id']] = $item;
                }
            }
        }

        return $out;
    }
}
