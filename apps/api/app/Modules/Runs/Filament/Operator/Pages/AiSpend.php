<?php

namespace App\Modules\Runs\Filament\Operator\Pages;

use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What every shop spent on models, for the operator only: tokens and dollars by shop, by model
 * and by agent, day by day, for a month or a range of days. Read from the ledger, where every
 * model call is one row.
 */
final class AiSpend extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'ai-spend';

    protected string $view = 'runs::operator.ai-spend';

    /** "2026-10": the month shown when no days are chosen. */
    #[Url]
    public string $month = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** One shop, or every shop when empty. */
    #[Url]
    public string $shop = '';

    public static function getNavigationLabel(): string
    {
        return __('runs::spend.title');
    }

    public function getTitle(): string
    {
        return __('runs::spend.title');
    }

    public function getSubheading(): ?string
    {
        return __('runs::spend.subheading');
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : now()->format('Y-m');
    }

    public function clearDays(): void
    {
        $this->from = '';
        $this->to = '';
    }

    /** @return array{0: string, 1: string} the first and last day shown */
    public function range(): array
    {
        $valid = fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        if ($valid($this->from) || $valid($this->to)) {
            $from = $valid($this->from) ? $this->from : ($valid($this->to) ? $this->to : now()->toDateString());
            $to = $valid($this->to) ? $this->to : $from;

            return $from <= $to ? [$from, $to] : [$to, $from];
        }

        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01') ?: now()->startOfMonth();

        return [$start->copy()->startOfMonth()->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /** @return array<string, string> the months with spend, newest first, and this month */
    public function months(): array
    {
        // Days, not an SQL month function: the same on Postgres and in tests.
        $months = DB::table('ai_usage')->distinct()->pluck('day')->map(fn ($day): string => substr((string) $day, 0, 7))
            ->push(now()->format('Y-m'))->unique()->sortDesc()->values();

        return $months->mapWithKeys(fn (string $m): array => [$m => Carbon::createFromFormat('Y-m-d', $m.'-01')->locale(app()->getLocale())->translatedFormat('F Y')])->all();
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array{cost: float, input: int, output: int, cache: int, calls: int} */
    public function totals(): array
    {
        $row = $this->ledger()->selectRaw('count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cache_read_tokens) as cache, sum(cost_usd) as cost')->first();

        return ['cost' => (float) ($row->cost ?? 0), 'input' => (int) ($row->input ?? 0), 'output' => (int) ($row->output ?? 0), 'cache' => (int) ($row->cache ?? 0), 'calls' => (int) ($row->calls ?? 0)];
    }

    /** @return Collection<int, object> each shop's spend, the most first, with its models */
    public function byShop(): Collection
    {
        $names = $this->shops();
        $models = $this->ledger()
            ->selectRaw('shop_id, provider, model, count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cost_usd) as cost')
            ->groupBy('shop_id', 'provider', 'model')->orderByDesc('cost')->get()->groupBy('shop_id');

        return $this->ledger()
            ->selectRaw('shop_id, count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cache_read_tokens) as cache, sum(cost_usd) as cost')
            ->groupBy('shop_id')->orderByDesc('cost')->get()
            ->map(function (object $row) use ($names, $models): object {
                $row->name = $row->shop_id === null ? __('runs::spend.platform') : ($names[$row->shop_id] ?? __('runs::spend.deleted'));
                $row->models = $models->get($row->shop_id ?? '', collect());

                return $row;
            });
    }

    /** @return Collection<int, object> */
    public function byModel(): Collection
    {
        return $this->ledger()
            ->selectRaw('provider, model, count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cache_read_tokens) as cache, sum(cost_usd) as cost')
            ->groupBy('provider', 'model')->orderByDesc('cost')->get();
    }

    /** @return Collection<int, object> what the money went to */
    public function byAgent(): Collection
    {
        return $this->ledger()
            ->selectRaw('agent, count(*) as calls, sum(input_tokens) + sum(output_tokens) as tokens, sum(cost_usd) as cost')
            ->groupBy('agent')->orderByDesc('cost')->get()
            ->map(function (object $row): object {
                $row->label = Run::labelFor('agents', (string) $row->agent);

                return $row;
            });
    }

    /** @return Collection<int, object> every day in the range, a day without spend at zero */
    public function byDay(): Collection
    {
        [$from, $to] = $this->range();
        $spent = $this->ledger()
            ->selectRaw('day, sum(input_tokens) + sum(output_tokens) as tokens, sum(cost_usd) as cost')
            ->groupBy('day')->get()->keyBy(fn (object $r): string => substr((string) $r->day, 0, 10));
        $days = collect();

        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $key = $day->toDateString();
            $days->push((object) ['day' => $key, 'tokens' => (int) ($spent[$key]->tokens ?? 0), 'cost' => (float) ($spent[$key]->cost ?? 0)]);
        }

        return $days;
    }

    /** The ledger for the range and the shop chosen. */
    private function ledger(): Builder
    {
        [$from, $to] = $this->range();

        return DB::table('ai_usage')
            ->whereBetween('day', [$from, $to])
            ->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop));
    }
}
