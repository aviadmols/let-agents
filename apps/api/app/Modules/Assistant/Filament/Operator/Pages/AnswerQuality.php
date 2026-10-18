<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerFailure;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
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
